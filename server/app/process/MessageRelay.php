<?php

namespace app\process;

use app\model\channel\MessageRealtimeRecord;
use app\model\system\Database;
use app\server\school\SchoolConnectionManager;
use support\Context;
use support\Log;
use support\Redis;
use Throwable;
use Workerman\Timer;

/** 将已提交的通知投递至 Redis，再由 WebSocket 扇出。 */
class MessageRelay
{
    private array $schools = [];
    private int $lastErrorAt = 0;
    private bool $running = false;

    /** 启动通知转发并缓存学校连接配置。 */
    public function onWorkerStart(): void
    {
        $this->reloadSchools();
        Timer::add(60, fn () => $this->reloadSchools());
        Timer::add(1, fn () => $this->relay());
    }

    /** 每分钟更新启用学校，不按消息访问主库。 */
    private function reloadSchools(): void
    {
        try {
            $this->schools = Database::enabledConnectionConfigs();
        } catch (Throwable $exception) {
            $this->logError($exception);
        } finally {
            Context::destroy();
        }
    }

    /** 行锁防止多个服务实例同时消费同一通知。 */
    private function relay(): void
    {
        if ($this->running) return;
        $this->running = true;
        try {
            foreach ($this->schools as $school) {
                Context::destroy();
                $id = (int) ($school['database_id'] ?? 0);
                try {
                    (new SchoolConnectionManager())->ensureConnection($id, $school);
                    MessageRealtimeRecord::connection()->transaction(function () use ($id): void {
                        $rows = MessageRealtimeRecord::pending();
                        foreach ($rows as $row) {
                            Redis::publish(config('message_realtime.channel'), json_encode([
                                'school' => $id, 'accounts' => json_decode($row['account_ids'], true), 'topic' => $row['topic'],
                            ]));
                        }
                        if ($rows) MessageRealtimeRecord::acknowledge(array_column($rows, 'id'));
                    });
                } catch (Throwable $exception) {
                    $this->logError($exception, $id);
                } finally {
                    Context::destroy();
                }
            }
        } finally {
            $this->running = false;
        }
    }

    /** 限制依赖故障时的重复错误日志。 */
    private function logError(Throwable $exception, ?int $databaseId = null): void
    {
        if (time() - $this->lastErrorAt < 60) return;
        $this->lastErrorAt = time();
        Log::error('消息实时转发失败', [
            'database_id' => $databaseId ?? null,
            'exception' => $exception::class,
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => mb_substr($exception->getTraceAsString(), 0, 12000),
        ]);
    }
}
