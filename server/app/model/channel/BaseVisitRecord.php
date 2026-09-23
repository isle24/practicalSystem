<?php

namespace app\model\channel;

use app\server\CurrentContext;

class BaseVisitRecord extends TableRecord
{
    public static function installed(): bool
    {
        $schema = self::connection()->getSchemaBuilder();
        return $schema->hasTable('base_visit_plan') && $schema->hasTable('base_visit_record');
    }

    public static function visiblePlans(): mixed
    {
        $query = self::queryTable('base_visit_plan')->whereNull('base_visit_plan.deleted_at');
        return match (CurrentContext::roleType()) {
            'super_admin', 'school_admin' => $query,
            'college_admin' => $query->whereIn('base_visit_plan.dep_id', self::scopeIds('dep_id')),
            'profession_admin' => $query->whereIn('base_visit_plan.base_id', self::professionBases()),
            'teacher' => $query->whereIn('base_visit_plan.teacher_id', self::queryTable('teacher_list')->where('user_id', CurrentContext::userId())->whereNull('deleted_at')->select('teacher_id')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function page(array $filters): array
    {
        $query = self::visiblePlans();
        self::keyword($query, (string) ($filters['keyword'] ?? ''), ['title', 'base_name', 'teacher_name', 'base_address']);
        foreach (['status', 'dep_id', 'visit_date'] as $field) {
            if (($filters[$field] ?? '') !== '' && ($filters[$field] ?? null) !== null) $query->where($field, $filters[$field]);
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $size = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $total = (clone $query)->count();
        $items = $query->select('base_visit_plan.*')->selectSub(self::conflictQuery(), 'conflict_count')
            ->orderByDesc('base_visit_plan.id')->forPage($page, $size)->get()->map(fn ($row) => $row->toArray())->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    public static function plan(int $id, bool $lock = false): ?array
    {
        $query = self::visiblePlans()->where('base_visit_plan.id', $id);
        if ($lock) $query->lockForUpdate();
        $row = $query->first(['base_visit_plan.*']);
        return $row?->toArray();
    }

    public static function conflicts(array $plan): int
    {
        if (!$plan['visit_date'] || !$plan['visit_period'] || $plan['status'] === 'cancelled') return 0;
        return self::queryTable('base_visit_plan')->whereNull('deleted_at')->where('id', '<>', $plan['id'])
            ->whereIn('status', ['scheduled', 'completed'])->where('visit_date', $plan['visit_date'])->where('visit_period', $plan['visit_period'])->count();
    }

    private static function conflictQuery(): mixed
    {
        return self::queryTable('base_visit_plan as conflict')->selectRaw('COUNT(*)')
            ->whereNull('conflict.deleted_at')->whereIn('conflict.status', ['scheduled', 'completed'])
            ->whereColumn('conflict.visit_date', 'base_visit_plan.visit_date')
            ->whereColumn('conflict.visit_period', 'base_visit_plan.visit_period')
            ->whereColumn('conflict.id', '<>', 'base_visit_plan.id')
            ->whereIn('base_visit_plan.status', ['scheduled', 'completed']);
    }

    public static function createPlan(array $values): int
    {
        return (int) self::queryTable('base_visit_plan')->insertGetId($values + ['uuid' => self::uuid()]);
    }

    public static function updatePlan(int $id, array $values): void
    {
        self::queryTable('base_visit_plan')->where('id', $id)->update($values);
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

    public static function departments(): array
    {
        return self::baseQuery()->whereNotNull('department.dep_id')->distinct()->orderBy('department.dep_id')
            ->get(['department.dep_id', 'department.dep_name'])->toArray();
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
        return ['base.id', 'base.name', 'base.dep_id', 'department.dep_name', 'base.address',
            self::connection()->raw("COALESCE((SELECT NULLIF(base_level, '') FROM base_declaration WHERE base_id = base.id AND deleted_at IS NULL ORDER BY id DESC LIMIT 1), base.category) as base_category"),
            'base.manager_name', 'base.manager_phone'];
    }

    private static function teacherColumns(): array
    {
        return ['teacher_list.teacher_id', 'teacher_list.teacher_name', 'teacher_list.teacher_num', 'department.dep_name', self::connection()->raw("COALESCE(NULLIF(teacher_list.phone, ''), users.mobile) as phone")];
    }

    private static function professionBases(): mixed
    {
        return self::queryTable('base_profession')->whereNull('deleted_at')->whereIn('profession_id', self::scopeIds('profession_id'))->select('base_id');
    }

    private static function scopeIds(string $key): array
    {
        return array_values(array_filter(array_map('intval', array_column(CurrentContext::organizationScopes(), $key)), fn ($id) => $id > 0));
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
