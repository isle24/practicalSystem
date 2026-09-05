<?php

namespace app\model\channel;

/** 学生个人实习信息变更申请及历史记录。 */
class InternshipStudentChangeRecord extends TableRecord
{
    /** 查询当前学校范围内的变更申请。 */
    public static function page(array $scope, array $filters): array
    {
        self::ensureTable();

        $query = self::queryTable('internship_student_change as change')
            ->join('students', 'change.student_id', '=', 'students.student_id')
            ->join('arrangement', 'change.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('internship_plan', 'arrangement.plan_id', '=', 'internship_plan.id')
            ->leftJoin('internship_category', 'internship_plan.category_id', '=', 'internship_category.id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->whereNull('change.deleted_at')
            ->whereNull('students.deleted_at')
            ->whereNull('arrangement.deleted_at');
        self::applyScope($query, $scope);
        self::applyCategoryScope($query, $filters);
        foreach (['id', 'status', 'arrangement_id', 'student_id'] as $field) {
            $value = $filters[$field] ?? null;
            if ($value !== null && $value !== '') {
                $query->where('change.' . $field, $value);
            }
        }
        $academicFields = ['dep_id', 'profession_id'];
        if (!self::hasCategoryFilter($filters)) {
            $academicFields = array_merge($academicFields, ['grade_id', 'graduation_cohort_id']);
        }
        foreach ($academicFields as $field) {
            $value = $filters[$field] ?? null;
            if ($value !== null && $value !== '') {
                $query->where('students.' . $field, $value);
            }
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('students.name', 'like', $like)
                    ->orWhere('students.student_num', 'like', $like)
                    ->orWhere('arrangement.title', 'like', $like)
                    ->orWhere('internship_plan.course_name', 'like', $like)
                    ->orWhere('change.reason', 'like', $like);
            });
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $total = (int) (clone $query)->count('change.id');
        $items = $query->orderByDesc('change.id')->forPage($page, $pageSize)->get([
            'change.*', 'students.name as student_name', 'students.student_num', 'students.dep_id', 'students.profession_id', 'students.grade_id', 'students.graduation_cohort_id',
            'department.dep_name', 'profession.profession_name', 'arrangement.title as arrangement_title', 'internship_plan.course_name',
            'internship_plan.category_id', 'internship_category.name as category_name', 'internship_category.scope_type',
        ])->map(static function ($row): array {
            $item = $row->toArray();
            foreach (['before_payload', 'after_payload'] as $key) {
                $value = $item[$key] ?? null;
                $decoded = is_string($value) ? json_decode($value, true) : $value;
                $item[$key] = is_array($decoded) ? $decoded : [];
            }
            return $item;
        })->all();

        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total]];
    }

    /** 查询单条变更申请并校验可见范围。 */
    public static function visibleById(array $scope, int $id): ?object
    {
        self::ensureTable();
        if ($id <= 0) {
            return null;
        }

        $query = self::queryTable('internship_student_change as change')
            ->join('students', 'change.student_id', '=', 'students.student_id')
            ->join('arrangement', 'change.arrangement_id', '=', 'arrangement.id')
            ->where('change.id', $id)
            ->whereNull('change.deleted_at')
            ->whereNull('students.deleted_at')
            ->whereNull('arrangement.deleted_at');
        self::applyScope($query, $scope);

        return $query->first([
            'change.*',
            'students.name as student_name',
            'students.student_num',
            'students.dep_id',
            'students.profession_id',
            'students.grade_id',
            'students.graduation_cohort_id',
            'arrangement.title as arrangement_title',
            'arrangement.task_no',
        ]);
    }

    /** 直接查询变更申请，调用方需先完成任务范围校验。 */
    public static function byId(int $id, bool $lock = false): ?object
    {
        self::ensureTable();
        $query = self::queryTable('internship_student_change')->where('id', $id)->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->first();
    }

    /** 保存变更申请主记录。 */
    public static function saveChange(int $id, array $values, string $now): int
    {
        self::ensureTable();
        if ($id > 0) {
            self::queryTable('internship_student_change')->where('id', $id)->whereNull('deleted_at')->update(array_merge($values, ['updated_at' => $now]));
            return $id;
        }
        return (int) self::queryTable('internship_student_change')->insertGetId(array_merge([
            'uuid' => self::uuidValue(), 'name' => '学生实习信息变更', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ], $values));
    }

    /** 审核变更并写入审核信息。 */
    public static function review(int $id, string $status, int $reviewerId, string $opinion, string $now): int
    {
        self::ensureTable();
        return (int) self::queryTable('internship_student_change')->where('id', $id)->whereNull('deleted_at')->update([
            'status' => $status, 'reviewer_id' => $reviewerId, 'review_opinion' => $opinion, 'reviewed_at' => $now, 'updated_at' => $now,
        ]);
    }

    private static function applyScope(mixed $query, array $scope): void
    {
        $role = (string) ($scope['role_type'] ?? '');
        if ($role === 'student') {
            $studentId = (int) ($scope['student_id'] ?? 0);
            $studentId > 0
                ? $query->where('change.student_id', $studentId)
                : $query->whereRaw('1 = 0');
            return;
        }
        $query->where('change.status', '<>', 'draft');
        if ($role === 'teacher') {
            $teacherId = (int) ($scope['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                $query->whereRaw('1 = 0');
                return;
            }
            $query->whereExists(function ($subQuery) use ($scope): void {
                $subQuery->selectRaw('1')->from('pair as scope_pair')
                    ->whereColumn('scope_pair.student_id', 'change.student_id')
                    ->whereColumn('scope_pair.arrangement_id', 'change.arrangement_id')
                    ->where(function ($teacherQuery) use ($scope): void {
                        $teacherId = (int) $scope['teacher_id'];
                        $teacherQuery->where('scope_pair.teacher_id', $teacherId)
                            ->orWhere('scope_pair.second_teacher_id', $teacherId);
                    })
                    ->where('scope_pair.type', 'internship')
                    ->where('scope_pair.status', 'active')
                    ->whereNull('scope_pair.deleted_at');
            });
            return;
        }
        if (in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        }
        if ($role === 'college_admin') {
            self::whereInOrDeny($query, 'students.dep_id', (array) ($scope['dep_ids'] ?? []));
            return;
        }
        if ($role === 'profession_admin') {
            self::whereInOrDeny($query, 'students.profession_id', (array) ($scope['profession_ids'] ?? []));
            return;
        }
        $query->whereRaw('1 = 0');
    }

    private static function whereInOrDeny(mixed $query, string $column, array $values): void
    {
        $values = array_values(array_filter(array_map('intval', $values), static fn (int $value): bool => $value > 0));
        if ($values) {
            $query->whereIn($column, $values);
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    /** 按实习类别只应用对应的年级或毕业届次筛选。 */
    private static function applyCategoryScope(mixed $query, array $filters): void
    {
        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return;
        }

        $query->where('internship_plan.category_id', $categoryId);
        $scopeType = (string) (self::queryTable('internship_category')
            ->where('id', $categoryId)
            ->whereNull('deleted_at')
            ->value('scope_type') ?: 'grade');
        if ($scopeType === 'cohort') {
            self::filter($query, 'students.graduation_cohort_id', $filters['graduation_cohort_id'] ?? null);
            return;
        }

        self::filter($query, 'students.grade_id', $filters['grade_id'] ?? null);
    }

    /** 判断请求是否已经指定实习类别。 */
    private static function hasCategoryFilter(array $filters): bool
    {
        return (int) ($filters['category_id'] ?? 0) > 0;
    }

    /** 添加可复用的学生维度筛选。 */
    private static function filter(mixed $query, string $column, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $query->where($column, $value);
        }
    }

    private static function ensureTable(): void
    {
        self::requireTables(['internship_student_change', 'internship_student_change_recording']);
    }

    /** 生成变更申请 UUID。 */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
