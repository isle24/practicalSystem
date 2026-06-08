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

    public static function enabledOrCreate(string $code, string $name = ''): self
    {
        $group = self::query()
            ->where('code', $code)
            ->first();

        if ($group) {
            $group->name = $group->name ?: ($name ?: $code);
            $group->status = 'enabled';
            $group->deleted_at = null;
            $group->save();
            return $group;
        }

        return self::query()->create([
            'parent_id' => 0,
            'code' => $code,
            'name' => $name ?: $code,
            'sort' => 0,
            'status' => 'enabled',
        ]);
    }
}
