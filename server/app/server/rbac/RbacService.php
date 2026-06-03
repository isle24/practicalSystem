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

        $role = Role::enabledById($roleId);

        return $role ? $role->toArray() : null;
    }

    public function roles(int $accountId): array
    {
        $this->ensureSchoolConnection();

        return Role::enabledForAccount($accountId);
    }

    public function permissionCodes(?int $roleId = null): array
    {
        $this->ensureSchoolConnection();

        $roleId ??= CurrentContext::roleId();
        if (!$roleId) {
            return [];
        }

        return Menu::permissionCodesByRole($roleId);
    }

    public function menus(string $platform = 'pc', ?int $roleId = null): array
    {
        $this->ensureSchoolConnection();

        $roleId ??= CurrentContext::roleId();
        if (!$roleId) {
            return [];
        }

        $items = Menu::visibleItemsByRole($roleId, $platform);

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

        return SysOrganization::activeScopes($accountId, $roleId);
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
