<?php

namespace app\model\channel;

class User extends BaseModel
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function enabledById(int $id, array $columns = ['*']): ?self
    {
        return self::query()
            ->where('id', $id)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function activeById(int $id, array $columns = ['*']): ?self
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function updateActiveProfile(int $id, array $values): int
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update($values);
    }
}
