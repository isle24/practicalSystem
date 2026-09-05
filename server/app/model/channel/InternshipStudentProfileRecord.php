<?php

namespace app\model\channel;

use RuntimeException;

/** 实习学生任务的当前有效信息和历史变更查询。 */
class InternshipStudentProfileRecord extends TableRecord
{
    /** 查询学生任务当前有效资料。 */
    public static function currentByTask(int $studentId, int $arrangementId, bool $lock = false): ?object
    {
        self::ensureTable();

        $query = self::queryTable('internship_student_profile')
            ->where('student_id', $studentId)
            ->where('arrangement_id', $arrangementId)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** 首次使用学生任务资料时从任务和绑定关系初始化。 */
    public static function ensureForTask(int $studentId, int $arrangementId, string $now): object
    {
        self::ensureTable();

        $current = self::currentByTask($studentId, $arrangementId, true);
        if ($current) {
            return $current;
        }

        $source = self::queryTable('arrangement')
            ->leftJoin('pair', function ($join) use ($studentId, $arrangementId): void {
                $join->on('pair.arrangement_id', '=', 'arrangement.id')
                    ->where('pair.student_id', $studentId)
                    ->where('pair.type', 'internship')
                    ->where('pair.status', 'active')
                    ->whereNull('pair.deleted_at');
            })
            ->leftJoin('base', 'arrangement.base_id', '=', 'base.id')
            ->where('arrangement.id', $arrangementId)
            ->whereNull('arrangement.deleted_at')
            ->first([
                'arrangement.base_id', 'arrangement.location', 'arrangement.start_date', 'arrangement.end_date',
                'pair.id as pair_id', 'pair.enterprise_mentor_id', 'base.company_id',
            ]);
        if (!$source || (int) ($source->pair_id ?? 0) <= 0) {
            throw new RuntimeException('学生未绑定该实习任务');
        }

        $id = self::queryTable('internship_student_profile')->insertGetId([
            'uuid' => self::uuidValue(),
            'name' => '学生实习任务资料',
            'student_id' => $studentId,
            'arrangement_id' => $arrangementId,
            'pair_id' => (int) $source->pair_id,
            'company_id' => (int) ($source->company_id ?? 0) ?: null,
            'base_id' => (int) ($source->base_id ?? 0) ?: null,
            'enterprise_mentor_id' => (int) ($source->enterprise_mentor_id ?? 0) ?: null,
            'location' => $source->location,
            'position' => null,
            'start_date' => $source->start_date,
            'end_date' => $source->end_date,
            'effective_at' => $now,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        return self::currentByTask($studentId, $arrangementId, true) ?: throw new RuntimeException('学生实习资料初始化失败');
    }

    /** 从有效任务绑定批量补齐学生当前实习资料。 */
    public static function ensureForStudentTasks(int $studentId, array $arrangementIds, string $now): void
    {
        foreach (array_values(array_unique(array_filter(array_map('intval', $arrangementIds)))) as $arrangementId) {
            self::connection()->transaction(static function () use ($arrangementId, $now, $studentId): void {
                self::ensureForTask($studentId, $arrangementId, $now);
            });
        }
    }

    /** 将审核通过的个人变更应用为当前资料。 */
    public static function applyChange(int $profileId, array $payload, string $now): int
    {
        self::ensureTable();

        $profile = self::queryTable('internship_student_profile')
            ->where('id', $profileId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id', 'pair_id']);
        if (!$profile) {
            return 0;
        }

        $values = [
            'company_id' => self::nullableInt($payload['company_id'] ?? null),
            'base_id' => self::nullableInt($payload['base_id'] ?? null),
            'enterprise_mentor_id' => self::nullableInt($payload['enterprise_mentor_id'] ?? null),
            'location' => self::nullableString($payload['location'] ?? null),
            'position' => self::nullableString($payload['position'] ?? null),
            'start_date' => self::nullableString($payload['start_date'] ?? null),
            'end_date' => self::nullableString($payload['end_date'] ?? null),
            'status' => ($payload['status'] ?? 'active') === 'terminated' ? 'terminated' : 'active',
            'terminated_at' => ($payload['status'] ?? '') === 'terminated' ? ($payload['terminated_at'] ?? $now) : null,
            'termination_reason' => self::nullableString($payload['termination_reason'] ?? null),
            'effective_at' => $now,
            'updated_at' => $now,
        ];

        $updated = (int) self::queryTable('internship_student_profile')
            ->where('id', $profileId)
            ->whereNull('deleted_at')
            ->update($values);

        if (array_key_exists('enterprise_mentor_id', $payload) && (int) ($profile->pair_id ?? 0) > 0) {
            self::queryTable('pair')
                ->where('id', (int) $profile->pair_id)
                ->where('type', 'internship')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->update([
                    'enterprise_mentor_id' => self::nullableInt($payload['enterprise_mentor_id'] ?? null),
                    'updated_at' => $now,
                ]);
        }

        return $updated;
    }

    /** 校验实习单位、基地和企业导师引用。 */
    public static function referenceIssue(array $payload): ?string
    {
        $companyId = self::nullableInt($payload['company_id'] ?? null);
        $baseId = self::nullableInt($payload['base_id'] ?? null);
        $mentorId = self::nullableInt($payload['enterprise_mentor_id'] ?? null);

        if ($companyId && !self::queryTable('companies')->where('company_id', $companyId)->where('flag', 'on')->whereNull('deleted_at')->exists()) {
            return '实习单位不存在或已停用';
        }

        $base = $baseId
            ? self::queryTable('base')->where('id', $baseId)->where('status', 'enabled')->whereNull('deleted_at')->first(['company_id'])
            : null;
        if ($baseId && !$base) {
            return '实习基地不存在或已停用';
        }
        if ($base && $companyId && (int) ($base->company_id ?? 0) !== $companyId) {
            return '实习基地不属于所选实习单位';
        }

        $mentor = $mentorId
            ? self::queryTable('enterprise_mentor')->where('id', $mentorId)->where('status', 'enabled')->whereNull('deleted_at')->first(['company_id'])
            : null;
        if ($mentorId && !$mentor) {
            return '企业导师不存在或已停用';
        }
        if ($mentor && $companyId && (int) ($mentor->company_id ?? 0) !== $companyId) {
            return '企业导师不属于所选实习单位';
        }

        return null;
    }

    /** 查询当前学校范围内的学生任务资料。 */
    public static function page(array $scope, array $filters): array
    {
        self::ensureTable();

        $query = self::queryTable('internship_student_profile as profile')
            ->join('students', 'profile.student_id', '=', 'students.student_id')
            ->join('arrangement', 'profile.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('internship_plan', 'arrangement.plan_id', '=', 'internship_plan.id')
            ->leftJoin('internship_category', 'internship_plan.category_id', '=', 'internship_category.id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->leftJoin('enterprise_mentor', 'profile.enterprise_mentor_id', '=', 'enterprise_mentor.id')
            ->whereNull('profile.deleted_at')
            ->whereNull('students.deleted_at')
            ->whereNull('arrangement.deleted_at');

        self::applyScope($query, $scope);
        self::applyCategoryScope($query, $filters);
        self::filter($query, 'profile.status', $filters['status'] ?? null);
        self::filter($query, 'profile.arrangement_id', $filters['arrangement_id'] ?? null);
        self::filter($query, 'profile.student_id', $filters['student_id'] ?? null);
        self::filter($query, 'students.dep_id', $filters['dep_id'] ?? null);
        self::filter($query, 'students.profession_id', $filters['profession_id'] ?? null);
        if (!self::hasCategoryFilter($filters)) {
            self::filter($query, 'students.grade_id', $filters['grade_id'] ?? null);
            self::filter($query, 'students.graduation_cohort_id', $filters['graduation_cohort_id'] ?? null);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('students.name', 'like', $like)
                    ->orWhere('students.student_num', 'like', $like)
                    ->orWhere('arrangement.title', 'like', $like)
                    ->orWhere('internship_plan.course_name', 'like', $like)
                    ->orWhere('enterprise_mentor.name', 'like', $like);
            });
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $total = (int) (clone $query)->count('profile.id');
        $rows = $query->orderByDesc('profile.id')->forPage($page, $pageSize)->get([
            'profile.*',
            'students.name as student_name', 'students.student_num', 'students.grade_id', 'students.dep_id', 'students.profession_id', 'students.class_id',
            'department.dep_name', 'profession.profession_name', 'class.class_name',
            'arrangement.title as arrangement_title', 'arrangement.task_no', 'internship_plan.course_name',
            'internship_plan.category_id', 'internship_category.name as category_name', 'internship_category.scope_type',
            'enterprise_mentor.name as enterprise_mentor_name',
        ])->map(static fn ($row): array => $row->toArray())->all();

        return [
            'items' => $rows,
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
        ];
    }

    /** 将当前有效资料转成变更快照。 */
    public static function snapshot(object $profile): array
    {
        return [
            'company_id' => self::nullableInt($profile->company_id ?? null),
            'base_id' => self::nullableInt($profile->base_id ?? null),
            'enterprise_mentor_id' => self::nullableInt($profile->enterprise_mentor_id ?? null),
            'location' => $profile->location ?? null,
            'position' => $profile->position ?? null,
            'start_date' => $profile->start_date ?? null,
            'end_date' => $profile->end_date ?? null,
            'status' => $profile->status ?? 'active',
            'terminated_at' => $profile->terminated_at ?? null,
            'termination_reason' => $profile->termination_reason ?? null,
        ];
    }

    private static function applyScope(mixed $query, array $scope): void
    {
        $role = (string) ($scope['role_type'] ?? '');
        if ($role === 'student') {
            $studentId = (int) ($scope['student_id'] ?? 0);
            $studentId > 0
                ? $query->where('profile.student_id', $studentId)
                : $query->whereRaw('1 = 0');
        } elseif ($role === 'teacher') {
            $teacherId = (int) ($scope['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                $query->whereRaw('1 = 0');
                return;
            }
            $query->whereExists(function ($subQuery) use ($scope): void {
                $subQuery->selectRaw('1')->from('pair as scope_pair')
                    ->whereColumn('scope_pair.student_id', 'profile.student_id')
                    ->whereColumn('scope_pair.arrangement_id', 'profile.arrangement_id')
                    ->where(function ($teacherQuery) use ($scope): void {
                        $teacherId = (int) $scope['teacher_id'];
                        $teacherQuery->where('scope_pair.teacher_id', $teacherId)
                            ->orWhere('scope_pair.second_teacher_id', $teacherId);
                    })
                    ->where('scope_pair.type', 'internship')
                    ->where('scope_pair.status', 'active')
                    ->whereNull('scope_pair.deleted_at');
            });
        } elseif (in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        } elseif ($role === 'college_admin') {
            self::whereInOrDeny($query, 'students.dep_id', (array) ($scope['dep_ids'] ?? []));
        } elseif ($role === 'profession_admin') {
            self::whereInOrDeny($query, 'students.profession_id', (array) ($scope['profession_ids'] ?? []));
        } else {
            $query->whereRaw('1 = 0');
        }
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

    private static function filter(mixed $query, string $column, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $query->where($column, $value);
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

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, 5000);
    }

    private static function ensureTable(): void
    {
        self::requireTables(['internship_student_profile']);
    }

    /** 生成学生资料 UUID。 */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
