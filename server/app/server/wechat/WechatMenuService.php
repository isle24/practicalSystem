<?php

namespace app\server\wechat;

use app\server\config\ConfigService;
use InvalidArgumentException;
use RuntimeException;

class WechatMenuService
{
    public function normalize(mixed $menu, bool $allowChildren = true): array
    {
        if (!is_array($menu)) {
            throw new InvalidArgumentException('企业微信菜单格式无效');
        }
        if (count($menu) > ($allowChildren ? 3 : 5)) {
            throw new InvalidArgumentException($allowChildren ? '企业微信一级菜单最多 3 个' : '企业微信子菜单最多 5 个');
        }

        return array_map(function (mixed $item) use ($allowChildren): array {
            if (!is_array($item)) {
                throw new InvalidArgumentException('企业微信菜单格式无效');
            }
            $name = $this->requiredString($item, 'name', '企业微信菜单名称', $allowChildren ? 16 : 40);
            $children = $item['children'] ?? [];
            if (!is_array($children)) {
                throw new InvalidArgumentException('企业微信子菜单格式无效');
            }
            if ($children) {
                if (!$allowChildren) {
                    throw new InvalidArgumentException('企业微信菜单只支持两级');
                }
                return ['name' => $name, 'children' => $this->normalize($children, false)];
            }

            $type = $item['type'] ?? 'view';
            $payload = ['name' => $name, 'type' => $type];
            if ($type === 'click') {
                $payload['key'] = $this->requiredString($item, 'key', '点击菜单 Key', 128);
            } elseif ($type === 'view') {
                $payload['url'] = $this->requiredString($item, 'url', '跳转菜单 URL', 1024);
                if (!filter_var($payload['url'], FILTER_VALIDATE_URL)
                    || !in_array(strtolower((string) parse_url($payload['url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('跳转菜单 URL 必须为有效的 HTTP 或 HTTPS 地址');
                }
            } elseif ($type === 'miniprogram') {
                $payload['appid'] = $this->requiredString($item, 'appid', '小程序菜单 AppID');
                $payload['pagepath'] = $this->requiredString($item, 'pagepath', '小程序菜单路径');
            } else {
                throw new InvalidArgumentException('企业微信菜单类型无效');
            }

            return $payload;
        }, array_values($menu));
    }

    public function syncSaved(): array
    {
        $config = new ConfigService();
        $corpId = trim((string) ($config->get('wechat.corp_id') ?? ''));
        $agentId = trim((string) ($config->get('wechat.agent_id') ?? ''));
        $secret = trim((string) ($config->get('wechat.secret') ?? ''));
        if ($corpId === '' || $agentId === '' || $secret === '') {
            throw new InvalidArgumentException('请先保存 CorpId、AgentId 和应用 Secret');
        }
        if (!ctype_digit($agentId)) {
            throw new InvalidArgumentException('AgentId 必须为数字');
        }
        $menu = $this->normalize(json_decode((string) ($config->get('wechat.menu_json') ?? '[]'), true));
        if (!$menu) {
            throw new InvalidArgumentException('请至少配置并保存一个一级菜单');
        }

        $client = new WechatClient();
        $token = $client->getToken($corpId, $secret);
        $this->assertResponse($token, '获取访问凭证失败');
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('企业微信未返回访问凭证');
        }
        $result = $client->createMenu($accessToken, $agentId, $this->buttons($menu));
        $this->assertResponse($result, '同步企业微信菜单失败');

        return [
            'agent_id' => $agentId,
            'menu_count' => count($menu),
            'synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function buttons(array $menu): array
    {
        return array_map(function (array $item): array {
            if (!empty($item['children'])) {
                return ['name' => $item['name'], 'sub_button' => $this->buttons($item['children'])];
            }
            if ($item['type'] === 'miniprogram') {
                $item['type'] = 'view_miniprogram';
            }
            return $item;
        }, $menu);
    }

    private function requiredString(array $item, string $field, string $label, ?int $maxBytes = null): string
    {
        $value = $item[$field] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        $value = trim($value);
        if ($maxBytes !== null && strlen($value) > $maxBytes) {
            throw new InvalidArgumentException($label . '不能超过 ' . $maxBytes . ' 字节');
        }
        return $value;
    }

    private function assertResponse(array $response, string $message): void
    {
        if ((int) ($response['errcode'] ?? -1) !== 0) {
            $detail = trim((string) ($response['errmsg'] ?? ''));
            throw new RuntimeException($message . ($detail !== '' ? '：' . $detail : ''));
        }
    }
}
