<?php

namespace app\model\channel;

class Account extends BaseModel
{
    protected $table = 'account';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function enabledByLoginName(string $loginName): ?self
    {
        return self::query()
            ->where('login_name', $loginName)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first();
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
}
