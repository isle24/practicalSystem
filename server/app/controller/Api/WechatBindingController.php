<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\wechat\WechatBindingService;
use app\server\wechat\WechatScanSessionService;
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

    public function scanSession(Request $request): Response
    {
        return $this->scanResponse(fn () => (new WechatScanSessionService())->create());
    }

    public function scanStatus(Request $request): Response
    {
        return $this->scanResponse(fn () => (new WechatScanSessionService())->status((string) $request->get('session_id', '')));
    }

    public function scanCancel(Request $request): Response
    {
        return $this->scanResponse(fn () => (new WechatScanSessionService())->cancel((string) $request->post('session_id', '')));
    }

    public function scanStart(Request $request): Response
    {
        try {
            $result = (new WechatScanSessionService())->start((string) $request->get('ticket', ''));
            return redirect($result['url'], 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'])
                ->cookie(WechatBindingService::BROWSER_COOKIE, $result['browser'], WechatScanSessionService::TTL, '/', '', true, true, 'Lax');
        } catch (Throwable $exception) {
            return $this->failurePage($exception->getMessage());
        }
    }

    public function scanCallback(Request $request): Response
    {
        try {
            $result = (new WechatScanSessionService())->callback((string) $request->get('code', ''), (string) $request->get('state', ''));
            return redirect('/h5/?wechat_scan=1', 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'])
                ->cookie(WechatScanSessionService::COOKIE, $result['credential'], $result['expires_in'], '/', '', true, true, 'Lax');
        } catch (Throwable $exception) {
            return $this->failurePage($exception->getMessage());
        }
    }

    public function scanContext(Request $request): Response
    {
        return $this->scanResponse(fn () => (new WechatScanSessionService())->mobileContext());
    }

    public function scanConfirm(Request $request): Response
    {
        return $this->scanResponse(fn () => (new WechatScanSessionService())->confirm((string) $request->post('nonce', ''), (string) $request->post('password', '')));
    }

    private function scanResponse(callable $callback): Response
    {
        try {
            return $this->ok($callback())->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function failurePage(string $message): Response
    {
        $text = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return response('<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>企业微信绑定</title><body><p>' . $text . '</p><a href="/?wechat_oauth=failed">返回系统重新绑定</a></body></html>', 400, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
