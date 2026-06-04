<?php

namespace app\model\channel;

class UserWechat extends BaseModel
{
    protected $table = 'user_wechat';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function byUser(int $userId): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get([
                'id',
                'user_id',
                'wechat_userid',
                'wechat_name',
                'wechat_avatar',
                'department',
                'position',
                'mobile',
                'email',
                'last_synced_at',
                'created_at',
                'updated_at',
            ])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'wechat_userid' => $row->wechat_userid,
                'wechat_name' => $row->wechat_name,
                'wechat_avatar' => $row->wechat_avatar,
                'department' => self::decodeJson($row->department),
                'position' => $row->position,
                'mobile' => $row->mobile,
                'email' => $row->email,
                'last_synced_at' => $row->last_synced_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->all();
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
