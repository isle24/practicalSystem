<?php

namespace app\server\message;

use app\model\channel\AssistantRecord;
use app\model\channel\TableRecord;
use app\server\config\ConfigService;
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
        $config = new ConfigService();
        $enabled = (bool) $config->get('assistant.enabled');
        $result = ['enabled' => $enabled, 'name' => (string) ($config->get('assistant.name') ?: '问答助手'), 'can_manage' => $this->isAdmin()];
        if ($this->isAdmin()) {
            $result['settings'] = array_column($config->list('assistant'), 'value', 'key');
        }
        return $result;
    }

    /** 保存学校助手配置；密钥不回传明文。 */
    public function saveSettings(array $payload): array
    {
        if (!$this->isAdmin()) throw new RuntimeException('仅学校管理员可以配置助手', 403);
        $url = rtrim(trim((string) ($payload['endpoint'] ?? '')), '/');
        $model = trim((string) ($payload['model'] ?? ''));
        $key = trim((string) ($payload['api_key'] ?? ''));
        $enabled = in_array($payload['enabled'] ?? false, [true, 1, '1'], true);
        if ($url !== '') AssistantTransport::validateEndpoint($url);
        if (strlen($url) > 500 || strlen($model) > 120 || strlen($key) > 2048 || preg_match('/[\r\n]/', $key)) throw new RuntimeException('接口配置格式无效', 400);
        $config = new ConfigService();
        if ($enabled && ($url === '' || $model === '' || (in_array($key, ['', '******'], true) && !$config->get('assistant.api_key')))) throw new RuntimeException('启用前请填写接口、模型和密钥', 400);
        TableRecord::connection()->transaction(function () use ($config, $payload, $url, $model, $key, $enabled): void {
            foreach (['enabled' => $enabled, 'name' => mb_substr(trim((string) ($payload['name'] ?? '问答助手')), 0, 60) ?: '问答助手', 'endpoint' => $url, 'model' => $model] as $name => $value) {
                $config->set('assistant', $name, $value);
            }
            if ($key !== '' && $key !== '******') $config->set('assistant', 'api_key', $key);
        });
        return $this->settings();
    }

    /** 幂等创建任务，不在 HTTP worker 中等待模型响应。 */
    public function ask(array $payload): array
    {
        $accountId = (int) CurrentContext::accountId();
        $question = trim((string) ($payload['question'] ?? ''));
        $requestId = (string) ($payload['request_id'] ?? '');
        if ($question === '' || mb_strlen($question) > 4000) throw new RuntimeException('问题须为 1 至 4000 字', 400);
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $requestId)) throw new RuntimeException('请求标识无效', 400);
        if (!(new ConfigService())->get('assistant.enabled')) throw new RuntimeException('问答助手尚未启用，请联系学校管理员', 400);
        return (new WorkflowLock())->run(WorkflowLock::key('assistant', 'ask', $accountId), function () use ($accountId, $payload, $requestId, $question): array {
            if ($existing = AssistantRecord::byRequest($accountId, $requestId)) return $existing;
            $key = 'assistant_rate:' . CurrentContext::schoolDatabaseId() . ':' . $accountId;
            $count = (int) Redis::eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],60) end; return n", 1, $key);
            if ($count > 10) throw new RuntimeException('提问过于频繁，请稍后重试', 429);
            $turn = AssistantRecord::enqueue($accountId, (int) ($payload['thread_id'] ?? 0), $requestId, $question);
            try {
                if (!Queue::send('assistant-answer', ['database_id' => CurrentContext::schoolDatabaseId(), 'turn_id' => $turn['id']])) throw new RuntimeException('queue unavailable');
            } catch (\Throwable) {
                AssistantRecord::finish($turn['id'], '', '任务队列暂不可用，请重新发送问题');
            }
            return AssistantRecord::turn($turn['id']);
        }, 15, true);
    }

    /** 校验学校配置权限。 */
    private function isAdmin(): bool
    {
        return in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true);
    }
}
