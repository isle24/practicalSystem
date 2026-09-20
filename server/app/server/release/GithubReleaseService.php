<?php

namespace app\server\release;

use app\model\channel\ReleaseRecord;
use RuntimeException;

/** 固定 GitHub 仓库的只读版本与资产获取。 */
class GithubReleaseService
{
    /** 列出可导入的正式版本。 */
    public function releases(): array
    {
        (new ReleaseService())->assertAdmin();
        $rows = $this->json('/releases?per_page=30');
        return array_values(array_map(fn ($row) => ['id' => $row['id'], 'tag' => $row['tag_name'], 'name' => $row['name'], 'published_at' => $row['published_at']],
            array_filter($rows, fn ($row) => !$row['draft'] && !$row['prerelease'])));
    }

    /** 根据 GitHub 资产编号导入不可变清单，并排队下载。 */
    public function import(int $id): array
    {
        $service = new ReleaseService();
        $service->assertAdmin();
        if ($id <= 0) throw new RuntimeException('请选择 GitHub 版本', 400);
        $release = $this->json('/releases/' . $id);
        if ($release['draft'] || $release['prerelease']) throw new RuntimeException('只能导入正式版本', 400);
        $source = array_column($release['assets'], null, 'name');
        $manifestAsset = $source['desktop-release.json'] ?? null;
        if (!$manifestAsset || $manifestAsset['size'] > 2 * 1024 * 1024) throw new RuntimeException('此版本没有受支持的客户端清单', 400);
        $raw = $this->assetBytes((int) $manifestAsset['id'], 2 * 1024 * 1024);
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $version = (string) ($manifest['version'] ?? '');
        if ($release['tag_name'] !== 'v' . $version) throw new RuntimeException('清单版本与 GitHub 标签不一致', 409);
        $values = $service->newRelease('desktop', $version, $release['name'] ?: $release['tag_name'], '<p>' . nl2br(htmlspecialchars((string) ($manifest['notes'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</p>');
        $row = ReleaseRecord::connection()->transaction(function () use ($values, $id, $raw, $manifest, $source): array {
            $hash = hash('sha256', $raw);
            $row = ReleaseRecord::import($values + ['source_id' => (string) $id, 'source_hash' => $hash]);
            if ($row['source_hash'] !== $hash || $row['source_id'] !== (string) $id) throw new RuntimeException('同版本清单已变化，请使用新版本号发布', 409);
            $assets = $manifest['assets'] ?? [];
            if (count($assets) < 3 || count($assets) > 20) throw new RuntimeException('资产清单不完整', 400);
            foreach ($assets as $asset) {
                $name = (string) ($asset['file_name'] ?? '');
                $github = $source[$name] ?? null;
                if (!$github || $name !== basename($name) || strlen($name) > 240 || (int) $github['size'] !== (int) ($asset['size'] ?? 0)
                    || $github['size'] <= 0 || $github['size'] > config('desktop_release.max_asset_size')
                    || !preg_match('/^[a-f0-9]{64}$/', $asset['sha256'] ?? '')
                    || !in_array($asset['platform'] ?? '', ['darwin-universal', 'windows-x86_64', 'linux-x86_64'], true)
                    || !in_array($asset['kind'] ?? '', ['installer', 'updater'], true)) throw new RuntimeException('资产清单校验失败', 400);
                $signature = $asset['signature'] ?? null;
                if ($asset['kind'] === 'updater' && (!is_string($signature) || strlen($signature) > 2000 || base64_decode($signature, true) === false)) throw new RuntimeException('升级包缺少有效签名', 400);
                $stored = ReleaseRecord::importAsset(['release_id' => $row['id'], 'source_asset_id' => $github['id'], 'file_name' => $name,
                    'platform' => $asset['platform'], 'kind' => $asset['kind'], 'size' => $github['size'], 'sha256' => $asset['sha256'], 'signature' => $signature,
                    'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                if ($stored['sha256'] !== $asset['sha256']) throw new RuntimeException('同一资产内容不一致，拒绝覆盖', 409);
            }
            return $row;
        });
        $service->enqueue($row['id']);
        return $service->detail($row['id']);
    }

    /** 将固定仓库资产流式下载到新建临时文件。 */
    public function download(int $id, string $path, int $size): void
    {
        $this->fetch($this->api('/releases/assets/' . $id), $size, $path, 'application/octet-stream');
    }

    /** 读取小型资产清单。 */
    private function assetBytes(int $id, int $limit): string { return $this->fetch($this->api('/releases/assets/' . $id), $limit, null, 'application/octet-stream'); }

    /** 读取固定仓库 JSON。 */
    private function json(string $path): array { return json_decode($this->fetch($this->api($path), 8 * 1024 * 1024), true, 512, JSON_THROW_ON_ERROR); }

    /** 限定仓库配置，拒绝用户传入任意 URL。 */
    private function api(string $path): string
    {
        $repo = config('desktop_release.repository', '');
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo)) throw new RuntimeException('GitHub 仓库配置无效', 503);
        return 'https://api.github.com/repos/' . $repo . $path;
    }

    /** 手动处理可信重定向，令牌永不转发给下载域名。 */
    private function fetch(string $url, int $limit, ?string $path = null, string $accept = 'application/vnd.github+json'): string
    {
        for ($redirect = 0; $redirect < 5; $redirect++) {
            $host = parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, ['api.github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github.com'], true)) throw new RuntimeException('GitHub 下载跳转地址不受信任', 502);
            $headers = ['User-Agent: PracticalSystem-Release', 'Accept: ' . $accept];
            if ($host === 'api.github.com' && config('desktop_release.token')) $headers[] = 'Authorization: Bearer ' . config('desktop_release.token');
            $stream = $path ? fopen($path, 'wb') : null;
            if ($path && !$stream) throw new RuntimeException('无法创建下载临时文件', 500);
            $body = ''; $location = ''; $bytes = 0; $status = 0;
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $path ? 1800 : 45,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$location, &$status): int {
                    if (preg_match('~^HTTP/\S+\s+(\d+)~i', $line, $match)) $status = (int) $match[1];
                    if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, &$bytes, &$status, $limit, $stream): int {
                    $bytes += strlen($chunk);
                    if ($bytes > $limit) return 0;
                    if ($status >= 300 && $status < 400) return strlen($chunk);
                    if ($stream) return fwrite($stream, $chunk);
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            try { $ok = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); }
            finally { curl_close($curl); if ($stream) fclose($stream); }
            if (!$ok) throw new RuntimeException('GitHub 下载失败或文件超出大小限制，请重试', 502);
            if (in_array($code, [301, 302, 303, 307, 308], true) && $location) { $url = $location; continue; }
            if ($code !== 200) throw new RuntimeException('GitHub 返回 HTTP ' . $code . '，请检查仓库只读权限或稍后重试', 502);
            return $body;
        }
        throw new RuntimeException('GitHub 重定向次数过多', 502);
    }
}
