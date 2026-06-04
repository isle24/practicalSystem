<?php

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : $value;
};

$host = (string) $env('REDIS_QUEUE_HOST', $env('REDIS_HOST', '127.0.0.1'));
$port = (int) $env('REDIS_QUEUE_PORT', $env('REDIS_PORT', 6379));
$auth = (string) $env('REDIS_QUEUE_PASS', $env('REDIS_PASS', ''));

if (!str_starts_with($host, 'redis://')) {
    $host = "redis://{$host}:{$port}";
}

return [
    'default' => [
        'host' => $host,
        'options' => [
            'auth' => $auth !== '' ? $auth : null,
            'db' => (int) $env('REDIS_QUEUE_DB', $env('REDIS_DB', 0)),
            'prefix' => (string) $env('REDIS_QUEUE_PREFIX', ''),
            'max_attempts' => (int) $env('REDIS_QUEUE_MAX_ATTEMPTS', 5),
            'retry_seconds' => (int) $env('REDIS_QUEUE_RETRY_SECONDS', 5),
        ]
    ],
];
