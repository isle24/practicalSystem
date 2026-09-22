<?php

namespace app\server\wechat;

use app\server\config\ConfigService;
use InvalidArgumentException;
use RuntimeException;

class WechatClient
{
    private string $apiBase = 'https://qyapi.weixin.qq.com';

    /**
     * 代理配置；为空时回退到已保存的配置，便于检测接口使用表单中尚未保存的设置。
     */
    public function __construct(private ?bool $proxyEnabled = null, private ?string $proxyUrl = null)
    {
    }

    public function getToken(string $corpId, string $secret): array
    {
        return $this->request('GET', '/cgi-bin/gettoken', ['corpid' => $corpId, 'corpsecret' => $secret]);
    }

    public function getAgent(string $accessToken, string $agentId): array
    {
        return $this->request('GET', '/cgi-bin/agent/get', ['access_token' => $accessToken, 'agentid' => $agentId]);
    }

    public function createMenu(string $accessToken, string $agentId, array $buttons): array
    {
        return $this->request('POST', '/cgi-bin/menu/create', [
            'access_token' => $accessToken,
            'agentid' => $agentId,
        ], ['button' => $buttons]);
    }

    public function getUserInfo(string $accessToken, string $code): array
    {
        return $this->request('GET', '/cgi-bin/user/getuserinfo', [
            'access_token' => $accessToken,
            'code' => $code,
        ]);
    }

    public function sendTextMessage(string $accessToken, string $toUser, string $content, string $agentId): array
    {
        return $this->request('POST', '/cgi-bin/message/send', [
            'access_token' => $accessToken,
        ], [
            'touser' => $toUser,
            'msgtype' => 'text',
            'agentid' => (int) $agentId,
            'text' => ['content' => $content],
            'safe' => 0,
        ]);
    }

    public function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $config = new ConfigService();
        $proxyEnabled = $this->proxyEnabled ?? (bool) $config->get('wechat.proxy_enabled');
        $proxyUrl = $this->proxyUrl ?? (string) ($config->get('wechat.proxy_url') ?? '');
        $url = $this->apiBase . '/' . ltrim($path, '/');

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        if ($proxyEnabled && $proxyUrl !== '') {
            self::validateProxyUrl($proxyUrl);
            return $this->postJson($proxyUrl, [
                'method' => strtoupper($method),
                'path' => '/' . ltrim($path, '/'),
                'query' => $query,
                'body' => $body,
            ]);
        }

        return $this->postJson($url, $body, strtoupper($method));
    }

    private function postJson(string $url, array $payload, string $method = 'POST'): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => 15,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw new RuntimeException('企业微信请求失败');
        }
        $response = stream_get_contents($handle, 2 * 1024 * 1024 + 1);
        fclose($handle);
        if ($response === false) {
            throw new RuntimeException('企业微信请求失败');
        }
        if (strlen($response) > 2 * 1024 * 1024) {
            throw new RuntimeException('企业微信响应过大');
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : ['raw' => $response];
    }

    public static function validateProxyUrl(string $proxyUrl): void
    {
        $url = filter_var(trim($proxyUrl), FILTER_VALIDATE_URL);
        if (!$url) {
            throw new InvalidArgumentException('企业微信代理地址格式无效');
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('企业微信代理必须使用 HTTPS 域名，不能包含账号、参数或片段');
        }
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('WECHAT_PROXY_ALLOWED_HOSTS') ?: '')))));
        if (!$allowed || !in_array($host, array_map('strtolower', $allowed), true)) {
            throw new InvalidArgumentException('企业微信代理域名未加入服务端允许列表');
        }
        $addresses = gethostbynamel($host) ?: [];
        if (!$addresses) {
            throw new InvalidArgumentException('企业微信代理域名无法解析');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('企业微信代理域名解析到非公网地址');
            }
        }
    }
}
