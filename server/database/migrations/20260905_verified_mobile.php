<?php

/** 为现有学校库补充可信手机号字段，不修改历史绑定数据。 */
use Dotenv\Dotenv;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$env = static fn (string $key, mixed $default = ''): mixed => $_ENV[$key] ?? (getenv($key) ?: $default);
$databases = array_slice($argv, 1) ?: [
    $env('SCHOOL_TEMPLATE_DB', 'practical_template'),
    $env('DEFAULT_SCHOOL_DB', 'practical_default'),
];
$pdo = new PDO(
    'mysql:host=' . $env('DB_HOST', '127.0.0.1') . ';port=' . $env('DB_PORT', 3306) . ';charset=utf8mb4',
    $env('DB_USER', 'root'), $env('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
foreach (array_unique($databases) as $database) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new RuntimeException('数据库名无效');
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $exists->execute([$database, 'users', 'verified_mobile']);
    if (!(int) $exists->fetchColumn()) {
        $pdo->exec("ALTER TABLE `{$database}`.`users` ADD COLUMN `verified_mobile` VARCHAR(40) DEFAULT NULL COMMENT '已短信验证的手机号' AFTER `mobile`");
        echo "{$database}: verified_mobile added\n";
    } else {
        echo "{$database}: verified_mobile exists\n";
    }
}
