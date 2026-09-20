<?php

/** 补充消息通知队列和个人问答存储。 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/support/bootstrap.php';

$ids = array_filter(array_map('intval', array_slice($argv, 1)));
if (!$ids) throw new RuntimeException('用法: php database/migrations/20260920_message_assistant.php 学校数据库编号');
foreach (array_unique($ids) as $id) {
    \support\Context::reset();
    (new \app\server\school\SchoolConnectionManager())->bootstrapById($id);
    \app\model\channel\MessageRealtimeRecord::install();
    echo "学校数据库 {$id}: 消息与问答结构已更新\n";
}
