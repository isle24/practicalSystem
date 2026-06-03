<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\model\channel\Account;
use app\model\channel\Menu;
use app\model\channel\Role;
use app\model\channel\RoleMenu;
use app\model\channel\SysOrganization;
use app\model\channel\TableRecord as ChannelTable;
use app\server\CurrentContext;
use app\server\rbac\RbacService;
use support\Request;
use support\Response;
use Throwable;

class AdminController
{
    use Responds;

    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin'];

    public function roles(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok([
                'roles' => Role::query()
                    ->where('status', 'enabled')
                    ->whereNull('deleted_at')
                    ->orderBy('sort')
                    ->get(['id', 'code', 'name', 'role_type', 'sort', 'status'])
                    ->map(static fn ($role): array => $role->toArray())
                    ->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function menus(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $items = Menu::query()
                ->where('status', 'enabled')
                ->whereNull('deleted_at')
                ->orderBy('sort')
                ->get(['id', 'parent_id', 'name', 'code', 'path', 'url', 'platform', 'type', 'sort', 'icon', 'visible', 'status'])
                ->map(static fn ($menu): array => $menu->toArray())
                ->all();

            return $this->ok([
                'items' => $items,
                'menus' => $this->tree($items),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function rolePermissions(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $roleId = $this->requiredInt($request, 'role_id');

            return $this->ok([
                'role_id' => $roleId,
                'menu_ids' => RoleMenu::query()
                    ->where('role_id', $roleId)
                    ->whereNull('deleted_at')
                    ->pluck('menu_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
                'permissions' => (new RbacService())->permissionCodes($roleId),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function saveMenu(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $menuId = $this->optionalInt($request, 'id');
            $parentId = $this->optionalInt($request, 'parent_id') ?? 0;
            if ($menuId && $menuId === $parentId) {
                return $this->fail(40001, 'parent_id 不能等于当前菜单', 400);
            }
            if ($menuId && $this->isDescendantMenu($parentId, $menuId)) {
                return $this->fail(40001, '父级菜单不能选择当前菜单的子级', 400);
            }

            $values = [
                'parent_id' => $parentId,
                'name' => $this->requiredString($request, 'name'),
                'code' => $this->nullableString($request, 'code'),
                'path' => $this->nullableString($request, 'path'),
                'url' => $this->nullableString($request, 'url'),
                'platform' => $this->enum($request, 'platform', ['h5', 'pc', 'both'], 'both'),
                'type' => $this->enum($request, 'type', ['directory', 'menu', 'button'], 'menu'),
                'sort' => $this->optionalInt($request, 'sort') ?? 0,
                'icon' => $this->nullableString($request, 'icon'),
                'visible' => $this->enum($request, 'visible', ['false', 'true'], 'true'),
                'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
                'deleted_at' => null,
            ];

            if ($menuId) {
                $menu = Menu::query()->where('id', $menuId)->first();
                if (!$menu) {
                    return $this->fail(40400, '菜单不存在', 404);
                }
                $menu->fill($values);
                $menu->save();
            } else {
                Menu::query()->create($values);
            }

            return $this->menus($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function deleteMenu(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $menuId = $this->requiredInt($request, 'id');
            $children = Menu::query()
                ->where('parent_id', $menuId)
                ->whereNull('deleted_at')
                ->count();
            if ($children > 0) {
                return $this->fail(42200, '请先删除子菜单', 422);
            }

            $now = date('Y-m-d H:i:s');
            ChannelTable::connection()->transaction(function () use ($menuId, $now): void {
                Menu::query()
                    ->where('id', $menuId)
                    ->update(['status' => 'disabled', 'deleted_at' => $now, 'updated_at' => $now]);
                RoleMenu::query()
                    ->where('menu_id', $menuId)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            });

            return $this->menus($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function saveRoleMenus(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $roleId = $this->requiredInt($request, 'role_id');
            $menuIds = $this->intArray($request->input('menu_ids', []));
            $menuIds = $this->existingMenuIds($menuIds);
            $now = date('Y-m-d H:i:s');

            ChannelTable::connection()->transaction(function () use ($roleId, $menuIds, $now): void {
                RoleMenu::query()
                    ->where('role_id', $roleId)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);

                foreach ($menuIds as $menuId) {
                    $record = RoleMenu::query()
                        ->where('role_id', $roleId)
                        ->where('menu_id', $menuId)
                        ->first();

                    if ($record) {
                        $record->deleted_at = null;
                        $record->updated_at = $now;
                        $record->save();
                        continue;
                    }

                    RoleMenu::query()->create([
                        'role_id' => $roleId,
                        'menu_id' => $menuId,
                    ]);
                }
            });

            return $this->rolePermissions($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function accounts(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok([
                'accounts' => $this->accountsData(),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function options(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok([
                'roles' => Role::query()
                    ->where('status', 'enabled')
                    ->whereNull('deleted_at')
                    ->orderBy('sort')
                    ->get(['id', 'code', 'name', 'role_type'])
                    ->map(static fn ($role): array => $role->toArray())
                    ->all(),
                'accounts' => $this->accountsData(),
                'departments' => $this->rows(ChannelTable::queryTable('department')
                    ->where('flag', 'on')
                    ->whereNull('deleted_at')
                    ->orderBy('sort')
                    ->get(['dep_id', 'dep_name', 'dep_code'])),
                'grades' => $this->rows(ChannelTable::queryTable('grade_list')
                    ->where('flag', 'on')
                    ->whereNull('deleted_at')
                    ->orderBy('sort')
                    ->get(['grade_id', 'grade_name', 'dep_id'])),
                'professions' => $this->rows(ChannelTable::queryTable('profession')
                    ->where('flag', 'on')
                    ->whereNull('deleted_at')
                    ->orderBy('sort')
                    ->get(['profession_id', 'profession_name', 'profession_code', 'dep_id', 'grade_id'])),
                'classes' => $this->rows(ChannelTable::queryTable('class')
                    ->where('flag', 'on')
                    ->whereNull('deleted_at')
                    ->orderBy('sort')
                    ->get(['class_id', 'class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id'])),
                'companies' => $this->rows(ChannelTable::queryTable('companies')
                    ->where('flag', 'on')
                    ->whereNull('deleted_at')
                    ->orderBy('company_id')
                    ->get(['company_id', 'company_name', 'credit_code'])),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function organizationScopes(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $accountId = $this->requiredInt($request, 'account_id');
            $roleId = $this->requiredInt($request, 'role_id');

            return $this->ok([
                'organization_scopes' => (new RbacService())->organizationScopes($accountId, $roleId),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function saveOrganizationScopes(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $accountId = $this->requiredInt($request, 'account_id');
            $roleId = $this->requiredInt($request, 'role_id');
            $scopes = $this->scopes((array) $request->input('scopes', []));
            $account = $this->account($accountId);
            $now = date('Y-m-d H:i:s');

            ChannelTable::connection()->transaction(function () use ($accountId, $roleId, $scopes, $account, $now): void {
                SysOrganization::query()
                    ->where('account_id', $accountId)
                    ->where('role_id', $roleId)
                    ->where('disabled', 'false')
                    ->whereNull('deleted_at')
                    ->update(['disabled' => 'true', 'deleted_at' => $now, 'updated_at' => $now]);

                foreach ($scopes as $scope) {
                    $query = SysOrganization::query()
                        ->where('account_id', $accountId)
                        ->where('role_id', $roleId);

                    foreach (['dep_id', 'profession_id', 'class_id', 'company_id', 'cate_id'] as $field) {
                        $scope[$field] === null
                            ? $query->whereNull($field)
                            : $query->where($field, $scope[$field]);
                    }

                    $record = $query->first();
                    $values = array_merge($scope, [
                        'account_id' => $accountId,
                        'user_id' => (int) $account->user_id,
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

                    SysOrganization::query()->create($values);
                }
            });

            return $this->organizationScopes($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function isAdmin(): bool
    {
        return in_array(CurrentContext::roleType(), self::ADMIN_ROLE_TYPES, true);
    }

    private function requiredInt(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException("{$key} 无效");
        }

        return (int) $value;
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private function requiredString(Request $request, string $key): string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            throw new \InvalidArgumentException("{$key} 不能为空");
        }

        return $value;
    }

    private function nullableString(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        return $value === '' ? null : $value;
    }

    private function enum(Request $request, string $key, array $values, string $default): string
    {
        $value = (string) $request->input($key, $default);
        return in_array($value, $values, true) ? $value : $default;
    }

    private function intArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    private function existingMenuIds(array $menuIds): array
    {
        if (!$menuIds) {
            return [];
        }

        return Menu::query()
            ->whereIn('id', $menuIds)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function isDescendantMenu(int $parentId, int $menuId): bool
    {
        while ($parentId > 0) {
            if ($parentId === $menuId) {
                return true;
            }

            $parentId = (int) (Menu::query()
                ->where('id', $parentId)
                ->whereNull('deleted_at')
                ->value('parent_id') ?? 0);
        }

        return false;
    }

    private function account(int $accountId): object
    {
        $account = Account::query()
            ->where('id', $accountId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'user_id']);

        if (!$account) {
            throw new \InvalidArgumentException('账号不存在或已禁用');
        }

        return $account;
    }

    private function scopes(array $items): array
    {
        $scopes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $scope = [];
            foreach (['dep_id', 'profession_id', 'class_id', 'company_id', 'cate_id'] as $field) {
                $value = $item[$field] ?? null;
                $scope[$field] = $value === null || $value === '' ? null : (string) $value;
            }

            if (array_filter($scope, static fn ($value): bool => $value !== null) === []) {
                continue;
            }

            $key = implode(':', array_map(static fn ($value): string => $value ?? '0', $scope));
            $scopes[$key] = $scope;
        }

        return array_values($scopes);
    }

    private function accountsData(): array
    {
        $rows = Account::query()
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
            ]);

        return $this->rows($rows);
    }

    private function rows(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = (array) $row;
        }

        return $items;
    }

    private function tree(array $items): array
    {
        $children = [];
        foreach ($items as $item) {
            $item['children'] = [];
            $children[(int) ($item['parent_id'] ?? 0)][] = $item;
        }

        $build = function (int $parentId) use (&$build, &$children): array {
            $nodes = $children[$parentId] ?? [];
            foreach ($nodes as &$node) {
                $node['children'] = $build((int) $node['id']);
            }
            unset($node);
            return $nodes;
        };

        return $build(0);
    }
}
