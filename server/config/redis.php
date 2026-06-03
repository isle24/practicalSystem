<?php

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : $value;
};

return [
    'default' => [
        'password' => $env('REDIS_PASS', ''),
        'host' => $env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) $env('REDIS_PORT', 6379),
        'database' => (int) $env('REDIS_DB', 0),
        'pool' => [
            'max_connections' => (int) $env('REDIS_POOL_MAX_CONNECTIONS', 5),
            'min_connections' => (int) $env('REDIS_POOL_MIN_CONNECTIONS', 1),
            'wait_timeout' => (int) $env('REDIS_POOL_WAIT_TIMEOUT', 3),
            'idle_timeout' => (int) $env('REDIS_POOL_IDLE_TIMEOUT', 60),
            'heartbeat_interval' => (int) $env('REDIS_POOL_HEARTBEAT_INTERVAL', 50),
        ],
    ],
];
