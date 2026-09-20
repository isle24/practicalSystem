<?php

namespace app\server\message;

use app\model\channel\AssistantRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use RuntimeException;
use support\Redis;
use Webman\RedisQueue\Redis as Queue;

/** 仅处理用户主动发起的问答，不提供业务写入工具。 */
class AssistantService
{
    /** 返回公开可用状态及管理员的脱敏配置。 */
    public function settings(): array
    {
        return (new AssistantConfiguration())->settings();
    }

    /** 保存已授权的学校或个人配置；密钥不回传明文。 */
    public function saveSettings(array $payload): array
    {
        return (new AssistantConfiguration())->save($payload);
    }

    /** 幂等创建任务，不在 HTTP worker 中等待模型响应。 */
    public function ask(array $payload): array
    {
        $accountId = (int) CurrentContext::accountId();
        $question = trim((string) ($payload['question'] ?? ''));
        $requestId = (string) ($payload['request_id'] ?? '');
        if ($question === '' || mb_strlen($question) > 4000) throw new RuntimeException('问题须为 1 至 4000 字', 400);
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $requestId)) throw new RuntimeException('请求标识无效', 400);
        return (new WorkflowLock())->run(WorkflowLock::key('assistant', 'ask', $accountId), function () use ($accountId, $payload, $requestId, $question): array {
            if ($existing = AssistantRecord::byRequest($accountId, $requestId)) return $existing;
            $provider = (new AssistantConfiguration())->resolve((string) ($payload['mode'] ?? 'school'), $accountId);
            $key = 'assistant_rate:' . CurrentContext::schoolDatabaseId() . ':' . $accountId;
            $count = (int) Redis::eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],60) end; return n", 1, $key);
            if ($count > 10) throw new RuntimeException('提问过于频繁，请稍后重试', 429);
            $turn = AssistantRecord::enqueue($accountId, (int) ($payload['thread_id'] ?? 0), $requestId, $question, $provider['mode'], $provider['revision']);
            try {
                if (!Queue::send('assistant-answer', ['database_id' => CurrentContext::schoolDatabaseId(), 'turn_id' => $turn['id']])) throw new RuntimeException('queue unavailable');
            } catch (\Throwable) {
                AssistantRecord::finish($turn['id'], '', '任务队列暂不可用，请重新发送问题');
            }
            return AssistantRecord::turn($turn['id']);
        }, 15, true);
    }
}
