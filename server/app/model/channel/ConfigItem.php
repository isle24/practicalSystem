<?php

namespace app\model\channel;

class ConfigItem extends BaseModel
{
    protected $table = 'config_item';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function enabledValue(int $groupId, string $key, int $collegeId = 0, int $userId = 0): mixed
    {
        $item = self::query()
            ->where('group_id', $groupId)
            ->where('key', $key)
            ->whereIn('college_id', [$collegeId, 0])
            ->whereIn('user_id', [$userId, 0])
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderByDesc('user_id')
            ->orderByDesc('college_id')
            ->first(['value']);

        return $item ? json_decode((string) $item->value, true) : null;
    }

    public static function enabledList(int $groupId): array
    {
        return self::query()
            ->where('group_id', $groupId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->get(['id', 'key', 'value', 'college_id', 'user_id', 'description', 'sort'])
            ->map(static fn ($item): array => [
                'id' => $item->id,
                'key' => $item->key,
                'value' => json_decode((string) $item->value, true),
                'college_id' => (int) $item->college_id,
                'user_id' => (int) $item->user_id,
                'description' => $item->description,
                'sort' => (int) $item->sort,
            ])
            ->all();
    }

    public static function saveValue(int $groupId, string $key, mixed $value, string $description, int $collegeId = 0, int $userId = 0): self
    {
        return self::query()->updateOrCreate(
            [
                'group_id' => $groupId,
                'key' => $key,
                'college_id' => $collegeId,
                'user_id' => $userId,
            ],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'description' => $description,
                'status' => 'enabled',
                'deleted_at' => null,
            ]
        );
    }
}
