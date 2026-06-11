<?php

namespace app\middleware;

use app\server\auth\AuthService;
use app\server\CurrentContext;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AuthMiddleware implements MiddlewareInterface
{
    /**
     * 解析登录令牌并写入当前请求上下文。
     */
    public function process(Request $request, callable $handler): Response
    {
        $token = $this->token($request);
        if ($token !== '') {
            try {
                (new AuthService())->applyToken($token);
            } catch (Throwable $exception) {
                CurrentContext::set(['auth_error' => $exception->getMessage()]);
            }
        }

        return $handler($request);
    }

    /**
     * 从 Cookie 或 Authorization 头读取登录令牌。
     */
    private function token(Request $request): string
    {
        $cookieToken = $request->cookie('jwt', '');
        if (is_string($cookieToken) && $cookieToken !== '') {
            return $cookieToken;
        }

        $authorization = (string) $request->header('authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}
