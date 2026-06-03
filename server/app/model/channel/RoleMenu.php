<?php

namespace app\model\channel;

class RoleMenu extends BaseModel
{
    protected $table = 'role_menu';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function activeMenuIdsByRole(int $roleId): array
    {
        return self::query()
            ->where('role_id', $roleId)
            ->whereNull('deleted_at')
            ->pluck('menu_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public static function softDeleteByRole(int $roleId, string $now): int
    {
        return self::query()
            ->where('role_id', $roleId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
    }

    public static function softDeleteByMenu(int $menuId, string $now): int
    {
        return self::query()
            ->where('menu_id', $menuId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
    }

    public static function restoreOrCreate(int $roleId, int $menuId, string $now): void
    {
        $record = self::query()
            ->where('role_id', $roleId)
            ->where('menu_id', $menuId)
            ->first();

        if ($record) {
            $record->deleted_at = null;
            $record->updated_at = $now;
            $record->save();
            return;
        }

        self::query()->create([
            'role_id' => $roleId,
            'menu_id' => $menuId,
        ]);
    }
}
