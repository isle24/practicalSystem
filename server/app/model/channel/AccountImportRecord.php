<?php

namespace app\model\channel;

use InvalidArgumentException;
use RuntimeException;

class AccountImportRecord extends TableRecord
{
    public static function assertSchema(): void
    {
        try {
            self::requireTables(['account_import_task']);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('账号导入表尚未升级，请在数据库结构检查中生成并执行升级 SQL', 422, $exception);
        }
    }

    public static function task(int $id, bool $lock = false): ?array
    {
        $query = self::queryTable('account_import_task')->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->first()?->toArray();
    }

    public static function requestTask(int $accountId, string $requestKey): ?array
    {
        return self::queryTable('account_import_task')->where('created_by', $accountId)
            ->where('request_key', $requestKey)->first()?->toArray();
    }

    public static function tasks(int $accountId, string $type): array
    {
        return self::queryTable('account_import_task')->where('created_by', $accountId)->where('type', $type)
            ->orderByDesc('id')->limit(30)->get()->map(fn ($row): array => $row->toArray())->all();
    }

    public static function createTask(array $values): int
    {
        return (int) self::queryTable('account_import_task')->insertGetId(array_merge([
            'uuid' => self::uuid(), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ], $values));
    }

    public static function updateTask(int $id, array $values): void
    {
        self::queryTable('account_import_task')->where('id', $id)
            ->update(array_merge($values, ['updated_at' => date('Y-m-d H:i:s')]));
    }

    public static function departmentCounts(): array
    {
        return self::queryTable('department')->where('flag', 'on')->whereNull('deleted_at')
            ->get(['dep_name'])->countBy('dep_name')->all();
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
}
