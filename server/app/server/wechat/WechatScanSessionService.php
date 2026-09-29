<?php

namespace app\server\wechat;

use app\model\channel\Account;
use app\model\channel\TableRecord;
use app\model\channel\User;
use app\model\channel\UserWechat;
use app\server\CurrentContext;
use app\server\config\ConfigService;
use RuntimeException;
use support\Redis;

class WechatScanSessionService
{
    public const TTL = 300;
    public const COOKIE = 'wechat_scan_identity';

    public function create(): array
    {
        $binding = (new WechatBindingService())->contextForUser((int) CurrentContext::userId(), (string) CurrentContext::roleType());
        if (!$binding['required'] || $binding['bound'] || $binding['binding_outdated']) throw new RuntimeException('当前账号不能发起扫码绑定');
        $corp = $this->corp();
        $device = (string) CurrentContext::deviceJti();
        if ($device === '') throw new RuntimeException('登录设备信息缺失，请重新登录');
        $origin = (new WechatBindingService())->origin();
        if (!str_starts_with($origin, 'https://')) throw new RuntimeException('扫码绑定需要学校 HTTPS 地址');
        return $this->locked('owner:' . $device, function () use ($corp, $device, $origin): array {
            $ownerKey = $this->key('owner', $device);
            $previous = (string) Redis::get($ownerKey);
            if ($previous !== '') $this->cancel($previous);
            $id = bin2hex(random_bytes(32));
            $ticket = bin2hex(random_bytes(32));
            $data = [
                'id' => $id, 'school' => CurrentContext::schoolDatabaseId(), 'account' => CurrentContext::accountId(),
                'user' => CurrentContext::userId(), 'device' => $device, 'corp' => $corp,
                'state' => 'pending', 'expires_at' => time() + self::TTL,
            ];
            $this->assertTarget($data);
            $this->save($data);
            Redis::setEx($this->key('ticket', $ticket), self::TTL, $id);
            Redis::setEx($ownerKey, self::TTL, $id);
            return ['session_id' => $id, 'scan_url' => $origin . '/api/wechat/scan/start?ticket=' . $ticket, 'expires_in' => self::TTL, 'state' => 'pending'];
        });
    }

    public function cancelCurrent(): void
    {
        $device = (string) CurrentContext::deviceJti();
        if ($device === '' || !CurrentContext::accountId()) return;
        $id = (string) Redis::get($this->key('owner', $device));
        if ($id !== '') $this->cancel($id);
    }

    public function status(string $id): array
    {
        $data = $this->read($id);
        if (!$data) return ['state' => 'expired'];
        $this->assertOwner($data);
        return ['state' => $data['state'], 'expires_in' => max(0, $data['expires_at'] - time())];
    }

    public function cancel(string $id): array
    {
        return $this->locked($id, function () use ($id): array {
            $data = $this->read($id);
            if (!$data) return ['state' => 'expired'];
            $this->assertOwner($data);
            if (in_array($data['state'], ['pending', 'scanned'], true)) {
                $data['state'] = 'cancelled';
                $this->save($data);
            }
            return ['state' => $data['state']];
        });
    }

    public function start(string $ticket): array
    {
        if (!(new WechatBindingService())->inWechat()) throw new RuntimeException('请使用企业微信扫描二维码');
        $this->assertToken($ticket);
        $id = (string) $this->consume($this->key('ticket', $ticket));
        $data = $this->active($id);
        $this->assertTarget($data);
        $browser = bin2hex(random_bytes(32));
        $state = bin2hex(random_bytes(32));
        Redis::setEx($this->key('oauth', $state), $this->remaining($data), json_encode([
            'id' => $id, 'browser' => hash('sha256', $browser), 'corp' => $data['corp'],
        ]));
        return ['browser' => $browser, 'url' => 'https://open.weixin.qq.com/connect/oauth2/authorize?' . http_build_query([
            'appid' => $data['corp'], 'redirect_uri' => (new WechatBindingService())->origin() . '/api/wechat/scan/callback',
            'response_type' => 'code', 'scope' => 'snsapi_base', 'state' => $state,
        ]) . '#wechat_redirect'];
    }

    public function callback(string $code, string $state): array
    {
        $this->assertToken($state);
        $oauth = json_decode((string) $this->consume($this->key('oauth', $state)), true);
        $browser = (string) request()->cookie(WechatBindingService::BROWSER_COOKIE, '');
        if (!$oauth || !hash_equals($oauth['browser'], hash('sha256', $browser))) throw new RuntimeException('企业微信授权已失效，请在电脑刷新二维码');
        $data = $this->active($oauth['id']);
        $this->assertTarget($data);
        $wechatUser = (new WechatBindingService())->resolveIdentity($code, $data['corp']);
        return $this->locked($data['id'], function () use ($data, $oauth, $wechatUser): array {
            $data = $this->active($data['id']);
            if ($data['state'] !== 'pending') throw new RuntimeException('二维码已使用，请在电脑重新获取');
            $credential = bin2hex(random_bytes(32));
            $identity = [
                'id' => $data['id'], 'browser' => $oauth['browser'], 'corp' => $data['corp'],
                'wechat_userid' => $wechatUser, 'nonce' => bin2hex(random_bytes(32)),
            ];
            Redis::setEx($this->key('identity', $credential), $this->remaining($data), json_encode($identity));
            $data['state'] = 'scanned';
            $this->save($data);
            return ['credential' => $credential, 'expires_in' => $this->remaining($data)];
        });
    }

    public function mobileContext(): array
    {
        $identity = $this->identity();
        $data = $this->active($identity['id']);
        $account = $this->assertTarget($data);
        $user = User::enabledById($data['user']);
        return [
            'account_name' => (string) $account->login_name, 'user_name' => (string) $user->name,
            'wechat_userid' => $identity['wechat_userid'], 'nonce' => $identity['nonce'],
            'expires_in' => $this->remaining($data),
        ];
    }

    public function confirm(string $nonce, string $password): array
    {
        $this->assertSameOrigin();
        $identity = $this->identity();
        if (!hash_equals($identity['nonce'], $nonce)) throw new RuntimeException('确认凭证无效，请重新扫码');
        return $this->locked($identity['id'], function () use ($identity, $password): array {
            $data = $this->active($identity['id']);
            if ($data['state'] !== 'scanned') throw new RuntimeException('二维码已使用或失效');
            $this->throttle('session:' . $data['id'], 5);
            $this->throttle('account:' . $data['account'], 10);
            $account = $this->assertTarget($data);
            if ($password === '' || strlen($password) > 4096 || !password_verify($password, (string) $account->password)) throw new RuntimeException('目标系统账号密码错误');
            $credential = (string) request()->cookie(self::COOKIE, '');
            if (!$this->consume($this->key('identity', $credential))) throw new RuntimeException('确认凭证已使用');
            $data['state'] = 'confirming';
            $this->save($data);
            try {
                User::connection()->transaction(function () use ($data, $identity, $account): void {
                    $verifiedHash = (string) $account->password;
                    $account = Account::query()->where('id', $data['account'])->lockForUpdate()->first();
                    if (!$account || $account->status !== 'enabled' || $account->deleted_at || (int) $account->user_id !== $data['user']) throw new RuntimeException('目标账号已停用或变更');
                    if (!hash_equals($verifiedHash, (string) $account->password)) throw new RuntimeException('账号密码已变更，请重新扫码');
                    $this->assertTarget($data);
                    UserWechat::bindUser($data['user'], $identity['wechat_userid'], $data['corp']);
                });
                $data['state'] = 'confirmed';
            } catch (\Throwable $exception) {
                $data['state'] = 'failed';
                $this->save($data);
                throw $exception;
            }
            $this->save($data);
            return ['state' => 'confirmed'];
        });
    }

    private function identity(): array
    {
        $ticket = (string) request()->cookie(self::COOKIE, '');
        $this->assertToken($ticket);
        $identity = json_decode((string) Redis::get($this->key('identity', $ticket)), true);
        $browser = (string) request()->cookie(WechatBindingService::BROWSER_COOKIE, '');
        if (!$identity || $identity['corp'] !== $this->corp() || !hash_equals($identity['browser'], hash('sha256', $browser))) throw new RuntimeException('手机确认已失效，请重新扫码');
        return $identity;
    }

    private function assertTarget(array $data): Account
    {
        if ($data['expires_at'] <= time()) throw new RuntimeException('二维码已过期，请重新获取');
        $account = Account::enabledById($data['account']);
        $activeDevice = TableRecord::queryTable('user_device')->where('account_id', $data['account'])->where('jti', $data['device'])
            ->where('status', 'enabled')->whereNull('deleted_at')->exists();
        $revoked = Redis::exists('jwt_blacklist:' . CurrentContext::schoolDatabaseId() . ':' . $data['device']);
        if (!$account || (int) $account->user_id !== $data['user'] || !User::enabledById($data['user']) || !$activeDevice || $revoked) throw new RuntimeException('电脑登录会话或目标账号已失效，请重新登录');
        if ($data['corp'] !== $this->corp()) throw new RuntimeException('学校企业微信配置已变更，请重新扫码');
        return $account;
    }

    private function assertOwner(array $data): void
    {
        if ($data['account'] !== CurrentContext::accountId() || $data['user'] !== CurrentContext::userId()
            || !hash_equals($data['device'], (string) CurrentContext::deviceJti())) throw new RuntimeException('无权访问此扫码会话');
    }

    private function active(string $id): array
    {
        $data = $this->read($id);
        if (!$data || !in_array($data['state'], ['pending', 'scanned'], true)) throw new RuntimeException('二维码已过期、取消或使用，请在电脑重新获取');
        return $data;
    }

    private function read(string $id): ?array
    {
        $this->assertToken($id);
        $data = json_decode((string) Redis::get($this->key('session', $id)), true);
        return is_array($data) && $data['school'] === CurrentContext::schoolDatabaseId() && $data['expires_at'] > time() ? $data : null;
    }

    private function save(array $data): void
    {
        Redis::setEx($this->key('session', $data['id']), $this->remaining($data), json_encode($data));
    }

    private function remaining(array $data): int { return max(1, $data['expires_at'] - time()); }

    private function assertToken(string $value): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $value)) throw new RuntimeException('扫码凭证无效，请重新获取二维码');
    }

    private function corp(): string
    {
        $config = new ConfigService();
        $corp = trim((string) $config->get('wechat.corp_id'));
        if ($corp === '' || trim((string) $config->get('wechat.secret')) === '') throw new RuntimeException('请管理员先配置企业微信');
        return $corp;
    }

    private function assertSameOrigin(): void
    {
        $origin = (string) request()->header('origin', '');
        if ($origin === '' || !hash_equals((new WechatBindingService())->origin(), $origin)
            || !str_contains(strtolower((string) request()->header('content-type', '')), 'application/json')) throw new RuntimeException('请通过学校手机确认页面操作');
    }

    private function throttle(string $scope, int $limit): void
    {
        $count = (int) Redis::eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],900) end; return n", 1, $this->key('attempt', $scope));
        if ($count > $limit) throw new RuntimeException('确认尝试过于频繁，请十五分钟后重试');
    }

    private function consume(string $key): mixed
    {
        return Redis::eval("local v=redis.call('GET',KEYS[1]); if v then redis.call('DEL',KEYS[1]) end; return v", 1, $key);
    }

    private function locked(string $id, callable $callback): mixed
    {
        $key = $this->key('lock', $id);
        $token = bin2hex(random_bytes(16));
        $acquired = Redis::eval("if redis.call('SET',KEYS[1],ARGV[1],'NX','EX',15) then return 1 else return 0 end", 1, $key, $token);
        if (!$acquired) throw new RuntimeException('扫码会话正在处理，请稍后重试');
        try { return $callback(); }
        finally { Redis::eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) else return 0 end", 1, $key, $token); }
    }

    private function key(string $kind, string $value): string
    {
        return 'wechat:scan:' . CurrentContext::schoolDatabaseId() . ':' . $kind . ':' . hash('sha256', $value);
    }
}
