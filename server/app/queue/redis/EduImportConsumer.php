<?php

namespace app\queue\redis;

use app\model\channel\EduDataRecord;
use app\server\edu\EduImportService;
use app\server\school\SchoolConnectionManager;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis as RedisQueue;

class EduImportConsumer implements Consumer
{
    public string $queue = EduImportService::QUEUE;

    public string $connection = 'default';

    public function consume($data): void
    {
        \support\Context::reset();
        $databaseId = (int) ($data['database_id'] ?? 0);
        $batchId = (int) ($data['batch_id'] ?? 0);
        $attempt = max(0, (int) ($data['attempt'] ?? 0));
        if ($databaseId <= 0 || $batchId <= 0) {
            return;
        }

        try {
            (new SchoolConnectionManager())->bootstrapById($databaseId);
            $batch = EduDataRecord::batchById($batchId);
            if (!$batch || !in_array((string) ($batch['status'] ?? ''), ['queued', 'failed'], true)) {
                return;
            }
            (new EduImportService())->parse($batchId);
        } catch (Throwable $exception) {
            $this->handleFailure($databaseId, $batchId, $attempt, $exception);
        }
    }

    private function handleFailure(int $databaseId, int $batchId, int $attempt, Throwable $exception): void
    {
        try {
            $batch = EduDataRecord::batchById($batchId);
            $status = (string) ($batch['status'] ?? '');
            if ($status === 'failed' || $attempt >= 2) {
                EduDataRecord::updateBatch($batchId, [
                    'status' => 'failed',
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Log::error('edu import batch failed batch=' . $batchId . ': ' . $exception->getMessage());
                return;
            }
            EduDataRecord::updateBatch($batchId, [
                'status' => 'queued',
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            RedisQueue::send($this->queue, [
                'database_id' => $databaseId,
                'batch_id' => $batchId,
                'import_type' => $batch['import_type'] ?? null,
                'attempt' => $attempt + 1,
            ]);
        } catch (Throwable $retryException) {
            Log::error('edu import retry failed batch=' . $batchId . ': ' . $retryException->getMessage());
        }
    }
}
