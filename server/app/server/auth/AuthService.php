<?php

namespace app\server\auth;

use app\model\channel\Account;
use app\model\channel\AuthPasskey;
use app\model\channel\MessageRecord;
use app\model\channel\TableRecord;
use app\model\channel\User;
use app\server\CurrentContext;
use app\server\rbac\DataScopeService;
use app\server\rbac\RbacService;
use app\server\wechat\WechatBindingService;
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
    private const PASSKEY_TTL = 600;

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

        return $this->issueSessionForAccount((int) $account->id, $this->client($client));
    }

    public function switchableAccounts(): array
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            throw new RuntimeException('请先登录');
        }

        return [
            'accounts' => Account::switchableAccounts($accountId),
        ];
    }

    public function switchAccount(int $targetAccountId, string $client = JwtToken::TOKEN_CLIENT_WEB): array
    {
        $currentAccountId = CurrentContext::accountId();
        if (!$currentAccountId) {
            throw new RuntimeException('请先登录');
        }
        if ($targetAccountId <= 0) {
            throw new RuntimeException('目标账号无效');
        }
        if (!Account::canSwitchBetween($currentAccountId, $targetAccountId)) {
            throw new RuntimeException('只能切换同一用户或同手机号绑定的账号');
        }

        return $this->issueSessionForAccount($targetAccountId, $client);
    }

    public function adminLoginPasskey(int $targetAccountId, string $client = JwtToken::TOKEN_CLIENT_WEB, string $returnUrl = ''): array
    {
        $creatorAccountId = CurrentContext::accountId();
        if (!$creatorAccountId) {
            throw new RuntimeException('请先登录');
        }
        $target = $this->assertAdminCanAccessAccount($targetAccountId);

        $now = date('Y-m-d H:i:s');
        $purpose = 'admin_login';
        $record = AuthPasskey::reusable($creatorAccountId, $targetAccountId, $purpose, $now);
        $reused = (bool) $record;
        if (!$record) {
            $record = AuthPasskey::createKey([
                'passkey' => $this->newPasskey(),
                'purpose' => $purpose,
                'creator_account_id' => $creatorAccountId,
                'target_account_id' => $targetAccountId,
                'creator_role_type' => CurrentContext::roleType(),
                'client' => $this->client($client),
                'expires_at' => date('Y-m-d H:i:s', time() + self::PASSKEY_TTL),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $passkey = (string) $record->passkey;

        return [
            'passkey' => $passkey,
            'login_url' => $this->appendPasskeyUrl($returnUrl, $passkey),
            'expires_in' => max(0, strtotime((string) $record->expires_at) - time()),
            'expires_at' => (string) $record->expires_at,
            'reused' => $reused,
            'target' => $target,
        ];
    }

    public function loginByPasskey(string $passkey, string $client = JwtToken::TOKEN_CLIENT_WEB): array
    {
        $passkey = trim($passkey);
        if ($passkey === '') {
            throw new RuntimeException('一键登录凭证不能为空');
        }

        $record = AuthPasskey::consume($passkey, date('Y-m-d H:i:s'));
        if (!$record) {
            throw new RuntimeException('一键登录链接已失效或已使用');
        }

        return $this->issueSessionForAccount((int) $record['target_account_id'], $client);
    }

    public function assertAdminCanAccessAccount(int $targetAccountId): array
    {
        $roleType = CurrentContext::roleType();
        if (!in_array($roleType, ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            throw new RuntimeException('无一键登录权限');
        }

        $target = Account::adminLoginTargetProfile($targetAccountId);
        if (!$target) {
            throw new RuntimeException('目标账号不存在、已停用或未分配角色');
        }

        $targetRoleType = (string) ($target['role_type'] ?? '');
        if ($roleType === 'super_admin') {
            return $target;
        }
        if ($roleType === 'school_admin') {
            if ($targetRoleType === 'super_admin') {
                throw new RuntimeException('学校管理员不能一键登录超级管理员账号');
            }
            return $target;
        }

        if (!in_array($targetRoleType, ['teacher', 'student'], true)) {
            throw new RuntimeException('学院或专业管理员只能一键登录老师、学生账号');
        }

        $scope = $this->adminScope();
        if ($roleType === 'college_admin') {
            $depIds = $scope['dep_ids'];
            if (!$depIds || !in_array((int) ($target['dep_id'] ?? 0), $depIds, true)) {
                throw new RuntimeException('目标账号不在当前学院范围内');
            }
            return $target;
        }

        $professionIds = $scope['profession_ids'];
        if (!$professionIds || !in_array((int) ($target['profession_id'] ?? 0), $professionIds, true)) {
            throw new RuntimeException('目标账号不在当前专业范围内');
        }

        return $target;
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

        $jti = trim((string) ($extend['jti'] ?? ''));
        if ($jti !== '' && DeviceBlacklist::isRevoked($jti)) {
            throw new RuntimeException('设备已被移除，请重新登录');
        }

        $client = (string) ($extend['client'] ?? JwtToken::TOKEN_CLIENT_WEB);

        return $this->issueSessionForAccount($accountId, $client, $jti !== '' ? $jti : null);
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

    public function applyToken(string $token, bool $initializeSchema = true): ?array
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

        $jti = trim((string) ($extend['jti'] ?? ''));
        if ($jti !== '' && DeviceBlacklist::isRevoked($jti)) {
            throw new RuntimeException('设备已被移除，请重新登录');
        }

        $context = $this->contextForAccount($accountId, (string) ($extend['client'] ?? JwtToken::TOKEN_CLIENT_WEB), $initializeSchema);
        if ($jti !== '') {
            CurrentContext::set(['device_jti' => $jti]);
        }

        return $context;
    }

    public function publicContext(array $context): array
    {
        unset(
            $context['school_database_id'],
            $context['school_database'],
            $context['school_connection'],
            $context['school_code']
        );

        return $context;
    }

    public function contextForAccount(int $accountId, string $client = JwtToken::TOKEN_CLIENT_WEB, bool $initializeSchema = true): array
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
        $context['wechat_binding'] = (new WechatBindingService())->contextForUser((int) $user->id, (string) $role['role_type']);

        CurrentContext::set($context);

        $dataScope = (new DataScopeService($rbac))->filter('default', (int) $account->id, $roleId, (string) $role['role_type']);
        $context['organization_scopes'] = CurrentContext::organizationScopes();
        $context['data_scope'] = $dataScope;

        CurrentContext::set($context);
        if ($initializeSchema) $this->ensureDefaultMessageTemplates();

        return $context;
    }

    /**
     * 同步默认流程消息模板
     */
    private function ensureDefaultMessageTemplates(): void
    {
        try {
            MessageRecord::ensureSchema();
        } catch (\Throwable) {
        }
    }

    private function issueSessionForAccount(int $accountId, string $client, ?string $jti = null): array
    {
        $context = $this->contextForAccount($accountId, $this->client($client));
        $jti = $jti ?: $this->newDeviceId();
        $token = JwtToken::generateToken($this->tokenPayload($context, $this->client($client), $jti));
        $this->registerDevice((int) $context['account_id'], $jti);

        return [
            'token' => $token,
            'session' => $this->session($context, (int) $token['expires_in']),
        ];
    }

    /**
     * 生成稳定的设备标识（登录时创建，刷新时沿用，用于远程下线）。
     */
    private function newDeviceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 登录/刷新时登记当前设备，供设备管理与远程下线使用。
     */
    private function registerDevice(int $accountId, string $jti): void
    {
        if ($accountId <= 0 || $jti === '') {
            return;
        }

        try {
            $request = request();
            $ip = $request ? (string) $request->getRealIp() : '';
            $userAgent = $request ? (string) $request->header('user-agent', '') : '';
            TableRecord::registerDevice($accountId, $jti, [
                'device_name' => $this->deviceName($userAgent),
                'ip' => mb_substr($ip, 0, 80),
                'user_agent' => mb_substr($userAgent, 0, 255),
            ], date('Y-m-d H:i:s'));
        } catch (\Throwable) {
        }
    }

    /**
     * 从 UA 粗略识别设备名称。
     */
    private function deviceName(string $userAgent): string
    {
        $agent = strtolower($userAgent);
        $platform = match (true) {
            str_contains($agent, 'iphone'), str_contains($agent, 'ios') => 'iPhone',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh'), str_contains($agent, 'mac os') => 'Mac',
            str_contains($agent, 'micromessenger') => '微信',
            default => '未知设备',
        };
        $browser = match (true) {
            str_contains($agent, 'micromessenger') => '企业微信',
            str_contains($agent, 'edg') => 'Edge',
            str_contains($agent, 'chrome') => 'Chrome',
            str_contains($agent, 'firefox') => 'Firefox',
            str_contains($agent, 'safari') => 'Safari',
            default => '',
        };

        return $browser !== '' ? "{$platform} · {$browser}" : $platform;
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
            'context' => $this->publicContext($context),
        ];
    }

    private function tokenPayload(array $context, string $client, string $jti = ''): array
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
            'jti' => $jti,
        ];
    }

    private function client(string $client): string
    {
        return in_array(strtoupper($client), ['MOBILE', 'H5'], true)
            ? JwtToken::TOKEN_CLIENT_MOBILE
            : JwtToken::TOKEN_CLIENT_WEB;
    }

    private function newPasskey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }

    private function appendPasskeyUrl(string $returnUrl, string $passkey): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '') {
            return '';
        }

        $parts = parse_url($returnUrl);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['passkey'] = $passkey;
        unset($query['fresh']);

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}{$path}?" . http_build_query($query) . $fragment;
    }

    private function adminScope(): array
    {
        $scopes = CurrentContext::organizationScopes();
        $depIds = [];
        $professionIds = [];
        foreach ($scopes as $scope) {
            if (!empty($scope['dep_id'])) {
                $depIds[] = (int) $scope['dep_id'];
            }
            if (!empty($scope['profession_id'])) {
                $professionIds[] = (int) $scope['profession_id'];
            }
        }

        return [
            'role_type' => CurrentContext::roleType(),
            'dep_ids' => array_values(array_unique($depIds)),
            'profession_ids' => array_values(array_unique($professionIds)),
        ];
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
