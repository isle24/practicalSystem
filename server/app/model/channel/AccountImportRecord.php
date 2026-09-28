<?php

namespace app\model\channel;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AccountImportRecord extends TableRecord
{
    private static array $schemaReady = [];

    public static function assertSchema(): void
    {
        self::ensureSchema();
    }

    public static function ensureSchema(): void
    {
        $connection = self::connection();
        $key = method_exists($connection, 'getDatabaseName')
            ? (string) $connection->getDatabaseName()
            : spl_object_hash($connection);
        if (isset(self::$schemaReady[$key])) {
            return;
        }

        if ($connection->transactionLevel() > 0) {
            self::assertRequiredSchema();
            return;
        }

        try {
            $connection->statement("CREATE TABLE IF NOT EXISTS `account_import_task` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` CHAR(36) NOT NULL,
                `request_key` VARCHAR(64) NOT NULL,
                `type` VARCHAR(20) NOT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'queued',
                `created_by` BIGINT UNSIGNED NOT NULL,
                `file_id` BIGINT UNSIGNED DEFAULT NULL,
                `source_json` JSON DEFAULT NULL,
                `password_hash` VARCHAR(255) DEFAULT NULL,
                `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
                `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `linked_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `updated_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `errors_json` JSON DEFAULT NULL,
                `error_message` VARCHAR(500) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `finished_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_account_import_uuid` (`uuid`),
                UNIQUE KEY `uk_account_import_request` (`created_by`, `request_key`),
                KEY `idx_account_import_owner` (`created_by`, `type`, `id`),
                KEY `idx_account_import_status` (`status`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::ensureColumns();
            self::assertRequiredSchema();
            self::$schemaReady[$key] = true;
        } catch (RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                throw $exception;
            }
            throw new RuntimeException('账号导入表初始化失败，请检查数据库结构与权限', 422, $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('账号导入表初始化失败，请检查数据库结构与权限', 422, $exception);
        }
    }

    public static function task(int $id, bool $lock = false): ?array
    {
        self::ensureSchema();
        $query = self::queryTable('account_import_task')->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->first()?->toArray();
    }

    public static function requestTask(int $accountId, string $requestKey): ?array
    {
        self::ensureSchema();
        return self::queryTable('account_import_task')->where('created_by', $accountId)
            ->where('request_key', $requestKey)->first()?->toArray();
    }

    public static function tasks(int $accountId, string $type): array
    {
        self::ensureSchema();
        return self::queryTable('account_import_task')->where('created_by', $accountId)->where('type', $type)
            ->orderByDesc('id')->limit(30)->get()->map(fn ($row): array => $row->toArray())->all();
    }

    public static function createTask(array $values): int
    {
        self::ensureSchema();
        return (int) self::queryTable('account_import_task')->insertGetId(array_merge([
            'uuid' => self::uuid(), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ], $values));
    }

    public static function updateTask(int $id, array $values): void
    {
        self::ensureSchema();
        self::queryTable('account_import_task')->where('id', $id)
            ->update(array_merge($values, ['updated_at' => date('Y-m-d H:i:s')]));
    }

    public static function departments(): array
    {
        return self::queryTable('department')->where('flag', 'on')->whereNull('deleted_at')
            ->get(['dep_id', 'dep_name', 'dep_short_name'])->map(static fn ($row): array => $row->toArray())->all();
    }

    public static function studentPreview(array $filters): array
    {
        $total = (int) self::studentQuery($filters)->count();
        $eligible = self::eligibleStudentQuery($filters);
        $eligibleCount = (int) (clone $eligible)->count();
        return [
            'total_rows' => $total,
            'eligible_rows' => $eligibleCount,
            'existing_rows' => (int) (clone $eligible)->whereExists(function ($query): void {
                $query->selectRaw('1')->from('account')->join('user_role', 'user_role.account_id', '=', 'account.id')
                    ->join('role', 'role.id', '=', 'user_role.role_id')->whereColumn('account.user_id', 'students.user_id')
                    ->whereNull('account.deleted_at')->whereNull('user_role.deleted_at')->whereNull('role.deleted_at')
                    ->where('user_role.is_primary', 'true')->where('role.role_type', 'student')->where('role.status', 'enabled');
            })->count(),
            'unmapped_rows' => $total - $eligibleCount,
        ];
    }

    public static function studentSnapshot(array $filters): array
    {
        $rows = self::eligibleStudentQuery($filters)->orderBy('edu_student_source.id')->limit(100001)
            ->get(['edu_student_source.id', 'edu_student_source.student_id']);
        if ($rows->count() > 100000) {
            throw new InvalidArgumentException('单次最多开通 100000 条学生数据，请缩小筛选范围');
        }
        return $rows->map(fn ($row): array => ['id' => (int) $row->id, 'student_id' => (int) $row->student_id])->all();
    }

    public static function provisionStudentSource(array $source, string $passwordHash): array
    {
        $row = self::queryTable('edu_student_source')->where('id', (int) $source['id'])->lockForUpdate()->first();
        if (!$row || $row->deleted_at !== null || $row->source_status !== 'active'
            || $row->mapping_status !== 'matched' || (int) $row->student_id !== (int) $source['student_id']) {
            throw new InvalidArgumentException('学生源数据已变更或不再有效，请重新发布、预览后开通');
        }
        $profile = self::queryTable('students')->where('student_id', (int) $row->student_id)->lockForUpdate()->first();
        if (!$profile || trim((string) $profile->student_num) !== trim((string) $row->student_num)) {
            throw new InvalidArgumentException('源数据学号与关联学生档案不一致');
        }
        return ProfileAccountRecord::provisionStudent((int) $row->student_id, $passwordHash);
    }

    private static function eligibleStudentQuery(array $filters): mixed
    {
        return self::studentQuery($filters)->join('students', 'students.student_id', '=', 'edu_student_source.student_id')
            ->where('edu_student_source.source_status', 'active')->where('edu_student_source.mapping_status', 'matched')
            ->where('students.status', 'enabled')->whereNull('students.deleted_at');
    }

    private static function studentQuery(array $filters): mixed
    {
        $query = self::queryTable('edu_student_source')->whereNull('edu_student_source.deleted_at');
        foreach (['source_status', 'mapping_status'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where('edu_student_source.' . $field, $filters[$field]);
            }
        }
        if (($filters['batch_id'] ?? 0) > 0) {
            $query->where('edu_student_source.last_seen_batch_id', $filters['batch_id']);
        }
        if (($filters['keyword'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['keyword']) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('edu_student_source.name', 'like', $like)
                    ->orWhere('edu_student_source.code', 'like', $like)
                    ->orWhere('edu_student_source.source_key', 'like', $like);
            });
        }
        return $query;
    }

    private static function ensureColumns(): void
    {
        $columns = [
            'uuid' => "ALTER TABLE `account_import_task` ADD COLUMN `uuid` CHAR(36) NOT NULL AFTER `id`",
            'request_key' => "ALTER TABLE `account_import_task` ADD COLUMN `request_key` VARCHAR(64) NOT NULL AFTER `uuid`",
            'type' => "ALTER TABLE `account_import_task` ADD COLUMN `type` VARCHAR(20) NOT NULL AFTER `request_key`",
            'status' => "ALTER TABLE `account_import_task` ADD COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'queued' AFTER `type`",
            'created_by' => "ALTER TABLE `account_import_task` ADD COLUMN `created_by` BIGINT UNSIGNED NOT NULL AFTER `status`",
            'file_id' => "ALTER TABLE `account_import_task` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `created_by`",
            'source_json' => "ALTER TABLE `account_import_task` ADD COLUMN `source_json` JSON DEFAULT NULL AFTER `file_id`",
            'password_hash' => "ALTER TABLE `account_import_task` ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL AFTER `source_json`",
            'total_rows' => "ALTER TABLE `account_import_task` ADD COLUMN `total_rows` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `password_hash`",
            'processed_rows' => "ALTER TABLE `account_import_task` ADD COLUMN `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `total_rows`",
            'created_count' => "ALTER TABLE `account_import_task` ADD COLUMN `created_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `processed_rows`",
            'linked_count' => "ALTER TABLE `account_import_task` ADD COLUMN `linked_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `created_count`",
            'updated_count' => "ALTER TABLE `account_import_task` ADD COLUMN `updated_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `linked_count`",
            'skipped_count' => "ALTER TABLE `account_import_task` ADD COLUMN `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `updated_count`",
            'failed_count' => "ALTER TABLE `account_import_task` ADD COLUMN `failed_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `skipped_count`",
            'errors_json' => "ALTER TABLE `account_import_task` ADD COLUMN `errors_json` JSON DEFAULT NULL AFTER `failed_count`",
            'error_message' => "ALTER TABLE `account_import_task` ADD COLUMN `error_message` VARCHAR(500) DEFAULT NULL AFTER `errors_json`",
            'created_at' => "ALTER TABLE `account_import_task` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `error_message`",
            'updated_at' => "ALTER TABLE `account_import_task` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
            'finished_at' => "ALTER TABLE `account_import_task` ADD COLUMN `finished_at` DATETIME DEFAULT NULL AFTER `updated_at`",
        ];
        foreach ($columns as $column => $ddl) {
            $row = self::connection()->selectOne(
                'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['account_import_task', $column]
            );
            if ((int) ($row->total ?? 0) === 0) {
                self::connection()->statement($ddl);
            }
        }
    }

    private static function assertRequiredSchema(): void
    {
        $table = self::connection()->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['account_import_task']
        );
        if ((int) ($table->total ?? 0) === 0) {
            throw new RuntimeException('账号导入表尚未升级，请在数据库结构检查中生成并执行升级 SQL', 422);
        }
        $required = [
            'uuid', 'request_key', 'type', 'status', 'created_by', 'file_id', 'source_json', 'password_hash',
            'total_rows', 'processed_rows', 'created_count', 'linked_count', 'updated_count', 'skipped_count',
            'failed_count', 'errors_json', 'error_message', 'created_at', 'updated_at', 'finished_at',
        ];
        $missing = [];
        foreach ($required as $column) {
            $row = self::connection()->selectOne(
                'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['account_import_task', $column]
            );
            if ((int) ($row->total ?? 0) === 0) {
                $missing[] = $column;
            }
        }
        if ($missing) {
            throw new RuntimeException('账号导入表缺少字段：' . implode('、', $missing) . '，请执行数据库结构升级 SQL', 422);
        }
    }
}
