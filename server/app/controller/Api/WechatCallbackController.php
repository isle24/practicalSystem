<?php

namespace app\controller\Api;

use app\server\CurrentContext;
use app\server\wechat\WechatCallbackService;
use InvalidArgumentException;
use RuntimeException;
use support\Log;
use support\Request;
use support\Response;
use Throwable;
use Webman\Event\Event;

class WechatCallbackController
{
    public function callback(Request $request): Response
    {
        if (!in_array($request->method(), ['GET', 'POST'], true)) {
            return $this->plain('Method Not Allowed', 405)->withHeader('Allow', 'GET, POST');
        }

        try {
            $signature = $this->query($request, 'msg_signature', 40);
            $timestamp = $this->query($request, 'timestamp', 20);
            $nonce = $this->query($request, 'nonce', 256);
            $service = new WechatCallbackService();

            if ($request->method() === 'GET') {
                $echo = $this->query($request, 'echostr', WechatCallbackService::MAX_BODY_BYTES);
                return $this->plain($service->verifyUrl($signature, $timestamp, $nonce, $echo));
            }

            $xml = $service->decryptMessage($signature, $timestamp, $nonce, $request->rawBody());
            try {
                Event::dispatch('wechat.callback.received', [
                    'school_database_id' => CurrentContext::schoolDatabaseId(),
                    'xml' => $xml,
                ]);
            } catch (Throwable $exception) {
                throw new RuntimeException('企业微信回调事件派发失败', 500, $exception);
            }

            return $this->plain('');
        } catch (InvalidArgumentException) {
            return $this->plain('Invalid callback request', 400);
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode();
            if (!in_array($status, [403, 413, 503], true)) {
                Log::error('企业微信回调处理异常', ['exception_type' => $exception::class]);
                $status = 500;
            }
            return $this->plain(match ($status) {
                403 => 'Invalid callback signature or payload',
                413 => 'Callback payload too large',
                503 => 'Wechat callback configuration unavailable',
                default => 'Callback unavailable',
            }, $status);
        }
    }

    private function query(Request $request, string $name, int $maxLength): string
    {
        $value = $request->get($name);
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('企业微信回调参数无效');
        }
        return $value;
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
