<?php

namespace app\model\channel;

class Account extends BaseModel
{
    protected $table = 'account';
    protected $primaryKey = 'id';
    protected $guarded = [];

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
            ->map(static fn ($row): array => [
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
            ])
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
