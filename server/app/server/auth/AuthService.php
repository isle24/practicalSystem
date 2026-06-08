<?php

namespace app\server\auth;

use app\model\channel\Account;
use app\model\channel\User;
use app\server\CurrentContext;
use app\server\rbac\DataScopeService;
use app\server\rbac\RbacService;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;
use Tinywan\Jwt\JwtToken;
use UnexpectedValueException;

class AuthService
{
    private const ACCESS_COOKIE = 'jwt';
    private const REFRESH_COOKIE = 'refresh_jwt';

    public function login(string $loginName, string $password, string $client = JwtToken::TOKEN_CLIENT_WEB): array
    {
        $loginName = trim($loginName);
        if ($loginName === '' || $password === '') {
            throw new RuntimeException('账号或密码不能为空');
        }

        $account = Account::enabledByLoginName($loginName);

        if (!$account || !password_verify($password, (string) $account->password)) {
            throw new RuntimeException('账号或密码错误');
        }

        $context = $this->contextForAccount((int) $account->id, $this->client($client));
        $token = JwtToken::generateToken($this->tokenPayload($context, $this->client($client)));

        return [
            'token' => $token,
            'session' => $this->session($context, (int) $token['expires_in']),
        ];
    }

    public function refresh(string $refreshToken): array
    {
        $extend = $this->verifyRefreshToken($refreshToken);
        $accountId = (int) ($extend['account_id'] ?? $extend['id'] ?? 0);
        if ($accountId <= 0) {
            throw new RuntimeException('刷新凭证缺少账号信息');
        }

        $schoolDatabaseId = (int) ($extend['school_database_id'] ?? 0);
        if ($schoolDatabaseId > 0 && CurrentContext::schoolDatabaseId() && $schoolDatabaseId !== CurrentContext::schoolDatabaseId()) {
            throw new RuntimeException('登录学校与当前学校不一致');
        }

        $client = (string) ($extend['client'] ?? JwtToken::TOKEN_CLIENT_WEB);
        $context = $this->contextForAccount($accountId, $client);
        $token = JwtToken::generateToken($this->tokenPayload($context, $this->client($client)));

        return [
            'token' => $token,
            'session' => $this->session($context, (int) $token['expires_in']),
        ];
    }

    public function cookieNames(): array
    {
        return [
            'access' => self::ACCESS_COOKIE,
            'refresh' => self::REFRESH_COOKIE,
        ];
    }

    public function refreshExpiresIn(): int
    {
        $config = (array) config('plugin.tinywan.jwt.app.jwt', []);
        return max(86400, (int) ($config['refresh_exp'] ?? 604800));
    }

    public function applyToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $payload = JwtToken::verify(token: $token);
        $extend = (array) ($payload['extend'] ?? []);
        $accountId = (int) ($extend['account_id'] ?? $extend['id'] ?? 0);
        if ($accountId <= 0) {
            throw new RuntimeException('登录凭证缺少账号信息');
        }

        $schoolDatabaseId = (int) ($extend['school_database_id'] ?? 0);
        if ($schoolDatabaseId > 0 && CurrentContext::schoolDatabaseId() && $schoolDatabaseId !== CurrentContext::schoolDatabaseId()) {
            throw new RuntimeException('登录学校与当前学校不一致');
        }

        return $this->contextForAccount($accountId, (string) ($extend['client'] ?? JwtToken::TOKEN_CLIENT_WEB));
    }

    public function contextForAccount(int $accountId, string $client = JwtToken::TOKEN_CLIENT_WEB): array
    {
        $account = Account::enabledById($accountId);

        if (!$account) {
            throw new RuntimeException('登录账号不存在或已禁用');
        }

        $user = User::enabledById((int) $account->user_id);

        if (!$user) {
            throw new RuntimeException('用户不存在或已禁用');
        }

        $rbac = new RbacService();
        $roles = $rbac->roles((int) $account->id);
        $role = $roles[0] ?? null;
        if (!$role) {
            throw new RuntimeException('账号未分配可用角色');
        }

        $roleId = (int) $role['id'];
        $context = [
            'school_database_id' => CurrentContext::schoolDatabaseId(),
            'school_database' => CurrentContext::schoolDatabase(),
            'school_connection' => CurrentContext::schoolConnection(),
            'school_id' => CurrentContext::get('school_id'),
            'school_code' => CurrentContext::schoolCode(),
            'school_name' => CurrentContext::get('school_name'),
            'user_id' => (int) $user->id,
            'user_name' => (string) $user->name,
            'account_id' => (int) $account->id,
            'login_name' => (string) $account->login_name,
            'role_id' => $roleId,
            'role_type' => (string) $role['role_type'],
            'role_name' => (string) $role['name'],
            'client' => $this->client($client),
            'permissions' => $rbac->permissionCodes($roleId),
        ];

        CurrentContext::set($context);

        $dataScope = (new DataScopeService($rbac))->filter('default', (int) $account->id, $roleId, (string) $role['role_type']);
        $context['organization_scopes'] = CurrentContext::organizationScopes();
        $context['data_scope'] = $dataScope;

        CurrentContext::set($context);

        return $context;
    }

    private function session(array $context, int $expiresIn): array
    {
        return [
            'expires_in' => $expiresIn,
            'user' => [
                'id' => $context['user_id'],
                'name' => $context['user_name'],
                'login_name' => $context['login_name'],
            ],
            'role' => [
                'id' => $context['role_id'],
                'name' => $context['role_name'],
                'role_type' => $context['role_type'],
            ],
            'context' => $context,
        ];
    }

    private function tokenPayload(array $context, string $client): array
    {
        return [
            'id' => $context['account_id'],
            'account_id' => $context['account_id'],
            'user_id' => $context['user_id'],
            'login_name' => $context['login_name'],
            'role_id' => $context['role_id'],
            'role_type' => $context['role_type'],
            'school_database_id' => $context['school_database_id'],
            'school_database' => $context['school_database'],
            'school_connection' => $context['school_connection'],
            'school_id' => $context['school_id'],
            'school_code' => $context['school_code'],
            'school_name' => $context['school_name'],
            'client' => $client,
        ];
    }

    private function client(string $client): string
    {
        return in_array(strtoupper($client), ['MOBILE', 'H5'], true)
            ? JwtToken::TOKEN_CLIENT_MOBILE
            : JwtToken::TOKEN_CLIENT_WEB;
    }

    private function verifyRefreshToken(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new RuntimeException('刷新凭证为空');
        }

        $config = (array) config('plugin.tinywan.jwt.app.jwt', []);
        $algorithm = (string) ($config['algorithms'] ?? 'HS256');
        $secret = (string) ($config['refresh_secret_key'] ?? '');
        if ($secret === '') {
            throw new RuntimeException('刷新凭证配置缺失');
        }

        try {
            JWT::$leeway = (int) ($config['leeway'] ?? 60);
            $decoded = JWT::decode($refreshToken, new Key($secret, $algorithm));
            $payload = json_decode(json_encode($decoded), true);
            $extend = (array) ($payload['extend'] ?? []);
            if (!$extend) {
                throw new RuntimeException('刷新凭证无效');
            }

            return $extend;
        } catch (ExpiredException) {
            throw new RuntimeException('登录已过期，请重新登录');
        } catch (SignatureInvalidException|BeforeValidException|UnexpectedValueException) {
            throw new RuntimeException('刷新凭证无效');
        }
    }
}
