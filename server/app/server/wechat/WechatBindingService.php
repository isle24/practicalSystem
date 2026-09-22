<?php

namespace app\server\wechat;

use app\model\channel\UserWechat;
use app\server\CurrentContext;
use app\server\config\ConfigService;
use RuntimeException;
use support\Redis;

class WechatBindingService
{
    public const BROWSER_COOKIE = 'wechat_browser';
    public const IDENTITY_COOKIE = 'wechat_identity';
    public const TTL = 600;
    public const IDENTITY_TTL = 3600;

    public function status(): array
    {
        $required = in_array(CurrentContext::roleType(), ['teacher', 'student'], true);
        $binding = UserWechat::currentByUser((int) CurrentContext::userId());
        $identity = $required ? $this->identity() : null;
        $config = new ConfigService();
        return [
            'bound' => $binding !== null,
            'required' => $required,
            'enforced' => $required && $this->inWechat(),
            'identity_ready' => $identity !== null,
            'identity_matches' => $identity && $binding && hash_equals($binding['wechat_userid'], $identity['wechat_userid']) && $binding['corp_id'] === $identity['corp'],
            'wechat_name' => (string) ($binding['wechat_name'] ?? ''),
            'configured' => (string) $config->get('wechat.corp_id') !== '' && (string) $config->get('wechat.secret') !== '',
        ];
    }

    public function begin(string $returnPath): array
    {
        if (!$this->inWechat()) throw new RuntimeException('请在企业微信中打开系统后绑定');
        $config = new ConfigService();
        $corpId = trim((string) $config->get('wechat.corp_id'));
        if ($corpId === '' || (string) $config->get('wechat.secret') === '') throw new RuntimeException('请管理员先配置企业微信 CorpId 和 Secret');
        $state = bin2hex(random_bytes(24));
        $browser = (string) request()->cookie(self::BROWSER_COOKIE, '');
        if (!preg_match('/^[a-f0-9]{64}$/', $browser)) $browser = bin2hex(random_bytes(32));
        Redis::setEx($this->key('state', $state), self::TTL, json_encode([
            'school' => CurrentContext::schoolDatabaseId(),
            'account' => CurrentContext::accountId(),
            'browser' => hash('sha256', $browser),
            'corp' => $corpId,
            'return_path' => $this->safePath($returnPath),
        ]));
        return ['browser' => $browser, 'url' => 'https://open.weixin.qq.com/connect/oauth2/authorize?' . http_build_query([
            'appid' => $corpId,
            'redirect_uri' => $this->origin() . '/api/wechat/oauth/callback',
            'response_type' => 'code', 'scope' => 'snsapi_base', 'state' => $state,
        ]) . '#wechat_redirect'];
    }

    public function finish(string $code, string $state): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $state) || $code === '' || strlen($code) > 1024) throw new RuntimeException('企业微信授权参数无效');
        $stateData = $this->consume($this->key('state', $state));
        $browser = (string) request()->cookie(self::BROWSER_COOKIE, '');
        if (!$stateData || (int) ($stateData['school'] ?? 0) !== CurrentContext::schoolDatabaseId()
            || $browser === '' || !hash_equals($stateData['browser'], hash('sha256', $browser))) {
            throw new RuntimeException('企业微信授权已失效，请重新获取身份');
        }
        $config = new ConfigService();
        $corpId = trim((string) $config->get('wechat.corp_id'));
        if ($corpId !== $stateData['corp']) throw new RuntimeException('企业微信配置已变更，请重新获取身份');
        $client = new WechatClient();
        $token = $client->getToken($corpId, (string) $config->get('wechat.secret'));
        $this->assertResponse($token, '获取企业微信访问凭证失败');
        if (empty($token['access_token'])) throw new RuntimeException('企业微信未返回访问凭证');
        $info = $client->getUserInfo($token['access_token'], $code);
        $this->assertResponse($info, '获取企业微信身份失败');
        $userId = trim((string) ($info['UserId'] ?? $info['userid'] ?? ''));
        if ($userId === '' || strlen($userId) > 120) throw new RuntimeException('未获取到企业成员 UserId，请确认已加入应用可见范围');
        $ticket = bin2hex(random_bytes(32));
        $identity = [
            'school' => CurrentContext::schoolDatabaseId(), 'corp' => $corpId,
            'account' => (int) ($stateData['account'] ?? 0), 'browser' => $stateData['browser'],
            'wechat_userid' => $userId, 'created_at' => time(),
        ];
        Redis::setEx($this->key('identity', $ticket), self::IDENTITY_TTL, json_encode($identity));
        Redis::setEx($this->key('bind', $ticket), self::TTL, json_encode($identity));
        return ['ticket' => $ticket, 'return_path' => $this->safePath($stateData['return_path'])];
    }

    public function bind(): array
    {
        if (!in_array(CurrentContext::roleType(), ['teacher', 'student'], true)) throw new RuntimeException('当前角色无需绑定企业微信');
        $identity = $this->identity();
        if (!$identity) throw new RuntimeException('企业微信身份已失效，请重新获取身份');
        $key = $this->key('bind', (string) request()->cookie(self::IDENTITY_COOKIE, ''));
        $ticket = json_decode((string) Redis::get($key), true);
        if (!$ticket) throw new RuntimeException('绑定凭证已使用或过期，请重新获取身份');
        if ($ticket['account'] > 0 && $ticket['account'] !== CurrentContext::accountId()) throw new RuntimeException('登录账号已改变，请重新获取企业微信身份');
        if (!$this->consume($key)) throw new RuntimeException('绑定凭证已使用，请重新获取身份');
        UserWechat::bindUser((int) CurrentContext::userId(), $identity['wechat_userid'], $identity['corp']);
        return $this->status();
    }

    private function identity(): ?array
    {
        $ticket = (string) request()?->cookie(self::IDENTITY_COOKIE, '');
        $browser = (string) request()?->cookie(self::BROWSER_COOKIE, '');
        if (!preg_match('/^[a-f0-9]{64}$/', $ticket) || $browser === '') return null;
        $data = json_decode((string) Redis::get($this->key('identity', $ticket)), true);
        if (!is_array($data) || (int) ($data['school'] ?? 0) !== CurrentContext::schoolDatabaseId()
            || !hash_equals($data['browser'], hash('sha256', $browser))
            || $data['corp'] !== (string) (new ConfigService())->get('wechat.corp_id')) return null;
        return $data;
    }

    public function inWechat(): bool
    {
        return str_contains(strtolower((string) request()?->header('user-agent', '')), 'wxwork');
    }

    public function origin(): string
    {
        $host = (string) request()->host();
        if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/', $host)) throw new RuntimeException('访问域名无效');
        return (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) ? 'http://' : 'https://') . $host;
    }

    public function safePath(string $path): string
    {
        if (strlen($path) > 2000 || preg_match('/[\x00-\x20\\\\]/', $path)) return '/';
        $parts = parse_url($path);
        if (!$parts || isset($parts['host']) || isset($parts['scheme']) || !in_array($parts['path'] ?? '/', ['/', '/pc/', '/h5/', '/pc/index.html', '/h5/index.html'], true)) return '/';
        parse_str($parts['query'] ?? '', $query);
        foreach (['code', 'state', 'wechat_bind_ticket', 'wechat_bind_error', 'wechat_oauth', 'passkey', 'key'] as $key) unset($query[$key]);
        return ($parts['path'] ?? '/') . ($query ? '?' . http_build_query($query) : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    private function consume(string $key): ?array
    {
        $raw = Redis::eval("local v=redis.call('GET',KEYS[1]); if v then redis.call('DEL',KEYS[1]) end; return v", 1, $key);
        $value = $raw ? json_decode((string) $raw, true) : null;
        return is_array($value) ? $value : null;
    }

    private function key(string $kind, string $value): string { return 'wechat:' . $kind . ':' . CurrentContext::schoolDatabaseId() . ':' . hash('sha256', $value); }

    private function assertResponse(array $response, string $message): void
    {
        if ((int) ($response['errcode'] ?? -1) !== 0) throw new RuntimeException($message . '（错误码 ' . (int) ($response['errcode'] ?? -1) . '）');
    }
}
