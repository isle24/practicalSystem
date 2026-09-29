<?php

namespace app\model\channel;

use app\server\file\FileService;

class WallpaperSchema extends TableRecord
{
    public static function apply(): void
    {
        if (self::connection()->getTablePrefix() !== '') throw new \RuntimeException('壁纸结构升级不支持数据库表前缀');
        if (self::connection()->transactionLevel() > 0) throw new \RuntimeException('壁纸结构升级不能在事务内执行');
        self::connection()->statement(self::creationStatement());
        self::migrateCurrentSelections();
    }

    public static function creationStatement(): string
    {
        return "CREATE TABLE IF NOT EXISTS `wallpaper` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_id` BIGINT UNSIGNED NOT NULL,
            `owner_account_id` BIGINT UNSIGNED NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `is_shared` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_file_owner` (`file_id`, `owner_account_id`),
            KEY `idx_owner` (`owner_account_id`, `deleted_at`, `id`),
            KEY `idx_shared` (`is_shared`, `deleted_at`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function migrateCurrentSelections(): void
    {
        $files = new FileService();
        $wallpaper = new \app\server\wallpaper\WallpaperService();
        self::queryTable('user_desktop_config')->whereNull('deleted_at')
            ->whereRaw('user_desktop_config.id = (SELECT MAX(c2.id) FROM user_desktop_config c2 WHERE c2.account_id = user_desktop_config.account_id AND c2.deleted_at IS NULL)')
            ->chunkById(200, function ($configs) use ($files, $wallpaper): void {
                foreach ($configs as $config) {
                    $accountId = (int) $config->account_id;
                    $layout = json_decode((string) $config->layout_json, true);
                    if (!is_array($layout) || !empty($layout['wallpaper_mode'])) continue;
                    $url = (string) ($layout['wallpaper_url'] ?? '');
                    if ($url === '') continue;
                    foreach (FileRecord::idsByUrl($url) as $fileId) {
                        $source = FileRecord::detailById((int) $fileId);
                        if (!$source || (int) $source->uploader_id !== $accountId || !$wallpaper->validImage($source)) continue;
                        $target = (string) $source->category === 'wallpaper'
                            ? ['file_id' => (int) $source->id, 'url' => (string) $source->url]
                            : $files->copyExistingImage((int) $source->id, 'wallpaper', $accountId);
                        $targetId = (int) $target['file_id'];
                        $record = WallpaperRecord::ownedByFile($targetId, $accountId);
                        $wallpaperId = $record ? (int) $record->id : WallpaperRecord::createForFile($targetId, $accountId, (string) $source->name);
                        $layout['wallpaper_mode'] = 'item';
                        $layout['wallpaper_id'] = $wallpaperId;
                        $layout['wallpaper_url'] = (string) $target['url'];
                        self::queryTable('user_desktop_config')->where('id', (int) $config->id)->update([
                            'layout_json' => json_encode($layout, JSON_UNESCAPED_UNICODE),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                        break;
                    }
                }
            }, 'user_desktop_config.id', 'id');
    }
}
