<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\config\ConfigService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class WechatController
{
    use Responds;

    private const MENU_TYPES = ['click', 'view', 'miniprogram'];

    public function proxy(Request $request): Response
    {
        return $this->config($request);
    }

    public function config(Request $request): Response
    {
        if (!$this->can('wechat:proxy')) {
            return $this->fail(40300, '无操作权限', 403);
        }

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
        if (!$this->can('wechat:proxy:save')) {
            return $this->fail(40300, '无操作权限', 403);
        }

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
        } catch (InvalidArgumentException $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
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
        if (!is_array($menu)) {
            return [];
        }
        $items = array_values($menu);
        if (count($items) > 3) {
            throw new InvalidArgumentException('企业微信一级菜单最多 3 个');
        }

        return array_map(fn (mixed $item): array => $this->menuItemInput($item, true), $items);
    }

    private function menuItemInput(mixed $item, bool $allowChildren): array
    {
        if (!is_array($item)) {
            throw new InvalidArgumentException('企业微信菜单格式无效');
        }

        $name = $this->limitString($item['name'] ?? '', 32);
        if ($name === '') {
            throw new InvalidArgumentException('企业微信菜单名称不能为空');
        }

        $children = isset($item['children']) && is_array($item['children']) ? array_values($item['children']) : [];
        if ($children) {
            if (!$allowChildren) {
                throw new InvalidArgumentException('企业微信菜单只支持两级');
            }
            if (count($children) > 5) {
                throw new InvalidArgumentException('企业微信子菜单最多 5 个');
            }

            return [
                'name' => $name,
                'children' => array_map(fn (mixed $child): array => $this->menuItemInput($child, false), $children),
            ];
        }

        $type = $this->limitString($item['type'] ?? 'view', 40);
        if (!in_array($type, self::MENU_TYPES, true)) {
            throw new InvalidArgumentException('企业微信菜单类型无效');
        }

        $payload = [
            'name' => $name,
            'type' => $type,
        ];
        foreach (['key', 'url', 'appid', 'pagepath'] as $field) {
            $value = $this->limitString($item[$field] ?? '', 255);
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        if ($type === 'click' && empty($payload['key'])) {
            throw new InvalidArgumentException('点击菜单 Key 不能为空');
        }
        if ($type === 'view' && empty($payload['url'])) {
            throw new InvalidArgumentException('跳转菜单 URL 不能为空');
        }
        if ($type === 'miniprogram' && (empty($payload['appid']) || empty($payload['pagepath']))) {
            throw new InvalidArgumentException('小程序菜单 AppID 和路径不能为空');
        }

        return $payload;
    }

    private function limitString(mixed $value, int $maxLength): string
    {
        $value = trim((string) $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function can(string $permission): bool
    {
        return in_array($permission, CurrentContext::permissionCodes(), true);
    }
}
