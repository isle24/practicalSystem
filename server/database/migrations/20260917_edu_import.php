<?php

use app\server\edu\EduImportSchema;
use Dotenv\Dotenv;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$env = static fn (string $key, mixed $default = ''): mixed => $_ENV[$key] ?? (getenv($key) ?: $default);
$databases = array_slice($argv, 1) ?: [
    $env('SCHOOL_TEMPLATE_DB', 'practical_template'),
    $env('DEFAULT_SCHOOL_DB', 'practical_default'),
];

$host = (string) $env('DB_HOST', '127.0.0.1');
$port = (int) $env('DB_PORT', 3306);
$user = (string) $env('DB_USER', 'root');
$pass = (string) $env('DB_PASS', '');
$charset = (string) $env('DB_CHARSET', 'utf8mb4');

foreach (array_unique($databases) as $database) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new RuntimeException('数据库名无效');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    EduImportSchema::ensure($pdo);
    echo "{$database}: edu import schema ready\n";
}
