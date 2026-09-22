<?php

/** 补充可选范围的异步测试数据清理任务表。 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/support/bootstrap.php';

$ids = array_filter(array_map('intval', array_slice($argv, 1)));
if (!$ids) throw new RuntimeException('用法: php database/migrations/20260922_data_cleanup.php 学校数据库编号');
foreach (array_unique($ids) as $id) {
    \support\Context::reset();
    (new \app\server\school\SchoolConnectionManager())->bootstrapById($id);
    \app\model\channel\DataCleanupRecord::install();
    echo "学校数据库 {$id}: 数据清理任务结构已更新\n";
}
