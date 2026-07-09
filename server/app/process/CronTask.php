<?php

namespace app\process;

use app\model\system\Database;
use app\model\channel\ExportTaskRecord;
use app\server\export\ExportTaskService;
use app\server\school\SchoolConnectionManager;
use support\Log;
use Throwable;
use Webman\RedisQueue\Redis as RedisQueue;
use Workerman\Crontab\Crontab;

/**
 * 定时任务调度进程（骨架）。
 *
 * 后台进程无 HTTP 上下文，逐个学校库切换连接后执行。
 * 目前仅注册一个任务：补投滞留的待处理导出任务
 * （入队失败或消费者宕机时的兜底，保证导出最终会被处理）。
 * 后续的超时标记、临时文件清理、简报生成等定时任务在此处追加。
 */
class CronTask
{
    /**
     * 单个学校库单次补投的最大任务数，避免堆积时一次扫太多。
     */
    private const REQUEUE_LIMIT = 50;

    public function onWorkerStart(): void
    {
        // 每分钟第 5 秒执行，避开整点，降低与其它任务的碰撞
        new Crontab('5 * * * * *', function (): void {
            $this->requeueStuckExports();
        });
    }

    /**
     * 扫描所有启用学校库，补投滞留超过 1 分钟仍未处理的导出任务。
     */
    private function requeueStuckExports(): void
    {
        // 后台进程无 HTTP 生命周期，手动初始化上下文存储，
        // 避免 Fiber\Context 未初始化的致命错误。
        \support\Context::reset();

        try {
            $databases = Database::enabledConnectionConfigs();
        } catch (Throwable $exception) {
            Log::error('cron 读取学校库列表失败: ' . $exception->getMessage());
            return;
        }

        $before = date('Y-m-d H:i:s', time() - 60);
        foreach ($databases as $config) {
            $databaseId = (int) ($config['database_id'] ?? 0);
            if ($databaseId <= 0) {
                continue;
            }

            try {
                (new SchoolConnectionManager())->ensureConnection($databaseId, $config);
                $taskIds = ExportTaskRecord::stalePendingIds($before, self::REQUEUE_LIMIT);
                foreach ($taskIds as $taskId) {
                    RedisQueue::send(ExportTaskService::QUEUE, [
                        'database_id' => $databaseId,
                        'task_id' => (int) $taskId,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('cron 补投导出任务失败 db=' . $databaseId . ': ' . $exception->getMessage());
            }
        }
    }
}
