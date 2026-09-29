<?php

namespace app\model\channel;

class WorkflowDeliverySchema extends TableRecord
{
    public static function apply(): void
    {
        $connection = self::connection();
        if ($connection->transactionLevel() > 0) throw new \RuntimeException('通知结构升级不能在事务内执行');
        if (!$connection->getSchemaBuilder()->hasColumn('workflow_outbox', 'message_id')) $connection->statement('ALTER TABLE `workflow_outbox` ADD COLUMN `message_id` BIGINT UNSIGNED DEFAULT NULL');
        MessageRecord::ensureSchema();
        MessageRecord::seedDefaultTemplates(true);
    }
}
