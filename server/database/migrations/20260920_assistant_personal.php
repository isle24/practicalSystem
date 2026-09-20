<?php

/** 补充个人助手配置和问答来源。 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/support/bootstrap.php';

$ids = array_filter(array_map('intval', array_slice($argv, 1)));
if (!$ids) throw new RuntimeException('用法: php database/migrations/20260920_assistant_personal.php 学校数据库编号');
foreach (array_unique($ids) as $id) {
    \support\Context::reset();
    (new \app\server\school\SchoolConnectionManager())->bootstrapById($id);
    \app\model\channel\MessageRealtimeRecord::install();
    \app\model\channel\AssistantProfile::install();
    echo "学校数据库 {$id}: 个人助手结构已更新\n";
}
