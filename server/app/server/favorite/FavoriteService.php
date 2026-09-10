<?php

namespace app\server\favorite;

use app\model\channel\FavoriteRecord;
use app\server\CurrentContext;
use app\server\file\FileService;
use RuntimeException;

/** 收藏权限与共享变更。 */
class FavoriteService
{
    /** 学校范围的收藏维护权限。 */
    public function canShare(): bool
    {
        return in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true)
            && in_array('config:manage', CurrentContext::permissionCodes(), true);
    }

    /** 分页返回收藏及当前账号操作权限。 */
    public function page(array $filters): array
    {
        $data = FavoriteRecord::page((int) CurrentContext::accountId(), $filters);
        $data['items'] = array_map(fn ($row) => $this->present($row), $data['items']);
        $data['can_share'] = $this->canShare();
        return $data;
    }

    /** 保存收藏并检查并发修改。 */
    public function save(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $url = trim((string) ($input['url'] ?? ''));
        $parts = parse_url($url);
        if ($title === '' || mb_strlen($title) > 180 || mb_strlen($url) > 500
            || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('请填写有效标题和 HTTP/HTTPS 地址', 400);
        }
        $scope = (string) ($input['scope'] ?? 'personal');
        $mode = (string) ($input['open_mode'] ?? 'client');
        if (!in_array($scope, ['personal', 'school'], true) || !in_array($mode, ['client', 'browser'], true)) {
            throw new RuntimeException('收藏范围或打开方式无效', 400);
        }
        if ($scope === 'school' && !$this->canShare()) {
            throw new RuntimeException('没有全校共享权限', 403);
        }
        return FavoriteRecord::connection()->transaction(function () use ($input, $title, $url, $scope, $mode): array {
            $id = (int) ($input['id'] ?? 0);
            $old = $id ? FavoriteRecord::lock($id) : null;
            if ($id) {
                $this->assertEditable($old, $input);
            }
            $fileId = (int) ($input['icon_file_id'] ?? 0);
            $files = new FileService();
            $icon = $fileId ? $files->info($fileId) : null;
            if ($icon && !in_array(strtolower((string) ($icon['blob']['ext'] ?? '')), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'ico'], true)) {
                throw new RuntimeException('收藏图标必须是图片', 400);
            }
            $now = date('Y-m-d H:i:s');
            $values = ['title' => $title, 'url' => $url, 'scope' => $scope, 'open_mode' => $mode,
                'icon_file_id' => $fileId ?: null, 'icon_url' => $icon['url'] ?? null,
                'sort' => (int) ($input['sort'] ?? 0), 'status' => 'enabled',
                'updated_by' => CurrentContext::accountId(), 'updated_at' => $now, 'revision' => (int) ($old['revision'] ?? 0) + 1];
            if ($id) {
                FavoriteRecord::updateFavorite($id, $values);
            } else {
                $id = FavoriteRecord::createFavorite($values + ['account_id' => CurrentContext::accountId(),
                    'user_id' => CurrentContext::userId(), 'created_at' => $now]);
            }
            $files->replaceRelations($fileId ? [$fileId] : [], 'favorite_link', $id, 'icon');
            return $this->present(array_replace($old ?? ['account_id' => CurrentContext::accountId()], $values, ['id' => $id]));
        });
    }

    /** 软删除收藏，桌面查询自动排除失效项。 */
    public function delete(array $input): void
    {
        FavoriteRecord::connection()->transaction(function () use ($input): void {
            $id = (int) ($input['id'] ?? 0);
            $this->assertEditable(FavoriteRecord::lock($id), $input);
            FavoriteRecord::updateFavorite($id, ['deleted_at' => date('Y-m-d H:i:s'), 'status' => 'disabled',
                'updated_by' => CurrentContext::accountId()]);
            (new FileService())->replaceRelations([], 'favorite_link', $id, 'icon');
        });
    }

    /** 校验所有权与修订号。 */
    private function assertEditable(?array $row, array $input): void
    {
        if (!$row || !($row['scope'] === 'school' ? $this->canShare() : (int) $row['account_id'] === CurrentContext::accountId())) {
            throw new RuntimeException('收藏不存在或没有编辑权限', 403);
        }
        if ((int) ($input['revision'] ?? 0) !== (int) $row['revision']) {
            throw new RuntimeException('收藏已被修改，请刷新后重试', 409);
        }
    }

    /** 输出可见字段，不暴露收藏所有者资料。 */
    private function present(array $row): array
    {
        $row['can_open'] = ($row['scope'] ?? '') === 'school' || (int) ($row['account_id'] ?? 0) === CurrentContext::accountId();
        $row['can_edit'] = ($row['scope'] ?? '') === 'school' ? $this->canShare() : (int) ($row['account_id'] ?? 0) === CurrentContext::accountId();
        unset($row['account_id'], $row['user_id'], $row['updated_by'], $row['deleted_at']);
        return $row;
    }
}
