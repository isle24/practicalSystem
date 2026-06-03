<?php

namespace app\controller\Api;

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

    public function login(Request $request): Response
    {
        try {
            $loginName = (string) ($request->input('login_name') ?? $request->input('username', ''));
            $password = (string) $request->input('password', '');
            $client = (string) $request->input('client', 'WEB');
            $result = (new AuthService())->login($loginName, $password, $client);

            return $this->ok($result['session'])
                ->cookie('jwt', $result['token']['access_token'], (int) $result['token']['expires_in'], '/', '', false, true, 'Lax');
        } catch (Throwable $exception) {
            return $this->fail(40100, $exception->getMessage(), 401);
        }
    }

    public function logout(Request $request): Response
    {
        return $this->ok()
            ->cookie('jwt', '', 0, '/', '', false, true, 'Lax');
    }

    public function context(Request $request): Response
    {
        return $this->ok(CurrentContext::all());
    }

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
}
