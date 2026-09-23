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
    foreach ($statements as $statement) $connection->unprepared($statement);
    echo "学校数据库 {$id}: 基地巡查结构已更新\n";
}
