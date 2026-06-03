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
        try {
            $config = new ConfigService();
            return $this->ok([
                'proxy_url' => $config->get('wechat.proxy_url') ?? '',
                'proxy_enabled' => (bool) $config->get('wechat.proxy_enabled'),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function saveProxy(Request $request): Response
    {
        try {
            $proxyUrl = (string) $request->input('proxy_url', '');
            $enabled = filter_var($request->input('proxy_enabled', $proxyUrl !== ''), FILTER_VALIDATE_BOOL);
            $config = new ConfigService();
            $config->set('wechat', 'proxy_url', $proxyUrl, '本地转发企业微信 API 的代理地址，空值表示直连企业微信');
            $config->set('wechat', 'proxy_enabled', $enabled, '是否启用企业微信代理地址');

            return $this->ok([
                'proxy_url' => $proxyUrl,
                'proxy_enabled' => $enabled,
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }
}
