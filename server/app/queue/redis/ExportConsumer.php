<?php

namespace app\queue\redis;

use app\model\channel\ExportTaskRecord;
use app\server\export\ExportGenerator;
use app\server\export\ExportTaskService;
use app\server\file\FileService;
use app\server\school\SchoolConnectionManager;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * 导出任务消费者：接收 {database_id, task_id}，在对应租户库中
 * 生成导出文件、落库、更新任务状态并通知用户。
 */
class ExportConsumer implements Consumer
{
    public string $queue = ExportTaskService::QUEUE;

    public string $connection = 'default';

    public function consume($data): void
    {
        // 后台进程无 HTTP 请求生命周期，手动重置上下文：
        // 既初始化 Fiber\Context 存储（避免"未初始化"致命错误），
        // 也隔离不同消息（不同租户）之间的上下文，防止串库。
        \support\Context::reset();

        $databaseId = (int) ($data['database_id'] ?? 0);
        $taskId = (int) ($data['task_id'] ?? 0);
        if ($databaseId <= 0 || $taskId <= 0) {
            return;
        }

        try {
            (new SchoolConnectionManager())->bootstrapById($databaseId);
        } catch (Throwable $exception) {
            Log::error('export consumer 无法连接学校库 ' . $databaseId . ': ' . $exception->getMessage());
            return;
        }

        $task = ExportTaskRecord::taskById($taskId);
        if (!$task) {
            return;
        }
        // 仅处理待处理任务，避免重复消费已完成/处理中的任务
        if (!in_array($task['status'], ['pending', 'failed', 'timeout'], true)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        ExportTaskRecord::updateTaskStatus($taskId, [
            'status' => 'processing',
            'progress' => 10,
            'error_message' => null,
            'error_trace' => null,
            'started_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $generated = (new ExportGenerator())->generate((string) $task['type'], (array) ($task['params'] ?? []));
            $stored = (new FileService())->storeGeneratedFile($generated['path'], [
                'name' => $task['file_name'] ?: ($task['type'] . '_' . date('Ymd_His') . '.' . $generated['ext']),
                'ext' => $generated['ext'],
                'category' => 'export',
                'uploader_id' => (int) ($task['user_id'] ?? 0),
                'is_temporary' => true,
            ]);

            $finishedAt = date('Y-m-d H:i:s');
            ExportTaskRecord::updateTaskStatus($taskId, [
                'status' => 'completed',
                'progress' => 100,
                'total_rows' => (int) $generated['total_rows'],
                'file_id' => (int) $stored['file_id'],
                'finished_at' => $finishedAt,
                'updated_at' => $finishedAt,
            ]);

            $this->notify((int) ($task['user_id'] ?? 0), $taskId, '导出任务完成', '导出文件已生成，可在导出中心下载。');
        } catch (Throwable $exception) {
            $finishedAt = date('Y-m-d H:i:s');
            ExportTaskRecord::updateTaskStatus($taskId, [
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'error_trace' => mb_substr($exception->getTraceAsString(), 0, 2000),
                'finished_at' => $finishedAt,
                'updated_at' => $finishedAt,
            ]);
            Log::error('export task ' . $taskId . ' 失败: ' . $exception->getMessage());
            $this->notify((int) ($task['user_id'] ?? 0), $taskId, '导出任务失败', '导出失败：' . mb_substr($exception->getMessage(), 0, 200));
        }
    }

    private function notify(int $accountId, int $taskId, string $title, string $content): void
    {
        if ($accountId <= 0) {
            return;
        }

        try {
            (new \app\server\message\MessageService())->send([
                'account_ids' => [$accountId],
                'template_code' => 'export_task_result',
                'variables' => [
                    'export_title' => $title,
                    'export_content' => $content,
                ],
                'entity_type' => 'export_task',
                'entity_id' => $taskId,
            ], 0, '系统');
        } catch (Throwable) {
        }
    }
}
