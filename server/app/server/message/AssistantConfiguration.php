<?php

namespace app\server\message;

use app\model\channel\AssistantProfile;
use app\model\channel\TableRecord;
use app\server\config\ConfigService;
use app\server\CurrentContext;
use app\server\security\SecretCipher;
use app\server\WorkflowLock;
use RuntimeException;

/** 学校与个人助手配置的授权、加密和来源校验。 */
class AssistantConfiguration
{
    /** 返回本人配置和可见的学校配置，不返回密钥。 */
    public function settings(): array
    {
        $config = new ConfigService();
        $profile = AssistantProfile::forAccount((int) CurrentContext::accountId()) ?? [];
        $result = [
            'enabled' => (bool) $config->get('assistant.enabled'),
            'name' => (string) ($config->get('assistant.name') ?: '问答助手'),
            'can_manage' => $this->isAdmin(),
            'personal' => [
                'enabled' => (bool) ($profile['enabled'] ?? false), 'endpoint' => $profile['endpoint'] ?? '',
                'model' => $profile['model'] ?? '', 'has_key' => !empty($profile['api_key_cipher']),
            ],
        ];
        if ($this->isAdmin()) {
            $result['settings'] = array_column($config->list('assistant'), 'value', 'key');
            $result['settings']['has_key'] = !empty($result['settings']['api_key']);
            unset($result['settings']['api_key'], $result['settings']['revision']);
        }
        return $result;
    }

    /** 保存指定来源；学校配置仅管理员可修改。 */
    public function save(array $payload): array
    {
        $mode = $this->mode((string) ($payload['mode'] ?? 'school'));
        if ($mode === 'school' && !$this->isAdmin()) throw new RuntimeException('仅学校管理员可以配置学校助手', 403);
        $accountId = (int) CurrentContext::accountId();
        return (new WorkflowLock())->run(WorkflowLock::key('assistant', 'config-' . $mode, $mode === 'school' ? 0 : $accountId), function () use ($payload, $mode, $accountId): array {
            $url = rtrim(trim((string) ($payload['endpoint'] ?? '')), '/');
            $model = trim((string) ($payload['model'] ?? ''));
            $key = trim((string) ($payload['api_key'] ?? ''));
            $clear = in_array($payload['clear_key'] ?? false, [true, 1, '1'], true);
            $enabled = !$clear && in_array($payload['enabled'] ?? false, [true, 1, '1'], true);
            if (strlen($url) > 500 || strlen($model) > 120 || strlen($key) > 2048 || preg_match('/[\r\n]/', $key)) throw new RuntimeException('接口配置格式无效', 400);
            if ($enabled && $url !== '') AssistantTransport::validateEndpoint($url);
            $config = new ConfigService();
            $profile = $mode === 'personal' ? AssistantProfile::forAccount($accountId) : null;
            $cipher = new SecretCipher();
            $hasNewKey = !in_array($key, ['', '******'], true);
            $hasKey = !$clear && ($hasNewKey || ($mode === 'personal' ? !empty($profile['api_key_cipher']) : (bool) $config->get('assistant.api_key')));
            if ($enabled && ($url === '' || $model === '' || !$hasKey)) throw new RuntimeException('启用前请填写接口、模型和密钥', 400);
            TableRecord::connection()->transaction(function () use ($config, $payload, $mode, $accountId, $profile, $cipher, $hasNewKey, $clear, $url, $model, $key, $enabled): void {
                if ($mode === 'personal') {
                    AssistantProfile::saveForAccount($accountId, [
                        'enabled' => $enabled, 'endpoint' => $url, 'model' => $model,
                        'api_key_cipher' => $clear ? '' : ($hasNewKey ? $cipher->encrypt($key) : ($profile['api_key_cipher'] ?? '')),
                    ]);
                    return;
                }
                foreach (['enabled' => $enabled, 'name' => mb_substr(trim((string) ($payload['name'] ?? '问答助手')), 0, 60) ?: '问答助手', 'endpoint' => $url, 'model' => $model] as $name => $value) {
                    $config->set('assistant', $name, $value);
                }
                if ($hasNewKey || $clear) $config->set('assistant', 'api_key', $clear ? '' : $key);
                $config->set('assistant', 'revision', bin2hex(random_bytes(16)));
            });
            return $this->settings();
        }, 15, true);
    }

    /** 按任务所属账号解析配置，供 HTTP 与异步队列共用。 */
    public function resolve(string $mode, int $accountId, ?string $expectedRevision = null): array
    {
        return TableRecord::connection()->transaction(fn () => $this->resolveSnapshot($mode, $accountId, $expectedRevision));
    }

    /** 在同一数据库快照中读取配置，避免混用并发修改的字段。 */
    private function resolveSnapshot(string $mode, int $accountId, ?string $expectedRevision): array
    {
        $mode = $this->mode($mode);
        if ($mode === 'personal') {
            $profile = AssistantProfile::forAccount($accountId);
            if (empty($profile['enabled'])) throw new RuntimeException('个人服务尚未启用，请配置个人 API Key', 400);
            $endpoint = (string) $profile['endpoint'];
            $model = (string) $profile['model'];
            $key = (new SecretCipher())->decrypt((string) $profile['api_key_cipher']);
            $version = (string) $profile['revision'];
        } else {
            $config = new ConfigService();
            if (!$config->get('assistant.enabled')) throw new RuntimeException('学校服务尚未启用，可配置个人服务', 400);
            $endpoint = (string) $config->get('assistant.endpoint');
            $model = (string) $config->get('assistant.model');
            $key = (string) $config->get('assistant.api_key');
            $version = (string) ($config->get('assistant.revision') ?? '');
        }
        if ($endpoint === '' || $model === '' || $key === '') throw new RuntimeException('所选服务配置不完整', 400);
        $revision = hash_hmac('sha256', json_encode([$mode, $endpoint, $model, $version], JSON_THROW_ON_ERROR), $key);
        if ($expectedRevision !== null && ($expectedRevision === '' || !hash_equals($expectedRevision, $revision))) {
            throw new RuntimeException('服务配置已变更，请重新发送问题', 409);
        }
        return ['mode' => $mode, 'revision' => $revision, 'endpoint' => $endpoint, 'model' => $model, 'api_key' => $key];
    }

    /** 限定可选择的服务来源。 */
    private function mode(string $mode): string
    {
        if (!in_array($mode, ['school', 'personal'], true)) throw new RuntimeException('请选择学校服务或个人服务', 400);
        return $mode;
    }

    /** 校验学校配置权限。 */
    private function isAdmin(): bool
    {
        return in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true);
    }
}
