<?php

namespace app\model\channel;

use app\server\CurrentContext;
use Illuminate\Database\Query\Expression;

class TableRecord extends BaseModel
{
    private const DEFAULT_DESKTOP_MODULE_KEYS = ['internship', 'practice', 'config'];

    protected $guarded = [];
    public $timestamps = false;

    public static function queryTable(string $table): mixed
    {
        $model = new static();
        $model->setTable($table);
        return $model->newQuery();
    }

    public static function table(string $table): mixed
    {
        return self::queryTable($table);
    }

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    public static function operationLogTables(string $database): array
    {
        return self::queryTable('information_schema.tables')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'like', 'operation_log\_%')
            ->orderByDesc('TABLE_NAME')
            ->pluck('TABLE_NAME')
            ->filter(static fn ($table): bool => is_string($table) && preg_match('/^operation_log_\d{6}$/', $table))
            ->values()
            ->all();
    }

    public static function operationLogQuery(string $table): mixed
    {
        return self::queryTable($table)
            ->leftJoin('account', "{$table}.account_id", '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->whereNull("{$table}.deleted_at");
    }

    public static function operationLogColumns(string $table): array
    {
        return [
            "{$table}.id",
            "{$table}.uuid",
            "{$table}.account_id",
            "{$table}.action",
            "{$table}.ip",
            "{$table}.payload",
            "{$table}.created_at",
            'account.login_name',
            'users.name as user_name',
            new Expression("'" . str_replace("'", "''", $table) . "' as source_table"),
        ];
    }

    public static function operationLogPage(string $database, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        if ($database === '') {
            return [
                'items' => [],
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => 0,
                ],
                'tables' => [],
            ];
        }

        $tables = self::operationLogTables($database);
        $total = 0;
        $rows = [];
        $limit = min(1000, $page * $pageSize);

        foreach ($tables as $table) {
            $query = self::applyOperationLogFilters(self::operationLogQuery($table), $table, $filters);
            $total += (int) (clone $query)->count();
            foreach ($query->orderByDesc("{$table}.id")->forPage(1, $limit)->get(self::operationLogColumns($table)) as $row) {
                $rows[] = self::operationLogRow($row);
            }
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));

        return [
            'items' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
            'tables' => $tables,
        ];
    }

    public static function writeOperationLog(array $values, ?string $datetime = null): void
    {
        $datetime = $datetime ?: date('Y-m-d H:i:s');
        $table = 'operation_log_' . date('Ym', strtotime($datetime) ?: time());
        self::ensureOperationLogTable($table);
        self::queryTable($table)->insert([
            'uuid' => self::uuid(),
            'account_id' => $values['account_id'] ?? null,
            'action' => $values['action'] ?? null,
            'ip' => $values['ip'] ?? null,
            'payload' => json_encode($values['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $datetime,
            'updated_at' => $datetime,
            'deleted_at' => null,
        ]);
    }

    public static function ensureOperationLogTable(string $table): void
    {
        if (!preg_match('/^operation_log_\d{6}$/', $table)) {
            return;
        }

        self::connection()->statement("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `account_id` BIGINT UNSIGNED DEFAULT NULL,
            `action` VARCHAR(120) DEFAULT NULL,
            `ip` VARCHAR(80) DEFAULT NULL,
            `payload` JSON DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_account_id` (`account_id`),
            KEY `idx_action` (`action`),
            KEY `idx_ip` (`ip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function ensureRecordingTable(string $table): void
    {
        static $ensured = [];

        if (!preg_match('/^[a-z_]+_recording$/', $table)) {
            return;
        }
        $key = self::connection()->getDatabaseName() . ':' . $table;
        if (isset($ensured[$key])) {
            return;
        }
        if (self::tableExists($table)) {
            $ensured[$key] = true;
            return;
        }
        if (self::connection()->transactionLevel() > 0) {
            throw new \RuntimeException('记录表尚未初始化，请先升级学校数据库结构');
        }

        self::connection()->statement("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `entity_type` VARCHAR(40) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `parent_id` BIGINT UNSIGNED DEFAULT NULL,
            `action` VARCHAR(40) DEFAULT NULL,
            `operator_id` BIGINT UNSIGNED DEFAULT NULL,
            `from_status` VARCHAR(40) DEFAULT NULL,
            `to_status` VARCHAR(40) DEFAULT NULL,
            `opinion` TEXT DEFAULT NULL,
            `content` TEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_status` (`status`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_parent` (`parent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ensured[$key] = true;
    }

    /** 检查部署时初始化的业务表，不在业务事务内执行 DDL。 */
    protected static function requireTables(array $tables): void
    {
        static $ready = [];
        $database = self::connection()->getDatabaseName();
        foreach ($tables as $table) {
            $key = $database . ':' . $table;
            if (isset($ready[$key])) {
                continue;
            }
            if (!self::tableExists($table)) {
                throw new \RuntimeException('业务表尚未初始化，请先升级学校数据库结构');
            }
            $ready[$key] = true;
        }
    }

    public static function ensureReviewOpinionDraftTable(): void
    {
        static $ensured = [];
        $connection = self::connection();
        $key = method_exists($connection, 'getDatabaseName') ? (string) $connection->getDatabaseName() : spl_object_hash($connection);
        if (isset($ensured[$key])) {
            return;
        }
        if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
            return;
        }

        $connection->statement("CREATE TABLE IF NOT EXISTS `review_opinion_draft` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `entity_type` VARCHAR(40) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `reviewer_id` BIGINT UNSIGNED DEFAULT NULL,
            `teacher_id` BIGINT UNSIGNED DEFAULT NULL,
            `review_status` VARCHAR(40) DEFAULT NULL,
            `opinion` TEXT DEFAULT NULL,
            `score` DECIMAL(5,2) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_review_draft` (`entity_type`, `entity_id`, `reviewer_id`),
            KEY `idx_status` (`status`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_reviewer` (`reviewer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ensured[$key] = true;
    }

    public static function reviewOpinionDraftRow(string $entityType, int $entityId, int $reviewerId): ?array
    {
        self::ensureReviewOpinionDraftTable();

        $row = self::queryTable('review_opinion_draft')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('reviewer_id', $reviewerId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'uuid', 'entity_type', 'entity_id', 'reviewer_id', 'teacher_id', 'review_status', 'opinion', 'score', 'updated_at']);

        return $row ? $row->getAttributes() : null;
    }

    public static function saveReviewOpinionDraft(array $values): int
    {
        self::ensureReviewOpinionDraftTable();

        $entityType = (string) ($values['entity_type'] ?? '');
        $entityId = (int) ($values['entity_id'] ?? 0);
        $reviewerId = (int) ($values['reviewer_id'] ?? 0);
        $now = (string) ($values['updated_at'] ?? date('Y-m-d H:i:s'));
        $existingId = (int) (self::queryTable('review_opinion_draft')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('reviewer_id', $reviewerId)
            ->value('id') ?: 0);

        if ($existingId > 0) {
            unset($values['uuid'], $values['created_at']);
            $values['status'] = 'enabled';
            $values['deleted_at'] = null;
            $values['updated_at'] = $now;
            self::queryTable('review_opinion_draft')->where('id', $existingId)->update($values);
            return $existingId;
        }

        return (int) self::queryTable('review_opinion_draft')->insertGetId(array_merge([
            'uuid' => self::uuid(),
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $values));
    }

    public static function clearReviewOpinionDraft(string $entityType, int $entityId, int $reviewerId, string $now): int
    {
        self::ensureReviewOpinionDraftTable();

        return self::queryTable('review_opinion_draft')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('reviewer_id', $reviewerId)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'disabled',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private static function applyOperationLogFilters(mixed $query, string $table, array $filters): mixed
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($table, $like): void {
                $builder->where("{$table}.action", 'like', $like)
                    ->orWhere("{$table}.ip", 'like', $like)
                    ->orWhere("{$table}.payload", 'like', $like)
                    ->orWhere('account.login_name', 'like', $like)
                    ->orWhere('users.name', 'like', $like);
            });
        }

        foreach (['action', 'ip'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where("{$table}.{$field}", $value);
            }
        }

        $accountId = (int) ($filters['account_id'] ?? 0);
        if ($accountId > 0) {
            $query->where("{$table}.account_id", $accountId);
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query->where("{$table}.created_at", '>=', $dateFrom . ' 00:00:00');
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query->where("{$table}.created_at", '<=', $dateTo . ' 23:59:59');
        }

        return $query;
    }

    private static function operationLogRow(object $row): array
    {
        $payload = self::decodeLogPayload($row->payload);
        $payloadData = is_array($payload) ? $payload : [];

        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'source_table' => $row->source_table,
            'account_id' => $row->account_id === null ? null : (int) $row->account_id,
            'login_name' => $row->login_name,
            'user_name' => $row->user_name,
            'action' => $row->action,
            'operation' => $payloadData['operation'] ?? $row->action,
            'ip' => $row->ip,
            'method' => $payloadData['method'] ?? null,
            'path' => $payloadData['path'] ?? null,
            'status_code' => isset($payloadData['status_code']) ? (int) $payloadData['status_code'] : null,
            'duration_ms' => isset($payloadData['duration_ms']) ? (float) $payloadData['duration_ms'] : null,
            'response_code' => isset($payloadData['response_code']) && is_numeric($payloadData['response_code']) ? (int) $payloadData['response_code'] : null,
            'response_message' => $payloadData['response_message'] ?? null,
            'error' => $payloadData['error'] ?? null,
            'payload' => $payload,
            'created_at' => $row->created_at,
        ];
    }

    private static function decodeLogPayload(mixed $payload): mixed
    {
        if (!is_string($payload) || $payload === '') {
            return $payload;
        }

        $decoded = json_decode($payload, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
    }

    protected static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function archiveRows(string $table, array $columns, array $order): array
    {
        $query = self::queryTable($table)->whereNull('deleted_at');
        foreach ($order as $field) {
            $query->orderBy($field);
        }

        return $query->get($columns)->map(static fn ($row): array => $row->toArray())->all();
    }

    public static function archivePage(string $table, array $columns, array $order, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::queryTable($table)->whereNull('deleted_at');

        $flag = trim((string) ($filters['flag'] ?? ''));
        if (in_array($flag, ['on', 'off'], true)) {
            $query->where('flag', $flag);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $keywordColumns = array_values(array_filter((array) ($filters['keyword_columns'] ?? []), static fn ($column): bool => is_string($column) && $column !== ''));
        if ($keyword !== '' && $keywordColumns) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($keywordColumns, $like): void {
                foreach ($keywordColumns as $index => $column) {
                    $index === 0
                        ? $builder->where($column, 'like', $like)
                        : $builder->orWhere($column, 'like', $like);
                }
            });
        }

        $total = (int) (clone $query)->count();
        foreach ($order as $field) {
            $query->orderBy($field);
        }

        return [
            'items' => $query
                ->forPage($page, $pageSize)
                ->get($columns)
                ->map(static fn ($row): array => $row->toArray())
                ->all(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    public static function updateArchiveRow(string $table, string $idField, int $id, array $values): int
    {
        return self::queryTable($table)
            ->where($idField, $id)
            ->whereNull('deleted_at')
            ->update($values);
    }

    public static function insertArchiveRow(string $table, array $values): bool
    {
        return self::queryTable($table)->insert($values);
    }

    public static function softDeleteArchiveRow(string $table, string $idField, int $id, string $now): int
    {
        return self::queryTable($table)
            ->where($idField, $id)
            ->whereNull('deleted_at')
            ->update([
                'flag' => 'off',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public static function clearCurrentGrade(?int $excludeId, string $now): int
    {
        $query = self::queryTable('grade_list')
            ->where('is_current', 'true')
            ->whereNull('deleted_at');

        if ($excludeId) {
            $query->where('grade_id', '<>', $excludeId);
        }

        return $query->update([
            'is_current' => 'false',
            'updated_at' => $now,
        ]);
    }

    public static function currentGradeId(): ?int
    {
        $id = self::queryTable('grade_list')
            ->where('is_current', 'true')
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->value('grade_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public static function enabledOptionRows(string $table, array $columns, array $order, ?string $flagField = 'flag'): array
    {
        $query = self::queryTable($table)->whereNull('deleted_at');
        if ($flagField) {
            $query->where($flagField, 'on');
        }
        foreach ($order as $field) {
            $query->orderBy($field);
        }

        return $query->get($columns)->map(static fn ($row): array => $row->toArray())->all();
    }

    public static function importAcademicArchiveRows(string $type, array $rows, string $now): array
    {
        $summary = [
            'type' => $type,
            'total' => count($rows),
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'created_dependencies' => [
                'departments' => 0,
                'grades' => 0,
                'professions' => 0,
            ],
            'errors' => [],
        ];

        self::connection()->transaction(function () use (&$summary, $now, $rows, $type): void {
            foreach ($rows as $row) {
                try {
                    if (!empty($row['invalid_message'])) {
                        throw new \InvalidArgumentException((string) $row['invalid_message']);
                    }

                    $departmentName = self::importArchiveRequiredValue($row, 'dep_name', '学院');
                    $gradeName = self::importArchiveRequiredValue($row, 'grade_name', '届次');
                    $professionName = self::importArchiveRequiredValue($row, 'profession_name', '专业');
                    $department = self::findOrCreateImportDepartment($departmentName, $now);
                    $grade = self::findOrCreateImportGrade($gradeName, $now);
                    $profession = self::findOrCreateImportProfession($professionName, (int) $department['id'], (int) $grade['id'], $now);

                    if ($department['created']) {
                        $summary['created_dependencies']['departments']++;
                    }
                    if ($grade['created']) {
                        $summary['created_dependencies']['grades']++;
                    }

                    if ($type === 'profession') {
                        if ($profession['created']) {
                            $summary['created']++;
                        } else {
                            $summary['skipped']++;
                        }
                        continue;
                    }

                    if ($profession['created']) {
                        $summary['created_dependencies']['professions']++;
                    }

                    $className = self::importArchiveRequiredValue($row, 'class_name', '班级');
                    $class = self::findOrCreateImportClass($className, (int) $department['id'], (int) $grade['id'], (int) $profession['id'], $now);
                    if ($class['created']) {
                        $summary['created']++;
                    } else {
                        $summary['skipped']++;
                    }
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    if (count($summary['errors']) < 30) {
                        $summary['errors'][] = [
                            'row' => (int) ($row['row_number'] ?? 0),
                            'message' => $exception->getMessage(),
                        ];
                    }
                }
            }
        });

        return $summary;
    }

    private static function importArchiveRequiredValue(array $row, string $field, string $label): string
    {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException("缺少{$label}");
        }

        return $value;
    }

    private static function findOrCreateImportDepartment(string $name, string $now): array
    {
        $row = self::activeArchiveNameRow('department', 'dep_id', 'dep_name', $name);
        if ($row) {
            return ['id' => (int) $row->dep_id, 'created' => false];
        }

        return [
            'id' => (int) self::queryTable('department')->insertGetId([
                'dep_name' => $name,
                'dep_short_name' => null,
                'dep_code' => null,
                'parent_id' => 0,
                'sort' => 0,
                'flag' => 'on',
                'created_at' => $now,
                'updated_at' => $now,
            ], 'dep_id'),
            'created' => true,
        ];
    }

    private static function findOrCreateImportGrade(string $name, string $now): array
    {
        $row = self::activeArchiveNameRow('grade_list', 'grade_id', 'grade_name', $name);
        if ($row) {
            return ['id' => (int) $row->grade_id, 'created' => false];
        }

        return [
            'id' => (int) self::queryTable('grade_list')->insertGetId([
                'grade_name' => $name,
                'dep_id' => null,
                'is_current' => 'false',
                'sort' => 0,
                'flag' => 'on',
                'created_at' => $now,
                'updated_at' => $now,
            ], 'grade_id'),
            'created' => true,
        ];
    }

    private static function findOrCreateImportProfession(string $name, int $departmentId, int $gradeId, string $now): array
    {
        $row = self::activeArchiveNameRow('profession', 'profession_id', 'profession_name', $name, [
            'dep_id' => $departmentId,
            'grade_id' => $gradeId,
        ]);
        if ($row) {
            return ['id' => (int) $row->profession_id, 'created' => false];
        }

        return [
            'id' => (int) self::queryTable('profession')->insertGetId([
                'profession_name' => $name,
                'profession_short_name' => null,
                'profession_code' => null,
                'dep_id' => $departmentId,
                'grade_id' => $gradeId,
                'sort' => 0,
                'flag' => 'on',
                'created_at' => $now,
                'updated_at' => $now,
            ], 'profession_id'),
            'created' => true,
        ];
    }

    private static function findOrCreateImportClass(string $name, int $departmentId, int $gradeId, int $professionId, string $now): array
    {
        $row = self::activeArchiveNameRow('class', 'class_id', 'class_name', $name, [
            'dep_id' => $departmentId,
            'grade_id' => $gradeId,
            'profession_id' => $professionId,
        ]);
        if ($row) {
            return ['id' => (int) $row->class_id, 'created' => false];
        }

        return [
            'id' => (int) self::queryTable('class')->insertGetId([
                'class_name' => $name,
                'class_short_name' => null,
                'class_num' => null,
                'dep_id' => $departmentId,
                'grade_id' => $gradeId,
                'profession_id' => $professionId,
                'sort' => 0,
                'flag' => 'on',
                'created_at' => $now,
                'updated_at' => $now,
            ], 'class_id'),
            'created' => true,
        ];
    }

    private static function activeArchiveNameRow(string $table, string $idField, string $nameField, string $name, array $conditions = []): ?object
    {
        $query = self::queryTable($table)
            ->where($nameField, $name)
            ->whereNull('deleted_at');

        foreach ($conditions as $field => $value) {
            if ($value === null) {
                $query->whereNull($field);
                continue;
            }

            $query->where($field, $value);
        }

        return $query->first([$idField]);
    }

    public static function latestDesktopConfig(int $accountId, array $columns = ['id', 'layout_json']): ?object
    {
        return self::queryTable('user_desktop_config')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first($columns);
    }

    public static function saveDesktopConfig(int $accountId, array $values): void
    {
        $row = self::latestDesktopConfig($accountId, ['id']);
        if ($row) {
            self::queryTable('user_desktop_config')
                ->where('id', $row->id)
                ->update($values);
            return;
        }

        self::queryTable('user_desktop_config')->insert(array_merge($values, [
            'account_id' => $accountId,
            'created_at' => $values['updated_at'] ?? date('Y-m-d H:i:s'),
        ]));
    }

    public static function notifySettings(int $accountId): array
    {
        return self::queryTable('user_notify_setting')
            ->where('account_id', $accountId)
            ->where('msg_type', 'system')
            ->whereNull('deleted_at')
            ->get(['channel', 'enabled'])
            ->map(static fn ($row): array => $row->toArray())
            ->all();
    }

    public static function saveNotifySetting(int $accountId, string $channel, bool $enabled, string $now): void
    {
        $row = self::queryTable('user_notify_setting')
            ->where('account_id', $accountId)
            ->where('msg_type', 'system')
            ->where('channel', $channel)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first(['id']);
        $values = [
            'enabled' => $enabled ? 'true' : 'false',
            'updated_at' => $now,
        ];

        if ($row) {
            self::queryTable('user_notify_setting')
                ->where('id', $row->id)
                ->update($values);
            return;
        }

        self::queryTable('user_notify_setting')->insert(array_merge($values, [
            'account_id' => $accountId,
            'msg_type' => 'system',
            'channel' => $channel,
            'created_at' => $now,
        ]));
    }

    /**
     * 登记或更新登录设备（按 account_id + jti 唯一）。
     */
    public static function registerDevice(int $accountId, string $jti, array $values, string $now): void
    {
        $row = self::queryTable('user_device')
            ->where('account_id', $accountId)
            ->where('jti', $jti)
            ->orderByDesc('id')
            ->first(['id']);

        $payload = [
            'device_name' => $values['device_name'] ?? null,
            'ip' => $values['ip'] ?? null,
            'user_agent' => $values['user_agent'] ?? null,
            'last_active_at' => $now,
            'status' => 'enabled',
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        if ($row) {
            self::queryTable('user_device')->where('id', $row->id)->update($payload);
            return;
        }

        self::queryTable('user_device')->insert(array_merge($payload, [
            'uuid' => self::uuid(),
            'account_id' => $accountId,
            'jti' => $jti,
            'created_at' => $now,
        ]));
    }

    /**
     * 查询指定账号的所有在线设备。
     */
    public static function devices(int $accountId): array
    {
        return self::queryTable('user_device')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->orderByDesc('last_active_at')
            ->orderByDesc('id')
            ->get(['id', 'jti', 'device_name', 'ip', 'user_agent', 'last_active_at', 'created_at'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'jti' => (string) $row->jti,
                'device_name' => $row->device_name,
                'ip' => $row->ip,
                'user_agent' => $row->user_agent,
                'last_active_at' => $row->last_active_at,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    /**
     * 下线设备：软删除设备记录，返回被下线设备的 jti。
     */
    public static function revokeDevice(int $accountId, int $deviceId, string $now): ?string
    {
        $row = self::queryTable('user_device')
            ->where('id', $deviceId)
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->first(['id', 'jti']);
        if (!$row) {
            return null;
        }

        self::queryTable('user_device')
            ->where('id', $deviceId)
            ->update([
                'status' => 'disabled',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

        return (string) $row->jti;
    }

    /**
     * 下线当前设备之外的所有登录设备。
     *
     * @return string[]
     */
    public static function revokeOtherDevices(int $accountId, ?string $currentJti, string $now): array
    {
        $currentJti = trim((string) $currentJti);
        $query = self::queryTable('user_device')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at');

        if ($currentJti !== '') {
            $query->where('jti', '<>', $currentJti);
        }

        $rows = $query->get(['id', 'jti']);
        $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if (!$ids) {
            return [];
        }

        self::queryTable('user_device')
            ->whereIn('id', $ids)
            ->update([
                'status' => 'disabled',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

        return $rows->pluck('jti')
            ->map(static fn ($jti): string => trim((string) $jti))
            ->filter()
            ->values()
            ->all();
    }

    public static function desktopShortcuts(int $accountId): array
    {
        $items = self::queryTable('user_desktop_shortcut')
            ->leftJoin('favorite_link', function ($join): void {
                $join->on('user_desktop_shortcut.ref_id', '=', 'favorite_link.id')
                    ->where('user_desktop_shortcut.item_type', 'favorite')
                    ->whereNull('favorite_link.deleted_at');
            })
            ->where('user_desktop_shortcut.account_id', $accountId)
            ->where(function ($query) use ($accountId): void {
                $query->where('user_desktop_shortcut.item_type', 'module')->orWhere(function ($favorite) use ($accountId): void {
                    $favorite->where('favorite_link.status', 'enabled')->whereNull('favorite_link.deleted_at')
                        ->where(fn ($visible) => $visible->where('favorite_link.account_id', $accountId)->orWhere('favorite_link.scope', 'school'));
                });
            })
            ->whereNull('user_desktop_shortcut.deleted_at')
            ->orderBy('user_desktop_shortcut.sort')
            ->orderBy('user_desktop_shortcut.id')
            ->get([
                'user_desktop_shortcut.id',
                'user_desktop_shortcut.item_type',
                'user_desktop_shortcut.item_key',
                'user_desktop_shortcut.ref_id',
                'user_desktop_shortcut.sort',
                'favorite_link.title as favorite_title',
                'favorite_link.url as favorite_url',
                'favorite_link.icon_url as favorite_icon_url',
                'favorite_link.open_mode as favorite_open_mode',
                'favorite_link.scope as favorite_scope',
            ])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'type' => (string) $row->item_type,
                'key' => $row->item_key,
                'ref_id' => $row->ref_id === null ? null : (int) $row->ref_id,
                'sort' => (int) $row->sort,
                'favorite' => $row->ref_id === null ? null : [
                    'id' => (int) $row->ref_id,
                    'title' => $row->favorite_title,
                    'url' => $row->favorite_url,
                    'icon_url' => $row->favorite_icon_url,
                    'open_mode' => $row->favorite_open_mode,
                    'scope' => $row->favorite_scope,
                ],
            ])
            ->all();

        return self::mergeDefaultDesktopShortcuts($items);
    }

    public static function replaceDesktopShortcuts(int $accountId, array $items, string $now): void
    {
        self::connection()->transaction(function () use ($accountId, $items, $now): void {
            self::queryTable('account')->where('id', $accountId)->lockForUpdate()->first(['id']);
            self::queryTable('user_desktop_shortcut')
                ->where('account_id', $accountId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach (self::normalizeDesktopShortcutItems($accountId, $items) as $index => $item) {
                self::queryTable('user_desktop_shortcut')->insert([
                    'uuid' => self::uuid(),
                    'account_id' => $accountId,
                    'item_type' => $item['type'],
                    'item_key' => $item['key'],
                    'ref_id' => $item['ref_id'],
                    'sort' => $index + 1,
                    'status' => 'enabled',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }
        });
    }

    public static function clearTestData(string $now): array
    {
        $result = [];
        $result['operation_logs'] = self::clearOperationLogRows($now);

        foreach (self::testDataTables() as $table) {
            if (!self::tableExists($table)) {
                $result[$table] = 0;
                continue;
            }
            $result[$table] = self::queryTable($table)->delete();
        }

        foreach (['user_device', 'user_notify_setting', 'user_desktop_config', 'user_desktop_shortcut', 'favorite_link', 'user_note', 'message_target', 'message_channel_log'] as $table) {
            $result[$table] = self::queryTable($table)
                ->where(function ($query): void {
                    $query->whereNull('account_id')
                        ->orWhere('account_id', '<>', 1);
                })
                ->delete();
        }
        $result['file_relation'] = self::queryTable('file_relation')->where('entity_type', '<>', 'system_release')->delete();
        $result['file'] = self::queryTable('file')
            ->whereNotIn('id', self::queryTable('desktop_release_asset')->whereNotNull('file_id')->select('file_id'))
            ->where(function ($query): void {
                $query->whereNull('uploader_id')
                    ->orWhere('uploader_id', '<>', 1);
            })
            ->delete();
        $result['message'] = self::queryTable('message')->delete();

        foreach (['students', 'teacher_list', 'grade_teacher_guide'] as $table) {
            $result[$table] = self::queryTable($table)->delete();
        }

        $result['sys_organization'] = self::queryTable('sys_organization')
            ->where(function ($query): void {
                $query->whereNull('account_id')
                    ->orWhere('account_id', '<>', 1);
            })
            ->delete();
        $result['user_role'] = self::queryTable('user_role')
            ->where('account_id', '<>', 1)
            ->delete();
        $result['account'] = self::queryTable('account')
            ->where('id', '<>', 1)
            ->delete();
        $result['user_wechat'] = self::queryTable('user_wechat')
            ->where(function ($query): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', '<>', 1);
            })
            ->delete();
        $result['users'] = self::queryTable('users')
            ->where('id', '<>', 1)
            ->delete();

        return [
            'cleared_at' => $now,
            'affected' => $result,
        ];
    }

    private static function normalizeDesktopShortcutItems(int $accountId, array $items): array
    {
        $normalized = [];
        $seen = [];
        foreach (self::defaultDesktopShortcutItems() as $item) {
            $unique = "module:{$item['key']}";
            $seen[$unique] = true;
            $normalized[] = $item;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? $item['item_type'] ?? 'module');
            $key = trim((string) ($item['key'] ?? $item['item_key'] ?? ''));
            $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int) $item['ref_id'] : null;
            if ($type === 'module' && $key !== '') {
                $unique = "module:{$key}";
                if (isset($seen[$unique])) {
                    continue;
                }
                $seen[$unique] = true;
                $normalized[] = ['type' => 'module', 'key' => $key, 'ref_id' => null];
                continue;
            }
            if ($type === 'favorite' && $refId && self::favoriteExists($accountId, $refId)) {
                $unique = "favorite:{$refId}";
                if (isset($seen[$unique])) {
                    continue;
                }
                $seen[$unique] = true;
                $normalized[] = ['type' => 'favorite', 'key' => null, 'ref_id' => $refId];
            }
        }

        return $normalized;
    }

    private static function mergeDefaultDesktopShortcuts(array $items): array
    {
        $merged = [];
        $seen = [];
        $rowsByDefaultKey = [];

        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'module' && in_array((string) ($item['key'] ?? ''), self::DEFAULT_DESKTOP_MODULE_KEYS, true)) {
                $rowsByDefaultKey[(string) $item['key']] = $item;
                continue;
            }
        }

        foreach (self::DEFAULT_DESKTOP_MODULE_KEYS as $index => $key) {
            $item = $rowsByDefaultKey[$key] ?? [
                'id' => 0,
                'type' => 'module',
                'key' => $key,
                'ref_id' => null,
                'sort' => $index + 1,
                'favorite' => null,
            ];
            $item['locked'] = true;
            $merged[] = $item;
            $seen["module:{$key}"] = true;
        }

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $unique = $type === 'favorite'
                ? "favorite:" . (int) ($item['ref_id'] ?? 0)
                : "module:" . (string) ($item['key'] ?? '');
            if (isset($seen[$unique])) {
                continue;
            }
            $seen[$unique] = true;
            $merged[] = $item;
        }

        return $merged;
    }

    private static function defaultDesktopShortcutItems(): array
    {
        return array_map(
            static fn (string $key): array => ['type' => 'module', 'key' => $key, 'ref_id' => null],
            self::DEFAULT_DESKTOP_MODULE_KEYS
        );
    }

    /** 判断收藏是否仍对桌面所属账号可见。 */
    private static function favoriteExists(int $accountId, int $id): bool
    {
        return FavoriteRecord::visible($accountId, $id) !== null;
    }

    private static function clearOperationLogRows(string $now): int
    {
        $database = CurrentContext::schoolDatabase();
        if (!$database) {
            return 0;
        }

        $affected = 0;
        foreach (self::operationLogTables($database) as $table) {
            $affected += self::queryTable($table)->delete();
        }

        return $affected;
    }

    private static function tableExists(string $table): bool
    {
        $database = self::connection()->getDatabaseName();
        if ($database === '') {
            return false;
        }

        return (int) self::queryTable('information_schema.tables')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->count() > 0;
    }

    private static function testDataTables(): array
    {
        return [
            'arrangement_recording',
            'arrangement_change_recording',
            'arrangement_change',
            'internship_task_class',
            'application_recording',
            'application',
            'student_join_teacher',
            'join_recording',
            'pair',
            'sign_in_recording',
            'sign_in',
            'sign_in_qrcode',
            'journal_recording',
            'journal',
            'report_recording',
            'report',
            'review_opinion',
            'review_opinion_draft',
            'apply_report_delay_recording',
            'apply_report_delay',
            'score_recording',
            'score',
            'course_score',
            'internship_plan_approval',
            'plan_recording',
            'internship_plan',
            'insurance_recording',
            'insurance',
            'safety_letter_recording',
            'safety_letter_sign',
            'syllabus_guide_recording',
            'syllabus_guide',
            'implementation_sheet_recording',
            'implementation_sheet',
            'teacher_work_report_recording',
            'teacher_work_report',
            'inspection_recording',
            'inspection_record',
            'base_application_recording',
            'base_application',
            'base_usage_recording',
            'base_usage',
            'base_result_recording',
            'base_result',
            'base_expense_recording',
            'base_expense',
            'practice_project_student',
            'practice_recording',
            'practice_score',
            'practice_reflection',
            'practice_lesson_plan',
            'practice_syllabus',
            'practice_project',
            'practice_schedule',
            'practice_plan',
            'training_project',
            'training_project_class',
            'training_booking',
            'training_material',
            'training_report',
            'training_score',
            'lab_project',
            'lab_project_member',
            'lab_booking',
            'lab_material',
            'lab_report',
            'lab_score',
        ];
    }
}
