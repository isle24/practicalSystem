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
                'roles' => Role::enabledOrdered(),
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
            $items = Menu::enabledItems();

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
                'menu_ids' => RoleMenu::activeMenuIdsByRole($roleId),
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
                $menu = Menu::activeById($menuId);
                if (!$menu) {
                    return $this->fail(40400, '菜单不存在', 404);
                }
                $menu->fill($values);
                $menu->save();
            } else {
                Menu::createMenu($values);
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
            $children = Menu::activeChildrenCount($menuId);
            if ($children > 0) {
                return $this->fail(42200, '请先删除子菜单', 422);
            }

            $now = date('Y-m-d H:i:s');
            ChannelTable::connection()->transaction(function () use ($menuId, $now): void {
                Menu::disableMenu($menuId, $now);
                RoleMenu::softDeleteByMenu($menuId, $now);
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
                RoleMenu::softDeleteByRole($roleId, $now);

                foreach ($menuIds as $menuId) {
                    RoleMenu::restoreOrCreate($roleId, $menuId, $now);
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
                'roles' => Role::enabledOrdered(['id', 'code', 'name', 'role_type']),
                'accounts' => $this->accountsData(),
                'departments' => ChannelTable::enabledOptionRows('department', ['dep_id', 'dep_name', 'dep_code'], ['sort']),
                'grades' => ChannelTable::enabledOptionRows('grade_list', ['grade_id', 'grade_name', 'dep_id'], ['sort']),
                'professions' => ChannelTable::enabledOptionRows('profession', ['profession_id', 'profession_name', 'profession_code', 'dep_id', 'grade_id'], ['sort']),
                'classes' => ChannelTable::enabledOptionRows('class', ['class_id', 'class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id'], ['sort']),
                'companies' => ChannelTable::enabledOptionRows('companies', ['company_id', 'company_name', 'credit_code'], ['company_id']),
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
                SysOrganization::deactivateScopes($accountId, $roleId, $now);

                foreach ($scopes as $scope) {
                    $record = SysOrganization::matchingScope($accountId, $roleId, $scope);
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

                    SysOrganization::createScope($values);
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

        return Menu::enabledIds($menuIds);
    }

    private function isDescendantMenu(int $parentId, int $menuId): bool
    {
        while ($parentId > 0) {
            if ($parentId === $menuId) {
                return true;
            }

            $parentId = Menu::parentIdOfActive($parentId);
        }

        return false;
    }

    private function account(int $accountId): object
    {
        $account = Account::enabledById($accountId, ['id', 'user_id']);

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
        return Account::enabledWithPrimaryRole();
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
