<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\config\ConfigService;
use app\server\wechat\WechatClient;
use app\server\wechat\WechatMenuService;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use support\Response;
use Throwable;

class WechatController
{
    use Responds;

    /**
     * 查询企业微信代理配置
     */
    #[OperationLog('查询企业微信代理配置')]
    public function proxy(Request $request): Response
    {
        return $this->config($request);
    }

    /**
     * 查询企业微信配置
     */
    #[OperationLog('查询企业微信配置')]
    public function config(Request $request): Response
    {
        if (!$this->can('wechat:proxy')) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok($this->wechatConfig($request));
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /**
     * 保存企业微信代理配置
     */
    #[OperationLog('保存企业微信代理配置')]
    public function saveProxy(Request $request): Response
    {
        return $this->saveConfig($request);
    }

    /**
     * 保存企业微信配置
     */
    #[OperationLog('保存企业微信配置')]
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
                'secret' => $this->secretInput($request),
                'token' => $this->stringInput($request, 'token', 120),
                'encoding_aes_key' => $this->stringInput($request, 'encoding_aes_key', 255),
                'proxy_url' => $this->stringInput($request, 'proxy_url', 255),
                'proxy_enabled' => filter_var($request->input('proxy_enabled', false), FILTER_VALIDATE_BOOL),
                'menu_json' => json_encode($this->menuInput($request) ?: $this->defaultMenu($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if ($values['proxy_url'] !== '') {
                WechatClient::validateProxyUrl($values['proxy_url']);
            }

            $descriptions = [
                'app_id' => '企业微信应用 AppID，可用于第三方应用或自建应用标识',
                'corp_id' => '企业微信企业 ID',
                'agent_id' => '企业微信自建应用 AgentId',
                'secret' => '企业微信应用 Secret',
                'token' => '企业微信回调 Token',
                'encoding_aes_key' => '企业微信回调 EncodingAESKey',
                'proxy_url' => '服务端允许列表中的 HTTPS 代理地址，空值表示直连企业微信',
                'proxy_enabled' => '是否启用企业微信代理地址',
                'menu_json' => '企业微信应用菜单 JSON',
            ];

            foreach ($values as $key => $value) {
                $config->set('wechat', $key, $value, $descriptions[$key] ?? '');
            }

            return $this->ok($this->wechatConfig($request));
        } catch (InvalidArgumentException $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    #[OperationLog('检测企业微信配置')]
    public function checkConfig(Request $request): Response
    {
        if (!$this->can('wechat:proxy:test')) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $config = new ConfigService();
            $corpId = $this->stringInput($request, 'corp_id', 120) ?: (string) ($config->get('wechat.corp_id') ?? '');
            $agentId = $this->stringInput($request, 'agent_id', 80) ?: (string) ($config->get('wechat.agent_id') ?? '');
            $secret = $this->secretInput($request);
            if ($corpId === '' || $agentId === '' || $secret === '') {
                throw new InvalidArgumentException('CorpId、AgentId 和 Secret 不能为空');
            }
            if (!ctype_digit($agentId)) {
                throw new InvalidArgumentException('AgentId 必须为数字');
            }

            $client = new WechatClient(
                $request->input('proxy_enabled') === null ? null : filter_var($request->input('proxy_enabled'), FILTER_VALIDATE_BOOL),
                $this->stringInput($request, 'proxy_url', 255) ?: null
            );
            $token = $client->getToken($corpId, $secret);
            $this->assertWechatResponse($token, '获取访问凭证失败');
            $accessToken = (string) ($token['access_token'] ?? '');
            if ($accessToken === '') {
                throw new RuntimeException('企业微信未返回访问凭证');
            }
            $agent = $client->getAgent($accessToken, $agentId);
            $this->assertWechatResponse($agent, '读取企业微信应用失败');
            if ((string) ($agent['agentid'] ?? '') !== $agentId) {
                throw new RuntimeException('企业微信返回的 AgentId 与配置不一致');
            }

            return $this->ok([
                'connected' => true,
                'agent_id' => $agentId,
                'agent_name' => (string) ($agent['name'] ?? ''),
                'checked_at' => date('Y-m-d H:i:s'),
            ], '企业微信配置检测成功');
        } catch (InvalidArgumentException $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 429, 503], true) ? (int) $exception->getCode() : 502;
            return $this->fail($status === 503 ? 50300 : 50200, $exception->getMessage(), $status);
        }
    }

    private function wechatConfig(?Request $request = null): array
    {
        $config = new ConfigService();
        $menuJson = (string) ($config->get('wechat.menu_json') ?? '[]');
        $menu = json_decode($menuJson, true);
        if (!is_array($menu) || !$menu) {
            $menu = $request ? $this->defaultMenu($request) : [];
        }
        $secret = (string) ($config->get('wechat.secret') ?? '');

        return [
            'app_id' => $config->get('wechat.app_id') ?? '',
            'corp_id' => $config->get('wechat.corp_id') ?? '',
            'agent_id' => $config->get('wechat.agent_id') ?? '',
            'secret' => $secret === '' ? '' : '******',
            'secret_configured' => $secret !== '',
            'token' => $config->get('wechat.token') ?? '',
            'encoding_aes_key' => $config->get('wechat.encoding_aes_key') ?? '',
            'proxy_url' => $config->get('wechat.proxy_url') ?? '',
            'proxy_enabled' => (bool) $config->get('wechat.proxy_enabled'),
            'menu' => is_array($menu) ? $menu : [],
        ];
    }

    private function secretInput(Request $request): string
    {
        $value = $this->stringInput($request, 'secret', 255);
        if ($value === '******' || $value === '') {
            return (string) ((new ConfigService())->get('wechat.secret') ?? '');
        }
        return $value;
    }

    private function defaultMenu(Request $request): array
    {
        return [['name' => '实践系统', 'type' => 'view', 'url' => rtrim($this->origin($request), '/') . '/']];
    }

    private function origin(Request $request): string
    {
        $host = trim((string) $request->host());
        $forwarded = strtolower(trim((string) $request->header('x-forwarded-proto', '')));
        $scheme = in_array($forwarded, ['http', 'https'], true) ? $forwarded : 'http';
        if ($scheme !== 'https' && !preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)) {
            $scheme = 'https';
        }
        return $scheme . '://' . $host;
    }

    private function assertWechatResponse(array $response, string $message): void
    {
        if ((int) ($response['errcode'] ?? -1) !== 0) {
            $detail = trim((string) ($response['errmsg'] ?? ''));
            throw new RuntimeException($message . ($detail !== '' ? '：' . $detail : ''));
        }
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
        return (new WechatMenuService())->normalize($request->input('menu', []));
    }

    private function can(string $permission): bool
    {
        return in_array($permission, CurrentContext::permissionCodes(), true);
    }
}
