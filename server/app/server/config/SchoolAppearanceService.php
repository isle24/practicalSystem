<?php

namespace app\server\config;

use app\model\channel\TableRecord;
use app\model\channel\FileRecord;
use app\model\channel\WallpaperRecord;
use app\server\CurrentContext;
use app\server\file\FileService;
use app\server\wallpaper\WallpaperService;
use app\server\WorkflowLock;
use RuntimeException;
use support\Request;

class SchoolAppearanceService
{
    public function publicSettings(): array
    {
        $config = new ConfigService();
        $connected = (bool) CurrentContext::get('school_connection');
        return [
            'school_name' => CurrentContext::get('school_name') ?: '成都锦城学院',
            'school_logo_url' => $connected ? (string) ($config->get('system.school_logo_url') ?? '') : '',
            'login_background_url' => $connected ? (string) ($config->get('system.login_background_url') ?? '') : '',
            'default_wallpaper_url' => $connected ? (string) ($config->get('system.default_wallpaper_url') ?? '') : '',
        ];
    }

    public function uploadWallpaper(Request $request): array
    {
        $this->requireManager();
        $files = new FileService();
        $uploaded = $files->upload($request, [
            'category' => 'wallpaper', 'is_temporary' => false, 'require_md5' => false,
            'max_size' => 8 * 1024 * 1024, 'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        ]);
        return $this->saveDefaultWallpaper((int) $uploaded['file_id']);
    }

    public function selectWallpaper(int $wallpaperId): array
    {
        $this->requireManager();
        $row = WallpaperRecord::byId($wallpaperId);
        if (!$row || !(bool) $row->is_shared) throw new RuntimeException('请选择已共享壁纸', 422);
        return $this->saveDefaultWallpaper((int) $row->file_id);
    }

    private function saveDefaultWallpaper(int $fileId): array
    {
        $file = FileRecord::detailById($fileId);
        if (!$file || !(new WallpaperService())->validImage($file)) throw new RuntimeException('壁纸文件无效', 422);
        $schoolId = (int) CurrentContext::get('school_id');
        return (new WorkflowLock())->run(WorkflowLock::key('school', 'wallpaper', $schoolId), function () use ($fileId, $file, $schoolId): array {
            return TableRecord::connection()->transaction(function () use ($fileId, $file, $schoolId): array {
                $config = new ConfigService();
                $config->set('system', 'default_wallpaper_url', (string) $file->url);
                $config->set('system', 'default_wallpaper_file_id', $fileId);
                (new FileService())->replaceRelations([$fileId], 'school_appearance', $schoolId, 'default_wallpaper');
                return $this->settings();
            });
        });
    }

    public function settings(): array
    {
        $this->requireManager();
        return $this->publicSettings();
    }

    public function upload(Request $request, string $type): array
    {
        $this->requireManager();
        $logo = $type === 'logo';
        if (!$logo && $type !== 'background') {
            throw new RuntimeException('学校素材类型无效', 422);
        }
        $files = new FileService();
        $uploaded = $files->upload($request, [
            'category' => $logo ? 'school_logo' : 'login_background',
            'is_temporary' => false,
            'require_md5' => false,
            'max_size' => ($logo ? 2 : 8) * 1024 * 1024,
            'allowed_extensions' => $logo ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        ]);
        $schoolId = (int) CurrentContext::get('school_id');
        return (new WorkflowLock())->run(WorkflowLock::key('school', 'appearance', $schoolId), function () use ($logo, $uploaded, $files, $schoolId): array {
            return TableRecord::connection()->transaction(function () use ($logo, $uploaded, $files, $schoolId): array {
                $key = $logo ? 'school_logo' : 'login_background';
                $config = new ConfigService();
                $config->set('system', $key . '_url', $uploaded['url']);
                $config->set('system', $key . '_file_id', (int) $uploaded['file_id']);
                $files->replaceRelations([(int) $uploaded['file_id']], 'school_appearance', $schoolId, $key);
                return $this->publicSettings();
            });
        });
    }

    public function requireManager(): void
    {
        if (!CurrentContext::accountId()
            || !in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true)
            || !in_array('config:manage', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无学校设置管理权限', 403);
        }
    }
}
