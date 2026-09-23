<?php

namespace app\middleware;

use app\server\auth\AuthService;
use app\server\CurrentContext;
use app\server\wechat\WechatBindingService;
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
        '/api/wechat/callback',
        '/api/wechat/oauth/start',
        '/api/wechat/oauth/callback',
        '/api/release/update-manifest',
        '/api/release/download',
        '/api/release/downloads',
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

    private const BINDING_PATHS = [
        '/api/auth/switchable-accounts',
        '/api/auth/switch-account',
        '/api/permission/menus',
        '/api/permission/filter',
        '/api/wechat/binding-status',
        '/api/wechat/bind',
    ];

    /**
     * 解析登录令牌并写入当前请求上下文，未登录访问受保护接口返回 401。
     */
    public function process(Request $request, callable $handler): Response
    {
        $token = $this->token($request);
        if ($token !== '') {
            try {
                $path = '/' . trim($request->path(), '/');
                $initializeSchema = !in_array($path, ['/api/config/database-schema/options', '/api/config/database-schema/check'], true);
                (new AuthService())->applyToken($token, $initializeSchema);
            } catch (Throwable $exception) {
                CurrentContext::set(['auth_error' => $exception->getMessage()]);
            }
        }

        if ($this->requiresAuth($request) && !CurrentContext::accountId()) {
            $message = (string) (CurrentContext::get('auth_error') ?: '请先登录');
            return json(['code' => 40100, 'message' => $message, 'data' => null])->withStatus(401);
        }

        $path = '/' . trim($request->path(), '/');
        $binding = (array) CurrentContext::get('wechat_binding', []);
        if (str_starts_with($path, '/api/') && CurrentContext::accountId()
            && !in_array($path, self::PUBLIC_PATHS, true)
            && !in_array($path, self::BINDING_PATHS, true)
            && (new WechatBindingService())->isBlocked($binding)) {
            return json(['code' => 40310, 'message' => '请先完成企业微信绑定及身份确认', 'data' => null])->withStatus(403);
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
