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

    public static function currentByUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = self::query()->where('user_id', $userId)->first();
        return $row ? self::row($row) : null;
    }

    public static function byWechatUserId(string $wechatUserId): ?array
    {
        $wechatUserId = trim($wechatUserId);
        if ($wechatUserId === '') {
            return null;
        }

        $row = self::query()->where('wechat_userid', $wechatUserId)->first();
        return $row ? self::row($row) : null;
    }

    public static function bindUser(int $userId, string $wechatUserId, string $corpId): void
    {
        try {
            User::connection()->transaction(function () use ($userId, $wechatUserId, $corpId): void {
                if (!User::lockProfile($userId)) throw new \RuntimeException('用户不存在或已停用');
                $current = self::currentByUser($userId);
                if ($current && ($current['wechat_userid'] !== $wechatUserId || $current['corp_id'] !== $corpId)) throw new \RuntimeException('当前用户已绑定其他企业微信，请管理员先解除绑定');
                $owner = self::byWechatUserId($wechatUserId);
                if ($owner && $owner['user_id'] !== $userId) throw new \RuntimeException('该企业微信已绑定其他用户，请管理员先解除绑定');
                if (!$current) self::query()->insert([
                    'user_id' => $userId, 'wechat_userid' => $wechatUserId, 'raw_data' => json_encode(['corp_id' => $corpId]),
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) throw new \RuntimeException('企业微信或系统用户已绑定，请刷新后重试');
            throw $exception;
        }
    }

    public static function unbindByUser(int $userId): int
    {
        return User::connection()->transaction(function () use ($userId): int {
            User::query()->where('id', $userId)->lockForUpdate()->first(['id']);
            return (int) self::query()->where('user_id', $userId)->delete();
        });
    }

    private static function row(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'wechat_userid' => $row->wechat_userid,
            'corp_id' => (string) (self::decodeJson($row->raw_data ?? null)['corp_id'] ?? ''),
            'wechat_name' => $row->wechat_name,
            'wechat_avatar' => $row->wechat_avatar,
            'department' => self::decodeJson($row->department),
            'position' => $row->position,
            'mobile' => $row->mobile,
            'email' => $row->email,
            'last_synced_at' => $row->last_synced_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
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
