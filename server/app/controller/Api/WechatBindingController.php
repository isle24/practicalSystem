<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\wechat\WechatBindingService;
use support\Request;
use support\Response;
use Throwable;

class WechatBindingController
{
    use Responds;

    public function status(Request $request): Response
    {
        try {
            return $this->ok((new WechatBindingService())->status())->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function start(Request $request): Response
    {
        try {
            $service = new WechatBindingService();
            $result = $service->begin((string) $request->input('return_path', '/'));
            return redirect($result['url'], 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'])
                ->cookie(WechatBindingService::BROWSER_COOKIE, $result['browser'], WechatBindingService::IDENTITY_TTL, '/', '', str_starts_with($service->origin(), 'https:'), true, 'Lax');
        } catch (Throwable $exception) {
            return $this->failurePage($exception->getMessage());
        }
    }

    public function callback(Request $request): Response
    {
        try {
            $service = new WechatBindingService();
            $result = $service->finish((string) $request->input('code', ''), (string) $request->input('state', ''));
            $parts = explode('#', $result['return_path'], 2);
            $path = $parts[0] . (str_contains($parts[0], '?') ? '&' : '?') . 'wechat_oauth=ready' . (isset($parts[1]) ? '#' . $parts[1] : '');
            return redirect($path, 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'])
                ->cookie(WechatBindingService::IDENTITY_COOKIE, $result['ticket'], WechatBindingService::IDENTITY_TTL, '/', '', str_starts_with($service->origin(), 'https:'), true, 'Lax');
        } catch (Throwable $exception) {
            return $this->failurePage($exception->getMessage());
        }
    }

    #[OperationLog('绑定企业微信')]
    public function bind(Request $request): Response
    {
        try {
            if (!str_contains(strtolower((string) $request->header('content-type', '')), 'application/json')) {
                return $this->fail(40001, '请通过系统绑定入口操作', 400);
            }
            return $this->ok((new WechatBindingService())->bind(), '企业微信绑定成功');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function failurePage(string $message): Response
    {
        $text = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return response('<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>企业微信绑定</title><body><p>' . $text . '</p><a href="/?wechat_oauth=failed">返回系统重新绑定</a></body></html>', 400, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
