<?php

namespace app\model\channel;

class EducationPlanSyncRecord extends TableRecord
{
    protected $table = 'internship_plan_sync_inbox';
    protected $guarded = [];
    public $timestamps = false;

    /** 在学校业务库事务中执行同步写入 */
    public static function transaction(callable $callback): mixed
    {
        return (new static())->getConnection()->transaction($callback);
    }

    /** 按代码查询启用年级 */
    public static function gradeByCode(string $code): ?array
    {
        $row = self::queryTable('grade_list')
            ->where('grade_code', $code)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['grade_id', 'grade_code', 'grade_name']);

        return $row ? self::row($row) : null;
    }

    /** 按代码查询启用学院 */
    public static function departmentByCode(string $code): ?array
    {
        $row = self::queryTable('department')
            ->where('dep_code', $code)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['dep_id', 'dep_code', 'dep_name']);

        return $row ? self::row($row) : null;
    }

    /** 按学院和代码查询启用专业 */
    public static function professionByCode(string $code, int $departmentId): ?array
    {
        $row = self::queryTable('profession')
            ->where('profession_code', $code)
            ->where('dep_id', $departmentId)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['profession_id', 'profession_code', 'profession_name', 'dep_id', 'grade_id']);

        return $row ? self::row($row) : null;
    }

    /** 按来源计划和版本查询暂存记录 */
    public static function inboxBySourceVersion(string $sourcePlanId, string $sourceVersion): ?array
    {
        $row = self::queryTable('internship_plan_sync_inbox')
            ->where('source_plan_id', $sourcePlanId)
            ->where('source_version', $sourceVersion)
            ->whereNull('deleted_at')
            ->first();

        return $row ? self::row($row, ['raw_payload', 'diff_payload']) : null;
    }

    /** 按来源计划查询最近生成的本地计划 */
    public static function localPlanBySourceId(string $sourcePlanId): ?array
    {
        $row = self::queryTable('internship_plan')
            ->where('source_plan_id', $sourcePlanId)
            ->whereNull('deleted_at')
            ->orderByDesc('source_last_synced_at')
            ->orderByDesc('id')
            ->first([
                'id', 'source_plan_id', 'source_version', 'source_last_synced_at',
                'category_id', 'grade_id', 'graduation_cohort_id', 'dep_id', 'profession_id',
                'course_code', 'course_name', 'course_category', 'credit', 'total_credit',
                'internship_credit', 'total_hours', 'internship_hours', 'status',
            ]);

        return $row ? self::row($row) : null;
    }

    /** 分页查询当前组织范围内的待接收计划 */
    public static function inboxPage(array $scope, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::queryTable('internship_plan_sync_inbox')
            ->leftJoin('grade_list', 'internship_plan_sync_inbox.mapped_grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'internship_plan_sync_inbox.mapped_dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'internship_plan_sync_inbox.mapped_profession_id', '=', 'profession.profession_id')
            ->leftJoin('internship_plan', 'internship_plan_sync_inbox.local_plan_id', '=', 'internship_plan.id')
            ->whereNull('internship_plan_sync_inbox.deleted_at');
        self::applyScope($query, $scope);
        self::filterInboxStatus($query, $filters);
        self::filter($query, $filters, 'internship_plan_sync_inbox.mapping_status', 'mapping_status');
        self::filter($query, $filters, 'internship_plan_sync_inbox.mapped_grade_id', 'grade_id');
        self::filter($query, $filters, 'internship_plan_sync_inbox.mapped_dep_id', 'dep_id');
        self::filter($query, $filters, 'internship_plan_sync_inbox.mapped_profession_id', 'profession_id');
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('internship_plan_sync_inbox.source_plan_id', 'like', $like)
                    ->orWhere('internship_plan_sync_inbox.course_code', 'like', $like)
                    ->orWhere('internship_plan_sync_inbox.course_name', 'like', $like)
                    ->orWhere('internship_plan_sync_inbox.dep_name', 'like', $like)
                    ->orWhere('internship_plan_sync_inbox.profession_name', 'like', $like);
            });
        }

        $total = (int) (clone $query)->count('internship_plan_sync_inbox.id');
        $items = $query->orderByDesc('internship_plan_sync_inbox.received_at')
            ->orderByDesc('internship_plan_sync_inbox.id')
            ->forPage($page, $pageSize)
            ->get([
                'internship_plan_sync_inbox.*',
                'grade_list.grade_name as mapped_grade_name',
                'department.dep_name as mapped_dep_name',
                'profession.profession_name as mapped_profession_name',
                'internship_plan.status as local_plan_status',
            ])
            ->map(static fn ($row): array => self::row($row, ['raw_payload', 'diff_payload']))
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

    /** 查询当前组织范围内的单条暂存计划 */
    public static function inboxRow(array $scope, int $id, bool $lock = false): ?array
    {
        $query = self::queryTable('internship_plan_sync_inbox')
            ->where('internship_plan_sync_inbox.id', $id)
            ->whereNull('internship_plan_sync_inbox.deleted_at');
        self::applyScope($query, $scope);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row ? self::row($row, ['raw_payload', 'diff_payload']) : null;
    }

    /** 新增或刷新同一来源版本的暂存计划 */
    public static function upsertInbox(array $values, string $now): array
    {
        $query = self::queryTable('internship_plan_sync_inbox')
            ->where('source_plan_id', $values['source_plan_id'])
            ->where('source_version', $values['source_version']);
        $existing = $query->lockForUpdate()->first(['id', 'status', 'local_plan_id']);
        if ($existing) {
            $terminal = in_array((string) $existing->status, ['generated', 'ignored'], true);
            if ($terminal) {
                unset($values['status'], $values['local_plan_id']);
            }
            $query->update(array_merge($values, [
                'updated_at' => $now,
                'deleted_at' => null,
            ]));
            return [
                'id' => (int) $existing->id,
                'result' => $terminal ? 'duplicate' : 'updated',
                'status' => $terminal ? (string) $existing->status : (string) ($values['status'] ?? $existing->status),
            ];
        }

        $id = (int) self::queryTable('internship_plan_sync_inbox')->insertGetId(array_merge($values, [
            'uuid' => self::uuidValue(),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]));

        return ['id' => $id, 'result' => 'created', 'status' => (string) ($values['status'] ?? 'pending')];
    }

    /** 创建教务来源的本地计划草稿 */
    public static function createLocalPlan(array $values): int
    {
        return (int) self::queryTable('internship_plan')->insertGetId($values);
    }

    /** 锁定教务来源的本地计划 */
    public static function lockLocalPlan(int $planId): ?array
    {
        $row = self::queryTable('internship_plan')
            ->where('id', $planId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        return $row ? self::row($row, ['source_row', 'plan_content']) : null;
    }

    /** 更新教务来源的本地计划 */
    public static function updateLocalPlan(int $planId, array $values): int
    {
        return self::queryTable('internship_plan')->where('id', $planId)->update($values);
    }

    /** 判断计划是否已有任务或实施数据 */
    public static function localPlanHasBusinessData(int $planId): bool
    {
        if ($planId <= 0) {
            return false;
        }
        if (self::queryTable('arrangement')->where('plan_id', $planId)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return self::queryTable('implementation_sheet')
            ->join('arrangement', 'implementation_sheet.arrangement_id', '=', 'arrangement.id')
            ->where('arrangement.plan_id', $planId)
            ->whereNull('implementation_sheet.deleted_at')
            ->whereNull('arrangement.deleted_at')
            ->exists();
    }

    /** 更新暂存计划状态和关联信息 */
    public static function updateInbox(int $id, array $values): int
    {
        return self::queryTable('internship_plan_sync_inbox')->where('id', $id)->update($values);
    }

    /** 应用学院或专业管理范围 */
    private static function applyScope(mixed $query, array $scope): void
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return;
        }
        if ($roleType === 'college_admin') {
            $ids = self::ids((array) ($scope['dep_ids'] ?? []));
            $ids ? $query->whereIn('internship_plan_sync_inbox.mapped_dep_id', $ids) : $query->whereRaw('1 = 0');
            return;
        }
        if ($roleType === 'profession_admin') {
            $ids = self::ids((array) ($scope['profession_ids'] ?? []));
            $ids ? $query->whereIn('internship_plan_sync_inbox.mapped_profession_id', $ids) : $query->whereRaw('1 = 0');
            return;
        }

        $query->whereRaw('1 = 0');
    }

    /** 应用等值筛选 */
    private static function filter(mixed $query, array $filters, string $column, string $key): void
    {
        $value = $filters[$key] ?? null;
        if ($value !== null && $value !== '') {
            $query->where($column, $value);
        }
    }

    /** 按同步工作台状态筛选暂存计划。 */
    private static function filterInboxStatus(mixed $query, array $filters): void
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'pending') {
            $query->whereIn('internship_plan_sync_inbox.status', ['pending', 'change_pending', 'mapping_failed']);
            return;
        }
        if ($status !== '') {
            $query->where('internship_plan_sync_inbox.status', $status);
        }
    }

    /** 转换查询结果并解码 JSON 字段 */
    private static function row(object $row, array $jsonFields = []): array
    {
        $values = method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
        foreach ($jsonFields as $field) {
            $decoded = json_decode((string) ($values[$field] ?? ''), true);
            $values[$field] = is_array($decoded) ? $decoded : [];
        }
        return $values;
    }

    /** 规范化主键数组 */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    /** 生成 UUID */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
