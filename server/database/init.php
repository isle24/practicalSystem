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
    ensureColumn($pdo, 'account', 'login_name', "ALTER TABLE `account` ADD COLUMN `login_name` VARCHAR(80) DEFAULT NULL AFTER `user_id`");
    ensureIndex($pdo, 'account', 'uk_login_name', "ALTER TABLE `account` ADD UNIQUE KEY `uk_login_name` (`login_name`)");
    ensureFileSchema($pdo);
    ensureInternshipSchema($pdo);
}

function ensureMenuSchema(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE `menu` MODIFY COLUMN `type` ENUM('directory','menu','list','button') DEFAULT 'menu'");
}

function schoolCoreStatements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `department` (
            `dep_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dep_uuid` CHAR(36) DEFAULT NULL,
            `dep_name` VARCHAR(120) NOT NULL,
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
            UNIQUE KEY `uk_menu_code` (`code`),
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
        simpleTable('arrangement', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`profession_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL', '`start_date` DATE DEFAULT NULL', '`end_date` DATE DEFAULT NULL']),
        simpleTable('application', ['`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('application_recording', recordingColumns()),
        simpleTable('student_join_teacher', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('join_recording', recordingColumns()),
        simpleTable('pair', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`type` ENUM(\'internship\',\'training\',\'lab\') DEFAULT \'internship\'', '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`entity_type` VARCHAR(40) DEFAULT NULL', '`entity_id` BIGINT UNSIGNED DEFAULT NULL', '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'active\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED', 'UNIQUE KEY `uk_pair_active` (`student_id`, `type`, `arrangement_id`, `active_flag`)']),
        simpleTable('sign_in', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`sign_time` DATETIME DEFAULT NULL', '`longitude` DECIMAL(10,6) DEFAULT NULL', '`latitude` DECIMAL(10,6) DEFAULT NULL'])),
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
        simpleTable('internship_plan', ['`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL', '`plan_json` JSON DEFAULT NULL']),
        simpleTable('internship_plan_approval', ['`plan_id` BIGINT UNSIGNED DEFAULT NULL', '`reviewer_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('plan_recording', recordingColumns()),
        simpleTable('insurance', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`company_id` BIGINT UNSIGNED DEFAULT NULL', '`policy_no` VARCHAR(120) DEFAULT NULL']),
        simpleTable('safety_letter_sign', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`signed_at` DATETIME DEFAULT NULL']),
        simpleTable('syllabus_guide', ['`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`file_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('implementation_sheet', ['`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`sheet_json` JSON DEFAULT NULL']),
        simpleTable('teacher_work_report', ['`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL']),
        simpleTable('inspection_record', ['`inspector_id` BIGINT UNSIGNED DEFAULT NULL', '`entity_type` VARCHAR(40) DEFAULT NULL', '`entity_id` BIGINT UNSIGNED DEFAULT NULL']),
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

    foreach (['message', 'message_target', 'message_template', 'message_channel_log', 'operation_log_template', 'stat_cache', 'export_task', 'doc_category', 'doc_article', 'doc_article_history', 'template_category', 'template'] as $table) {
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
            'base_id' => "ALTER TABLE `arrangement` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'dep_id' => "ALTER TABLE `arrangement` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'profession_id' => "ALTER TABLE `arrangement` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'semester' => "ALTER TABLE `arrangement` ADD COLUMN `semester` VARCHAR(80) DEFAULT NULL AFTER `profession_id`",
            'type' => "ALTER TABLE `arrangement` ADD COLUMN `type` VARCHAR(40) DEFAULT 'major_external' AFTER `semester`",
            'organize_mode' => "ALTER TABLE `arrangement` ADD COLUMN `organize_mode` VARCHAR(40) DEFAULT 'centralized' AFTER `type`",
            'title' => "ALTER TABLE `arrangement` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `organize_mode`",
            'start_date' => "ALTER TABLE `arrangement` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `title`",
            'end_date' => "ALTER TABLE `arrangement` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`",
            'location' => "ALTER TABLE `arrangement` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `end_date`",
            'description' => "ALTER TABLE `arrangement` ADD COLUMN `description` TEXT DEFAULT NULL AFTER `location`",
            'created_by' => "ALTER TABLE `arrangement` ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `description`",
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
            'dep_id' => "ALTER TABLE `internship_plan` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'semester' => "ALTER TABLE `internship_plan` ADD COLUMN `semester` VARCHAR(80) DEFAULT NULL AFTER `dep_id`",
            'plan_content' => "ALTER TABLE `internship_plan` ADD COLUMN `plan_content` JSON DEFAULT NULL AFTER `semester`",
            'submitter_id' => "ALTER TABLE `internship_plan` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_content`",
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

    foreach (['application_recording', 'journal_recording', 'report_recording', 'join_recording', 'apply_report_delay_recording', 'plan_recording'] as $table) {
        ensureColumn($pdo, $table, 'parent_id', "ALTER TABLE `{$table}` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`");
        ensureColumn($pdo, $table, 'action', "ALTER TABLE `{$table}` ADD COLUMN `action` VARCHAR(40) DEFAULT NULL AFTER `parent_id`");
        ensureColumn($pdo, $table, 'operator_id', "ALTER TABLE `{$table}` ADD COLUMN `operator_id` BIGINT UNSIGNED DEFAULT NULL AFTER `action`");
        ensureColumn($pdo, $table, 'content', "ALTER TABLE `{$table}` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `operator_id`");
        ensureIndex($pdo, $table, 'idx_parent', "ALTER TABLE `{$table}` ADD KEY `idx_parent` (`parent_id`)");
    }

    ensureIndex($pdo, 'base_profession_direction', 'uk_base_profession_direction', "ALTER TABLE `base_profession_direction` ADD UNIQUE KEY `uk_base_profession_direction` (`base_id`, `profession_id`, `direction_id`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_scope', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_scope` (`dep_id`, `profession_id`, `status`)");
    ensureIndex($pdo, 'application', 'idx_application_student', "ALTER TABLE `application` ADD KEY `idx_application_student` (`student_id`, `arrangement_id`, `status`)");
    ensureIndex($pdo, 'student_join_teacher', 'idx_join_application', "ALTER TABLE `student_join_teacher` ADD KEY `idx_join_application` (`application_id`, `application_status`)");
    ensureIndex($pdo, 'pair', 'idx_pair_teacher', "ALTER TABLE `pair` ADD KEY `idx_pair_teacher` (`teacher_id`, `type`, `status`)");
    ensureIndex($pdo, 'pair', 'idx_pair_student', "ALTER TABLE `pair` ADD KEY `idx_pair_student` (`student_id`, `type`, `status`)");
    ensureIndex($pdo, 'sign_in', 'idx_sign_student_date', "ALTER TABLE `sign_in` ADD KEY `idx_sign_student_date` (`student_id`, `entity_type`, `entity_id`, `date`)");
    ensureIndex($pdo, 'journal', 'idx_journal_student_date', "ALTER TABLE `journal` ADD KEY `idx_journal_student_date` (`student_id`, `entity_type`, `entity_id`, `date`)");
    ensureIndex($pdo, 'report', 'idx_report_student_arrangement', "ALTER TABLE `report` ADD KEY `idx_report_student_arrangement` (`student_id`, `arrangement_id`, `status`)");
    ensureIndex($pdo, 'score', 'idx_score_student_arrangement', "ALTER TABLE `score` ADD KEY `idx_score_student_arrangement` (`student_id`, `arrangement_id`)");
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
        "INSERT INTO `arrangement` (`id`, `uuid`, `name`, `base_id`, `dep_id`, `profession_id`, `semester`, `type`, `organize_mode`, `title`, `start_date`, `end_date`, `location`, `description`, `created_by`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000100001', '软件技术专业实习安排', 1, 1, 1, '2025-2026-2', 'major_external', 'centralized', '软件技术专业校外实习', '2026-07-01', '2026-08-31', '成都锦城学院实践基地', '默认开发环境实习安排', 1, 'enabled')
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `base_id` = VALUES(`base_id`),
            `dep_id` = VALUES(`dep_id`),
            `profession_id` = VALUES(`profession_id`),
            `semester` = VALUES(`semester`),
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
        [1, '00000000-0000-0000-0000-000000010001', '信息工程学院', 'D001', 10],
        [2, '00000000-0000-0000-0000-000000010002', '经济管理学院', 'D002', 20],
    ];
    $departmentStmt = $pdo->prepare(
        "INSERT INTO `department` (`dep_id`, `dep_uuid`, `dep_name`, `dep_code`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE `dep_name` = VALUES(`dep_name`), `dep_code` = VALUES(`dep_code`), `sort` = VALUES(`sort`), `flag` = 'on'"
    );
    foreach ($departments as $department) {
        $departmentStmt->execute($department);
    }

    $grades = [
        [1, '00000000-0000-0000-0000-000000020001', '2026级', 1, 10],
        [2, '00000000-0000-0000-0000-000000020002', '2026级', 2, 20],
    ];
    $gradeStmt = $pdo->prepare(
        "INSERT INTO `grade_list` (`grade_id`, `grade_uuid`, `grade_name`, `dep_id`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE `grade_name` = VALUES(`grade_name`), `dep_id` = VALUES(`dep_id`), `sort` = VALUES(`sort`), `flag` = 'on'"
    );
    foreach ($grades as $grade) {
        $gradeStmt->execute($grade);
    }

    $professions = [
        [1, '00000000-0000-0000-0000-000000030001', '软件技术', 'P001', 1, 1, 10],
        [2, '00000000-0000-0000-0000-000000030002', '大数据技术', 'P002', 1, 1, 20],
        [3, '00000000-0000-0000-0000-000000030003', '电子商务', 'P003', 2, 2, 30],
    ];
    $professionStmt = $pdo->prepare(
        "INSERT INTO `profession` (`profession_id`, `profession_uuid`, `profession_name`, `profession_code`, `dep_id`, `grade_id`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE
            `profession_name` = VALUES(`profession_name`),
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
        [1, '00000000-0000-0000-0000-000000040001', '软件技术2601', 'RJ2601', 1, 1, 1, 10],
        [2, '00000000-0000-0000-0000-000000040002', '大数据2601', 'DS2601', 1, 2, 1, 20],
        [3, '00000000-0000-0000-0000-000000040003', '电子商务2601', 'EC2601', 2, 3, 2, 30],
    ];
    $classStmt = $pdo->prepare(
        "INSERT INTO `class` (`class_id`, `class_uuid`, `class_name`, `class_num`, `dep_id`, `profession_id`, `grade_id`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE
            `class_name` = VALUES(`class_name`),
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
        [12, 1, '实习申请', null, null, 'both', 'menu', 12, 'ClipboardList'],
        [121, 12, '列表', 'internship:application:list', '/internship/applications', 'both', 'list', 121, 'List'],
        [103, 121, '提交', 'internship:apply', null, 'both', 'button', 103, null],
        [104, 121, '审核', 'internship:approve', null, 'both', 'button', 104, null],
        [1213, 121, '通过后修改', 'internship:application:reopen', null, 'pc', 'button', 123, null],
        [13, 1, '指导关系', null, null, 'pc', 'menu', 13, 'UsersRound'],
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
        [21, 2, '实训项目', null, null, 'both', 'menu', 21, 'Workflow'],
        [211, 21, '列表', 'training:view', '/training', 'both', 'list', 211, 'List'],
        [201, 211, '处理', 'training:manage', null, 'both', 'button', 201, null],
        [2112, 211, '删除', 'training:project:delete', null, 'pc', 'button', 212, null],
        [3, 0, '实验管理', null, null, 'both', 'directory', 30, 'FlaskConical'],
        [31, 3, '实验项目', null, null, 'both', 'menu', 31, 'FlaskConical'],
        [311, 31, '列表', 'lab:view', '/lab', 'both', 'list', 311, 'List'],
        [301, 311, '处理', 'lab:manage', null, 'both', 'button', 301, null],
        [3112, 311, '删除', 'lab:project:delete', null, 'pc', 'button', 312, null],
        [4, 0, '统计报表', null, null, 'pc', 'directory', 40, 'ChartColumn'],
        [41, 4, '实习统计', null, null, 'pc', 'menu', 41, 'ChartColumn'],
        [411, 41, '列表', 'stat:view', '/stat', 'pc', 'list', 411, 'List'],
        [402, 411, '处理', 'stat:manage', null, 'pc', 'button', 402, null],
        [42, 4, '学院统计', null, null, 'pc', 'menu', 42, 'Building2'],
        [421, 42, '列表', 'stat:department', '/stat/department', 'pc', 'list', 421, 'List'],
        [43, 4, '专业统计', null, null, 'pc', 'menu', 43, 'GraduationCap'],
        [431, 43, '列表', 'stat:profession', '/stat/profession', 'pc', 'list', 431, 'List'],
        [44, 4, '指导统计', null, null, 'pc', 'menu', 44, 'UsersRound'],
        [441, 44, '列表', 'stat:teacher', '/stat/teacher', 'pc', 'list', 441, 'List'],
        [45, 4, '学生过程统计', null, null, 'pc', 'menu', 45, 'UserRound'],
        [451, 45, '列表', 'stat:student', '/stat/student', 'pc', 'list', 451, 'List'],
        [46, 4, '归档材料统计', null, null, 'pc', 'menu', 46, 'FolderOpen'],
        [461, 46, '列表', 'stat:archive', '/stat/archive', 'pc', 'list', 461, 'List'],
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

    $dedupeCode = $pdo->prepare(
        "UPDATE `menu`
         SET `code` = NULL
         WHERE `code` = ? AND `id` <> ?"
    );

    foreach ($menus as $menu) {
        if ($menu[3] !== null) {
            $dedupeCode->execute([$menu[3], $menu[0]]);
        }
        $stmt->execute($menu);
    }

    $disabledMenuIds = [102, 202, 302, 401];
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
    $trainingMenus = [2, 21, 211, 201, 2112];
    $labMenus = [3, 31, 311, 301, 3112];
    $allMenuIds = array_map(static fn (array $menu): int => (int) $menu[0], $menus);
    $roleMenuIds = [
        1 => $allMenuIds,
        2 => $allMenuIds,
        3 => array_merge($internshipAdminMenus, $trainingMenus, $labMenus),
        4 => array_merge($internshipAdminMenus, $trainingMenus, $labMenus),
        5 => [1, 11, 111, 12, 121, 104, 1213, 13, 131, 14, 141, 105, 15, 151, 106, 1512, 16, 161, 107, 1612, 17, 171, 108, 195, 1951, 19512, 2, 21, 211, 201, 3, 31, 311, 301],
        6 => [1, 11, 111, 12, 121, 103, 14, 141, 105, 15, 151, 106, 16, 161, 107, 195, 1951, 19511],
        7 => [1, 17, 171, 108],
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

function seedOperationGuides(PDO $pdo): void
{
    $guides = [
        ['internship', '实习管理操作说明', '实习管理围绕实习安排、学生申请、签到、日志、报告、成绩和归档材料进行全过程留痕。', '学生提交申请并完成过程材料，教师按指导关系审核评阅，学校管理员按学院、专业、届次查看整体进度。', '学生看不到列表筛选时，先确认当前账号是否为学生角色；教师看不到学生时，检查指导关系和组织范围；审核退回后学生重新提交会形成新的记录。', 10],
        ['training', '实训管理操作说明', '实训流程尚未最终确认，当前先保留菜单、权限和基础框架。', '后续确认实训项目、预约、材料、报告、成绩等流程后，在该模块补充列表、提交和审核功能。', '如果页面暂无业务数据，属于流程待确认状态；权限可先在系统配置中完成角色授权。', 20],
        ['lab', '实验管理操作说明', '实验流程尚未最终确认，当前先保留菜单、权限和基础框架。', '后续确认实验课程、项目、预约、材料、报告、成绩等流程后，在该模块补充业务页面。', '如果页面暂无业务数据，属于流程待确认状态；权限可先在系统配置中完成角色授权。', 30],
        ['stat', '统计报表操作说明', '统计报表按当前角色的数据范围展示实习总览、学院统计、专业统计、指导统计、学生过程统计和归档材料统计。', '选择左侧报表菜单后，通过届次、学院、专业和关键词筛选数据；切换报表菜单可查看不同统计口径的数据明细。', '如果统计值与列表不一致，优先确认当前角色的数据范围、筛选条件和业务数据是否已刷新。', 40],
        ['log', '日志审计操作说明', '日志审计读取当前学校业务库下所有 operation_log 按月分表，支持关键词、动作、IP 和日期范围查询。', '管理员进入日志审计后先设置查询条件，再查看来源分表、操作账号、动作、IP 和日志内容。', '如果日志为空，检查当前月份日志分表是否存在，以及账号是否具备日志查看权限。', 50],
        ['file', '文件管理操作说明', '文件管理用于查看学校业务库内的上传文件、上传人、上传时间、设备信息和文件状态。', '通过关键词、状态和分类定位文件，点击打开可查看文件访问地址。', '如果文件打不开，检查文件状态、存储配置和浏览器访问权限。', 60],
        ['config', '系统配置操作说明', '系统配置维护菜单权限、角色权限、组织范围、基础档案、操作说明和企业微信应用配置。', '菜单树按主菜单、业务菜单、列表、按钮维护；角色授权按树勾选；组织范围用于限制学院、专业、班级、企业等数据边界。', '如果授权后没有生效，刷新权限或重新登录；如果菜单结构异常，先检查父级是否选择为按钮节点。', 70],
        ['profile', '个人设置操作说明', '个人设置用于维护头像资料、桌面壁纸和消息接收偏好。', '点击头像或壁纸区域上传文件，也可以在桌面右键进入壁纸设置。', '如果壁纸没有立即变化，检查浏览器缓存和上传结果，必要时重新保存个人设置。', 80],
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
        [2, 'pair_mode', 'admin_assign', '实习配对模式', 20],
        [2, 'max_student_count', 20, '教师默认最大指导学生数', 30],
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
