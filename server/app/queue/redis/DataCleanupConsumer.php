<?php

namespace app\queue\redis;

use app\model\channel\DataCleanupRecord;
use app\server\maintenance\DataCleanupService;
use app\server\school\SchoolConnectionManager;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/** 分批执行测试数据清理任务，避免单次请求占用数据库连接。 */
class DataCleanupConsumer implements Consumer
{
    public string $queue = DataCleanupService::QUEUE;
    public string $connection = 'default';

    public function consume($data): void
    {
        \support\Context::reset();
        $databaseId = (int) ($data['database_id'] ?? 0);
        $taskId = (int) ($data['task_id'] ?? 0);
        if ($databaseId <= 0 || $taskId <= 0) return;
        try {
            (new SchoolConnectionManager())->bootstrapById($databaseId);
            $task = DataCleanupRecord::taskById($taskId);
            if (!$task) return;
            if ((string) ($task['status'] ?? '') === 'queued') {
                $task = DataCleanupRecord::claimTask($taskId, date('Y-m-d H:i:s'));
            }
            if (!$task || !in_array((string) ($task['status'] ?? ''), ['processing'], true)) return;
            $this->run($task);
        } catch (Throwable $exception) {
            DataCleanupRecord::updateTask($taskId, [
                'status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Log::error('data cleanup task failed task=' . $taskId . ': ' . $exception->getMessage());
        }
    }

    private function run(array $task): void
    {
        $taskId = (int) $task['id'];
        $scopes = DataCleanupRecord::taskScopes($task);
        $preserve = (bool) $task['preserve_edu_data'];
        $total = 0;
        foreach ($scopes as $scope) {
            if ($scope === 'edu_data' && $preserve) continue;
            $total += DataCleanupRecord::scopeTableCount($scope, $preserve);
        }
        $affected = [];
        $done = 0;
        DataCleanupRecord::updateTask($taskId, ['total_rows' => $total, 'updated_at' => date('Y-m-d H:i:s')]);
        foreach ($scopes as $scope) {
            if ($scope === 'edu_data' && $preserve) continue;
            do {
                $result = DataCleanupRecord::deleteScope($scope, $preserve, 500, date('Y-m-d H:i:s'));
                $count = (int) ($result['rows'] ?? 0);
                $done += $count;
                foreach ((array) ($result['tables'] ?? []) as $table => $value) $affected[$table] = ($affected[$table] ?? 0) + (int) $value;
                $progress = $total > 0 ? min(99, (int) floor(($done / $total) * 100)) : 99;
                DataCleanupRecord::updateTask($taskId, [
                    'progress' => $progress, 'affected_rows' => $done,
                    'affected_json' => json_encode($affected, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } while ($count > 0);
        }
        DataCleanupRecord::updateTask($taskId, [
            'status' => 'completed', 'progress' => 100, 'affected_rows' => $done,
            'affected_json' => json_encode($affected, JSON_UNESCAPED_UNICODE),
            'finished_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
