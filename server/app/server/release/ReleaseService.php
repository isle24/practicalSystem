<?php

namespace app\server\release;

use app\model\channel\ReleaseRecord;
use app\model\channel\FileRecord;
use app\server\CurrentContext;
use app\server\favorite\FavoriteService;
use RuntimeException;
use support\Redis;
use Webman\RedisQueue\Client;

/** 学校版本草稿、发布和受限升级下载。 */
class ReleaseService
{
    /** 学校管理员维护权限。 */
    public function assertAdmin(): void
    {
        if (!(new FavoriteService())->canShare()) throw new RuntimeException('无版本维护权限', 403);
    }

    /** 自动导入随服务端部署的版本草稿。 */
    public function adminPage(array $input): array
    {
        $this->assertAdmin();
        foreach (glob(base_path('releases/*.json')) ?: [] as $file) {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $this->validateVersion($data['version'] ?? '');
            ReleaseRecord::connection()->transaction(function () use ($data, $file): void {
                $row = ReleaseRecord::import($this->newRelease('system', $data['version'], $data['title'], $data['notes_html']) + ['source_id' => basename($file), 'source_hash' => hash_file('sha256', $file)]);
                foreach ($data['sql'] ?? [] as $index => $sql) {
                    $name = basename($sql['file']);
                    $path = base_path('database/updates/' . $name);
                    if (!is_file($path)) throw new RuntimeException('升级 SQL 文件缺失: ' . $name, 409);
                    ReleaseRecord::importSql(['release_id' => $row['id'], 'file_name' => $name, 'target_scope' => $sql['scope'] ?? 'school',
                        'sql_content' => file_get_contents($path), 'sha256' => hash_file('sha256', $path), 'sort' => $index]);
                }
            });
        }
        return ReleaseRecord::page($input, true) + ['github_configured' => (bool) config('desktop_release.token')];
    }

    /** 普通用户仅能读取已发布的说明快照。 */
    public function page(array $input): array { return ReleaseRecord::page($input, false); }

    /** 管理员详情独立返回 SQL，不复用用户响应。 */
    public function detail(int $id): array
    {
        $this->assertAdmin();
        $row = ReleaseRecord::detail($id);
        if (!$row) throw new RuntimeException('版本不存在', 404);
        return ['item' => $row, 'sql_files' => ReleaseRecord::sqlFiles($id, CurrentContext::roleType() === 'super_admin'), 'assets' => ReleaseRecord::assets($id)];
    }

    /** 保存草稿不影响已发布快照。 */
    public function save(array $input): array
    {
        $this->assertAdmin();
        $title = trim((string) ($input['title'] ?? ''));
        $html = (string) ($input['notes_html'] ?? '');
        if ($title === '' || mb_strlen($title) > 180 || strlen($html) > 200000) throw new RuntimeException('标题不能为空且最多180字，说明最多200KB', 400);
        return ReleaseRecord::connection()->transaction(function () use ($input, $title, $html): array {
            $row = $this->locked($input);
            ReleaseRecord::updateRelease($row['id'], ['title' => $title, 'notes_html' => $this->sanitize($html), 'revision' => $row['revision'] + 1,
                'updated_by' => CurrentContext::accountId(), 'updated_at' => date('Y-m-d H:i:s')]);
            return $this->detail($row['id']);
        });
    }

    /** 发布或撤回，校验修订号、SQL 登记和平台资产。 */
    public function publish(array $input, bool $withdraw = false): array
    {
        $this->assertAdmin();
        return ReleaseRecord::connection()->transaction(function () use ($input, $withdraw): array {
            ReleaseRecord::lockPublication();
            $row = $this->locked($input);
            if (!$withdraw) {
                if (trim(strip_tags($row['notes_html'])) === '') throw new RuntimeException('请先填写更新说明', 400);
                if (ReleaseRecord::pendingSql($row['id'])) throw new RuntimeException('请先在数据库工具执行 SQL，并由对应管理员登记处理结果', 409);
                if ($row['product'] === 'desktop') {
                    $assets = ReleaseRecord::assets($row['id']);
                    foreach (['darwin-universal', 'windows-x86_64', 'linux-x86_64'] as $platform) {
                        $ready = array_filter($assets, fn ($a) => $a['platform'] === $platform && $a['kind'] === 'updater' && $a['status'] === 'ready' && $a['signature']);
                        if (!$ready) throw new RuntimeException('升级包或签名未就绪: ' . $platform, 409);
                    }
                    if (array_filter($assets, fn ($a) => $a['status'] !== 'ready')) throw new RuntimeException('请等待所有安装包下载完成', 409);
                    $latest = ReleaseRecord::latestDesktop();
                    if ($latest && $latest['id'] !== $row['id'] && !version_compare($row['version'], $latest['version'], '>')) throw new RuntimeException('不能发布低于当前版本的客户端', 409);
                }
            }
            $values = ['status' => $withdraw ? 'withdrawn' : 'published', 'revision' => $row['revision'] + 1, 'updated_by' => CurrentContext::accountId(), 'updated_at' => date('Y-m-d H:i:s')];
            if (!$withdraw) $values += ['published_title' => $row['title'], 'published_notes_html' => $row['notes_html'], 'published_at' => date('Y-m-d H:i:s'), 'published_by' => CurrentContext::accountId()];
            ReleaseRecord::updateRelease($row['id'], $values);
            return $this->detail($row['id']);
        });
    }

    /** 只登记人工执行结果，不执行 SQL。 */
    public function markSql(array $input): void
    {
        $this->assertAdmin();
        ReleaseRecord::connection()->transaction(function () use ($input): void {
            $row = $this->locked($input);
            if (!ReleaseRecord::markSql($row['id'], (int) ($input['sql_id'] ?? 0), ['executed_by' => CurrentContext::accountId(),
                'executed_at' => date('Y-m-d H:i:s'), 'execution_note' => mb_substr((string) ($input['execution_note'] ?? ''), 0, 500)], CurrentContext::roleType() === 'super_admin')) throw new RuntimeException('SQL 不存在或无权登记', 403);
            ReleaseRecord::updateRelease($row['id'], ['revision' => $row['revision'] + 1]);
        });
    }

    /** 下载失败或超时后可重复排队。 */
    public function enqueue(int $id): void
    {
        $this->assertAdmin();
        if (!ReleaseRecord::detail($id)) throw new RuntimeException('版本不存在', 404);
        foreach (ReleaseRecord::assets($id) as $asset) {
            if ($asset['status'] !== 'ready') Client::send('desktop-release-download', ['database_id' => CurrentContext::schoolDatabaseId(), 'asset_id' => $asset['id']]);
        }
    }

    /** 签发学校当前已发布客户端的限时下载凭据。 */
    public function check(string $platform, string $current): ?array
    {
        if (!in_array($platform, ['darwin-universal', 'windows-x86_64', 'linux-x86_64'], true)) throw new RuntimeException('不支持的平台', 400);
        $this->validateVersion($current);
        $row = ReleaseRecord::latestDesktop();
        if (!$row || !version_compare($row['version'], $current, '>')) return null;
        foreach (ReleaseRecord::assets($row['id']) as $asset) {
            if ($asset['platform'] !== $platform || $asset['kind'] !== 'updater' || $asset['status'] !== 'ready') continue;
            $ticket = bin2hex(random_bytes(32));
            Redis::setex($this->ticketKey($ticket), (int) config('desktop_release.download_ttl', 3600), json_encode(['release_id' => $row['id'], 'asset_id' => $asset['id']]));
            return ['version' => $row['version'], 'notes' => strip_tags($row['published_notes_html']), 'size' => $asset['size'], 'manifest_path' => '/api/release/update-manifest?ticket=' . $ticket];
        }
        return null;
    }

    /** 用户可下载学校当前发布的安装文件，不返回内部文件地址。 */
    public function packages(int $releaseId): array
    {
        $release = ReleaseRecord::latestDesktop();
        if (!$release || $release['id'] !== $releaseId) return [];
        $items = [];
        foreach (ReleaseRecord::assets($releaseId) as $asset) {
            if ($asset['status'] !== 'ready' || str_ends_with($asset['file_name'], '.app.tar.gz')) continue;
            $ticket = bin2hex(random_bytes(32));
            Redis::setex($this->ticketKey($ticket), (int) config('desktop_release.download_ttl', 3600), json_encode(['release_id' => $releaseId, 'asset_id' => $asset['id']]));
            $items[] = ['file_name' => $asset['file_name'], 'platform' => $asset['platform'], 'size' => $asset['size'], 'download_path' => '/api/release/download?ticket=' . $ticket];
        }
        return $items;
    }

    /** 校验凭据以及发布状态，撤回后凭据立即失效。 */
    public function ticket(string $ticket): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $ticket)) throw new RuntimeException('下载凭据无效', 403);
        $data = json_decode(Redis::get($this->ticketKey($ticket)) ?: 'null', true);
        $asset = $data ? ReleaseRecord::asset((int) $data['asset_id']) : null;
        $release = $data ? ReleaseRecord::detail((int) $data['release_id']) : null;
        $latest = ReleaseRecord::latestDesktop();
        if (!$asset || !$release || !$latest || $release['id'] !== $latest['id'] || $release['status'] !== 'published' || $asset['status'] !== 'ready') throw new RuntimeException('下载凭据已过期或版本已撤回，请重新检查更新', 403);
        return [$release, $asset];
    }

    /** 定位已授权资产对应的有效存储文件。 */
    public function downloadPath(array $asset): string
    {
        $file = FileRecord::detailById((int) $asset['file_id']);
        $path = $file ? realpath(public_path() . '/' . ltrim($file->path, '/')) : false;
        if (!$path || !str_starts_with($path, realpath(public_path()) . DIRECTORY_SEPARATOR)) throw new RuntimeException('安装文件不可用，请联系管理员重新下载', 404);
        return $path;
    }

    /** 创建统一版本字段。 */
    public function newRelease(string $product, string $version, string $title, string $notes): array
    {
        $this->validateVersion($version);
        return ['product' => $product, 'version' => $version, 'title' => mb_substr($title, 0, 180), 'notes_html' => $this->sanitize($notes), 'status' => 'draft',
            'revision' => 1, 'created_by' => CurrentContext::accountId(), 'updated_by' => CurrentContext::accountId(), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    }

    /** 锁定指定修订号。 */
    private function locked(array $input): array
    {
        $row = ReleaseRecord::detail((int) ($input['id'] ?? 0), true);
        if (!$row) throw new RuntimeException('版本不存在', 404);
        if ((int) ($input['revision'] ?? 0) !== (int) $row['revision']) throw new RuntimeException('版本已由其他窗口修改，请刷新后重试', 409);
        return $row;
    }

    /** 限定正式语义版本。 */
    private function validateVersion(string $version): void
    {
        if (!preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version) || strlen($version) > 40) throw new RuntimeException('请使用 x.y.z 格式的正式版本号', 400);
    }

    /** 保留排版标签，不允许脚本、嵌入内容和事件属性。 */
    private function sanitize(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $before = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'pre', 'code', 'div', 'span', 'table', 'tbody', 'thead', 'tr', 'th', 'td'];
        $walk = function ($parent) use (&$walk, $allowed): void {
            foreach (iterator_to_array($parent->childNodes) as $node) {
                if ($node instanceof \DOMElement) {
                    if (!in_array(strtolower($node->tagName), $allowed, true)) { $parent->removeChild($node); continue; }
                    foreach (iterator_to_array($node->attributes) as $attribute) $node->removeAttribute($attribute->name);
                    $walk($node);
                } elseif (!($node instanceof \DOMText)) $parent->removeChild($node);
            }
        };
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) return '';
        $walk($body);
        return implode('', array_map(fn ($node) => $dom->saveHTML($node), iterator_to_array($body->childNodes)));
    }

    /** 隔离不同学校的升级凭据。 */
    private function ticketKey(string $ticket): string { return 'desktop-update:' . CurrentContext::schoolDatabaseId() . ':' . hash('sha256', $ticket); }
}
