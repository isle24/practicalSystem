<?php

namespace app\model\channel;

use app\server\CurrentContext;

class BaseVisitRecord extends TableRecord
{
    private const ADMIN_ROLES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];

    public static function installed(): bool
    {
        $schema = self::connection()->getSchemaBuilder();
        $columns = [
            'base_visit_plan' => [
                'id', 'uuid', 'revision', 'title', 'base_id', 'teacher_id', 'dep_id', 'base_name', 'base_address',
                'base_department', 'base_category', 'base_location', 'base_manager_name', 'base_manager_phone',
                'teacher_name', 'teacher_department', 'contact_phone', 'contact_account_id', 'contact_person',
                'supervisor_id', 'participant_ids', 'news_url', 'visit_date', 'visit_period', 'start_time', 'end_time',
                'remark', 'status', 'scheduled_at', 'cancel_reason', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
            ],
            'base_visit_record' => [
                'id', 'uuid', 'visit_id', 'actual_at', 'participants', 'contact_person', 'content', 'problems',
                'follow_up', 'attachment_ids', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
            ],
            'base_visit_participant' => ['id', 'uuid', 'visit_id', 'account_id', 'created_at', 'updated_at', 'deleted_at'],
        ];
        foreach ($columns as $table => $required) {
            if (!$schema->hasTable($table)) return false;
            foreach ($required as $column) {
                if (!$schema->hasColumn($table, $column)) return false;
            }
        }
        return true;
    }

    public static function visiblePlans(): mixed
    {
        $query = self::queryTable('base_visit_plan')->whereNull('base_visit_plan.deleted_at');
        return match (CurrentContext::roleType()) {
            'super_admin', 'school_admin' => $query,
            'college_admin' => $query->whereIn('base_visit_plan.dep_id', self::scopeIds('dep_id')),
            'profession_admin' => $query->whereIn('base_visit_plan.base_id', self::professionBases()),
            'teacher' => $query->where(function ($builder): void {
                $accountId = CurrentContext::accountId();
                if ($accountId) $builder->whereExists(function ($sub) use ($accountId): void {
                    $sub->from('base_visit_participant')->whereColumn('base_visit_participant.visit_id', 'base_visit_plan.id')->where('base_visit_participant.account_id', $accountId)->whereNull('base_visit_participant.deleted_at');
                });
                $builder->orWhereIn('base_visit_plan.teacher_id', self::queryTable('teacher_list')->where('user_id', CurrentContext::userId())->whereNull('deleted_at')->select('teacher_id'));
            }),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function page(array $filters): array
    {
        $query = self::visiblePlans();
        self::keyword($query, (string) ($filters['keyword'] ?? ''), ['title', 'base_name', 'teacher_name', 'base_address', 'contact_person']);
        foreach (['status', 'dep_id', 'visit_date'] as $field) {
            if (($filters[$field] ?? '') !== '' && ($filters[$field] ?? null) !== null) $query->where('base_visit_plan.' . $field, $filters[$field]);
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $size = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $total = (clone $query)->count('base_visit_plan.id');
        $items = $query->select('base_visit_plan.*')->selectSub(self::conflictQuery(), 'conflict_count')
            ->orderByDesc('base_visit_plan.id')->forPage($page, $size)->get()->map(fn ($row) => self::normalisePlan($row->toArray(), false))->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    public static function plan(int $id, bool $lock = false): ?array
    {
        $query = self::visiblePlans()->where('base_visit_plan.id', $id);
        if ($lock) $query->lockForUpdate();
        $row = $query->first(['base_visit_plan.*']);
        return $row ? self::normalisePlan($row->toArray(), true) : null;
    }

    public static function conflicts(array $plan): int
    {
        if (!$plan['visit_date'] || !$plan['start_time'] || !$plan['end_time'] || $plan['status'] === 'cancelled') return 0;
        return self::conflictQueryFor($plan['id'], $plan['visit_date'], $plan['start_time'], $plan['end_time'], self::participantIds($plan))->count();
    }

    public static function lockParticipants(array $accountIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds), fn (int $id): bool => $id > 0)));
        if (!$ids) return;
        self::queryTable('account')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public static function conflictingParticipants(int $planId, string $date, string $start, string $end, array $participantIds): array
    {
        if (!$participantIds) return [];
        $query = self::conflictQueryFor($planId, $date, $start, $end, $participantIds);
        return $query->get(['base_visit_plan.id', 'base_visit_plan.base_name', 'base_visit_plan.start_time', 'base_visit_plan.end_time'])
            ->map(fn ($row): array => $row->toArray())->all();
    }

    private static function conflictQuery(): mixed
    {
        return self::queryTable('base_visit_plan as conflict')->whereNull('conflict.deleted_at')
            ->whereIn('conflict.status', ['scheduled', 'completed'])
            ->whereColumn('conflict.visit_date', 'base_visit_plan.visit_date')
            ->whereColumn('conflict.start_time', '<', 'base_visit_plan.end_time')
            ->where(function ($builder): void {
                $builder->whereNull('conflict.end_time')->orWhereColumn('conflict.end_time', '>', 'base_visit_plan.start_time');
            })
            ->whereColumn('conflict.id', '<>', 'base_visit_plan.id')
            ->whereExists(function ($sub): void {
                $sub->from('base_visit_participant as cp')->join('base_visit_participant as bp', 'bp.account_id', '=', 'cp.account_id')
                    ->whereColumn('cp.visit_id', 'conflict.id')->whereColumn('bp.visit_id', 'base_visit_plan.id')
                    ->whereNull('cp.deleted_at')->whereNull('bp.deleted_at');
            })->selectRaw('COUNT(*)');
    }

    private static function conflictQueryFor(int $planId, string $date, string $start, string $end, array $participantIds): mixed
    {
        $query = self::queryTable('base_visit_plan')->whereNull('base_visit_plan.deleted_at')
            ->where('base_visit_plan.id', '<>', $planId)->whereIn('base_visit_plan.status', ['scheduled', 'completed'])
            ->where('base_visit_plan.visit_date', $date)
            ->where('base_visit_plan.start_time', '<', $end)
            ->where(function ($builder) use ($start): void {
                $builder->whereNull('base_visit_plan.end_time')->orWhere('base_visit_plan.end_time', '>', $start);
            });
        $query->whereExists(function ($sub) use ($participantIds): void {
            $sub->from('base_visit_participant')->whereColumn('base_visit_participant.visit_id', 'base_visit_plan.id')->whereIn('base_visit_participant.account_id', $participantIds)->whereNull('base_visit_participant.deleted_at');
        });
        return $query;
    }

    public static function createPlan(array $values): int
    {
        return (int) self::queryTable('base_visit_plan')->insertGetId($values + ['uuid' => self::uuid()]);
    }

    public static function updatePlan(int $id, array $values): void
    {
        $participants = $values['participant_ids'] ?? null;
        self::queryTable('base_visit_plan')->where('id', $id)->update($values);
        if ($participants !== null) self::syncParticipants($id, is_array($participants) ? $participants : (json_decode((string) $participants, true) ?: []));
    }

    public static function syncParticipants(int $visitId, array $accountIds): void
    {
        $table = self::queryTable('base_visit_participant');
        $table->where('visit_id', $visitId)->delete();
        $rows = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $accountIds), fn (int $id): bool => $id > 0))) as $accountId) {
            $rows[] = ['uuid' => self::uuid(), 'visit_id' => $visitId, 'account_id' => $accountId, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'), 'deleted_at' => null];
        }
        if ($rows) $table->insert($rows);
    }

    public static function record(int $visitId): ?array
    {
        $row = self::queryTable('base_visit_record')->where('visit_id', $visitId)->whereNull('deleted_at')->first();
        if (!$row) return null;
        $values = $row->toArray();
        $values['attachment_ids'] = json_decode($values['attachment_ids'] ?? '[]', true) ?: [];
        return $values;
    }

    public static function saveRecord(int $visitId, array $values): int
    {
        $id = self::queryTable('base_visit_record')->where('visit_id', $visitId)->value('id');
        if ($id) {
            self::queryTable('base_visit_record')->where('id', $id)->update($values + ['deleted_at' => null]);
            return (int) $id;
        }
        return (int) self::queryTable('base_visit_record')->insertGetId($values + [
            'visit_id' => $visitId, 'uuid' => self::uuid(), 'created_by' => CurrentContext::accountId(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function recordReadable(int $id): bool
    {
        if (!in_array('internship:view', CurrentContext::permissionCodes(), true)) return false;
        $visitId = self::queryTable('base_visit_record')->where('id', $id)->whereNull('deleted_at')->value('visit_id');
        return $visitId && self::visiblePlans()->where('base_visit_plan.id', $visitId)->exists();
    }

    public static function base(int $id): ?array
    {
        $row = self::baseQuery()->where('base.id', $id)->first(self::baseColumns());
        return $row?->toArray();
    }

    public static function bases(string $keyword): array
    {
        $query = self::baseQuery();
        self::keyword($query, $keyword, ['base.name', 'base.code', 'department.dep_name']);
        return $query->orderBy('base.id')->limit(100)->get(self::baseColumns())->toArray();
    }

    public static function teacher(int $id): ?array
    {
        $row = self::teacherQuery()->where('teacher_list.teacher_id', $id)->first(self::teacherColumns());
        return $row?->toArray();
    }

    public static function teachers(string $keyword): array
    {
        $query = self::teacherQuery();
        self::keyword($query, $keyword, ['teacher_list.teacher_name', 'teacher_list.teacher_num', 'department.dep_name']);
        return $query->orderBy('teacher_list.teacher_id')->limit(100)->get(self::teacherColumns())->toArray();
    }

    public static function participants(string $keyword = '', array $types = ['teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin']): array
    {
        $query = self::queryTable('account')->join('users', 'account.user_id', '=', 'users.id')
            ->join('user_role', 'account.id', '=', 'user_role.account_id')->join('role', 'user_role.role_id', '=', 'role.id')
            ->leftJoin('teacher_list', function ($join): void { $join->on('teacher_list.user_id', '=', 'users.id')->whereNull('teacher_list.deleted_at'); })
            ->leftJoin('department', 'teacher_list.dep_id', '=', 'department.dep_id')
            ->whereIn('role.role_type', $types)->where('account.status', 'enabled')->where('users.status', 'enabled')
            ->where('role.status', 'enabled')->whereNull('account.deleted_at')->whereNull('user_role.deleted_at')->whereNull('role.deleted_at');
        self::applyParticipantScope($query);
        self::keyword($query, $keyword, ['users.name', 'account.login_name', 'teacher_list.teacher_num', 'department.dep_name']);
        $rows = $query->distinct()->orderBy('users.name')->limit(500)->get([
            'account.id as account_id', 'users.name as name', 'users.mobile', 'role.role_type',
            'teacher_list.teacher_id', 'teacher_list.teacher_num', 'department.dep_name',
        ]);
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->account_id;
            if (!isset($items[$id]) || ($row->role_type === 'teacher' && ($items[$id]['role_type'] ?? '') !== 'teacher')) {
                $items[$id] = [
                    'account_id' => $id, 'name' => $row->name, 'mobile' => $row->mobile,
                    'role_type' => $row->role_type, 'teacher_id' => $row->teacher_id ? (int) $row->teacher_id : null,
                    'teacher_num' => $row->teacher_num, 'dep_name' => $row->dep_name,
                ];
            }
        }
        return array_slice(array_values($items), 0, 200);
    }

    public static function contacts(string $keyword = ''): array
    {
        $items = Account::messageTargets(['keyword' => $keyword, 'limit' => 200]);
        if (!in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true)) {
            $visibleIds = self::visibleEnabledAccountIds(array_column($items, 'id'));
            $items = array_values(array_filter($items, static fn (array $item): bool => in_array((int) ($item['id'] ?? 0), $visibleIds, true)));
        }
        return array_map(static fn (array $item): array => [
            'account_id' => (int) ($item['id'] ?? 0), 'name' => $item['name'] ?? '',
            'mobile' => $item['mobile'] ?? '', 'login_name' => $item['login_name'] ?? '',
            'role_name' => $item['role_name'] ?? '', 'role_type' => $item['role_type'] ?? '',
        ], $items);
    }

    public static function accountIds(array $ids, array $types = ['teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin']): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if (!$ids) return [];
        $query = self::queryTable('account')->join('users', 'account.user_id', '=', 'users.id')
            ->join('user_role', 'account.id', '=', 'user_role.account_id')->join('role', 'user_role.role_id', '=', 'role.id')
            ->leftJoin('teacher_list', function ($join): void { $join->on('teacher_list.user_id', '=', 'users.id')->whereNull('teacher_list.deleted_at'); })
            ->whereIn('account.id', $ids)->whereIn('role.role_type', $types)->where('account.status', 'enabled')->where('role.status', 'enabled')
            ->whereNull('account.deleted_at')->whereNull('user_role.deleted_at')->whereNull('role.deleted_at');
        self::applyParticipantScope($query);
        return $query->distinct()->pluck('account.id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function enabledAccountIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if (!$ids) return [];
        return self::queryTable('account')->join('users', 'account.user_id', '=', 'users.id')
            ->whereIn('account.id', $ids)->where('account.status', 'enabled')->where('users.status', 'enabled')
            ->whereNull('account.deleted_at')->whereNull('users.deleted_at')->distinct()->pluck('account.id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function visibleEnabledAccountIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if (!$ids) return [];
        $query = self::queryTable('account')->join('users', 'account.user_id', '=', 'users.id')
            ->join('user_role', 'account.id', '=', 'user_role.account_id')->join('role', 'user_role.role_id', '=', 'role.id')
            ->leftJoin('teacher_list', function ($join): void { $join->on('teacher_list.user_id', '=', 'users.id')->whereNull('teacher_list.deleted_at'); })
            ->whereIn('account.id', $ids)->where('account.status', 'enabled')->where('users.status', 'enabled')
            ->where('role.status', 'enabled')->whereNull('account.deleted_at')->whereNull('users.deleted_at')
            ->whereNull('user_role.deleted_at')->whereNull('role.deleted_at');
        self::applyParticipantScope($query);
        return $query->distinct()->pluck('account.id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function participantsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if (!$ids) return [];
        $query = self::queryTable('account')->join('users', 'account.user_id', '=', 'users.id')
            ->join('user_role', 'account.id', '=', 'user_role.account_id')->join('role', 'user_role.role_id', '=', 'role.id')
            ->leftJoin('teacher_list', function ($join): void { $join->on('teacher_list.user_id', '=', 'users.id')->whereNull('teacher_list.deleted_at'); })
            ->whereIn('account.id', $ids)->whereIn('role.role_type', ['teacher', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'])
            ->where('account.status', 'enabled')->where('role.status', 'enabled')->whereNull('account.deleted_at')->whereNull('user_role.deleted_at')->whereNull('role.deleted_at')
            ;
        self::applyParticipantScope($query);
        $rows = $query->distinct()->get(['account.id as account_id', 'users.name as name', 'users.mobile', 'role.role_type', 'teacher_list.teacher_id']);
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->account_id;
            if (!isset($items[$id]) || ($row->role_type === 'teacher' && ($items[$id]['role_type'] ?? '') !== 'teacher')) {
                $items[$id] = ['account_id' => $id, 'name' => $row->name, 'mobile' => $row->mobile, 'role_type' => $row->role_type, 'teacher_id' => $row->teacher_id ? (int) $row->teacher_id : null];
            }
        }
        return array_values($items);
    }

    public static function departments(): array
    {
        return self::baseQuery()->whereNotNull('department.dep_id')->distinct()->orderBy('department.dep_id')->get(['department.dep_id', 'department.dep_name'])->toArray();
    }

    public static function teacherAccountId(int $teacherId): int
    {
        $userId = self::queryTable('teacher_list')->where('teacher_id', $teacherId)->whereNull('deleted_at')->value('user_id');
        if (!$userId) return 0;
        return Account::enabledAccountIdByUserRole((int) $userId, 'teacher');
    }

    public static function participantIds(array $plan, bool $loadLinks = true): array
    {
        $raw = $plan['participant_ids'] ?? [];
        $ids = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0))) : [];
        sort($ids, SORT_NUMERIC);
        if ($loadLinks && !$ids && !empty($plan['id'])) {
            try {
                $linked = self::queryTable('base_visit_participant')->where('visit_id', (int) $plan['id'])->pluck('account_id')->map(fn ($id): int => (int) $id)->all();
                if ($linked) { $ids = array_values(array_unique($linked)); sort($ids, SORT_NUMERIC); }
            } catch (\Throwable) {
            }
        }
        if (!$ids && $loadLinks && !empty($plan['teacher_id'])) {
            $accountId = self::teacherAccountId((int) $plan['teacher_id']);
            if ($accountId) $ids[] = $accountId;
        }
        return $ids;
    }

    private static function normalisePlan(array $plan, bool $includeParticipantDetails = true): array
    {
        $plan['participant_ids'] = self::participantIds($plan, $includeParticipantDetails);
        $plan['participants'] = $plan['participant_ids'];
        foreach (['id', 'base_id', 'teacher_id', 'dep_id', 'revision', 'supervisor_id', 'contact_account_id', 'conflict_count'] as $key) {
            if (array_key_exists($key, $plan)) $plan[$key] = (int) ($plan[$key] ?? 0);
        }
        if ($includeParticipantDetails) {
            $rows = self::participantsByIds($plan['participant_ids']);
            $plan['participant_rows'] = $rows;
            $plan['participant_names'] = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows)));
            if (!empty($plan['supervisor_id'])) {
                $supervisor = self::participantsByIds([(int) $plan['supervisor_id']]);
                $plan['supervisor_name'] = $supervisor[0]['name'] ?? '';
            }
            if (!empty($plan['contact_account_id'])) {
                $contact = self::participantsByIds([(int) $plan['contact_account_id']]);
                $plan['contact_account_name'] = $contact[0]['name'] ?? '';
            }
        }
        return $plan;
    }

    private static function baseQuery(): mixed
    {
        $query = self::queryTable('base')->leftJoin('department', 'base.dep_id', '=', 'department.dep_id')
            ->whereNull('base.deleted_at')->where('base.status', 'enabled');
        return match (CurrentContext::roleType()) {
            'super_admin', 'school_admin' => $query,
            'college_admin' => $query->whereIn('base.dep_id', self::scopeIds('dep_id')),
            'profession_admin' => $query->whereIn('base.id', self::professionBases()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private static function teacherQuery(): mixed
    {
        $query = self::queryTable('teacher_list')->leftJoin('users', 'teacher_list.user_id', '=', 'users.id')
            ->leftJoin('department', 'teacher_list.dep_id', '=', 'department.dep_id')
            ->whereNull('teacher_list.deleted_at')->where('teacher_list.status', 'enabled');
        return match (CurrentContext::roleType()) {
            'super_admin', 'school_admin' => $query,
            'college_admin' => $query->whereIn('teacher_list.dep_id', self::scopeIds('dep_id')),
            'profession_admin' => $query->whereIn('teacher_list.profession_id', self::scopeIds('profession_id')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private static function baseColumns(): array
    {
        return ['base.id', 'base.name', 'base.dep_id', 'base.base_type', 'department.dep_name', 'base.address',
            self::connection()->raw("COALESCE((SELECT NULLIF(base_level, '') FROM base_declaration WHERE base_id = base.id AND deleted_at IS NULL ORDER BY id DESC LIMIT 1), base.category) as base_category"),
            'base.manager_name', 'base.manager_phone', 'base.district'];
    }

    private static function teacherColumns(): array
    {
        return ['teacher_list.teacher_id', 'teacher_list.teacher_name', 'teacher_list.teacher_num', 'teacher_list.dep_id', 'department.dep_name', self::connection()->raw("COALESCE(NULLIF(teacher_list.phone, ''), users.mobile) as phone")];
    }

    private static function professionBases(): mixed
    {
        return self::queryTable('base_profession')->whereNull('deleted_at')->whereIn('profession_id', self::scopeIds('profession_id'))->select('base_id');
    }

    private static function scopeIds(string $key): array
    {
        return array_values(array_filter(array_map('intval', array_column(CurrentContext::organizationScopes(), $key)), fn ($id) => $id > 0));
    }

    private static function applyParticipantScope(mixed $query): void
    {
        $roleType = CurrentContext::roleType();
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) return;
        if ($roleType === 'college_admin') {
            $ids = self::scopeIds('dep_id');
            if (!$ids) { $query->whereRaw('1 = 0'); return; }
            $query->where(function ($builder) use ($ids): void {
                $builder->whereIn('teacher_list.dep_id', $ids)->orWhereExists(function ($sub) use ($ids): void {
                    $sub->from('sys_organization as scope')->whereColumn('scope.account_id', 'account.id')->whereColumn('scope.role_id', 'role.id')
                        ->whereIn('scope.dep_id', $ids)->where('scope.disabled', 'false')->whereNull('scope.deleted_at');
                });
            });
            return;
        }
        if ($roleType === 'profession_admin') {
            $ids = self::scopeIds('profession_id');
            if (!$ids) { $query->whereRaw('1 = 0'); return; }
            $query->where(function ($builder) use ($ids): void {
                $builder->whereIn('teacher_list.profession_id', $ids)->orWhereExists(function ($sub) use ($ids): void {
                    $sub->from('sys_organization as scope')->whereColumn('scope.account_id', 'account.id')->whereColumn('scope.role_id', 'role.id')
                        ->whereIn('scope.profession_id', $ids)->where('scope.disabled', 'false')->whereNull('scope.deleted_at');
                });
            });
            return;
        }
        $query->whereRaw('1 = 0');
    }

    private static function keyword(mixed $query, string $keyword, array $columns): void
    {
        $keyword = mb_substr(trim($keyword), 0, 180);
        if ($keyword === '') return;
        $query->where(function ($builder) use ($columns, $keyword): void {
            foreach ($columns as $column) $builder->orWhere($column, 'like', '%' . addcslashes($keyword, '%_\\') . '%');
        });
    }
}
