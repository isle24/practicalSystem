<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\config\ConfigService;
use support\Request;
use support\Response;
use Throwable;

class WechatController
{
    use Responds;

    public function proxy(Request $request): Response
    {
        return $this->config($request);
    }

    public function config(Request $request): Response
    {
        try {
            return $this->ok($this->wechatConfig());
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function saveProxy(Request $request): Response
    {
        return $this->saveConfig($request);
    }

    public function saveConfig(Request $request): Response
    {
        try {
            $config = new ConfigService();
            $values = [
                'app_id' => $this->stringInput($request, 'app_id', 120),
                'corp_id' => $this->stringInput($request, 'corp_id', 120),
                'agent_id' => $this->stringInput($request, 'agent_id', 80),
                'secret' => $this->stringInput($request, 'secret', 255),
                'token' => $this->stringInput($request, 'token', 120),
                'encoding_aes_key' => $this->stringInput($request, 'encoding_aes_key', 255),
                'proxy_url' => $this->stringInput($request, 'proxy_url', 255),
                'proxy_enabled' => filter_var($request->input('proxy_enabled', false), FILTER_VALIDATE_BOOL),
                'menu_json' => json_encode($this->menuInput($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $descriptions = [
                'app_id' => '企业微信应用 AppID，可用于第三方应用或自建应用标识',
                'corp_id' => '企业微信企业 ID',
                'agent_id' => '企业微信自建应用 AgentId',
                'secret' => '企业微信应用 Secret',
                'token' => '企业微信回调 Token',
                'encoding_aes_key' => '企业微信回调 EncodingAESKey',
                'proxy_url' => '本地转发企业微信 API 的代理地址，空值表示直连企业微信',
                'proxy_enabled' => '是否启用企业微信代理地址',
                'menu_json' => '企业微信应用菜单 JSON',
            ];

            foreach ($values as $key => $value) {
                $config->set('wechat', $key, $value, $descriptions[$key] ?? '');
            }

            return $this->ok($this->wechatConfig());
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    private function wechatConfig(): array
    {
        $config = new ConfigService();
        $menuJson = (string) ($config->get('wechat.menu_json') ?? '[]');
        $menu = json_decode($menuJson, true);

        return [
            'app_id' => $config->get('wechat.app_id') ?? '',
            'corp_id' => $config->get('wechat.corp_id') ?? '',
            'agent_id' => $config->get('wechat.agent_id') ?? '',
            'secret' => $config->get('wechat.secret') ?? '',
            'token' => $config->get('wechat.token') ?? '',
            'encoding_aes_key' => $config->get('wechat.encoding_aes_key') ?? '',
            'proxy_url' => $config->get('wechat.proxy_url') ?? '',
            'proxy_enabled' => (bool) $config->get('wechat.proxy_enabled'),
            'menu' => is_array($menu) ? $menu : [],
        ];
    }

    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        $value = trim((string) $request->input($key, ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function menuInput(Request $request): array
    {
        $menu = $request->input('menu', []);
        return is_array($menu) ? array_values($menu) : [];
    }
}
