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
     * 免登录接口白名单（完整路径）。
     */
    private const PUBLIC_PATHS = [
        '/api/release/update-manifest',
        '/api/release/download',
        '/api/auth/login',
        '/api/auth/register',
        '/api/auth/register-options',
        '/api/auth/passkey-login',
        '/api/auth/refresh',
        '/api/auth/logout',
        '/api/auth/context',
        '/api/config/login-page',
        '/api/open-teacher-sync/push',
        '/api/open-social-practice-sync/push',
        '/api/open-enterprise-evaluation/send-code',
        '/api/open-enterprise-evaluation/verify-code',
        '/api/open-enterprise-evaluation/context',
        '/api/open-enterprise-evaluation/submit',
    ];

    /**
     * 解析登录令牌并写入当前请求上下文，未登录访问受保护接口返回 401。
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

        if ($this->requiresAuth($request) && !CurrentContext::accountId()) {
            $message = (string) (CurrentContext::get('auth_error') ?: '请先登录');
            return json(['code' => 40100, 'message' => $message, 'data' => null])->withStatus(401);
        }

        return $handler($request);
    }

    /**
     * 判断当前请求是否需要登录态。
     */
    private function requiresAuth(Request $request): bool
    {
        $path = '/' . trim($request->path(), '/');
        if (!str_starts_with($path, '/api/')) {
            return false;
        }

        return !in_array($path, self::PUBLIC_PATHS, true);
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
