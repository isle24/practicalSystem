<?php

namespace app\server\rbac;

use app\model\channel\Menu;
use app\model\channel\Role;
use app\model\channel\SysOrganization;
use app\server\CurrentContext;
use RuntimeException;

class RbacService
{
    public function role(?int $roleId = null): ?array
    {
        $this->ensureSchoolConnection();

        $roleId ??= CurrentContext::roleId();
        if (!$roleId) {
            return null;
        }

        $role = Role::query()
            ->where('id', $roleId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'name', 'role_type', 'sort', 'status']);

        return $role ? $role->toArray() : null;
    }

    public function roles(int $accountId): array
    {
        $this->ensureSchoolConnection();

        return Role::query()
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

    public function permissionCodes(?int $roleId = null): array
    {
        $this->ensureSchoolConnection();

        $roleId ??= CurrentContext::roleId();
        if (!$roleId) {
            return [];
        }

        return Menu::query()
            ->join('role_menu', 'menu.id', '=', 'role_menu.menu_id')
            ->where('role_menu.role_id', $roleId)
            ->where('menu.status', 'enabled')
            ->whereNull('menu.deleted_at')
            ->whereNull('role_menu.deleted_at')
            ->whereNotNull('menu.code')
            ->where('menu.code', '<>', '')
            ->pluck('menu.code')
            ->unique()
            ->values()
            ->all();
    }

    public function menus(string $platform = 'pc', ?int $roleId = null): array
    {
        $this->ensureSchoolConnection();

        $roleId ??= CurrentContext::roleId();
        if (!$roleId) {
            return [];
        }

        $items = Menu::query()
            ->join('role_menu', 'menu.id', '=', 'role_menu.menu_id')
            ->where('role_menu.role_id', $roleId)
            ->where('menu.status', 'enabled')
            ->where('menu.visible', 'true')
            ->whereNull('menu.deleted_at')
            ->whereNull('role_menu.deleted_at')
            ->whereIn('menu.type', ['directory', 'menu'])
            ->whereIn('menu.platform', [$platform, 'both'])
            ->orderBy('menu.sort')
            ->get([
                'menu.id',
                'menu.parent_id',
                'menu.name',
                'menu.code',
                'menu.path',
                'menu.url',
                'menu.platform',
                'menu.type',
                'menu.sort',
                'menu.icon',
            ])
            ->map(static fn ($menu): array => $menu->toArray())
            ->all();

        return $this->tree($items);
    }

    public function organizationScopes(?int $accountId = null, ?int $roleId = null): array
    {
        $this->ensureSchoolConnection();

        $accountId ??= CurrentContext::accountId();
        $roleId ??= CurrentContext::roleId();
        if (!$accountId || !$roleId) {
            return [];
        }

        return SysOrganization::query()
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

    public function ensureSchoolConnection(): void
    {
        if (!CurrentContext::get('school_connection')) {
            throw new RuntimeException('学校业务库连接未解析');
        }
    }

    private function tree(array $items): array
    {
        $children = [];
        foreach ($items as $item) {
            $parentId = (int) ($item['parent_id'] ?? 0);
            $item['children'] = [];
            $children[$parentId][] = $item;
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
