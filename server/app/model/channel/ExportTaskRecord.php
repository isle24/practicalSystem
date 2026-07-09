<?php

namespace app\model\channel;

class ExportTaskRecord extends TableRecord
{
    public const STATUSES = ['pending', 'processing', 'completed', 'failed', 'timeout'];

    private static array $schemaReady = [];

    public static function taskPage(array $filters, int $accountId, bool $includeAll): array
    {
        self::ensureSchema();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::applyTaskFilters(self::taskQuery(), $filters, $accountId, $includeAll);
        $total = (int) (clone $query)->count();
        $items = $query
            ->orderByDesc('export_task.id')
            ->forPage($page, $pageSize)
            ->get(self::taskColumns())
            ->map(static fn ($row): array => self::taskRow($row))
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

    public static function createTask(array $values): int
    {
        self::ensureSchema();

        $now = $values['created_at'] ?? date('Y-m-d H:i:s');
        return (int) self::queryTable('export_task')->insertGetId(array_merge([
            'uuid' => self::uuidValue(),
            'status' => 'pending',
            'progress' => 0,
            'total_rows' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $values));
    }

    public static function taskById(int $id): ?array
    {
        self::ensureSchema();

        $row = self::taskQuery()
            ->where('export_task.id', $id)
            ->first(self::taskColumns());

        return $row ? self::taskRow($row) : null;
    }

    public static function resetTask(int $id, int $accountId, bool $includeAll, string $now): int
    {
        self::ensureSchema();

        $query = self::queryTable('export_task')
            ->where('id', $id)
            ->whereNull('deleted_at');
        if (!$includeAll) {
            $query->where('user_id', $accountId);
        }

        return (int) $query->update([
            'status' => 'pending',
            'progress' => 0,
            'error_message' => null,
            'error_trace' => null,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => $now,
        ]);
    }

    public static function updateTaskStatus(int $id, array $values): int
    {
        self::ensureSchema();

        return (int) self::queryTable('export_task')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update($values);
    }

    /**
     * 后台补投用：取创建于 $before 之前仍处于 pending 的任务 id（最多 $limit 条）。
     */
    public static function stalePendingIds(string $before, int $limit = 50): array
    {
        self::ensureSchema();

        return self::queryTable('export_task')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->where('created_at', '<=', $before)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
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

    private static function taskQuery(): mixed
    {
        return self::queryTable('export_task')
            ->leftJoin('file', 'export_task.file_id', '=', 'file.id')
            ->leftJoin('account', 'export_task.user_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->whereNull('export_task.deleted_at');
    }

    private static function applyTaskFilters(mixed $query, array $filters, int $accountId, bool $includeAll): mixed
    {
        if (!$includeAll) {
            $query->where('export_task.user_id', $accountId);
        } else {
            $userId = (int) ($filters['user_id'] ?? 0);
            if ($userId > 0) {
                $query->where('export_task.user_id', $userId);
            }
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('export_task.status', self::normalizeStatus($status));
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '' && $type !== 'all') {
            $query->where('export_task.type', $type);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('export_task.file_name', 'like', $like)
                    ->orWhere('export_task.type', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhere('account.login_name', 'like', $like);
            });
        }

        return $query;
    }

    private static function taskColumns(): array
    {
        return [
            'export_task.id',
            'export_task.uuid',
            'export_task.user_id',
            'export_task.type',
            'export_task.file_name',
            'export_task.params',
            'export_task.status',
            'export_task.progress',
            'export_task.total_rows',
            'export_task.file_id',
            'export_task.error_message',
            'export_task.started_at',
            'export_task.finished_at',
            'export_task.created_at',
            'export_task.updated_at',
            'file.url as file_url',
            'file.download_name',
            'account.login_name',
            'users.name as user_name',
        ];
    }

    private static function taskRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            'user_name' => $row->user_name ?: $row->login_name,
            'type' => $row->type,
            'file_name' => $row->file_name,
            'params' => self::decodeJson($row->params),
            'status' => self::normalizeStatus((string) $row->status),
            'progress' => (int) ($row->progress ?? 0),
            'total_rows' => (int) ($row->total_rows ?? 0),
            'file_id' => $row->file_id === null ? null : (int) $row->file_id,
            'file_url' => $row->file_url,
            'download_name' => $row->download_name,
            'error_message' => $row->error_message,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    public static function normalizeStatus(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'pending';
    }

    public static function jsonValue(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : ['text' => $value];
        }

        return json_encode($value ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value ?: [];
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

    private static function createSchema(): void
    {
        $connection = self::connection();
        $connection->statement("CREATE TABLE IF NOT EXISTS `export_task` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'pending',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `type` VARCHAR(80) DEFAULT NULL,
            `file_name` VARCHAR(255) DEFAULT NULL,
            `params` JSON DEFAULT NULL,
            `progress` TINYINT UNSIGNED DEFAULT 0,
            `total_rows` INT UNSIGNED DEFAULT 0,
            `file_id` BIGINT UNSIGNED DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `error_trace` MEDIUMTEXT DEFAULT NULL,
            `started_at` DATETIME DEFAULT NULL,
            `finished_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_user_status` (`user_id`, `status`),
            KEY `idx_type_status` (`type`, `status`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::ensureColumns();
        self::ensureIndexes();
    }

    private static function ensureColumns(): void
    {
        $columns = [
            'user_id' => "ALTER TABLE `export_task` ADD COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
            'type' => "ALTER TABLE `export_task` ADD COLUMN `type` VARCHAR(80) DEFAULT NULL AFTER `user_id`",
            'file_name' => "ALTER TABLE `export_task` ADD COLUMN `file_name` VARCHAR(255) DEFAULT NULL AFTER `type`",
            'params' => "ALTER TABLE `export_task` ADD COLUMN `params` JSON DEFAULT NULL AFTER `file_name`",
            'progress' => "ALTER TABLE `export_task` ADD COLUMN `progress` TINYINT UNSIGNED DEFAULT 0 AFTER `status`",
            'total_rows' => "ALTER TABLE `export_task` ADD COLUMN `total_rows` INT UNSIGNED DEFAULT 0 AFTER `progress`",
            'file_id' => "ALTER TABLE `export_task` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `total_rows`",
            'error_message' => "ALTER TABLE `export_task` ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `file_id`",
            'error_trace' => "ALTER TABLE `export_task` ADD COLUMN `error_trace` MEDIUMTEXT DEFAULT NULL AFTER `error_message`",
            'started_at' => "ALTER TABLE `export_task` ADD COLUMN `started_at` DATETIME DEFAULT NULL AFTER `error_trace`",
            'finished_at' => "ALTER TABLE `export_task` ADD COLUMN `finished_at` DATETIME DEFAULT NULL AFTER `started_at`",
        ];

        foreach ($columns as $column => $ddl) {
            self::ensureColumnExists('export_task', $column, $ddl);
        }
    }

    private static function ensureIndexes(): void
    {
        $indexes = [
            ['export_task', 'idx_user_status', "ALTER TABLE `export_task` ADD KEY `idx_user_status` (`user_id`, `status`)"],
            ['export_task', 'idx_type_status', "ALTER TABLE `export_task` ADD KEY `idx_type_status` (`type`, `status`)"],
            ['export_task', 'idx_deleted_at', "ALTER TABLE `export_task` ADD KEY `idx_deleted_at` (`deleted_at`)"],
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
