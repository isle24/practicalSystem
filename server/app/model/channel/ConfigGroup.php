<?php

namespace app\model\channel;

class ConfigGroup extends BaseModel
{
    protected $table = 'config_group';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function enabledByCode(string $code): ?self
    {
        return self::query()
            ->where('code', $code)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'name']);
    }
}
