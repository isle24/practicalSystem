<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;

class DocRecord extends TableRecord
{
    private static array $schemaReady = [];

    public static function categoryTree(bool $includeDisabled = false, ?string $roleType = null): array
    {
        self::ensureSchema();

        $rows = self::categoryRows($includeDisabled, $roleType);
        $indexed = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $indexed[$row['id']] = $row;
        }

        $tree = [];
        foreach ($indexed as $id => &$row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            if ($parentId > 0 && isset($indexed[$parentId])) {
                $indexed[$parentId]['children'][] = &$row;
                continue;
            }
            $tree[] = &$row;
        }
        unset($row);

        return $tree;
    }

    public static function categoryRows(bool $includeDisabled = false, ?string $roleType = null): array
    {
        self::ensureSchema();

        $query = self::queryTable('doc_category')->whereNull('deleted_at');
        if (!$includeDisabled) {
            $query->where('status', 'enabled');
        }

        $rows = $query
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'name', 'icon', 'sort', 'status', 'created_at', 'updated_at'])
            ->map(static fn ($row): array => self::categoryRow($row))
            ->all();

        if ($includeDisabled || $roleType === null || $roleType === '') {
            return $rows;
        }

        return self::filterVisibleCategoryRows($rows, self::visibleCategoryIds($roleType));
    }

    public static function articlePage(array $filters, bool $includeUnpublished, ?string $roleType = null): array
    {
        self::ensureSchema();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::applyArticleFilters(self::articleQuery(), $filters, $includeUnpublished, $roleType);
        $total = (int) (clone $query)->count();
        $items = $query
            ->orderByDesc(new Expression('COALESCE(doc_article.published_at, doc_article.updated_at, doc_article.created_at)'))
            ->orderByDesc('doc_article.id')
            ->forPage($page, $pageSize)
            ->get(self::articleColumns())
            ->map(static fn ($row): array => self::articleRow($row, false))
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

    public static function articleDetail(int $id, bool $includeUnpublished, bool $increaseViewCount = false, ?string $roleType = null): ?array
    {
        self::ensureSchema();

        $query = self::articleQuery()->where('doc_article.id', $id);
        if (!$includeUnpublished) {
            $query->where('doc_article.status', 'published');
            self::applyVisibleRoleFilter($query, $roleType);
        }

        $row = $query->first(self::articleColumns(true));
        if (!$row) {
            return null;
        }

        if ($increaseViewCount) {
            self::queryTable('doc_article')
                ->where('id', $id)
                ->increment('view_count');
            $row->view_count = (int) $row->view_count + 1;
        }

        return self::articleRow($row, true);
    }

    public static function saveCategory(array $values): int
    {
        self::ensureSchema();

        $id = (int) ($values['id'] ?? 0);
        unset($values['id']);
        $values['updated_at'] = $values['updated_at'] ?? date('Y-m-d H:i:s');

        if ($id > 0) {
            self::queryTable('doc_category')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->update($values);
            return $id;
        }

        $values['uuid'] = $values['uuid'] ?? self::uuidValue();
        $values['created_at'] = $values['created_at'] ?? $values['updated_at'];
        return (int) self::queryTable('doc_category')->insertGetId($values);
    }

    public static function saveArticle(array $values, int $editorId, string $changeNote): int
    {
        self::ensureSchema();

        return (int) self::connection()->transaction(function () use ($changeNote, $editorId, $values): int {
            $id = (int) ($values['id'] ?? 0);
            unset($values['id']);
            $now = $values['updated_at'] ?? date('Y-m-d H:i:s');
            $values['updated_at'] = $now;
            $values['visible_roles'] = self::jsonListValue($values['visible_roles'] ?? ['all']);

            if ($id > 0) {
                self::queryTable('doc_article')
                    ->where('id', $id)
                    ->whereNull('deleted_at')
                    ->update($values);
            } else {
                $values['uuid'] = $values['uuid'] ?? self::uuidValue();
                $values['created_at'] = $values['created_at'] ?? $now;
                $id = (int) self::queryTable('doc_article')->insertGetId($values);
            }

            self::queryTable('doc_article_history')->insert([
                'uuid' => self::uuidValue(),
                'article_id' => $id,
                'title' => $values['title'] ?? '',
                'content' => $values['content'] ?? '',
                'version' => $values['version'] ?? '1.0',
                'editor_id' => $editorId,
                'change_note' => $changeNote,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            return $id;
        });
    }

    public static function softDeleteArticle(int $id, string $now): int
    {
        self::ensureSchema();

        return (int) self::queryTable('doc_article')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'archived',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public static function historyRows(int $articleId): array
    {
        self::ensureSchema();

        return self::queryTable('doc_article_history')
            ->leftJoin('account', 'doc_article_history.editor_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->where('doc_article_history.article_id', $articleId)
            ->whereNull('doc_article_history.deleted_at')
            ->orderByDesc('doc_article_history.id')
            ->get([
                'doc_article_history.id',
                'doc_article_history.article_id',
                'doc_article_history.title',
                'doc_article_history.version',
                'doc_article_history.change_note',
                'doc_article_history.editor_id',
                'doc_article_history.created_at',
                'account.login_name',
                'users.name as editor_name',
            ])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'article_id' => (int) $row->article_id,
                'title' => $row->title,
                'version' => $row->version,
                'change_note' => $row->change_note,
                'editor_id' => $row->editor_id === null ? null : (int) $row->editor_id,
                'editor_name' => $row->editor_name ?: $row->login_name,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    public static function defaultVisibleRolesForCategory(?int $categoryId): array
    {
        self::ensureSchema();
        if (!$categoryId) {
            return ['all'];
        }

        $code = (string) (self::queryTable('doc_category')
            ->where('id', $categoryId)
            ->whereNull('deleted_at')
            ->value('code') ?? '');

        return self::defaultVisibleRolesByCategoryCode($code);
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

    private static function articleQuery(): mixed
    {
        return self::queryTable('doc_article')
            ->leftJoin('doc_category', 'doc_article.category_id', '=', 'doc_category.id')
            ->leftJoin('account', 'doc_article.author_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->whereNull('doc_article.deleted_at');
    }

    private static function applyArticleFilters(mixed $query, array $filters, bool $includeUnpublished, ?string $roleType): mixed
    {
        if (!$includeUnpublished) {
            $query->where('doc_article.status', 'published');
            self::applyVisibleRoleFilter($query, $roleType);
        } else {
            $status = trim((string) ($filters['status'] ?? ''));
            if ($status !== '' && $status !== 'all') {
                $query->where('doc_article.status', $status);
            }
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('doc_article.category_id', $categoryId);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('doc_article.title', 'like', $like)
                    ->orWhere('doc_article.content', 'like', $like)
                    ->orWhere('doc_category.name', 'like', $like);
            });
        }

        return $query;
    }

    private static function articleColumns(bool $withContent = false): array
    {
        $columns = [
            'doc_article.id',
            'doc_article.uuid',
            'doc_article.category_id',
            'doc_article.title',
            'doc_article.version',
            'doc_article.status',
            'doc_article.author_id',
            'doc_article.view_count',
            'doc_article.published_at',
            'doc_article.visible_roles',
            'doc_article.created_at',
            'doc_article.updated_at',
            'doc_category.name as category_name',
            'doc_category.code as category_code',
            'account.login_name',
            'users.name as author_name',
        ];

        if ($withContent) {
            $columns[] = 'doc_article.content';
        }

        return $columns;
    }

    private static function articleRow(object $row, bool $withContent): array
    {
        $data = [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'category_name' => $row->category_name,
            'category_code' => $row->category_code,
            'title' => $row->title,
            'version' => $row->version,
            'status' => $row->status,
            'author_id' => $row->author_id === null ? null : (int) $row->author_id,
            'author_name' => $row->author_name ?: $row->login_name,
            'visible_roles' => self::visibleRolesFromValue($row->visible_roles ?? null, $row->category_code ?? null),
            'view_count' => (int) ($row->view_count ?? 0),
            'published_at' => $row->published_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];

        if ($withContent) {
            $data['content'] = (string) ($row->content ?? '');
        }

        return $data;
    }

    private static function applyVisibleRoleFilter(mixed $query, ?string $roleType): void
    {
        $roles = self::roleVisibleKeys($roleType);
        if (!$roles) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($builder) use ($roleType, $roles): void {
            $builder->where(function ($legacy) use ($roleType): void {
                $legacy->whereNull('doc_article.visible_roles');
                self::applyLegacyCategoryVisibleRoleFilter($legacy, $roleType);
            });

            foreach ($roles as $role) {
                $builder->orWhereRaw('JSON_CONTAINS(doc_article.visible_roles, ?)', [json_encode($role, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    private static function applyLegacyCategoryVisibleRoleFilter(mixed $query, ?string $roleType): void
    {
        $allowedCodes = self::legacyVisibleCategoryCodes($roleType);
        if (!$allowedCodes) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($builder) use ($allowedCodes): void {
            $builder->whereNull('doc_category.code')
                ->orWhere('doc_category.code', '')
                ->orWhereIn('doc_category.code', $allowedCodes);
        });
    }

    private static function visibleCategoryIds(?string $roleType): array
    {
        $query = self::articleQuery()->where('doc_article.status', 'published');
        self::applyVisibleRoleFilter($query, $roleType);

        return $query
            ->whereNotNull('doc_article.category_id')
            ->distinct()
            ->pluck('doc_article.category_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    private static function filterVisibleCategoryRows(array $rows, array $visibleIds): array
    {
        if (!$visibleIds) {
            return [];
        }

        $ids = array_fill_keys($visibleIds, true);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        foreach ($visibleIds as $id) {
            $parentId = (int) ($byId[$id]['parent_id'] ?? 0);
            while ($parentId > 0 && isset($byId[$parentId])) {
                $ids[$parentId] = true;
                $parentId = (int) ($byId[$parentId]['parent_id'] ?? 0);
            }
        }

        return array_values(array_filter($rows, static fn (array $row): bool => isset($ids[(int) $row['id']])));
    }

    private static function visibleRolesFromValue(mixed $value, ?string $categoryCode): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (!is_array($value) || !$value) {
            return self::defaultVisibleRolesByCategoryCode((string) $categoryCode);
        }

        return array_values(array_unique(array_filter(array_map(static fn ($role): string => trim((string) $role), $value))));
    }

    private static function roleVisibleKeys(?string $roleType): array
    {
        $roleType = trim((string) ($roleType ?? ''));
        if ($roleType === '') {
            return [];
        }

        $roles = ['all', $roleType];
        if (in_array($roleType, ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            $roles[] = 'admin';
        }

        return array_values(array_unique($roles));
    }

    private static function legacyVisibleCategoryCodes(?string $roleType): array
    {
        $roleType = trim((string) ($roleType ?? ''));
        if ($roleType === 'student') {
            return ['practice_flow', 'student_help'];
        }
        if ($roleType === 'teacher') {
            return ['practice_flow', 'student_help', 'teacher_help'];
        }
        if (in_array($roleType, ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            return ['practice_flow', 'student_help', 'teacher_help', 'admin_help'];
        }
        if ($roleType === 'enterprise') {
            return ['practice_flow'];
        }

        return ['practice_flow'];
    }

    private static function defaultVisibleRolesByCategoryCode(string $code): array
    {
        return match ($code) {
            'student_help' => ['student', 'teacher', 'admin'],
            'teacher_help' => ['teacher', 'admin'],
            'admin_help' => ['admin'],
            default => ['all'],
        };
    }

    private static function jsonListValue(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : explode(',', $value);
        }
        $values = is_array($value) ? $value : [];
        $values = array_values(array_unique(array_filter(array_map(static fn ($item): string => trim((string) $item), $values))));

        return json_encode($values ?: ['all'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function categoryRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'parent_id' => (int) ($row->parent_id ?? 0),
            'code' => $row->code,
            'name' => $row->name,
            'icon' => $row->icon,
            'sort' => (int) ($row->sort ?? 0),
            'status' => $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private static function createSchema(): void
    {
        $connection = self::connection();
        $connection->statement("CREATE TABLE IF NOT EXISTS `doc_category` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `parent_id` BIGINT UNSIGNED DEFAULT 0,
            `icon` VARCHAR(80) DEFAULT NULL,
            `sort` INT DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_parent` (`parent_id`),
            KEY `idx_status_sort` (`status`, `sort`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $connection->statement("CREATE TABLE IF NOT EXISTS `doc_article` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'draft',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `category_id` BIGINT UNSIGNED DEFAULT NULL,
            `title` VARCHAR(180) DEFAULT NULL,
            `content` MEDIUMTEXT DEFAULT NULL,
            `version` VARCHAR(40) DEFAULT '1.0',
            `author_id` BIGINT UNSIGNED DEFAULT NULL,
            `view_count` INT UNSIGNED DEFAULT 0,
            `published_at` DATETIME DEFAULT NULL,
            `visible_roles` JSON DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_category_status` (`category_id`, `status`),
            KEY `idx_status_published` (`status`, `published_at`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $connection->statement("CREATE TABLE IF NOT EXISTS `doc_article_history` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `article_id` BIGINT UNSIGNED DEFAULT NULL,
            `title` VARCHAR(180) DEFAULT NULL,
            `content` MEDIUMTEXT DEFAULT NULL,
            `version` VARCHAR(40) DEFAULT '1.0',
            `editor_id` BIGINT UNSIGNED DEFAULT NULL,
            `change_note` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_article` (`article_id`, `created_at`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::ensureColumns();
        self::ensureIndexes();
    }

    private static function ensureColumns(): void
    {
        $schemas = [
            'doc_category' => [
                'parent_id' => "ALTER TABLE `doc_category` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT 0 AFTER `deleted_at`",
                'icon' => "ALTER TABLE `doc_category` ADD COLUMN `icon` VARCHAR(80) DEFAULT NULL AFTER `parent_id`",
                'sort' => "ALTER TABLE `doc_category` ADD COLUMN `sort` INT DEFAULT 0 AFTER `icon`",
            ],
            'doc_article' => [
                'category_id' => "ALTER TABLE `doc_article` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
                'title' => "ALTER TABLE `doc_article` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `category_id`",
                'content' => "ALTER TABLE `doc_article` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `title`",
                'version' => "ALTER TABLE `doc_article` ADD COLUMN `version` VARCHAR(40) DEFAULT '1.0' AFTER `content`",
                'author_id' => "ALTER TABLE `doc_article` ADD COLUMN `author_id` BIGINT UNSIGNED DEFAULT NULL AFTER `version`",
                'view_count' => "ALTER TABLE `doc_article` ADD COLUMN `view_count` INT UNSIGNED DEFAULT 0 AFTER `author_id`",
                'published_at' => "ALTER TABLE `doc_article` ADD COLUMN `published_at` DATETIME DEFAULT NULL AFTER `view_count`",
                'visible_roles' => "ALTER TABLE `doc_article` ADD COLUMN `visible_roles` JSON DEFAULT NULL AFTER `published_at`",
            ],
            'doc_article_history' => [
                'article_id' => "ALTER TABLE `doc_article_history` ADD COLUMN `article_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
                'title' => "ALTER TABLE `doc_article_history` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `article_id`",
                'content' => "ALTER TABLE `doc_article_history` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `title`",
                'version' => "ALTER TABLE `doc_article_history` ADD COLUMN `version` VARCHAR(40) DEFAULT '1.0' AFTER `content`",
                'editor_id' => "ALTER TABLE `doc_article_history` ADD COLUMN `editor_id` BIGINT UNSIGNED DEFAULT NULL AFTER `version`",
                'change_note' => "ALTER TABLE `doc_article_history` ADD COLUMN `change_note` VARCHAR(500) DEFAULT NULL AFTER `editor_id`",
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
            ['doc_category', 'uk_code', "ALTER TABLE `doc_category` ADD UNIQUE KEY `uk_code` (`code`)"],
            ['doc_category', 'idx_parent', "ALTER TABLE `doc_category` ADD KEY `idx_parent` (`parent_id`)"],
            ['doc_category', 'idx_status_sort', "ALTER TABLE `doc_category` ADD KEY `idx_status_sort` (`status`, `sort`)"],
            ['doc_category', 'idx_deleted_at', "ALTER TABLE `doc_category` ADD KEY `idx_deleted_at` (`deleted_at`)"],
            ['doc_article', 'idx_category_status', "ALTER TABLE `doc_article` ADD KEY `idx_category_status` (`category_id`, `status`)"],
            ['doc_article', 'idx_status_published', "ALTER TABLE `doc_article` ADD KEY `idx_status_published` (`status`, `published_at`)"],
            ['doc_article', 'idx_deleted_at', "ALTER TABLE `doc_article` ADD KEY `idx_deleted_at` (`deleted_at`)"],
            ['doc_article_history', 'idx_article', "ALTER TABLE `doc_article_history` ADD KEY `idx_article` (`article_id`, `created_at`)"],
            ['doc_article_history', 'idx_deleted_at', "ALTER TABLE `doc_article_history` ADD KEY `idx_deleted_at` (`deleted_at`)"],
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
}
