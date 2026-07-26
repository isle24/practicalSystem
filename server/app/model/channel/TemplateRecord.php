<?php

namespace app\model\channel;

class TemplateRecord extends TableRecord
{
    private static array $schemaReady = [];

    public static function categoryRows(bool $includeDisabled = false): array
    {
        self::ensureSchema();

        $query = self::queryTable('template_category')->whereNull('deleted_at');
        if (!$includeDisabled) {
            $query->where('flag', 'on')->where('status', 'enabled');
        }

        return $query
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'description', 'sort', 'flag', 'status', 'created_at', 'updated_at'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'description' => $row->description,
                'sort' => (int) ($row->sort ?? 0),
                'flag' => $row->flag,
                'status' => $row->status,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->all();
    }

    public static function templatePage(array $filters, bool $includeDisabled): array
    {
        self::ensureSchema();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::applyTemplateFilters(self::templateQuery(), $filters, $includeDisabled);
        $total = (int) (clone $query)->count();
        $items = $query
            ->orderByDesc('template.id')
            ->forPage($page, $pageSize)
            ->get(self::templateColumns())
            ->map(static fn ($row): array => self::templateRow($row))
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    public static function detailById(int $id, bool $includeDisabled): ?array
    {
        self::ensureSchema();

        $query = self::templateQuery()->where('template.id', $id);
        if (!$includeDisabled) {
            $query->where('template.flag', 'on')
                ->where('template.status', 'enabled');
        }

        $row = $query->first(self::templateColumns());
        return $row ? self::templateRow($row) : null;
    }

    public static function saveCategory(array $values): int
    {
        self::ensureSchema();

        $id = (int) ($values['id'] ?? 0);
        unset($values['id']);
        $values['updated_at'] = $values['updated_at'] ?? date('Y-m-d H:i:s');

        if ($id > 0) {
            self::queryTable('template_category')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->update($values);
            return $id;
        }

        $values['uuid'] = $values['uuid'] ?? self::uuidValue();
        $values['created_at'] = $values['created_at'] ?? $values['updated_at'];
        return (int) self::queryTable('template_category')->insertGetId($values);
    }

    public static function saveTemplate(array $values): int
    {
        self::ensureSchema();

        $id = (int) ($values['id'] ?? 0);
        unset($values['id']);
        $values['updated_at'] = $values['updated_at'] ?? date('Y-m-d H:i:s');

        if ($id > 0) {
            self::queryTable('template')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->update($values);
            return $id;
        }

        $values['uuid'] = $values['uuid'] ?? self::uuidValue();
        $values['created_at'] = $values['created_at'] ?? $values['updated_at'];
        $values['download_count'] = $values['download_count'] ?? 0;
        return (int) self::queryTable('template')->insertGetId($values);
    }

    public static function softDeleteTemplate(int $id, string $now): int
    {
        self::ensureSchema();

        return (int) self::queryTable('template')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update([
                'flag' => 'off',
                'status' => 'disabled',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public static function incrementDownload(int $id): int
    {
        self::ensureSchema();

        return (int) self::queryTable('template')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->increment('download_count');
    }

    public static function ensureSchema(): void
    {
        $connection = self::connection();
        $key = method_exists($connection, 'getDatabaseName') ? (string) $connection->getDatabaseName() : spl_object_hash($connection);
        if (isset(self::$schemaReady[$key])) {
            return;
        }

        self::createSchema();
        self::$schemaReady[$key] = true;
    }

    private static function templateQuery(): mixed
    {
        return self::queryTable('template')
            ->leftJoin('template_category', 'template.category_id', '=', 'template_category.id')
            ->leftJoin('file', 'template.file_id', '=', 'file.id')
            ->leftJoin('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->whereNull('template.deleted_at');
    }

    private static function applyTemplateFilters(mixed $query, array $filters, bool $includeDisabled): mixed
    {
        if (!$includeDisabled) {
            $query->where('template.flag', 'on')
                ->where('template.status', 'enabled');
        } else {
            $status = trim((string) ($filters['status'] ?? ''));
            if ($status !== '' && $status !== 'all') {
                $query->where('template.status', $status);
            }
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('template.category_id', $categoryId);
        }

        $businessCode = trim((string) ($filters['business_code'] ?? ''));
        if ($businessCode !== '' && $businessCode !== 'all') {
            $query->where('template.business_code', $businessCode);
        }

        $materialType = trim((string) ($filters['material_type'] ?? ''));
        if ($materialType !== '' && $materialType !== 'all') {
            $query->where('template.material_type', $materialType);
        }

        $scopeType = trim((string) ($filters['scope_type'] ?? ''));
        if ($scopeType !== '' && $scopeType !== 'all') {
            $query->where('template.scope_type', $scopeType);
        }

        $practiceType = trim((string) ($filters['practice_type'] ?? ''));
        if ($practiceType !== '' && $practiceType !== 'all') {
            $query->whereJsonContains('template.practice_types', $practiceType);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('template.name', 'like', $like)
                    ->orWhere('template.description', 'like', $like)
                    ->orWhere('template_category.name', 'like', $like)
                    ->orWhere('file.name', 'like', $like);
            });
        }

        return $query;
    }

    private static function templateColumns(): array
    {
        return [
            'template.id',
            'template.uuid',
            'template.category_id',
            'template.name',
            'template.description',
            'template.file_id',
            'template.version',
            'template.download_count',
            'template.flag',
            'template.business_code',
            'template.material_type',
            'template.scope_type',
            'template.practice_types',
            'template.status',
            'template.created_at',
            'template.updated_at',
            'template_category.name as category_name',
            'template_category.code as category_code',
            'file.name as file_name',
            'file.download_name',
            'file.url as file_url',
            'file_blob.ext',
            'file_blob.size',
            'file_blob.mime_type',
        ];
    }

    private static function templateRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'category_name' => $row->category_name,
            'category_code' => $row->category_code,
            'name' => $row->name,
            'description' => $row->description,
            'file_id' => $row->file_id === null ? null : (int) $row->file_id,
            'version' => $row->version,
            'download_count' => (int) ($row->download_count ?? 0),
            'flag' => $row->flag,
            'business_code' => $row->business_code,
            'material_type' => $row->material_type,
            'scope_type' => $row->scope_type,
            'practice_types' => self::jsonArray($row->practice_types ?? null),
            'status' => $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'file' => [
                'name' => $row->file_name,
                'download_name' => $row->download_name ?: $row->file_name,
                'url' => $row->file_url,
                'ext' => $row->ext,
                'size' => $row->size === null ? null : (int) $row->size,
                'mime_type' => $row->mime_type,
            ],
        ];
    }

    private static function createSchema(): void
    {
        $connection = self::connection();
        $connection->statement("CREATE TABLE IF NOT EXISTS `template_category` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `description` VARCHAR(500) DEFAULT NULL,
            `sort` INT DEFAULT 0,
            `flag` ENUM('off','on') DEFAULT 'on',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_flag_sort` (`flag`, `sort`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $connection->statement("CREATE TABLE IF NOT EXISTS `template` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `category_id` BIGINT UNSIGNED DEFAULT NULL,
            `description` VARCHAR(1000) DEFAULT NULL,
            `file_id` BIGINT UNSIGNED DEFAULT NULL,
            `version` VARCHAR(40) DEFAULT '1.0',
                `download_count` INT UNSIGNED DEFAULT 0,
                `flag` ENUM('off','on') DEFAULT 'on',
                `business_code` VARCHAR(80) DEFAULT NULL,
                `material_type` VARCHAR(60) DEFAULT NULL,
                `scope_type` VARCHAR(40) DEFAULT NULL,
                `practice_types` JSON DEFAULT NULL,
                PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_category_flag` (`category_id`, `flag`),
            KEY `idx_file_id` (`file_id`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::ensureColumns();
        self::ensureIndexes();
    }

    private static function ensureColumns(): void
    {
        $schemas = [
            'template_category' => [
                'description' => "ALTER TABLE `template_category` ADD COLUMN `description` VARCHAR(500) DEFAULT NULL AFTER `deleted_at`",
                'sort' => "ALTER TABLE `template_category` ADD COLUMN `sort` INT DEFAULT 0 AFTER `description`",
                'flag' => "ALTER TABLE `template_category` ADD COLUMN `flag` ENUM('off','on') DEFAULT 'on' AFTER `sort`",
            ],
            'template' => [
                'category_id' => "ALTER TABLE `template` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
                'description' => "ALTER TABLE `template` ADD COLUMN `description` VARCHAR(1000) DEFAULT NULL AFTER `category_id`",
                'file_id' => "ALTER TABLE `template` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `description`",
                'version' => "ALTER TABLE `template` ADD COLUMN `version` VARCHAR(40) DEFAULT '1.0' AFTER `file_id`",
                'download_count' => "ALTER TABLE `template` ADD COLUMN `download_count` INT UNSIGNED DEFAULT 0 AFTER `version`",
                'flag' => "ALTER TABLE `template` ADD COLUMN `flag` ENUM('off','on') DEFAULT 'on' AFTER `download_count`",
                'business_code' => "ALTER TABLE `template` ADD COLUMN `business_code` VARCHAR(80) DEFAULT NULL AFTER `flag`",
                'material_type' => "ALTER TABLE `template` ADD COLUMN `material_type` VARCHAR(60) DEFAULT NULL AFTER `business_code`",
                'scope_type' => "ALTER TABLE `template` ADD COLUMN `scope_type` VARCHAR(40) DEFAULT NULL AFTER `material_type`",
                'practice_types' => "ALTER TABLE `template` ADD COLUMN `practice_types` JSON DEFAULT NULL AFTER `scope_type`",
            ],
        ];

        foreach ($schemas as $table => $columns) {
            foreach ($columns as $column => $ddl) {
                self::ensureColumnExists($table, $column, $ddl);
            }
        }
    }

    private static function ensureIndexes(): void
    {
        $indexes = [
            ['template_category', 'uk_code', "ALTER TABLE `template_category` ADD UNIQUE KEY `uk_code` (`code`)"],
            ['template_category', 'idx_flag_sort', "ALTER TABLE `template_category` ADD KEY `idx_flag_sort` (`flag`, `sort`)"],
            ['template_category', 'idx_deleted_at', "ALTER TABLE `template_category` ADD KEY `idx_deleted_at` (`deleted_at`)"],
            ['template', 'idx_category_flag', "ALTER TABLE `template` ADD KEY `idx_category_flag` (`category_id`, `flag`)"],
            ['template', 'idx_file_id', "ALTER TABLE `template` ADD KEY `idx_file_id` (`file_id`)"],
            ['template', 'idx_deleted_at', "ALTER TABLE `template` ADD KEY `idx_deleted_at` (`deleted_at`)"],
            ['template', 'idx_template_business_material', "ALTER TABLE `template` ADD KEY `idx_template_business_material` (`business_code`, `material_type`, `status`)"],
        ];

        foreach ($indexes as [$table, $index, $ddl]) {
            self::ensureIndexExists($table, $index, $ddl);
        }
    }

    private static function ensureColumnExists(string $table, string $column, string $ddl): void
    {
        $row = self::connection()->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if ((int) ($row->total ?? 0) === 0) {
            self::connection()->statement($ddl);
        }
    }

    private static function ensureIndexExists(string $table, string $index, string $ddl): void
    {
        $row = self::connection()->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );
        if ((int) ($row->total ?? 0) === 0) {
            self::connection()->statement($ddl);
        }
    }

    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
