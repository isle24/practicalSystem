<?php

use app\model\channel\AssistantProfile;
use app\model\channel\AuthPasskey;
use app\model\channel\DesktopToolsSchema;
use app\model\channel\DocRecord;
use app\model\channel\MessageRecord;
use app\model\channel\PluginRecord;
use app\model\channel\TableRecord;
use app\server\edu\EduImportSchema;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

function initializeDatabases(): void
{
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
    $appUrl = (string) $env('APP_URL', '');
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
        'appDomain' => appDomain($appUrl),
    ]);

    $schoolTargets = [[
        'database_id' => null,
        'database_host' => $host,
        'database_port' => $port,
        'database_user' => $user,
        'database_pwd' => $pass,
        'database_db' => $templateDb,
        'database_charset' => $charset,
        'database_prefix' => '',
        'is_default_business_db' => 'false',
    ]];
    $schoolTargets = array_merge($schoolTargets, enabledSchoolDatabaseTargets($master));
    $upgradedTargets = [];
    foreach ($schoolTargets as $target) {
        $targetId = isset($target['database_id']) ? (int) $target['database_id'] : null;
        $targetHost = trim((string) ($target['database_host'] ?? ''));
        $targetPort = (int) ($target['database_port'] ?? 3306);
        $targetUser = (string) ($target['database_user'] ?? '');
        $targetPass = (string) ($target['database_pwd'] ?? '');
        $targetDb = trim((string) ($target['database_db'] ?? ''));
        $targetCharset = trim((string) ($target['database_charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
        $targetPrefix = trim((string) ($target['database_prefix'] ?? ''));
        $isDefaultBusinessDb = (string) ($target['is_default_business_db'] ?? 'false') === 'true';
        $label = $targetId === null
            ? "模板库升级失败：database_db={$targetDb}"
            : "学校业务库升级失败：database_id={$targetId}, database_db={$targetDb}";
        if ($targetHost === '' || $targetPort < 1 || $targetPort > 65535 || $targetUser === '' || $targetDb === '') {
            throw new RuntimeException($label . '：连接配置不完整');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $targetCharset)) {
            throw new RuntimeException($label . '：database_charset 格式无效');
        }
        if ($targetPrefix !== '') {
            throw new RuntimeException($label . "：当前初始化程序不支持带表前缀的学校业务库（database_prefix={$targetPrefix}）");
        }
        $targetKey = hash('sha256', strtolower($targetHost) . "\0" . $targetPort . "\0" . $targetDb);
        if (isset($upgradedTargets[$targetKey])) {
            continue;
        }

        try {
            $school = databasePdo($targetHost, $targetPort, $targetUser, $targetPass, $targetDb, $targetCharset);
            createSchoolSchema($school);
            if ($targetId === null || $isDefaultBusinessDb || $targetDb === $defaultSchoolDb) {
                seedSchool($school, $wechatProxyUrl);
            }
        } catch (Throwable $exception) {
            $detail = sanitizeDatabaseError($exception->getMessage(), $targetPass);
            throw new RuntimeException($detail === '' ? $label : $label . '：' . $detail);
        }

        $upgradedTargets[$targetKey] = true;
    }

    echo "database initialized\n";
    echo "master={$masterDb}\n";
    echo "template={$templateDb}\n";
    echo "default_school={$defaultSchoolDb}\n";
}

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

/** 查询主库登记的全部启用学校业务库 */
function enabledSchoolDatabaseTargets(PDO $master): array
{
    return $master->query(
        "SELECT `database_id`, `database_host`, `database_port`, `database_user`, `database_pwd`,
                `database_db`, `database_charset`, `database_prefix`, `is_default_business_db`
         FROM `databases`
         WHERE `status` = 'enabled'
         ORDER BY `database_id`"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** 清理数据库升级错误中的连接密码 */
function sanitizeDatabaseError(string $message, string $password): string
{
    $message = trim($message);
    return $password === '' ? $message : str_replace($password, '******', $message);
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

/** 检测重复值后创建单列唯一索引 */
function ensureUniqueIndex(PDO $pdo, string $table, string $index, string $column, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX'
    );
    $stmt->execute([$table, $index]);
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($existing) {
        if (array_filter($existing, static fn (array $row): bool => (int) $row['NON_UNIQUE'] !== 0)) {
            throw new RuntimeException("索引 {$index} 已存在但不是唯一索引");
        }
        $columns = array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $existing);
        if ($columns !== [$column]) {
            throw new RuntimeException("唯一索引 {$index} 已存在但字段不匹配，请检查数据库结构");
        }
        return;
    }

    $tableName = quoteIdentifier($table);
    $columnName = quoteIdentifier($column);
    $duplicate = $pdo->query(
        "SELECT {$columnName} AS duplicate_value, COUNT(*) AS duplicate_count
         FROM {$tableName}
         WHERE {$columnName} IS NOT NULL
         GROUP BY {$columnName}
         HAVING COUNT(*) > 1
         LIMIT 1"
    )->fetch();
    if ($duplicate) {
        $value = (string) ($duplicate['duplicate_value'] ?? '');
        $count = (int) ($duplicate['duplicate_count'] ?? 0);
        throw new RuntimeException("无法创建唯一索引 {$index}：{$table}.{$column} 值 '{$value}' 重复 {$count} 次，请先清理重复教师档案");
    }

    $pdo->exec($ddl);
}

function ensureIndexColumns(PDO $pdo, string $table, string $index, array $columns, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX'
    );
    $stmt->execute([$table, $index]);
    $current = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($current === $columns) {
        return;
    }

    if ($current) {
        $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
    $pdo->exec($ddl);
}

/** 收紧归档要求唯一索引，检测到历史重复配置时中止升级。 */
function ensureArchiveRequirementIndex(PDO $pdo): void
{
    $duplicate = $pdo->query(
        "SELECT `practice_type`, `material_type`, COUNT(*) AS `total`
         FROM `internship_archive_requirement`
         GROUP BY `practice_type`, `material_type`
         HAVING COUNT(*) > 1
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($duplicate) {
        throw new RuntimeException(
            '归档材料要求存在重复配置：'
            . (string) $duplicate['practice_type'] . '/'
            . (string) $duplicate['material_type']
        );
    }

    ensureIndexColumns(
        $pdo,
        'internship_archive_requirement',
        'uk_archive_requirement_type_material',
        ['practice_type', 'material_type'],
        "ALTER TABLE `internship_archive_requirement` ADD UNIQUE KEY `uk_archive_requirement_type_material` (`practice_type`, `material_type`)"
    );
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

    foreach (array_unique([$config['defaultDomain'], $config['appDomain'], 'localhost', '127.0.0.1']) as $domain) {
        if (!$domain) {
            continue;
        }
        $pdo->prepare(
            "INSERT INTO `authorizations` (`authorization_domain`, `school_id`, `database_id`, `status`)
             VALUES (?, 1, 1, 'enabled')
             ON DUPLICATE KEY UPDATE `school_id` = 1, `database_id` = 1, `status` = 'enabled'"
        )->execute([$domain]);
    }
}

function appDomain(string $appUrl): string
{
    $parts = parse_url($appUrl);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    return (string) $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
}

function createSchoolSchema(PDO $pdo, ?int $archiveYear = null): void
{
    $archiveYear ??= (int) date('Y');
    execSql($pdo, schoolCoreStatements());
    execSql($pdo, schoolBusinessStatements($archiveYear));
    EduImportSchema::ensure($pdo);

    foreach (DesktopToolsSchema::columnDefinitions() as $column => $ddl) {
        ensureColumn($pdo, 'favorite_link', $column, $ddl);
    }
    foreach (AssistantProfile::schemaStatements() as $statement) {
        if (preg_match('/^ALTER TABLE `(\w+)` ADD COLUMN `(\w+)`/', $statement, $match)) {
            ensureColumn($pdo, $match[1], $match[2], $statement);
        }
    }

    ensureMenuSchema($pdo);
    ensureArchiveSchema($pdo);
    ensureColumn($pdo, 'users', 'verified_mobile', "ALTER TABLE `users` ADD COLUMN `verified_mobile` VARCHAR(40) DEFAULT NULL AFTER `mobile`");
    ensureColumn($pdo, 'account', 'login_name', "ALTER TABLE `account` ADD COLUMN `login_name` VARCHAR(80) DEFAULT NULL AFTER `user_id`");
    ensureIndex($pdo, 'account', 'uk_login_name', "ALTER TABLE `account` ADD UNIQUE KEY `uk_login_name` (`login_name`)");
    ensureFileSchema($pdo);
    ensureInternshipSchema($pdo);
    ensurePracticeSchema($pdo);
    ensureSocialPracticeSchema($pdo);
    ensureMessageSchema($pdo);
    ensureDocSchema($pdo);
    ensureTemplateSchema($pdo);
    ensureExportTaskSchema($pdo);
    ensureRecordingArchiveSchema($pdo, $archiveYear);
    foreach (['created_at', 'account_id', 'action', 'ip'] as $column) {
        ensureIndex($pdo, 'operation_log_202606', 'idx_' . $column, "ALTER TABLE `operation_log_202606` ADD KEY `idx_{$column}` (`{$column}`)");
    }
}

function ensureRecordingArchiveSchema(PDO $pdo, int $year): void
{
    $table = 'recording_archive_' . max(2000, min(2999, $year));
    foreach ([
        'source_table' => "ALTER TABLE `{$table}` ADD COLUMN `source_table` VARCHAR(80) DEFAULT NULL AFTER `uuid`",
        'source_id' => "ALTER TABLE `{$table}` ADD COLUMN `source_id` BIGINT UNSIGNED DEFAULT NULL AFTER `source_table`",
        'parent_table' => "ALTER TABLE `{$table}` ADD COLUMN `parent_table` VARCHAR(80) DEFAULT NULL AFTER `source_id`",
        'parent_id' => "ALTER TABLE `{$table}` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT NULL AFTER `parent_table`",
        'grade_id' => "ALTER TABLE `{$table}` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `parent_id`",
        'action' => "ALTER TABLE `{$table}` ADD COLUMN `action` VARCHAR(40) DEFAULT NULL AFTER `entity_id`",
        'content' => "ALTER TABLE `{$table}` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `to_status`",
        'snapshot_data' => "ALTER TABLE `{$table}` ADD COLUMN `snapshot_data` JSON DEFAULT NULL AFTER `content`",
        'review_snapshot' => "ALTER TABLE `{$table}` ADD COLUMN `review_snapshot` JSON DEFAULT NULL AFTER `snapshot_data`",
        'original_created_at' => "ALTER TABLE `{$table}` ADD COLUMN `original_created_at` DATETIME DEFAULT NULL AFTER `review_snapshot`",
        'archived_at' => "ALTER TABLE `{$table}` ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `original_created_at`",
    ] as $column => $ddl) {
        ensureColumn($pdo, $table, $column, $ddl);
    }
    ensureIndex($pdo, $table, 'uk_source', "ALTER TABLE `{$table}` ADD UNIQUE KEY `uk_source` (`source_table`, `source_id`)");
    ensureIndex($pdo, $table, 'idx_parent', "ALTER TABLE `{$table}` ADD KEY `idx_parent` (`parent_table`, `parent_id`)");
    ensureIndex($pdo, $table, 'idx_grade', "ALTER TABLE `{$table}` ADD KEY `idx_grade` (`grade_id`)");
    ensureIndex($pdo, $table, 'idx_original_created', "ALTER TABLE `{$table}` ADD KEY `idx_original_created` (`original_created_at`)");
}

function ensureMenuSchema(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE `menu` MODIFY COLUMN `type` ENUM('directory','menu','list','button') DEFAULT 'menu'");
    ensureColumn($pdo, 'menu', 'is_module', "ALTER TABLE `menu` ADD COLUMN `is_module` ENUM('false','true') DEFAULT 'false' AFTER `icon`");
    ensureColumn($pdo, 'menu', 'module_key', "ALTER TABLE `menu` ADD COLUMN `module_key` VARCHAR(80) DEFAULT NULL AFTER `is_module`");
    ensureColumn($pdo, 'menu', 'icon_url', "ALTER TABLE `menu` ADD COLUMN `icon_url` VARCHAR(500) DEFAULT NULL AFTER `module_key`");
    ensureColumn($pdo, 'menu', 'icon_file_id', "ALTER TABLE `menu` ADD COLUMN `icon_file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `icon_url`");
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
    ensureColumn($pdo, 'grade_list', 'grade_code', "ALTER TABLE `grade_list` ADD COLUMN `grade_code` VARCHAR(80) DEFAULT NULL AFTER `grade_name`");
    ensureColumn($pdo, 'grade_list', 'is_current', "ALTER TABLE `grade_list` ADD COLUMN `is_current` ENUM('false','true') DEFAULT 'false' AFTER `grade_name`");
    ensureColumn($pdo, 'profession', 'profession_short_name', "ALTER TABLE `profession` ADD COLUMN `profession_short_name` VARCHAR(80) DEFAULT NULL AFTER `profession_name`");
    ensureColumn($pdo, 'class', 'class_short_name', "ALTER TABLE `class` ADD COLUMN `class_short_name` VARCHAR(80) DEFAULT NULL AFTER `class_name`");
    ensureColumn($pdo, 'students', 'graduation_cohort_id', "ALTER TABLE `students` ADD COLUMN `graduation_cohort_id` BIGINT UNSIGNED DEFAULT NULL AFTER `grade_id`");
    ensureIndex($pdo, 'students', 'idx_graduation_cohort', "ALTER TABLE `students` ADD KEY `idx_graduation_cohort` (`graduation_cohort_id`)");
    ensureIndex($pdo, 'grade_list', 'idx_grade_code', "ALTER TABLE `grade_list` ADD KEY `idx_grade_code` (`grade_code`, `flag`)");

    $teacherColumns = [
        'external_id' => "ALTER TABLE `teacher_list` ADD COLUMN `external_id` VARCHAR(120) DEFAULT NULL AFTER `teacher_uuid`",
        'gender' => "ALTER TABLE `teacher_list` ADD COLUMN `gender` VARCHAR(20) DEFAULT NULL AFTER `profession_id`",
        'birth_date' => "ALTER TABLE `teacher_list` ADD COLUMN `birth_date` DATE DEFAULT NULL AFTER `gender`",
        'title' => "ALTER TABLE `teacher_list` ADD COLUMN `title` VARCHAR(120) DEFAULT NULL AFTER `birth_date`",
        'education' => "ALTER TABLE `teacher_list` ADD COLUMN `education` VARCHAR(80) DEFAULT NULL AFTER `title`",
        'phone' => "ALTER TABLE `teacher_list` ADD COLUMN `phone` VARCHAR(40) DEFAULT NULL AFTER `education`",
        'email' => "ALTER TABLE `teacher_list` ADD COLUMN `email` VARCHAR(120) DEFAULT NULL AFTER `phone`",
        'employment_type' => "ALTER TABLE `teacher_list` ADD COLUMN `employment_type` VARCHAR(40) DEFAULT NULL AFTER `email`",
        'sync_source' => "ALTER TABLE `teacher_list` ADD COLUMN `sync_source` VARCHAR(40) DEFAULT NULL AFTER `employment_type`",
        'source_updated_at' => "ALTER TABLE `teacher_list` ADD COLUMN `source_updated_at` DATETIME DEFAULT NULL AFTER `sync_source`",
        'last_synced_at' => "ALTER TABLE `teacher_list` ADD COLUMN `last_synced_at` DATETIME DEFAULT NULL AFTER `source_updated_at`",
    ];
    foreach ($teacherColumns as $column => $ddl) {
        ensureColumn($pdo, 'teacher_list', $column, $ddl);
    }
    ensureIndex($pdo, 'teacher_list', 'idx_teacher_external', "ALTER TABLE `teacher_list` ADD KEY `idx_teacher_external` (`external_id`, `status`)");
    ensureIndex($pdo, 'teacher_list', 'idx_teacher_number', "ALTER TABLE `teacher_list` ADD KEY `idx_teacher_number` (`teacher_num`, `status`)");
    ensureUniqueIndex($pdo, 'teacher_list', 'uk_teacher_external_id', 'external_id', "ALTER TABLE `teacher_list` ADD UNIQUE KEY `uk_teacher_external_id` (`external_id`)");
    ensureUniqueIndex($pdo, 'teacher_list', 'uk_teacher_number', 'teacher_num', "ALTER TABLE `teacher_list` ADD UNIQUE KEY `uk_teacher_number` (`teacher_num`)");

    ensureColumn($pdo, 'companies', 'unit_type', "ALTER TABLE `companies` ADD COLUMN `unit_type` VARCHAR(80) DEFAULT NULL AFTER `address`");
    ensureColumn($pdo, 'companies', 'enterprise_level', "ALTER TABLE `companies` ADD COLUMN `enterprise_level` VARCHAR(120) DEFAULT NULL AFTER `unit_type`");
}

function schoolCoreStatements(): array
{
    return [
        AuthPasskey::creationStatement(),
        PluginRecord::creationStatement(),
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
            `grade_code` VARCHAR(80) DEFAULT NULL,
            `is_current` ENUM('false','true') DEFAULT 'false',
            `sort` INT DEFAULT 0,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`grade_id`),
            UNIQUE KEY `uk_grade_uuid` (`grade_uuid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `graduation_cohort` (
            `cohort_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cohort_uuid` CHAR(36) DEFAULT NULL,
            `cohort_name` VARCHAR(80) NOT NULL,
            `cohort_year` SMALLINT UNSIGNED NOT NULL,
            `is_current` ENUM('false','true') DEFAULT 'false',
            `sort` INT DEFAULT 0,
            `flag` ENUM('on','off') DEFAULT 'on',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED,
            PRIMARY KEY (`cohort_id`),
            UNIQUE KEY `uk_cohort_uuid` (`cohort_uuid`),
            UNIQUE KEY `uk_cohort_year_active` (`cohort_year`, `active_flag`),
            KEY `idx_cohort_current` (`is_current`, `flag`, `sort`)
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
            `unit_type` VARCHAR(80) DEFAULT NULL,
            `enterprise_level` VARCHAR(120) DEFAULT NULL,
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
            `verified_mobile` VARCHAR(40) DEFAULT NULL,
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
            `graduation_cohort_id` BIGINT UNSIGNED DEFAULT NULL,
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
            `external_id` VARCHAR(120) DEFAULT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `teacher_name` VARCHAR(80) NOT NULL,
            `teacher_num` VARCHAR(80) DEFAULT NULL,
            `dep_id` BIGINT UNSIGNED DEFAULT NULL,
            `profession_id` BIGINT UNSIGNED DEFAULT NULL,
            `gender` VARCHAR(20) DEFAULT NULL,
            `birth_date` DATE DEFAULT NULL,
            `title` VARCHAR(120) DEFAULT NULL,
            `education` VARCHAR(80) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `email` VARCHAR(120) DEFAULT NULL,
            `employment_type` VARCHAR(40) DEFAULT NULL,
            `sync_source` VARCHAR(40) DEFAULT NULL,
            `source_updated_at` DATETIME DEFAULT NULL,
            `last_synced_at` DATETIME DEFAULT NULL,
            `status` ENUM('enabled','disabled') DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`teacher_id`),
            UNIQUE KEY `uk_teacher_uuid` (`teacher_uuid`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_dep_id` (`dep_id`),
            KEY `idx_teacher_external` (`external_id`, `status`),
            KEY `idx_teacher_number` (`teacher_num`, `status`),
            UNIQUE KEY `uk_teacher_external_id` (`external_id`),
            UNIQUE KEY `uk_teacher_number` (`teacher_num`)
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
            `is_module` ENUM('false','true') DEFAULT 'false',
            `module_key` VARCHAR(80) DEFAULT NULL,
            `icon_url` VARCHAR(500) DEFAULT NULL,
            `icon_file_id` BIGINT UNSIGNED DEFAULT NULL,
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

function schoolBusinessStatements(?int $archiveYear = null): array
{
    $statements = [
        ...EduImportSchema::statements(),
        simpleTable('user_device', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`jti` VARCHAR(120) DEFAULT NULL', '`device_name` VARCHAR(120) DEFAULT NULL', '`ip` VARCHAR(80) DEFAULT NULL', '`user_agent` VARCHAR(255) DEFAULT NULL', '`last_active_at` DATETIME DEFAULT NULL']),
        simpleTable('user_notify_setting', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`msg_type` VARCHAR(80) DEFAULT NULL', '`channel` VARCHAR(80) DEFAULT NULL', '`enabled` ENUM(\'false\',\'true\') DEFAULT \'true\'', '`quiet_start` VARCHAR(5) NOT NULL DEFAULT \'00:00\'', '`quiet_end` VARCHAR(5) NOT NULL DEFAULT \'24:00\'']),
        simpleTable('user_desktop_config', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`layout_json` JSON DEFAULT NULL']),
        simpleTable('user_desktop_shortcut', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`item_type` VARCHAR(40) DEFAULT \'module\'', '`item_key` VARCHAR(120) DEFAULT NULL', '`ref_id` BIGINT UNSIGNED DEFAULT NULL', '`sort` INT DEFAULT 0', 'KEY `idx_account_type` (`account_id`, `item_type`)', 'KEY `idx_ref` (`ref_id`)']),
        simpleTable('data_cleanup_task', [
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            '`scope_json` JSON DEFAULT NULL',
            '`preserve_edu_data` ENUM(\'false\',\'true\') DEFAULT \'true\'',
            '`progress` TINYINT UNSIGNED DEFAULT 0',
            '`total_rows` BIGINT UNSIGNED DEFAULT 0',
            '`affected_rows` BIGINT UNSIGNED DEFAULT 0',
            '`affected_json` JSON DEFAULT NULL',
            '`error_message` VARCHAR(1000) DEFAULT NULL',
            '`started_at` DATETIME DEFAULT NULL',
            '`finished_at` DATETIME DEFAULT NULL',
            'KEY `idx_cleanup_status` (`status`, `created_at`)',
            'KEY `idx_cleanup_creator` (`created_by`, `created_at`)',
        ], 'queued'),
        simpleTable('favorite_link', ['`scope` VARCHAR(20) NOT NULL DEFAULT \'personal\'', '`open_mode` VARCHAR(20) NOT NULL DEFAULT \'client\'', '`updated_by` BIGINT UNSIGNED DEFAULT NULL', '`revision` INT UNSIGNED NOT NULL DEFAULT 1', 'KEY `idx_scope_status` (`scope`, `status`, `deleted_at`)', '`account_id` BIGINT UNSIGNED DEFAULT NULL', '`user_id` BIGINT UNSIGNED DEFAULT NULL', '`title` VARCHAR(180) DEFAULT NULL', '`url` VARCHAR(500) DEFAULT NULL', '`icon_url` VARCHAR(500) DEFAULT NULL', '`icon_file_id` BIGINT UNSIGNED DEFAULT NULL', '`sort` INT DEFAULT 0', 'KEY `idx_account_status` (`account_id`, `status`)', 'KEY `idx_user_id` (`user_id`)']),
        simpleTable('theme_preset', ['`theme_json` JSON DEFAULT NULL']),
        simpleTable('api_key', ['`account_id` BIGINT UNSIGNED DEFAULT NULL', '`api_key_hash` CHAR(64) DEFAULT NULL', '`enabled` ENUM(\'false\',\'true\') DEFAULT \'false\'']),
        simpleTable('internship_category', [
            '`scope_type` ENUM(\'grade\',\'cohort\') NOT NULL DEFAULT \'grade\'',
            '`sort` INT DEFAULT 0',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_internship_category_code` (`code`, `active_flag`)',
            'KEY `idx_internship_category_scope` (`scope_type`, `status`, `sort`)',
        ]),
        simpleTable('base', [
            '`company_id` BIGINT UNSIGNED DEFAULT NULL',
            '`dep_id` BIGINT UNSIGNED DEFAULT NULL',
            '`base_type` VARCHAR(20) DEFAULT \'long_term\'',
            '`address` VARCHAR(255) DEFAULT NULL',
            '`district` VARCHAR(120) DEFAULT NULL',
            '`area` DECIMAL(12,2) DEFAULT NULL',
            '`annual_student_count` INT UNSIGNED DEFAULT 0',
            '`current_student_count` INT UNSIGNED DEFAULT 0',
            '`service_courses` TEXT DEFAULT NULL',
            '`category` VARCHAR(80) DEFAULT NULL',
            '`manager_name` VARCHAR(80) DEFAULT NULL',
            '`manager_phone` VARCHAR(40) DEFAULT NULL',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            '`updated_by` BIGINT UNSIGNED DEFAULT NULL',
        ]),
        simpleTable('base_profession', [
            '`base_id` BIGINT UNSIGNED NOT NULL',
            '`profession_id` BIGINT UNSIGNED NOT NULL',
            'UNIQUE KEY `uk_base_profession` (`base_id`, `profession_id`)',
            'KEY `idx_profession_id` (`profession_id`)',
        ]),
        simpleTable('base_person', [
            '`base_id` BIGINT UNSIGNED NOT NULL',
            '`person_type` VARCHAR(20) NOT NULL',
            '`user_id` BIGINT UNSIGNED DEFAULT NULL',
            '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`gender` VARCHAR(20) DEFAULT NULL',
            '`birth_date` VARCHAR(40) DEFAULT NULL',
            '`title` VARCHAR(120) DEFAULT NULL',
            '`education` VARCHAR(80) DEFAULT NULL',
            '`phone` VARCHAR(40) DEFAULT NULL',
            '`duties` TEXT DEFAULT NULL',
            '`sort` INT DEFAULT 0',
            'KEY `idx_base_type` (`base_id`, `person_type`, `status`)',
            'KEY `idx_user_id` (`user_id`)',
        ]),
        simpleTable('base_existing_site', [
            '`base_id` BIGINT UNSIGNED NOT NULL',
            '`site_name` VARCHAR(180) DEFAULT NULL',
            '`cooperation` TEXT DEFAULT NULL',
            '`sort` INT DEFAULT 0',
            'KEY `idx_base_sort` (`base_id`, `sort`, `status`)',
        ]),
        simpleTable('base_company_profile', [
            '`base_id` BIGINT UNSIGNED NOT NULL',
            '`company_name` VARCHAR(180) DEFAULT NULL',
            '`registered_capital` VARCHAR(80) DEFAULT NULL',
            '`main_business` TEXT DEFAULT NULL',
            '`employee_count` INT UNSIGNED DEFAULT 0',
            '`annual_intern_count` INT UNSIGNED DEFAULT 0',
            '`senior_title_count` INT UNSIGNED DEFAULT 0',
            'UNIQUE KEY `uk_base_company_profile` (`base_id`)',
        ]),
        simpleTable('base_construction', [
            '`base_id` BIGINT UNSIGNED NOT NULL',
            '`content` MEDIUMTEXT DEFAULT NULL',
            'UNIQUE KEY `uk_base_construction` (`base_id`)',
        ]),
        simpleTable('base_budget', [
            '`base_id` BIGINT UNSIGNED NOT NULL',
            '`declaration_id` BIGINT UNSIGNED DEFAULT NULL',
            '`item_name` VARCHAR(180) DEFAULT NULL',
            '`content` TEXT DEFAULT NULL',
            '`amount` DECIMAL(12,2) DEFAULT NULL',
            '`remark` TEXT DEFAULT NULL',
            '`sort` INT DEFAULT 0',
            'KEY `idx_base_sort` (`base_id`, `sort`, `status`)',
        ]),
        simpleTable('base_declaration', [
            '`base_id` BIGINT UNSIGNED NOT NULL',
            '`declaration_year` SMALLINT UNSIGNED NOT NULL',
            '`base_category` VARCHAR(120) DEFAULT NULL',
            '`base_level` VARCHAR(80) DEFAULT NULL',
            '`project_status` VARCHAR(20) DEFAULT NULL',
            '`approved_amount` DECIMAL(12,2) DEFAULT NULL',
            '`industry_cobuilt` VARCHAR(20) DEFAULT NULL',
            '`service_profession_count` INT UNSIGNED DEFAULT 0',
            '`curriculum_in_plan` VARCHAR(20) DEFAULT NULL',
            '`unit_type` VARCHAR(80) DEFAULT NULL',
            '`enterprise_level` VARCHAR(120) DEFAULT NULL',
            '`teacher_count` INT UNSIGNED DEFAULT 0',
            '`external_teacher_count` INT UNSIGNED DEFAULT 0',
            '`planned_content` TEXT DEFAULT NULL',
            '`expected_student_visits` INT UNSIGNED DEFAULT 0',
            '`expected_student_days` INT UNSIGNED DEFAULT 0',
            '`has_signboard` VARCHAR(20) DEFAULT NULL',
            '`has_agreement` VARCHAR(20) DEFAULT NULL',
            '`remark` TEXT DEFAULT NULL',
            '`source_edited_at` DATETIME DEFAULT NULL',
            '`source_editor` VARCHAR(120) DEFAULT NULL',
            '`source_admin` VARCHAR(120) DEFAULT NULL',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_base_declaration` (`base_id`, `declaration_year`, `active_flag`)',
            'KEY `idx_base_declaration_year` (`declaration_year`, `base_level`, `status`)',
        ]),
        simpleTable('base_reception_stat', [
            '`declaration_id` BIGINT UNSIGNED NOT NULL',
            '`stat_year` SMALLINT UNSIGNED NOT NULL',
            '`student_count` INT UNSIGNED DEFAULT 0',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_base_reception_stat` (`declaration_id`, `stat_year`, `active_flag`)',
            'KEY `idx_base_reception_year` (`stat_year`, `status`)',
        ]),
        simpleTable('open_sync_app', [
            '`app_id` VARCHAR(120) NOT NULL',
            '`app_secret` TEXT NOT NULL',
            '`allowed_ips` JSON DEFAULT NULL',
            '`last_used_at` DATETIME DEFAULT NULL',
            '`secret_updated_at` DATETIME DEFAULT NULL',
            'UNIQUE KEY `uk_open_sync_app_id` (`app_id`)',
        ]),
        simpleTable('teacher_sync_batch', [
            '`request_id` VARCHAR(120) NOT NULL',
            '`sync_source` VARCHAR(40) DEFAULT \'push\'',
            '`received_count` INT UNSIGNED DEFAULT 0',
            '`created_count` INT UNSIGNED DEFAULT 0',
            '`updated_count` INT UNSIGNED DEFAULT 0',
            '`disabled_count` INT UNSIGNED DEFAULT 0',
            '`failed_count` INT UNSIGNED DEFAULT 0',
            '`result_json` JSON DEFAULT NULL',
            '`processed_at` DATETIME DEFAULT NULL',
            'UNIQUE KEY `uk_teacher_sync_request` (`request_id`)',
            'KEY `idx_teacher_sync_source` (`sync_source`, `status`, `created_at`)',
        ]),
        simpleTable('internship_plan_sync_inbox', [
            '`source_plan_id` VARCHAR(120) NOT NULL',
            '`source_version` VARCHAR(80) NOT NULL',
            '`source_course_id` VARCHAR(120) DEFAULT NULL',
            '`source_status` VARCHAR(40) DEFAULT NULL',
            '`grade_code` VARCHAR(80) DEFAULT NULL',
            '`grade_name` VARCHAR(120) DEFAULT NULL',
            '`dep_code` VARCHAR(80) DEFAULT NULL',
            '`dep_name` VARCHAR(120) DEFAULT NULL',
            '`profession_code` VARCHAR(80) DEFAULT NULL',
            '`profession_name` VARCHAR(180) DEFAULT NULL',
            '`education_level` VARCHAR(80) DEFAULT NULL',
            '`scheme_name` VARCHAR(180) DEFAULT NULL',
            '`scheme_version` VARCHAR(80) DEFAULT NULL',
            '`course_code` VARCHAR(120) DEFAULT NULL',
            '`course_name` VARCHAR(180) DEFAULT NULL',
            '`course_category` VARCHAR(80) DEFAULT NULL',
            '`course_nature` VARCHAR(80) DEFAULT NULL',
            '`credit` DECIMAL(5,2) DEFAULT NULL',
            '`weekly_hours` DECIMAL(6,2) DEFAULT NULL',
            '`total_hours` DECIMAL(8,2) DEFAULT NULL',
            '`theory_hours` DECIMAL(8,2) DEFAULT NULL',
            '`experiment_hours` DECIMAL(8,2) DEFAULT NULL',
            '`practice_hours` DECIMAL(8,2) DEFAULT NULL',
            '`computer_hours` DECIMAL(8,2) DEFAULT NULL',
            '`other_hours` DECIMAL(8,2) DEFAULT NULL',
            '`source_period` VARCHAR(80) DEFAULT NULL',
            '`source_updated_at` DATETIME DEFAULT NULL',
            '`mapping_status` VARCHAR(40) DEFAULT \'pending\'',
            '`mapped_grade_id` BIGINT UNSIGNED DEFAULT NULL',
            '`mapped_dep_id` BIGINT UNSIGNED DEFAULT NULL',
            '`mapped_profession_id` BIGINT UNSIGNED DEFAULT NULL',
            '`raw_payload` JSON DEFAULT NULL',
            '`diff_payload` JSON DEFAULT NULL',
            '`sync_batch_no` VARCHAR(120) DEFAULT NULL',
            '`local_plan_id` BIGINT UNSIGNED DEFAULT NULL',
            '`confirmed_by` BIGINT UNSIGNED DEFAULT NULL',
            '`confirmed_at` DATETIME DEFAULT NULL',
            '`ignored_by` BIGINT UNSIGNED DEFAULT NULL',
            '`ignored_at` DATETIME DEFAULT NULL',
            '`ignore_reason` VARCHAR(500) DEFAULT NULL',
            '`received_at` DATETIME DEFAULT NULL',
            'UNIQUE KEY `uk_plan_version` (`source_plan_id`, `source_version`)',
            'KEY `idx_inbox_status` (`status`, `received_at`)',
            'KEY `idx_inbox_mapping` (`mapping_status`, `mapped_dep_id`, `mapped_profession_id`)',
            'KEY `idx_inbox_local_plan` (`local_plan_id`, `status`)',
        ]),
        simpleTable('base_profession_direction', ['`base_id` BIGINT UNSIGNED NOT NULL', '`profession_id` BIGINT UNSIGNED NOT NULL', '`direction_id` BIGINT UNSIGNED NOT NULL', 'UNIQUE KEY `uk_base_profession_direction` (`base_id`, `profession_id`, `direction_id`)']),
        simpleTable('enterprise_mentor', ['`company_id` BIGINT UNSIGNED DEFAULT NULL', '`mentor_name` VARCHAR(80) DEFAULT NULL', '`mobile` VARCHAR(40) DEFAULT NULL']),
        simpleTable('arrangement', ['`plan_id` BIGINT UNSIGNED DEFAULT NULL', '`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`profession_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`task_no` VARCHAR(80) DEFAULT NULL', '`batch_no` VARCHAR(80) DEFAULT NULL', '`credit` DECIMAL(5,2) DEFAULT NULL', '`student_count` INT DEFAULT 0', '`start_date` DATE DEFAULT NULL', '`end_date` DATE DEFAULT NULL', '`created_by` BIGINT UNSIGNED DEFAULT NULL', '`submitter_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('arrangement_recording', recordingColumns()),
        simpleTable('arrangement_change', ['`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`payload` JSON DEFAULT NULL', '`reason` TEXT DEFAULT NULL', '`from_status` VARCHAR(40) DEFAULT NULL', '`submitter_id` BIGINT UNSIGNED DEFAULT NULL', '`submitted_at` DATETIME DEFAULT NULL', '`reviewer_id` BIGINT UNSIGNED DEFAULT NULL', '`review_opinion` TEXT DEFAULT NULL', '`reviewed_at` DATETIME DEFAULT NULL', '`new_arrangement_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('arrangement_change_recording', recordingColumns()),
        simpleTable('internship_student_profile', [
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`arrangement_id` BIGINT UNSIGNED NOT NULL',
            '`pair_id` BIGINT UNSIGNED DEFAULT NULL',
            '`company_id` BIGINT UNSIGNED DEFAULT NULL',
            '`base_id` BIGINT UNSIGNED DEFAULT NULL',
            '`enterprise_mentor_id` BIGINT UNSIGNED DEFAULT NULL',
            '`location` VARCHAR(255) DEFAULT NULL',
            '`position` VARCHAR(180) DEFAULT NULL',
            '`start_date` DATE DEFAULT NULL',
            '`end_date` DATE DEFAULT NULL',
            '`effective_at` DATETIME DEFAULT NULL',
            '`terminated_at` DATETIME DEFAULT NULL',
            '`termination_reason` TEXT DEFAULT NULL',
            'UNIQUE KEY `uk_student_arrangement` (`student_id`, `arrangement_id`)',
            'KEY `idx_profile_mentor` (`enterprise_mentor_id`, `status`)',
            'KEY `idx_profile_dates` (`start_date`, `end_date`, `status`)',
        ]),
        simpleTable('internship_student_change', [
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`arrangement_id` BIGINT UNSIGNED NOT NULL',
            '`profile_id` BIGINT UNSIGNED DEFAULT NULL',
            '`change_type` VARCHAR(40) NOT NULL',
            '`before_payload` JSON DEFAULT NULL',
            '`after_payload` JSON DEFAULT NULL',
            '`reason` TEXT DEFAULT NULL',
            '`effective_date` DATE DEFAULT NULL',
            '`submitter_id` BIGINT UNSIGNED DEFAULT NULL',
            '`reviewer_id` BIGINT UNSIGNED DEFAULT NULL',
            '`review_opinion` TEXT DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            '`reviewed_at` DATETIME DEFAULT NULL',
            'KEY `idx_student_change_task` (`student_id`, `arrangement_id`, `status`)',
            'KEY `idx_student_change_review` (`status`, `reviewer_id`, `updated_at`)',
        ]),
        simpleTable('internship_student_change_recording', recordingColumns()),
        simpleTable('internship_task_class', ['`arrangement_id` BIGINT UNSIGNED NOT NULL', '`grade_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`profession_id` BIGINT UNSIGNED DEFAULT NULL', '`class_id` BIGINT UNSIGNED NOT NULL', '`student_count_snapshot` INT DEFAULT 0', 'UNIQUE KEY `uk_task_class` (`arrangement_id`, `class_id`)']),
        simpleTable('application', ['`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('application_recording', recordingColumns()),
        simpleTable('student_join_teacher', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('join_recording', recordingColumns()),
        simpleTable('pair', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`type` ENUM(\'internship\',\'training\',\'lab\',\'social_practice\') DEFAULT \'internship\'', '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL', '`entity_type` VARCHAR(40) DEFAULT NULL', '`entity_id` BIGINT UNSIGNED DEFAULT NULL', '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'active\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED', 'UNIQUE KEY `uk_pair_active` (`student_id`, `type`, `arrangement_id`, `active_flag`)']),
        simpleTable('internship_enterprise_evaluation_invitation', [
            '`arrangement_id` BIGINT UNSIGNED NOT NULL',
            '`enterprise_mentor_id` BIGINT UNSIGNED NOT NULL',
            '`token` VARCHAR(180) NOT NULL',
            '`mobile` VARCHAR(40) DEFAULT NULL',
            '`expires_at` DATETIME NOT NULL',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            '`revoked_at` DATETIME DEFAULT NULL',
            'UNIQUE KEY `uk_evaluation_invitation_token` (`token`)',
            'KEY `idx_evaluation_invitation_target` (`arrangement_id`, `enterprise_mentor_id`, `status`, `expires_at`)',
        ]),
        simpleTable('internship_enterprise_evaluation_session', [
            '`invitation_id` BIGINT UNSIGNED NOT NULL',
            '`mobile` VARCHAR(40) NOT NULL',
            '`code_hash` VARCHAR(255) NOT NULL',
            '`code_sent_at` DATETIME DEFAULT NULL',
            '`verified_at` DATETIME DEFAULT NULL',
            '`expires_at` DATETIME NOT NULL',
            '`session_token` VARCHAR(180) DEFAULT NULL',
            '`attempts` INT UNSIGNED DEFAULT 0',
            'UNIQUE KEY `uk_evaluation_session_token` (`session_token`)',
            'KEY `idx_evaluation_session_invitation` (`invitation_id`, `mobile`, `expires_at`)',
        ]),
        simpleTable('internship_enterprise_evaluation', [
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`arrangement_id` BIGINT UNSIGNED NOT NULL',
            '`pair_id` BIGINT UNSIGNED DEFAULT NULL',
            '`enterprise_mentor_id` BIGINT UNSIGNED NOT NULL',
            '`evaluator_name` VARCHAR(80) DEFAULT NULL',
            '`evaluator_mobile` VARCHAR(40) DEFAULT NULL',
            '`verification_id` BIGINT UNSIGNED DEFAULT NULL',
            '`criteria_json` JSON DEFAULT NULL',
            '`total_score` DECIMAL(5,2) DEFAULT NULL',
            '`comment` TEXT DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            '`submitted_ip` VARCHAR(80) DEFAULT NULL',
            'UNIQUE KEY `uk_enterprise_evaluation_student_task` (`student_id`, `arrangement_id`)',
            'KEY `idx_enterprise_evaluation_mentor` (`enterprise_mentor_id`, `status`)',
        ]),
        simpleTable('internship_enterprise_evaluation_recording', recordingColumns()),
        simpleTable('internship_enterprise_evaluation_rule', [
            '`practice_type` VARCHAR(40) DEFAULT \'graduation\'',
            '`criteria_json` JSON DEFAULT NULL',
            '`total_score` DECIMAL(5,2) DEFAULT 30',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            'UNIQUE KEY `uk_enterprise_evaluation_rule_type` (`practice_type`, `status`)',
        ]),
        simpleTable('internship_archive_requirement', [
            '`practice_type` VARCHAR(40) NOT NULL',
            '`material_type` VARCHAR(60) NOT NULL',
            '`required` TINYINT(1) DEFAULT 1',
            '`sort` INT DEFAULT 100',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            'UNIQUE KEY `uk_archive_requirement_type_material` (`practice_type`, `material_type`)',
        ]),
        simpleTable('sign_in', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`sign_time` DATETIME DEFAULT NULL', '`date` DATE DEFAULT NULL', '`longitude` DECIMAL(10,6) DEFAULT NULL', '`latitude` DECIMAL(10,6) DEFAULT NULL', '`location` VARCHAR(255) DEFAULT NULL', '`sign_type` VARCHAR(40) DEFAULT \'gps\'', '`remark` TEXT DEFAULT NULL'])),
        simpleTable('sign_in_recording', recordingColumns()),
        simpleTable('sign_in_qrcode', entityColumns(['`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`token` VARCHAR(120) DEFAULT NULL', '`expires_at` DATETIME DEFAULT NULL'])),
        simpleTable('journal', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`date` DATE DEFAULT NULL', '`title` VARCHAR(180) DEFAULT NULL', '`content` TEXT DEFAULT NULL', '`remark` TEXT DEFAULT NULL'])),
        simpleTable('journal_recording', recordingColumns()),
        simpleTable('report', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`date` DATE DEFAULT NULL', '`title` VARCHAR(180) DEFAULT NULL', '`content` TEXT DEFAULT NULL', '`template_id` BIGINT UNSIGNED DEFAULT NULL', '`submitted_at` DATETIME DEFAULT NULL', '`remark` TEXT DEFAULT NULL', '`practice_project_id` BIGINT UNSIGNED DEFAULT NULL', '`reflection_summary` TEXT DEFAULT NULL'])),
        simpleTable('report_recording', recordingColumns()),
        simpleTable('internship_graduation_appraisal', [
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`arrangement_id` BIGINT UNSIGNED NOT NULL',
            '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`process_score` DECIMAL(5,2) DEFAULT NULL',
            '`enterprise_score` DECIMAL(5,2) DEFAULT NULL',
            '`school_score` DECIMAL(5,2) DEFAULT NULL',
            '`report_score` DECIMAL(5,2) DEFAULT NULL',
            '`final_score` DECIMAL(5,2) DEFAULT NULL',
            '`grade_level` VARCHAR(40) DEFAULT NULL',
            '`enterprise_comment` TEXT DEFAULT NULL',
            '`school_comment` TEXT DEFAULT NULL',
            '`form_data` JSON DEFAULT NULL',
            '`attachment_id` BIGINT UNSIGNED DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            '`reviewed_at` DATETIME DEFAULT NULL',
            'KEY `idx_graduation_appraisal_student_task` (`student_id`, `arrangement_id`, `status`)',
        ]),
        simpleTable('internship_graduation_appraisal_recording', recordingColumns()),
        simpleTable('report_template', ['`template_json` JSON DEFAULT NULL']),
        simpleTable('review_opinion', entityColumns(['`reviewer_id` BIGINT UNSIGNED DEFAULT NULL', '`opinion` TEXT DEFAULT NULL'])),
        simpleTable('review_opinion_draft', entityColumns(['`reviewer_id` BIGINT UNSIGNED DEFAULT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`review_status` VARCHAR(40) DEFAULT NULL', '`opinion` TEXT DEFAULT NULL', '`score` DECIMAL(5,2) DEFAULT NULL', 'UNIQUE KEY `uk_review_draft` (`entity_type`, `entity_id`, `reviewer_id`)', 'KEY `idx_reviewer` (`reviewer_id`)'])),
        simpleTable('recording_archive_' . max(2000, min(2999, $archiveYear ?? (int) date('Y'))), recordingArchiveColumns()),
        simpleTable('apply_report_delay', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`config_key` VARCHAR(120) DEFAULT NULL', '`requested_date` DATE DEFAULT NULL', '`reason` TEXT DEFAULT NULL'])),
        simpleTable('apply_report_delay_recording', recordingColumns()),
        simpleTable('score', entityColumns(['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`score_value` DECIMAL(5,2) DEFAULT NULL'])),
        simpleTable('score_recording', recordingColumns()),
        simpleTable('course_score', ['`plan_id` BIGINT UNSIGNED DEFAULT NULL', '`student_id` BIGINT UNSIGNED DEFAULT NULL', '`score_value` DECIMAL(5,2) DEFAULT NULL', '`operator_id` BIGINT UNSIGNED DEFAULT NULL', '`remark` TEXT DEFAULT NULL', 'UNIQUE KEY `uk_course_score` (`plan_id`, `student_id`)']),
        simpleTable('internship_plan', [
            '`source_type` VARCHAR(40) DEFAULT \'edu_system\'',
            '`source_plan_id` VARCHAR(120) DEFAULT NULL',
            '`source_version` VARCHAR(80) DEFAULT NULL',
            '`source_last_synced_at` DATETIME DEFAULT NULL',
            '`course_code` VARCHAR(120) DEFAULT NULL',
            '`course_name` VARCHAR(180) DEFAULT NULL',
            '`course_category` VARCHAR(80) DEFAULT NULL',
            '`category_id` BIGINT UNSIGNED DEFAULT NULL',
            '`grade_id` BIGINT UNSIGNED DEFAULT NULL',
            '`graduation_cohort_id` BIGINT UNSIGNED DEFAULT NULL',
            '`dep_id` BIGINT UNSIGNED DEFAULT NULL',
            '`profession_id` BIGINT UNSIGNED DEFAULT NULL',
            '`semester` VARCHAR(80) DEFAULT NULL',
            '`credit` DECIMAL(5,2) DEFAULT NULL',
            '`total_credit` DECIMAL(5,2) DEFAULT NULL',
            '`internship_credit` DECIMAL(5,2) DEFAULT NULL',
            '`total_hours` VARCHAR(40) DEFAULT NULL',
            '`internship_hours` VARCHAR(40) DEFAULT NULL',
            '`source_teacher` TEXT DEFAULT NULL',
            '`source_time` TEXT DEFAULT NULL',
            '`source_location` TEXT DEFAULT NULL',
            '`remark` TEXT DEFAULT NULL',
            '`source_row` JSON DEFAULT NULL',
            '`business_type` VARCHAR(40) DEFAULT \'internship\'',
            '`import_file_id` BIGINT UNSIGNED DEFAULT NULL',
            '`imported_at` DATETIME DEFAULT NULL',
            '`student_count` INT DEFAULT 0',
            '`score_rule` VARCHAR(40) DEFAULT \'average\'',
            '`plan_content` JSON DEFAULT NULL',
            '`submitter_id` BIGINT UNSIGNED DEFAULT NULL',
        ]),
        simpleTable('internship_plan_approval', ['`plan_id` BIGINT UNSIGNED DEFAULT NULL', '`reviewer_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('plan_recording', recordingColumns()),
        simpleTable('insurance', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`company_id` BIGINT UNSIGNED DEFAULT NULL', '`policy_no` VARCHAR(120) DEFAULT NULL']),
        simpleTable('insurance_recording', recordingColumns()),
        simpleTable('internship_brief', ['`week_key` VARCHAR(80) DEFAULT NULL', '`week_start` DATE DEFAULT NULL', '`week_end` DATE DEFAULT NULL', "`scope_type` VARCHAR(20) DEFAULT 'school'", '`scope_id` BIGINT UNSIGNED DEFAULT 0', '`scope_name` VARCHAR(120) DEFAULT NULL', '`title` VARCHAR(180) DEFAULT NULL', '`content_json` JSON DEFAULT NULL', '`generated_at` DATETIME DEFAULT NULL', 'UNIQUE KEY `uk_week_key` (`week_key`)', 'KEY `idx_period` (`week_start`, `week_end`)', 'KEY `idx_scope_period` (`scope_type`, `scope_id`, `week_start`)']),
        simpleTable('safety_letter_sign', ['`student_id` BIGINT UNSIGNED DEFAULT NULL', '`signed_at` DATETIME DEFAULT NULL']),
        simpleTable('safety_letter_recording', recordingColumns()),
        simpleTable('syllabus_guide', ['`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`file_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('syllabus_guide_recording', recordingColumns()),
        simpleTable('internship_archive_material', [
            '`material_type` VARCHAR(60) NOT NULL',
            '`scope_type` VARCHAR(40) NOT NULL',
            '`plan_id` BIGINT UNSIGNED DEFAULT NULL',
            '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL',
            '`student_id` BIGINT UNSIGNED DEFAULT NULL',
            '`class_id` BIGINT UNSIGNED DEFAULT NULL',
            '`template_id` BIGINT UNSIGNED DEFAULT NULL',
            '`template_version` VARCHAR(40) DEFAULT NULL',
            '`source_entity_type` VARCHAR(60) DEFAULT NULL',
            '`source_entity_id` BIGINT UNSIGNED DEFAULT NULL',
            '`content_json` JSON DEFAULT NULL',
            '`generated_file_id` BIGINT UNSIGNED DEFAULT NULL',
            '`signed_file_id` BIGINT UNSIGNED DEFAULT NULL',
            '`recording_id` BIGINT UNSIGNED DEFAULT NULL',
            '`archive_version` INT UNSIGNED DEFAULT 1',
            '`archived_at` DATETIME DEFAULT NULL',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            '`updated_by` BIGINT UNSIGNED DEFAULT NULL',
            "`scope_key` VARCHAR(180) GENERATED ALWAYS AS (CONCAT(`scope_type`, ':', IFNULL(`plan_id`, 0), ':', IFNULL(`arrangement_id`, 0), ':', IFNULL(`student_id`, 0), ':', IFNULL(`class_id`, 0))) STORED",
            "`current_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` IN ('draft','submitted','archived') AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED",
            'UNIQUE KEY `uk_archive_material_current` (`material_type`, `scope_key`, `current_flag`)',
            'KEY `idx_archive_material_plan` (`plan_id`, `scope_type`, `status`)',
            'KEY `idx_archive_material_task` (`arrangement_id`, `student_id`, `status`)',
            'KEY `idx_archive_material_files` (`generated_file_id`, `signed_file_id`)',
        ]),
        simpleTable('internship_archive_material_recording', recordingColumns()),
        simpleTable('implementation_sheet', [
            '`arrangement_id` BIGINT UNSIGNED DEFAULT NULL',
            '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`plan_ref_id` BIGINT UNSIGNED DEFAULT NULL',
            '`syllabus_ref_id` BIGINT UNSIGNED DEFAULT NULL',
            '`applicant_id` BIGINT UNSIGNED DEFAULT NULL',
            '`applicant_name` VARCHAR(80) DEFAULT NULL',
            '`applicant_department` VARCHAR(180) DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            '`approval_no` VARCHAR(120) DEFAULT NULL',
            '`grade_id` BIGINT UNSIGNED DEFAULT NULL',
            '`course_name` VARCHAR(180) DEFAULT NULL',
            '`course_type` VARCHAR(80) DEFAULT NULL',
            '`detail_content` TEXT DEFAULT NULL',
            '`credit` DECIMAL(5,2) DEFAULT NULL',
            '`practice_type` VARCHAR(80) DEFAULT NULL',
            '`internship_mode` VARCHAR(80) DEFAULT NULL',
            '`organize_mode` VARCHAR(80) DEFAULT NULL',
            '`total_people` INT UNSIGNED DEFAULT 0',
            '`total_amount` DECIMAL(12,2) DEFAULT 0',
            '`attachment_ids` JSON DEFAULT NULL',
            '`remark` TEXT DEFAULT NULL',
            '`signed_count` INT DEFAULT 0',
            '`unsigned_count` INT DEFAULT 0',
            '`insurance_verified` ENUM(\'false\',\'true\') DEFAULT \'false\'',
            '`fee_detail` JSON DEFAULT NULL',
            '`sheet_json` JSON DEFAULT NULL',
            '`confirmed_at` DATETIME DEFAULT NULL',
        ]),
        simpleTable('implementation_schedule', [
            '`implementation_id` BIGINT UNSIGNED NOT NULL',
            '`profession_id` BIGINT UNSIGNED DEFAULT NULL',
            '`profession_name` VARCHAR(180) DEFAULT NULL',
            '`grade_id` BIGINT UNSIGNED DEFAULT NULL',
            '`grade_name` VARCHAR(80) DEFAULT NULL',
            '`people_count` INT UNSIGNED DEFAULT 0',
            '`week_text` VARCHAR(120) DEFAULT NULL',
            '`weekday_text` VARCHAR(120) DEFAULT NULL',
            '`location` VARCHAR(255) DEFAULT NULL',
            '`time_text` VARCHAR(255) DEFAULT NULL',
            '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`teacher_name` VARCHAR(80) DEFAULT NULL',
            '`sort` INT DEFAULT 0',
            'KEY `idx_implementation_schedule` (`implementation_id`, `sort`, `status`)',
        ]),
        simpleTable('implementation_expense', [
            '`implementation_id` BIGINT UNSIGNED NOT NULL',
            '`item_name` VARCHAR(180) DEFAULT NULL',
            '`content` TEXT DEFAULT NULL',
            '`amount` DECIMAL(12,2) DEFAULT NULL',
            '`remark` TEXT DEFAULT NULL',
            '`sort` INT DEFAULT 0',
            'KEY `idx_implementation_expense` (`implementation_id`, `sort`, `status`)',
        ]),
        simpleTable('implementation_sheet_recording', recordingColumns()),
        simpleTable('teacher_work_report', ['`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`semester` VARCHAR(80) DEFAULT NULL']),
        simpleTable('teacher_work_report_recording', recordingColumns()),
        simpleTable('inspection_record', ['`inspector_id` BIGINT UNSIGNED DEFAULT NULL', '`entity_type` VARCHAR(40) DEFAULT NULL', '`entity_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('inspection_recording', recordingColumns()),
        simpleTable('base_application', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`base_type` VARCHAR(40) DEFAULT NULL']),
        TableRecord::recordingCreationStatement('base_application_recording'),
        simpleTable('base_usage', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`usage_type` VARCHAR(80) DEFAULT NULL']),
        simpleTable('base_result', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`result_type` VARCHAR(80) DEFAULT NULL']),
        simpleTable('base_expense', ['`base_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`amount` DECIMAL(12,2) DEFAULT NULL']),
        simpleTable('practice_period', ['`start_time` TIME NOT NULL', '`end_time` TIME NOT NULL', '`sort` INT DEFAULT 0', 'KEY `idx_period_sort` (`sort`, `status`)']),
        simpleTable('practice_plan', practiceCommonColumns(['`source_type` VARCHAR(40) DEFAULT \'manual\'', '`submitter_id` BIGINT UNSIGNED DEFAULT NULL', '`course_leader_id` BIGINT UNSIGNED DEFAULT NULL', '`course_leader_account_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_plan_teacher', [
            '`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'',
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`teacher_id` BIGINT UNSIGNED NOT NULL',
            '`teacher_role` ENUM(\'leader\',\'teacher\') DEFAULT \'teacher\'',
            '`sort` INT DEFAULT 0',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'enabled\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_practice_plan_teacher_active` (`plan_id`, `teacher_id`, `active_flag`)',
            'KEY `idx_practice_plan_teacher_scope` (`module_type`, `teacher_id`, `status`)',
        ]),
        simpleTable('practice_schedule', practiceCommonColumns(['`room_id` BIGINT UNSIGNED DEFAULT NULL', '`base_id` BIGINT UNSIGNED DEFAULT NULL', '`place_type` VARCHAR(40) DEFAULT \'inside\'', '`schedule_date` DATE DEFAULT NULL', '`period_start_id` BIGINT UNSIGNED DEFAULT NULL', '`period_end_id` BIGINT UNSIGNED DEFAULT NULL', '`start_time` VARCHAR(20) DEFAULT NULL', '`end_time` VARCHAR(20) DEFAULT NULL', '`location` VARCHAR(255) DEFAULT NULL', '`student_count` INT DEFAULT 0', '`roster_printed_at` DATETIME DEFAULT NULL'])),
        simpleTable('practice_project', practiceCommonColumns(['`schedule_id` BIGINT UNSIGNED DEFAULT NULL', '`start_date` DATE DEFAULT NULL', '`end_date` DATE DEFAULT NULL', '`student_count` INT DEFAULT 0', '`published_at` DATETIME DEFAULT NULL', '`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_project_student', ['`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'', '`project_id` BIGINT UNSIGNED NOT NULL', '`student_id` BIGINT UNSIGNED NOT NULL', '`teacher_id` BIGINT UNSIGNED DEFAULT NULL', '`plan_id` BIGINT UNSIGNED DEFAULT NULL', '`schedule_id` BIGINT UNSIGNED DEFAULT NULL', '`grade_id` BIGINT UNSIGNED DEFAULT NULL', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`profession_id` BIGINT UNSIGNED DEFAULT NULL', '`class_id` BIGINT UNSIGNED DEFAULT NULL', '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'active\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED', 'UNIQUE KEY `uk_project_student` (`project_id`, `student_id`, `active_flag`)', 'KEY `idx_student` (`student_id`)', 'KEY `idx_project` (`project_id`)']),
        simpleTable('practice_syllabus', practiceCommonColumns(['`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_lesson_plan', practiceCommonColumns(['`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_grade_rule', practiceCommonColumns(['`ratio_json` JSON DEFAULT NULL'])),
        simpleTable('practice_score', practiceCommonColumns(['`submitter_id` BIGINT UNSIGNED DEFAULT NULL', '`project_id` BIGINT UNSIGNED DEFAULT NULL', '`student_id` BIGINT UNSIGNED DEFAULT NULL', '`rule_id` BIGINT UNSIGNED DEFAULT NULL', '`score_items` JSON DEFAULT NULL', '`score_value` DECIMAL(5,2) DEFAULT NULL'])),
        simpleTable('practice_reflection', practiceCommonColumns(['`submitter_id` BIGINT UNSIGNED DEFAULT NULL'])),
        simpleTable('practice_room', ['`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'', '`dep_id` BIGINT UNSIGNED DEFAULT NULL', '`room_type` VARCHAR(80) DEFAULT NULL', '`capacity` INT DEFAULT 0', '`location` VARCHAR(255) DEFAULT NULL', '`manager_id` BIGINT UNSIGNED DEFAULT NULL']),
        simpleTable('practice_recording', array_merge(['`parent_id` BIGINT UNSIGNED DEFAULT NULL', '`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'', '`action` VARCHAR(40) DEFAULT NULL', '`content` TEXT DEFAULT NULL'], recordingColumns())),
        simpleTable('practice_archive', [
            '`module_type` ENUM(\'training\',\'lab\') DEFAULT \'training\'',
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`version_no` INT UNSIGNED NOT NULL DEFAULT 1',
            '`snapshot_json` JSON DEFAULT NULL',
            '`missing_items_json` JSON DEFAULT NULL',
            '`pending_items_json` JSON DEFAULT NULL',
            '`archive_file_id` BIGINT UNSIGNED DEFAULT NULL',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            '`invalidated_by` BIGINT UNSIGNED DEFAULT NULL',
            '`invalidated_at` DATETIME DEFAULT NULL',
            '`invalidate_reason` VARCHAR(500) DEFAULT NULL',
            'UNIQUE KEY `uk_practice_archive_version` (`module_type`, `plan_id`, `version_no`)',
            'KEY `idx_practice_archive_plan` (`module_type`, `plan_id`, `status`)',
        ]),
        simpleTable('social_practice_plan', [
            '`source_type` VARCHAR(40) DEFAULT \'manual\'',
            '`source_key` VARCHAR(180) DEFAULT NULL',
            '`title` VARCHAR(180) DEFAULT NULL',
            '`description` MEDIUMTEXT DEFAULT NULL',
            '`grade_id` BIGINT UNSIGNED NOT NULL',
            '`organizer_dep_id` BIGINT UNSIGNED DEFAULT NULL',
            '`credit` DECIMAL(5,2) DEFAULT 0',
            '`participation_mode` VARCHAR(40) DEFAULT \'mandatory\'',
            '`teacher_match_mode` VARCHAR(40) DEFAULT \'mixed\'',
            '`teacher_confirm_hours` INT UNSIGNED DEFAULT 48',
            '`max_reselect_count` INT UNSIGNED DEFAULT 2',
            '`default_team_submit_mode` VARCHAR(40) DEFAULT \'individual\'',
            '`register_start_at` DATETIME DEFAULT NULL',
            '`register_end_at` DATETIME DEFAULT NULL',
            '`practice_start_at` DATETIME DEFAULT NULL',
            '`practice_end_at` DATETIME DEFAULT NULL',
            '`result_deadline_at` DATETIME DEFAULT NULL',
            '`score_deadline_at` DATETIME DEFAULT NULL',
            '`approval_flow_id` BIGINT UNSIGNED DEFAULT NULL',
            '`current_node_id` BIGINT UNSIGNED DEFAULT NULL',
            '`phase` VARCHAR(40) DEFAULT \'ready\'',
            '`submitter_id` BIGINT UNSIGNED DEFAULT NULL',
            '`published_at` DATETIME DEFAULT NULL',
            'UNIQUE KEY `uk_social_plan_source` (`source_type`, `source_key`)',
            'KEY `idx_social_plan_scope` (`grade_id`, `organizer_dep_id`, `status`, `phase`)',
        ]),
        simpleTable('social_practice_plan_scope', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`scope_type` VARCHAR(40) DEFAULT \'school\'',
            '`dep_id` BIGINT UNSIGNED DEFAULT NULL',
            '`profession_id` BIGINT UNSIGNED DEFAULT NULL',
            '`class_id` BIGINT UNSIGNED DEFAULT NULL',
            '`scope_key` VARCHAR(180) GENERATED ALWAYS AS (CONCAT(`scope_type`, \':\', IFNULL(`dep_id`, 0), \':\', IFNULL(`profession_id`, 0), \':\', IFNULL(`class_id`, 0))) STORED',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'enabled\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_social_plan_scope` (`plan_id`, `scope_key`, `active_flag`)',
            'KEY `idx_social_scope_org` (`dep_id`, `profession_id`, `class_id`, `status`)',
        ]),
        simpleTable('social_practice_approval_flow', [
            '`scope_type` VARCHAR(40) DEFAULT \'school\'',
            '`dep_id` BIGINT UNSIGNED DEFAULT NULL',
            '`profession_id` BIGINT UNSIGNED DEFAULT NULL',
            '`version` INT UNSIGNED DEFAULT 1',
            '`description` TEXT DEFAULT NULL',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            'KEY `idx_social_flow_scope` (`scope_type`, `dep_id`, `profession_id`, `status`)',
        ]),
        simpleTable('social_practice_approval_node', [
            '`flow_id` BIGINT UNSIGNED NOT NULL',
            '`node_code` VARCHAR(80) NOT NULL',
            '`node_name` VARCHAR(120) DEFAULT NULL',
            '`sort` INT DEFAULT 0',
            '`scope_type` VARCHAR(40) DEFAULT \'school\'',
            '`permission_code` VARCHAR(120) DEFAULT NULL',
            '`node_type` VARCHAR(40) DEFAULT \'review\'',
            '`can_return` ENUM(\'false\',\'true\') DEFAULT \'true\'',
            '`auto_pass` ENUM(\'false\',\'true\') DEFAULT \'false\'',
            'UNIQUE KEY `uk_social_flow_node` (`flow_id`, `node_code`)',
            'KEY `idx_social_flow_sort` (`flow_id`, `sort`, `status`)',
        ]),
        simpleTable('social_practice_requirement', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`practice_mode` VARCHAR(40) DEFAULT \'all\'',
            '`requirement_type` VARCHAR(80) NOT NULL',
            '`required_flag` ENUM(\'false\',\'true\') DEFAULT \'true\'',
            '`submit_scope` VARCHAR(40) DEFAULT \'student\'',
            '`deadline_at` DATETIME DEFAULT NULL',
            '`config_json` JSON DEFAULT NULL',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'enabled\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_social_requirement` (`plan_id`, `practice_mode`, `requirement_type`, `active_flag`)',
            'KEY `idx_social_requirement_plan` (`plan_id`, `practice_mode`, `status`)',
        ]),
        simpleTable('social_practice_project', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`practice_mode` VARCHAR(40) NOT NULL',
            '`source_type` VARCHAR(40) DEFAULT \'admin_created\'',
            '`source_declaration_id` BIGINT UNSIGNED DEFAULT NULL',
            '`project_code` VARCHAR(120) DEFAULT NULL',
            '`title` VARCHAR(180) DEFAULT NULL',
            '`content` MEDIUMTEXT DEFAULT NULL',
            '`objective` TEXT DEFAULT NULL',
            '`location` VARCHAR(255) DEFAULT NULL',
            '`start_at` DATETIME DEFAULT NULL',
            '`end_at` DATETIME DEFAULT NULL',
            '`capacity` INT UNSIGNED DEFAULT 0',
            '`phase` VARCHAR(40) DEFAULT \'ready\'',
            '`created_by` BIGINT UNSIGNED DEFAULT NULL',
            '`published_at` DATETIME DEFAULT NULL',
            'UNIQUE KEY `uk_social_project_code` (`plan_id`, `project_code`)',
            'KEY `idx_social_project_plan` (`plan_id`, `practice_mode`, `status`, `phase`)',
        ]),
        simpleTable('social_practice_project_teacher', [
            '`project_id` BIGINT UNSIGNED NOT NULL',
            '`teacher_id` BIGINT UNSIGNED NOT NULL',
            '`teacher_role` VARCHAR(40) DEFAULT \'guide\'',
            '`capacity` INT UNSIGNED DEFAULT 0',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'active\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_social_project_teacher` (`project_id`, `teacher_id`, `active_flag`)',
            'KEY `idx_social_teacher_project` (`teacher_id`, `project_id`, `status`)',
        ]),
        simpleTable('social_practice_participant', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`project_id` BIGINT UNSIGNED DEFAULT NULL',
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`practice_mode` VARCHAR(40) NOT NULL',
            '`join_source` VARCHAR(40) DEFAULT \'scope\'',
            '`remove_reason` TEXT DEFAULT NULL',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'active\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_social_participant` (`plan_id`, `student_id`, `active_flag`)',
            'KEY `idx_social_participant_project` (`project_id`, `teacher_id`, `status`)',
            'KEY `idx_social_participant_student` (`student_id`, `status`)',
        ]),
        simpleTable('social_practice_implementation_application', [
            '`project_id` BIGINT UNSIGNED NOT NULL',
            '`applicant_id` BIGINT UNSIGNED NOT NULL',
            '`title` VARCHAR(180) DEFAULT NULL',
            '`content` MEDIUMTEXT DEFAULT NULL',
            '`budget_amount` DECIMAL(12,2) DEFAULT 0',
            '`material_requirement` TEXT DEFAULT NULL',
            '`venue_requirement` TEXT DEFAULT NULL',
            '`approval_flow_id` BIGINT UNSIGNED DEFAULT NULL',
            '`current_node_id` BIGINT UNSIGNED DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            'KEY `idx_social_implementation_project` (`project_id`, `status`)',
        ]),
        simpleTable('social_practice_declaration', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`applicant_student_id` BIGINT UNSIGNED NOT NULL',
            '`declaration_type` VARCHAR(40) DEFAULT \'individual\'',
            '`team_submit_mode` VARCHAR(40) DEFAULT \'individual\'',
            '`title` VARCHAR(180) DEFAULT NULL',
            '`content` MEDIUMTEXT DEFAULT NULL',
            '`objective` TEXT DEFAULT NULL',
            '`expected_duration` VARCHAR(120) DEFAULT NULL',
            '`expected_result` TEXT DEFAULT NULL',
            '`location` VARCHAR(255) DEFAULT NULL',
            '`selected_teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`assigned_teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`teacher_confirm_deadline_at` DATETIME DEFAULT NULL',
            '`teacher_confirm_status` VARCHAR(40) DEFAULT \'pending\'',
            '`teacher_reselect_count` INT UNSIGNED DEFAULT 0',
            '`accepted_project_id` BIGINT UNSIGNED DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            'KEY `idx_social_declaration_plan` (`plan_id`, `status`, `teacher_confirm_status`)',
            'KEY `idx_social_declaration_student` (`applicant_student_id`, `status`)',
            'KEY `idx_social_declaration_teacher` (`selected_teacher_id`, `assigned_teacher_id`, `status`)',
        ]),
        simpleTable('social_practice_declaration_member', [
            '`declaration_id` BIGINT UNSIGNED NOT NULL',
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`member_role` VARCHAR(40) DEFAULT \'member\'',
            '`confirm_status` VARCHAR(40) DEFAULT \'pending\'',
            '`confirmed_at` DATETIME DEFAULT NULL',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'active\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_social_declaration_member` (`declaration_id`, `student_id`, `active_flag`)',
            'KEY `idx_social_member_student` (`student_id`, `confirm_status`, `status`)',
        ]),
        simpleTable('social_practice_material', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`project_id` BIGINT UNSIGNED DEFAULT NULL',
            '`declaration_id` BIGINT UNSIGNED DEFAULT NULL',
            '`student_id` BIGINT UNSIGNED DEFAULT NULL',
            '`material_type` VARCHAR(80) NOT NULL',
            '`submit_scope` VARCHAR(40) DEFAULT \'student\'',
            '`version` INT UNSIGNED DEFAULT 1',
            '`title` VARCHAR(180) DEFAULT NULL',
            '`content` MEDIUMTEXT DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            'KEY `idx_social_material_target` (`plan_id`, `project_id`, `student_id`, `material_type`, `status`)',
        ]),
        simpleTable('social_practice_patch_sign', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`project_id` BIGINT UNSIGNED NOT NULL',
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`sign_in_id` BIGINT UNSIGNED DEFAULT NULL',
            '`sign_date` DATE NOT NULL',
            '`reason` TEXT DEFAULT NULL',
            '`proof` TEXT DEFAULT NULL',
            '`submitted_at` DATETIME DEFAULT NULL',
            'KEY `idx_social_patch_sign` (`project_id`, `student_id`, `sign_date`, `status`)',
        ]),
        simpleTable('social_practice_score_rule', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`practice_mode` VARCHAR(40) NOT NULL',
            '`item_code` VARCHAR(80) NOT NULL',
            '`item_name` VARCHAR(120) DEFAULT NULL',
            '`weight` DECIMAL(5,2) DEFAULT 0',
            '`max_score` DECIMAL(5,2) DEFAULT 100',
            '`sort` INT DEFAULT 0',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = \'enabled\' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_social_score_rule` (`plan_id`, `practice_mode`, `item_code`, `active_flag`)',
            'KEY `idx_social_score_rule_plan` (`plan_id`, `practice_mode`, `sort`, `status`)',
        ]),
        simpleTable('social_practice_score', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`project_id` BIGINT UNSIGNED NOT NULL',
            '`student_id` BIGINT UNSIGNED NOT NULL',
            '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`final_score` DECIMAL(5,2) DEFAULT NULL',
            '`credit_recognized` ENUM(\'false\',\'true\') DEFAULT \'false\'',
            '`comment` TEXT DEFAULT NULL',
            '`reviewer_id` BIGINT UNSIGNED DEFAULT NULL',
            '`reviewed_at` DATETIME DEFAULT NULL',
            '`active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED',
            'UNIQUE KEY `uk_social_score_student` (`plan_id`, `project_id`, `student_id`, `active_flag`)',
            'KEY `idx_social_score_review` (`teacher_id`, `status`, `reviewed_at`)',
        ]),
        simpleTable('social_practice_score_detail', [
            '`score_id` BIGINT UNSIGNED NOT NULL',
            '`rule_id` BIGINT UNSIGNED DEFAULT NULL',
            '`item_code` VARCHAR(80) NOT NULL',
            '`score_value` DECIMAL(5,2) DEFAULT 0',
            '`weight` DECIMAL(5,2) DEFAULT 0',
            '`weighted_score` DECIMAL(6,2) DEFAULT 0',
            'UNIQUE KEY `uk_social_score_detail` (`score_id`, `item_code`)',
            'KEY `idx_social_score_detail_rule` (`rule_id`, `status`)',
        ]),
        simpleTable('social_practice_archive', [
            '`plan_id` BIGINT UNSIGNED NOT NULL',
            '`project_id` BIGINT UNSIGNED DEFAULT NULL',
            '`student_id` BIGINT UNSIGNED DEFAULT NULL',
            '`teacher_id` BIGINT UNSIGNED DEFAULT NULL',
            '`version` INT UNSIGNED DEFAULT 1',
            '`snapshot_json` JSON DEFAULT NULL',
            '`print_file_id` BIGINT UNSIGNED DEFAULT NULL',
            '`archived_by` BIGINT UNSIGNED DEFAULT NULL',
            '`archived_at` DATETIME DEFAULT NULL',
            'UNIQUE KEY `uk_social_archive_version` (`plan_id`, `project_id`, `student_id`, `version`)',
            'KEY `idx_social_archive_scope` (`plan_id`, `project_id`, `status`, `archived_at`)',
        ]),
        simpleTable('social_practice_recording', array_merge([
            '`parent_id` BIGINT UNSIGNED DEFAULT NULL',
            '`action` VARCHAR(40) DEFAULT NULL',
            '`content` MEDIUMTEXT DEFAULT NULL',
        ], recordingColumns())),
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
        $statusDefault = match ($table) {
            'export_task' => 'pending',
            'doc_article' => 'draft',
            default => 'enabled',
        };
        $statements[] = simpleTable($table, ['`payload` JSON DEFAULT NULL'], $statusDefault);
    }

    $statements[] = TableRecord::operationLogCreationStatement('operation_log_202606');

    $statements = array_merge($statements, DesktopToolsSchema::creationStatements());
    foreach (['0.3.4-message-assistant.sql', '20260923-base-visit.sql', '20260923-account-import.sql'] as $file) {
        $sql = file_get_contents(__DIR__ . '/updates/' . $file);
        if ($sql === false) throw new RuntimeException('无法读取结构 SQL：' . $file);
        foreach (array_map('trim', explode(';', $sql)) as $statement) {
            if (str_starts_with($statement, 'CREATE TABLE')) $statements[] = $statement;
        }
    }
    foreach (AssistantProfile::schemaStatements() as $statement) {
        if (str_starts_with($statement, 'CREATE TABLE')) $statements[] = $statement;
    }
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
            `type` VARCHAR(40) DEFAULT 'system',
            `level` VARCHAR(40) DEFAULT 'normal',
            `description` VARCHAR(500) DEFAULT NULL,
            `variables` JSON DEFAULT NULL,
            `link_url_tpl` VARCHAR(500) DEFAULT NULL,
            `channels` JSON DEFAULT NULL,
            `is_system` TINYINT(1) DEFAULT 0,
            `sort` INT DEFAULT 100,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_status` (`status`),
            KEY `idx_type_status` (`type`, `status`, `sort`)
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
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `available_at` DATETIME DEFAULT NULL,
            `locked_until` DATETIME DEFAULT NULL,
            `claim_token` VARCHAR(64) DEFAULT NULL,
            KEY `idx_channel_available` (`channel`, `status`, `available_at`),
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
    ensureIndex($pdo, 'file', 'idx_temporary_created', "ALTER TABLE `file` ADD KEY `idx_temporary_created` (`is_temporary`, `deleted_at`, `created_at`)");
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
            'base_type' => "ALTER TABLE `base` ADD COLUMN `base_type` VARCHAR(20) DEFAULT 'long_term' AFTER `dep_id`",
            'address' => "ALTER TABLE `base` ADD COLUMN `address` VARCHAR(255) DEFAULT NULL AFTER `dep_id`",
            'area' => "ALTER TABLE `base` ADD COLUMN `area` DECIMAL(12,2) DEFAULT NULL AFTER `address`",
            'annual_student_count' => "ALTER TABLE `base` ADD COLUMN `annual_student_count` INT UNSIGNED DEFAULT 0 AFTER `area`",
            'current_student_count' => "ALTER TABLE `base` ADD COLUMN `current_student_count` INT UNSIGNED DEFAULT 0 AFTER `annual_student_count`",
            'service_courses' => "ALTER TABLE `base` ADD COLUMN `service_courses` TEXT DEFAULT NULL AFTER `current_student_count`",
            'category' => "ALTER TABLE `base` ADD COLUMN `category` VARCHAR(80) DEFAULT NULL AFTER `service_courses`",
            'manager_name' => "ALTER TABLE `base` ADD COLUMN `manager_name` VARCHAR(80) DEFAULT NULL AFTER `category`",
            'manager_phone' => "ALTER TABLE `base` ADD COLUMN `manager_phone` VARCHAR(40) DEFAULT NULL AFTER `manager_name`",
            'created_by' => "ALTER TABLE `base` ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `manager_phone`",
            'updated_by' => "ALTER TABLE `base` ADD COLUMN `updated_by` BIGINT UNSIGNED DEFAULT NULL AFTER `created_by`",
            'capacity' => "ALTER TABLE `base` ADD COLUMN `capacity` INT UNSIGNED DEFAULT 0 AFTER `address`",
            'used_count' => "ALTER TABLE `base` ADD COLUMN `used_count` INT UNSIGNED DEFAULT 0 AFTER `capacity`",
            'district' => "ALTER TABLE `base` ADD COLUMN `district` VARCHAR(120) DEFAULT NULL AFTER `address`",
        ],
        'base_profession' => [
            'base_id' => "ALTER TABLE `base_profession` ADD COLUMN `base_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'profession_id' => "ALTER TABLE `base_profession` ADD COLUMN `profession_id` BIGINT UNSIGNED NOT NULL AFTER `base_id`",
        ],
        'base_person' => [
            'base_id' => "ALTER TABLE `base_person` ADD COLUMN `base_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'person_type' => "ALTER TABLE `base_person` ADD COLUMN `person_type` VARCHAR(20) NOT NULL AFTER `base_id`",
            'user_id' => "ALTER TABLE `base_person` ADD COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL AFTER `person_type`",
            'teacher_id' => "ALTER TABLE `base_person` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`",
            'name' => "ALTER TABLE `base_person` ADD COLUMN `name` VARCHAR(80) DEFAULT NULL AFTER `teacher_id`",
            'gender' => "ALTER TABLE `base_person` ADD COLUMN `gender` VARCHAR(20) DEFAULT NULL AFTER `name`",
            'birth_date' => "ALTER TABLE `base_person` ADD COLUMN `birth_date` VARCHAR(40) DEFAULT NULL AFTER `gender`",
            'title' => "ALTER TABLE `base_person` ADD COLUMN `title` VARCHAR(120) DEFAULT NULL AFTER `birth_date`",
            'education' => "ALTER TABLE `base_person` ADD COLUMN `education` VARCHAR(80) DEFAULT NULL AFTER `title`",
            'phone' => "ALTER TABLE `base_person` ADD COLUMN `phone` VARCHAR(40) DEFAULT NULL AFTER `education`",
            'duties' => "ALTER TABLE `base_person` ADD COLUMN `duties` TEXT DEFAULT NULL AFTER `phone`",
            'sort' => "ALTER TABLE `base_person` ADD COLUMN `sort` INT DEFAULT 0 AFTER `duties`",
        ],
        'base_existing_site' => [
            'base_id' => "ALTER TABLE `base_existing_site` ADD COLUMN `base_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'site_name' => "ALTER TABLE `base_existing_site` ADD COLUMN `site_name` VARCHAR(180) DEFAULT NULL AFTER `base_id`",
            'cooperation' => "ALTER TABLE `base_existing_site` ADD COLUMN `cooperation` TEXT DEFAULT NULL AFTER `site_name`",
            'sort' => "ALTER TABLE `base_existing_site` ADD COLUMN `sort` INT DEFAULT 0 AFTER `cooperation`",
        ],
        'base_company_profile' => [
            'base_id' => "ALTER TABLE `base_company_profile` ADD COLUMN `base_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'company_name' => "ALTER TABLE `base_company_profile` ADD COLUMN `company_name` VARCHAR(180) DEFAULT NULL AFTER `base_id`",
            'registered_capital' => "ALTER TABLE `base_company_profile` ADD COLUMN `registered_capital` VARCHAR(80) DEFAULT NULL AFTER `company_name`",
            'main_business' => "ALTER TABLE `base_company_profile` ADD COLUMN `main_business` TEXT DEFAULT NULL AFTER `registered_capital`",
            'employee_count' => "ALTER TABLE `base_company_profile` ADD COLUMN `employee_count` INT UNSIGNED DEFAULT 0 AFTER `main_business`",
            'annual_intern_count' => "ALTER TABLE `base_company_profile` ADD COLUMN `annual_intern_count` INT UNSIGNED DEFAULT 0 AFTER `employee_count`",
            'senior_title_count' => "ALTER TABLE `base_company_profile` ADD COLUMN `senior_title_count` INT UNSIGNED DEFAULT 0 AFTER `annual_intern_count`",
        ],
        'base_construction' => [
            'base_id' => "ALTER TABLE `base_construction` ADD COLUMN `base_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'content' => "ALTER TABLE `base_construction` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `base_id`",
        ],
        'base_budget' => [
            'base_id' => "ALTER TABLE `base_budget` ADD COLUMN `base_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'declaration_id' => "ALTER TABLE `base_budget` ADD COLUMN `declaration_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_id`",
            'item_name' => "ALTER TABLE `base_budget` ADD COLUMN `item_name` VARCHAR(180) DEFAULT NULL AFTER `declaration_id`",
            'content' => "ALTER TABLE `base_budget` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `item_name`",
            'amount' => "ALTER TABLE `base_budget` ADD COLUMN `amount` DECIMAL(12,2) DEFAULT NULL AFTER `content`",
            'remark' => "ALTER TABLE `base_budget` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `amount`",
            'sort' => "ALTER TABLE `base_budget` ADD COLUMN `sort` INT DEFAULT 0 AFTER `remark`",
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
            'submitter_id' => "ALTER TABLE `arrangement` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `created_by`",
            'required_journal_count' => "ALTER TABLE `arrangement` ADD COLUMN `required_journal_count` INT UNSIGNED DEFAULT 1 AFTER `created_by`",
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
            'location' => "ALTER TABLE `journal` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `teacher_id`",
            'work_content' => "ALTER TABLE `journal` ADD COLUMN `work_content` TEXT DEFAULT NULL AFTER `location`",
            'gains' => "ALTER TABLE `journal` ADD COLUMN `gains` TEXT DEFAULT NULL AFTER `work_content`",
            'problems' => "ALTER TABLE `journal` ADD COLUMN `problems` TEXT DEFAULT NULL AFTER `gains`",
            'form_data' => "ALTER TABLE `journal` ADD COLUMN `form_data` JSON DEFAULT NULL AFTER `problems`",
            'attachment_ids' => "ALTER TABLE `journal` ADD COLUMN `attachment_ids` JSON DEFAULT NULL AFTER `form_data`",
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
            'report_type' => "ALTER TABLE `report` ADD COLUMN `report_type` VARCHAR(40) DEFAULT 'general' AFTER `reviewed_at`",
            'form_data' => "ALTER TABLE `report` ADD COLUMN `form_data` JSON DEFAULT NULL AFTER `report_type`",
            'attachment_ids' => "ALTER TABLE `report` ADD COLUMN `attachment_ids` JSON DEFAULT NULL AFTER `form_data`",
            'practice_project_id' => "ALTER TABLE `report` ADD COLUMN `practice_project_id` BIGINT UNSIGNED DEFAULT NULL AFTER `attachment_ids`",
            'reflection_summary' => "ALTER TABLE `report` ADD COLUMN `reflection_summary` TEXT DEFAULT NULL AFTER `practice_project_id`",
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
        'course_score' => [
            'plan_id' => "ALTER TABLE `course_score` ADD COLUMN `plan_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'student_id' => "ALTER TABLE `course_score` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_id`",
            'score_value' => "ALTER TABLE `course_score` ADD COLUMN `score_value` DECIMAL(5,2) DEFAULT NULL AFTER `student_id`",
            'operator_id' => "ALTER TABLE `course_score` ADD COLUMN `operator_id` BIGINT UNSIGNED DEFAULT NULL AFTER `score_value`",
            'remark' => "ALTER TABLE `course_score` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `operator_id`",
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
            'source_plan_id' => "ALTER TABLE `internship_plan` ADD COLUMN `source_plan_id` VARCHAR(120) DEFAULT NULL AFTER `source_type`",
            'source_version' => "ALTER TABLE `internship_plan` ADD COLUMN `source_version` VARCHAR(80) DEFAULT NULL AFTER `source_plan_id`",
            'source_last_synced_at' => "ALTER TABLE `internship_plan` ADD COLUMN `source_last_synced_at` DATETIME DEFAULT NULL AFTER `source_version`",
            'course_code' => "ALTER TABLE `internship_plan` ADD COLUMN `course_code` VARCHAR(120) DEFAULT NULL AFTER `source_type`",
            'course_name' => "ALTER TABLE `internship_plan` ADD COLUMN `course_name` VARCHAR(180) DEFAULT NULL AFTER `course_code`",
            'course_category' => "ALTER TABLE `internship_plan` ADD COLUMN `course_category` VARCHAR(80) DEFAULT NULL AFTER `course_name`",
            'category_id' => "ALTER TABLE `internship_plan` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `course_category`",
            'grade_id' => "ALTER TABLE `internship_plan` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `category_id`",
            'graduation_cohort_id' => "ALTER TABLE `internship_plan` ADD COLUMN `graduation_cohort_id` BIGINT UNSIGNED DEFAULT NULL AFTER `grade_id`",
            'dep_id' => "ALTER TABLE `internship_plan` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `grade_id`",
            'profession_id' => "ALTER TABLE `internship_plan` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'semester' => "ALTER TABLE `internship_plan` ADD COLUMN `semester` VARCHAR(80) DEFAULT NULL AFTER `profession_id`",
            'credit' => "ALTER TABLE `internship_plan` ADD COLUMN `credit` DECIMAL(5,2) DEFAULT NULL AFTER `semester`",
            'total_credit' => "ALTER TABLE `internship_plan` ADD COLUMN `total_credit` DECIMAL(5,2) DEFAULT NULL AFTER `credit`",
            'internship_credit' => "ALTER TABLE `internship_plan` ADD COLUMN `internship_credit` DECIMAL(5,2) DEFAULT NULL AFTER `total_credit`",
            'total_hours' => "ALTER TABLE `internship_plan` ADD COLUMN `total_hours` VARCHAR(40) DEFAULT NULL AFTER `internship_credit`",
            'internship_hours' => "ALTER TABLE `internship_plan` ADD COLUMN `internship_hours` VARCHAR(40) DEFAULT NULL AFTER `total_hours`",
            'source_teacher' => "ALTER TABLE `internship_plan` ADD COLUMN `source_teacher` TEXT DEFAULT NULL AFTER `internship_hours`",
            'source_time' => "ALTER TABLE `internship_plan` ADD COLUMN `source_time` TEXT DEFAULT NULL AFTER `source_teacher`",
            'source_location' => "ALTER TABLE `internship_plan` ADD COLUMN `source_location` TEXT DEFAULT NULL AFTER `source_time`",
            'remark' => "ALTER TABLE `internship_plan` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `source_location`",
            'source_row' => "ALTER TABLE `internship_plan` ADD COLUMN `source_row` JSON DEFAULT NULL AFTER `remark`",
            'business_type' => "ALTER TABLE `internship_plan` ADD COLUMN `business_type` VARCHAR(40) DEFAULT 'internship' AFTER `source_row`",
            'import_file_id' => "ALTER TABLE `internship_plan` ADD COLUMN `import_file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `business_type`",
            'imported_at' => "ALTER TABLE `internship_plan` ADD COLUMN `imported_at` DATETIME DEFAULT NULL AFTER `import_file_id`",
            'student_count' => "ALTER TABLE `internship_plan` ADD COLUMN `student_count` INT DEFAULT 0 AFTER `imported_at`",
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
        'internship_plan_sync_inbox' => [
            'source_plan_id' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `source_plan_id` VARCHAR(120) NOT NULL AFTER `code`",
            'source_version' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `source_version` VARCHAR(80) NOT NULL AFTER `source_plan_id`",
            'source_course_id' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `source_course_id` VARCHAR(120) DEFAULT NULL AFTER `source_version`",
            'source_status' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `source_status` VARCHAR(40) DEFAULT NULL AFTER `source_course_id`",
            'grade_code' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `grade_code` VARCHAR(80) DEFAULT NULL AFTER `source_status`",
            'grade_name' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `grade_name` VARCHAR(120) DEFAULT NULL AFTER `grade_code`",
            'dep_code' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `dep_code` VARCHAR(80) DEFAULT NULL AFTER `grade_name`",
            'dep_name' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `dep_name` VARCHAR(120) DEFAULT NULL AFTER `dep_code`",
            'profession_code' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `profession_code` VARCHAR(80) DEFAULT NULL AFTER `dep_name`",
            'profession_name' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `profession_name` VARCHAR(180) DEFAULT NULL AFTER `profession_code`",
            'education_level' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `education_level` VARCHAR(80) DEFAULT NULL AFTER `profession_name`",
            'scheme_name' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `scheme_name` VARCHAR(180) DEFAULT NULL AFTER `education_level`",
            'scheme_version' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `scheme_version` VARCHAR(80) DEFAULT NULL AFTER `scheme_name`",
            'course_code' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `course_code` VARCHAR(120) DEFAULT NULL AFTER `scheme_version`",
            'course_name' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `course_name` VARCHAR(180) DEFAULT NULL AFTER `course_code`",
            'course_category' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `course_category` VARCHAR(80) DEFAULT NULL AFTER `course_name`",
            'course_nature' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `course_nature` VARCHAR(80) DEFAULT NULL AFTER `course_category`",
            'credit' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `credit` DECIMAL(5,2) DEFAULT NULL AFTER `course_nature`",
            'weekly_hours' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `weekly_hours` DECIMAL(6,2) DEFAULT NULL AFTER `credit`",
            'total_hours' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `total_hours` DECIMAL(8,2) DEFAULT NULL AFTER `weekly_hours`",
            'theory_hours' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `theory_hours` DECIMAL(8,2) DEFAULT NULL AFTER `total_hours`",
            'experiment_hours' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `experiment_hours` DECIMAL(8,2) DEFAULT NULL AFTER `theory_hours`",
            'practice_hours' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `practice_hours` DECIMAL(8,2) DEFAULT NULL AFTER `experiment_hours`",
            'computer_hours' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `computer_hours` DECIMAL(8,2) DEFAULT NULL AFTER `practice_hours`",
            'other_hours' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `other_hours` DECIMAL(8,2) DEFAULT NULL AFTER `computer_hours`",
            'source_period' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `source_period` VARCHAR(80) DEFAULT NULL AFTER `other_hours`",
            'source_updated_at' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `source_updated_at` DATETIME DEFAULT NULL AFTER `source_period`",
            'mapping_status' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `mapping_status` VARCHAR(40) DEFAULT 'pending' AFTER `source_updated_at`",
            'mapped_grade_id' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `mapped_grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `mapping_status`",
            'mapped_dep_id' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `mapped_dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `mapped_grade_id`",
            'mapped_profession_id' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `mapped_profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `mapped_dep_id`",
            'raw_payload' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `raw_payload` JSON DEFAULT NULL AFTER `mapped_profession_id`",
            'diff_payload' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `diff_payload` JSON DEFAULT NULL AFTER `raw_payload`",
            'sync_batch_no' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `sync_batch_no` VARCHAR(120) DEFAULT NULL AFTER `diff_payload`",
            'local_plan_id' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `local_plan_id` BIGINT UNSIGNED DEFAULT NULL AFTER `sync_batch_no`",
            'confirmed_by' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `confirmed_by` BIGINT UNSIGNED DEFAULT NULL AFTER `local_plan_id`",
            'confirmed_at' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `confirmed_by`",
            'ignored_by' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `ignored_by` BIGINT UNSIGNED DEFAULT NULL AFTER `confirmed_at`",
            'ignored_at' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `ignored_at` DATETIME DEFAULT NULL AFTER `ignored_by`",
            'ignore_reason' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `ignore_reason` VARCHAR(500) DEFAULT NULL AFTER `ignored_at`",
            'received_at' => "ALTER TABLE `internship_plan_sync_inbox` ADD COLUMN `received_at` DATETIME DEFAULT NULL AFTER `ignore_reason`",
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
            'plan_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `plan_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'document_type' => "ALTER TABLE `syllabus_guide` ADD COLUMN `document_type` VARCHAR(40) DEFAULT 'syllabus' AFTER `plan_id`",
            'dep_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'profession_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
            'title' => "ALTER TABLE `syllabus_guide` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `profession_id`",
            'content' => "ALTER TABLE `syllabus_guide` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'file_id' => "ALTER TABLE `syllabus_guide` ADD COLUMN `file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `content`",
            'created_by' => "ALTER TABLE `syllabus_guide` ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `file_id`",
            'form_data' => "ALTER TABLE `syllabus_guide` ADD COLUMN `form_data` JSON DEFAULT NULL AFTER `created_by`",
        ],
        'implementation_sheet' => [
            'arrangement_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `arrangement_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
            'teacher_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `arrangement_id`",
            'plan_ref_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `plan_ref_id` BIGINT UNSIGNED DEFAULT NULL AFTER `teacher_id`",
            'syllabus_ref_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `syllabus_ref_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_ref_id`",
            'applicant_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `applicant_id` BIGINT UNSIGNED DEFAULT NULL AFTER `syllabus_ref_id`",
            'applicant_name' => "ALTER TABLE `implementation_sheet` ADD COLUMN `applicant_name` VARCHAR(80) DEFAULT NULL AFTER `applicant_id`",
            'applicant_department' => "ALTER TABLE `implementation_sheet` ADD COLUMN `applicant_department` VARCHAR(180) DEFAULT NULL AFTER `applicant_name`",
            'submitted_at' => "ALTER TABLE `implementation_sheet` ADD COLUMN `submitted_at` DATETIME DEFAULT NULL AFTER `applicant_department`",
            'approval_no' => "ALTER TABLE `implementation_sheet` ADD COLUMN `approval_no` VARCHAR(120) DEFAULT NULL AFTER `submitted_at`",
            'grade_id' => "ALTER TABLE `implementation_sheet` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `approval_no`",
            'course_name' => "ALTER TABLE `implementation_sheet` ADD COLUMN `course_name` VARCHAR(180) DEFAULT NULL AFTER `grade_id`",
            'course_type' => "ALTER TABLE `implementation_sheet` ADD COLUMN `course_type` VARCHAR(80) DEFAULT NULL AFTER `course_name`",
            'detail_content' => "ALTER TABLE `implementation_sheet` ADD COLUMN `detail_content` TEXT DEFAULT NULL AFTER `course_type`",
            'credit' => "ALTER TABLE `implementation_sheet` ADD COLUMN `credit` DECIMAL(5,2) DEFAULT NULL AFTER `detail_content`",
            'practice_type' => "ALTER TABLE `implementation_sheet` ADD COLUMN `practice_type` VARCHAR(80) DEFAULT NULL AFTER `credit`",
            'internship_mode' => "ALTER TABLE `implementation_sheet` ADD COLUMN `internship_mode` VARCHAR(80) DEFAULT NULL AFTER `practice_type`",
            'organize_mode' => "ALTER TABLE `implementation_sheet` ADD COLUMN `organize_mode` VARCHAR(80) DEFAULT NULL AFTER `internship_mode`",
            'total_people' => "ALTER TABLE `implementation_sheet` ADD COLUMN `total_people` INT UNSIGNED DEFAULT 0 AFTER `organize_mode`",
            'total_amount' => "ALTER TABLE `implementation_sheet` ADD COLUMN `total_amount` DECIMAL(12,2) DEFAULT 0 AFTER `total_people`",
            'attachment_ids' => "ALTER TABLE `implementation_sheet` ADD COLUMN `attachment_ids` JSON DEFAULT NULL AFTER `total_amount`",
            'remark' => "ALTER TABLE `implementation_sheet` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `attachment_ids`",
            'signed_count' => "ALTER TABLE `implementation_sheet` ADD COLUMN `signed_count` INT DEFAULT 0 AFTER `remark`",
            'unsigned_count' => "ALTER TABLE `implementation_sheet` ADD COLUMN `unsigned_count` INT DEFAULT 0 AFTER `signed_count`",
            'insurance_verified' => "ALTER TABLE `implementation_sheet` ADD COLUMN `insurance_verified` ENUM('false','true') DEFAULT 'false' AFTER `unsigned_count`",
            'fee_detail' => "ALTER TABLE `implementation_sheet` ADD COLUMN `fee_detail` JSON DEFAULT NULL AFTER `insurance_verified`",
            'sheet_json' => "ALTER TABLE `implementation_sheet` ADD COLUMN `sheet_json` JSON DEFAULT NULL AFTER `fee_detail`",
            'confirmed_at' => "ALTER TABLE `implementation_sheet` ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `sheet_json`",
        ],
        'implementation_schedule' => [
            'implementation_id' => "ALTER TABLE `implementation_schedule` ADD COLUMN `implementation_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'profession_id' => "ALTER TABLE `implementation_schedule` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `implementation_id`",
            'profession_name' => "ALTER TABLE `implementation_schedule` ADD COLUMN `profession_name` VARCHAR(180) DEFAULT NULL AFTER `profession_id`",
            'grade_id' => "ALTER TABLE `implementation_schedule` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `profession_name`",
            'grade_name' => "ALTER TABLE `implementation_schedule` ADD COLUMN `grade_name` VARCHAR(80) DEFAULT NULL AFTER `grade_id`",
            'people_count' => "ALTER TABLE `implementation_schedule` ADD COLUMN `people_count` INT UNSIGNED DEFAULT 0 AFTER `grade_name`",
            'week_text' => "ALTER TABLE `implementation_schedule` ADD COLUMN `week_text` VARCHAR(120) DEFAULT NULL AFTER `people_count`",
            'weekday_text' => "ALTER TABLE `implementation_schedule` ADD COLUMN `weekday_text` VARCHAR(120) DEFAULT NULL AFTER `week_text`",
            'location' => "ALTER TABLE `implementation_schedule` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `weekday_text`",
            'time_text' => "ALTER TABLE `implementation_schedule` ADD COLUMN `time_text` VARCHAR(255) DEFAULT NULL AFTER `location`",
            'teacher_id' => "ALTER TABLE `implementation_schedule` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `time_text`",
            'teacher_name' => "ALTER TABLE `implementation_schedule` ADD COLUMN `teacher_name` VARCHAR(80) DEFAULT NULL AFTER `teacher_id`",
            'sort' => "ALTER TABLE `implementation_schedule` ADD COLUMN `sort` INT DEFAULT 0 AFTER `teacher_name`",
        ],
        'implementation_expense' => [
            'implementation_id' => "ALTER TABLE `implementation_expense` ADD COLUMN `implementation_id` BIGINT UNSIGNED NOT NULL AFTER `code`",
            'item_name' => "ALTER TABLE `implementation_expense` ADD COLUMN `item_name` VARCHAR(180) DEFAULT NULL AFTER `implementation_id`",
            'content' => "ALTER TABLE `implementation_expense` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `item_name`",
            'amount' => "ALTER TABLE `implementation_expense` ADD COLUMN `amount` DECIMAL(12,2) DEFAULT NULL AFTER `content`",
            'remark' => "ALTER TABLE `implementation_expense` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `amount`",
            'sort' => "ALTER TABLE `implementation_expense` ADD COLUMN `sort` INT DEFAULT 0 AFTER `remark`",
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
        'internship_graduation_appraisal' => [
            'process_score' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `process_score` DECIMAL(5,2) DEFAULT NULL AFTER `teacher_id`",
            'enterprise_score' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `enterprise_score` DECIMAL(5,2) DEFAULT NULL AFTER `process_score`",
            'school_score' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `school_score` DECIMAL(5,2) DEFAULT NULL AFTER `enterprise_score`",
            'report_score' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `report_score` DECIMAL(5,2) DEFAULT NULL AFTER `school_score`",
            'final_score' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `final_score` DECIMAL(5,2) DEFAULT NULL AFTER `report_score`",
            'grade_level' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `grade_level` VARCHAR(40) DEFAULT NULL AFTER `final_score`",
            'enterprise_comment' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `enterprise_comment` TEXT DEFAULT NULL AFTER `grade_level`",
            'school_comment' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `school_comment` TEXT DEFAULT NULL AFTER `enterprise_comment`",
            'form_data' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `form_data` JSON DEFAULT NULL AFTER `school_comment`",
            'attachment_id' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `attachment_id` BIGINT UNSIGNED DEFAULT NULL AFTER `form_data`",
            'submitted_at' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `submitted_at` DATETIME DEFAULT NULL AFTER `attachment_id`",
            'reviewed_at' => "ALTER TABLE `internship_graduation_appraisal` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL AFTER `submitted_at`",
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

    foreach (['application_recording', 'arrangement_recording', 'arrangement_change_recording', 'sign_in_recording', 'journal_recording', 'report_recording', 'internship_graduation_appraisal_recording', 'internship_archive_material_recording', 'join_recording', 'apply_report_delay_recording', 'score_recording', 'plan_recording', 'insurance_recording', 'safety_letter_recording', 'syllabus_guide_recording', 'implementation_sheet_recording', 'teacher_work_report_recording', 'inspection_recording'] as $table) {
        ensureColumn($pdo, $table, 'parent_id', "ALTER TABLE `{$table}` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`");
        ensureColumn($pdo, $table, 'action', "ALTER TABLE `{$table}` ADD COLUMN `action` VARCHAR(40) DEFAULT NULL AFTER `parent_id`");
        ensureColumn($pdo, $table, 'operator_id', "ALTER TABLE `{$table}` ADD COLUMN `operator_id` BIGINT UNSIGNED DEFAULT NULL AFTER `action`");
        ensureColumn($pdo, $table, 'content', "ALTER TABLE `{$table}` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `operator_id`");
        ensureIndex($pdo, $table, 'idx_parent', "ALTER TABLE `{$table}` ADD KEY `idx_parent` (`parent_id`)");
        ensureIndex($pdo, $table, "idx_{$table}_timeline", "ALTER TABLE `{$table}` ADD KEY `idx_{$table}_timeline` (`parent_id`, `action`, `created_at`)");
    }

    ensureIndex($pdo, 'base_profession_direction', 'uk_base_profession_direction', "ALTER TABLE `base_profession_direction` ADD UNIQUE KEY `uk_base_profession_direction` (`base_id`, `profession_id`, `direction_id`)");
    ensureIndex($pdo, 'base', 'idx_base_scope', "ALTER TABLE `base` ADD KEY `idx_base_scope` (`dep_id`, `base_type`, `status`)");
    ensureIndex($pdo, 'base_person', 'idx_base_teacher', "ALTER TABLE `base_person` ADD KEY `idx_base_teacher` (`base_id`, `teacher_id`, `person_type`, `status`)");
    ensureIndex($pdo, 'base_budget', 'idx_base_declaration', "ALTER TABLE `base_budget` ADD KEY `idx_base_declaration` (`declaration_id`, `sort`, `status`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_scope', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_scope` (`dep_id`, `profession_id`, `status`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_plan', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_plan` (`plan_id`, `teacher_id`, `status`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_plan_task', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_plan_task` (`plan_id`, `task_no`, `status`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_teacher_time', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_teacher_time` (`teacher_id`, `start_date`, `end_date`)");
    ensureIndex($pdo, 'arrangement', 'idx_arrangement_submitter', "ALTER TABLE `arrangement` ADD KEY `idx_arrangement_submitter` (`submitter_id`, `status`)");
    ensureIndex($pdo, 'arrangement_change', 'idx_arrangement_change_task', "ALTER TABLE `arrangement_change` ADD KEY `idx_arrangement_change_task` (`arrangement_id`, `status`)");
    ensureIndex($pdo, 'arrangement_change', 'idx_arrangement_change_submitter', "ALTER TABLE `arrangement_change` ADD KEY `idx_arrangement_change_submitter` (`submitter_id`, `status`)");
    ensureIndex($pdo, 'internship_plan', 'idx_internship_plan_scope', "ALTER TABLE `internship_plan` ADD KEY `idx_internship_plan_scope` (`grade_id`, `dep_id`, `profession_id`, `status`)");
    ensureIndex($pdo, 'internship_plan', 'idx_internship_plan_category', "ALTER TABLE `internship_plan` ADD KEY `idx_internship_plan_category` (`category_id`, `status`)");
    ensureIndex($pdo, 'internship_plan', 'idx_internship_plan_cohort', "ALTER TABLE `internship_plan` ADD KEY `idx_internship_plan_cohort` (`graduation_cohort_id`, `dep_id`, `profession_id`, `status`)");
    ensureIndex($pdo, 'internship_plan', 'idx_internship_plan_import_code', "ALTER TABLE `internship_plan` ADD KEY `idx_internship_plan_import_code` (`grade_id`, `dep_id`, `profession_id`, `course_code`, `status`)");
    ensureIndex($pdo, 'internship_plan', 'idx_internship_plan_import_name', "ALTER TABLE `internship_plan` ADD KEY `idx_internship_plan_import_name` (`grade_id`, `dep_id`, `profession_id`, `course_name`, `status`)");
    ensureIndex($pdo, 'internship_plan', 'idx_internship_plan_file', "ALTER TABLE `internship_plan` ADD KEY `idx_internship_plan_file` (`import_file_id`)");
    ensureIndex($pdo, 'internship_plan', 'uk_internship_plan_source_version', "ALTER TABLE `internship_plan` ADD UNIQUE KEY `uk_internship_plan_source_version` (`source_plan_id`, `source_version`)");
    ensureIndex($pdo, 'internship_plan_sync_inbox', 'uk_plan_version', "ALTER TABLE `internship_plan_sync_inbox` ADD UNIQUE KEY `uk_plan_version` (`source_plan_id`, `source_version`)");
    ensureIndex($pdo, 'internship_plan_sync_inbox', 'idx_inbox_status', "ALTER TABLE `internship_plan_sync_inbox` ADD KEY `idx_inbox_status` (`status`, `received_at`)");
    ensureIndex($pdo, 'internship_plan_sync_inbox', 'idx_inbox_mapping', "ALTER TABLE `internship_plan_sync_inbox` ADD KEY `idx_inbox_mapping` (`mapping_status`, `mapped_dep_id`, `mapped_profession_id`)");
    ensureIndex($pdo, 'internship_plan_sync_inbox', 'idx_inbox_local_plan', "ALTER TABLE `internship_plan_sync_inbox` ADD KEY `idx_inbox_local_plan` (`local_plan_id`, `status`)");
    ensureIndex($pdo, 'implementation_sheet', 'idx_implementation_arrangement', "ALTER TABLE `implementation_sheet` ADD KEY `idx_implementation_arrangement` (`arrangement_id`, `status`)");
    ensureIndex($pdo, 'implementation_schedule', 'idx_implementation_schedule', "ALTER TABLE `implementation_schedule` ADD KEY `idx_implementation_schedule` (`implementation_id`, `sort`, `status`)");
    ensureIndex($pdo, 'implementation_expense', 'idx_implementation_expense', "ALTER TABLE `implementation_expense` ADD KEY `idx_implementation_expense` (`implementation_id`, `sort`, `status`)");
    ensureIndex($pdo, 'syllabus_guide', 'idx_syllabus_plan_type', "ALTER TABLE `syllabus_guide` ADD KEY `idx_syllabus_plan_type` (`plan_id`, `document_type`, `status`)");
    ensureIndex($pdo, 'report', 'idx_report_student_task_type', "ALTER TABLE `report` ADD KEY `idx_report_student_task_type` (`student_id`, `arrangement_id`, `report_type`, `status`)");
    ensureIndex($pdo, 'internship_graduation_appraisal', 'idx_graduation_appraisal_student_task', "ALTER TABLE `internship_graduation_appraisal` ADD KEY `idx_graduation_appraisal_student_task` (`student_id`, `arrangement_id`, `status`)");
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
    ensureIndex($pdo, 'report', 'idx_report_student_entity', "ALTER TABLE `report` ADD KEY `idx_report_student_entity` (`student_id`, `entity_type`, `entity_id`, `status`)");
    ensureIndex($pdo, 'review_opinion', 'idx_review_entity', "ALTER TABLE `review_opinion` ADD KEY `idx_review_entity` (`entity_type`, `entity_id`, `status`, `created_at`)");
    ensureIndex($pdo, 'review_opinion_draft', 'uk_review_draft', "ALTER TABLE `review_opinion_draft` ADD UNIQUE KEY `uk_review_draft` (`entity_type`, `entity_id`, `reviewer_id`)");
    ensureIndex($pdo, 'review_opinion_draft', 'idx_review_draft_entity', "ALTER TABLE `review_opinion_draft` ADD KEY `idx_review_draft_entity` (`entity_type`, `entity_id`, `status`, `updated_at`)");
    ensureIndex($pdo, 'apply_report_delay', 'idx_delay_student_entity', "ALTER TABLE `apply_report_delay` ADD KEY `idx_delay_student_entity` (`student_id`, `entity_type`, `entity_id`, `status`)");
    ensureIndex($pdo, 'score', 'idx_score_student_arrangement', "ALTER TABLE `score` ADD KEY `idx_score_student_arrangement` (`student_id`, `arrangement_id`)");
    ensureIndex($pdo, 'course_score', 'uk_course_score', "ALTER TABLE `course_score` ADD UNIQUE KEY `uk_course_score` (`plan_id`, `student_id`)");
    ensureIndex($pdo, 'course_score', 'idx_course_score_student', "ALTER TABLE `course_score` ADD KEY `idx_course_score_student` (`student_id`, `status`)");
    ensureIndex($pdo, 'insurance', 'idx_insurance_student_task_date', "ALTER TABLE `insurance` ADD KEY `idx_insurance_student_task_date` (`student_id`, `arrangement_id`, `status`, `start_date`, `end_date`)");
    ensureIndex($pdo, 'insurance', 'idx_insurance_expiry', "ALTER TABLE `insurance` ADD KEY `idx_insurance_expiry` (`end_date`, `status`, `deleted_at`)");
    ensureIndex($pdo, 'safety_letter_sign', 'idx_safety_student_task_status', "ALTER TABLE `safety_letter_sign` ADD KEY `idx_safety_student_task_status` (`student_id`, `arrangement_id`, `status`, `signed_at`)");
    ensureArchiveRequirementIndex($pdo);
}

function ensurePracticeSchema(PDO $pdo): void
{
    $practiceTables = ['practice_plan', 'practice_schedule', 'practice_project', 'practice_syllabus', 'practice_lesson_plan', 'practice_grade_rule', 'practice_score', 'practice_reflection'];
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
            'course_leader_id' => "ALTER TABLE `practice_plan` ADD COLUMN `course_leader_id` BIGINT UNSIGNED DEFAULT NULL AFTER `submitter_id`",
            'course_leader_account_id' => "ALTER TABLE `practice_plan` ADD COLUMN `course_leader_account_id` BIGINT UNSIGNED DEFAULT NULL AFTER `course_leader_id`",
        ],
        'practice_schedule' => [
            'room_id' => "ALTER TABLE `practice_schedule` ADD COLUMN `room_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
            'base_id' => "ALTER TABLE `practice_schedule` ADD COLUMN `base_id` BIGINT UNSIGNED DEFAULT NULL AFTER `room_id`",
            'place_type' => "ALTER TABLE `practice_schedule` ADD COLUMN `place_type` VARCHAR(40) DEFAULT 'inside' AFTER `base_id`",
            'schedule_date' => "ALTER TABLE `practice_schedule` ADD COLUMN `schedule_date` DATE DEFAULT NULL AFTER `place_type`",
            'period_start_id' => "ALTER TABLE `practice_schedule` ADD COLUMN `period_start_id` BIGINT UNSIGNED DEFAULT NULL AFTER `schedule_date`",
            'period_end_id' => "ALTER TABLE `practice_schedule` ADD COLUMN `period_end_id` BIGINT UNSIGNED DEFAULT NULL AFTER `period_start_id`",
            'start_time' => "ALTER TABLE `practice_schedule` ADD COLUMN `start_time` VARCHAR(20) DEFAULT NULL AFTER `period_end_id`",
            'end_time' => "ALTER TABLE `practice_schedule` ADD COLUMN `end_time` VARCHAR(20) DEFAULT NULL AFTER `start_time`",
            'location' => "ALTER TABLE `practice_schedule` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `end_time`",
            'student_count' => "ALTER TABLE `practice_schedule` ADD COLUMN `student_count` INT DEFAULT 0 AFTER `location`",
            'roster_printed_at' => "ALTER TABLE `practice_schedule` ADD COLUMN `roster_printed_at` DATETIME DEFAULT NULL AFTER `student_count`",
        ],
        'practice_project' => [
            'schedule_id' => "ALTER TABLE `practice_project` ADD COLUMN `schedule_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
            'start_date' => "ALTER TABLE `practice_project` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `schedule_id`",
            'end_date' => "ALTER TABLE `practice_project` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`",
            'student_count' => "ALTER TABLE `practice_project` ADD COLUMN `student_count` INT DEFAULT 0 AFTER `end_date`",
            'published_at' => "ALTER TABLE `practice_project` ADD COLUMN `published_at` DATETIME DEFAULT NULL AFTER `student_count`",
            'submitter_id' => "ALTER TABLE `practice_project` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `published_at`",
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
            'submitter_id' => "ALTER TABLE `practice_score` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
            'project_id' => "ALTER TABLE `practice_score` ADD COLUMN `project_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
            'student_id' => "ALTER TABLE `practice_score` ADD COLUMN `student_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
            'rule_id' => "ALTER TABLE `practice_score` ADD COLUMN `rule_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'score_items' => "ALTER TABLE `practice_score` ADD COLUMN `score_items` JSON DEFAULT NULL AFTER `rule_id`",
            'score_value' => "ALTER TABLE `practice_score` ADD COLUMN `score_value` DECIMAL(5,2) DEFAULT NULL AFTER `score_items`",
        ],
        'practice_reflection' => [
            'submitter_id' => "ALTER TABLE `practice_reflection` ADD COLUMN `submitter_id` BIGINT UNSIGNED DEFAULT NULL AFTER `remark`",
        ],
        'practice_archive' => [
            'archive_file_id' => "ALTER TABLE `practice_archive` ADD COLUMN `archive_file_id` BIGINT UNSIGNED DEFAULT NULL AFTER `pending_items_json`",
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

    ensureIndex($pdo, 'practice_plan', 'idx_practice_plan_leader', "ALTER TABLE `practice_plan` ADD KEY `idx_practice_plan_leader` (`module_type`, `course_leader_id`, `status`)");
    ensureIndex($pdo, 'report', 'idx_report_practice_project', "ALTER TABLE `report` ADD KEY `idx_report_practice_project` (`practice_project_id`, `student_id`, `status`)");
    ensureIndex($pdo, 'practice_plan_teacher', 'uk_practice_plan_teacher_active', "ALTER TABLE `practice_plan_teacher` ADD UNIQUE KEY `uk_practice_plan_teacher_active` (`plan_id`, `teacher_id`, `active_flag`)");
    ensureIndex($pdo, 'practice_plan_teacher', 'idx_practice_plan_teacher_scope', "ALTER TABLE `practice_plan_teacher` ADD KEY `idx_practice_plan_teacher_scope` (`module_type`, `teacher_id`, `status`)");
    ensureIndex($pdo, 'practice_archive', 'uk_practice_archive_version', "ALTER TABLE `practice_archive` ADD UNIQUE KEY `uk_practice_archive_version` (`module_type`, `plan_id`, `version_no`)");
    ensureIndex($pdo, 'practice_archive', 'idx_practice_archive_plan', "ALTER TABLE `practice_archive` ADD KEY `idx_practice_archive_plan` (`module_type`, `plan_id`, `status`)");

    $pdo->exec("UPDATE `practice_plan`
        SET `course_leader_id` = `teacher_id`
        WHERE `course_leader_id` IS NULL AND `teacher_id` IS NOT NULL");
    $pdo->exec("INSERT INTO `practice_plan_teacher`
        (`uuid`, `status`, `created_at`, `updated_at`, `module_type`, `plan_id`, `teacher_id`, `teacher_role`, `sort`)
        SELECT UUID(), 'enabled', NOW(), NOW(), p.`module_type`, p.`id`, p.`course_leader_id`, 'leader', 0
        FROM `practice_plan` p
        WHERE p.`course_leader_id` IS NOT NULL
          AND p.`deleted_at` IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM `practice_plan_teacher` pt
              WHERE pt.`plan_id` = p.`id`
                AND pt.`teacher_id` = p.`course_leader_id`
                AND pt.`status` = 'enabled'
                AND pt.`deleted_at` IS NULL
          )");

    ensureIndex($pdo, 'practice_schedule', 'idx_practice_schedule_date', "ALTER TABLE `practice_schedule` ADD KEY `idx_practice_schedule_date` (`module_type`, `schedule_date`, `status`)");
    ensureIndex($pdo, 'practice_schedule', 'idx_practice_schedule_period', "ALTER TABLE `practice_schedule` ADD KEY `idx_practice_schedule_period` (`schedule_date`, `period_start_id`, `period_end_id`, `status`)");
    ensureIndex($pdo, 'practice_project', 'idx_practice_project_schedule', "ALTER TABLE `practice_project` ADD KEY `idx_practice_project_schedule` (`module_type`, `schedule_id`, `status`)");
    ensureIndex($pdo, 'practice_score', 'idx_practice_score_student', "ALTER TABLE `practice_score` ADD KEY `idx_practice_score_student` (`module_type`, `student_id`, `status`)");
    ensureIndex($pdo, 'practice_score', 'idx_practice_score_project', "ALTER TABLE `practice_score` ADD KEY `idx_practice_score_project` (`module_type`, `project_id`, `student_id`, `status`)");
    ensureIndex($pdo, 'practice_room', 'idx_practice_room_module', "ALTER TABLE `practice_room` ADD KEY `idx_practice_room_module` (`module_type`, `dep_id`, `status`)");
    ensureIndex($pdo, 'practice_recording', 'idx_practice_recording_entity', "ALTER TABLE `practice_recording` ADD KEY `idx_practice_recording_entity` (`module_type`, `entity_type`, `entity_id`)");
    ensureIndex($pdo, 'practice_recording', 'idx_practice_recording_parent', "ALTER TABLE `practice_recording` ADD KEY `idx_practice_recording_parent` (`parent_id`)");

    $projectStudentColumns = [
        'module_type' => "ALTER TABLE `practice_project_student` ADD COLUMN `module_type` ENUM('training','lab') DEFAULT 'training' AFTER `code`",
        'project_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `project_id` BIGINT UNSIGNED NOT NULL AFTER `module_type`",
        'student_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `student_id` BIGINT UNSIGNED NOT NULL AFTER `project_id`",
        'teacher_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
        'plan_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `plan_id` BIGINT UNSIGNED DEFAULT NULL AFTER `teacher_id`",
        'schedule_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `schedule_id` BIGINT UNSIGNED DEFAULT NULL AFTER `plan_id`",
        'grade_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `schedule_id`",
        'dep_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `dep_id` BIGINT UNSIGNED DEFAULT NULL AFTER `grade_id`",
        'profession_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `profession_id` BIGINT UNSIGNED DEFAULT NULL AFTER `dep_id`",
        'class_id' => "ALTER TABLE `practice_project_student` ADD COLUMN `class_id` BIGINT UNSIGNED DEFAULT NULL AFTER `profession_id`",
        'active_flag' => "ALTER TABLE `practice_project_student` ADD COLUMN `active_flag` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = 'active' AND `deleted_at` IS NULL THEN 1 ELSE NULL END) STORED AFTER `class_id`",
    ];
    foreach ($projectStudentColumns as $column => $ddl) {
        ensureColumn($pdo, 'practice_project_student', $column, $ddl);
    }
    ensureIndexColumns($pdo, 'practice_project_student', 'uk_project_student', ['project_id', 'student_id', 'active_flag'], "ALTER TABLE `practice_project_student` ADD UNIQUE KEY `uk_project_student` (`project_id`, `student_id`, `active_flag`)");
    ensureIndex($pdo, 'practice_project_student', 'idx_project_student', "ALTER TABLE `practice_project_student` ADD KEY `idx_project_student` (`module_type`, `student_id`, `status`)");

    $executionColumns = [
        'sign_in' => [
            'date' => "ALTER TABLE `sign_in` ADD COLUMN `date` DATE DEFAULT NULL AFTER `sign_time`",
            'location' => "ALTER TABLE `sign_in` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `latitude`",
            'sign_type' => "ALTER TABLE `sign_in` ADD COLUMN `sign_type` VARCHAR(40) DEFAULT 'gps' AFTER `location`",
            'remark' => "ALTER TABLE `sign_in` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `sign_type`",
        ],
        'journal' => [
            'teacher_id' => "ALTER TABLE `journal` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'date' => "ALTER TABLE `journal` ADD COLUMN `date` DATE DEFAULT NULL AFTER `teacher_id`",
            'remark' => "ALTER TABLE `journal` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `content`",
        ],
        'report' => [
            'teacher_id' => "ALTER TABLE `report` ADD COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL AFTER `student_id`",
            'date' => "ALTER TABLE `report` ADD COLUMN `date` DATE DEFAULT NULL AFTER `teacher_id`",
            'title' => "ALTER TABLE `report` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `date`",
            'content' => "ALTER TABLE `report` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
            'remark' => "ALTER TABLE `report` ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `submitted_at`",
        ],
    ];
    foreach ($executionColumns as $table => $columns) {
        foreach ($columns as $column => $ddl) {
            ensureColumn($pdo, $table, $column, $ddl);
        }
    }

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

    ensureIndex($pdo, 'base', 'idx_base_scope', "ALTER TABLE `base` ADD KEY `idx_base_scope` (`base_type`, `dep_id`, `status`)");
    ensureIndex($pdo, 'base_profession', 'uk_base_profession', "ALTER TABLE `base_profession` ADD UNIQUE KEY `uk_base_profession` (`base_id`, `profession_id`)");
    ensureIndex($pdo, 'base_person', 'idx_base_person_type', "ALTER TABLE `base_person` ADD KEY `idx_base_person_type` (`base_id`, `person_type`, `status`)");
    ensureIndex($pdo, 'base_person', 'idx_base_person_user', "ALTER TABLE `base_person` ADD KEY `idx_base_person_user` (`user_id`)");
    ensureIndex($pdo, 'base_existing_site', 'idx_base_existing_site', "ALTER TABLE `base_existing_site` ADD KEY `idx_base_existing_site` (`base_id`, `sort`, `status`)");
    ensureIndex($pdo, 'base_company_profile', 'uk_base_company_profile', "ALTER TABLE `base_company_profile` ADD UNIQUE KEY `uk_base_company_profile` (`base_id`)");
    ensureIndex($pdo, 'base_construction', 'uk_base_construction', "ALTER TABLE `base_construction` ADD UNIQUE KEY `uk_base_construction` (`base_id`)");
    ensureIndex($pdo, 'base_budget', 'idx_base_budget', "ALTER TABLE `base_budget` ADD KEY `idx_base_budget` (`base_id`, `sort`, `status`)");
}

function ensureSocialPracticeSchema(PDO $pdo): void
{
    $pdo->exec(
        "ALTER TABLE `pair`
         MODIFY COLUMN `type` ENUM('internship','training','lab','social_practice') DEFAULT 'internship'"
    );

    ensureColumn(
        $pdo,
        'social_practice_plan',
        'current_node_id',
        "ALTER TABLE `social_practice_plan` ADD COLUMN `current_node_id` BIGINT UNSIGNED DEFAULT NULL AFTER `approval_flow_id`"
    );
    ensureColumn(
        $pdo,
        'social_practice_declaration',
        'teacher_reselect_count',
        "ALTER TABLE `social_practice_declaration` ADD COLUMN `teacher_reselect_count` INT UNSIGNED DEFAULT 0 AFTER `teacher_confirm_status`"
    );

    $indexes = [
        ['social_practice_plan', 'idx_social_plan_scope', "ALTER TABLE `social_practice_plan` ADD KEY `idx_social_plan_scope` (`grade_id`, `organizer_dep_id`, `status`, `phase`)"],
        ['social_practice_plan_scope', 'idx_social_scope_org', "ALTER TABLE `social_practice_plan_scope` ADD KEY `idx_social_scope_org` (`dep_id`, `profession_id`, `class_id`, `status`)"],
        ['social_practice_project', 'idx_social_project_plan', "ALTER TABLE `social_practice_project` ADD KEY `idx_social_project_plan` (`plan_id`, `practice_mode`, `status`, `phase`)"],
        ['social_practice_project_teacher', 'idx_social_teacher_project', "ALTER TABLE `social_practice_project_teacher` ADD KEY `idx_social_teacher_project` (`teacher_id`, `project_id`, `status`)"],
        ['social_practice_participant', 'idx_social_participant_project', "ALTER TABLE `social_practice_participant` ADD KEY `idx_social_participant_project` (`project_id`, `teacher_id`, `status`)"],
        ['social_practice_participant', 'idx_social_participant_student', "ALTER TABLE `social_practice_participant` ADD KEY `idx_social_participant_student` (`student_id`, `status`)"],
        ['social_practice_declaration', 'idx_social_declaration_plan', "ALTER TABLE `social_practice_declaration` ADD KEY `idx_social_declaration_plan` (`plan_id`, `status`, `teacher_confirm_status`)"],
        ['social_practice_declaration', 'idx_social_declaration_teacher', "ALTER TABLE `social_practice_declaration` ADD KEY `idx_social_declaration_teacher` (`selected_teacher_id`, `assigned_teacher_id`, `status`)"],
        ['social_practice_material', 'idx_social_material_target', "ALTER TABLE `social_practice_material` ADD KEY `idx_social_material_target` (`plan_id`, `project_id`, `student_id`, `material_type`, `status`)"],
        ['social_practice_score', 'idx_social_score_review', "ALTER TABLE `social_practice_score` ADD KEY `idx_social_score_review` (`teacher_id`, `status`, `reviewed_at`)"],
        ['social_practice_archive', 'idx_social_archive_scope', "ALTER TABLE `social_practice_archive` ADD KEY `idx_social_archive_scope` (`plan_id`, `project_id`, `status`, `archived_at`)"],
        ['social_practice_recording', 'idx_social_recording_entity', "ALTER TABLE `social_practice_recording` ADD KEY `idx_social_recording_entity` (`entity_type`, `entity_id`, `created_at`)"],
        ['social_practice_recording', 'idx_social_recording_parent', "ALTER TABLE `social_practice_recording` ADD KEY `idx_social_recording_parent` (`parent_id`)"],
    ];

    foreach ($indexes as [$table, $index, $ddl]) {
        ensureIndex($pdo, $table, $index, $ddl);
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
            'type' => "ALTER TABLE `message_template` ADD COLUMN `type` VARCHAR(40) DEFAULT 'system' AFTER `content_tpl`",
            'level' => "ALTER TABLE `message_template` ADD COLUMN `level` VARCHAR(40) DEFAULT 'normal' AFTER `type`",
            'description' => "ALTER TABLE `message_template` ADD COLUMN `description` VARCHAR(500) DEFAULT NULL AFTER `level`",
            'variables' => "ALTER TABLE `message_template` ADD COLUMN `variables` JSON DEFAULT NULL AFTER `description`",
            'link_url_tpl' => "ALTER TABLE `message_template` ADD COLUMN `link_url_tpl` VARCHAR(500) DEFAULT NULL AFTER `variables`",
            'is_system' => "ALTER TABLE `message_template` ADD COLUMN `is_system` TINYINT(1) DEFAULT 0 AFTER `channels`",
            'sort' => "ALTER TABLE `message_template` ADD COLUMN `sort` INT DEFAULT 100 AFTER `is_system`",
        ],
        'user_notify_setting' => [
            'quiet_start' => "ALTER TABLE `user_notify_setting` ADD COLUMN `quiet_start` VARCHAR(5) NOT NULL DEFAULT '00:00'",
            'quiet_end' => "ALTER TABLE `user_notify_setting` ADD COLUMN `quiet_end` VARCHAR(5) NOT NULL DEFAULT '24:00'",
        ],
        'message_channel_log' => [
            'attempts' => "ALTER TABLE `message_channel_log` ADD COLUMN `attempts` INT UNSIGNED NOT NULL DEFAULT 0",
            'available_at' => "ALTER TABLE `message_channel_log` ADD COLUMN `available_at` DATETIME DEFAULT NULL",
            'locked_until' => "ALTER TABLE `message_channel_log` ADD COLUMN `locked_until` DATETIME DEFAULT NULL",
            'claim_token' => "ALTER TABLE `message_channel_log` ADD COLUMN `claim_token` VARCHAR(64) DEFAULT NULL",

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
    ensureIndex($pdo, 'message_template', 'idx_type_status', "ALTER TABLE `message_template` ADD KEY `idx_type_status` (`type`, `status`, `sort`)");
    ensureIndex($pdo, 'message_channel_log', 'idx_channel_available', "ALTER TABLE `message_channel_log` ADD KEY `idx_channel_available` (`channel`, `status`, `available_at`)");
    ensureIndex($pdo, 'message_channel_log', 'idx_message_account', "ALTER TABLE `message_channel_log` ADD KEY `idx_message_account` (`message_id`, `account_id`)");
    ensureIndex($pdo, 'message_channel_log', 'idx_channel_status', "ALTER TABLE `message_channel_log` ADD KEY `idx_channel_status` (`channel`, `status`)");
    ensureIndex($pdo, 'message_channel_log', 'idx_deleted_at', "ALTER TABLE `message_channel_log` ADD KEY `idx_deleted_at` (`deleted_at`)");
}

function ensureDocSchema(PDO $pdo): void
{
    $schemas = DocRecord::columnDefinitions();

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
            'business_code' => "ALTER TABLE `template` ADD COLUMN `business_code` VARCHAR(80) DEFAULT NULL AFTER `flag`",
            'material_type' => "ALTER TABLE `template` ADD COLUMN `material_type` VARCHAR(60) DEFAULT NULL AFTER `business_code`",
            'scope_type' => "ALTER TABLE `template` ADD COLUMN `scope_type` VARCHAR(40) DEFAULT NULL AFTER `material_type`",
            'practice_types' => "ALTER TABLE `template` ADD COLUMN `practice_types` JSON DEFAULT NULL AFTER `scope_type`",
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
    ensureIndex($pdo, 'template', 'idx_template_business_material', "ALTER TABLE `template` ADD KEY `idx_template_business_material` (`business_code`, `material_type`, `status`)");
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
    ensureIndex($pdo, 'export_task', 'idx_status_started', "ALTER TABLE `export_task` ADD KEY `idx_status_started` (`status`, `started_at`)");
    ensureIndex($pdo, 'export_task', 'idx_deleted_at', "ALTER TABLE `export_task` ADD KEY `idx_deleted_at` (`deleted_at`)");
}

function simpleTable(string $table, array $columns = [], string $statusDefault = 'enabled'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,39}$/D', $statusDefault)) {
        throw new InvalidArgumentException('默认状态无效');
    }
    $extra = $columns ? ",\n            " . implode(",\n            ", $columns) : '';

    return "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT '{$statusDefault}',
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

function recordingArchiveColumns(): array
{
    return [
        '`source_table` VARCHAR(80) DEFAULT NULL',
        '`source_id` BIGINT UNSIGNED DEFAULT NULL',
        '`parent_table` VARCHAR(80) DEFAULT NULL',
        '`parent_id` BIGINT UNSIGNED DEFAULT NULL',
        '`grade_id` BIGINT UNSIGNED DEFAULT NULL',
        '`entity_type` VARCHAR(40) DEFAULT NULL',
        '`entity_id` BIGINT UNSIGNED DEFAULT NULL',
        '`action` VARCHAR(40) DEFAULT NULL',
        '`operator_id` BIGINT UNSIGNED DEFAULT NULL',
        '`from_status` VARCHAR(40) DEFAULT NULL',
        '`to_status` VARCHAR(40) DEFAULT NULL',
        '`content` MEDIUMTEXT DEFAULT NULL',
        '`snapshot_data` JSON DEFAULT NULL',
        '`review_snapshot` JSON DEFAULT NULL',
        '`original_created_at` DATETIME DEFAULT NULL',
        '`archived_at` DATETIME DEFAULT NULL',
        'UNIQUE KEY `uk_source` (`source_table`, `source_id`)',
        'KEY `idx_parent` (`parent_table`, `parent_id`)',
        'KEY `idx_grade` (`grade_id`)',
        'KEY `idx_entity` (`entity_type`, `entity_id`)',
        'KEY `idx_original_created` (`original_created_at`)',
    ];
}

function seedSchool(PDO $pdo, string $wechatProxyUrl): void
{
    seedRoles($pdo);
    seedPracticePeriods($pdo);
    seedInternshipCategories($pdo);
    seedArchiveRequirements($pdo);
    seedArchives($pdo);
    seedAdmin($pdo);
    seedPermissionAccounts($pdo);
    seedPracticeUsers($pdo);
    seedMenus($pdo);
    seedOperationGuides($pdo);
    seedCommonSupportData($pdo);
    seedMessageTemplates($pdo);
    seedConfig($pdo, $wechatProxyUrl);
    seedInternshipDemo($pdo);
    seedSocialPracticeDemo($pdo);
}

function seedInternshipCategories(PDO $pdo): void
{
    $categories = [
        [1, '00000000-0000-0000-0000-000000320001', '毕业实习', 'graduation', 'cohort', 10],
        [2, '00000000-0000-0000-0000-000000320002', '认识实习', 'cognition', 'grade', 20],
        [3, '00000000-0000-0000-0000-000000320003', '生产实习', 'production', 'grade', 30],
        [4, '00000000-0000-0000-0000-000000320004', '岗位实习', 'position', 'grade', 40],
        [5, '00000000-0000-0000-0000-000000320005', '课程实习', 'course', 'grade', 50],
        [6, '00000000-0000-0000-0000-000000320006', '其他实习', 'other', 'grade', 60],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO `internship_category` (`id`, `uuid`, `name`, `code`, `scope_type`, `sort`, `status`, `deleted_at`)
         VALUES (?, ?, ?, ?, ?, ?, 'enabled', NULL)
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `sort` = VALUES(`sort`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );
    foreach ($categories as $category) {
        $stmt->execute($category);
    }

    $pdo->exec(
        "UPDATE `internship_plan`
         SET `category_id` = CASE
             WHEN CONCAT_WS(' ', `name`, `course_name`, `course_category`) LIKE '%毕业实习%' THEN 1
             ELSE 6
         END
         WHERE `category_id` IS NULL"
    );
    $pdo->exec(
        "UPDATE `internship_plan`
         SET `status` = 'draft'
         WHERE `category_id` = 1 AND `graduation_cohort_id` IS NULL AND `deleted_at` IS NULL"
    );
}

function seedArchiveRequirements(PDO $pdo): void
{
    $requirements = [
        'graduation' => ['plan', 'implementation_sheet', 'syllabus', 'guide', 'registration', 'teacher_work_report', 'journal', 'graduation_report', 'graduation_appraisal', 'score_register', 'safety_commitment'],
        'internship' => ['plan', 'implementation_sheet', 'syllabus', 'guide', 'registration', 'teacher_work_report', 'journal', 'report', 'score_register', 'safety_commitment'],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO `internship_archive_requirement` (`uuid`, `name`, `practice_type`, `material_type`, `required`, `sort`, `status`, `deleted_at`)
         VALUES (?, '实习归档材料要求', ?, ?, 1, ?, 'enabled', NULL)
         ON DUPLICATE KEY UPDATE `practice_type` = VALUES(`practice_type`)"
    );
    foreach ($requirements as $practiceType => $materials) {
        foreach ($materials as $sort => $materialType) {
            $stmt->execute([
                '00000000-0000-0000-0000-' . str_pad((string) (($practiceType === 'graduation' ? 500001 : 600001) + $sort), 12, '0', STR_PAD_LEFT),
                $practiceType,
                $materialType,
                $sort,
            ]);
        }
    }
}

function seedPracticePeriods(PDO $pdo): void
{
    $periods = [
        [1, '00000000-0000-0000-0000-000000310001', '第 1 节', '08:30:00', '09:15:00', 10],
        [2, '00000000-0000-0000-0000-000000310002', '第 2 节', '09:20:00', '10:05:00', 20],
        [3, '00000000-0000-0000-0000-000000310003', '第 3 节', '10:25:00', '11:10:00', 30],
        [4, '00000000-0000-0000-0000-000000310004', '第 4 节', '11:15:00', '12:00:00', 40],
        [5, '00000000-0000-0000-0000-000000310005', '第 5 节', '14:00:00', '14:45:00', 50],
        [6, '00000000-0000-0000-0000-000000310006', '第 6 节', '14:50:00', '15:35:00', 60],
        [7, '00000000-0000-0000-0000-000000310007', '第 7 节', '15:55:00', '16:40:00', 70],
        [8, '00000000-0000-0000-0000-000000310008', '第 8 节', '16:45:00', '17:30:00', 80],
        [9, '00000000-0000-0000-0000-000000310009', '第 9 节', '18:30:00', '19:15:00', 90],
        [10, '00000000-0000-0000-0000-000000310010', '第 10 节', '19:20:00', '20:05:00', 100],
        [11, '00000000-0000-0000-0000-000000310011', '第 11 节', '20:15:00', '21:00:00', 110],
        [12, '00000000-0000-0000-0000-000000310012', '第 12 节', '21:05:00', '21:50:00', 120],
    ];
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO `practice_period` (`id`, `uuid`, `name`, `start_time`, `end_time`, `sort`, `status`)
         VALUES (?, ?, ?, ?, ?, ?, 'enabled')"
    );
    foreach ($periods as $period) {
        $stmt->execute($period);
    }
}

function seedMessageTemplates(PDO $pdo): void
{
    MessageRecord::ensureDefaultTemplates($pdo);
    $pdo->exec(
        "UPDATE `message_template`
         SET `link_url_tpl` = REPLACE(`link_url_tpl`, '#panel=training:', '#panel=practice:')
         WHERE `code` LIKE 'training\\_%' ESCAPE '\\\\' AND `link_url_tpl` LIKE '#panel=training:%'"
    );
    $pdo->exec(
        "UPDATE `message_template`
         SET `link_url_tpl` = REPLACE(`link_url_tpl`, '#panel=lab:', '#panel=practice:')
         WHERE `code` LIKE 'lab\\_%' ESCAPE '\\\\' AND `link_url_tpl` LIKE '#panel=lab:%'"
    );
    $pdo->exec(
        "UPDATE `message_template`
         SET `name` = REPLACE(`name`, '特殊申请', '实习方式申请'),
             `title_tpl` = REPLACE(`title_tpl`, '特殊申请', '实习方式申请'),
             `content_tpl` = REPLACE(`content_tpl`, '特殊申请', '实习方式申请'),
             `description` = REPLACE(`description`, '特殊申请', '实习方式申请')
         WHERE `code` LIKE 'internship\\_application\\_%' ESCAPE '\\\\'"
    );
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
        "INSERT INTO `internship_plan` (`id`, `uuid`, `source_type`, `course_code`, `course_name`, `category_id`, `grade_id`, `dep_id`, `profession_id`, `semester`, `credit`, `student_count`, `score_rule`, `plan_content`, `submitter_id`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000100000', 'edu_system', 'SX-RJ-2026', '软件技术专业实习课程', 6, 1, 1, 1, '2025-2026-2', 2.00, 1, 'average', JSON_OBJECT('content', '从教务系统抽取的软件技术专业实习课程计划。'), 1, 'accept')
         ON DUPLICATE KEY UPDATE
            `source_type` = VALUES(`source_type`),
            `course_code` = VALUES(`course_code`),
            `course_name` = VALUES(`course_name`),
            `category_id` = VALUES(`category_id`),
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
        "INSERT INTO `arrangement` (`id`, `uuid`, `name`, `plan_id`, `base_id`, `dep_id`, `profession_id`, `semester`, `teacher_id`, `task_no`, `batch_no`, `credit`, `student_count`, `type`, `organize_mode`, `title`, `start_date`, `end_date`, `location`, `description`, `created_by`, `submitter_id`, `status`)
         VALUES (1, '00000000-0000-0000-0000-000000100001', '软件技术2601实习任务', 1, 1, 1, 1, '2025-2026-2', 1, 'TASK-RJ-2601-01', '第一批', 2.00, 1, 'major_external', 'centralized', '软件技术2601校外实习任务', '2026-07-01', '2026-08-31', '成都锦城学院实践基地', '默认开发环境实习任务', 1, 1, 'accept')
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
            `submitter_id` = VALUES(`submitter_id`),
            `status` = 'accept',
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
        [1, '00000000-0000-0000-0000-000000020001', '2026级', 'true', 10],
    ];
    $gradeStmt = $pdo->prepare(
        "INSERT INTO `grade_list` (`grade_id`, `grade_uuid`, `grade_name`, `is_current`, `sort`, `flag`)
         VALUES (?, ?, ?, ?, ?, 'on')
         ON DUPLICATE KEY UPDATE `grade_name` = VALUES(`grade_name`), `is_current` = VALUES(`is_current`), `sort` = VALUES(`sort`), `flag` = 'on'"
    );
    foreach ($grades as $grade) {
        $gradeStmt->execute($grade);
    }

    $professions = [
        [1, '00000000-0000-0000-0000-000000030001', '软件技术', '软件', 'P001', 1, 1, 10],
        [2, '00000000-0000-0000-0000-000000030002', '大数据技术', '大数据', 'P002', 1, 1, 20],
        [3, '00000000-0000-0000-0000-000000030003', '电子商务', '电商', 'P003', 2, 1, 30],
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
        [3, '00000000-0000-0000-0000-000000040003', '电子商务2601', '电商2601', 'EC2601', 2, 3, 1, 30],
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
         VALUES (1, '00000000-0000-0000-0000-000000050001', '本地实践合作基地', 'LOCAL-COMPANY-001', '企业导师', '13800000000', '本地测试地址', 'on')
         ON DUPLICATE KEY UPDATE
            `company_name` = VALUES(`company_name`),
            `credit_code` = VALUES(`credit_code`),
            `contact_name` = VALUES(`contact_name`),
            `contact_mobile` = VALUES(`contact_mobile`),
            `address` = VALUES(`address`),
            `flag` = 'on'"
    );
}

function seedSocialPracticeDemo(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO `social_practice_approval_flow`
            (`id`, `uuid`, `name`, `code`, `scope_type`, `version`, `description`, `created_by`, `status`)
         VALUES
            (1, '00000000-0000-0000-0000-000000710001', '社会实践默认审批流', 'social-default', 'school', 1, '专业负责人、学院审批、教务备案', 1, 'enabled')
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`), `description` = VALUES(`description`),
            `version` = VALUES(`version`), `status` = 'enabled', `deleted_at` = NULL"
    );

    $nodes = [
        [1, '00000000-0000-0000-0000-000000711001', 'major_review', '专业负责人审核', 10, 'profession', 'social_practice:approve', 'review', 'true', 'false'],
        [2, '00000000-0000-0000-0000-000000711002', 'college_review', '学院审批', 20, 'college', 'social_practice:approve', 'review', 'true', 'false'],
        [3, '00000000-0000-0000-0000-000000711003', 'academic_filing', '教务备案', 30, 'school', 'social_practice:approve', 'filing', 'true', 'true'],
    ];
    $nodeStmt = $pdo->prepare(
        "INSERT INTO `social_practice_approval_node`
            (`id`, `uuid`, `flow_id`, `node_code`, `node_name`, `sort`, `scope_type`, `permission_code`, `node_type`, `can_return`, `auto_pass`, `status`)
         VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `node_name` = VALUES(`node_name`), `sort` = VALUES(`sort`),
            `scope_type` = VALUES(`scope_type`), `permission_code` = VALUES(`permission_code`),
            `node_type` = VALUES(`node_type`), `can_return` = VALUES(`can_return`),
            `auto_pass` = VALUES(`auto_pass`), `status` = 'enabled', `deleted_at` = NULL"
    );
    foreach ($nodes as $node) {
        $nodeStmt->execute($node);
    }

    $pdo->exec(
        "INSERT INTO `social_practice_plan`
            (`id`, `uuid`, `name`, `code`, `source_type`, `source_key`, `title`, `description`, `grade_id`, `organizer_dep_id`, `credit`, `participation_mode`, `teacher_match_mode`, `teacher_confirm_hours`, `max_reselect_count`, `default_team_submit_mode`, `register_start_at`, `register_end_at`, `practice_start_at`, `practice_end_at`, `result_deadline_at`, `score_deadline_at`, `approval_flow_id`, `phase`, `submitter_id`, `published_at`, `status`)
         VALUES
            (1, '00000000-0000-0000-0000-000000712001', '2026级社会实践计划', 'SP-2026-001', 'manual', 'demo-social-2026', '2026级社会实践计划', '社会调研、志愿服务与专业实践结合。', 1, 1, 1.00, 'mandatory', 'mixed', 48, 2, 'individual', '2026-07-01 00:00:00', '2026-07-15 23:59:59', '2026-07-20 00:00:00', '2026-08-20 23:59:59', '2026-08-25 23:59:59', '2026-08-31 23:59:59', 1, 'active', 1, '2026-06-20 09:00:00', 'accept')
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`), `title` = VALUES(`title`), `description` = VALUES(`description`),
            `grade_id` = VALUES(`grade_id`), `organizer_dep_id` = VALUES(`organizer_dep_id`),
            `credit` = VALUES(`credit`), `approval_flow_id` = VALUES(`approval_flow_id`),
            `phase` = VALUES(`phase`), `status` = 'accept', `deleted_at` = NULL"
    );
    $pdo->exec(
        "INSERT INTO `social_practice_plan_scope`
            (`id`, `uuid`, `plan_id`, `scope_type`, `dep_id`, `profession_id`, `class_id`, `status`)
         VALUES
            (1, '00000000-0000-0000-0000-000000713001', 1, 'profession', 1, 1, NULL, 'enabled')
         ON DUPLICATE KEY UPDATE
            `dep_id` = VALUES(`dep_id`), `profession_id` = VALUES(`profession_id`),
            `status` = 'enabled', `deleted_at` = NULL"
    );

    $requirements = [
        [1, '00000000-0000-0000-0000-000000714001', 'all', 'safety_agreement', 'true', 'student'],
        [2, '00000000-0000-0000-0000-000000714002', 'all', 'insurance', 'true', 'student'],
        [3, '00000000-0000-0000-0000-000000714003', 'all', 'emergency_plan', 'true', 'project'],
        [4, '00000000-0000-0000-0000-000000714004', 'all', 'parent_notice', 'false', 'student'],
        [5, '00000000-0000-0000-0000-000000714005', 'centralized', 'practice_report', 'true', 'student'],
        [6, '00000000-0000-0000-0000-000000714006', 'centralized', 'practice_photo', 'true', 'student'],
        [7, '00000000-0000-0000-0000-000000714007', 'distributed', 'practice_proof', 'true', 'student'],
        [8, '00000000-0000-0000-0000-000000714008', 'distributed', 'social_practice_report', 'true', 'student'],
    ];
    $requirementStmt = $pdo->prepare(
        "INSERT INTO `social_practice_requirement`
            (`id`, `uuid`, `plan_id`, `practice_mode`, `requirement_type`, `required_flag`, `submit_scope`, `status`)
         VALUES (?, ?, 1, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `required_flag` = VALUES(`required_flag`), `submit_scope` = VALUES(`submit_scope`),
            `status` = 'enabled', `deleted_at` = NULL"
    );
    foreach ($requirements as $requirement) {
        $requirementStmt->execute($requirement);
    }

    $pdo->exec(
        "INSERT INTO `social_practice_project`
            (`id`, `uuid`, `name`, `code`, `plan_id`, `practice_mode`, `source_type`, `project_code`, `title`, `content`, `objective`, `location`, `start_at`, `end_at`, `capacity`, `phase`, `created_by`, `published_at`, `status`)
         VALUES
            (1, '00000000-0000-0000-0000-000000715001', '社区数字服务集中实践', 'SP-PROJECT-001', 1, 'centralized', 'admin_created', 'SP-C-001', '社区数字服务集中实践', '完成社区数字服务调研和志愿服务。', '将专业能力用于真实社区场景。', '成都市高新区', '2026-07-20 08:00:00', '2026-08-20 18:00:00', 20, 'active', 1, '2026-06-20 10:00:00', 'enabled')
         ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`), `title` = VALUES(`title`), `content` = VALUES(`content`),
            `location` = VALUES(`location`), `capacity` = VALUES(`capacity`),
            `phase` = VALUES(`phase`), `status` = 'enabled', `deleted_at` = NULL"
    );
    $pdo->exec(
        "INSERT INTO `social_practice_project_teacher`
            (`id`, `uuid`, `project_id`, `teacher_id`, `teacher_role`, `capacity`, `status`)
         VALUES
            (1, '00000000-0000-0000-0000-000000716001', 1, 1, 'leader', 20, 'active')
         ON DUPLICATE KEY UPDATE
            `teacher_role` = VALUES(`teacher_role`), `capacity` = VALUES(`capacity`),
            `status` = 'active', `deleted_at` = NULL"
    );
    $pdo->exec(
        "INSERT INTO `social_practice_participant`
            (`id`, `uuid`, `plan_id`, `project_id`, `student_id`, `teacher_id`, `practice_mode`, `join_source`, `status`)
         VALUES
            (1, '00000000-0000-0000-0000-000000717001', 1, 1, 1, 1, 'centralized', 'scope', 'active')
         ON DUPLICATE KEY UPDATE
            `project_id` = VALUES(`project_id`), `teacher_id` = VALUES(`teacher_id`),
            `practice_mode` = VALUES(`practice_mode`), `status` = 'active', `deleted_at` = NULL"
    );
    $pdo->exec(
        "INSERT INTO `pair`
            (`uuid`, `student_id`, `teacher_id`, `type`, `arrangement_id`, `entity_type`, `entity_id`, `status`)
         VALUES
            ('00000000-0000-0000-0000-000000718001', 1, 1, 'social_practice', 1, 'social_practice_project', 1, 'active')
         ON DUPLICATE KEY UPDATE
            `teacher_id` = VALUES(`teacher_id`), `entity_type` = VALUES(`entity_type`),
            `entity_id` = VALUES(`entity_id`), `status` = 'active', `deleted_at` = NULL"
    );

    $scoreRules = [
        [1, '00000000-0000-0000-0000-000000719001', 'centralized', 'attendance', '签到', 20, 10],
        [2, '00000000-0000-0000-0000-000000719002', 'centralized', 'performance', '实践表现', 30, 20],
        [3, '00000000-0000-0000-0000-000000719003', 'centralized', 'report', '实践报告', 50, 30],
        [4, '00000000-0000-0000-0000-000000719004', 'distributed', 'attitude', '实践态度', 20, 10],
        [5, '00000000-0000-0000-0000-000000719005', 'distributed', 'result', '实践成果', 30, 20],
        [6, '00000000-0000-0000-0000-000000719006', 'distributed', 'report', '社会实践报告', 50, 30],
    ];
    $ruleStmt = $pdo->prepare(
        "INSERT INTO `social_practice_score_rule`
            (`id`, `uuid`, `plan_id`, `practice_mode`, `item_code`, `item_name`, `weight`, `max_score`, `sort`, `status`)
         VALUES (?, ?, 1, ?, ?, ?, ?, 100, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `item_name` = VALUES(`item_name`), `weight` = VALUES(`weight`),
            `sort` = VALUES(`sort`), `status` = 'enabled', `deleted_at` = NULL"
    );
    foreach ($scoreRules as $rule) {
        $ruleStmt->execute($rule);
    }
}

function seedMenus(PDO $pdo): void
{
    $menus = [
        [1, 0, '实习管理', null, null, 'both', 'directory', 10, 'BriefcaseBusiness'],
        [11, 1, '实习安排', null, null, 'both', 'menu', 11, 'CalendarCheck'],
        [111, 11, '列表', 'internship:view', '/internship', 'both', 'list', 111, 'List'],
        [101, 111, '新增', 'internship:manage', null, 'both', 'button', 101, null],
        [1112, 111, '删除', 'internship:arrangement:delete', null, 'pc', 'button', 112, null],
        [12, 1, '申请管理', null, null, 'both', 'menu', 12, 'ClipboardList'],
        [121, 12, '实习方式申请', 'internship:application:list', '/internship/requests?tab=applications', 'both', 'list', 121, 'List'],
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
        [195, 12, '延期申请', null, null, 'both', 'menu', 195, 'FileClock'],
        [1951, 195, '列表', 'internship:delay:list', '/internship/requests?tab=delays', 'both', 'list', 1951, 'List'],
        [19511, 1951, '提交', 'internship:apply', null, 'both', 'button', 1951, null],
        [19512, 1951, '审核', 'internship:approve', null, 'both', 'button', 1952, null],
        [2, 0, '实验实训管理', null, null, 'both', 'directory', 20, 'FlaskConical'],
        [21, 2, '教学计划', null, null, 'both', 'menu', 21, 'FileText'],
        [211, 21, '列表', 'practice:view', '/practice/plans', 'both', 'list', 211, 'List'],
        [201, 211, '维护', 'practice:manage', null, 'both', 'button', 201, null],
        [202, 211, '审核', 'practice:approve', null, 'both', 'button', 202, null],
        [22, 2, '课表安排', null, null, 'both', 'menu', 22, 'CalendarCheck'],
        [221, 22, '列表', 'practice:view', '/practice/schedules', 'both', 'list', 221, 'List'],
        [2211, 221, '维护', 'practice:manage', null, 'both', 'button', 2211, null],
        [222, 2, '项目发布', null, null, 'both', 'menu', 222, 'ClipboardList'],
        [2221, 222, '列表', 'practice:view', '/practice/projects', 'both', 'list', 2221, 'List'],
        [22211, 2221, '维护', 'practice:manage', null, 'pc', 'button', 2221, null],
        [23, 2, '大纲编写', null, null, 'both', 'menu', 23, 'BookOpen'],
        [231, 23, '列表', 'practice:view', '/practice/syllabus', 'both', 'list', 231, 'List'],
        [2311, 231, '维护', 'practice:manage', null, 'both', 'button', 2311, null],
        [2312, 231, '审核', 'practice:approve', null, 'both', 'button', 2312, null],
        [24, 2, '教案编写', null, null, 'both', 'menu', 24, 'FileText'],
        [241, 24, '列表', 'practice:view', '/practice/lesson-plans', 'both', 'list', 241, 'List'],
        [2411, 241, '维护', 'practice:manage', null, 'both', 'button', 2411, null],
        [2412, 241, '审核', 'practice:approve', null, 'both', 'button', 2412, null],
        [25, 2, '成绩评定', null, null, 'both', 'menu', 25, 'GraduationCap'],
        [251, 25, '列表', 'practice:view', '/practice/scores', 'both', 'list', 251, 'List'],
        [2511, 251, '维护', 'practice:manage', null, 'both', 'button', 2511, null],
        [26, 2, '反思报告', null, null, 'both', 'menu', 26, 'FileClock'],
        [261, 26, '列表', 'practice:view', '/practice/reflections', 'both', 'list', 261, 'List'],
        [2611, 261, '维护', 'practice:manage', null, 'both', 'button', 2611, null],
        [2612, 261, '审核', 'practice:approve', null, 'both', 'button', 2612, null],
        [27, 2, '场地管理', null, null, 'pc', 'menu', 27, 'Building2'],
        [271, 27, '列表', 'practice:view', '/practice/rooms', 'pc', 'list', 271, 'List'],
        [2711, 271, '维护', 'practice:manage', null, 'pc', 'button', 2711, null],
        [700000, 0, '社会实践管理', 'social_practice:view', null, 'both', 'directory', 35, 'Route'],
        [700010, 700000, '总览', null, null, 'both', 'menu', 10, 'LayoutDashboard'],
        [700011, 700010, '列表', 'social_practice:view', '/social-practice/overview', 'both', 'list', 10, 'List'],
        [700020, 700000, '计划管理', null, null, 'both', 'menu', 20, 'CalendarRange'],
        [700021, 700020, '列表', 'social_practice:view', '/social-practice/plans', 'both', 'list', 20, 'List'],
        [7000211, 700021, '维护', 'social_practice:plan:manage', null, 'pc', 'button', 21, null],
        [7000212, 700021, '审核', 'social_practice:approve', null, 'pc', 'button', 22, null],
        [7000213, 700021, '发布', 'social_practice:plan:manage', null, 'pc', 'button', 23, null],
        [700030, 700000, '集中实践', null, null, 'both', 'menu', 30, 'UsersRound'],
        [700031, 700030, '列表', 'social_practice:view', '/social-practice/centralized', 'both', 'list', 30, 'List'],
        [7000311, 700031, '项目维护', 'social_practice:project:manage', null, 'pc', 'button', 31, null],
        [7000312, 700031, '教师学生分配', 'social_practice:project:manage', null, 'pc', 'button', 32, null],
        [7000313, 700031, '实施审批', 'social_practice:approve', null, 'pc', 'button', 33, null],
        [700040, 700000, '分散实践', null, null, 'both', 'menu', 40, 'Network'],
        [700041, 700040, '列表', 'social_practice:view', '/social-practice/distributed', 'both', 'list', 40, 'List'],
        [7000411, 700041, '申报', 'social_practice:declare', null, 'both', 'button', 41, null],
        [7000412, 700041, '审核', 'social_practice:approve', null, 'pc', 'button', 42, null],
        [7000413, 700041, '教师确认', 'social_practice:teacher:confirm', null, 'both', 'button', 43, null],
        [700050, 700000, '指导教师', null, null, 'both', 'menu', 50, 'UserRoundCheck'],
        [700051, 700050, '列表', 'social_practice:view', '/social-practice/teachers', 'both', 'list', 50, 'List'],
        [700060, 700000, '安全材料', null, null, 'both', 'menu', 60, 'ShieldCheck'],
        [700061, 700060, '列表', 'social_practice:view', '/social-practice/safety', 'both', 'list', 60, 'List'],
        [7000611, 700061, '提交评阅', 'social_practice:material:manage', null, 'both', 'button', 61, null],
        [700070, 700000, '签到与补签', null, null, 'both', 'menu', 70, 'MapPin'],
        [700071, 700070, '列表', 'social_practice:view', '/social-practice/attendance', 'both', 'list', 70, 'List'],
        [7000711, 700071, '签到和审核', 'social_practice:attendance:manage', null, 'both', 'button', 71, null],
        [700080, 700000, '成果材料', null, null, 'both', 'menu', 80, 'FileText'],
        [700081, 700080, '列表', 'social_practice:view', '/social-practice/materials', 'both', 'list', 80, 'List'],
        [7000811, 700081, '提交评阅', 'social_practice:material:manage', null, 'both', 'button', 81, null],
        [700090, 700000, '成绩管理', null, null, 'both', 'menu', 90, 'GraduationCap'],
        [700091, 700090, '列表', 'social_practice:view', '/social-practice/scores', 'both', 'list', 90, 'List'],
        [7000911, 700091, '评分', 'social_practice:score:manage', null, 'pc', 'button', 91, null],
        [7000912, 700091, '审核', 'social_practice:score:approve', null, 'pc', 'button', 92, null],
        [700100, 700000, '归档管理', null, null, 'pc', 'menu', 100, 'FolderOpen'],
        [700101, 700100, '列表', 'social_practice:view', '/social-practice/archives', 'pc', 'list', 100, 'List'],
        [7001011, 700101, '归档', 'social_practice:archive', null, 'pc', 'button', 101, null],
        [700110, 700000, '统计报表', null, null, 'pc', 'menu', 110, 'ChartColumn'],
        [700111, 700110, '列表', 'social_practice:view', '/social-practice/statistics', 'pc', 'list', 110, 'List'],
        [7001111, 700111, '导出', 'social_practice:export', null, 'pc', 'button', 111, null],
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
        [607, 6, '年级管理', null, null, 'pc', 'menu', 61, 'GraduationCap'],
        [6071, 607, '列表', 'config:grade', '/config/grades', 'pc', 'list', 611, 'List'],
        [60711, 6071, '新增', 'config:grade:save', null, 'pc', 'button', 611, null],
        [60712, 6071, '编辑', 'config:grade:update', null, 'pc', 'button', 612, null],
        [60713, 6071, '删除', 'config:grade:delete', null, 'pc', 'button', 613, null],
        [6120, 6, '毕业届次管理', null, null, 'pc', 'menu', 62, 'CalendarRange'],
        [6121, 6120, '列表', 'config:graduation-cohort', '/config/graduation-cohorts', 'pc', 'list', 621, 'List'],
        [61211, 6121, '新增', 'config:graduation-cohort:save', null, 'pc', 'button', 6211, null],
        [61212, 6121, '编辑', 'config:graduation-cohort:update', null, 'pc', 'button', 6212, null],
        [61213, 6121, '删除', 'config:graduation-cohort:delete', null, 'pc', 'button', 6213, null],
        [6130, 6, '实习类别管理', null, null, 'pc', 'menu', 63, 'Tags'],
        [6131, 6130, '列表', 'config:internship-category', '/config/internship-categories', 'pc', 'list', 631, 'List'],
        [61311, 6131, '新增', 'config:internship-category:save', null, 'pc', 'button', 6311, null],
        [61312, 6131, '编辑', 'config:internship-category:update', null, 'pc', 'button', 6312, null],
        [61313, 6131, '删除', 'config:internship-category:delete', null, 'pc', 'button', 6313, null],
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
        [610, 0, '基地管理', 'internship:view', null, 'pc', 'directory', 30, 'Building2'],
        [6101, 610, '基地建设', null, null, 'pc', 'menu', 301, 'Building2'],
        [61011, 6101, '列表', 'internship:view', '/base-management/construction', 'pc', 'list', 3011, 'List'],
        [61012, 61011, '维护', 'internship:manage', null, 'pc', 'button', 3012, null],
        [61013, 61011, '导入', 'internship:manage', null, 'pc', 'button', 3013, null],
        [61014, 61011, '导出', 'internship:manage', null, 'pc', 'button', 3014, null],
        [6102, 610, '基地申报', null, null, 'pc', 'menu', 302, 'FileText'],
        [61021, 6102, '列表', 'internship:view', '/base-management/applications', 'pc', 'list', 3021, 'List'],
        [610211, 61021, '提交', 'internship:manage', null, 'pc', 'button', 30211, null],
        [610212, 61021, '审核', 'internship:approve', null, 'pc', 'button', 30212, null],
        [610213, 61021, '通过后修改', 'internship:approve', null, 'pc', 'button', 30213, null],
        [6103, 610, '基地使用', null, null, 'pc', 'menu', 303, 'ClipboardList'],
        [61031, 6103, '列表', 'internship:view', '/base-management/usage', 'pc', 'list', 3031, 'List'],
        [610311, 61031, '提交', 'internship:manage', null, 'pc', 'button', 30311, null],
        [610312, 61031, '审核', 'internship:approve', null, 'pc', 'button', 30312, null],
        [610313, 61031, '通过后修改', 'internship:approve', null, 'pc', 'button', 30313, null],
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
        [64, 6, '课节配置', null, null, 'pc', 'menu', 74, 'Clock3'],
        [641, 64, '列表', 'practice:period:manage', '/config/practice-periods', 'pc', 'list', 741, 'List'],
        [6411, 641, '维护', 'practice:period:manage', null, 'pc', 'button', 7411, null],
        [7, 6, '企业微信应用', null, null, 'pc', 'menu', 74, 'Network'],
        [711, 7, '列表', 'wechat:proxy', '/config/wechat-proxy', 'pc', 'list', 741, 'List'],
        [701, 711, '保存', 'wechat:proxy:save', null, 'pc', 'button', 701, null],
        [702, 711, '测试配置', 'wechat:proxy:test', null, 'pc', 'button', 702, null],
        [650, 6, '教务数据', 'edu:data:view', '/data/edu-data', 'pc', 'menu', 63, 'Upload'],
        [6501, 650, '列表', 'edu:data:view', '/data/edu-data', 'pc', 'list', 631, 'List'],
        [6502, 6501, '导入', 'edu:data:import', null, 'pc', 'button', 6502, null],
        [6503, 6501, '确认', 'edu:data:confirm', null, 'pc', 'button', 6503, null],
        [6504, 6501, '问题处理', 'edu:data:issue', null, 'pc', 'button', 6504, null],
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
            `id` = VALUES(`id`)"
    );

    foreach ($menus as $menu) {
        $stmt->execute($menu);
    }

    $baseManagementMenuIds = [
        610, 6101, 61011, 61012, 61013, 61014,
        6102, 61021, 610211, 610212, 610213,
        6103, 61031, 610311, 610312, 610313,
    ];
    $menuById = [];
    foreach ($menus as $menu) {
        $menuById[(int) $menu[0]] = $menu;
    }
    $baseMenuStmt = $pdo->prepare(
        "UPDATE `menu`
         SET `parent_id` = ?, `name` = ?, `code` = ?, `path` = ?, `platform` = ?, `type` = ?,
             `sort` = ?, `icon` = ?, `visible` = 'true', `status` = 'enabled', `deleted_at` = NULL
         WHERE `id` = ?"
    );
    foreach ($baseManagementMenuIds as $menuId) {
        $menu = $menuById[$menuId];
        $baseMenuStmt->execute([
            $menu[1], $menu[2], $menu[3], $menu[4], $menu[5], $menu[6], $menu[7], $menu[8], $menu[0],
        ]);
    }

    $pdo->exec("UPDATE `menu` SET `name` = '年级管理' WHERE `id` = 607");

    $pdo->exec(
        "UPDATE `menu`
         SET `name` = '申请管理', `icon` = 'ClipboardList'
         WHERE `id` = 12 AND `name` IN ('补充申请', '特殊申请', '申请管理')"
    );
    $pdo->exec(
        "UPDATE `menu`
         SET `name` = '实习方式申请', `path` = '/internship/requests?tab=applications'
         WHERE `id` = 121"
    );
    $pdo->exec(
        "UPDATE `menu`
         SET `parent_id` = 12, `name` = '延期申请', `icon` = 'FileClock'
         WHERE `id` = 195"
    );
    $pdo->exec(
        "UPDATE `menu`
         SET `path` = '/internship/requests?tab=delays'
         WHERE `id` = 1951"
    );

    $practiceMenuIds = [
        2, 21, 211, 201, 202, 22, 221, 2211, 222, 2221, 22211,
        23, 231, 2311, 2312, 24, 241, 2411, 2412, 25, 251, 2511,
        26, 261, 2611, 2612, 27, 271, 2711,
    ];
    $practiceMenuById = [];
    foreach ($menus as $menu) {
        if (in_array((int) $menu[0], $practiceMenuIds, true)) {
            $practiceMenuById[(int) $menu[0]] = $menu;
        }
    }
    $legacyPracticeStmt = $pdo->prepare(
        "UPDATE `menu`
         SET `parent_id` = ?, `code` = ?, `path` = ?, `platform` = ?, `type` = ?, `sort` = ?, `icon` = ?
         WHERE `id` = ? AND (`code` LIKE 'training:%' OR `path` LIKE '/training/%')"
    );
    foreach ($practiceMenuIds as $menuId) {
        $menu = $practiceMenuById[$menuId] ?? null;
        if (!$menu) {
            continue;
        }
        $legacyPracticeStmt->execute([
            $menu[1], $menu[3], $menu[4], $menu[5], $menu[6], $menu[7], $menu[8], $menu[0],
        ]);
    }
    $pdo->exec(
        "UPDATE `menu`
         SET `name` = '实验实训管理', `icon` = 'FlaskConical'
         WHERE `id` = 2 AND `name` IN ('实训管理', '实验管理')"
    );

    $moduleMenus = [
        1 => 'internship',
        2 => 'practice',
        700000 => 'socialPractice',
        4 => 'stat',
        5 => 'log',
        6 => 'config',
        606 => 'userManage',
        607 => 'gradeManage',
        6120 => 'graduationCohortManage',
        6130 => 'internshipCategoryManage',
        605 => 'departmentManage',
        608 => 'professionManage',
        609 => 'classManage',
        610 => 'companyManage',
        8 => 'file',
        9 => 'doc',
        10 => 'templateLib',
        20 => 'exportTask',
        650 => 'eduData',
    ];
    $moduleStmt = $pdo->prepare(
        "UPDATE `menu`
         SET `is_module` = 'true', `module_key` = ?, `deleted_at` = NULL
         WHERE `id` = ?"
    );
    foreach ($moduleMenus as $menuId => $moduleKey) {
        $moduleStmt->execute([$moduleKey, $menuId]);
    }

    $disabledMenuIds = [
        3, 31, 311, 301, 302, 32, 321, 3211, 322, 3221, 32211,
        33, 331, 3311, 3312, 34, 341, 3411, 3412, 35, 351, 3511,
        36, 361, 3611, 3612, 37, 371, 3711, 102, 401,
    ];
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
         ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`)"
    );

    $legacyPracticeRoleMap = [
        3 => 2, 31 => 21, 311 => 211, 301 => 201, 302 => 202,
        32 => 22, 321 => 221, 3211 => 2211, 322 => 222, 3221 => 2221,
        32211 => 22211, 33 => 23, 331 => 231, 3311 => 2311, 3312 => 2312,
        34 => 24, 341 => 241, 3411 => 2411, 3412 => 2412, 35 => 25,
        351 => 251, 3511 => 2511, 36 => 26, 361 => 261, 3611 => 2611,
        3612 => 2612, 37 => 27, 371 => 271, 3711 => 2711,
    ];
    $legacyRoleStmt = $pdo->prepare(
        "SELECT DISTINCT `role_id` FROM `role_menu` WHERE `menu_id` = ? AND `deleted_at` IS NULL"
    );
    $migrateRoleStmt = $pdo->prepare(
        "INSERT INTO `role_menu` (`role_id`, `menu_id`)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `deleted_at` = NULL, `updated_at` = NOW()"
    );
    $disableLegacyRoleStmt = $pdo->prepare(
        "UPDATE `role_menu` SET `deleted_at` = NOW(), `updated_at` = NOW()
         WHERE `role_id` = ? AND `menu_id` = ? AND `deleted_at` IS NULL"
    );
    foreach ($legacyPracticeRoleMap as $legacyMenuId => $practiceMenuId) {
        $legacyRoleStmt->execute([$legacyMenuId]);
        foreach ($legacyRoleStmt->fetchAll(PDO::FETCH_COLUMN) as $roleId) {
            $migrateRoleStmt->execute([(int) $roleId, $practiceMenuId]);
            $disableLegacyRoleStmt->execute([(int) $roleId, $legacyMenuId]);
        }
    }

    $internshipAdminMenus = [
        1, 11, 111, 101, 1112, 12, 121, 103, 104, 1213, 13, 131, 1311, 1312,
        14, 141, 105, 15, 151, 106, 1512, 16, 161, 107, 1612, 17, 171, 108,
        18, 181, 109, 19, 191, 110, 195, 1951, 19511, 19512,
    ];
    $baseManagementMenus = [
        610, 6101, 61011, 61012, 61013, 61014,
        6102, 61021, 610211, 610212, 610213,
        6103, 61031, 610311, 610312, 610313,
    ];
    $practiceMenus = [2, 21, 211, 201, 202, 22, 221, 2211, 222, 2221, 22211, 23, 231, 2311, 2312, 24, 241, 2411, 2412, 25, 251, 2511, 26, 261, 2611, 2612, 27, 271, 2711];
    $eduDataAdminMenus = [650, 6501, 6502, 6503, 6504];
    $eduDataViewMenus = [650, 6501];
    $practiceReadonlyMenus = [2, 21, 211, 22, 221, 222, 2221, 25, 251];
    $socialPracticeMenus = [
        700000, 700010, 700011, 700020, 700021, 7000211, 7000212, 7000213,
        700030, 700031, 7000311, 7000312, 7000313,
        700040, 700041, 7000411, 7000412, 7000413,
        700050, 700051, 700060, 700061, 7000611,
        700070, 700071, 7000711, 700080, 700081, 7000811,
        700090, 700091, 7000911, 7000912, 700100, 700101, 7001011,
        700110, 700111, 7001111,
    ];
    $socialPracticeTeacherMenus = [
        700000, 700010, 700011, 700020, 700021,
        700030, 700031, 700040, 700041, 7000412, 7000413,
        700050, 700051, 700060, 700061, 7000611,
        700070, 700071, 7000711, 700080, 700081, 7000811,
        700090, 700091, 7000911,
    ];
    $socialPracticeStudentMenus = [
        700000, 700010, 700011, 700020, 700021,
        700030, 700031, 700040, 700041, 7000411,
        700050, 700051, 700060, 700061, 7000611,
        700070, 700071, 7000711, 700080, 700081, 7000811,
        700090, 700091,
    ];
    $statMenus = [4, 41, 411, 402, 42, 421, 43, 431, 44, 441, 45, 451, 46, 461, 47, 471];
    $commonViewMenus = [9, 91, 911, 10, 100, 1000, 10001, 20, 200, 2000, 20001, 20002];
    $allMenuIds = array_map(static fn (array $menu): int => (int) $menu[0], $menus);
    $roleMenuIds = [
        1 => $allMenuIds,
        2 => $allMenuIds,
        3 => array_merge($internshipAdminMenus, $baseManagementMenus, $practiceMenus, $socialPracticeMenus, $statMenus, $commonViewMenus, $eduDataViewMenus),
        4 => array_merge($internshipAdminMenus, $baseManagementMenus, $practiceMenus, $socialPracticeMenus, $statMenus, $commonViewMenus),
        5 => array_merge([1, 11, 111, 12, 121, 104, 1213, 13, 131, 14, 141, 105, 15, 151, 106, 1512, 16, 161, 107, 1612, 17, 171, 108, 195, 1951, 19512, 201, 202, 2311, 2312, 2411, 2412, 2511, 2611, 2612], $practiceReadonlyMenus, $socialPracticeTeacherMenus, $commonViewMenus),
        6 => array_merge([1, 11, 111, 12, 121, 103, 14, 141, 105, 15, 151, 106, 16, 161, 107, 195, 1951, 19511], $practiceReadonlyMenus, $socialPracticeStudentMenus, $commonViewMenus),
        7 => array_merge([1, 17, 171, 108], $commonViewMenus),
    ];

    foreach ($roleMenuIds as $roleId => $menuIds) {
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        foreach ($menuIds as $menuId) {
            $roleMenu->execute([$roleId, $menuId]);
        }
    }
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
            '<h3>流程</h3><p>管理员维护届次、学院、专业、班级、基地、实习计划、实习任务和任务绑定；学生按已绑定任务提交实习方式申请、签到、日志、报告和延期申请；任务老师按任务处理审核、评阅和成绩；学校管理员查看统计、归档材料和审计日志。</p><h3>注意</h3><p>实习主链路以计划、任务、班级学生绑定为边界，实习方式申请不生成任务绑定。</p>',
        ],
        [
            2,
            '00000000-0000-0000-0000-000000220002',
            2,
            '学生端提交说明',
            '<h3>学生流程</h3><p>学生端主要完成自己已绑定任务下的实习方式申请、签到、日志、报告、延期申请和流程记录查看。状态为需修改时，应进入对应记录重新编辑并提交。</p><h3>常见问题</h3><p>看不到列表筛选属于正常体验，学生仅查看本人的数据。</p>',
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
        [8, '00000000-0000-0000-0000-000000230008', 'internship_archive', '实习档案模板', '实习计划、实施、大纲、指导书、过程、报告、成绩和安全承诺档案模板。', 80],
    ];
    foreach ($categories as $category) {
        $stmt->execute($category);
    }

    $templateStmt = $pdo->prepare(
        "INSERT INTO `template` (`uuid`, `category_id`, `code`, `name`, `description`, `file_id`, `version`, `download_count`, `flag`, `business_code`, `material_type`, `scope_type`, `practice_types`, `status`)
         VALUES (?, 8, ?, ?, ?, ?, ?, 0, 'on', 'internship_archive', ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `category_id` = 8,
            `code` = VALUES(`code`),
            `name` = VALUES(`name`),
            `description` = VALUES(`description`),
            `file_id` = VALUES(`file_id`),
            `version` = VALUES(`version`),
            `flag` = 'on',
            `business_code` = 'internship_archive',
            `material_type` = VALUES(`material_type`),
            `scope_type` = VALUES(`scope_type`),
            `practice_types` = VALUES(`practice_types`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );
    $templates = [
        ['plan', '实习计划', '计划表导入和归档模板。', 'plan.xlsx', 'plan', 'plan', ['internship']],
        ['implementation_sheet', '教学实习实施表', '按已确认的新版实施（经费）表生成。', 'implementation-sheet.pdf', 'implementation_sheet', 'arrangement', ['internship']],
        ['syllabus', '实习教学大纲', '成都锦城学院实习教学大纲模板。', 'syllabus.docx', 'syllabus', 'plan', ['internship']],
        ['guide', '实习指导书', '实习指导书模板。', 'guide.doc', 'guide', 'plan', ['internship']],
        ['registration', '实习情况登记表', '根据实习任务和学生绑定生成。', 'registration.xlsx', 'registration', 'arrangement', ['internship']],
        ['teacher_work_report', '指导教师工作报告', '实习指导教师工作报告模板。', 'teacher-work-report.docx', 'teacher_work_report', 'arrangement', ['internship']],
        ['journal', '实习周（日）志', '学生实习周志和日志模板。', 'journal.docx', 'journal', 'student_task', ['internship']],
        ['report', '实习/实训报告', '普通实习报告模板。', 'report.docx', 'report', 'student_task', ['internship']],
        ['graduation_report', '毕业实习报告', '毕业实习报告模板。', 'graduation-report.docx', 'graduation_report', 'student_task', ['graduation']],
        ['graduation_appraisal', '毕业实习成绩鉴定表', '毕业实习过程管理、实习单位和校内指导教师成绩鉴定模板。', 'graduation-appraisal.docx', 'graduation_appraisal', 'student_task', ['graduation']],
        ['score_register', '成绩登记表', '按计划、任务和班级生成成绩登记表。', 'score-register.pdf', 'score_register', 'plan_class', ['internship']],
        ['safety_commitment', '学生实习安全承诺书', '学生签署后上传定稿文件。', 'safety-commitment.pdf', 'safety_commitment', 'student_task', ['internship']],
    ];
    foreach ($templates as $index => [$code, $name, $description, $fileName, $materialType, $scopeType, $practiceTypes]) {
        $sequence = $index + 1;
        $fileId = seedArchiveTemplateFile($pdo, $fileName, $name, $sequence);
        $templateStmt->execute([
            sprintf('00000000-0000-0000-0000-%012d', 250000 + $sequence),
            'internship_archive_' . $code,
            $name,
            $description,
            $fileId ?: null,
            '2026.1',
            $materialType,
            $scopeType,
            json_encode($practiceTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function seedArchiveTemplateFile(PDO $pdo, string $fileName, string $downloadName, int $sequence): ?int
{
    $source = dirname(__DIR__) . '/resources/templates/internship/archive/' . $fileName;
    if (!is_file($source)) {
        return null;
    }

    $relativePath = 'files/b1/system/template/archive/' . $fileName;
    $target = dirname(__DIR__) . '/public/' . $relativePath;
    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('模板文件目录创建失败');
    }
    if (!is_file($target) || md5_file($target) !== md5_file($source)) {
        if (!copy($source, $target)) {
            throw new RuntimeException('模板文件复制失败：' . $fileName);
        }
    }

    $md5 = md5_file($target);
    $sha1 = sha1_file($target);
    if ($md5 === false || $sha1 === false) {
        throw new RuntimeException('模板文件校验失败：' . $fileName);
    }
    $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? (string) mime_content_type($target) : 'application/octet-stream';
    $blobStmt = $pdo->prepare(
        "INSERT INTO `file_blob` (`md5`, `sha1`, `path`, `url`, `ext`, `size`, `mime_type`, `disk`, `block`, `category`, `ref_count`, `deleted_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'public', 'b1', 'template', 1, NULL)
         ON DUPLICATE KEY UPDATE
            `id` = LAST_INSERT_ID(`id`),
            `sha1` = VALUES(`sha1`),
            `path` = VALUES(`path`),
            `url` = VALUES(`url`),
            `ext` = VALUES(`ext`),
            `size` = VALUES(`size`),
            `mime_type` = VALUES(`mime_type`),
            `category` = 'template',
            `ref_count` = GREATEST(`ref_count`, 1),
            `deleted_at` = NULL"
    );
    $url = '/' . $relativePath;
    $blobStmt->execute([$md5, $sha1, $relativePath, $url, $ext, (int) filesize($target), $mime]);
    $blobId = (int) $pdo->lastInsertId();

    $fileStmt = $pdo->prepare(
        "INSERT INTO `file` (`uuid`, `blob_id`, `name`, `download_name`, `url`, `is_temporary`, `uploader_id`, `client`, `category`, `status`, `deleted_at`)
         VALUES (?, ?, ?, ?, ?, 0, 1, 'system', 'template', 'enabled', NULL)
         ON DUPLICATE KEY UPDATE
            `id` = LAST_INSERT_ID(`id`),
            `blob_id` = VALUES(`blob_id`),
            `name` = VALUES(`name`),
            `download_name` = VALUES(`download_name`),
            `url` = VALUES(`url`),
            `is_temporary` = 0,
            `category` = 'template',
            `status` = 'enabled',
            `deleted_at` = NULL"
    );
    $uuid = sprintf('00000000-0000-0000-0000-%012d', 240000 + $sequence);
    $fileStmt->execute([$uuid, $blobId, $fileName, $downloadName . '.' . $ext, $url]);

    return (int) $pdo->lastInsertId();
}

function seedOperationGuides(PDO $pdo): void
{
    $guides = [
        ['internship', '实习管理操作说明', '实习管理围绕实习计划、实习任务、任务绑定、实习方式申请、延期申请、签到、日志、报告、成绩和归档材料进行全过程留痕。', '管理员按计划拆分任务并绑定班级，系统展开学生生成任务绑定；学生按任务完成过程材料，任务老师按任务审核评阅，学校管理员按学院、专业、届次查看整体进度。', '学生看不到列表筛选时，先确认当前账号是否为学生角色；教师看不到学生时，检查任务绑定和组织范围；审核退回后学生重新提交会形成新的记录。', 10],
        ['companyManage', '基地管理操作说明', '基地管理统一维护基地建设资料、基地申报和基地使用记录。', '管理员先维护长期或临时基地资料，再按实际业务提交基地申报或基地使用记录；提交审核后由具备审核权限的管理员处理，全部流程保留提交和审核记录。', '学院管理员和专业管理员仅能查看本组织范围内的基地数据；基地申报和基地使用保存草稿后不会进入审核待办。', 15],
        ['practice', '实验实训管理操作说明', '实验实训管理统一维护教学计划、专业课表、项目、大纲、教案、成绩和反思报告，并通过类别区分实验与实训。', '管理员先维护届次、学院、专业和十二节通用课节，再按专业安排二维周课表；每条课表明确实验或实训类别、教师、日期、起止课节和场地，项目发布后按届次与专业绑定学生。', '课表必须先选择届次、学院和专业；同专业、教师或场地在重叠课节内不能重复排课；历史课表仍按保存时的时间快照显示。', 20],
        ['socialPractice', '社会实践管理操作说明', '社会实践按年级组织，同一计划可同时开展集中实践和分散实践。', '管理员创建并发布计划；集中实践由管理员配置项目、教师和学生，分散实践由学生个人或团队申报并完成教师确认；安全条件完整后进入签到、成果、成绩和归档。', '学生只能查看本人参与数据，教师只能查看本人项目和学生；家长知情书为可选材料，不会阻断实施、评分或归档。', 30],
        ['stat', '统计报表操作说明', '统计报表按当前角色的数据范围展示实习总览、学院统计、专业统计、任务老师统计、学生过程统计和归档材料统计。', '选择左侧报表菜单后，通过届次、学院、专业和关键词筛选数据；切换报表菜单可查看不同统计口径的数据明细。', '如果统计值与列表不一致，优先确认当前角色的数据范围、筛选条件和业务数据是否已刷新。', 40],
        ['log', '日志审计操作说明', '日志审计读取当前学校业务库下所有 operation_log 按月分表，支持关键词、动作、IP 和日期范围查询。', '管理员进入日志审计后先设置查询条件，再查看来源分表、操作账号、动作、IP 和日志内容。', '如果日志为空，检查当前月份日志分表是否存在，以及账号是否具备日志查看权限。', 50],
        ['file', '文件管理操作说明', '文件管理用于查看学校业务库内的上传文件、上传人、上传时间、设备信息和文件状态。', '通过关键词、状态和分类定位文件，点击打开可查看文件访问地址。', '如果文件打不开，检查文件状态、存储配置和浏览器访问权限。', 60],
        ['config', '系统配置操作说明', '系统配置维护菜单权限、角色权限、组织范围、基础档案、操作说明和企业微信应用配置。', '菜单树按主菜单、业务菜单、列表、按钮维护；角色授权按树勾选；组织范围用于限制学院、专业、班级、基地等数据边界。', '如果授权后没有生效，刷新权限或重新登录；如果菜单结构异常，先检查父级是否选择为按钮节点。', 70],
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
    $pdo->exec("UPDATE `operation_guide` SET `status` = 'disabled', `deleted_at` = COALESCE(`deleted_at`, NOW()) WHERE `module_key` IN ('training', 'lab')");
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
        [3, 0, 'practice', '实验实训管理', 30],
        [7, 0, 'social_practice', '社会实践管理', 40],
        [5, 0, 'wechat', '企业微信', 50],
        [6, 0, 'file', '文件管理', 60],
    ];

    $groupStmt = $pdo->prepare(
        "INSERT INTO `config_group` (`id`, `parent_id`, `code`, `name`, `sort`, `status`)
         VALUES (?, ?, ?, ?, ?, 'enabled')
         ON DUPLICATE KEY UPDATE
            `parent_id` = VALUES(`parent_id`),
            `code` = VALUES(`code`),
            `name` = VALUES(`name`),
            `sort` = VALUES(`sort`),
            `status` = 'enabled',
            `deleted_at` = NULL"
    );
    foreach ($groups as $group) {
        $groupStmt->execute($group);
    }

    $items = [
        [1, 'login_background_url', '', 'PC 登录页学校背景图', 10],
        [1, 'public_register_enabled', false, '临时公开注册入口开关，交付前关闭', 20],
        [2, 'sign_in_radius', 500, '学生 GPS 签到时允许的最大距离，单位米', 10],
        [2, 'pair_mode', 'admin_assign', '实习任务绑定模式', 20],
        [2, 'max_student_count', 20, '任务老师默认负责学生数上限', 30],
        [3, 'booking_max_days', 14, '实验实训室最长可预约天数', 10],
        [7, 'teacher_confirm_hours', 48, '分散实践教师默认确认时限，单位小时', 10],
        [7, 'max_reselect_count', 2, '分散实践学生默认重新选择教师次数', 20],
        [7, 'default_team_submit_mode', 'individual', '团队成果默认提交方式', 30],
        [7, 'parent_notice_required', false, '家长知情书是否必交，当前需保持关闭', 40],
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
        [6, 'file_retention_days', 7, '临时文件保留天数', 40],
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
    $pdo->exec("UPDATE `config_group` SET `status` = 'disabled', `deleted_at` = COALESCE(`deleted_at`, NOW()) WHERE `id` = 4");
    $pdo->exec("UPDATE `config_item` SET `status` = 'disabled', `deleted_at` = COALESCE(`deleted_at`, NOW()) WHERE `group_id` = 4");
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    initializeDatabases();
}
