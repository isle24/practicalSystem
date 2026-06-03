<?php

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : $value;
};

$pool = static function (string $prefix, int $max, int $min) use ($env): array {
    return [
        'max_connections' => (int) $env("{$prefix}_MAX_CONNECTIONS", $env('DB_POOL_MAX_CONNECTIONS', $max)),
        'min_connections' => (int) $env("{$prefix}_MIN_CONNECTIONS", $env('DB_POOL_MIN_CONNECTIONS', $min)),
        'wait_timeout' => (int) $env('DB_POOL_WAIT_TIMEOUT', 3),
        'idle_timeout' => (int) $env('DB_POOL_IDLE_TIMEOUT', 60),
        'heartbeat_interval' => (int) $env('DB_POOL_HEARTBEAT_INTERVAL', 50),
    ];
};

$workerCount = max(1, (int) $env('WEBMAN_WORKER_COUNT', 16));
$masterPool = $pool('DB_MASTER_POOL', 1, 0);
$schoolPool = $pool('DB_SCHOOL_POOL', $workerCount, 1);

$master = [
    'driver' => 'mysql',
    'host' => $env('DB_HOST', '127.0.0.1'),
    'port' => (int) $env('DB_PORT', 3306),
    'database' => $env('DB_NAME', 'practical_master'),
    'username' => $env('DB_USER', 'root'),
    'password' => $env('DB_PASS', ''),
    'charset' => $env('DB_CHARSET', 'utf8mb4'),
    'collation' => $env('DB_COLLATION', 'utf8mb4_general_ci'),
    'prefix' => $env('DB_PREFIX', ''),
    'strict' => filter_var($env('DB_STRICT', true), FILTER_VALIDATE_BOOL),
    'engine' => $env('DB_ENGINE', null),
    'options' => [
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
    'pool' => $masterPool,
];

$schoolTemplate = $master;
$schoolTemplate['database'] = $env('SCHOOL_TEMPLATE_DB', 'practical_template');
$schoolTemplate['pool'] = $schoolPool;

return [
    'default' => $env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => $master,
        'master' => $master,
        'school_template' => $schoolTemplate,
    ],
];
