<?php

namespace app\model\channel;

class SysOrganization extends BaseModel
{
    protected $table = 'sys_organization';
    protected $primaryKey = 'organization_id';
    protected $guarded = [];

    public static function activeScopes(int $accountId, int $roleId): array
    {
        return self::query()
            ->where('account_id', $accountId)
            ->where('role_id', $roleId)
            ->where('disabled', 'false')
            ->whereNull('deleted_at')
            ->orderBy('organization_id')
            ->get([
                'organization_id',
                'account_id',
                'user_id',
                'role_id',
                'dep_id',
                'profession_id',
                'class_id',
                'company_id',
                'cate_id',
            ])
            ->map(static fn ($scope): array => $scope->toArray())
            ->all();
    }

    public static function deactivateScopes(int $accountId, int $roleId, string $now): int
    {
        return self::query()
            ->where('account_id', $accountId)
            ->where('role_id', $roleId)
            ->where('disabled', 'false')
            ->whereNull('deleted_at')
            ->update(['disabled' => 'true', 'deleted_at' => $now, 'updated_at' => $now]);
    }

    public static function matchingScope(int $accountId, int $roleId, array $scope): ?self
    {
        $query = self::query()
            ->where('account_id', $accountId)
            ->where('role_id', $roleId);

        foreach (['dep_id', 'profession_id', 'class_id', 'company_id', 'cate_id'] as $field) {
            $scope[$field] === null
                ? $query->whereNull($field)
                : $query->where($field, $scope[$field]);
        }

        return $query->first();
    }

    public static function createScope(array $values): self
    {
        return self::query()->create($values);
    }

    /**
     * 替换账号角色的组织范围。
     */
    public static function replaceScopesForRole(int $accountId, int $userId, int $roleId, array $scopes, string $now): void
    {
        self::connection()->transaction(function () use ($accountId, $userId, $roleId, $scopes, $now): void {
            self::deactivateScopes($accountId, $roleId, $now);

            foreach ($scopes as $scope) {
                $record = self::matchingScope($accountId, $roleId, $scope);
                $values = array_merge($scope, [
                    'account_id' => $accountId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'disabled' => 'false',
                    'deleted_at' => null,
                ]);

                if ($record) {
                    $record->fill($values);
                    $record->updated_at = $now;
                    $record->save();
                    continue;
                }

                self::createScope($values);
            }
        });
    }
}
