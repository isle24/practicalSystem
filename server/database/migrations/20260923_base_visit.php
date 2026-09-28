<?php

/** 按学校数据库编号补充基地巡查结构。 */
$arguments = array_slice($argv ?? [], 1);
if (!$arguments) throw new RuntimeException('用法: php database/migrations/20260923_base_visit.php 学校数据库编号 [学校数据库编号...]');
$ids = [];
foreach ($arguments as $argument) {
    $id = filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException('学校数据库编号必须为正整数');
    $ids[] = $id;
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/support/bootstrap.php';

$sql = file_get_contents(dirname(__DIR__) . '/updates/20260923-base-visit.sql');
if ($sql === false) throw new RuntimeException('无法读取基地巡查结构 SQL');
$statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
foreach (array_unique($ids) as $id) {
    \support\Context::reset();
    $name = (new \app\server\school\SchoolConnectionManager())->bootstrapById($id);
    $connection = \support\Db::connection($name);
    if ($connection->getTablePrefix() !== '') {
        throw new RuntimeException('基地巡查结构升级不支持带表前缀的学校数据库');
    }
    $deferred = [];
    foreach ($statements as $statement) {
        $upper = strtoupper($statement);
        if (str_starts_with($upper, 'ALTER TABLE `BASE_VISIT_PLAN`')) continue;
        if (str_starts_with($upper, 'INSERT INTO `BASE_VISIT_PARTICIPANT`')) { $deferred[] = $statement; continue; }
        $connection->unprepared($statement);
    }
    $schema = $connection->getSchemaBuilder();
    foreach ([
        'base_manager_name' => 'VARCHAR(80) DEFAULT NULL AFTER `base_location`',
        'base_manager_phone' => 'VARCHAR(40) DEFAULT NULL AFTER `base_manager_name`',
        'contact_account_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `teacher_department`',
        'contact_person' => 'VARCHAR(180) DEFAULT NULL AFTER `contact_account_id`',
        'supervisor_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER `contact_phone`',
        'participant_ids' => 'JSON DEFAULT NULL AFTER `supervisor_id`',
        'news_url' => 'VARCHAR(500) DEFAULT NULL AFTER `participant_ids`',
    ] as $column => $definition) {
        if (!$schema->hasColumn('base_visit_plan', $column)) {
            $connection->statement("ALTER TABLE `base_visit_plan` ADD COLUMN `{$column}` {$definition}");
        }
    }
    $teacherColumn = $connection->selectOne(
        "SELECT IS_NULLABLE AS nullable_flag FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'teacher_id'"
    );
    if ($teacherColumn && strtoupper((string) $teacherColumn->nullable_flag) !== 'YES') {
        $connection->statement('ALTER TABLE `base_visit_plan` MODIFY COLUMN `teacher_id` BIGINT UNSIGNED DEFAULT NULL');
    }
    foreach ($deferred as $statement) $connection->unprepared($statement);
    echo "学校数据库 {$id}: 基地巡查结构已更新\n";
}
