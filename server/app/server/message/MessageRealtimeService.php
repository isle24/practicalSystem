<?php

namespace app\server\message;

use app\server\CurrentContext;
use RuntimeException;
use support\Redis;

/** 实时连接的一次性授权票据。 */
class MessageRealtimeService
{
    /** 为当前登录账号签发短期票据。 */
    public function ticket(): array
    {
        $account = (int) CurrentContext::accountId();
        $school = (int) CurrentContext::schoolDatabaseId();
        if ($account <= 0 || $school <= 0) throw new RuntimeException('请先登录', 401);
        $ticket = bin2hex(random_bytes(32));
        Redis::setEx('message_ws_ticket:' . hash('sha256', $ticket), 60, json_encode([
            'school' => $school, 'account' => $account, 'jti' => CurrentContext::deviceJti(),
        ]));
        return ['ticket' => $ticket, 'path' => config('message_realtime.path'), 'expires_in' => 60];
    }

    /** 原子消费票据，票据不出现在 URL 中。 */
    public static function consume(string $ticket): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $ticket)) return null;
        $raw = Redis::eval("local v=redis.call('GET',KEYS[1]); if v then redis.call('DEL',KEYS[1]) end; return v", 1, 'message_ws_ticket:' . hash('sha256', $ticket));
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) && !empty($data['school']) && !empty($data['account']) ? $data : null;
    }
}
