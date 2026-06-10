<?php

use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : $value;
};

$host = (string) $env('DB_HOST', '127.0.0.1');
$port = (int) $env('DB_PORT', 3306);
$user = (string) $env('DB_USER', 'root');
$pass = (string) $env('DB_PASS', '');
$charset = (string) $env('DB_CHARSET', 'utf8mb4');
$masterDb = (string) $env('DB_NAME', 'practical_master');
$templateDb = (string) $env('SCHOOL_TEMPLATE_DB', 'practical_template');
$defaultSchoolDb = (string) $env('DEFAULT_SCHOOL_DB', 'practical_default');
$defaultDomain = (string) $env('DEFAULT_SCHOOL_DOMAIN', '127.0.0.1');
$wechatProxyUrl = (string) $env('WECHAT_PROXY_URL', '');

$pdo = new PDO(
    "mysql:host={$host};port={$port};charset={$charset}",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

createDatabase($pdo, $masterDb, $charset);
createDatabase($pdo, $templateDb, $charset);
createDatabase($pdo, $defaultSchoolDb, $charset);

$master = databasePdo($host, $port, $user, $pass, $masterDb, $charset);
createMasterSchema($master);
seedMaster($master, [
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'pass' => $pass,
    'charset' => $charset,
    'defaultSchoolDb' => $defaultSchoolDb,
    'defaultDomain' => $defaultDomain,
]);

foreach ([$templateDb, $defaultSchoolDb] as $schoolDb) {
    $school = databasePdo($host, $port, $user, $pass, $schoolDb, $charset);
    createSchoolSchema($school);
    seedSchool($school, $wechatProxyUrl);
}

echo "database initialized\n";
echo "master={$masterDb}\n";
echo "template={$templateDb}\n";
echo "default_school={$defaultSchoolDb}\n";

function databasePdo(string $host, int $port, string $user, string $pass, string $db, string $charset): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function quoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException("非法标识符：{$identifier}");
    }

    return "`{$identifier}`";
}

function createDatabase(PDO $pdo, string $database, string $charset): void
{
    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . quoteIdentifier($database) . " DEFAULT CHARACTER SET {$charset} COLLATE {$charset}_general_ci");
}

function execSql(PDO $pdo, array $statements): void
{
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function createMasterSchema(PDO $pdo): void
{
    execSql($pdo, [
        "CREATE TABLE IF NOT EXISTS `schools` (
            `school_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_name` VARCHAR(120) NOT NULL,
            `school_code` VARCHAR(80) NOT NULL,
            `school_domain` VARCHAR(180) DEFAULT NULL,
            `school_contact` VARCHAR(80) DEFAULT NULL,
            `school_scale` ENUM('small','medium','large') DEFAULT 'small',
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`school_id`),
            UNIQUE KEY `uk_school_code` (`school_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `databases` (
            `database_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `database_host` VARCHAR(120) NOT NULL,
            `database_port` INT UNSIGNED DEFAULT 3306,
            `database_user` VARCHAR(80) NOT NULL,
            `database_pwd` VARCHAR(255) DEFAULT '',
            `database_db` VARCHAR(120) NOT NULL,
            `database_charset` VARCHAR(40) DEFAULT 'utf8mb4',
            `database_prefix` VARCHAR(40) DEFAULT '',
            `is_default_business_db` ENUM('false','true') DEFAULT 'false',
            `default_business_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `is_default_business_db` = 'true' AND `status` = 'enabled' THEN 1 ELSE NULL END) STORED,
            `config_version` INT UNSIGNED DEFAULT 1,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`database_id`),
            UNIQUE KEY `uk_database_db` (`database_db`),
            UNIQUE KEY `uk_default_business` (`default_business_flag`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `authorizations` (
            `authorization_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `authorization_domain` VARCHAR(180) NOT NULL,
            `school_id` BIGINT UNSIGNED NOT NULL,
            `database_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`authorization_id`),
            UNIQUE KEY `uk_authorization_domain` (`authorization_domain`),
            KEY `idx_school_id` (`school_id`),
            KEY `idx_database_id` (`database_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ]);

    ensureColumn($pdo, 'databases', 'is_default_business_db', "ALTER TABLE `databases` ADD COLUMN `is_default_business_db` ENUM('false','true') DEFAULT 'false' AFTER `database_prefix`");
}

function ensureColumn(PDO $pdo, string $table, string $column, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($ddl);
    }
}

function ensureIndex(PDO $pdo, string $table, string $index, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($ddl);
    }
}

function seedMaster(PDO $pdo, array $config): void
{
    $pdo->prepare(
        "INSERT INTO `schools` (`school_id`, `school_name`, `school_code`, `school_domain`, `school_contact`, `school_scale`, `status`)
         VALUES (1, '成都锦城学院', '2184', ?, '本地测试', 'medium', 'enabled')
         ON DUPLICATE KEY UPDATE
            `school_name` = VALUES(`school_name`),
            `school_code` = VALUES(`school_code`),
            `school_domain` = VALUES(`school_domain`),
            `school_scale` = VALUES(`school_scale`),
            `status` = 'enabled'"
    )->execute([$config['defaultDomain']]);

    $pdo->exec("UPDATE `databases` SET `is_default_business_db` = 'false' WHERE `is_default_business_db` = 'true'");

    $pdo->prepare(
        "INSERT INTO `databases` (`database_id`, `database_host`, `database_port`, `database_user`, `database_pwd`, `database_db`, `database_charset`, `database_prefix`, `is_default_business_db`, `config_version`, `status`)
         VALUES (1, ?, ?, ?, ?, ?, ?, '', 'true', 1, 'enabled')
         ON DUPLICATE KEY UPDATE
            `database_host` = VALUES(`database_host`),
            `database_port` = VALUES(`database_port`),
            `database_user` = VALUES(`database_user`),
            `database_pwd` = VALUES(`database_pwd`),
            `database_charset` = VALUES(`database_charset`),
            `is_default_business_db` = 'true',
            `status` = 'enabled',
            `config_version` = `config_version` + 1"
    )->execute([
        $config['host'],
        $config['port'],
        $config['user'],
        $config['pass'],
        $config['defaultSchoolDb'],
        $config['charset'],
    ]);

    foreach (array_unique([$config['defaultDomain'], 'localhost', '127.0.0.1']) as $domain) {
        $pdo->prepare(
            "INSERT INTO `authorizations` (`authorization_domain`, `school_id`, `database_id`, `status`)
             VALUES (?, 1, 1, 'enabled')
             ON DUPLICATE KEY UPDATE `school_id` = 1, `database_id` = 1, `status` = 'enabled'"
        )->execute([$domain]);
    }
}

function createSchoolSchema(PDO $pdo): void
{
    execSql($pdo, schoolCoreStatements());
    execSql($pdo, schoolBusinessStatements());

    ensureMenuSchema($pdo);
    ensureArchiveSchema($pdo);
    ensureColumn($pdo, 'account', 'login_name', "ALTER TABLE `account` ADD COLUMN `login_name` VARCHAR(80) DEFAULT NULL AFTER `user_id`");
    ensureIndex($pdo, 'account', 'uk_login_name', "ALTER TABLE `account` ADD UNIQUE KEY `uk_login_name` (`login_name`)");
    ensureFileSchema($pdo);
    ensureInternshipSchema($pdo);
    ensurePracticeSchema($pdo);
    ensureMessageSchema($pdo);
    ensureDocSchema($pdo);
    ensureTemplateSchema($pdo);
    ensureExportTaskSchema($pdo);
}

function ensureMenuSchema(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE `menu` MODIFY COLUMN `type` ENUM('directory','menu','list','button') DEFAULT 'menu'");
    $indexes = menuIndexMap($pdo);
    if (isset($indexes['uk_menu_code']) && (int) $indexes['uk_menu_code']['non_unique'] === 0) {
        $pdo->exec('ALTER TABLE `menu` DROP INDEX `uk_menu_code`');
    }
    ensureIndex($pdo, 'menu', 'idx_menu_code', 'ALTER TABLE `menu` ADD KEY `idx_menu_code` (`code`)');
}

function menuIndexMap(PDO $pdo): array
{
    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM `menu`') as $row) {
        $indexName = (string) $row['Key_name'];
        if (($row['Column_name'] ?? '') === 'code') {
            $indexes[$indexName] = ['non_unique' => (int) $row['Non_unique']];
        }
    }

    return $indexes;
}

function ensureArchiveSchema(PDO $pdo): void
{
    ensureColumn($pdo, 'department', 'dep_short_name', "ALTER TABLE `department` ADD COLUMN `dep_short_name` VARCHAR(80) DEFAULT NULL AFTER `dep_name`");
    ensureColumn($pdo, 'grade_list', 'is_current', "ALTER TABLE `grade_list` ADD COLUMN `is_current` ENUM('false','true') DEFAULT 'false' AFTER `dep_id`");
    ensureColumn($pdo, 'profession', 'profession_short_name', "ALTER TABLE `profession` ADD COLUMN `profession_short_name` VARCHAR(80) DEFAULT NULL AFTER `profession_name`");
    ensureColumn($pdo, 'class', 'class_short_name', "ALTER TABLE `class` ADD COLUMN `class_short_name` VARCHAR(80) DEFAULT NULL AFTER `class_name`");
}

function schoolCoreStatements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `department` (
            `dep_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dep_uuid` CHAR(36) DEFAULT NULL,
            `dep_name` VARCHAR(120) NOT NULL,
            `dep_short_name` VARCHAR(80) DEFAULT NULL,
            `dep_code` VARCHAR(80) DEFAULT NULL,
            `parent_id` BIGINT UNSIGNED DEFAULT 0,
            `sort` INT DEFAULT 0,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`dep_id`),
            UNIQUE KEY `uk_dep_uuid` (`dep_uuid`),
            KEY `idx_flag` (`flag`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `grade_list` (
            `grade_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `grade_uuid` CHAR(36) DEFAULT NULL,
            `grade_name` VARCHAR(80) NOT NULL,
            `dep_id` BIGINT UNSIGNED DEFAULT NULL,
            `is_current` ENUM('false','true') DEFAULT 'false',
            `sort` INT DEFAULT 0,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`grade_id`),
            UNIQUE KEY `uk_grade_uuid` (`grade_uuid`),
            KEY `idx_dep_id` (`dep_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `profession` (
            `profession_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `profession_uuid` CHAR(36) DEFAULT NULL,
            `profession_name` VARCHAR(120) NOT NULL,
            `profession_short_name` VARCHAR(80) DEFAULT NULL,
            `profession_code` VARCHAR(80) DEFAULT NULL,
            `dep_id` BIGINT UNSIGNED DEFAULT NULL,
            `grade_id` BIGINT UNSIGNED DEFAULT NULL,
            `sort` INT DEFAULT 0,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`profession_id`),
            UNIQUE KEY `uk_profession_uuid` (`profession_uuid`),
            KEY `idx_dep_id` (`dep_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `profession_direction` (
            `direction_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `direction_uuid` CHAR(36) DEFAULT NULL,
            `direction_name` VARCHAR(120) NOT NULL,
            `profession_id` BIGINT UNSIGNED DEFAULT NULL,
            `sort` INT DEFAULT 0,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`direction_id`),
            UNIQUE KEY `uk_direction_uuid` (`direction_uuid`),
            KEY `idx_profession_id` (`profession_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `class` (
            `class_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `class_uuid` CHAR(36) DEFAULT NULL,
            `class_name` VARCHAR(120) NOT NULL,
            `class_short_name` VARCHAR(80) DEFAULT NULL,
            `class_num` VARCHAR(80) DEFAULT NULL,
            `dep_id` BIGINT UNSIGNED DEFAULT NULL,
            `profession_id` BIGINT UNSIGNED DEFAULT NULL,
            `grade_id` BIGINT UNSIGNED DEFAULT NULL,
            `sort` INT DEFAULT 0,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`class_id`),
            UNIQUE KEY `uk_class_uuid` (`class_uuid`),
            KEY `idx_profession_id` (`profession_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `companies` (
            `company_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_uuid` CHAR(36) DEFAULT NULL,
            `company_name` VARCHAR(180) NOT NULL,
            `credit_code` VARCHAR(80) DEFAULT NULL,
            `contact_name` VARCHAR(80) DEFAULT NULL,
            `contact_mobile` VARCHAR(40) DEFAULT NULL,
            `address` VARCHAR(255) DEFAULT NULL,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`company_id`),
            UNIQUE KEY `uk_company_uuid` (`company_uuid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `users` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(80) NOT NULL,
            `avatar` VARCHAR(255) DEFAULT NULL,
            `mobile` VARCHAR(40) DEFAULT NULL,
            `email` VARCHAR(120) DEFAULT NULL,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_uuid` (`uuid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `user_wechat` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `wechat_userid` VARCHAR(120) NOT NULL,
            `wechat_name` VARCHAR(120) DEFAULT NULL,
            `wechat_avatar` VARCHAR(255) DEFAULT NULL,
            `department` JSON DEFAULT NULL,
            `position` VARCHAR(120) DEFAULT NULL,
            `mobile` VARCHAR(40) DEFAULT NULL,
            `email` VARCHAR(120) DEFAULT NULL,
            `raw_data` JSON DEFAULT NULL,
            `last_synced_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_wechat_userid` (`wechat_userid`),
            UNIQUE KEY `uk_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `account` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `login_name` VARCHAR(80) DEFAULT NULL,
            `password` VARCHAR(255) DEFAULT NULL,
            `two_factor_secret` TEXT DEFAULT NULL,
            `two_factor_enabled` ENUM('false','true') DEFAULT 'false',
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_account_uuid` (`uuid`),
            UNIQUE KEY `uk_login_name` (`login_name`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `role` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(80) NOT NULL,
            `name` VARCHAR(80) NOT NULL,
            `role_type` VARCHAR(80) NOT NULL,
            `sort` INT DEFAULT 0,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_role_code` (`code`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `user_role` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `account_id` BIGINT UNSIGNED NOT NULL,
            `role_id` BIGINT UNSIGNED NOT NULL,
            `is_primary` ENUM('false','true') DEFAULT 'false',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_account_role` (`account_id`, `role_id`),
            KEY `idx_role_id` (`role_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `sys_organization` (
            `organization_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `account_id` BIGINT UNSIGNED DEFAULT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `role_id` BIGINT UNSIGNED DEFAULT NULL,
            `dep_id` VARCHAR(50) DEFAULT NULL,
            `profession_id` VARCHAR(50) DEFAULT NULL,
            `class_id` VARCHAR(50) DEFAULT NULL,
            `company_id` VARCHAR(50) DEFAULT NULL,
            `cate_id` VARCHAR(50) DEFAULT NULL,
            `scope_key` VARCHAR(255) GENERATED ALWAYS AS (CONCAT_WS(':', COALESCE(`dep_id`, '0'), COALESCE(`profession_id`, '0'), COALESCE(`class_id`, '0'), COALESCE(`company_id`, '0'), COALESCE(`cate_id`, '0'))) STORED,
            `enabled_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `disabled` = 'false' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED,
            `disabled` ENUM('false','true') DEFAULT 'false',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`organization_id`),
            UNIQUE KEY `uk_scope` (`account_id`, `role_id`, `scope_key`, `enabled_flag`),
            KEY `idx_role_disabled` (`role_id`, `disabled`),
            KEY `idx_dep_disabled` (`dep_id`, `disabled`),
            KEY `idx_profession_disabled` (`profession_id`, `disabled`),
            KEY `idx_class_disabled` (`class_id`, `disabled`),
            KEY `idx_company_disabled` (`company_id`, `disabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `students` (
            `student_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `student_uuid` CHAR(36) DEFAULT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `name` VARCHAR(80) NOT NULL,
            `student_num` VARCHAR(80) DEFAULT NULL,
            `grade_id` BIGINT UNSIGNED DEFAULT NULL,
            `dep_id` BIGINT UNSIGNED DEFAULT NULL,
            `profession_id` BIGINT UNSIGNED DEFAULT NULL,
            `class_id` BIGINT UNSIGNED DEFAULT NULL,
            `class_num` VARCHAR(80) DEFAULT NULL,
            `semester` VARCHAR(80) DEFAULT NULL,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`student_id`),
            UNIQUE KEY `uk_student_uuid` (`student_uuid`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_dep_profession` (`dep_id`, `profession_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `teacher_list` (
            `teacher_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `teacher_uuid` CHAR(36) DEFAULT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `teacher_name` VARCHAR(80) NOT NULL,
            `teacher_num` VARCHAR(80) DEFAULT NULL,
            `dep_id` BIGINT UNSIGNED DEFAULT NULL,
            `profession_id` BIGINT UNSIGNED DEFAULT NULL,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`teacher_id`),
            UNIQUE KEY `uk_teacher_uuid` (`teacher_uuid`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_dep_id` (`dep_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `grade_teacher_guide` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `teacher_id` BIGINT UNSIGNED NOT NULL,
            `grade_id` BIGINT UNSIGNED NOT NULL,
            `dep_id` BIGINT UNSIGNED NOT NULL,
            `semester` VARCHAR(80) NOT NULL,
            `max_choose_number` INT DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_teacher_grade` (`teacher_id`, `grade_id`, `dep_id`, `semester`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `menu` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `parent_id` BIGINT UNSIGNED DEFAULT 0,
            `name` VARCHAR(80) NOT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `path` VARCHAR(180) DEFAULT NULL,
            `url` VARCHAR(255) DEFAULT NULL,
            `platform` ENUM('h5','pc','both') DEFAULT 'both',
            `type` ENUM('directory','menu','list','button') DEFAULT 'menu',
            `sort` INT DEFAULT 0,
            `icon` VARCHAR(80) DEFAULT NULL,
            `visible` ENUM('false','true') DEFAULT 'true',
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_menu_code` (`code`),
            KEY `idx_parent` (`parent_id`, `sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `role_menu` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `role_id` BIGINT UNSIGNED NOT NULL,
            `menu_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_role_menu` (`role_id`, `menu_id`),
            KEY `idx_menu_id` (`menu_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `config_group` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `parent_id` BIGINT UNSIGNED DEFAULT 0,
            `code` VARCHAR(80) NOT NULL,
            `name` VARCHAR(80) NOT NULL,
            `sort` INT DEFAULT 0,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_config_group_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `config_item` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `key` VARCHAR(120) NOT NULL,
            `value` JSON DEFAULT NULL,
            `college_id` BIGINT UNSIGNED DEFAULT 0,
            `user_id` BIGINT UNSIGNED DEFAULT 0,
            `description` VARCHAR(255) DEFAULT NULL,
            `sort` INT DEFAULT 0,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_config_item` (`group_id`, `key`, `college_id`, `user_id`),
            KEY `idx_scope` (`college_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `operation_guide` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `module_key` VARCHAR(60) NOT NULL,
            `title` VARCHAR(120) NOT NULL,
            `content` MEDIUMTEXT DEFAULT NULL,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `sort` INT DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_operation_guide_module` (`module_key`),
            KEY `idx_status_sort` (`status`, `sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `wechat_menu_config` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `role_id` BIGINT UNSIGNED NOT NULL,
            `menu_json` JSON DEFAULT NULL,
            `hash` CHAR(40) DEFAULT NULL,
            `last_synced_at` DATETIME DEFAULT NULL,
            `status` ENUM('draft','synced','failed') DEFAULT 'draft',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_role_id` (`role_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function schoolBusinessStatements(): array
{
    $statements = [
        simpleTable('user_device', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`jti` VARCHAR(120) DEFAULT NULL', '`device_name` VARCHAR(120) DEFAULT NULL', '`ip` VARCHAR(80) DEFAULT NULL', '`user_agent` VARCHAR(255) DEFAULT NULL', '`last_active_at` DATETIME DEFAULT NULL']),
        simpleTable('user_notify_setting', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`msg_type` VARCHAR(80) DEFAULT NULL', '`channel` VARCHAR(80) DEFAULT NULL', '`enabled` ENUM(\'false\',\'true\') DEFAULT \'true\'']),
        simpleTable('user_desktop_config', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`layout_json` JSON DEFAULT NULL']),
        simpleTable('theme_preset', ['`theme_json` JSON DEFAULT NULL']),
        simpleTable('api_key', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`api_key_hash` CHAR(64) DEFAULT NULL', '`enabled` ENUM(\'false\',\'true\') DEFAULT \'false\'']),
        simpleTable('base', ['`company_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`address` VARCHAR(255) DEFAULT NULL']),
        simpleTable('base_profession_direction', ['`base_id` BIGINT UNSIGNED NOT NULL', '`profession_id` BIGINT UNSIGNED NOT NULL', '`direction_id` BIGINT UNSIGNED NOT NULL', 'UNIQUE KEY `uk_base_profession_direction` (`base_id`, `profession_id`, `direction_id`)']),
        simpleTable('enterprise_mentor', ['`company_id` BIGINT UNSIGNED DEFAULT NULL', '`mentor_name` VARCHAR(80) DEFAULT NULL', '`mobile` VARCHAR(40) DEFAULT NULL']),
        simpleTable('arrangement', ['`plan_id` BIGINT UNSIGNED DEFAULT NULL', '`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`profession_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`task_no` VARCHAR(80) DEFAULT NULL', '`batch_no` VARCHAR(80) DEFAULT NULL', '`credit` DECIMAL(5,2) DEFAULT NULL', '`student_count` INT DEFAULT 0', '`start_date` DATE DEFAULT NULL', '`end_date` DATE DEFAULT NULL']),
        simpleTable('arrangement_recording', recordingColumns()),
        simpleTable('arrangement_change', ['`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`payload` JSON DEFAULT NULL', '`reason` TEXT DEFAULT NULL', '`from_status` VARCHAR(40) DEFAULT NULL', '`submitter_id` BIGINT UNSIGNED DEFAULT NULL', '`submitted_at` DATETIME DEFAULT NULL', '`reviewer_id` BIGINT UNSIGNED DEFAULT NULL', '`review_opinion` TEXT DEFAULT NULL', '`reviewed_at` DATETIME DEFAULT NULL', '`new_arrangement_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('arrangement_change_recording', recordingColumns()),
        simpleTable('internship_task_class', ['`arrangement_id` BIGINT UNSIGNED NOT NULL', '`grade_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`profession_id` BIGINT UNSIGNED DEFAULT NULL', '`class_id` BIGINT UNSIGNED NOT NULL', '`student_count_snapshot` INT DEFAULT 0', 'UNIQUE KEY `uk_task_class` (`arrangement_id`, `class_id`)']),
        simpleTable('application', ['`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('application_recording', recordingColumns()),
        simpleTable('student_join_teacher', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('join_recording', recordingColumns()),
        simpleTable('pair', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`type` ENUM(\'internship\',\'training\',\'lab\') DEFAULT \'internship\'', '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`entity_type` VARCHAR(40) DEFAULT NULL', '`entity_id` BIGINT UNSIGNED DEFAULT NULL', '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'active\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED', 'UNIQUE KEY `uk_pair_active` (`student_id`, `type`, `arrangement_id`, `active_flag`)']),
        simpleTable('sign_in', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`sign_time` DATETIME DEFAULT NULL', '`longitude` DECIMAL(10,6) DEFAULT NULL', '`latitude` DECIMAL(10,6) DEFAULT NULL'])),
        simpleTable('sign_in_recording', recordingColumns()),
        simpleTable('sign_in_qrcode', entityColumns(['`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`token` VARCHAR(120) DEFAULT NULL', '`expires_at` DATETIME DEFAULT NULL'])),
        simpleTable('journal', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`title` VARCHAR(180) DEFAULT NULL', '`content` TEXT DEFAULT NULL'])),
        simpleTable('journal_recording', recordingColumns()),
        simpleTable('report', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`template_id` BIGINT UNSIGNED DEFAULT NULL', '`submitted_at` DATETIME DEFAULT NULL'])),
        simpleTable('report_recording', recordingColumns()),
        simpleTable('report_template', ['`template_json` JSON DEFAULT NULL']),
        simpleTable('review_opinion', entityColumns(['`reviewer_id` BIGINT UNSIGNED DEFAULT NULL', '`opinion` TEXT DEFAULT NULL'])),
        simpleTable('recording_archive_2026', recordingColumns()),
        simpleTable('apply_report_delay', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`config_key` VARCHAR(120) DEFAULT NULL', '`requested_date` DATE DEFAULT NULL', '`reason` TEXT DEFAULT NULL'])),
        simpleTable('apply_report_delay_recording', recordingColumns()),
        simpleTable('score', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`score_value` DECIMAL(5,2) DEFAULT NULL'])),
        simpleTable('score_recording', recordingColumns()),
        simpleTable('internship_plan', ['`source_type` VARCHAR(40) DEFAULT \'edu_system\'', '`course_code` VARCHAR(120) DEFAULT NULL', '`course_name` VARCHAR(180) DEFAULT NULL', '`grade_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`profession_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL', '`credit` DECIMAL(5,2) DEFAULT NULL', '`student_count` INT DEFAULT 0', '`score_rule` VARCHAR(40) DEFAULT \'average\'', '`plan_content` JSON DEFAULT NULL', '`submitter_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('internship_plan_approval', ['`plan_id` BIGINT UNSIGNED DEFAULT NULL', '`reviewer_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('plan_recording', recordingColumns()),
        simpleTable('insurance', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`company_id` BIGINT UNSIGNED DEFAULT NULL', '`policy_no` VARCHAR(120) DEFAULT NULL']),
        simpleTable('insurance_recording', recordingColumns()),
        simpleTable('safety_letter_sign', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`signed_at` DATETIME DEFAULT NULL']),
        simpleTable('safety_letter_recording', recordingColumns()),
        simpleTable('syllabus_guide', ['`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`file_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('syllabus_guide_recording', recordingColumns()),
        simpleTable('implementation_sheet', ['`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`sheet_json` JSON DEFAULT NULL']),
        simpleTable('implementation_sheet_recording', recordingColumns()),
        simpleTable('teacher_work_report', ['`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL']),
        simpleTable('teacher_work_report_recording', recordingColumns()),
        simpleTable('inspection_record', ['`inspector_id` BIGINT UNSIGNED DEFAULT NULL', '`entity_type` VARCHAR(40) DEFAULT NULL', '`entity_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('inspection_recording', recordingColumns()),
        simpleTable('base_application', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`base_type` VARCHAR(40) DEFAULT NULL']),
        simpleTable('base_usage', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`usage_type` VARCHAR(80) DEFAULT NULL']),
        simpleTable('base_result', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`result_type` VARCHAR(80) DEFAULT NULL']),
        simpleTable('base_expense', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`amount` DECIMAL(12,2) DEFAULT NULL']),
        simpleTable('practice_plan', practiceCommonColumns(['`source_type` VARCHAR(40) DEFAULT \'manual\'', '`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_schedule', practiceCommonColumns(['`room_id` BIGINT UNSIGNED DEFAULT NULL', '`base_id` BIGINT UNSIGNED DEFAULT NULL', '`place_type` VARCHAR(40) DEFAULT \'inside\'', '`schedule_date` DATE DEFAULT NULL', '`start_time` VARCHAR(20) DEFAULT NULL', '`end_time` VARCHAR(20) DEFAULT NULL', '`location` VARCHAR(255) DEFAULT NULL', '`student_count` INT DEFAULT 0', '`roster_printed_at` DATETIME DEFAULT NULL'])),
        simpleTable('practice_syllabus', practiceCommonColumns(['`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_lesson_plan', practiceCommonColumns(['`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_grade_rule', practiceCommonColumns(['`ratio_json` JSON DEFAULT NULL'])),
        simpleTable('practice_score', practiceCommonColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`rule_id` BIGINT UNSIGNED DEFAULT NULL', '`score_items` JSON DEFAULT NULL', '`score_value` DECIMAL(5,2) DEFAULT NULL'])),
        simpleTable('practice_reflection', practiceCommonColumns(['`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_room', ['`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`room_type` VARCHAR(80) DEFAULT NULL', '`capacity` INT DEFAULT 0', '`location` VARCHAR(255) DEFAULT NULL', '`manager_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('practice_recording', array_merge(['`parent_id` BIGINT UNSIGNED DEFAULT NULL', '`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'', '`action` VARCHAR(40) DEFAULT NULL', '`content` TEXT DEFAULT NULL'], recordingColumns())),
    ];

    foreach (['training_project', 'training_project_class', 'training_room', 'training_booking', 'training_material', 'training_report', 'training_score'] as $table) {
        $statements[] = simpleTable($table, entityColumns());
    }

    foreach (['lab_room', 'lab_course', 'lab_course_class', 'lab_project', 'lab_project_member', 'lab_booking', 'lab_material', 'lab_report', 'lab_score'] as $table) {
        $statements[] = simpleTable($table, entityColumns());
    }

    foreach (fileTableStatements() as $statement) {
        $statements[] = $statement;
    }

    foreach (messageTableStatements() as $statement) {
        $statements[] = $statement;
    }

    foreach (['operation_log_template', 'stat_cache', 'export_task', 'doc_category', 'doc_article', 'doc_article_history', 'template_category', 'template'] as $table) {
        $statements[] = simpleTable($table, ['`payload` JSON DEFAULT NULL']);
    }

    $statements[] = simpleTable('operation_log_202606', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`action` VARCHAR(120) DEFAULT NULL', '`ip` VARCHAR(80) DEFAULT NULL', '`payload` JSON DEFAULT NULL']);

    return $statements;
}

function fileTableStatements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `file_blob` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `md5` CHAR(32) NOT NULL,
            `sha1` CHAR(40) DEFAULT NULL,
            `path` VARCHAR(500) NOT NULL,
            `url` VARCHAR(500) NOT NULL,
            `ext` VARCHAR(20) NOT NULL,
            `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `mime_type` VARCHAR(120) DEFAULT NULL,
            `disk` VARCHAR(40) DEFAULT 'public',
            `block` VARCHAR(40) DEFAULT 'b1',
            `category` VARCHAR(80) DEFAULT NULL,
            `ref_count` INT UNSIGNED DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_md5` (`md5`),
            KEY `idx_category` (`category`),
            KEY `idx_ref_count` (`ref_count`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `file` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `blob_id` BIGINT UNSIGNED NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `download_name` VARCHAR(255) DEFAULT NULL,
            `url` VARCHAR(500) NOT NULL,
            `is_temporary` TINYINT(1) DEFAULT 0,
            `uploader_id` BIGINT UNSIGNED DEFAULT NULL,
            `client` VARCHAR(40) DEFAULT NULL,
            `client_ip` VARCHAR(80) DEFAULT NULL,
            `user_agent` VARCHAR(255) DEFAULT NULL,
            `category` VARCHAR(80) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_blob_id` (`blob_id`),
            KEY `idx_uploader_id` (`uploader_id`),
            KEY `idx_category` (`category`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `file_relation` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_id` BIGINT UNSIGNED NOT NULL,
            `entity_type` VARCHAR(80) NOT NULL,
            `entity_id` BIGINT UNSIGNED NOT NULL,
            `tag` VARCHAR(80) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_file_id` (`file_id`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_tag` (`tag`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function messageTableStatements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `message` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `title` VARCHAR(180) DEFAULT NULL,
            `content` TEXT DEFAULT NULL,
            `type` VARCHAR(40) DEFAULT 'system',
            `sender_id` BIGINT UNSIGNED DEFAULT 0,
            `sender_name` VARCHAR(80) DEFAULT NULL,
            `level` VARCHAR(40) DEFAULT 'normal',
            `entity_type` VARCHAR(80) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `link_url` VARCHAR(500) DEFAULT NULL,
            `metadata` JSON DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_status` (`status`),
            KEY `idx_type_created` (`type`, `created_at`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `message_target` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `message_id` BIGINT UNSIGNED DEFAULT NULL,
            `account_id` BIGINT UNSIGNED DEFAULT NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `read_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_status` (`status`),
            KEY `idx_account_read` (`account_id`, `is_read`, `created_at`),
            KEY `idx_message_id` (`message_id`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `message_template` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `title_tpl` VARCHAR(255) DEFAULT NULL,
            `content_tpl` TEXT DEFAULT NULL,
            `channels` JSON DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `message_channel_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'pending',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `message_id` BIGINT UNSIGNED DEFAULT NULL,
            `account_id` BIGINT UNSIGNED DEFAULT NULL,
            `channel` VARCHAR(40) DEFAULT 'internal',
            `error_message` TEXT DEFAULT NULL,
            `sent_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_status` (`status`),
            KEY `idx_message_account` (`message_id`, `account_id`),
            KEY `idx_channel_status` (`channel`, `status`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function ensureFileSchema(PDO $pdo): void
{
    $fileBlobColumns = [
        'md5' => "ALTER TABLE `file_blob` ADD COLUMN `md5` CHAR(32) DEFAULT NULL AFTER `id`",
        'sha1' => "ALTER TABLE `file_blob` ADD COLUMN `sha1` CHAR(40) DEFAULT NULL AFTER `md5`",
        'path' => "ALTER TABLE `file_blob` ADD COLUMN `path` VARCHAR(500) DEFAULT NULL AFTER `sha1`",
        'url' => "ALTER TABLE `file_blob` ADD COLUMN `url` VARCHAR(500) DEFAULT NULL AFTER `path`",
        'ext' => "ALTER TABLE `file_blob` ADD COLUMN `ext` VARCHAR(20) DEFAULT NULL AFTER `url`",
        'size' => "ALTER TABLE `file_blob` ADD COLUMN `size` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ext`",
        'mime_type' => "ALTER TABLE `file_blob` ADD COLUMN `mime_type` VARCHAR(120) DEFAULT NULL AFTER `size`",
        'disk' => "ALTER TABLE `file_blob` ADD COLUMN `disk` VARCHAR(40) DEFAULT 'public' AFTER `mime_type`",
        'block' => "ALTER TABLE `file_blob` ADD COLUMN `block` VARCHAR(40) DEFAULT 'b1' AFTER `disk`",
        'category' => "ALTER TABLE `file_blob` ADD COLUMN `category` VARCHAR(80) DEFAULT NULL AFTER `block`",
        'ref_count' => "ALTER TABLE `file_blob` ADD COLUMN `ref_count` INT UNSIGNED DEFAULT 0 AFTER `category`",
    ];

    foreach ($fileBlobColumns as $column => $ddl) {
        ensureColumn($pdo, 'file_blob', $column, $ddl);
    }

    ensureIndex($pdo, 'file_blob', 'uk_md5', "ALTER TABLE `file_blob` ADD UNIQUE KEY `uk_md5` (`md5`)");
    ensureIndex($pdo, 'file_blob', 'idx_category', "ALTER TABLE `file_blob` ADD KEY `idx_category` (`category`)");
    ensureIndex($pdo, 'file_blob', 'idx_ref_count', "ALTER TABLE `file_blob` ADD KEY `idx_ref_count` (`ref_count`)");
    ensureIndex($pdo, 'file_blob', 'idx_deleted_at', "ALTER TABLE `file_blob` ADD KEY `idx_deleted_at` (`deleted_at`)");

    $fileColumns = [
        'blob_id' => "ALTER TABLE `file` ADD COLUMN `blob_id` BIGINT UNSIGNED DEFAULT NULL AFTER `uuid`",
        'download_name' => "ALTER TABLE `file` ADD COLUMN `download_name` VARCHAR(255) DEFAULT NULL AFTER `name`",
        'url' => "ALTER TABLE `file` ADD COLUMN `url` VARCHAR(500) DEFAULT NULL AFTER `download_name`",
        'is_temporary' => "ALTER TABLE `file` ADD COLUMN `is_temporary` TINYINT(1) DEFAULT 0 AFTER `url`",
        'uploader_id' => "ALTER TABLE `file` ADD COLUMN `uploader_id` BIGINT UNSIGNED DEFAULT NULL AFTER `is_temporary`",
        'client' => "ALTER TABLE `file` ADD COLUMN `client` VARCHAR(40) DEFAULT NULL AFTER `uploader_id`",
        'client_ip' => "ALTER TABLE `file` ADD COLUMN `client_ip` VARCHAR(80) DEFAULT NULL AFTER `client`",
        'user_agent' => "ALTER TABLE `file` ADD COLUMN `user_agent` VARCHAR(255) DEFAULT NULL AFTER `client_ip`",
        'category' => "ALTER TABLE `file` ADD COLUMN `category` VARCHAR(80) DEFAULT NULL AFTER `user_agent`",
    ];

    foreach ($fileColumns as $column => $ddl) {
        ensureColumn($pdo, 'file', $column, $ddl);
    }

    ensureIndex($pdo, 'file', 'idx_blob_id', "ALTER TABLE `file` ADD KEY `idx_blob_id` (`blob_id`)");
    ensureIndex($pdo, 'file', 'idx_uploader_id', "ALTER TABLE `file` ADD KEY `idx_uploader_id` (`uploader_id`)");
    ensureIndex($pdo, 'file', 'idx_category', "ALTER TABLE `file` ADD KEY `idx_category` (`category`)");
    ensureIndex($pdo, 'file', 'idx_deleted_at', "ALTER TABLE `file` ADD KEY `idx_deleted_at` (`deleted_at`)");

    $relationColumns = [
        'file_id' => "ALTER TABLE `file_relation` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `id`",
        'entity_type' => "ALTER TABLE `file_relation` ADD COLUMN `entity_type` VARCHAR(80) DEFAULT NULL AFTER `file_id`",
        'entity_id' => "ALTER TABLE `file_relation` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
        'tag' => "ALTER TABLE `file_relation` ADD COLUMN `tag` VARCHAR(80) DEFAULT NULL AFTER `entity_id`",
    ];

    foreach ($relationColumns as $column => $ddl) {
        ensureColumn($pdo, 'file_relation', $column, $ddl);
    }

    ensureIndex($pdo, 'file_relation', 'idx_file_id', "ALTER TABLE `file_relation` ADD KEY `idx_file_id` (`file_id`)");
    ensureIndex($pdo, 'file_relation', 'idx_entity', "ALTER TABLE `file_relation` ADD KEY `idx_entity` (`entity_type`, `entity_id`)");
    ensureIndex($pdo, 'file_relation', 'idx_tag', "ALTER TABLE `file_relation` ADD KEY `idx_tag` (`tag`)");
    ensureIndex($pdo, 'file_relation', 'idx_deleted_at', "ALTER TABLE `file_relation` ADD KEY `idx_deleted_at` (`deleted_at`)");
}

function ensureInternshipSchema(PDO $pdo): void
{
    $schemas = [
        'base' => [
            'company_id' => "ALTER TABLE `base` ADD COLUMN `company_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'dep_id' => "ALTER TABLE `base` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `company_id`",
            'address' => "ALTER TABLE `base` ADD COLUMN `address` VARCHAR(255) DEFAULT NULL AFTER `dep_id`",
            'capacity' => "ALTER TABLE `base` ADD COLUMN `capacity` INT UNSIGNED DEFAULT 0 AFTER `address`",
            'used_count' => "ALTER TABLE `base` ADD COLUMN `used_count` INT UNSIGNED DEFAULT 0 AFTER `capacity`",
        ],
        'enterprise_mentor' => [
            'company_id' => "ALTER TABLE `enterprise_mentor` ADD COLUMN `company_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'name' => "ALTER TABLE `enterprise_mentor` ADD COLUMN `name` VARCHAR(80) DEFAULT NULL AFTER `company_id`",
            'phone' => "ALTER TABLE `enterprise_mentor` ADD COLUMN `phone` VARCHAR(40) DEFAULT NULL AFTER `name`",
            'position' => "ALTER TABLE `enterprise_mentor` ADD COLUMN `position` VARCHAR(120) DEFAULT NULL AFTER `phone`",
        ],
        'arrangement' => [
            'plan_id' => "ALTER TABLE `arrangement` ADD COLUMN `plan_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'base_id' => "ALTER TABLE `arrangement` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_id`",
            'dep_id' => "ALTER TABLE `arrangement` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'profession_id' => "ALTER TABLE `arrangement` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'semester' => "ALTER TABLE `arrangement` ADD COLUMN `semester` VARCHAR(80) DEFAULT NULL AFTER `profession_id`",
            'teacher_id' => "ALTER TABLE `arrangement` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `semester`",
            'task_no' => "ALTER TABLE `arrangement` ADD COLUMN `task_no` VARCHAR(80) DEFAULT NULL AFTER `teacher_id`",
            'batch_no' => "ALTER TABLE `arrangement` ADD COLUMN `batch_no` VARCHAR(80) DEFAULT NULL AFTER `task_no`",
            'credit' => "ALTER TABLE `arrangement` ADD COLUMN `credit` DECIMAL(5,2) DEFAULT NULL AFTER `batch_no`",
            'student_count' => "ALTER TABLE `arrangement` ADD COLUMN `student_count` INT DEFAULT 0 AFTER `credit`",
            'type' => "ALTER TABLE `arrangement` ADD COLUMN `type` VARCHAR(40) DEFAULT 'major_external' AFTER `student_count`",
            'organize_mode' => "ALTER TABLE `arrangement` ADD COLUMN `organize_mode` VARCHAR(40) DEFAULT 'centralized' AFTER `type`",
            'title' => "ALTER TABLE `arrangement` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `organize_mode`",
            'start_date' => "ALTER TABLE `arrangement` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `title`",
            'end_date' => "ALTER TABLE `arrangement` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`",
            'location' => "ALTER TABLE `arrangement` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `end_date`",
            'description' => "ALTER TABLE `arrangement` ADD COLUMN `description` TEXT DEFAULT NULL AFTER `location`",
            'created_by' => "ALTER TABLE `arrangement` ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `description`",
        ],
        'arrangement_change' => [
            'arrangement_id' => "ALTER TABLE `arrangement_change` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'payload' => "ALTER TABLE `arrangement_change` ADD COLUMN `payload` JSON DEFAULT NULL AFTER `arrangement_id`",
            'reason' => "ALTER TABLE `arrangement_change` ADD COLUMN `reason` TEXT DEFAULT NULL AFTER `payload`",
            'from_status' => "ALTER TABLE `arrangement_change` ADD COLUMN `from_status` VARCHAR(40) DEFAULT NULL AFTER `reason`",
            'submitter_id' => "ALTER TABLE `arrangement_change` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `from_status`",
            'submitted_at' => "ALTER TABLE `arrangement_change` ADD COLUMN `submitted_at` DATETIME DEFAULT NULL AFTER `submitter_id`",
            'reviewer_id' => "ALTER TABLE `arrangement_change` ADD COLUMN `reviewer_id` BIGINT UNSIGNED DEFAULT NULL AFTER `submitted_at`",
            'review_opinion' => "ALTER TABLE `arrangement_change` ADD COLUMN `review_opinion` TEXT DEFAULT NULL AFTER `reviewer_id`",
            'reviewed_at' => "ALTER TABLE `arrangement_change` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL AFTER `review_opinion`",
            'new_arrangement_id' => "ALTER TABLE `arrangement_change` ADD COLUMN `new_arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `reviewed_at`",
        ],
        'application' => [
            'student_id' => "ALTER TABLE `application` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'arrangement_id' => "ALTER TABLE `application` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'type' => "ALTER TABLE `application` ADD COLUMN `type` VARCHAR(40) DEFAULT 'centralized' AFTER `arrangement_id`",
            'teacher_status' => "ALTER TABLE `application` ADD COLUMN `teacher_status` VARCHAR(40) DEFAULT 'pending' AFTER `type`",
            'admin_status' => "ALTER TABLE `application` ADD COLUMN `admin_status` VARCHAR(40) DEFAULT 'pending' AFTER `teacher_status`",
            'materials' => "ALTER TABLE `application` ADD COLUMN `materials` JSON DEFAULT NULL AFTER `admin_status`",
            'remark' => "ALTER TABLE `application` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `materials`",
        ],
        'student_join_teacher' => [
            'student_id' => "ALTER TABLE `student_join_teacher` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'teacher_id' => "ALTER TABLE `student_join_teacher` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'application_id' => "ALTER TABLE `student_join_teacher` ADD COLUMN `application_id` BIGINT UNSIGNED DEFAULT NULL AFTER `teacher_id`",
            'arrangement_id' => "ALTER TABLE `student_join_teacher` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `application_id`",
            'application_type' => "ALTER TABLE `student_join_teacher` ADD COLUMN `application_type` VARCHAR(40) DEFAULT 'internship' AFTER `arrangement_id`",
            'application_status' => "ALTER TABLE `student_join_teacher` ADD COLUMN `application_status` VARCHAR(40) DEFAULT 'applying' AFTER `application_type`",
            'is_anonymous' => "ALTER TABLE `student_join_teacher` ADD COLUMN `is_anonymous` ENUM('false','true') DEFAULT 'false' AFTER `application_status`",
            'student_name' => "ALTER TABLE `student_join_teacher` ADD COLUMN `student_name` VARCHAR(80) DEFAULT NULL AFTER `is_anonymous`",
            'student_num' => "ALTER TABLE `student_join_teacher` ADD COLUMN `student_num` VARCHAR(80) DEFAULT NULL AFTER `student_name`",
            'teacher_name' => "ALTER TABLE `student_join_teacher` ADD COLUMN `teacher_name` VARCHAR(80) DEFAULT NULL AFTER `student_num`",
        ],
        'pair' => [
            'student_id' => "ALTER TABLE `pair` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'teacher_id' => "ALTER TABLE `pair` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'dep_id' => "ALTER TABLE `pair` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `teacher_id`",
            'second_teacher_id' => "ALTER TABLE `pair` ADD COLUMN `second_teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'enterprise_mentor_id' => "ALTER TABLE `pair` ADD COLUMN `enterprise_mentor_id` BIGINT UNSIGNED DEFAULT NULL AFTER `second_teacher_id`",
            'arrangement_id' => "ALTER TABLE `pair` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `enterprise_mentor_id`",
            'type' => "ALTER TABLE `pair` ADD COLUMN `type` ENUM('internship','training','lab') DEFAULT 'internship' AFTER `arrangement_id`",
            'entity_type' => "ALTER TABLE `pair` ADD COLUMN `entity_type` VARCHAR(40) DEFAULT NULL AFTER `type`",
            'entity_id' => "ALTER TABLE `pair` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
            'application_id' => "ALTER TABLE `pair` ADD COLUMN `application_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_id`",
            'remove_reason' => "ALTER TABLE `pair` ADD COLUMN `remove_reason` VARCHAR(255) DEFAULT NULL AFTER `application_id`",
            'is_anonymous' => "ALTER TABLE `pair` ADD COLUMN `is_anonymous` ENUM('false','true') DEFAULT 'false' AFTER `remove_reason`",
            'active_flag' => "ALTER TABLE `pair` ADD COLUMN `active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = 'active' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED AFTER `is_anonymous`",
        ],
        'sign_in' => [
            'student_id' => "ALTER TABLE `sign_in` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'entity_type' => "ALTER TABLE `sign_in` ADD COLUMN `entity_type` VARCHAR(40) DEFAULT NULL AFTER `student_id`",
            'entity_id' => "ALTER TABLE `sign_in` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
            'date' => "ALTER TABLE `sign_in` ADD COLUMN `date` DATE DEFAULT NULL AFTER `entity_id`",
            'sign_time' => "ALTER TABLE `sign_in` ADD COLUMN `sign_time` DATETIME DEFAULT NULL AFTER `date`",
            'sign_type' => "ALTER TABLE `sign_in` ADD COLUMN `sign_type` VARCHAR(40) DEFAULT 'gps' AFTER `sign_time`",
            'location' => "ALTER TABLE `sign_in` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `sign_type`",
            'longitude' => "ALTER TABLE `sign_in` ADD COLUMN `longitude` DECIMAL(10,6) DEFAULT NULL AFTER `location`",
            'latitude' => "ALTER TABLE `sign_in` ADD COLUMN `latitude` DECIMAL(10,6) DEFAULT NULL AFTER `longitude`",
            'remark' => "ALTER TABLE `sign_in` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `latitude`",
        ],
        'sign_in_qrcode' => [
            'base_id' => "ALTER TABLE `sign_in_qrcode` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'room_id' => "ALTER TABLE `sign_in_qrcode` ADD COLUMN `room_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'entity_type' => "ALTER TABLE `sign_in_qrcode` ADD COLUMN `entity_type` VARCHAR(40) DEFAULT NULL AFTER `room_id`",
            'entity_id' => "ALTER TABLE `sign_in_qrcode` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
            'token' => "ALTER TABLE `sign_in_qrcode` ADD COLUMN `token` VARCHAR(120) DEFAULT NULL AFTER `entity_id`",
            'expire_at' => "ALTER TABLE `sign_in_qrcode` ADD COLUMN `expire_at` DATETIME DEFAULT NULL AFTER `token`",
            'created_by' => "ALTER TABLE `sign_in_qrcode` ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `expire_at`",
        ],
        'journal' => [
            'student_id' => "ALTER TABLE `journal` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'entity_type' => "ALTER TABLE `journal` ADD COLUMN `entity_type` VARCHAR(40) DEFAULT NULL AFTER `student_id`",
            'entity_id' => "ALTER TABLE `journal` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
            'title' => "ALTER TABLE `journal` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `entity_id`",
            'content' => "ALTER TABLE `journal` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'date' => "ALTER TABLE `journal` ADD COLUMN `date` DATE DEFAULT NULL AFTER `content`",
            'teacher_id' => "ALTER TABLE `journal` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `date`",
        ],
        'report' => [
            'student_id' => "ALTER TABLE `report` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'arrangement_id' => "ALTER TABLE `report` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'template_id' => "ALTER TABLE `report` ADD COLUMN `template_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'title' => "ALTER TABLE `report` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `template_id`",
            'content' => "ALTER TABLE `report` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'teacher_id' => "ALTER TABLE `report` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `content`",
            'submitted_at' => "ALTER TABLE `report` ADD COLUMN `submitted_at` DATETIME DEFAULT NULL AFTER `teacher_id`",
            'reviewed_at' => "ALTER TABLE `report` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL AFTER `submitted_at`",
        ],
        'report_template' => [
            'content' => "ALTER TABLE `report_template` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `code`",
            'version' => "ALTER TABLE `report_template` ADD COLUMN `version` VARCHAR(40) DEFAULT NULL AFTER `content`",
            'online_enabled' => "ALTER TABLE `report_template` ADD COLUMN `online_enabled` ENUM('false','true') DEFAULT 'false' AFTER `version`",
            'form_schema' => "ALTER TABLE `report_template` ADD COLUMN `form_schema` JSON DEFAULT NULL AFTER `online_enabled`",
        ],
        'score' => [
            'student_id' => "ALTER TABLE `score` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'arrangement_id' => "ALTER TABLE `score` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'sign_in_score' => "ALTER TABLE `score` ADD COLUMN `sign_in_score` DECIMAL(5,2) DEFAULT NULL AFTER `arrangement_id`",
            'journal_score' => "ALTER TABLE `score` ADD COLUMN `journal_score` DECIMAL(5,2) DEFAULT NULL AFTER `sign_in_score`",
            'report_score' => "ALTER TABLE `score` ADD COLUMN `report_score` DECIMAL(5,2) DEFAULT NULL AFTER `journal_score`",
            'sign_in_weight' => "ALTER TABLE `score` ADD COLUMN `sign_in_weight` DECIMAL(5,2) DEFAULT 0 AFTER `report_score`",
            'journal_weight' => "ALTER TABLE `score` ADD COLUMN `journal_weight` DECIMAL(5,2) DEFAULT 0 AFTER `sign_in_weight`",
            'report_weight' => "ALTER TABLE `score` ADD COLUMN `report_weight` DECIMAL(5,2) DEFAULT 0 AFTER `journal_weight`",
            'enterprise_score' => "ALTER TABLE `score` ADD COLUMN `enterprise_score` DECIMAL(5,2) DEFAULT NULL AFTER `report_weight`",
            'enterprise_comment' => "ALTER TABLE `score` ADD COLUMN `enterprise_comment` TEXT DEFAULT NULL AFTER `enterprise_score`",
            'final_score' => "ALTER TABLE `score` ADD COLUMN `final_score` DECIMAL(5,2) DEFAULT NULL AFTER `enterprise_comment`",
            'teacher_id' => "ALTER TABLE `score` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `final_score`",
            'comment' => "ALTER TABLE `score` ADD COLUMN `comment` TEXT DEFAULT NULL AFTER `teacher_id`",
        ],
        'review_opinion' => [
            'recording_id' => "ALTER TABLE `review_opinion` ADD COLUMN `recording_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_id`",
            'teacher_id' => "ALTER TABLE `review_opinion` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `recording_id`",
            'score' => "ALTER TABLE `review_opinion` ADD COLUMN `score` DECIMAL(5,2) DEFAULT NULL AFTER `opinion`",
        ],
        'apply_report_delay' => [
            'student_id' => "ALTER TABLE `apply_report_delay` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'config_key' => "ALTER TABLE `apply_report_delay` ADD COLUMN `config_key` VARCHAR(120) DEFAULT NULL AFTER `student_id`",
            'entity_type' => "ALTER TABLE `apply_report_delay` ADD COLUMN `entity_type` VARCHAR(40) DEFAULT NULL AFTER `config_key`",
            'entity_id' => "ALTER TABLE `apply_report_delay` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
            'requested_date' => "ALTER TABLE `apply_report_delay` ADD COLUMN `requested_date` DATE DEFAULT NULL AFTER `entity_id`",
            'reason' => "ALTER TABLE `apply_report_delay` ADD COLUMN `reason` TEXT DEFAULT NULL AFTER `requested_date`",
        ],
        'internship_plan' => [
            'source_type' => "ALTER TABLE `internship_plan` ADD COLUMN `source_type` VARCHAR(40) DEFAULT 'edu_system' AFTER `code`",
            'course_code' => "ALTER TABLE `internship_plan` ADD COLUMN `course_code` VARCHAR(120) DEFAULT NULL AFTER `source_type`",
            'course_name' => "ALTER TABLE `internship_plan` ADD COLUMN `course_name` VARCHAR(180) DEFAULT NULL AFTER `course_code`",
            'grade_id' => "ALTER TABLE `internship_plan` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `course_name`",
            'dep_id' => "ALTER TABLE `internship_plan` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `grade_id`",
            'profession_id' => "ALTER TABLE `internship_plan` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'semester' => "ALTER TABLE `internship_plan` ADD COLUMN `semester` VARCHAR(80) DEFAULT NULL AFTER `profession_id`",
            'credit' => "ALTER TABLE `internship_plan` ADD COLUMN `credit` DECIMAL(5,2) DEFAULT NULL AFTER `semester`",
            'student_count' => "ALTER TABLE `internship_plan` ADD COLUMN `student_count` INT DEFAULT 0 AFTER `credit`",
            'score_rule' => "ALTER TABLE `internship_plan` ADD COLUMN `score_rule` VARCHAR(40) DEFAULT 'average' AFTER `student_count`",
            'plan_content' => "ALTER TABLE `internship_plan` ADD COLUMN `plan_content` JSON DEFAULT NULL AFTER `score_rule`",
            'submitter_id' => "ALTER TABLE `internship_plan` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_content`",
        ],
        'internship_task_class' => [
            'arrangement_id' => "ALTER TABLE `internship_task_class` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'grade_id' => "ALTER TABLE `internship_task_class` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'dep_id' => "ALTER TABLE `internship_task_class` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `grade_id`",
            'profession_id' => "ALTER TABLE `internship_task_class` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'class_id' => "ALTER TABLE `internship_task_class` ADD COLUMN `class_id` BIGINT UNSIGNED DEFAULT NULL AFTER `profession_id`",
            'student_count_snapshot' => "ALTER TABLE `internship_task_class` ADD COLUMN `student_count_snapshot` INT DEFAULT 0 AFTER `class_id`",
        ],
        'internship_plan_approval' => [
            'plan_id' => "ALTER TABLE `internship_plan_approval` ADD COLUMN `plan_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'approver_id' => "ALTER TABLE `internship_plan_approval` ADD COLUMN `approver_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_id`",
            'approval_level' => "ALTER TABLE `internship_plan_approval` ADD COLUMN `approval_level` INT DEFAULT 1 AFTER `approver_id`",
            'level_name' => "ALTER TABLE `internship_plan_approval` ADD COLUMN `level_name` VARCHAR(80) DEFAULT NULL AFTER `approval_level`",
            'opinion' => "ALTER TABLE `internship_plan_approval` ADD COLUMN `opinion` TEXT DEFAULT NULL AFTER `level_name`",
        ],
        'insurance' => [
            'arrangement_id' => "ALTER TABLE `insurance` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'student_id' => "ALTER TABLE `insurance` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'insurance_company' => "ALTER TABLE `insurance` ADD COLUMN `insurance_company` VARCHAR(120) DEFAULT NULL AFTER `student_id`",
            'policy_number' => "ALTER TABLE `insurance` ADD COLUMN `policy_number` VARCHAR(120) DEFAULT NULL AFTER `insurance_company`",
            'insured_amount' => "ALTER TABLE `insurance` ADD COLUMN `insured_amount` DECIMAL(12,2) DEFAULT NULL AFTER `policy_number`",
            'start_date' => "ALTER TABLE `insurance` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `insured_amount`",
            'end_date' => "ALTER TABLE `insurance` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`",
            'attachment_id' => "ALTER TABLE `insurance` ADD COLUMN `attachment_id` BIGINT UNSIGNED DEFAULT NULL AFTER `end_date`",
        ],
        'safety_letter_sign' => [
            'arrangement_id' => "ALTER TABLE `safety_letter_sign` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'student_id' => "ALTER TABLE `safety_letter_sign` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'template_id' => "ALTER TABLE `safety_letter_sign` ADD COLUMN `template_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'signed_at' => "ALTER TABLE `safety_letter_sign` ADD COLUMN `signed_at` DATETIME DEFAULT NULL AFTER `template_id`",
            'signature_file_id' => "ALTER TABLE `safety_letter_sign` ADD COLUMN `signature_file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `signed_at`",
        ],
        'syllabus_guide' => [
            'arrangement_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'dep_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'profession_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'title' => "ALTER TABLE `syllabus_guide` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `profession_id`",
            'content' => "ALTER TABLE `syllabus_guide` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'file_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `content`",
            'created_by' => "ALTER TABLE `syllabus_guide` ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `file_id`",
        ],
        'implementation_sheet' => [
            'arrangement_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'teacher_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'plan_ref_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `plan_ref_id` BIGINT UNSIGNED DEFAULT NULL AFTER `teacher_id`",
            'syllabus_ref_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `syllabus_ref_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_ref_id`",
            'signed_count' => "ALTER TABLE `implementation_sheet` ADD COLUMN `signed_count` INT DEFAULT 0 AFTER `syllabus_ref_id`",
            'unsigned_count' => "ALTER TABLE `implementation_sheet` ADD COLUMN `unsigned_count` INT DEFAULT 0 AFTER `signed_count`",
            'insurance_verified' => "ALTER TABLE `implementation_sheet` ADD COLUMN `insurance_verified` ENUM('false','true') DEFAULT 'false' AFTER `unsigned_count`",
            'fee_detail' => "ALTER TABLE `implementation_sheet` ADD COLUMN `fee_detail` JSON DEFAULT NULL AFTER `insurance_verified`",
            'confirmed_at' => "ALTER TABLE `implementation_sheet` ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `fee_detail`",
        ],
        'teacher_work_report' => [
            'arrangement_id' => "ALTER TABLE `teacher_work_report` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'teacher_id' => "ALTER TABLE `teacher_work_report` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'guidance_count' => "ALTER TABLE `teacher_work_report` ADD COLUMN `guidance_count` INT DEFAULT 0 AFTER `teacher_id`",
            'summary' => "ALTER TABLE `teacher_work_report` ADD COLUMN `summary` TEXT DEFAULT NULL AFTER `guidance_count`",
            'problems' => "ALTER TABLE `teacher_work_report` ADD COLUMN `problems` TEXT DEFAULT NULL AFTER `summary`",
            'suggestions' => "ALTER TABLE `teacher_work_report` ADD COLUMN `suggestions` TEXT DEFAULT NULL AFTER `problems`",
            'attachment_id' => "ALTER TABLE `teacher_work_report` ADD COLUMN `attachment_id` BIGINT UNSIGNED DEFAULT NULL AFTER `suggestions`",
        ],
        'inspection_record' => [
            'semester' => "ALTER TABLE `inspection_record` ADD COLUMN `semester` VARCHAR(80) DEFAULT NULL AFTER `code`",
            'arrangement_id' => "ALTER TABLE `inspection_record` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `semester`",
            'student_id' => "ALTER TABLE `inspection_record` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'inspector_id' => "ALTER TABLE `inspection_record` ADD COLUMN `inspector_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'items' => "ALTER TABLE `inspection_record` ADD COLUMN `items` JSON DEFAULT NULL AFTER `inspector_id`",
            'result' => "ALTER TABLE `inspection_record` ADD COLUMN `result` VARCHAR(40) DEFAULT NULL AFTER `items`",
            'remark' => "ALTER TABLE `inspection_record` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `result`",
        ],
    ];

    foreach ($schemas as $table => $columns) {
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
    }

    foreach (['application_recording', 'arrangement_recording', 'arrangement_change_recording', 'sign_in_recording', 'journal_recording', 'report_recording', 'join_recording', 'apply_report_delay_recording', 'score_recording', 'plan_recording', 'insurance_recording', 'safety_letter_recording', 'syllabus_guide_recording', 'implementation_sheet_recording', 'teacher_work_report_recording', 'inspection_recording'] as $table) {
        ensureColumn($pdo, $table, 'parent_id', "ALTER TABLE `{$table}` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`");
        ensureColumn($pdo, $table, 'action', "ALTER TABLE `{$table}` ADD COLUMN `action` VARCHAR(40) DEFAULT NULL AFTER `parent_id`");
        ensureColumn($pdo, $table, 'operator_id', "ALTER TABLE `{$table}` ADD COLUMN `operator_id` BIGINT UNSIGNED DEFAULT NULL AFTER `action`");
        ensureColumn($pdo, $table, 'content', "ALTER TABLE `{$table}` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `operator_id`");
        ensureIndex($pdo, $table, 'idx_parent', "ALTER TABLE `{$table}` ADD KEY `idx_parent` (`parent_id`)");
        ensureIndex($pdo, $table, "idx_{$table}_timeline", "ALTER TABLE `{$table}` ADD KEY `idx_{$table}_timeline` (`parent_id`, `action`, `created_at`)");
    }

    ensureIndex($pdo, 'base_profession_direction', 'uk_base_profession_direction', "ALTER TABLE `base_profession_direction` ADD UNIQUE KEY `uk_base_profession_direction` (`base_id`, `profession_id`, `direction_id`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_scope', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_scope` (`dep_id`, `profession_id`, `status`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_plan', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_plan` (`plan_id`, `teacher_id`, `status`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_plan_task', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_plan_task` (`plan_id`, `task_no`, `status`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_teacher_time', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_teacher_time` (`teacher_id`, `start_date`, `end_date`)");
    ensureIndex($pdo, 'arrangement_change', 'idx_arrangement_change_task', "ALTER TABLE `arrangement_change` ADD KEY `idx_arrangement_change_task` (`arrangement_id`, `status`)");
    ensureIndex($pdo, 'arrangement_change', 'idx_arrangement_change_submitter', "ALTER TABLE `arrangement_change` ADD KEY `idx_arrangement_change_submitter` (`submitter_id`, `status`)");
    ensureIndex($pdo, 'internship_plan', 'idx_internship_plan_scope', "ALTER TABLE `internship_plan` ADD KEY `idx_internship_plan_scope` (`grade_id`, `dep_id`, `profession_id`, `status`)");
    ensureIndex($pdo, 'internship_plan_approval', 'idx_plan_approval_flow', "ALTER TABLE `internship_plan_approval` ADD KEY `idx_plan_approval_flow` (`plan_id`, `approval_level`, `status`, `created_at`)");
    ensureIndex($pdo, 'internship_task_class', 'uk_task_class', "ALTER TABLE `internship_task_class` ADD UNIQUE KEY `uk_task_class` (`arrangement_id`, `class_id`)");
    ensureIndex($pdo, 'internship_task_class', 'idx_task_class_scope', "ALTER TABLE `internship_task_class` ADD KEY `idx_task_class_scope` (`grade_id`, `dep_id`, `profession_id`, `class_id`)");
    ensureIndex($pdo, 'application', 'idx_application_student', "ALTER TABLE `application` ADD KEY `idx_application_student` (`student_id`, `arrangement_id`, `status`)");
    ensureIndex($pdo, 'student_join_teacher', 'idx_join_application', "ALTER TABLE `student_join_teacher` ADD KEY `idx_join_application` (`application_id`, `application_status`)");
    ensureIndex($pdo, 'pair', 'idx_pair_teacher', "ALTER TABLE `pair` ADD KEY `idx_pair_teacher` (`teacher_id`, `type`, `status`)");
    ensureIndex($pdo, 'pair', 'idx_pair_student', "ALTER TABLE `pair` ADD KEY `idx_pair_student` (`student_id`, `type`, `status`)");
    ensureIndex($pdo, 'pair', 'idx_pair_task_scope', "ALTER TABLE `pair` ADD KEY `idx_pair_task_scope` (`arrangement_id`, `student_id`, `type`, `status`)");
    ensureIndex($pdo, 'pair', 'uk_pair_active', "ALTER TABLE `pair` ADD UNIQUE KEY `uk_pair_active` (`student_id`, `type`, `arrangement_id`, `active_flag`)");
    ensureIndex($pdo, 'sign_in', 'idx_sign_student_date', "ALTER TABLE `sign_in` ADD KEY `idx_sign_student_date` (`student_id`, `entity_type`, `entity_id`, `date`)");
    ensureIndex($pdo, 'journal', 'idx_journal_student_date', "ALTER TABLE `journal` ADD KEY `idx_journal_student_date` (`student_id`, `entity_type`, `entity_id`, `date`)");
    ensureIndex($pdo, 'report', 'idx_report_student_arrangement', "ALTER TABLE `report` ADD KEY `idx_report_student_arrangement` (`student_id`, `arrangement_id`, `status`)");
    ensureIndex($pdo, 'review_opinion', 'idx_review_entity', "ALTER TABLE `review_opinion` ADD KEY `idx_review_entity` (`entity_type`, `entity_id`, `status`, `created_at`)");
    ensureIndex($pdo, 'apply_report_delay', 'idx_delay_student_entity', "ALTER TABLE `apply_report_delay` ADD KEY `idx_delay_student_entity` (`student_id`, `entity_type`, `entity_id`, `status`)");
    ensureIndex($pdo, 'score', 'idx_score_student_arrangement', "ALTER TABLE `score` ADD KEY `idx_score_student_arrangement` (`student_id`, `arrangement_id`)");
    ensureIndex($pdo, 'insurance', 'idx_insurance_student_task_date', "ALTER TABLE `insurance` ADD KEY `idx_insurance_student_task_date` (`student_id`, `arrangement_id`, `status`, `start_date`, `end_date`)");
    ensureIndex($pdo, 'safety_letter_sign', 'idx_safety_student_task_status', "ALTER TABLE `safety_letter_sign` ADD KEY `idx_safety_student_task_status` (`student_id`, `arrangement_id`, `status`, `signed_at`)");
}

function ensurePracticeSchema(PDO $pdo): void
{
    $practiceTables = ['practice_plan', 'practice_schedule', 'practice_syllabus', 'practice_lesson_plan', 'practice_grade_rule', 'practice_score', 'practice_reflection'];
    foreach ($practiceTables as $table) {
        $columns = [
            'module_type' => "ALTER TABLE `{$table}` ADD COLUMN `module_type` ENUM('training','lab') DEFAULT 'training' AFTER `code`",
            'plan_id' => "ALTER TABLE `{$table}` ADD COLUMN `plan_id` BIGINT UNSIGNED DEFAULT NULL AFTER `module_type`",
            'grade_id' => "ALTER TABLE `{$table}` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_id`",
            'dep_id' => "ALTER TABLE `{$table}` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `grade_id`",
            'profession_id' => "ALTER TABLE `{$table}` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'class_id' => "ALTER TABLE `{$table}` ADD COLUMN `class_id` BIGINT UNSIGNED DEFAULT NULL AFTER `profession_id`",
            'teacher_id' => "ALTER TABLE `{$table}` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `class_id`",
            'course_name' => "ALTER TABLE `{$table}` ADD COLUMN `course_name` VARCHAR(180) DEFAULT NULL AFTER `teacher_id`",
            'title' => "ALTER TABLE `{$table}` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `course_name`",
            'content' => "ALTER TABLE `{$table}` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `title`",
            'content_json' => "ALTER TABLE `{$table}` ADD COLUMN `content_json` JSON DEFAULT NULL AFTER `content`",
            'remark' => "ALTER TABLE `{$table}` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `content_json`",
        ];
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
        ensureIndex($pdo, $table, "idx_{$table}_module_scope", "ALTER TABLE `{$table}` ADD KEY `idx_{$table}_module_scope` (`module_type`, `grade_id`, `dep_id`, `profession_id`, `status`)");
        ensureIndex($pdo, $table, "idx_{$table}_teacher", "ALTER TABLE `{$table}` ADD KEY `idx_{$table}_teacher` (`module_type`, `teacher_id`, `status`)");
    }

    $specificColumns = [
        'practice_plan' => [
            'source_type' => "ALTER TABLE `practice_plan` ADD COLUMN `source_type` VARCHAR(40) DEFAULT 'manual' AFTER `remark`",
            'submitter_id' => "ALTER TABLE `practice_plan` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `source_type`",
        ],
        'practice_schedule' => [
            'room_id' => "ALTER TABLE `practice_schedule` ADD COLUMN `room_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
            'base_id' => "ALTER TABLE `practice_schedule` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `room_id`",
            'place_type' => "ALTER TABLE `practice_schedule` ADD COLUMN `place_type` VARCHAR(40) DEFAULT 'inside' AFTER `base_id`",
            'schedule_date' => "ALTER TABLE `practice_schedule` ADD COLUMN `schedule_date` DATE DEFAULT NULL AFTER `place_type`",
            'start_time' => "ALTER TABLE `practice_schedule` ADD COLUMN `start_time` VARCHAR(20) DEFAULT NULL AFTER `schedule_date`",
            'end_time' => "ALTER TABLE `practice_schedule` ADD COLUMN `end_time` VARCHAR(20) DEFAULT NULL AFTER `start_time`",
            'location' => "ALTER TABLE `practice_schedule` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `end_time`",
            'student_count' => "ALTER TABLE `practice_schedule` ADD COLUMN `student_count` INT DEFAULT 0 AFTER `location`",
            'roster_printed_at' => "ALTER TABLE `practice_schedule` ADD COLUMN `roster_printed_at` DATETIME DEFAULT NULL AFTER `student_count`",
        ],
        'practice_syllabus' => [
            'submitter_id' => "ALTER TABLE `practice_syllabus` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
        ],
        'practice_lesson_plan' => [
            'submitter_id' => "ALTER TABLE `practice_lesson_plan` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
        ],
        'practice_grade_rule' => [
            'ratio_json' => "ALTER TABLE `practice_grade_rule` ADD COLUMN `ratio_json` JSON DEFAULT NULL AFTER `remark`",
        ],
        'practice_score' => [
            'student_id' => "ALTER TABLE `practice_score` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
            'rule_id' => "ALTER TABLE `practice_score` ADD COLUMN `rule_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'score_items' => "ALTER TABLE `practice_score` ADD COLUMN `score_items` JSON DEFAULT NULL AFTER `rule_id`",
            'score_value' => "ALTER TABLE `practice_score` ADD COLUMN `score_value` DECIMAL(5,2) DEFAULT NULL AFTER `score_items`",
        ],
        'practice_reflection' => [
            'submitter_id' => "ALTER TABLE `practice_reflection` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
        ],
        'practice_room' => [
            'module_type' => "ALTER TABLE `practice_room` ADD COLUMN `module_type` ENUM('training','lab') DEFAULT 'training' AFTER `code`",
            'dep_id' => "ALTER TABLE `practice_room` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `module_type`",
            'room_type' => "ALTER TABLE `practice_room` ADD COLUMN `room_type` VARCHAR(80) DEFAULT NULL AFTER `dep_id`",
            'capacity' => "ALTER TABLE `practice_room` ADD COLUMN `capacity` INT DEFAULT 0 AFTER `room_type`",
            'location' => "ALTER TABLE `practice_room` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `capacity`",
            'manager_id' => "ALTER TABLE `practice_room` ADD COLUMN `manager_id` BIGINT UNSIGNED DEFAULT NULL AFTER `location`",
        ],
        'practice_recording' => [
            'parent_id' => "ALTER TABLE `practice_recording` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'module_type' => "ALTER TABLE `practice_recording` ADD COLUMN `module_type` ENUM('training','lab') DEFAULT 'training' AFTER `parent_id`",
            'action' => "ALTER TABLE `practice_recording` ADD COLUMN `action` VARCHAR(40) DEFAULT NULL AFTER `module_type`",
            'content' => "ALTER TABLE `practice_recording` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `action`",
        ],
    ];
    foreach ($specificColumns as $table => $columns) {
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
    }

    ensureIndex($pdo, 'practice_schedule', 'idx_practice_schedule_date', "ALTER TABLE `practice_schedule` ADD KEY `idx_practice_schedule_date` (`module_type`, `schedule_date`, `status`)");
    ensureIndex($pdo, 'practice_score', 'idx_practice_score_student', "ALTER TABLE `practice_score` ADD KEY `idx_practice_score_student` (`module_type`, `student_id`, `status`)");
    ensureIndex($pdo, 'practice_room', 'idx_practice_room_module', "ALTER TABLE `practice_room` ADD KEY `idx_practice_room_module` (`module_type`, `dep_id`, `status`)");
    ensureIndex($pdo, 'practice_recording', 'idx_practice_recording_entity', "ALTER TABLE `practice_recording` ADD KEY `idx_practice_recording_entity` (`module_type`, `entity_type`, `entity_id`)");
    ensureIndex($pdo, 'practice_recording', 'idx_practice_recording_parent', "ALTER TABLE `practice_recording` ADD KEY `idx_practice_recording_parent` (`parent_id`)");

    $baseFlowColumns = [
        'base_application' => [
            'base_id' => "ALTER TABLE `base_application` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'dep_id' => "ALTER TABLE `base_application` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'base_type' => "ALTER TABLE `base_application` ADD COLUMN `base_type` VARCHAR(40) DEFAULT NULL AFTER `dep_id`",
            'title' => "ALTER TABLE `base_application` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `base_type`",
            'content' => "ALTER TABLE `base_application` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'submitter_id' => "ALTER TABLE `base_application` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `content`",
        ],
        'base_usage' => [
            'base_id' => "ALTER TABLE `base_usage` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'dep_id' => "ALTER TABLE `base_usage` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'usage_type' => "ALTER TABLE `base_usage` ADD COLUMN `usage_type` VARCHAR(80) DEFAULT NULL AFTER `dep_id`",
            'title' => "ALTER TABLE `base_usage` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `usage_type`",
            'content' => "ALTER TABLE `base_usage` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'submitter_id' => "ALTER TABLE `base_usage` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `content`",
        ],
        'base_result' => [
            'base_id' => "ALTER TABLE `base_result` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'dep_id' => "ALTER TABLE `base_result` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'result_type' => "ALTER TABLE `base_result` ADD COLUMN `result_type` VARCHAR(80) DEFAULT NULL AFTER `dep_id`",
            'title' => "ALTER TABLE `base_result` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `result_type`",
            'content' => "ALTER TABLE `base_result` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'submitter_id' => "ALTER TABLE `base_result` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `content`",
        ],
        'base_expense' => [
            'base_id' => "ALTER TABLE `base_expense` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'dep_id' => "ALTER TABLE `base_expense` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'amount' => "ALTER TABLE `base_expense` ADD COLUMN `amount` DECIMAL(12,2) DEFAULT NULL AFTER `dep_id`",
            'title' => "ALTER TABLE `base_expense` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `amount`",
            'content' => "ALTER TABLE `base_expense` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'submitter_id' => "ALTER TABLE `base_expense` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `content`",
        ],
    ];
    foreach ($baseFlowColumns as $table => $columns) {
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
        ensureIndex($pdo, $table, "idx_{$table}_base", "ALTER TABLE `{$table}` ADD KEY `idx_{$table}_base` (`base_id`, `dep_id`, `status`)");
    }
}

function ensureMessageSchema(PDO $pdo): void
{
    $schemas = [
        'message' => [
            'title' => "ALTER TABLE `message` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `code`",
            'content' => "ALTER TABLE `message` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'type' => "ALTER TABLE `message` ADD COLUMN `type` VARCHAR(40) DEFAULT 'system' AFTER `content`",
            'sender_id' => "ALTER TABLE `message` ADD COLUMN `sender_id` BIGINT UNSIGNED DEFAULT 0 AFTER `type`",
            'sender_name' => "ALTER TABLE `message` ADD COLUMN `sender_name` VARCHAR(80) DEFAULT NULL AFTER `sender_id`",
            'level' => "ALTER TABLE `message` ADD COLUMN `level` VARCHAR(40) DEFAULT 'normal' AFTER `sender_name`",
            'entity_type' => "ALTER TABLE `message` ADD COLUMN `entity_type` VARCHAR(80) DEFAULT NULL AFTER `level`",
            'entity_id' => "ALTER TABLE `message` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
            'link_url' => "ALTER TABLE `message` ADD COLUMN `link_url` VARCHAR(500) DEFAULT NULL AFTER `entity_id`",
            'metadata' => "ALTER TABLE `message` ADD COLUMN `metadata` JSON DEFAULT NULL AFTER `link_url`",
        ],
        'message_target' => [
            'message_id' => "ALTER TABLE `message_target` ADD COLUMN `message_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'account_id' => "ALTER TABLE `message_target` ADD COLUMN `account_id` BIGINT UNSIGNED DEFAULT NULL AFTER `message_id`",
            'is_read' => "ALTER TABLE `message_target` ADD COLUMN `is_read` TINYINT(1) DEFAULT 0 AFTER `account_id`",
            'read_at' => "ALTER TABLE `message_target` ADD COLUMN `read_at` DATETIME DEFAULT NULL AFTER `is_read`",
        ],
        'message_template' => [
            'title_tpl' => "ALTER TABLE `message_template` ADD COLUMN `title_tpl` VARCHAR(255) DEFAULT NULL AFTER `code`",
            'content_tpl' => "ALTER TABLE `message_template` ADD COLUMN `content_tpl` TEXT DEFAULT NULL AFTER `title_tpl`",
            'channels' => "ALTER TABLE `message_template` ADD COLUMN `channels` JSON DEFAULT NULL AFTER `content_tpl`",
        ],
        'message_channel_log' => [
            'message_id' => "ALTER TABLE `message_channel_log` ADD COLUMN `message_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'account_id' => "ALTER TABLE `message_channel_log` ADD COLUMN `account_id` BIGINT UNSIGNED DEFAULT NULL AFTER `message_id`",
            'channel' => "ALTER TABLE `message_channel_log` ADD COLUMN `channel` VARCHAR(40) DEFAULT 'internal' AFTER `account_id`",
            'error_message' => "ALTER TABLE `message_channel_log` ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `status`",
            'sent_at' => "ALTER TABLE `message_channel_log` ADD COLUMN `sent_at` DATETIME DEFAULT NULL AFTER `error_message`",
        ],
    ];

    foreach ($schemas as $table => $columns) {
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
    }

    ensureIndex($pdo, 'message', 'idx_type_created', "ALTER TABLE `message` ADD KEY `idx_type_created` (`type`, `created_at`)");
    ensureIndex($pdo, 'message', 'idx_entity', "ALTER TABLE `message` ADD KEY `idx_entity` (`entity_type`, `entity_id`)");
    ensureIndex($pdo, 'message', 'idx_deleted_at', "ALTER TABLE `message` ADD KEY `idx_deleted_at` (`deleted_at`)");
    ensureIndex($pdo, 'message_target', 'idx_account_read', "ALTER TABLE `message_target` ADD KEY `idx_account_read` (`account_id`, `is_read`, `created_at`)");
    ensureIndex($pdo, 'message_target', 'idx_message_id', "ALTER TABLE `message_target` ADD KEY `idx_message_id` (`message_id`)");
    ensureIndex($pdo, 'message_target', 'idx_deleted_at', "ALTER TABLE `message_target` ADD KEY `idx_deleted_at` (`deleted_at`)");
    ensureIndex($pdo, 'message_template', 'uk_code', "ALTER TABLE `message_template` ADD UNIQUE KEY `uk_code` (`code`)");
    ensureIndex($pdo, 'message_channel_log', 'idx_message_account', "ALTER TABLE `message_channel_log` ADD KEY `idx_message_account` (`message_id`, `account_id`)");
    ensureIndex($pdo, 'message_channel_log', 'idx_channel_status', "ALTER TABLE `message_channel_log` ADD KEY `idx_channel_status` (`channel`, `status`)");
    ensureIndex($pdo, 'message_channel_log', 'idx_deleted_at', "ALTER TABLE `message_channel_log` ADD KEY `idx_deleted_at` (`deleted_at`)");
}

function ensureDocSchema(PDO $pdo): void
{
    $schemas = [
        'doc_category' => [
            'parent_id' => "ALTER TABLE `doc_category` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT 0 AFTER `deleted_at`",
            'icon' => "ALTER TABLE `doc_category` ADD COLUMN `icon` VARCHAR(80) DEFAULT NULL AFTER `parent_id`",
            'sort' => "ALTER TABLE `doc_category` ADD COLUMN `sort` INT DEFAULT 0 AFTER `icon`",
        ],
        'doc_article' => [
            'category_id' => "ALTER TABLE `doc_article` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
            'title' => "ALTER TABLE `doc_article` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `category_id`",
            'content' => "ALTER TABLE `doc_article` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `title`",
            'version' => "ALTER TABLE `doc_article` ADD COLUMN `version` VARCHAR(40) DEFAULT '1.0' AFTER `content`",
            'author_id' => "ALTER TABLE `doc_article` ADD COLUMN `author_id` BIGINT UNSIGNED DEFAULT NULL AFTER `version`",
            'view_count' => "ALTER TABLE `doc_article` ADD COLUMN `view_count` INT UNSIGNED DEFAULT 0 AFTER `author_id`",
            'published_at' => "ALTER TABLE `doc_article` ADD COLUMN `published_at` DATETIME DEFAULT NULL AFTER `view_count`",
        ],
        'doc_article_history' => [
            'article_id' => "ALTER TABLE `doc_article_history` ADD COLUMN `article_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
            'title' => "ALTER TABLE `doc_article_history` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `article_id`",
            'content' => "ALTER TABLE `doc_article_history` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `title`",
            'version' => "ALTER TABLE `doc_article_history` ADD COLUMN `version` VARCHAR(40) DEFAULT '1.0' AFTER `content`",
            'editor_id' => "ALTER TABLE `doc_article_history` ADD COLUMN `editor_id` BIGINT UNSIGNED DEFAULT NULL AFTER `version`",
            'change_note' => "ALTER TABLE `doc_article_history` ADD COLUMN `change_note` VARCHAR(500) DEFAULT NULL AFTER `editor_id`",
        ],
    ];

    foreach ($schemas as $table => $columns) {
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
    }

    ensureIndex($pdo, 'doc_category', 'uk_code', "ALTER TABLE `doc_category` ADD UNIQUE KEY `uk_code` (`code`)");
    ensureIndex($pdo, 'doc_category', 'idx_parent', "ALTER TABLE `doc_category` ADD KEY `idx_parent` (`parent_id`)");
    ensureIndex($pdo, 'doc_category', 'idx_status_sort', "ALTER TABLE `doc_category` ADD KEY `idx_status_sort` (`status`, `sort`)");
    ensureIndex($pdo, 'doc_category', 'idx_deleted_at', "ALTER TABLE `doc_category` ADD KEY `idx_deleted_at` (`deleted_at`)");
    ensureIndex($pdo, 'doc_article', 'idx_category_status', "ALTER TABLE `doc_article` ADD KEY `idx_category_status` (`category_id`, `status`)");
    ensureIndex($pdo, 'doc_article', 'idx_status_published', "ALTER TABLE `doc_article` ADD KEY `idx_status_published` (`status`, `published_at`)");
    ensureIndex($pdo, 'doc_article', 'idx_deleted_at', "ALTER TABLE `doc_article` ADD KEY `idx_deleted_at` (`deleted_at`)");
    ensureIndex($pdo, 'doc_article_history', 'idx_article', "ALTER TABLE `doc_article_history` ADD KEY `idx_article` (`article_id`, `created_at`)");
    ensureIndex($pdo, 'doc_article_history', 'idx_deleted_at', "ALTER TABLE `doc_article_history` ADD KEY `idx_deleted_at` (`deleted_at`)");
}

function ensureTemplateSchema(PDO $pdo): void
{
    $schemas = [
        'template_category' => [
            'description' => "ALTER TABLE `template_category` ADD COLUMN `description` VARCHAR(500) DEFAULT NULL AFTER `deleted_at`",
            'sort' => "ALTER TABLE `template_category` ADD COLUMN `sort` INT DEFAULT 0 AFTER `description`",
            'flag' => "ALTER TABLE `template_category` ADD COLUMN `flag` ENUM('off','on') DEFAULT 'on' AFTER `sort`",
        ],
        'template' => [
            'category_id' => "ALTER TABLE `template` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
            'description' => "ALTER TABLE `template` ADD COLUMN `description` VARCHAR(1000) DEFAULT NULL AFTER `category_id`",
            'file_id' => "ALTER TABLE `template` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `description`",
            'version' => "ALTER TABLE `template` ADD COLUMN `version` VARCHAR(40) DEFAULT '1.0' AFTER `file_id`",
            'download_count' => "ALTER TABLE `template` ADD COLUMN `download_count` INT UNSIGNED DEFAULT 0 AFTER `version`",
            'flag' => "ALTER TABLE `template` ADD COLUMN `flag` ENUM('off','on') DEFAULT 'on' AFTER `download_count`",
        ],
    ];

    foreach ($schemas as $table => $columns) {
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
    }

    ensureIndex($pdo, 'template_category', 'uk_code', "ALTER TABLE `template_category` ADD UNIQUE KEY `uk_code` (`code`)");
    ensureIndex($pdo, 'template_category', 'idx_flag_sort', "ALTER TABLE `template_category` ADD KEY `idx_flag_sort` (`flag`, `sort`)");
    ensureIndex($pdo, 'template_category', 'idx_deleted_at', "ALTER TABLE `template_category` ADD KEY `idx_deleted_at` (`deleted_at`)");
    ensureIndex($pdo, 'template', 'idx_category_flag', "ALTER TABLE `template` ADD KEY `idx_category_flag` (`category_id`, `flag`)");
    ensureIndex($pdo, 'template', 'idx_file_id', "ALTER TABLE `template` ADD KEY `idx_file_id` (`file_id`)");
    ensureIndex($pdo, 'template', 'idx_deleted_at', "ALTER TABLE `template` ADD KEY `idx_deleted_at` (`deleted_at`)");
}

function ensureExportTaskSchema(PDO $pdo): void
{
    $columns = [
        'user_id' => "ALTER TABLE `export_task` ADD COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`",
        'type' => "ALTER TABLE `export_task` ADD COLUMN `type` VARCHAR(80) DEFAULT NULL AFTER `user_id`",
        'file_name' => "ALTER TABLE `export_task` ADD COLUMN `file_name` VARCHAR(255) DEFAULT NULL AFTER `type`",
        'params' => "ALTER TABLE `export_task` ADD COLUMN `params` JSON DEFAULT NULL AFTER `file_name`",
        'progress' => "ALTER TABLE `export_task` ADD COLUMN `progress` TINYINT UNSIGNED DEFAULT 0 AFTER `status`",
        'total_rows' => "ALTER TABLE `export_task` ADD COLUMN `total_rows` INT UNSIGNED DEFAULT 0 AFTER `progress`",
        'file_id' => "ALTER TABLE `export_task` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `total_rows`",
        'error_message' => "ALTER TABLE `export_task` ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `file_id`",
        'error_trace' => "ALTER TABLE `export_task` ADD COLUMN `error_trace` MEDIUMTEXT DEFAULT NULL AFTER `error_message`",
        'started_at' => "ALTER TABLE `export_task` ADD COLUMN `started_at` DATETIME DEFAULT NULL AFTER `error_trace`",
        'finished_at' => "ALTER TABLE `export_task` ADD COLUMN `finished_at` DATETIME DEFAULT NULL AFTER `started_at`",
    ];

    foreach ($columns as $column => $ddl) {
        ensureColumn($pdo, 'export_task', $column, $ddl);
    }

    ensureIndex($pdo, 'export_task', 'idx_user_status', "ALTER TABLE `export_task` ADD KEY `idx_user_status` (`user_id`, `status`)");
    ensureIndex($pdo, 'export_task', 'idx_type_status', "ALTER TABLE `export_task` ADD KEY `idx_type_status` (`type`, `status`)");
    ensureIndex($pdo, 'export_task', 'idx_deleted_at', "ALTER TABLE `export_task` ADD KEY `idx_deleted_at` (`deleted_at`)");
}

function simpleTable(string $table, array $columns = []): string
{
    $extra = $columns ? ",\n            " . implode(",\n            ", $columns) : '';

    return "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL{$extra},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

function entityColumns(array $extra = []): array
{
    return array_merge([
        '`entity_type` VARCHAR(40) DEFAULT NULL',
        '`entity_id` BIGINT UNSIGNED DEFAULT NULL',
        'KEY `idx_entity` (`entity_type`, `entity_id`)',
    ], $extra);
}

function practiceCommonColumns(array $extra = []): array
{
    return array_merge([
        '`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'',
        '`plan_id` BIGINT UNSIGNED DEFAULT NULL',
        '`grade_id` BIGINT UNSIGNED DEFAULT NULL',
        '`dep_id` BIGINT UNSIGNED DEFAULT NULL',
        '`profession_id` BIGINT UNSIGNED DEFAULT NULL',
        '`class_id` BIGINT UNSIGNED DEFAULT NULL',
        '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
        '`course_name` VARCHAR(180) DEFAULT NULL',
        '`title` VARCHAR(180) DEFAULT NULL',
        '`content` MEDIUMTEXT DEFAULT NULL',
        '`content_json` JSON DEFAULT NULL',
        '`remark` TEXT DEFAULT NULL',
    ], $extra);
}

function recordingColumns(): array
{
    return [
        '`entity_type` VARCHAR(40) DEFAULT NULL',
        '`entity_id` BIGINT UNSIGNED DEFAULT NULL',
        '`operator_id` BIGINT UNSIGNED DEFAULT NULL',
        '`from_status` VARCHAR(40) DEFAULT NULL',
        '`to_status` VARCHAR(40) DEFAULT NULL',
        '`opinion` TEXT DEFAULT NULL',
        'KEY `idx_entity` (`entity_type`, `entity_id`)',
    ];
}

function seedSchool(PDO $pdo, string $wechatProxyUrl): void
{
    seedRoles($pdo);
    seedArchives($pdo);
    seedAdmin($pdo);
    seedPermissionAccounts($pdo);
    seedPracticeUsers($pdo);
    seedMenus($pdo);
    seedOperationGuides($pdo);
    seedCommonSupportData($pdo);
    seedConfig($pdo, $wechatProxyUrl);
    seedInternshipDemo($pdo);
}

function seedRoles(PDO $pdo): void
{
    $roles = [
        [1, 'super_admin', '超级管理员', 'super_admin', 1],
        [2, 'school_admin', '学校管理员', 'school_admin', 2],
        [3, 'college_admin', '学院管理员', 'college_admin', 3],
        [4, 'profession_admin', '专业管理员', 'profession_admin', 4],
        [5, 'teacher', '教师', 'teacher', 5],
        [6, 'student', '学生', 'student', 6],
        [7, 'enterprise', '企业用户', 'enterprise', 7],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO `role` (`id`, `code`, `name`, `role_type`, `sort`, `status`)
         VALUES (?, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `role_type` = VALUES(`role_type`), `sort` = VALUES(`sort`), `status` = 'enabled'"
    );

    foreach ($roles as $role) {
        $stmt->execute($role);
    }
}

function seedAdmin(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO `users` (`id`, `uuid`, `name`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000000001', '系统管理员', 'enabled')
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `status` = 'enabled'"
    );
    $pdo->prepare(
        "INSERT INTO `account` (`id`, `uuid`, `user_id`, `login_name`, `password`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000000101', 1, 'admin', ?, 'enabled')
         ON DUPLICATE KEY UPDATE `user_id` = 1, `login_name` = 'admin', `status` = 'enabled'"
    )->execute([password_hash('admin123456', PASSWORD_BCRYPT)]);
    $pdo->exec(
        "INSERT INTO `user_role` (`account_id`, `role_id`, `is_primary`)
         VALUES (1, 1, 'true')
         ON DUPLICATE KEY UPDATE `is_primary` = 'true', `deleted_at` = NULL"
    );
}

function seedPermissionAccounts(PDO $pdo): void
{
    $users = [
        [2, '00000000-0000-0000-0000-000000000002', '学校管理员'],
        [3, '00000000-0000-0000-0000-000000000003', '学院管理员'],
        [4, '00000000-0000-0000-0000-000000000004', '专业管理员'],
    ];

    $userStmt = $pdo->prepare(
        "INSERT INTO `users` (`id`, `uuid`, `name`, `status`)
         VALUES (?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `status` = 'enabled'"
    );
    foreach ($users as $user) {
        $userStmt->execute($user);
    }

    $accounts = [
        [2, '00000000-0000-0000-0000-000000000102', 2, 'school_admin', 2],
        [3, '00000000-0000-0000-0000-000000000103', 3, 'college_admin', 3],
        [4, '00000000-0000-0000-0000-000000000104', 4, 'profession_admin', 4],
    ];

    $accountStmt = $pdo->prepare(
        "INSERT INTO `account` (`id`, `uuid`, `user_id`, `login_name`, `password`, `status`)
         VALUES (?, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `login_name` = VALUES(`login_name`), `status` = 'enabled'"
    );
    $roleStmt = $pdo->prepare(
        "INSERT INTO `user_role` (`account_id`, `role_id`, `is_primary`)
         VALUES (?, ?, 'true')
         ON DUPLICATE KEY UPDATE `is_primary` = 'true', `deleted_at` = NULL"
    );

    foreach ($accounts as [$accountId, $uuid, $userId, $loginName, $roleId]) {
        $accountStmt->execute([$accountId, $uuid, $userId, $loginName, password_hash('admin123456', PASSWORD_BCRYPT)]);
        $roleStmt->execute([$accountId, $roleId]);
    }

    $scopeStmt = $pdo->prepare(
        "INSERT INTO `sys_organization` (`account_id`, `user_id`, `role_id`, `dep_id`, `profession_id`, `disabled`, `deleted_at`)
         VALUES (?, ?, ?, ?, ?, 'false', NULL)
         ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `disabled` = 'false', `deleted_at` = NULL"
    );
    $scopeStmt->execute([3, 3, 3, '1', null]);
    $scopeStmt->execute([4, 4, 4, null, '1']);
}

function seedPracticeUsers(PDO $pdo): void
{
    $users = [
        [5, '00000000-0000-0000-0000-000000000005', '指导教师张老师'],
        [6, '00000000-0000-0000-0000-000000000006', '学生李同学'],
        [7, '00000000-0000-0000-0000-000000000007', '企业导师账号'],
    ];

    $userStmt = $pdo->prepare(
        "INSERT INTO `users` (`id`, `uuid`, `name`, `mobile`, `status`)
         VALUES (?, ?, ?, '13800000000', 'enabled')
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `status` = 'enabled'"
    );
    foreach ($users as $user) {
        $userStmt->execute($user);
    }

    $accounts = [
        [5, '00000000-0000-0000-0000-000000000105', 5, 'teacher', 5],
        [6, '00000000-0000-0000-0000-000000000106', 6, 'student', 6],
        [7, '00000000-0000-0000-0000-000000000107', 7, 'enterprise', 7],
    ];

    $accountStmt = $pdo->prepare(
        "INSERT INTO `account` (`id`, `uuid`, `user_id`, `login_name`, `password`, `status`)
         VALUES (?, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `login_name` = VALUES(`login_name`), `status` = 'enabled'"
    );
    $roleStmt = $pdo->prepare(
        "INSERT INTO `user_role` (`account_id`, `role_id`, `is_primary`)
         VALUES (?, ?, 'true')
         ON DUPLICATE KEY UPDATE `is_primary` = 'true', `deleted_at` = NULL"
    );

    foreach ($accounts as [$accountId, $uuid, $userId, $loginName, $roleId]) {
        $accountStmt->execute([$accountId, $uuid, $userId, $loginName, password_hash('admin123456', PASSWORD_BCRYPT)]);
        $roleStmt->execute([$accountId, $roleId]);
    }

    $pdo->exec(
        "INSERT INTO `teacher_list` (`teacher_id`, `teacher_uuid`, `user_id`, `teacher_name`, `teacher_num`, `dep_id`, `profession_id`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000060001', 5, '张老师', 'T001', 1, 1, 'enabled')
         ON DUPLICATE KEY UPDATE
            `user_id` = VALUES(`user_id`),
            `teacher_name` = VALUES(`teacher_name`),
            `teacher_num` = VALUES(`teacher_num`),
            `dep_id` = VALUES(`dep_id`),
            `profession_id` = VALUES(`profession_id`),
            `status` = 'enabled'"
    );

    $pdo->exec(
        "INSERT INTO `students` (`student_id`, `student_uuid`, `user_id`, `name`, `student_num`, `grade_id`, `dep_id`, `profession_id`, `class_id`, `class_num`, `semester`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000070001', 6, '李同学', 'S20260001', 1, 1, 1, 1, 'RJ2601', '2025-2026-2', 'enabled')
         ON DUPLICATE KEY UPDATE
            `user_id` = VALUES(`user_id`),
            `name` = VALUES(`name`),
            `student_num` = VALUES(`student_num`),
            `grade_id` = VALUES(`grade_id`),
            `dep_id` = VALUES(`dep_id`),
            `profession_id` = VALUES(`profession_id`),
            `class_id` = VALUES(`class_id`),
            `class_num` = VALUES(`class_num`),
            `semester` = VALUES(`semester`),
            `status` = 'enabled'"
    );

    $pdo->exec(
        "INSERT INTO `grade_teacher_guide` (`teacher_id`, `grade_id`, `dep_id`, `semester`, `max_choose_number`)
         VALUES (1, 1, 1, '2025-2026-2', 20)
         ON DUPLICATE KEY UPDATE `max_choose_number` = VALUES(`max_choose_number`), `deleted_at` = NULL"
    );

    $scopeStmt = $pdo->prepare(
        "INSERT INTO `sys_organization` (`account_id`, `user_id`, `role_id`, `dep_id`, `profession_id`, `company_id`, `disabled`, `deleted_at`)
         VALUES (?, ?, ?, ?, ?, ?, 'false', NULL)
         ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `disabled` = 'false', `deleted_at` = NULL"
    );
    $scopeStmt->execute([7, 7, 7, null, null, '1']);
}

function seedInternshipDemo(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO `base` (`id`, `uuid`, `name`, `code`, `company_id`, `dep_id`, `address`, `capacity`, `used_count`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000080001', '成都锦城学院实践基地', 'BASE001', 1, 1, '成都市高新区', 80, 0, 'enabled')
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `company_id` = VALUES(`company_id`),
            `dep_id` = VALUES(`dep_id`),
            `address` = VALUES(`address`),
            `capacity` = VALUES(`capacity`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    $pdo->exec(
        "INSERT INTO `base_profession_direction` (`base_id`, `profession_id`, `direction_id`)
         VALUES (1, 1, 0)
         ON DUPLICATE KEY UPDATE `deleted_at` = NULL"
    );

    $pdo->exec(
        "INSERT INTO `enterprise_mentor` (`id`, `uuid`, `company_id`, `name`, `phone`, `position`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000090001', 1, '企业导师', '13800000000', '项目经理', 'enabled')
         ON DUPLICATE KEY UPDATE
            `company_id` = VALUES(`company_id`),
            `name` = VALUES(`name`),
            `phone` = VALUES(`phone`),
            `position` = VALUES(`position`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    $pdo->exec(
        "INSERT INTO `internship_plan` (`id`, `uuid`, `source_type`, `course_code`, `course_name`, `grade_id`, `dep_id`, `profession_id`, `semester`, `credit`, `student_count`, `score_rule`, `plan_content`, `submitter_id`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000100000', 'edu_system', 'SX-RJ-2026', '软件技术专业实习课程', 1, 1, 1, '2025-2026-2', 2.00, 1, 'average', JSON_OBJECT('content', '从教务系统抽取的软件技术专业实习课程计划。'), 1, 'accept')
         ON DUPLICATE KEY UPDATE
            `source_type` = VALUES(`source_type`),
            `course_code` = VALUES(`course_code`),
            `course_name` = VALUES(`course_name`),
            `grade_id` = VALUES(`grade_id`),
            `dep_id` = VALUES(`dep_id`),
            `profession_id` = VALUES(`profession_id`),
            `semester` = VALUES(`semester`),
            `credit` = VALUES(`credit`),
            `student_count` = VALUES(`student_count`),
            `score_rule` = VALUES(`score_rule`),
            `plan_content` = VALUES(`plan_content`),
            `submitter_id` = VALUES(`submitter_id`),
            `status` = 'accept',
            `deleted_at` = NULL"
    );

    $pdo->exec(
        "INSERT INTO `arrangement` (`id`, `uuid`, `name`, `plan_id`, `base_id`, `dep_id`, `profession_id`, `semester`, `teacher_id`, `task_no`, `batch_no`, `credit`, `student_count`, `type`, `organize_mode`, `title`, `start_date`, `end_date`, `location`, `description`, `created_by`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000100001', '软件技术2601实习任务', 1, 1, 1, 1, '2025-2026-2', 1, 'TASK-RJ-2601-01', '第一批', 2.00, 1, 'major_external', 'centralized', '软件技术2601校外实习任务', '2026-07-01', '2026-08-31', '成都锦城学院实践基地', '默认开发环境实习任务', 1, 'enabled')
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `plan_id` = VALUES(`plan_id`),
            `base_id` = VALUES(`base_id`),
            `dep_id` = VALUES(`dep_id`),
            `profession_id` = VALUES(`profession_id`),
            `semester` = VALUES(`semester`),
            `teacher_id` = VALUES(`teacher_id`),
            `task_no` = VALUES(`task_no`),
            `batch_no` = VALUES(`batch_no`),
            `credit` = VALUES(`credit`),
            `student_count` = VALUES(`student_count`),
            `type` = VALUES(`type`),
            `organize_mode` = VALUES(`organize_mode`),
            `title` = VALUES(`title`),
            `start_date` = VALUES(`start_date`),
            `end_date` = VALUES(`end_date`),
            `location` = VALUES(`location`),
            `description` = VALUES(`description`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    $pdo->exec(
        "INSERT INTO `internship_task_class` (`id`, `uuid`, `arrangement_id`, `grade_id`, `dep_id`, `profession_id`, `class_id`, `student_count_snapshot`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000100002', 1, 1, 1, 1, 1, 1, 'active')
         ON DUPLICATE KEY UPDATE
            `grade_id` = VALUES(`grade_id`),
            `dep_id` = VALUES(`dep_id`),
            `profession_id` = VALUES(`profession_id`),
            `student_count_snapshot` = VALUES(`student_count_snapshot`),
            `status` = 'active',
            `deleted_at` = NULL"
    );

    $pdo->exec(
        "INSERT INTO `pair` (`id`, `uuid`, `student_id`, `teacher_id`, `dep_id`, `arrangement_id`, `type`, `entity_type`, `entity_id`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000100003', 1, 1, 1, 1, 'internship', 'internship', 1, 'active')
         ON DUPLICATE KEY UPDATE
            `teacher_id` = VALUES(`teacher_id`),
            `dep_id` = VALUES(`dep_id`),
            `arrangement_id` = VALUES(`arrangement_id`),
            `type` = 'internship',
            `entity_type` = 'internship',
            `entity_id` = VALUES(`entity_id`),
            `status` = 'active',
            `deleted_at` = NULL"
    );

    $pdo->exec(
        "INSERT INTO `report_template` (`id`, `uuid`, `name`, `code`, `content`, `version`, `online_enabled`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000110001', '实习报告模板', 'internship_report_default', '请填写实习单位、实习内容、收获与建议。', '1.0', 'true', 'enabled')
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `content` = VALUES(`content`),
            `version` = VALUES(`version`),
            `online_enabled` = VALUES(`online_enabled`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );
}

function seedArchives(PDO $pdo): void
{
    $departments = [
        [1, '00000000-0000-0000-0000-000000010001', '信息工程学院', '信工', 'D001', 10],
        [2, '00000000-0000-0000-0000-000000010002', '经济管理学院', '经管', 'D002', 20],
    ];
    $departmentStmt = $pdo->prepare(
        "INSERT INTO `department` (`dep_id`, `dep_uuid`, `dep_name`, `dep_short_name`, `dep_code`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE `dep_name` = VALUES(`dep_name`), `dep_short_name` = VALUES(`dep_short_name`), `dep_code` = VALUES(`dep_code`), `sort` = VALUES(`sort`), `flag` = 'on'"
    );
    foreach ($departments as $department) {
        $departmentStmt->execute($department);
    }

    $grades = [
        [1, '00000000-0000-0000-0000-000000020001', '2026级', 1, 'true', 10],
        [2, '00000000-0000-0000-0000-000000020002', '2026级', 2, 'false', 20],
    ];
    $gradeStmt = $pdo->prepare(
        "INSERT INTO `grade_list` (`grade_id`, `grade_uuid`, `grade_name`, `dep_id`, `is_current`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE `grade_name` = VALUES(`grade_name`), `dep_id` = VALUES(`dep_id`), `is_current` = VALUES(`is_current`), `sort` = VALUES(`sort`), `flag` = 'on'"
    );
    foreach ($grades as $grade) {
        $gradeStmt->execute($grade);
    }

    $professions = [
        [1, '00000000-0000-0000-0000-000000030001', '软件技术', '软件', 'P001', 1, 1, 10],
        [2, '00000000-0000-0000-0000-000000030002', '大数据技术', '大数据', 'P002', 1, 1, 20],
        [3, '00000000-0000-0000-0000-000000030003', '电子商务', '电商', 'P003', 2, 2, 30],
    ];
    $professionStmt = $pdo->prepare(
        "INSERT INTO `profession` (`profession_id`, `profession_uuid`, `profession_name`, `profession_short_name`, `profession_code`, `dep_id`, `grade_id`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE
            `profession_name` = VALUES(`profession_name`),
            `profession_short_name` = VALUES(`profession_short_name`),
            `profession_code` = VALUES(`profession_code`),
            `dep_id` = VALUES(`dep_id`),
            `grade_id` = VALUES(`grade_id`),
            `sort` = VALUES(`sort`),
            `flag` = 'on'"
    );
    foreach ($professions as $profession) {
        $professionStmt->execute($profession);
    }

    $classes = [
        [1, '00000000-0000-0000-0000-000000040001', '软件技术2601', '软技2601', 'RJ2601', 1, 1, 1, 10],
        [2, '00000000-0000-0000-0000-000000040002', '大数据2601', '大数据2601', 'DS2601', 1, 2, 1, 20],
        [3, '00000000-0000-0000-0000-000000040003', '电子商务2601', '电商2601', 'EC2601', 2, 3, 2, 30],
    ];
    $classStmt = $pdo->prepare(
        "INSERT INTO `class` (`class_id`, `class_uuid`, `class_name`, `class_short_name`, `class_num`, `dep_id`, `profession_id`, `grade_id`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE
            `class_name` = VALUES(`class_name`),
            `class_short_name` = VALUES(`class_short_name`),
            `class_num` = VALUES(`class_num`),
            `dep_id` = VALUES(`dep_id`),
            `profession_id` = VALUES(`profession_id`),
            `grade_id` = VALUES(`grade_id`),
            `sort` = VALUES(`sort`),
            `flag` = 'on'"
    );
    foreach ($classes as $class) {
        $classStmt->execute($class);
    }

    $pdo->exec(
        "INSERT INTO `companies` (`company_id`, `company_uuid`, `company_name`, `credit_code`, `contact_name`, `contact_mobile`, `address`, `flag`)
         VALUES (1, '00000000-0000-0000-0000-000000050001', '本地实践合作企业', 'LOCAL-COMPANY-001', '企业导师', '13800000000', '本地测试地址', 'on')
         ON DUPLICATE KEY UPDATE
            `company_name` = VALUES(`company_name`),
            `credit_code` = VALUES(`credit_code`),
            `contact_name` = VALUES(`contact_name`),
            `contact_mobile` = VALUES(`contact_mobile`),
            `address` = VALUES(`address`),
            `flag` = 'on'"
    );
}

function seedMenus(PDO $pdo): void
{
    $menus = [
        [1, 0, '实习管理', null, null, 'both', 'directory', 10, 'BriefcaseBusiness'],
        [11, 1, '实习安排', null, null, 'both', 'menu', 11, 'CalendarCheck'],
        [111, 11, '列表', 'internship:view', '/internship', 'both', 'list', 111, 'List'],
        [101, 111, '新增', 'internship:manage', null, 'both', 'button', 101, null],
        [1112, 111, '删除', 'internship:arrangement:delete', null, 'pc', 'button', 112, null],
        [12, 1, '补充申请', null, null, 'both', 'menu', 12, 'ClipboardList'],
        [121, 12, '列表', 'internship:application:list', '/internship/applications', 'both', 'list', 121, 'List'],
        [103, 121, '提交', 'internship:apply', null, 'both', 'button', 103, null],
        [104, 121, '审核', 'internship:approve', null, 'both', 'button', 104, null],
        [1213, 121, '通过后修改', 'internship:application:reopen', null, 'pc', 'button', 123, null],
        [13, 1, '任务绑定', null, null, 'pc', 'menu', 13, 'UsersRound'],
        [131, 13, '列表', 'internship:pair:list', '/internship/pairs', 'pc', 'list', 131, 'List'],
        [1311, 131, '新增', 'internship:pair:save', null, 'pc', 'button', 131, null],
        [1312, 131, '删除', 'internship:pair:delete', null, 'pc', 'button', 132, null],
        [14, 1, '签到记录', null, null, 'both', 'menu', 14, 'MapPin'],
        [141, 14, '列表', 'internship:sign:list', '/internship/sign-ins', 'both', 'list', 141, 'List'],
        [105, 141, '签到', 'internship:sign', null, 'h5', 'button', 105, null],
        [15, 1, '实习日志', null, null, 'both', 'menu', 15, 'FileClock'],
        [151, 15, '列表', 'internship:journal:list', '/internship/journals', 'both', 'list', 151, 'List'],
        [106, 151, '提交', 'internship:journal', null, 'both', 'button', 106, null],
        [1512, 151, '评阅', 'internship:journal:review', null, 'pc', 'button', 152, null],
        [16, 1, '实习报告', null, null, 'both', 'menu', 16, 'FileText'],
        [161, 16, '列表', 'internship:report:list', '/internship/reports', 'both', 'list', 161, 'List'],
        [107, 161, '提交', 'internship:report', null, 'both', 'button', 107, null],
        [1612, 161, '评阅', 'internship:report:review', null, 'pc', 'button', 162, null],
        [17, 1, '成绩管理', null, null, 'pc', 'menu', 17, 'GraduationCap'],
        [171, 17, '列表', 'internship:score:list', '/internship/scores', 'pc', 'list', 171, 'List'],
        [108, 171, '录入', 'internship:score', null, 'pc', 'button', 108, null],
        [18, 1, '归档材料', null, null, 'pc', 'menu', 18, 'FolderOpen'],
        [181, 18, '列表', 'internship:archive:list', '/internship/documents', 'pc', 'list', 181, 'List'],
        [109, 181, '归档', 'internship:archive', null, 'pc', 'button', 109, null],
        [19, 1, '实习计划', null, null, 'pc', 'menu', 19, 'FileText'],
        [191, 19, '列表', 'internship:plan:list', '/internship/plans', 'pc', 'list', 191, 'List'],
        [110, 191, '维护', 'internship:plan', null, 'pc', 'button', 110, null],
        [195, 1, '延期申请', null, null, 'both', 'menu', 195, 'FileClock'],
        [1951, 195, '列表', 'internship:delay:list', '/internship/delays', 'both', 'list', 1951, 'List'],
        [19511, 1951, '提交', 'internship:apply', null, 'both', 'button', 1951, null],
        [19512, 1951, '审核', 'internship:approve', null, 'both', 'button', 1952, null],
        [2, 0, '实训管理', null, null, 'both', 'directory', 20, 'Workflow'],
        [21, 2, '教学计划', null, null, 'both', 'menu', 21, 'FileText'],
        [211, 21, '列表', 'training:view', '/training/plans', 'both', 'list', 211, 'List'],
        [201, 211, '维护', 'training:manage', null, 'both', 'button', 201, null],
        [202, 211, '审核', 'training:approve', null, 'both', 'button', 202, null],
        [22, 2, '课表安排', null, null, 'both', 'menu', 22, 'CalendarCheck'],
        [221, 22, '列表', 'training:schedule:list', '/training/schedules', 'both', 'list', 221, 'List'],
        [2211, 221, '维护', 'training:manage', null, 'both', 'button', 2211, null],
        [23, 2, '大纲编写', null, null, 'both', 'menu', 23, 'BookOpen'],
        [231, 23, '列表', 'training:syllabus:list', '/training/syllabus', 'both', 'list', 231, 'List'],
        [2311, 231, '维护', 'training:manage', null, 'both', 'button', 2311, null],
        [2312, 231, '审核', 'training:approve', null, 'both', 'button', 2312, null],
        [24, 2, '教案编写', null, null, 'both', 'menu', 24, 'FileText'],
        [241, 24, '列表', 'training:lesson:list', '/training/lesson-plans', 'both', 'list', 241, 'List'],
        [2411, 241, '维护', 'training:manage', null, 'both', 'button', 2411, null],
        [2412, 241, '审核', 'training:approve', null, 'both', 'button', 2412, null],
        [25, 2, '成绩评定', null, null, 'both', 'menu', 25, 'GraduationCap'],
        [251, 25, '列表', 'training:score:list', '/training/scores', 'both', 'list', 251, 'List'],
        [2511, 251, '维护', 'training:manage', null, 'both', 'button', 2511, null],
        [26, 2, '反思报告', null, null, 'both', 'menu', 26, 'FileClock'],
        [261, 26, '列表', 'training:reflection:list', '/training/reflections', 'both', 'list', 261, 'List'],
        [2611, 261, '维护', 'training:manage', null, 'both', 'button', 2611, null],
        [2612, 261, '审核', 'training:approve', null, 'both', 'button', 2612, null],
        [27, 2, '场地管理', null, null, 'pc', 'menu', 27, 'Building2'],
        [271, 27, '列表', 'training:room:list', '/training/rooms', 'pc', 'list', 271, 'List'],
        [2711, 271, '维护', 'training:manage', null, 'pc', 'button', 2711, null],
        [3, 0, '实验管理', null, null, 'both', 'directory', 30, 'FlaskConical'],
        [31, 3, '教学计划', null, null, 'both', 'menu', 31, 'FileText'],
        [311, 31, '列表', 'lab:view', '/lab/plans', 'both', 'list', 311, 'List'],
        [301, 311, '维护', 'lab:manage', null, 'both', 'button', 301, null],
        [302, 311, '审核', 'lab:approve', null, 'both', 'button', 302, null],
        [32, 3, '课表安排', null, null, 'both', 'menu', 32, 'CalendarCheck'],
        [321, 32, '列表', 'lab:schedule:list', '/lab/schedules', 'both', 'list', 321, 'List'],
        [3211, 321, '维护', 'lab:manage', null, 'both', 'button', 3211, null],
        [33, 3, '大纲编写', null, null, 'both', 'menu', 33, 'BookOpen'],
        [331, 33, '列表', 'lab:syllabus:list', '/lab/syllabus', 'both', 'list', 331, 'List'],
        [3311, 331, '维护', 'lab:manage', null, 'both', 'button', 3311, null],
        [3312, 331, '审核', 'lab:approve', null, 'both', 'button', 3312, null],
        [34, 3, '教案编写', null, null, 'both', 'menu', 34, 'FileText'],
        [341, 34, '列表', 'lab:lesson:list', '/lab/lesson-plans', 'both', 'list', 341, 'List'],
        [3411, 341, '维护', 'lab:manage', null, 'both', 'button', 3411, null],
        [3412, 341, '审核', 'lab:approve', null, 'both', 'button', 3412, null],
        [35, 3, '成绩评定', null, null, 'both', 'menu', 35, 'GraduationCap'],
        [351, 35, '列表', 'lab:score:list', '/lab/scores', 'both', 'list', 351, 'List'],
        [3511, 351, '维护', 'lab:manage', null, 'both', 'button', 3511, null],
        [36, 3, '反思报告', null, null, 'both', 'menu', 36, 'FileClock'],
        [361, 36, '列表', 'lab:reflection:list', '/lab/reflections', 'both', 'list', 361, 'List'],
        [3611, 361, '维护', 'lab:manage', null, 'both', 'button', 3611, null],
        [3612, 361, '审核', 'lab:approve', null, 'both', 'button', 3612, null],
        [37, 3, '场地管理', null, null, 'pc', 'menu', 37, 'Building2'],
        [371, 37, '列表', 'lab:room:list', '/lab/rooms', 'pc', 'list', 371, 'List'],
        [3711, 371, '维护', 'lab:manage', null, 'pc', 'button', 3711, null],
        [4, 0, '统计报表', null, null, 'pc', 'directory', 40, 'ChartColumn'],
        [41, 4, '实习统计', null, null, 'pc', 'menu', 41, 'ChartColumn'],
        [411, 41, '列表', 'stat:view', '/stat', 'pc', 'list', 411, 'List'],
        [402, 411, '处理', 'stat:manage', null, 'pc', 'button', 402, null],
        [42, 4, '学院统计', null, null, 'pc', 'menu', 42, 'Building2'],
        [421, 42, '列表', 'stat:department', '/stat/department', 'pc', 'list', 421, 'List'],
        [43, 4, '专业统计', null, null, 'pc', 'menu', 43, 'GraduationCap'],
        [431, 43, '列表', 'stat:profession', '/stat/profession', 'pc', 'list', 431, 'List'],
        [44, 4, '任务老师统计', null, null, 'pc', 'menu', 44, 'UsersRound'],
        [441, 44, '列表', 'stat:teacher', '/stat/teacher', 'pc', 'list', 441, 'List'],
        [45, 4, '学生过程统计', null, null, 'pc', 'menu', 45, 'UserRound'],
        [451, 45, '列表', 'stat:student', '/stat/student', 'pc', 'list', 451, 'List'],
        [46, 4, '归档材料统计', null, null, 'pc', 'menu', 46, 'FolderOpen'],
        [461, 46, '列表', 'stat:archive', '/stat/archive', 'pc', 'list', 461, 'List'],
        [47, 4, '实验实训成绩记载表', null, null, 'pc', 'menu', 47, 'Table2'],
        [471, 47, '列表', 'stat:view', '/stat/practice-score-sheet', 'pc', 'list', 471, 'List'],
        [5, 0, '日志审计', null, null, 'pc', 'directory', 50, 'FileClock'],
        [51, 5, '操作日志', null, null, 'pc', 'menu', 51, 'FileClock'],
        [511, 51, '列表', 'log:view', '/log', 'pc', 'list', 511, 'List'],
        [501, 511, '日志处理', 'log:manage', null, 'pc', 'button', 501, null],
        [6, 0, '系统配置', 'config:view', null, 'pc', 'directory', 60, 'Settings'],
        [606, 6, '用户管理', null, null, 'pc', 'menu', 60, 'UsersRound'],
        [6061, 606, '列表', 'config:user', '/config/users', 'pc', 'list', 601, 'List'],
        [607, 6, '届次管理', null, null, 'pc', 'menu', 61, 'GraduationCap'],
        [6071, 607, '列表', 'config:grade', '/config/grades', 'pc', 'list', 611, 'List'],
        [60711, 6071, '新增', 'config:grade:save', null, 'pc', 'button', 611, null],
        [60712, 6071, '编辑', 'config:grade:update', null, 'pc', 'button', 612, null],
        [60713, 6071, '删除', 'config:grade:delete', null, 'pc', 'button', 613, null],
        [605, 6, '学院管理', null, null, 'pc', 'menu', 62, 'Building2'],
        [6051, 605, '列表', 'config:department', '/config/departments', 'pc', 'list', 621, 'List'],
        [60511, 6051, '新增', 'config:department:save', null, 'pc', 'button', 621, null],
        [60512, 6051, '编辑', 'config:department:update', null, 'pc', 'button', 622, null],
        [60513, 6051, '删除', 'config:department:delete', null, 'pc', 'button', 623, null],
        [608, 6, '专业管理', null, null, 'pc', 'menu', 63, 'GraduationCap'],
        [6081, 608, '列表', 'config:profession', '/config/professions', 'pc', 'list', 631, 'List'],
        [60811, 6081, '新增', 'config:profession:save', null, 'pc', 'button', 631, null],
        [60812, 6081, '编辑', 'config:profession:update', null, 'pc', 'button', 632, null],
        [60813, 6081, '删除', 'config:profession:delete', null, 'pc', 'button', 633, null],
        [609, 6, '班级管理', null, null, 'pc', 'menu', 64, 'UsersRound'],
        [6091, 609, '列表', 'config:class', '/config/classes', 'pc', 'list', 641, 'List'],
        [60911, 6091, '新增', 'config:class:save', null, 'pc', 'button', 641, null],
        [60912, 6091, '编辑', 'config:class:update', null, 'pc', 'button', 642, null],
        [60913, 6091, '删除', 'config:class:delete', null, 'pc', 'button', 643, null],
        [610, 6, '企业管理', null, null, 'pc', 'menu', 65, 'Building2'],
        [6101, 610, '列表', 'config:company', '/config/companies', 'pc', 'list', 651, 'List'],
        [61011, 6101, '新增', 'config:company:save', null, 'pc', 'button', 651, null],
        [61012, 6101, '编辑', 'config:company:update', null, 'pc', 'button', 652, null],
        [61013, 6101, '删除', 'config:company:delete', null, 'pc', 'button', 653, null],
        [61, 6, '菜单管理', null, null, 'pc', 'menu', 70, 'ListTree'],
        [611, 61, '列表', 'config:menu', '/config/menus', 'pc', 'list', 701, 'List'],
        [601, 611, '新增', 'config:manage', null, 'pc', 'button', 601, null],
        [602, 611, '编辑', 'config:menu:save', null, 'pc', 'button', 602, null],
        [6113, 611, '删除', 'config:menu:delete', null, 'pc', 'button', 613, null],
        [603, 6, '角色权限', null, null, 'pc', 'menu', 71, 'ShieldCheck'],
        [6031, 603, '列表', 'config:role', '/config/roles', 'pc', 'list', 711, 'List'],
        [60311, 6031, '保存', 'config:role:save', null, 'pc', 'button', 621, null],
        [604, 6, '组织范围', null, null, 'pc', 'menu', 72, 'SlidersHorizontal'],
        [6041, 604, '列表', 'config:scope', '/config/scopes', 'pc', 'list', 721, 'List'],
        [60411, 6041, '保存', 'config:scope:save', null, 'pc', 'button', 641, null],
        [63, 6, '操作说明', null, null, 'pc', 'menu', 73, 'BookOpen'],
        [631, 63, '列表', 'guide:view', '/config/guides', 'pc', 'list', 731, 'List'],
        [6311, 631, '保存', 'guide:save', null, 'pc', 'button', 631, null],
        [6312, 631, '删除', 'guide:delete', null, 'pc', 'button', 632, null],
        [7, 6, '企业微信应用', null, null, 'pc', 'menu', 74, 'Network'],
        [711, 7, '列表', 'wechat:proxy', '/config/wechat-proxy', 'pc', 'list', 741, 'List'],
        [701, 711, '保存', 'wechat:proxy:save', null, 'pc', 'button', 701, null],
        [8, 0, '文件管理', null, null, 'pc', 'directory', 70, 'FolderOpen'],
        [81, 8, '文件管理', null, null, 'pc', 'menu', 81, 'FolderOpen'],
        [811, 81, '列表', 'file:view', '/file', 'pc', 'list', 811, 'List'],
        [801, 811, '文件处理', 'file:manage', null, 'pc', 'button', 801, null],
        [9, 0, '文档中心', null, null, 'both', 'directory', 75, 'BookOpen'],
        [91, 9, '帮助文档', null, null, 'both', 'menu', 91, 'BookOpen'],
        [911, 91, '列表', 'doc:view', '/doc', 'both', 'list', 911, 'List'],
        [9111, 911, '保存', 'doc:manage', null, 'pc', 'button', 912, null],
        [9112, 911, '删除', 'doc:manage', null, 'pc', 'button', 913, null],
        [10, 0, '模板库', null, null, 'both', 'directory', 76, 'FolderOpen'],
        [100, 10, '模板管理', null, null, 'both', 'menu', 100, 'FolderOpen'],
        [1000, 100, '列表', 'template:view', '/template', 'both', 'list', 1000, 'List'],
        [10001, 1000, '下载', 'template:download', null, 'both', 'button', 1001, null],
        [10002, 1000, '保存', 'template:manage', null, 'pc', 'button', 1002, null],
        [10003, 1000, '删除', 'template:manage', null, 'pc', 'button', 1003, null],
        [20, 0, '导出任务', null, null, 'pc', 'directory', 77, 'FileClock'],
        [200, 20, '任务中心', null, null, 'pc', 'menu', 200, 'FileClock'],
        [2000, 200, '列表', 'export:view', '/export/tasks', 'pc', 'list', 2000, 'List'],
        [20001, 2000, '创建', 'export:create', null, 'pc', 'button', 2001, null],
        [20002, 2000, '重试', 'export:retry', null, 'pc', 'button', 2002, null],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO `menu` (`id`, `parent_id`, `name`, `code`, `path`, `platform`, `type`, `sort`, `icon`, `visible`, `status`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'true', 'enabled')
         ON DUPLICATE KEY UPDATE
            `parent_id` = VALUES(`parent_id`),
            `name` = VALUES(`name`),
            `code` = VALUES(`code`),
            `path` = VALUES(`path`),
            `platform` = VALUES(`platform`),
            `type` = VALUES(`type`),
            `sort` = VALUES(`sort`),
            `icon` = VALUES(`icon`),
            `visible` = 'true',
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    foreach ($menus as $menu) {
        $stmt->execute($menu);
    }

    $disabledMenuIds = [102, 401];
    $disableStmt = $pdo->prepare(
        "UPDATE `menu`
         SET `visible` = 'false', `status` = 'disabled', `deleted_at` = COALESCE(`deleted_at`, NOW())
         WHERE `id` = ?"
    );
    foreach ($disabledMenuIds as $menuId) {
        $disableStmt->execute([$menuId]);
    }

    $roleMenu = $pdo->prepare(
        "INSERT INTO `role_menu` (`role_id`, `menu_id`)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `deleted_at` = NULL"
    );

    $internshipAdminMenus = [
        1, 11, 111, 101, 1112, 12, 121, 103, 104, 1213, 13, 131, 1311, 1312,
        14, 141, 105, 15, 151, 106, 1512, 16, 161, 107, 1612, 17, 171, 108,
        18, 181, 109, 19, 191, 110, 195, 1951, 19511, 19512,
    ];
    $trainingMenus = [2, 21, 211, 201, 202, 22, 221, 2211, 23, 231, 2311, 2312, 24, 241, 2411, 2412, 25, 251, 2511, 26, 261, 2611, 2612, 27, 271, 2711];
    $labMenus = [3, 31, 311, 301, 302, 32, 321, 3211, 33, 331, 3311, 3312, 34, 341, 3411, 3412, 35, 351, 3511, 36, 361, 3611, 3612, 37, 371, 3711];
    $statMenus = [4, 41, 411, 402, 42, 421, 43, 431, 44, 441, 45, 451, 46, 461, 47, 471];
    $commonViewMenus = [9, 91, 911, 10, 100, 1000, 10001, 20, 200, 2000, 20001, 20002];
    $allMenuIds = array_map(static fn (array $menu): int => (int) $menu[0], $menus);
    $roleMenuIds = [
        1 => $allMenuIds,
        2 => $allMenuIds,
        3 => array_merge($internshipAdminMenus, $trainingMenus, $labMenus, $statMenus, $commonViewMenus),
        4 => array_merge($internshipAdminMenus, $trainingMenus, $labMenus, $statMenus, $commonViewMenus),
        5 => array_merge([1, 11, 111, 12, 121, 104, 1213, 13, 131, 14, 141, 105, 15, 151, 106, 1512, 16, 161, 107, 1612, 17, 171, 108, 195, 1951, 19512, 2, 21, 211, 201, 3, 31, 311, 301], $commonViewMenus),
        6 => array_merge([1, 11, 111, 12, 121, 103, 14, 141, 105, 15, 151, 106, 16, 161, 107, 195, 1951, 19511], $commonViewMenus),
        7 => array_merge([1, 17, 171, 108], $commonViewMenus),
    ];

    foreach ($roleMenuIds as $roleId => $menuIds) {
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        foreach ($menuIds as $menuId) {
            $roleMenu->execute([$roleId, $menuId]);
        }
        syncSeedRoleMenus($pdo, (int) $roleId, $menuIds);
    }
}

function syncSeedRoleMenus(PDO $pdo, int $roleId, array $menuIds): void
{
    if (!$menuIds) {
        $pdo->prepare(
            "UPDATE `role_menu`
             SET `deleted_at` = NOW(), `updated_at` = NOW()
             WHERE `role_id` = ? AND `deleted_at` IS NULL"
        )->execute([$roleId]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
    $stmt = $pdo->prepare(
        "UPDATE `role_menu`
         SET `deleted_at` = NOW(), `updated_at` = NOW()
         WHERE `role_id` = ? AND `deleted_at` IS NULL AND `menu_id` NOT IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$roleId], $menuIds));
}

function seedCommonSupportData(PDO $pdo): void
{
    seedDocCenterData($pdo);
    seedTemplateLibraryData($pdo);
}

function seedDocCenterData(PDO $pdo): void
{
    $categoryStmt = $pdo->prepare(
        "INSERT INTO `doc_category` (`id`, `uuid`, `parent_id`, `code`, `name`, `icon`, `sort`, `status`)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `parent_id` = VALUES(`parent_id`),
            `code` = VALUES(`code`),
            `name` = VALUES(`name`),
            `icon` = VALUES(`icon`),
            `sort` = VALUES(`sort`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    $categories = [
        [1, '00000000-0000-0000-0000-000000210001', 0, 'practice_flow', '实践流程', 'Route', 10],
        [2, '00000000-0000-0000-0000-000000210002', 0, 'student_help', '学生帮助', 'UserRound', 20],
        [3, '00000000-0000-0000-0000-000000210003', 0, 'teacher_help', '教师帮助', 'UsersRound', 30],
        [4, '00000000-0000-0000-0000-000000210004', 0, 'admin_help', '管理员帮助', 'ShieldCheck', 40],
    ];
    foreach ($categories as $category) {
        $categoryStmt->execute($category);
    }

    $articleStmt = $pdo->prepare(
        "INSERT INTO `doc_article` (`id`, `uuid`, `category_id`, `title`, `name`, `content`, `version`, `author_id`, `view_count`, `published_at`, `status`)
         VALUES (?, ?, ?, ?, ?, ?, '1.0', 1, 0, NOW(), 'published')
         ON DUPLICATE KEY UPDATE
            `category_id` = VALUES(`category_id`),
            `title` = VALUES(`title`),
            `name` = VALUES(`name`),
            `content` = VALUES(`content`),
            `version` = VALUES(`version`),
            `published_at` = COALESCE(`published_at`, NOW()),
            `status` = 'published',
            `deleted_at` = NULL"
    );

    $articles = [
        [
            1,
            '00000000-0000-0000-0000-000000220001',
            1,
            '高校实习实践基础流程',
            '<h3>流程</h3><p>管理员维护届次、学院、专业、班级、企业、实习计划、实习任务和任务绑定；学生按已绑定任务提交补充申请、签到、日志、报告和延期申请；任务老师按任务处理审核、评阅和成绩；学校管理员查看统计、归档材料和审计日志。</p><h3>注意</h3><p>实习主链路以计划、任务、班级学生绑定为边界，补充申请不生成任务绑定。</p>',
        ],
        [
            2,
            '00000000-0000-0000-0000-000000220002',
            2,
            '学生端提交说明',
            '<h3>学生流程</h3><p>学生端主要完成自己已绑定任务下的补充申请、签到、日志、报告、延期申请和流程记录查看。状态为需修改时，应进入对应记录重新编辑并提交。</p><h3>常见问题</h3><p>看不到列表筛选属于正常体验，学生仅查看本人的数据。</p>',
        ],
        [
            3,
            '00000000-0000-0000-0000-000000220003',
            3,
            '教师端审核说明',
            '<h3>教师流程</h3><p>任务老师根据任务绑定查看学生过程材料。待审核状态可通过或退回，已通过状态只能发起通过后修改，退回后需等待学生重新提交。</p>',
        ],
        [
            4,
            '00000000-0000-0000-0000-000000220004',
            4,
            '管理员基础维护说明',
            '<h3>维护顺序</h3><p>建议先维护届次，再维护学院、专业、班级、用户、角色权限、组织范围，最后配置实习计划、实习任务和业务规则。</p><h3>数据范围</h3><p>学院管理员默认按学院范围，专业管理员默认按专业范围，任务老师按任务绑定学生范围查看数据。</p>',
        ],
    ];
    foreach ($articles as $article) {
        [$id, $uuid, $categoryId, $title, $content] = $article;
        $articleStmt->execute([$id, $uuid, $categoryId, $title, $title, $content]);
    }
}

function seedTemplateLibraryData(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO `template_category` (`id`, `uuid`, `code`, `name`, `description`, `sort`, `flag`, `status`)
         VALUES (?, ?, ?, ?, ?, ?, 'on', 'enabled')
         ON DUPLICATE KEY UPDATE
            `code` = VALUES(`code`),
            `name` = VALUES(`name`),
            `description` = VALUES(`description`),
            `sort` = VALUES(`sort`),
            `flag` = 'on',
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    $categories = [
        [1, '00000000-0000-0000-0000-000000230001', 'internship_requirement', '实习要求模板', '实习安排、实习要求、过程约束类模板。', 10],
        [2, '00000000-0000-0000-0000-000000230002', 'safety_letter', '安全责任书', '学生安全承诺、安全告知相关模板。', 20],
        [3, '00000000-0000-0000-0000-000000230003', 'position_cert', '岗位证明模板', '企业岗位证明、在岗证明材料模板。', 30],
        [4, '00000000-0000-0000-0000-000000230004', 'guardian_consent', '监护人知情同意书', '监护人告知和知情同意材料模板。', 40],
        [5, '00000000-0000-0000-0000-000000230005', 'tripartite_agreement', '三方协议模板库', '学校、学生、企业三方协议相关模板。', 50],
        [6, '00000000-0000-0000-0000-000000230006', 'process_document', '过程文档模板库', '签到、日志、周志、过程检查材料模板。', 60],
        [7, '00000000-0000-0000-0000-000000230007', 'report_template_lib', '实习报告模板库', '实习报告、总结、鉴定类模板。', 70],
    ];
    foreach ($categories as $category) {
        $stmt->execute($category);
    }
}

function seedOperationGuides(PDO $pdo): void
{
    $guides = [
        ['internship', '实习管理操作说明', '实习管理围绕实习计划、实习任务、任务绑定、补充申请、签到、日志、报告、成绩和归档材料进行全过程留痕。', '管理员按计划拆分任务并绑定班级，系统展开学生生成任务绑定；学生按任务完成过程材料，任务老师按任务审核评阅，学校管理员按学院、专业、届次查看整体进度。', '学生看不到列表筛选时，先确认当前账号是否为学生角色；教师看不到学生时，检查任务绑定和组织范围；审核退回后学生重新提交会形成新的记录。', 10],
        ['training', '实训管理操作说明', '实训管理围绕教学计划、课表安排、大纲、教案、成绩评定和反思报告进行维护。', '教学计划可由教务拉取或教师填报，经过系主任、学院教务科、学院主管院长、教务处和教务处领导等节点审核；课表安排区分校内实训室和校外基地，并支持签到册打印记录；大纲、教案和反思报告由任课教师提交后按学院流程审核。', '待审核数据才能通过或退回；已通过数据只能发起通过后修改；学生端主要查看本人课表、成绩和可提交材料。', 20],
        ['lab', '实验管理操作说明', '实验管理围绕教学计划、课表安排、大纲、教案、成绩评定和反思报告进行维护。', '教学计划可由教务拉取或教师填报，课表安排维护实验室、时间地点和学生范围；大纲、教案由任课教师编写后进入系主任和学院分管院长审核；成绩评定包含比例设定、成绩录入、成绩提交和成绩统计。', '实验室、课程、项目和课表数据要先维护基础信息；待审核数据才能处理，退回后需重新提交形成新记录。', 30],
        ['stat', '统计报表操作说明', '统计报表按当前角色的数据范围展示实习总览、学院统计、专业统计、任务老师统计、学生过程统计和归档材料统计。', '选择左侧报表菜单后，通过届次、学院、专业和关键词筛选数据；切换报表菜单可查看不同统计口径的数据明细。', '如果统计值与列表不一致，优先确认当前角色的数据范围、筛选条件和业务数据是否已刷新。', 40],
        ['log', '日志审计操作说明', '日志审计读取当前学校业务库下所有 operation_log 按月分表，支持关键词、动作、IP 和日期范围查询。', '管理员进入日志审计后先设置查询条件，再查看来源分表、操作账号、动作、IP 和日志内容。', '如果日志为空，检查当前月份日志分表是否存在，以及账号是否具备日志查看权限。', 50],
        ['file', '文件管理操作说明', '文件管理用于查看学校业务库内的上传文件、上传人、上传时间、设备信息和文件状态。', '通过关键词、状态和分类定位文件，点击打开可查看文件访问地址。', '如果文件打不开，检查文件状态、存储配置和浏览器访问权限。', 60],
        ['config', '系统配置操作说明', '系统配置维护菜单权限、角色权限、组织范围、基础档案、操作说明和企业微信应用配置。', '菜单树按主菜单、业务菜单、列表、按钮维护；角色授权按树勾选；组织范围用于限制学院、专业、班级、企业等数据边界。', '如果授权后没有生效，刷新权限或重新登录；如果菜单结构异常，先检查父级是否选择为按钮节点。', 70],
        ['profile', '个人设置操作说明', '个人设置用于维护头像资料、桌面壁纸和消息接收偏好。', '点击头像或壁纸区域上传文件，也可以在桌面右键进入壁纸设置。', '如果壁纸没有立即变化，检查浏览器缓存和上传结果，必要时重新保存个人设置。', 80],
        ['doc', '文档中心操作说明', '文档中心用于维护学校实践制度、操作流程、常见问题和角色帮助文档。', '学校管理员维护分类和文档，教师、学生和其他角色按权限查看已发布文档。', '如果用户看不到文档，检查文档状态是否发布，以及角色是否具备文档查看权限。', 90],
        ['templateLib', '模板库操作说明', '模板库用于维护实习实践材料模板，支持按分类查看、上传、下载和维护版本。', '学校管理员上传模板文件并选择分类，师生进入模板库下载对应材料。', '模板列表没有文件时，说明分类已创建但尚未上传真实模板。', 100],
        ['exportTask', '导出任务操作说明', '导出任务中心记录后续列表导出的任务状态、进度、文件和失败原因。', '用户创建导出任务后在任务中心查看状态；失败或超时任务可重新排队。', '当前任务中心先提供统一记录能力，具体业务列表的真实异步导出会在对应模块接入。', 110],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO `operation_guide` (`module_key`, `title`, `content`, `sort`, `status`)
         VALUES (?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `title` = VALUES(`title`),
            `sort` = VALUES(`sort`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    foreach ($guides as [$module, $title, $flow, $process, $faq, $sort]) {
        $stmt->execute([
            $module,
            $title,
            guideContent($flow, $process, $faq),
            $sort,
        ]);
    }
}

function guideContent(string $flow, string $process, string $faq): string
{
    return '<section><h3>操作流程</h3><p>' . htmlspecialchars($flow, ENT_QUOTES, 'UTF-8') . '</p></section>'
        . '<section><h3>流程说明</h3><p>' . htmlspecialchars($process, ENT_QUOTES, 'UTF-8') . '</p></section>'
        . '<section><h3>常见问题</h3><p>' . htmlspecialchars($faq, ENT_QUOTES, 'UTF-8') . '</p></section>';
}

function seedConfig(PDO $pdo, string $wechatProxyUrl): void
{
    $groups = [
        [1, 0, 'system', '系统配置', 10],
        [2, 0, 'internship', '实习管理', 20],
        [3, 0, 'training', '实训管理', 30],
        [4, 0, 'lab', '实验管理', 40],
        [5, 0, 'wechat', '企业微信', 50],
        [6, 0, 'file', '文件管理', 60],
    ];

    $groupStmt = $pdo->prepare(
        "INSERT INTO `config_group` (`id`, `parent_id`, `code`, `name`, `sort`, `status`)
         VALUES (?, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort` = VALUES(`sort`), `status` = 'enabled'"
    );
    foreach ($groups as $group) {
        $groupStmt->execute($group);
    }

    $items = [
        [1, 'login_background_url', '', 'PC 登录页学校背景图', 10],
        [2, 'sign_in_radius', 500, '学生 GPS 签到时允许的最大距离，单位米', 10],
        [2, 'pair_mode', 'admin_assign', '实习任务绑定模式', 20],
        [2, 'max_student_count', 20, '任务老师默认负责学生数上限', 30],
        [3, 'booking_max_days', 14, '实训室最长可预约天数', 10],
        [4, 'booking_max_days', 14, '实验室最长可预约天数', 10],
        [5, 'app_id', '', '企业微信应用 AppID，可用于第三方应用或自建应用标识', 5],
        [5, 'corp_id', '', '企业微信企业 ID', 10],
        [5, 'agent_id', '', '企业微信自建应用 AgentId', 15],
        [5, 'secret', '', '企业微信应用 Secret', 20],
        [5, 'token', '', '企业微信回调 Token', 25],
        [5, 'encoding_aes_key', '', '企业微信回调 EncodingAESKey', 30],
        [5, 'proxy_url', $wechatProxyUrl, '本地转发企业微信 API 的代理地址，空值表示直连企业微信', 35],
        [5, 'proxy_enabled', $wechatProxyUrl !== '', '是否启用企业微信代理地址', 40],
        [5, 'menu_json', '[]', '企业微信应用菜单 JSON', 45],
        [6, 'instant_upload_enabled', false, '是否启用文件秒传', 10],
        [6, 'block', 'b1', '文件存储块标识', 20],
        [6, 'school_code', '2184', '默认学校文件隔离标识', 30],
    ];

    $itemStmt = $pdo->prepare(
        "INSERT INTO `config_item` (`group_id`, `key`, `value`, `college_id`, `user_id`, `description`, `sort`, `status`)
         VALUES (?, ?, ?, 0, 0, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `value` = VALUES(`value`),
            `description` = VALUES(`description`),
            `sort` = VALUES(`sort`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );

    foreach ($items as [$groupId, $key, $value, $description, $sort]) {
        $itemStmt->execute([$groupId, $key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $description, $sort]);
    }
}
