<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\model\channel\Account;
use app\model\channel\Menu;
use app\model\channel\Role;
use app\model\channel\RoleMenu;
use app\model\channel\SysOrganization;
use app\model\channel\TableRecord as ChannelTable;
use app\model\channel\User;
use app\model\channel\UserWechat;
use app\model\channel\UserRole;
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
            $type = $this->enum($request, 'type', ['directory', 'menu', 'list', 'button'], 'menu');
            $parent = $parentId > 0 ? Menu::activeById($parentId, ['id', 'parent_id', 'name', 'type']) : null;
            $parentError = $this->validateMenuParent($type, $parentId, $parent);
            if ($parentError !== '') {
                return $this->fail(40001, $parentError, 400);
            }

            $values = [
                'parent_id' => $parentId,
                'name' => $this->requiredString($request, 'name'),
                'code' => $this->nullableString($request, 'code'),
                'path' => $this->nullableString($request, 'path'),
                'url' => $this->nullableString($request, 'url'),
                'platform' => $this->enum($request, 'platform', ['h5', 'pc', 'both'], 'both'),
                'type' => $type,
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
            return $this->ok(Account::adminPage([
                'page' => $this->optionalInt($request, 'page') ?? 1,
                'page_size' => $this->optionalInt($request, 'page_size') ?? 20,
                'keyword' => $this->nullableString($request, 'keyword') ?? '',
                'role_type' => $this->nullableString($request, 'role_type') ?? '',
                'status' => $this->enum($request, 'status', ['all', 'enabled', 'disabled'], 'all'),
            ]));
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function accountDetail(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $accountId = $this->requiredInt($request, 'id');
            $this->assertAccountRoleWritable($accountId, '');
            $account = Account::adminDetail($accountId);
            if (!$account) {
                return $this->fail(40400, '账号不存在', 404);
            }

            return $this->ok([
                'account' => $account,
                'bound_accounts' => Account::boundAccountsByUser((int) $account['user_id'], $accountId),
                'wechat_accounts' => UserWechat::byUser((int) $account['user_id']),
                'operation_logs' => ChannelTable::operationLogPage(CurrentContext::schoolDatabase() ?: '', [
                    'page' => $this->optionalInt($request, 'page') ?? 1,
                    'page_size' => $this->optionalInt($request, 'page_size') ?? 20,
                    'account_id' => $accountId,
                ]),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function saveAccount(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $accountId = $this->optionalInt($request, 'id');
            $roleId = $this->requiredInt($request, 'role_id');
            $role = Role::enabledById($roleId, ['id', 'role_type']);
            if (!$role) {
                return $this->fail(40001, '角色不存在或已停用', 400);
            }
            $this->assertAccountRoleWritable($accountId, (string) $role->role_type);

            $loginName = $this->requiredString($request, 'login_name', 80);
            if (Account::loginNameExists($loginName, $accountId)) {
                return $this->fail(40001, '登录账号已存在', 400);
            }

            $status = $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled');
            if ($accountId && $accountId === CurrentContext::accountId() && $status === 'disabled') {
                return $this->fail(40001, '不能停用当前登录账号', 400);
            }

            $name = $this->requiredString($request, 'name', 80);
            $password = $this->nullableString($request, 'password', 120);
            if (!$accountId && !$password) {
                $password = 'admin123456';
            }
            if ($password !== null && strlen($password) < 6) {
                return $this->fail(40001, '密码至少 6 位', 400);
            }

            $now = date('Y-m-d H:i:s');
            Account::connection()->transaction(function () use ($accountId, $loginName, $name, $now, $password, $request, $roleId, $status): void {
                $userValues = [
                    'name' => $name,
                    'mobile' => $this->nullableString($request, 'mobile', 40),
                    'email' => $this->nullableString($request, 'email', 120),
                    'status' => $status,
                    'updated_at' => $now,
                ];
                $accountValues = [
                    'login_name' => $loginName,
                    'status' => $status,
                    'updated_at' => $now,
                ];
                if ($password !== null) {
                    $accountValues['password'] = password_hash($password, PASSWORD_BCRYPT);
                }

                if ($accountId) {
                    $account = Account::activeById($accountId, ['id', 'user_id']);
                    if (!$account) {
                        throw new \InvalidArgumentException('账号不存在');
                    }
                    User::updateAdminUser((int) $account->user_id, $userValues);
                    Account::updateAdminAccount($accountId, $accountValues);
                    UserRole::setPrimaryRole($accountId, $roleId, $now);
                    return;
                }

                $user = User::createAdminUser(array_merge($userValues, ['created_at' => $now]));
                $account = Account::createAdminAccount((int) $user->id, array_merge($accountValues, ['created_at' => $now]));
                UserRole::setPrimaryRole((int) $account->id, $roleId, $now);
            });

            return $this->ok([], '已保存');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function changeAccountStatus(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $accountId = $this->requiredInt($request, 'id');
            if ($accountId === CurrentContext::accountId()) {
                return $this->fail(40001, '不能修改当前登录账号状态', 400);
            }
            $this->assertAccountRoleWritable($accountId, '');
            $status = $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled');
            $account = Account::activeById($accountId, ['id', 'user_id']);
            if (!$account) {
                return $this->fail(40400, '账号不存在', 404);
            }
            $now = date('Y-m-d H:i:s');

            Account::connection()->transaction(function () use ($account, $accountId, $now, $status): void {
                Account::updateAdminAccount($accountId, [
                    'status' => $status,
                    'updated_at' => $now,
                ]);
                User::updateAdminUser((int) $account->user_id, [
                    'status' => $status,
                    'updated_at' => $now,
                ]);
            });

            return $this->ok([], $status === 'enabled' ? '已启用' : '已停用');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function resetAccountPassword(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $accountId = $this->requiredInt($request, 'id');
            $this->assertAccountRoleWritable($accountId, '');
            $password = $this->nullableString($request, 'password', 120) ?? 'admin123456';
            if (strlen($password) < 6) {
                return $this->fail(40001, '密码至少 6 位', 400);
            }
            if (!Account::activeById($accountId, ['id'])) {
                return $this->fail(40400, '账号不存在', 404);
            }

            Account::updateAdminPassword($accountId, password_hash($password, PASSWORD_BCRYPT));

            return $this->ok([], '密码已重置');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
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
                'departments' => ChannelTable::enabledOptionRows('department', ['dep_id', 'dep_name', 'dep_short_name', 'dep_code'], ['sort']),
                'grades' => ChannelTable::enabledOptionRows('grade_list', ['grade_id', 'grade_name', 'dep_id', 'is_current'], ['sort']),
                'professions' => ChannelTable::enabledOptionRows('profession', ['profession_id', 'profession_name', 'profession_short_name', 'profession_code', 'dep_id', 'grade_id'], ['sort']),
                'classes' => ChannelTable::enabledOptionRows('class', ['class_id', 'class_name', 'class_short_name', 'class_num', 'dep_id', 'profession_id', 'grade_id'], ['sort']),
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

    private function requiredString(Request $request, string $key, int $maxLength = 255): string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        if ($value === '') {
            throw new \InvalidArgumentException("{$key} 不能为空");
        }

        return $value;
    }

    private function nullableString(Request $request, string $key, int $maxLength = 255): ?string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        return $value === '' ? null : $value;
    }

    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        $value = trim((string) $request->input($key, ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function enum(Request $request, string $key, array $values, string $default): string
    {
        $value = (string) $request->input($key, $default);
        return in_array($value, $values, true) ? $value : $default;
    }

    private function assertAccountRoleWritable(?int $accountId, string $targetRoleType): void
    {
        $currentRoleType = CurrentContext::roleType();
        if ($targetRoleType === 'super_admin' && $currentRoleType !== 'super_admin') {
            throw new \InvalidArgumentException('只有超级管理员可以分配超级管理员角色');
        }
        if ($accountId && $accountId === CurrentContext::accountId() && $targetRoleType !== '' && $targetRoleType !== $currentRoleType) {
            throw new \InvalidArgumentException('不能修改当前登录账号的角色');
        }
        if ($accountId && Account::primaryRoleTypeById($accountId) === 'super_admin' && $currentRoleType !== 'super_admin') {
            throw new \InvalidArgumentException('只有超级管理员可以维护超级管理员账号');
        }
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

    private function validateMenuParent(string $type, int $parentId, ?Menu $parent): string
    {
        if ($parentId > 0 && !$parent) {
            return '父级菜单不存在';
        }
        if ($type === 'directory') {
            return $parentId === 0 ? '' : '主菜单不能选择父级';
        }
        if ($parentId === 0) {
            return '非主菜单必须选择父级';
        }

        $parentType = (string) ($parent->type ?? '');
        if ($parentType === 'button') {
            return '按钮不能作为父级';
        }
        if ($type === 'button') {
            return $parentType === 'list' ? '' : '按钮必须挂在列表下';
        }
        if ($type === 'list') {
            return $parentType === 'menu' ? '' : '列表必须挂在菜单下';
        }
        if ($type === 'menu') {
            return in_array($parentType, ['directory', 'menu'], true) ? '' : '菜单不能挂在列表或按钮下';
        }

        return '';
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
