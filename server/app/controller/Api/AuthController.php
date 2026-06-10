<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\auth\AuthService;
use app\server\CurrentContext;
use app\server\rbac\RbacService;
use support\Request;
use support\Response;
use Throwable;

class AuthController
{
    use Responds;

    #[OperationLog('登录')]
    public function login(Request $request): Response
    {
        try {
            $loginName = (string) ($request->input('login_name') ?? $request->input('username', ''));
            $password = (string) $request->input('password', '');
            $client = (string) $request->input('client', 'WEB');
            $service = new AuthService();
            $result = $service->login($loginName, $password, $client);

            return $this->sessionResponse($service, $result);
        } catch (Throwable $exception) {
            return $this->fail(40100, $exception->getMessage(), 401);
        }
    }

    #[OperationLog('刷新登录状态')]
    public function refresh(Request $request): Response
    {
        try {
            $service = new AuthService();
            $cookies = $service->cookieNames();
            $result = $service->refresh((string) $request->cookie($cookies['refresh'], ''));

            return $this->sessionResponse($service, $result);
        } catch (Throwable $exception) {
            $cookies = (new AuthService())->cookieNames();
            return $this->fail(40100, $exception->getMessage(), 401)
                ->cookie($cookies['access'], '', 0, '/', '', false, true, 'Lax')
                ->cookie($cookies['refresh'], '', 0, '/', '', false, true, 'Lax');
        }
    }

    #[OperationLog('退出登录')]
    public function logout(Request $request): Response
    {
        $cookies = (new AuthService())->cookieNames();
        return $this->ok()
            ->cookie($cookies['access'], '', 0, '/', '', false, true, 'Lax')
            ->cookie($cookies['refresh'], '', 0, '/', '', false, true, 'Lax');
    }

    #[OperationLog('查询可切换账号')]
    public function switchableAccounts(Request $request): Response
    {
        try {
            return $this->ok((new AuthService())->switchableAccounts());
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    #[OperationLog('切换登录身份')]
    public function switchAccount(Request $request): Response
    {
        try {
            $service = new AuthService();
            $result = $service->switchAccount($this->intInput($request, 'account_id') ?: 0, (string) $request->input('client', 'WEB'));

            return $this->sessionResponse($service, $result);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    #[OperationLog('一键登录')]
    public function passkeyLogin(Request $request): Response
    {
        try {
            $service = new AuthService();
            $passkey = (string) ($request->input('passkey') ?? $request->input('key', ''));
            $result = $service->loginByPasskey($passkey, (string) $request->input('client', 'WEB'));

            return $this->sessionResponse($service, $result);
        } catch (Throwable $exception) {
            return $this->fail(40100, $exception->getMessage(), 401);
        }
    }

    #[OperationLog('获取登录上下文')]
    public function context(Request $request): Response
    {
        if (!CurrentContext::accountId()) {
            $service = new AuthService();
            $cookies = $service->cookieNames();
            $refreshToken = (string) $request->cookie($cookies['refresh'], '');
            if ($refreshToken !== '') {
                try {
                    $result = $service->refresh($refreshToken);
                    return $this->ok($result['session']['context'])
                        ->cookie($cookies['access'], $result['token']['access_token'], (int) $result['token']['expires_in'], '/', '', false, true, 'Lax')
                        ->cookie($cookies['refresh'], (string) ($result['token']['refresh_token'] ?? ''), $service->refreshExpiresIn(), '/', '', false, true, 'Lax');
                } catch (Throwable) {
                }
            }
        }

        return $this->ok(CurrentContext::all());
    }

    #[OperationLog('查询当前账号角色')]
    public function roles(Request $request): Response
    {
        try {
            $accountId = CurrentContext::accountId();
            if (!$accountId) {
                return $this->fail(40100, '请先登录', 401);
            }

            $requestAccountId = $this->intInput($request, 'account_id');
            if ($requestAccountId && in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true)) {
                $accountId = $requestAccountId;
            }

            return $this->ok([
                'roles' => (new RbacService())->roles($accountId),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    private function intInput(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private function sessionResponse(AuthService $service, array $result): Response
    {
        $cookies = $service->cookieNames();

        return $this->ok($result['session'])
            ->cookie($cookies['access'], $result['token']['access_token'], (int) $result['token']['expires_in'], '/', '', false, true, 'Lax')
            ->cookie($cookies['refresh'], (string) ($result['token']['refresh_token'] ?? ''), $service->refreshExpiresIn(), '/', '', false, true, 'Lax');
    }
}
