<?php

namespace app\model\channel;

class WallpaperRecord extends TableRecord
{
    public static function byId(int $id): ?object
    {
        return self::queryTable('wallpaper')->where('id', $id)->whereNull('deleted_at')->first();
    }

    public static function byFile(int $fileId): ?object
    {
        return self::queryTable('wallpaper')->where('file_id', $fileId)->whereNull('deleted_at')->first();
    }

    public static function ownedByFile(int $fileId, int $accountId): ?object
    {
        return self::queryTable('wallpaper')->where('file_id', $fileId)
            ->where('owner_account_id', $accountId)->whereNull('deleted_at')->first();
    }

    public static function page(string $scope, int $accountId, int $page, int $size): array
    {
        $query = self::queryTable('wallpaper')->whereNull('deleted_at');
        if ($scope === 'mine') $query->where('owner_account_id', $accountId);
        else $query->where('is_shared', 1);
        return [
            'total' => (int) (clone $query)->count(),
            'items' => $query->orderByDesc('id')->forPage($page, $size)->get()->all(),
        ];
    }

    public static function createForFile(int $fileId, int $accountId, string $name): int
    {
        return (int) self::queryTable('wallpaper')->insertGetId([
            'file_id' => $fileId, 'owner_account_id' => $accountId, 'name' => $name,
            'is_shared' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function setShared(int $id, int $accountId, bool $shared): bool
    {
        return self::queryTable('wallpaper')->where('id', $id)->where('owner_account_id', $accountId)
            ->whereNull('deleted_at')->update(['is_shared' => $shared ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }
}
