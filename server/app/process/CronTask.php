<?php

namespace app\process;

use app\model\system\Database;
use app\server\WorkflowLock;
use app\server\maintenance\InternshipScheduledService;
use app\server\maintenance\ScheduledMaintenanceService;
use app\server\school\SchoolConnectionManager;
use support\Log;
use Throwable;
use Workerman\Crontab\Crontab;

/**
 * 后台定时任务调度进程。
 */
class CronTask
{
    /**
     * 注册后台任务执行时间。
     */
    public function onWorkerStart(): void
    {
        new Crontab('0 10 * * * *', fn () => \app\server\release\ReleaseUploadService::cleanup());
        new Crontab('25 */5 * * * *', function (): void {
            $this->runForSchools('desktop_download_requeue', date('YmdHi'), 290, function (int $databaseId): int {
                $ids = \app\model\channel\ReleaseRecord::retryableAssetIds();
                foreach ($ids as $id) \Webman\RedisQueue\Client::send('desktop-release-download', ['database_id' => $databaseId, 'asset_id' => $id]);
                return count($ids);
            });
        });
        new Crontab('5 * * * * *', function (): void {
            $this->runForSchools('export_requeue', date('YmdHi'), 55, function (int $databaseId): int {
                return (new ScheduledMaintenanceService())->requeuePendingExports($databaseId);
            });
        });

        new Crontab('15 */5 * * * *', function (): void {
            $this->runForSchools('export_timeout', date('YmdHi'), 290, function (int $_databaseId): int {
                return (new ScheduledMaintenanceService())->timeoutExports();
            });
        });

        new Crontab('0 0 2 * * *', function (): void {
            $this->runForSchools('temporary_file_cleanup', date('Ymd'), 82800, function (int $_databaseId): array {
                return (new ScheduledMaintenanceService())->cleanupTemporaryFiles();
            });
        });

        new Crontab('0 30 2 * 7,12 *', function (): void {
            $this->runForSchools('recording_archive', date('Ymd'), 82800, function (int $_databaseId): array {
                return (new ScheduledMaintenanceService())->archiveRecordings();
            });
        });

        new Crontab('0 30 6 * * 1', function (): void {
            $this->runForSchools('internship_weekly_brief', date('oW'), 604000, function (int $_databaseId): array {
                return (new InternshipScheduledService())->generateWeeklyBrief();
            });
        });

        new Crontab('0 0 9 * * *', function (): void {
            $this->runForSchools('insurance_expiry_reminder', date('Ymd'), 82800, function (int $_databaseId): array {
                return (new InternshipScheduledService())->remindExpiringInsurance();
            });
        });
    }

    /**
     * 在所有启用学校业务库执行指定任务。
     */
    private function runForSchools(string $taskCode, string $periodKey, int $lockTtl, callable $callback): void
    {
        \support\Context::reset();

        try {
            $databases = Database::enabledConnectionConfigs();
        } catch (Throwable $exception) {
            Log::error('cron 读取学校库列表失败: ' . $exception->getMessage());
            return;
        }

        foreach ($databases as $config) {
            $databaseId = (int) ($config['database_id'] ?? 0);
            if ($databaseId <= 0) {
                continue;
            }

            try {
                (new SchoolConnectionManager())->ensureConnection($databaseId, $config);
                $result = (new WorkflowLock())->runOnce(
                    "cron_task:{$databaseId}:{$taskCode}:{$periodKey}",
                    fn (): mixed => $callback($databaseId),
                    $lockTtl
                );
                $this->logResult($taskCode, $databaseId, $result);
            } catch (Throwable $exception) {
                if ((int) $exception->getCode() !== 409) {
                    Log::error("cron {$taskCode} 失败 db={$databaseId}: " . $exception->getMessage());
                }
            }
        }
    }

    /**
     * 记录产生实际处理结果的后台任务。
     */
    private function logResult(string $taskCode, int $databaseId, mixed $result): void
    {
        $hasResult = is_int($result) ? $result > 0 : (is_array($result) && $this->arrayHasWork($result));
        if (!$hasResult) {
            return;
        }

        Log::info("cron {$taskCode} 完成 db={$databaseId} result=" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 判断任务结果是否包含正数处理量。
     */
    private function arrayHasWork(array $result): bool
    {
        $workKeys = ['archived', 'created', 'deleted', 'physical_deleted', 'orphan_blobs_deleted', 'failed', 'physical_delete_failed', 'sent', 'notified_accounts'];
        foreach ($result as $key => $value) {
            if ($key === 'created' && $value === true) {
                return true;
            }
            if (in_array((string) $key, $workKeys, true) && is_numeric($value) && (float) $value > 0) {
                return true;
            }
        }
        return false;
    }
}
