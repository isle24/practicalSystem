<?php

namespace app\server\auth;

use app\server\CurrentContext;
use support\Redis;
use Throwable;

class DeviceBlacklist
{
    /**
     * 将设备令牌标识加入黑名单，使其立即失效。
     */
    public static function revoke(string $jti, int $ttl): void
    {
        $jti = trim($jti);
        if ($jti === '') {
            return;
        }

        try {
            Redis::setEx(self::key($jti), max(60, $ttl), '1');
        } catch (Throwable) {
        }
    }

    /**
     * 判断设备令牌标识是否已被下线。
     */
    public static function isRevoked(string $jti): bool
    {
        $jti = trim($jti);
        if ($jti === '') {
            return false;
        }

        try {
            return (int) Redis::exists(self::key($jti)) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 生成按学校库隔离的黑名单缓存键。
     */
    private static function key(string $jti): string
    {
        $databaseId = CurrentContext::schoolDatabaseId() ?: 0;
        return "jwt_blacklist:{$databaseId}:{$jti}";
    }
}
