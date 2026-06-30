<?php

namespace app\model\channel;

class Account extends BaseModel
{
    protected $table = 'account';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    public static function adminPage(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::query()
            ->join('users', 'account.user_id', '=', 'users.id')
            ->leftJoin('user_role', function ($join): void {
                $join->on('account.id', '=', 'user_role.account_id')
                    ->where('user_role.is_primary', 'true')
                    ->whereNull('user_role.deleted_at');
            })
            ->leftJoin('role', 'user_role.role_id', '=', 'role.id')
            ->whereNull('account.deleted_at')
            ->whereNull('users.deleted_at');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('account.login_name', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhere('users.mobile', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('role.name', 'like', $like);
            });
        }

        $roleType = trim((string) ($filters['role_type'] ?? ''));
        if ($roleType !== '') {
            $query->where('role.role_type', $roleType);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['enabled', 'disabled'], true)) {
            $query->where('account.status', $status);
        }

        self::applyAdminVisibilityScope($query, (array) ($filters['admin_scope'] ?? []));

        $total = (clone $query)->count();
        $items = $query
            ->orderBy('account.id')
            ->forPage($page, $pageSize)
            ->get([
                'account.id',
                'account.uuid',
                'account.user_id',
                'account.login_name',
                'account.status',
                'account.created_at',
                'users.name',
                'users.mobile',
                'users.email',
                'users.avatar',
                'users.status as user_status',
                'role.id as role_id',
                'role.name as role_name',
                'role.role_type',
            ])
            ->map(static fn ($row): array => array_merge([
                'id' => (int) $row->id,
                'uuid' => $row->uuid,
                'user_id' => (int) $row->user_id,
                'login_name' => $row->login_name,
                'status' => $row->status,
                'created_at' => $row->created_at,
                'name' => $row->name,
                'mobile' => $row->mobile,
                'email' => $row->email,
                'avatar' => $row->avatar,
                'user_status' => $row->user_status,
                'role_id' => $row->role_id === null ? null : (int) $row->role_id,
                'role_name' => $row->role_name,
                'role_type' => $row->role_type,
            ], self::organizationProfile((int) $row->user_id, (string) $row->role_type)))
            ->all();

        return [
            'accounts' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => (int) $total,
            ],
        ];
    }

    public static function adminDetail(int $id): ?array
    {
        $row = self::query()
            ->join('users', 'account.user_id', '=', 'users.id')
            ->leftJoin('user_role', function ($join): void {
                $join->on('account.id', '=', 'user_role.account_id')
                    ->where('user_role.is_primary', 'true')
                    ->whereNull('user_role.deleted_at');
            })
            ->leftJoin('role', 'user_role.role_id', '=', 'role.id')
            ->where('account.id', $id)
            ->whereNull('account.deleted_at')
            ->whereNull('users.deleted_at')
            ->first([
                'account.id',
                'account.uuid',
                'account.user_id',
                'account.login_name',
                'account.status',
                'account.two_factor_enabled',
                'account.created_at',
                'account.updated_at',
                'users.name',
                'users.mobile',
                'users.email',
                'users.avatar',
                'users.status as user_status',
                'role.id as role_id',
                'role.name as role_name',
                'role.role_type',
            ]);

        return $row ? self::adminAccountRow($row) : null;
    }

    public static function boundAccountsByUser(int $userId, int $excludeAccountId): array
    {
        return self::query()
            ->leftJoin('user_role', function ($join): void {
                $join->on('account.id', '=', 'user_role.account_id')
                    ->where('user_role.is_primary', 'true')
                    ->whereNull('user_role.deleted_at');
            })
            ->leftJoin('role', 'user_role.role_id', '=', 'role.id')
            ->where('account.user_id', $userId)
            ->where('account.id', '<>', $excludeAccountId)
            ->whereNull('account.deleted_at')
            ->orderBy('account.id')
            ->get([
                'account.id',
                'account.uuid',
                'account.user_id',
                'account.login_name',
                'account.status',
                'account.two_factor_enabled',
                'account.created_at',
                'account.updated_at',
                'role.id as role_id',
                'role.name as role_name',
                'role.role_type',
            ])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'uuid' => $row->uuid,
                'user_id' => (int) $row->user_id,
                'login_name' => $row->login_name,
                'status' => $row->status,
                'two_factor_enabled' => $row->two_factor_enabled,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'role_id' => $row->role_id === null ? null : (int) $row->role_id,
                'role_name' => $row->role_name,
                'role_type' => $row->role_type,
            ])
            ->all();
    }

    public static function enabledByLoginName(string $loginName): ?self
    {
        return self::query()
            ->where('login_name', $loginName)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first();
    }

    public static function activeById(int $id, array $columns = ['*']): ?self
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function enabledById(int $id, array $columns = ['*']): ?self
    {
        return self::query()
            ->where('id', $id)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function enabledWithPrimaryRole(): array
    {
        return self::query()
            ->join('users', 'account.user_id', '=', 'users.id')
            ->leftJoin('user_role', function ($join): void {
                $join->on('account.id', '=', 'user_role.account_id')
                    ->where('user_role.is_primary', 'true')
                    ->whereNull('user_role.deleted_at');
            })
            ->leftJoin('role', 'user_role.role_id', '=', 'role.id')
            ->where('account.status', 'enabled')
            ->where('users.status', 'enabled')
            ->whereNull('account.deleted_at')
            ->whereNull('users.deleted_at')
            ->orderBy('account.id')
            ->get([
                'account.id',
                'account.user_id',
                'account.login_name',
                'users.name',
                'role.id as role_id',
                'role.name as role_name',
                'role.role_type',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public static function messageTargets(array $filters = []): array
    {
        $limit = min(500, max(20, (int) ($filters['limit'] ?? 200)));

        return self::messageTargetQuery($filters)
            ->orderBy('role.sort')
            ->orderBy('account.id')
            ->limit($limit)
            ->get([
                'account.id',
                'account.user_id',
                'account.login_name',
                'users.name',
                'users.mobile',
                'role.id as role_id',
                'role.name as role_name',
                'role.role_type',
            ])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'login_name' => $row->login_name,
                'name' => $row->name,
                'mobile' => $row->mobile,
                'role_id' => $row->role_id === null ? null : (int) $row->role_id,
                'role_name' => $row->role_name,
                'role_type' => $row->role_type,
            ])
            ->all();
    }

    public static function messageTargetIds(array $filters = []): array
    {
        $limit = min(20000, max(1, (int) ($filters['limit'] ?? 20000)));

        return self::messageTargetQuery($filters)
            ->orderBy('role.sort')
            ->orderBy('account.id')
            ->limit($limit)
            ->pluck('account.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function enabledIdsByUserIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids) {
            return [];
        }

        return self::query()
            ->whereIn('user_id', $ids)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function switchableAccounts(int $accountId): array
    {
        $identity = self::identityByAccountId($accountId);
        if (!$identity) {
            return [];
        }

        $query = self::accountListQuery()
            ->where(function ($builder) use ($identity): void {
                $builder->where('account.user_id', (int) $identity['user_id']);
                $mobile = trim((string) ($identity['mobile'] ?? ''));
                if ($mobile !== '') {
                    $builder->orWhere('users.mobile', $mobile);
                }
            })
            ->whereNotNull('role.id')
            ->where('role.status', 'enabled')
            ->whereNull('role.deleted_at');

        return $query
            ->orderBy('role.sort')
            ->orderBy('account.id')
            ->get([
                'account.id',
                'account.user_id',
                'account.login_name',
                'account.status',
                'users.name',
                'users.mobile',
                'role.id as role_id',
                'role.name as role_name',
                'role.role_type',
            ])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'login_name' => $row->login_name,
                'status' => $row->status,
                'name' => $row->name,
                'mobile' => $row->mobile,
                'role_id' => $row->role_id === null ? null : (int) $row->role_id,
                'role_name' => $row->role_name,
                'role_type' => $row->role_type,
                'is_current' => (int) $row->id === $accountId,
            ])
            ->all();
    }

    public static function canSwitchBetween(int $currentAccountId, int $targetAccountId): bool
    {
        if ($currentAccountId === $targetAccountId) {
            return true;
        }

        $current = self::identityByAccountId($currentAccountId);
        $target = self::identityByAccountId($targetAccountId);
        if (!$current || !$target) {
            return false;
        }

        if ((int) $current['user_id'] === (int) $target['user_id']) {
            return true;
        }

        $currentMobile = trim((string) ($current['mobile'] ?? ''));
        $targetMobile = trim((string) ($target['mobile'] ?? ''));
        return $currentMobile !== '' && $currentMobile === $targetMobile;
    }

    public static function adminLoginTargetProfile(int $accountId): ?array
    {
        $row = self::accountListQuery()
            ->where('account.id', $accountId)
            ->first([
                'account.id',
                'account.user_id',
                'account.login_name',
                'users.name',
                'users.mobile',
                'role.id as role_id',
                'role.name as role_name',
                'role.role_type',
            ]);

        if (!$row) {
            return null;
        }

        return array_merge([
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'login_name' => $row->login_name,
            'name' => $row->name,
            'mobile' => $row->mobile,
            'role_id' => $row->role_id === null ? null : (int) $row->role_id,
            'role_name' => $row->role_name,
            'role_type' => $row->role_type,
            'dep_id' => null,
            'profession_id' => null,
            'class_id' => null,
        ], self::organizationProfile((int) $row->user_id, (string) $row->role_type));
    }

    public static function professionDepartmentIds(array $professionIds): array
    {
        $ids = self::intIds($professionIds);
        if (!$ids) {
            return [];
        }

        return TableRecord::queryTable('profession')
            ->whereIn('profession_id', $ids)
            ->whereNull('deleted_at')
            ->pluck('dep_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function messageTargetQuery(array $filters = []): mixed
    {
        $query = self::query()
            ->join('users', 'account.user_id', '=', 'users.id')
            ->leftJoin('user_role', function ($join): void {
                $join->on('account.id', '=', 'user_role.account_id')
                    ->where('user_role.is_primary', 'true')
                    ->whereNull('user_role.deleted_at');
            })
            ->leftJoin('role', 'user_role.role_id', '=', 'role.id')
            ->where('account.status', 'enabled')
            ->where('users.status', 'enabled')
            ->whereNull('account.deleted_at')
            ->whereNull('users.deleted_at');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('account.login_name', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhere('users.mobile', 'like', $like)
                    ->orWhere('role.name', 'like', $like);
            });
        }

        $roleType = trim((string) ($filters['role_type'] ?? ''));
        if ($roleType !== '') {
            $query->where('role.role_type', $roleType);
        }

        return $query;
    }

    public static function loginNameExists(string $loginName, ?int $excludeId = null): bool
    {
        $query = self::query()
            ->where('login_name', $loginName)
            ->whereNull('deleted_at');

        if ($excludeId) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->exists();
    }

    public static function registerRoleOptions(array $roleTypes = []): array
    {
        $query = TableRecord::queryTable('role')
            ->where('status', 'enabled')
            ->whereNull('deleted_at');

        if ($roleTypes) {
            $query->whereIn('role_type', $roleTypes);
        }

        return $query
            ->orderBy('sort')
            ->get(['id', 'code', 'name', 'role_type'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'role_type' => $row->role_type,
            ])
            ->all();
    }

    public static function registerRoleByType(string $roleType): ?array
    {
        $row = TableRecord::queryTable('role')
            ->where('role_type', $roleType)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->first(['id', 'code', 'name', 'role_type']);

        return $row ? [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'role_type' => $row->role_type,
        ] : null;
    }

    public static function saveRoleProfile(string $roleType, int $userId, string $name, string $status, array $values, string $now): void
    {
        if ($roleType === 'student') {
            self::saveStudentProfile($userId, $name, $status, $values, $now);
            return;
        }

        if ($roleType === 'teacher') {
            self::saveTeacherProfile($userId, $name, $status, $values, $now);
        }
    }

    public static function primaryRoleTypeById(int $id): ?string
    {
        return self::query()
            ->leftJoin('user_role', function ($join): void {
                $join->on('account.id', '=', 'user_role.account_id')
                    ->where('user_role.is_primary', 'true')
                    ->whereNull('user_role.deleted_at');
            })
            ->leftJoin('role', 'user_role.role_id', '=', 'role.id')
            ->where('account.id', $id)
            ->whereNull('account.deleted_at')
            ->value('role.role_type');
    }

    public static function createAdminAccount(int $userId, array $values): self
    {
        return self::query()->create(array_merge([
            'uuid' => self::uuid(),
            'user_id' => $userId,
            'two_factor_enabled' => 'false',
            'status' => 'enabled',
        ], $values));
    }

    public static function updateAdminAccount(int $id, array $values): int
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update($values);
    }

    public static function updateAdminPassword(int $id, string $passwordHash): int
    {
        return self::updateAdminAccount($id, ['password' => $passwordHash]);
    }

    private static function adminAccountRow(object $row): array
    {
        return array_merge([
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'user_id' => (int) $row->user_id,
            'login_name' => $row->login_name,
            'status' => $row->status,
            'two_factor_enabled' => $row->two_factor_enabled,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'name' => $row->name,
            'mobile' => $row->mobile,
            'email' => $row->email,
            'avatar' => $row->avatar,
            'user_status' => $row->user_status,
            'role_id' => $row->role_id === null ? null : (int) $row->role_id,
            'role_name' => $row->role_name,
            'role_type' => $row->role_type,
        ], self::organizationProfile((int) $row->user_id, (string) $row->role_type));
    }

    private static function accountListQuery(): mixed
    {
        return self::query()
            ->join('users', 'account.user_id', '=', 'users.id')
            ->leftJoin('user_role', function ($join): void {
                $join->on('account.id', '=', 'user_role.account_id')
                    ->where('user_role.is_primary', 'true')
                    ->whereNull('user_role.deleted_at');
            })
            ->leftJoin('role', 'user_role.role_id', '=', 'role.id')
            ->where('account.status', 'enabled')
            ->where('users.status', 'enabled')
            ->whereNull('account.deleted_at')
            ->whereNull('users.deleted_at');
    }

    private static function identityByAccountId(int $accountId): ?array
    {
        $row = self::accountListQuery()
            ->where('account.id', $accountId)
            ->first([
                'account.id',
                'account.user_id',
                'account.login_name',
                'users.mobile',
                'role.role_type',
            ]);

        return $row ? [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'login_name' => $row->login_name,
            'mobile' => $row->mobile,
            'role_type' => $row->role_type,
        ] : null;
    }

    private static function organizationProfile(int $userId, string $roleType): array
    {
        if ($roleType === 'student') {
            $student = TableRecord::queryTable('students')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->orderByDesc('student_id')
                ->first(['student_id', 'student_num', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'class_num']);

            return $student ? [
                'student_id' => (int) $student->student_id,
                'student_num' => $student->student_num,
                'teacher_id' => null,
                'teacher_num' => null,
                'grade_id' => $student->grade_id === null ? null : (int) $student->grade_id,
                'dep_id' => $student->dep_id === null ? null : (int) $student->dep_id,
                'profession_id' => $student->profession_id === null ? null : (int) $student->profession_id,
                'class_id' => $student->class_id === null ? null : (int) $student->class_id,
                'class_num' => $student->class_num,
            ] : self::emptyOrganizationProfile();
        }

        if ($roleType === 'teacher') {
            $teacher = TableRecord::queryTable('teacher_list')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->orderByDesc('teacher_id')
                ->first(['teacher_id', 'teacher_num', 'dep_id', 'profession_id']);

            return $teacher ? [
                'student_id' => null,
                'student_num' => null,
                'teacher_id' => (int) $teacher->teacher_id,
                'teacher_num' => $teacher->teacher_num,
                'grade_id' => null,
                'dep_id' => $teacher->dep_id === null ? null : (int) $teacher->dep_id,
                'profession_id' => $teacher->profession_id === null ? null : (int) $teacher->profession_id,
                'class_id' => null,
                'class_num' => null,
            ] : self::emptyOrganizationProfile();
        }

        return self::emptyOrganizationProfile();
    }

    private static function emptyOrganizationProfile(): array
    {
        return [
            'student_id' => null,
            'student_num' => null,
            'teacher_id' => null,
            'teacher_num' => null,
            'grade_id' => null,
            'dep_id' => null,
            'profession_id' => null,
            'class_id' => null,
            'class_num' => null,
        ];
    }

    private static function applyAdminVisibilityScope(mixed $query, array $scope): void
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === '') {
            return;
        }

        if ($roleType === 'school_admin') {
            $query->where(function ($builder): void {
                $builder->whereNull('role.role_type')
                    ->orWhere('role.role_type', '<>', 'super_admin');
            });
            return;
        }

        if (!in_array($roleType, ['college_admin', 'profession_admin'], true)) {
            return;
        }

        $query->whereIn('role.role_type', ['teacher', 'student']);
        $depIds = self::intIds((array) ($scope['dep_ids'] ?? []));
        $professionIds = self::intIds((array) ($scope['profession_ids'] ?? []));

        if ($roleType === 'college_admin') {
            if (!$depIds) {
                $query->whereRaw('1 = 0');
                return;
            }
            self::whereTeacherOrStudentScope($query, 'dep_id', $depIds);
            return;
        }

        if (!$professionIds) {
            $query->whereRaw('1 = 0');
            return;
        }
        self::whereTeacherOrStudentScope($query, 'profession_id', $professionIds);
    }

    private static function whereTeacherOrStudentScope(mixed $query, string $field, array $ids): void
    {
        $query->where(function ($builder) use ($field, $ids): void {
            $builder->where(function ($roleBuilder) use ($field, $ids): void {
                $roleBuilder->where('role.role_type', 'student')
                    ->whereExists(function ($exists) use ($field, $ids): void {
                        $exists->selectRaw('1')
                            ->from('students')
                            ->whereColumn('students.user_id', 'account.user_id')
                            ->where('students.status', 'enabled')
                            ->whereNull('students.deleted_at')
                            ->whereIn("students.{$field}", $ids);
                    });
            })->orWhere(function ($roleBuilder) use ($field, $ids): void {
                $roleBuilder->where('role.role_type', 'teacher')
                    ->whereExists(function ($exists) use ($field, $ids): void {
                        $exists->selectRaw('1')
                            ->from('teacher_list')
                            ->whereColumn('teacher_list.user_id', 'account.user_id')
                            ->where('teacher_list.status', 'enabled')
                            ->whereNull('teacher_list.deleted_at')
                            ->whereIn("teacher_list.{$field}", $ids);
                    });
            });
        });
    }

    private static function saveStudentProfile(int $userId, string $name, string $status, array $values, string $now): void
    {
        $payload = [
            'name' => $name,
            'student_num' => $values['student_num'] ?? null,
            'grade_id' => $values['grade_id'] ?? null,
            'dep_id' => $values['dep_id'] ?? null,
            'profession_id' => $values['profession_id'] ?? null,
            'class_id' => $values['class_id'] ?? null,
            'class_num' => $values['class_num'] ?? null,
            'status' => $status,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        $row = TableRecord::queryTable('students')
            ->where('user_id', $userId)
            ->first(['student_id']);

        if ($row) {
            TableRecord::queryTable('students')
                ->where('student_id', (int) $row->student_id)
                ->update($payload);
            return;
        }

        TableRecord::queryTable('students')->insert(array_merge($payload, [
            'student_uuid' => self::uuid(),
            'user_id' => $userId,
            'created_at' => $now,
        ]));
    }

    private static function saveTeacherProfile(int $userId, string $name, string $status, array $values, string $now): void
    {
        $payload = [
            'teacher_name' => $name,
            'teacher_num' => $values['teacher_num'] ?? null,
            'dep_id' => $values['dep_id'] ?? null,
            'profession_id' => $values['profession_id'] ?? null,
            'status' => $status,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        $row = TableRecord::queryTable('teacher_list')
            ->where('user_id', $userId)
            ->first(['teacher_id']);

        if ($row) {
            TableRecord::queryTable('teacher_list')
                ->where('teacher_id', (int) $row->teacher_id)
                ->update($payload);
            return;
        }

        TableRecord::queryTable('teacher_list')->insert(array_merge($payload, [
            'teacher_uuid' => self::uuid(),
            'user_id' => $userId,
            'created_at' => $now,
        ]));
    }

    private static function intIds(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
