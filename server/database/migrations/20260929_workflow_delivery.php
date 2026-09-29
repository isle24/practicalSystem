<?php

$ids = array_slice($argv ?? [], 1);
if (!$ids) throw new RuntimeException('用法: php database/migrations/20260929_workflow_delivery.php 学校数据库编号 [学校数据库编号...]');
foreach ($ids as $id) {
    if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new InvalidArgumentException('学校数据库编号必须为正整数');
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/support/bootstrap.php';
foreach (array_unique($ids) as $id) {
    \support\Context::reset();
    (new \app\server\school\SchoolConnectionManager())->bootstrapById((int) $id);
    \app\model\channel\WorkflowDeliverySchema::apply();
    echo "学校数据库 {$id}: 流程结构已更新\n";
}
