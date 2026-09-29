<?php

namespace app\server\wallpaper;

use app\model\channel\FileRecord;
use app\model\channel\TableRecord;
use app\model\channel\WallpaperRecord;
use app\server\config\ConfigService;
use app\server\CurrentContext;
use app\server\file\FileService;
use RuntimeException;
use support\Request;

class WallpaperService
{
    public function list(string $scope, int $page, int $size): array
    {
        $accountId = $this->accountId();
        if (!in_array($scope, ['mine', 'shared'], true)) throw new RuntimeException('壁纸范围无效', 422);
        $page = max(1, $page);
        $size = min(50, max(1, $size));
        $result = WallpaperRecord::page($scope, $accountId, $page, $size);
        $items = [];
        foreach ($result['items'] as $row) {
            $file = FileRecord::detailById((int) $row->file_id);
            if ($file && $this->validImage($file)) $items[] = $this->item($row, $file);
        }
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $result['total']]];
    }

    public function upload(Request $request): array
    {
        $accountId = $this->accountId();
        $result = (new FileService())->upload($request, [
            'category' => 'wallpaper', 'is_temporary' => false, 'require_md5' => false,
            'max_size' => 8 * 1024 * 1024, 'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        ]);
        $file = FileRecord::detailById((int) $result['file_id']);
        if (!$file || !$this->validImage($file)) throw new RuntimeException('壁纸文件无效', 422);
        $id = WallpaperRecord::createForFile((int) $file->id, $accountId, (string) $file->name);
        return $this->item(WallpaperRecord::byId($id), $file);
    }

    public function importable(int $page, int $size): array
    {
        $accountId = $this->accountId();
        $page = max(1, $page);
        $size = min(50, max(1, $size));
        $query = FileRecord::query()->join('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->where('file.uploader_id', $accountId)->where('file.category', 'profile')
            ->whereNull('file.deleted_at')->whereNull('file_blob.deleted_at')
            ->whereIn('file_blob.mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
        $total = (int) (clone $query)->count();
        $rows = $query->orderByDesc('file.id')->forPage($page, $size)
            ->get(['file.id', 'file.name', 'file.url', 'file.created_at']);
        return ['items' => $rows->map(static fn ($row) => ['file_id' => (int) $row->id,
            'name' => (string) $row->name, 'url' => (string) $row->url,
            'created_at' => (string) $row->created_at])->all(),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    public function import(int $fileId): array
    {
        $accountId = $this->accountId();
        $source = FileRecord::detailById($fileId);
        if (!$source || (int) $source->uploader_id !== $accountId || (string) $source->category !== 'profile'
            || !$this->validImage($source)) throw new RuntimeException('历史图片不存在或无权限', 403);
        $copied = (new FileService())->copyExistingImage($fileId, 'wallpaper', $accountId);
        $file = FileRecord::detailById((int) $copied['file_id']);
        $id = WallpaperRecord::createForFile((int) $file->id, $accountId, (string) $source->name);
        return $this->item(WallpaperRecord::byId($id), $file);
    }

    public function share(int $id, bool $shared): array
    {
        if ($id <= 0) throw new RuntimeException('壁纸标识无效', 422);
        $row = WallpaperRecord::byId($id);
        if (!$row || (int) $row->owner_account_id !== $this->accountId()) throw new RuntimeException('壁纸不存在或无权限', 403);
        $file = FileRecord::detailById((int) $row->file_id);
        if (!$file || !$this->validImage($file) || (int) $file->uploader_id !== $this->accountId()) throw new RuntimeException('文件归属无效', 403);
        WallpaperRecord::setShared($id, $this->accountId(), $shared);
        return $this->item(WallpaperRecord::byId($id), $file);
    }

    public function apply(?int $id, string $mode): array
    {
        $accountId = $this->accountId();
        if ($mode !== 'school') {
            if (!$id || $id <= 0) throw new RuntimeException('壁纸标识无效', 422);
            $row = WallpaperRecord::byId($id);
            if (!$row || !$this->visible($row, $accountId)) throw new RuntimeException('壁纸不可用', 403);
            $file = FileRecord::detailById((int) $row->file_id);
            if (!$file || !$this->validImage($file)) throw new RuntimeException('壁纸文件无效', 422);
        }
        TableRecord::connection()->transaction(function () use ($accountId, $id, $mode): void {
            $row = TableRecord::latestDesktopConfig($accountId, ['layout_json']);
            $layout = json_decode((string) ($row->layout_json ?? ''), true);
            if (!is_array($layout)) $layout = [];
            $layout['wallpaper_mode'] = $mode === 'school' ? 'school' : 'item';
            $layout['wallpaper_id'] = $mode === 'school' ? null : $id;
            unset($layout['wallpaper_url']);
            TableRecord::saveDesktopConfig($accountId, ['layout_json' => json_encode($layout, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
        });
        return $this->selection($accountId);
    }

    public function selection(int $accountId): array
    {
        $row = TableRecord::latestDesktopConfig($accountId, ['layout_json']);
        $layout = json_decode((string) ($row->layout_json ?? ''), true);
        if (!is_array($layout)) $layout = [];
        $mode = (string) ($layout['wallpaper_mode'] ?? '');
        $id = (int) ($layout['wallpaper_id'] ?? 0);
        if ($mode === 'item' && $id > 0) {
            $wallpaper = WallpaperRecord::byId($id);
            if ($wallpaper && $this->visible($wallpaper, $accountId)) {
                $file = FileRecord::detailById((int) $wallpaper->file_id);
                if ($file && $this->validImage($file)) return ['wallpaper_mode' => 'item', 'wallpaper_id' => $id, 'wallpaper' => 'custom', 'wallpaper_url' => (string) $file->url];
            }
        }
        if ($mode === '' && !empty($layout['wallpaper_url'])) {
            foreach (FileRecord::idsByUrl((string) $layout['wallpaper_url']) as $fileId) {
                $wallpaper = WallpaperRecord::ownedByFile((int) $fileId, $accountId);
                if ($wallpaper) {
                    $file = FileRecord::detailById((int) $fileId);
                    if ($file && $this->validImage($file)) return ['wallpaper_mode' => 'item', 'wallpaper_id' => (int) $wallpaper->id, 'wallpaper' => 'custom', 'wallpaper_url' => (string) $file->url];
                }
            }
        }
        $defaultId = (int) ((new ConfigService())->get('system.default_wallpaper_file_id') ?? 0);
        $defaultFile = $defaultId > 0 ? FileRecord::detailById($defaultId) : null;
        return ['wallpaper_mode' => 'school', 'wallpaper_id' => null,
            'wallpaper' => $mode === '' && empty($layout['wallpaper_url']) ? (string) ($layout['wallpaper'] ?? 'default') : 'default',
            'wallpaper_url' => $defaultFile && $this->validImage($defaultFile) ? (string) $defaultFile->url : ''];
    }

    public function defaultFileId(): int
    {
        return (int) ((new ConfigService())->get('system.default_wallpaper_file_id') ?? 0);
    }

    public function visible(object $row, int $accountId): bool
    {
        return (int) $row->owner_account_id === $accountId || (int) $row->is_shared === 1
            || (int) $row->file_id === $this->defaultFileId();
    }

    public function validImage(object $file): bool
    {
        return in_array(strtolower((string) $file->ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            && in_array((string) $file->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    private function item(object $row, object $file): array
    {
        return ['id' => (int) $row->id, 'file_id' => (int) $row->file_id, 'name' => (string) $row->name,
            'url' => (string) $file->url, 'owner_account_id' => (int) $row->owner_account_id,
            'is_shared' => (bool) $row->is_shared, 'created_at' => (string) $row->created_at];
    }

    private function accountId(): int
    {
        $id = (int) CurrentContext::accountId();
        if ($id <= 0) throw new RuntimeException('请先登录', 401);
        return $id;
    }
}
