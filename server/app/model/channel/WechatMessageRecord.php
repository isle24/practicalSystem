<?php

namespace app\model\channel;

class WechatMessageRecord extends TableRecord
{
    private static function due(): mixed
    {
        $now = date('Y-m-d H:i:s');
        return self::queryTable('message_channel_log')->where('channel', 'wechat')
            ->where('status', 'pending')->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', $now));
    }

    public static function pendingIds(): array
    {
        return self::due()->orderBy('id')->limit(500)->pluck('id')->all();
    }

    public static function claim(int $id): ?array
    {
        return self::connection()->transaction(function () use ($id): ?array {
            $row = self::due()->where('id', $id)->lockForUpdate()->first();
            if (!$row) return null;
            $token = bin2hex(random_bytes(24));
            self::queryTable('message_channel_log')->where('id', $id)->update([
                'claim_token' => $token, 'locked_until' => date('Y-m-d H:i:s', time() + 300),
                'attempts' => (int) $row->attempts + 1, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return [...$row->toArray(), 'claim_token' => $token, 'attempts' => (int) $row->attempts + 1];
        });
    }

    public static function finish(array $claim, string $status, ?string $error = null, int $delay = 0): void
    {
        self::queryTable('message_channel_log')->where('id', $claim['id'])
            ->where('status', 'pending')->where('claim_token', $claim['claim_token'])->update([
                'status' => $status, 'error_message' => $error,
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
                'available_at' => $status === 'pending' ? date('Y-m-d H:i:s', time() + $delay) : null,
                'locked_until' => null, 'claim_token' => null, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
