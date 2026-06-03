<?php

namespace app\server\school;

use app\model\system\Authorization;
use Throwable;

class SchoolResolver
{
    private static array $cache = [];
    private array $fields = [
        'authorizations.authorization_id',
        'authorizations.school_id',
        'schools.school_code',
        'schools.school_name',
        'authorizations.database_id',
        'databases.database_host',
        'databases.database_port',
        'databases.database_user',
        'databases.database_pwd',
        'databases.database_db',
        'databases.database_charset',
        'databases.database_prefix',
        'databases.is_default_business_db',
        'databases.config_version',
        'databases.updated_at',
    ];

    public function resolveByDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }
        $cacheKey = 'domain:' . $domain;
        $cached = $this->cached($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $record = Authorization::query()
            ->join('databases', 'authorizations.database_id', '=', 'databases.database_id')
            ->join('schools', 'authorizations.school_id', '=', 'schools.school_id')
            ->where('authorizations.authorization_domain', $domain)
            ->where('authorizations.status', 'enabled')
            ->where('databases.status', 'enabled')
            ->where('schools.status', 'enabled')
            ->first($this->fields);

        return $this->remember($cacheKey, $record ? $record->toArray() : null);
    }

    public function resolveDefaultBusinessDatabase(): ?array
    {
        $cacheKey = 'default';
        $cached = $this->cached($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $record = Authorization::query()
            ->join('databases', 'authorizations.database_id', '=', 'databases.database_id')
            ->join('schools', 'authorizations.school_id', '=', 'schools.school_id')
            ->where('authorizations.status', 'enabled')
            ->where('databases.status', 'enabled')
            ->where('schools.status', 'enabled')
            ->where('databases.is_default_business_db', 'true')
            ->orderBy('authorizations.authorization_id')
            ->first($this->fields);

        return $this->remember($cacheKey, $record ? $record->toArray() : null);
    }

    public function resolveByDomainOrDefault(string $domain): ?array
    {
        return $this->resolveByDomain($domain) ?? $this->resolveDefaultBusinessDatabase();
    }

    private function cached(string $key): mixed
    {
        $item = self::$cache[$key] ?? null;
        if (!$item || (int) $item['expires_at'] < time()) {
            unset(self::$cache[$key]);
        } else {
            return $item['value'];
        }

        try {
            $payload = $this->redis()?->get($this->redisKey($key));
            $cached = is_string($payload) ? json_decode($payload, true) : null;
            if (is_array($cached) && array_key_exists('value', $cached)) {
                self::$cache[$key] = [
                    'value' => $cached['value'],
                    'expires_at' => time() + $this->ttl(),
                ];
                return $cached['value'];
            }
        } catch (Throwable $exception) {
            error_log('school resolver redis read failed: ' . $exception->getMessage());
        }

        return false;
    }

    private function remember(string $key, ?array $value): ?array
    {
        $ttl = $this->ttl();
        self::$cache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl,
        ];
        try {
            $this->redis()?->setex($this->redisKey($key), $ttl, json_encode(['value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            error_log('school resolver redis write failed: ' . $exception->getMessage());
        }

        return $value;
    }

    private function ttl(): int
    {
        return max(1, (int) (getenv('SCHOOL_RESOLVER_CACHE_TTL') ?: 300));
    }

    private function redisKey(string $key): string
    {
        return 'practical:school_resolver:' . $key;
    }

    private function redis(): ?\Redis
    {
        static $redis = null;
        if ($redis instanceof \Redis) {
            return $redis;
        }

        try {
            $redis = new \Redis();
            $redis->connect((string) (getenv('REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('REDIS_PORT') ?: 6379), 1.5);
            $password = (string) (getenv('REDIS_PASS') ?: '');
            if ($password !== '') {
                $redis->auth($password);
            }
            $redis->select((int) (getenv('REDIS_DB') ?: 0));
            return $redis;
        } catch (Throwable) {
            $redis = null;
            return null;
        }
    }
}
