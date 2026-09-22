<?php

namespace app\model\channel;

use app\server\CurrentContext;

/** 管理测试数据清理任务及分批删除范围。 */
class DataCleanupRecord extends TableRecord
{
    private const SCOPE_DEFINITIONS = [
        'internship' => ['name' => '实习业务数据', 'tables' => [
            'arrangement_recording', 'arrangement_change_recording', 'arrangement_change', 'internship_task_class',
            'application_recording', 'application', 'student_join_teacher', 'join_recording', 'pair',
            'sign_in_recording', 'sign_in', 'sign_in_qrcode', 'journal_recording', 'journal',
            'report_recording', 'report', 'review_opinion', 'review_opinion_draft', 'apply_report_delay_recording',
            'apply_report_delay', 'score_recording', 'score', 'course_score', 'internship_plan_approval',
            'plan_recording', 'internship_plan', 'insurance_recording', 'insurance', 'safety_letter_recording',
            'safety_letter_sign', 'syllabus_guide_recording', 'syllabus_guide', 'implementation_sheet_recording',
            'implementation_sheet', 'teacher_work_report_recording', 'teacher_work_report', 'inspection_recording',
            'inspection_record', 'base_application_recording', 'base_application', 'base_usage_recording',
            'base_usage', 'base_result_recording', 'base_result', 'base_expense_recording', 'base_expense',
        ]],
        'practice' => ['name' => '实验实训业务数据', 'prefixes' => ['practice_', 'training_', 'lab_'], 'exclude' => ['practice_period']],
        'social_practice' => ['name' => '社会实践业务数据', 'prefixes' => ['social_practice_'], 'exclude' => ['social_practice_approval_flow', 'social_practice_approval_node']],
        'student_teacher' => ['name' => '学生与教师档案', 'tables' => ['students', 'teacher_list']],
        'messages' => ['name' => '消息与个人工具', 'tables' => [
            'message', 'message_target', 'message_channel_log', 'message_realtime_outbox', 'user_note',
            'favorite_link', 'user_desktop_shortcut', 'user_desktop_config', 'user_device',
        ]],
        'files' => ['name' => '文件及关联记录', 'tables' => ['file_relation', 'file']],
        'operation_logs' => ['name' => '操作日志', 'operation_logs' => true],
        'accounts' => ['name' => '非教务关联账号', 'tables' => ['user_role', 'sys_organization', 'account', 'users']],
        'edu_data' => ['name' => '教务导入暂存数据', 'prefixes' => ['edu_']],
    ];

    public static function install(): void
    {
        $sql = file_get_contents(base_path('database/updates/20260922-data-cleanup.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            self::connection()->unprepared($statement);
        }
    }

    public static function scopeOptions(): array
    {
        return array_map(static fn (array $definition, string $key): array => [
            'key' => $key,
            'name' => $definition['name'],
        ], self::SCOPE_DEFINITIONS, array_keys(self::SCOPE_DEFINITIONS));
    }

    public static function createTask(int $accountId, array $scopes, bool $preserveEduData, string $now): int
    {
        return (int) self::queryTable('data_cleanup_task')->insertGetId([
            'uuid' => self::uuid(),
            'name' => '测试数据清理',
            'code' => 'test_data_cleanup',
            'status' => 'queued',
            'created_by' => $accountId,
            'scope_json' => json_encode($scopes, JSON_UNESCAPED_UNICODE),
            'preserve_edu_data' => $preserveEduData ? 'true' : 'false',
            'progress' => 0,
            'total_rows' => 0,
            'affected_rows' => 0,
            'affected_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function taskById(int $id): ?array
    {
        $row = self::queryTable('data_cleanup_task')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$row) return null;
        return self::taskRow($row);
    }

    public static function claimTask(int $id, string $now): ?array
    {
        $affected = self::queryTable('data_cleanup_task')->where('id', $id)->where('status', 'queued')->update([
            'status' => 'processing', 'progress' => 1, 'started_at' => $now, 'updated_at' => $now,
        ]);
        return $affected ? self::taskById($id) : null;
    }

    public static function updateTask(int $id, array $values): void
    {
        self::queryTable('data_cleanup_task')->where('id', $id)->whereNull('deleted_at')->update($values);
    }

    public static function taskScopes(array $task): array
    {
        $value = json_decode((string) ($task['scope_json'] ?? '[]'), true);
        return array_values(array_intersect(array_keys(self::SCOPE_DEFINITIONS), is_array($value) ? $value : []));
    }

    public static function deleteScope(string $scope, bool $preserveEduData, int $limit, string $now): array
    {
        $definition = self::SCOPE_DEFINITIONS[$scope] ?? null;
        if (!$definition) return ['rows' => 0, 'tables' => []];
        if ($scope === 'operation_logs') return self::deleteOperationLogs($limit);
        if ($scope === 'accounts') return self::deleteAccounts($preserveEduData, $limit, $now);
        if ($scope === 'files') return self::deleteFiles($limit, $now);
        if ($scope === 'student_teacher') return self::deleteStudentTeacher($preserveEduData, $limit);

        $tables = $definition['tables'] ?? self::existingTablesByPrefixes($definition['prefixes'] ?? [], $definition['exclude'] ?? []);
        $rows = 0;
        $counts = [];
        foreach ($tables as $table) {
            if (!self::tableExists($table)) continue;
            $count = (int) self::queryTable($table)->limit($limit)->delete();
            if ($count > 0) {
                $rows += $count;
                $counts[$table] = $count;
            }
        }
        return ['rows' => $rows, 'tables' => $counts];
    }

    public static function scopeTableCount(string $scope, bool $preserveEduData): int
    {
        $definition = self::SCOPE_DEFINITIONS[$scope] ?? null;
        if (!$definition) return 0;
        if ($scope === 'operation_logs') {
            $database = CurrentContext::schoolDatabase();
            return array_sum(array_map(static fn (string $table): int => (int) self::queryTable($table)->count(), self::operationLogTables($database)));
        }
        if ($scope === 'accounts') return self::accountCount($preserveEduData);
        if ($scope === 'files') return self::fileCount();
        if ($scope === 'student_teacher') return self::studentTeacherCount($preserveEduData);
        $tables = $definition['tables'] ?? self::existingTablesByPrefixes($definition['prefixes'] ?? [], $definition['exclude'] ?? []);
        $total = 0;
        foreach ($tables as $table) if (self::tableExists($table)) $total += (int) self::queryTable($table)->count();
        return $total;
    }

    private static function deleteAccounts(bool $preserveEduData, int $limit, string $now): array
    {
        $userIds = self::queryTable('users')->where('id', '<>', 1);
        if ($preserveEduData) {
            $studentUsers = self::queryTable('students')->whereNotNull('user_id')->select('user_id');
            $teacherUsers = self::queryTable('teacher_list')->whereNotNull('user_id')->select('user_id');
            $userIds->whereNotIn('id', $studentUsers)->whereNotIn('id', $teacherUsers);
        }
        $ids = $userIds->limit($limit)->pluck('id')->all();
        if (!$ids) return ['rows' => 0, 'tables' => []];
        $counts = [];
        $counts['user_role'] = self::queryTable('user_role')->whereIn('account_id', self::queryTable('account')->whereIn('user_id', $ids)->pluck('id'))->delete();
        $counts['sys_organization'] = self::queryTable('sys_organization')->whereIn('user_id', $ids)->delete();
        $counts['account'] = self::queryTable('account')->whereIn('user_id', $ids)->delete();
        $counts['users'] = self::queryTable('users')->whereIn('id', $ids)->delete();
        return ['rows' => array_sum($counts), 'tables' => $counts];
    }

    private static function accountCount(bool $preserveEduData): int
    {
        $query = self::queryTable('users')->where('id', '<>', 1);
        if ($preserveEduData) {
            $query->whereNotIn('id', self::queryTable('students')->whereNotNull('user_id')->select('user_id'))
                ->whereNotIn('id', self::queryTable('teacher_list')->whereNotNull('user_id')->select('user_id'));
        }
        return (int) $query->count();
    }

    private static function deleteStudentTeacher(bool $preserveEduData, int $limit): array
    {
        $studentQuery = self::queryTable('students')->whereNull('deleted_at');
        $teacherQuery = self::queryTable('teacher_list')->whereNull('deleted_at');
        if ($preserveEduData) {
            $studentQuery->whereNotIn('student_id', self::queryTable('edu_student_source')->whereNotNull('student_id')->whereNull('deleted_at')->select('student_id'));
            $teacherQuery->where(function ($query): void {
                $query->whereNull('sync_source')->orWhere('sync_source', '');
            });
        }
        $students = $studentQuery->limit($limit)->delete();
        $teachers = $teacherQuery->limit($limit)->delete();
        return ['rows' => $students + $teachers, 'tables' => ['students' => $students, 'teacher_list' => $teachers]];
    }

    private static function studentTeacherCount(bool $preserveEduData): int
    {
        $studentQuery = self::queryTable('students')->whereNull('deleted_at');
        $teacherQuery = self::queryTable('teacher_list')->whereNull('deleted_at');
        if ($preserveEduData) {
            $studentQuery->whereNotIn('student_id', self::queryTable('edu_student_source')->whereNotNull('student_id')->whereNull('deleted_at')->select('student_id'));
            $teacherQuery->where(function ($query): void {
                $query->whereNull('sync_source')->orWhere('sync_source', '');
            });
        }
        return (int) $studentQuery->count() + (int) $teacherQuery->count();
    }

    private static function deleteOperationLogs(int $limit): array
    {
        $counts = [];
        foreach (self::operationLogTables(CurrentContext::schoolDatabase()) as $table) {
            $count = (int) self::queryTable($table)->limit($limit)->delete();
            if ($count) $counts[$table] = $count;
        }
        return ['rows' => array_sum($counts), 'tables' => $counts];
    }

    private static function deleteFiles(int $limit, string $now): array
    {
        $relation = self::queryTable('file_relation')->where('entity_type', '<>', 'system_release')->limit($limit)->delete();
        $files = self::queryTable('file')
            ->whereNotIn('id', self::queryTable('desktop_release_asset')->whereNotNull('file_id')->select('file_id'))
            ->where(function ($query): void {
                $query->whereNull('uploader_id')->orWhere('uploader_id', '<>', 1);
            })
            ->limit($limit)->delete();
        return ['rows' => $relation + $files, 'tables' => ['file_relation' => $relation, 'file' => $files]];
    }

    private static function fileCount(): int
    {
        return (int) self::queryTable('file_relation')->where('entity_type', '<>', 'system_release')->count()
            + (int) self::queryTable('file')
                ->whereNotIn('id', self::queryTable('desktop_release_asset')->whereNotNull('file_id')->select('file_id'))
                ->where(function ($query): void {
                    $query->whereNull('uploader_id')->orWhere('uploader_id', '<>', 1);
                })->count();
    }

    private static function existingTablesByPrefixes(array $prefixes, array $excluded = []): array
    {
        $database = self::connection()->getDatabaseName();
        return self::queryTable('information_schema.tables')->where('TABLE_SCHEMA', $database)->get(['TABLE_NAME'])
            ->map(static fn ($row): string => (string) $row->TABLE_NAME)
            ->filter(static fn (string $table): bool => !in_array($table, $excluded, true)
                && (bool) array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($table, $prefix)))
            ->values()->all();
    }

    private static function tableExists(string $table): bool
    {
        return (int) self::queryTable('information_schema.tables')->where('TABLE_SCHEMA', self::connection()->getDatabaseName())->where('TABLE_NAME', $table)->count() > 0;
    }

    private static function taskRow(mixed $row): array
    {
        $data = (array) $row;
        foreach (['scope_json', 'affected_json'] as $key) $data[$key] = json_decode((string) ($data[$key] ?? '[]'), true) ?: [];
        foreach (['id', 'created_by', 'progress', 'total_rows', 'affected_rows'] as $key) $data[$key] = (int) ($data[$key] ?? 0);
        $data['preserve_edu_data'] = ($data['preserve_edu_data'] ?? 'true') === 'true';
        return $data;
    }
}
