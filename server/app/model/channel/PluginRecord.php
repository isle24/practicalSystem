<?php

namespace app\model\channel;

use Illuminate\Database\Eloquent\Builder;

/** 受控 Web 插件目录模型。 */
class PluginRecord extends TableRecord
{
    private static array $ready = [];

    public static function creationStatement(): string
    {
        return "CREATE TABLE IF NOT EXISTS `plugin_catalog` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) NOT NULL,
            `code` VARCHAR(80) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `description` VARCHAR(500) DEFAULT NULL,
            `version` VARCHAR(40) DEFAULT '1.0.0',
            `icon_url` VARCHAR(500) DEFAULT NULL,
            `entry_url` VARCHAR(500) NOT NULL,
            `open_mode` VARCHAR(20) NOT NULL DEFAULT 'browser',
            `allowed_domains` JSON DEFAULT NULL,
            `permission_description` VARCHAR(500) DEFAULT NULL,
            `sort` INT NOT NULL DEFAULT 100,
            `status` VARCHAR(20) NOT NULL DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uk_plugin_code` (`code`), KEY `idx_plugin_status_sort` (`status`, `sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    /** 确保插件目录表存在并写入内置入口。 */
    public static function ensureSchema(): void
    {
        $database = (string) self::connection()->getDatabaseName();
        if (isset(self::$ready[$database])) return;
        self::connection()->statement(self::creationStatement());
        self::queryTable('plugin_catalog')->insertOrIgnore([
            'uuid' => self::uuid(), 'code' => 'cloud-storage', 'name' => '网盘入口',
            'description' => '通过官方授权页面访问学校配置的网盘服务。', 'version' => '1.0.0',
            'entry_url' => 'https://drive.google.com/', 'open_mode' => 'browser',
            'allowed_domains' => json_encode(['drive.google.com'], JSON_UNESCAPED_UNICODE),
            'permission_description' => '仅打开官方 Web/OAuth 页面，不保存网盘密码。', 'sort' => 10,
            'status' => 'enabled', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        self::$ready[$database] = true;
    }

    /** 分页查询启用插件，关键词只作用于可展示字段。 */
    public static function page(array $filters): array
    {
        self::ensureSchema();
        $query = self::queryTable('plugin_catalog')->whereNull('deleted_at');
        if (($filters['status'] ?? 'enabled') !== 'all') $query->where('status', 'enabled');
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->where(fn (Builder $builder) => $builder->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('description', 'like', $like));
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $size = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $total = (int) (clone $query)->count();
        $items = $query->orderBy('sort')->orderByDesc('id')->forPage($page, $size)->get()->map(fn ($row) => self::present($row->toArray()))->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    /** 查询插件详情。 */
    public static function detail(int $id): ?array
    {
        self::ensureSchema();
        $row = self::queryTable('plugin_catalog')->where('id', $id)->whereNull('deleted_at')->first();
        return $row ? self::present($row->toArray()) : null;
    }

    /** 保存插件目录记录。 */
    public static function savePlugin(array $values): int
    {
        self::ensureSchema();
        $id = (int) ($values['id'] ?? 0);
        unset($values['id']);
        if ($id > 0) {
            self::queryTable('plugin_catalog')->where('id', $id)->whereNull('deleted_at')->update($values + ['updated_at' => date('Y-m-d H:i:s')]);
            return $id;
        }
        return (int) self::queryTable('plugin_catalog')->insertGetId($values + [
            'uuid' => self::uuid(), 'status' => 'enabled', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 软删除插件目录记录。 */
    public static function deletePlugin(int $id): int
    {
        self::ensureSchema();
        return (int) self::queryTable('plugin_catalog')->where('id', $id)->whereNull('deleted_at')->update([
            'status' => 'disabled', 'deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 输出目录字段并解码允许域名。 */
    private static function present(array $row): array
    {
        $domains = json_decode((string) ($row['allowed_domains'] ?? '[]'), true);
        $row['allowed_domains'] = is_array($domains) ? $domains : [];
        unset($row['deleted_at']);
        return $row;
    }
}
