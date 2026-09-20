<?php

namespace app\model\channel;

/** 与业务事务共同提交的实时通知。 */
class MessageRealtimeRecord extends TableRecord
{
    /** 执行固定结构升级。 */
    public static function install(): void
    {
        $sql = file_get_contents(base_path('database/updates/0.3.4-message-assistant.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            self::connection()->unprepared($statement);
        }
    }

    /** 在现有事务内记录刷新对象。 */
    public static function enqueueNotification(array $accountIds, string $topic = 'messages'): void
    {
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $accountIds)))), 500) as $ids) {
            self::queryTable('message_realtime_outbox')->insert([
                'account_ids' => json_encode($ids), 'topic' => $topic, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** 读取已提交通知；外层事务负责锁定和发送。 */
    public static function pending(): array
    {
        return self::queryTable('message_realtime_outbox')->orderBy('id')->limit(100)->lockForUpdate()->get()->toArray();
    }

    /** 移除已投递的临时通知，不影响消息和审计记录。 */
    public static function acknowledge(array $ids): void
    {
        self::queryTable('message_realtime_outbox')->whereIn('id', $ids)->delete();
    }
}
