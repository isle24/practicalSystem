<?php

namespace app\server\maintenance;

use app\model\channel\ConfigItem;
use app\model\channel\ExportTaskRecord;
use app\model\channel\RecordingArchiveRecord;
use app\server\file\FileService;
use app\server\export\ExportTaskService;
use Webman\RedisQueue\Redis as RedisQueue;

class ScheduledMaintenanceService
{
    /**
     * 补投创建超过 1 分钟仍待处理的导出任务。
     */
    public function requeuePendingExports(int $databaseId, int $limit = 50): int
    {
        $before = date('Y-m-d H:i:s', time() - 60);
        $taskIds = ExportTaskRecord::stalePendingIds($before, $limit);
        foreach ($taskIds as $taskId) {
            RedisQueue::send(ExportTaskService::QUEUE, [
                'database_id' => $databaseId,
                'task_id' => (int) $taskId,
            ]);
        }

        return count($taskIds);
    }

    /**
     * 标记执行超过 30 分钟的导出任务。
     */
    public function timeoutExports(): int
    {
        $now = date('Y-m-d H:i:s');
        $before = date('Y-m-d H:i:s', time() - 1800);
        return ExportTaskRecord::markProcessingTimedOut($before, $now);
    }

    /**
     * 按学校文件保留配置清理临时文件。
     */
    public function cleanupTemporaryFiles(): array
    {
        $days = (int) (ConfigItem::enabledValue(6, 'file_retention_days') ?? 7);
        return (new FileService())->cleanupExpiredTemporaryFiles($days);
    }

    /**
     * 按届次归档历史 recording 和审核意见。
     */
    public function archiveRecordings(): array
    {
        return RecordingArchiveRecord::archiveOlderGrades((int) date('Y'));
    }
}
