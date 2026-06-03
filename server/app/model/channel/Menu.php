<?php

namespace app\model\channel;

class Menu extends BaseModel
{
    protected $table = 'menu';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function enabledItems(bool $visibleOnly = false): array
    {
        $query = self::query()
            ->where('status', 'enabled')
            ->whereNull('deleted_at');

        if ($visibleOnly) {
            $query->where('visible', 'true');
        }

        return $query
            ->orderBy('sort')
            ->get(['id', 'parent_id', 'name', 'code', 'path', 'url', 'platform', 'type', 'sort', 'icon', 'visible', 'status'])
            ->map(static fn ($menu): array => $menu->toArray())
            ->all();
    }

    public static function permissionCodesByRole(int $roleId): array
    {
        return self::query()
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

    public static function visibleItemsByRole(int $roleId, string $platform): array
    {
        return self::query()
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
    }

    public static function enabledIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return self::query()
            ->whereIn('id', $ids)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public static function activeById(int $id, array $columns = ['*']): ?self
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function parentIdOfActive(int $id): int
    {
        return (int) (self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->value('parent_id') ?? 0);
    }

    public static function activeChildrenCount(int $parentId): int
    {
        return (int) self::query()
            ->where('parent_id', $parentId)
            ->whereNull('deleted_at')
            ->count();
    }

    public static function createMenu(array $values): self
    {
        return self::query()->create($values);
    }

    public static function disableMenu(int $id, string $now): int
    {
        return self::query()
            ->where('id', $id)
            ->update(['status' => 'disabled', 'deleted_at' => $now, 'updated_at' => $now]);
    }
}
