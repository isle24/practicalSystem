<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;

class TableRecord extends BaseModel
{
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

    private static function uuid(): string
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
}
