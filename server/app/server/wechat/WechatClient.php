<?php

namespace app\server\wechat;

use app\server\config\ConfigService;
use RuntimeException;

class WechatClient
{
    private string $apiBase = 'https://qyapi.weixin.qq.com';

    public function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $proxyEnabled = (bool) (new ConfigService())->get('wechat.proxy_enabled');
        $proxyUrl = (string) ((new ConfigService())->get('wechat.proxy_url') ?? '');
        $url = $this->apiBase . '/' . ltrim($path, '/');

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        if ($proxyEnabled && $proxyUrl !== '') {
            return $this->postJson($proxyUrl, [
                'method' => strtoupper($method),
                'url' => $url,
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
            ],
        ]);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('企业微信请求失败');
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : ['raw' => $response];
    }
}
