<?php

namespace app\server\maintenance;

use app\model\channel\DataCleanupRecord;
use app\server\CurrentContext;
use InvalidArgumentException;
use Webman\RedisQueue\Redis as RedisQueue;

/** 创建、查询并投递测试数据清理任务。 */
class DataCleanupService
{
    public const QUEUE = 'data_cleanup';

    public function options(): array
    {
        DataCleanupRecord::install();
        return ['items' => DataCleanupRecord::scopeOptions()];
    }

    public function create(array $payload): array
    {
        $this->assertAllowed();
        DataCleanupRecord::install();
        $scopes = is_array($payload['scopes'] ?? null) ? array_values(array_filter(array_map('strval', $payload['scopes']))) : [];
        if (!$scopes) throw new InvalidArgumentException('至少选择一个清理范围');
        $valid = array_column(DataCleanupRecord::scopeOptions(), 'key');
        $scopes = array_values(array_intersect($valid, $scopes));
        if (!$scopes) throw new InvalidArgumentException('清理范围无效');
        $preserve = filter_var($payload['preserve_edu_data'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $accountId = (int) CurrentContext::accountId();
        $now = date('Y-m-d H:i:s');
        $id = DataCleanupRecord::createTask($accountId, $scopes, $preserve, $now);
        try {
            RedisQueue::send(self::QUEUE, ['database_id' => CurrentContext::schoolDatabaseId(), 'task_id' => $id]);
        } catch (\Throwable $exception) {
            DataCleanupRecord::updateTask($id, ['status' => 'failed', 'error_message' => '清理任务入队失败', 'updated_at' => $now]);
            throw $exception;
        }
        return ['task' => DataCleanupRecord::taskById($id)];
    }

    public function detail(int $id): array
    {
        $this->assertAllowed();
        DataCleanupRecord::install();
        $task = DataCleanupRecord::taskById($id);
        if (!$task || (int) $task['created_by'] !== (int) CurrentContext::accountId()) throw new InvalidArgumentException('清理任务不存在');
        return ['task' => $task];
    }

    private function assertAllowed(): void
    {
        if (CurrentContext::roleType() !== 'super_admin') throw new InvalidArgumentException('仅超级管理员可执行数据清理', 403);
        if (!\app\server\RuntimeEnvironment::isTest()) throw new InvalidArgumentException('当前不是测试环境，禁止清除测试数据', 403);
    }
}
