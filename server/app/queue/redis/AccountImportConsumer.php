<?php

namespace app\queue\redis;

use app\model\channel\AccountImportRecord;
use app\server\account\AccountImportService;
use app\server\school\SchoolConnectionManager;
use support\Context;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

class AccountImportConsumer implements Consumer
{
    public string $queue = AccountImportService::QUEUE;
    public string $connection = 'default';

    public function consume($data): void
    {
        Context::reset();
        $databaseId = (int) ($data['database_id'] ?? 0);
        $taskId = (int) ($data['task_id'] ?? 0);
        if ($databaseId < 1 || $taskId < 1) {
            return;
        }
        $service = new AccountImportService();
        $connected = false;
        $cursor = 0;
        try {
            (new SchoolConnectionManager())->bootstrapById($databaseId);
            $connected = true;
            $cursor = (int) (AccountImportRecord::task($taskId)['processed_rows'] ?? 0);
            $nextCursor = $service->processChunk($taskId);
            if ($nextCursor !== null) {
                $cursor = $nextCursor;
                $service->enqueue($taskId, $cursor);
            }
        } catch (Throwable $exception) {
            if ($connected) {
                $service->markFailed($taskId, '任务执行中断，请检查管理员权限、数据结构及队列后重试', $cursor);
            }
            Log::error('account import failed', ['database_id' => $databaseId, 'task_id' => $taskId, 'exception' => get_class($exception), 'code' => $exception->getCode()]);
            if (!$connected) {
                throw $exception;
            }
        } finally {
            Context::reset();
        }
    }
}
