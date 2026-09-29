<?php

namespace app\process;

use app\server\workflow\WorkflowDeliveryService;
use app\model\system\Database;
use app\server\school\SchoolConnectionManager;
use support\Context;
use support\Log;
use Throwable;
use Workerman\Timer;

class WorkflowMessageRelay
{
    private array $schools = [];
    private int $lastErrorAt = 0;
    private bool $running = false;

    public function onWorkerStart(): void
    {
        $this->reloadSchools();
        Timer::add(60, fn () => $this->reloadSchools());
        Timer::add(5, fn () => $this->relay());
    }

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
                    (new WorkflowDeliveryService())->run();
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

    private function logError(?Throwable $exception = null, ?int $databaseId = null): void
    {
        if (time() - $this->lastErrorAt < 60) return;
        $this->lastErrorAt = time();
        $context = [];
        if ($databaseId !== null) $context['database_id'] = $databaseId;
        if ($exception) {
            $context += [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => mb_substr($exception->getTraceAsString(), 0, 12000),
            ];
        }
        Log::error('工作流消息投递失败，持久化任务将继续重试', $context);
    }
}
