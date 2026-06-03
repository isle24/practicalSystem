<?php

namespace app\model\channel;

class Role extends BaseModel
{
    protected $table = 'role';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function enabledById(int $roleId, array $columns = ['id', 'code', 'name', 'role_type', 'sort', 'status']): ?self
    {
        return self::query()
            ->where('id', $roleId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function enabledOrdered(array $columns = ['id', 'code', 'name', 'role_type', 'sort', 'status']): array
    {
        return self::query()
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->get($columns)
            ->map(static fn ($role): array => $role->toArray())
            ->all();
    }

    public static function enabledForAccount(int $accountId): array
    {
        return self::query()
            ->join('user_role', 'role.id', '=', 'user_role.role_id')
            ->where('user_role.account_id', $accountId)
            ->where('role.status', 'enabled')
            ->whereNull('role.deleted_at')
            ->whereNull('user_role.deleted_at')
            ->orderByDesc('user_role.is_primary')
            ->orderBy('role.sort')
            ->get([
                'role.id',
                'role.code',
                'role.name',
                'role.role_type',
                'user_role.is_primary',
            ])
            ->map(static fn ($role): array => $role->toArray())
            ->all();
    }
}
