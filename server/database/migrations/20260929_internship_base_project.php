<?php

use app\server\internship\InternshipUpgradeSchema;
use app\server\school\SchoolConnectionManager;
use support\Context;
use support\Db;

$arguments = array_slice($argv ?? [], 1);
if (!$arguments) {
    throw new RuntimeException('用法: php database/migrations/20260929_internship_base_project.php 学校数据库编号 [学校数据库编号...]');
}
$ids = [];
foreach ($arguments as $argument) {
    $id = filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('学校数据库编号必须为正整数');
    }
    $ids[] = $id;
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/support/bootstrap.php';

foreach (array_unique($ids) as $id) {
    Context::reset();
    $name = (new SchoolConnectionManager())->bootstrapById($id);
    $connection = Db::connection($name);
    if ($connection->getTablePrefix() !== '') {
        throw new RuntimeException('基地结构升级不支持带表前缀的学校数据库');
    }
    $pdo = $connection->getPdo();
    InternshipUpgradeSchema::ensureBaseProjectApproval($pdo);
    InternshipUpgradeSchema::syncBaseMenus($pdo);
    echo "学校数据库 {$id}: 基地立项字段和菜单已更新\n";
}
