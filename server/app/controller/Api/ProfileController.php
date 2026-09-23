<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\Account;
use app\model\channel\TableRecord as ChannelTable;
use app\model\channel\User;
use app\server\auth\AccountMobileService;
use app\server\auth\AuthService;
use app\server\auth\DeviceBlacklist;
use app\server\CurrentContext;
use app\server\file\FileService;
use app\server\wechat\WechatBindingService;
use support\Request;
use support\Response;
use Throwable;

class ProfileController
{
    use Responds;

    private const NOTIFY_CHANNELS = ['system', 'wechat', 'email'];

    /**
     * 获取个人设置
     */
    #[OperationLog('获取个人设置')]
    public function settings(Request $request): Response
    {
        if (!CurrentContext::accountId()) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            return $this->ok($this->profileData());
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /**
     * 保存个人设置
     */
    #[OperationLog('保存个人设置')]
    public function saveSettings(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        $userId = CurrentContext::userId();
        if (!$accountId || !$userId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $name = $this->stringInput($request, 'name', 80) ?: (string) (CurrentContext::get('user_name') ?: '未命名用户');
            $avatar = $this->nullableString($request, 'avatar', 255);
            $mobile = $this->nullableString($request, 'mobile', 40);
            $email = $this->nullableString($request, 'email', 120);
            $layout = [
                'wallpaper' => $this->nullableString($request, 'wallpaper', 80),
                'wallpaper_url' => $this->nullableString($request, 'wallpaper_url', 255),
            ];
            $notify = $this->notifyInput((array) $request->input('notify', []));
            [$quietStart, $quietEnd] = $this->quietInput((array) $request->input('wechat_quiet', []));
            ChannelTable::ensureNotifySettingColumns();
            $now = date('Y-m-d H:i:s');

            ChannelTable::connection()->transaction(function () use ($userId, $accountId, $name, $avatar, $mobile, $email, $layout, $notify, $quietStart, $quietEnd, $now): void {
                $current = User::lockProfile($userId);
                if (!$current) {
                    throw new \RuntimeException('用户不存在或已停用', 403);
                }
                if ($mobile !== null && (string) $current->mobile !== (string) $mobile) {
                    throw new \RuntimeException('手机号变更需要短信验证，请使用手机号绑定入口', 409);
                }
                User::updateActiveProfile($userId, [
                    'name' => $name,
                    'avatar' => $avatar,
                    'email' => $email,
                    'updated_at' => $now,
                ]);

                ChannelTable::saveDesktopConfig($accountId, [
                    'layout_json' => json_encode($layout, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                ]);

                foreach ($notify as $channel => $enabled) {
                    ChannelTable::saveNotifySetting($accountId, $channel, $enabled, $now);
                }
                ChannelTable::saveNotifyQuietTime($accountId, $quietStart, $quietEnd, $now);
            });

            return $this->ok($this->profileData(), '已保存');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    /**
     * 修改当前管理员密码。
     */
    #[OperationLog('修改个人密码')]
    public function changePassword(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }
        if (!in_array(CurrentContext::roleType(), ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            return $this->fail(40300, '仅管理员可修改管理员密码', 403);
        }

        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $confirmPassword = (string) $request->input('confirm_password', '');
        if ($currentPassword === '') {
            return $this->fail(40001, '请输入当前密码', 400);
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 120) {
            return $this->fail(40001, '新密码长度应为 6 至 120 位', 400);
        }
        if ($newPassword !== $confirmPassword) {
            return $this->fail(40001, '两次输入的新密码不一致', 400);
        }

        try {
            $account = Account::activeById((int) $accountId, ['id', 'password']);
            if (!$account || !password_verify($currentPassword, (string) $account->password)) {
                return $this->fail(40001, '当前密码不正确', 400);
            }
            if (password_verify($newPassword, (string) $account->password)) {
                return $this->fail(40001, '新密码不能与当前密码相同', 400);
            }

            $now = date('Y-m-d H:i:s');
            $revokedJtis = Account::connection()->transaction(function () use ($accountId, $newPassword, $now): array {
                Account::updateAdminAccount((int) $accountId, [
                    'password' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'updated_at' => $now,
                ]);

                return ChannelTable::revokeOtherDevices((int) $accountId, CurrentContext::deviceJti(), $now);
            });

            $ttl = (new AuthService())->refreshExpiresIn();
            foreach ($revokedJtis as $jti) {
                DeviceBlacklist::revoke($jti, $ttl);
            }

            return $this->ok([], '密码已修改，其他设备已下线');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    /**
     * 上传个人素材
     */
    #[OperationLog('上传个人素材')]
    public function uploadAsset(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $type = $this->assetType($request);
            $result = (new FileService())->upload($request, [
                'category' => 'profile',
                'is_temporary' => false,
                'require_md5' => false,
                'max_size' => $this->assetMaxSize($type),
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            ]);

            return $this->ok([
                'type' => $type,
                'file_id' => $result['file_id'],
                'url' => $result['url'],
            ], '已上传');
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode() === 429 ? 429 : 400;
            return $this->fail($status === 429 ? 42900 : 40001, $exception->getMessage(), $status);
        }
    }

    /**
     * 查询已登录设备
     */
    #[OperationLog('查询已登录设备')]
    public function devices(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $currentJti = CurrentContext::deviceJti();
            $devices = array_map(static function (array $device) use ($currentJti): array {
                $device['current'] = $currentJti !== null && $device['jti'] === $currentJti;
                unset($device['jti']);
                return $device;
            }, ChannelTable::devices((int) $accountId));

            return $this->ok(['devices' => $devices]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /**
     * 下线指定设备
     */
    #[OperationLog('下线设备')]
    public function revokeDevice(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }

        $deviceId = (int) $request->input('id', 0);
        if ($deviceId <= 0) {
            return $this->fail(40001, '设备标识无效', 400);
        }

        try {
            $now = date('Y-m-d H:i:s');
            $jti = ChannelTable::revokeDevice((int) $accountId, $deviceId, $now);
            if ($jti === null) {
                return $this->fail(40400, '设备不存在', 404);
            }

            DeviceBlacklist::revoke($jti, (new AuthService())->refreshExpiresIn());

            return $this->ok([], '已下线');
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /** 发送手机号绑定验证码。 */
    #[OperationLog('发送手机号绑定验证码')]
    public function sendMobileCode(Request $request): Response
    {
        try {
            return $this->ok((new AccountMobileService())->send((string) $request->input('mobile', '')));
        } catch (Throwable $exception) {
            return $this->mobileFailure($exception);
        }
    }

    /** 验证并绑定当前用户手机号。 */
    #[OperationLog('验证并绑定手机号')]
    public function verifyMobile(Request $request): Response
    {
        try {
            (new AccountMobileService())->verify((string) $request->input('mobile', ''), (string) $request->input('sms_code', ''));
            return $this->ok($this->profileData(), '手机号已验证');
        } catch (Throwable $exception) {
            return $this->mobileFailure($exception);
        }
    }

    /** 返回手机号验证错误。 */
    private function mobileFailure(Throwable $exception): Response
    {
        $status = in_array((int) $exception->getCode(), [401, 403, 409, 429, 503], true) ? (int) $exception->getCode() : 400;
        return $this->fail($status * 100, $exception->getMessage(), $status);
    }

    private function profileData(): array
    {
        $accountId = CurrentContext::accountId();
        $userId = CurrentContext::userId();
        $user = User::activeById((int) $userId, ['id', 'name', 'avatar', 'mobile', 'email', 'verified_mobile']);
        $desktopRow = ChannelTable::latestDesktopConfig((int) $accountId, ['layout_json']);
        $notifyRows = ChannelTable::notifySettings((int) $accountId);

        $notify = ['system' => true, 'wechat' => true, 'email' => false];
        $quiet = ['start' => '00:00', 'end' => '24:00'];
        foreach ($notifyRows as $row) {
            if (in_array($row['channel'], self::NOTIFY_CHANNELS, true)) {
                $notify[$row['channel']] = $row['enabled'] !== 'false';
                if ($row['channel'] === 'wechat') {
                    $quiet['start'] = (string) ($row['quiet_start'] ?: '00:00');
                    $quiet['end'] = (string) ($row['quiet_end'] ?: '24:00');
                }
            }
        }

        $layout = $this->decodeJson($desktopRow->layout_json ?? null);

        return [
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name ?? '',
                'avatar' => $user?->avatar ?? '',
                'mobile' => $user?->mobile ?? '',
                'mobile_verified' => !empty($user?->mobile) && $user->mobile === $user->verified_mobile,
                'email' => $user?->email ?? '',
            ],
            'desktop' => [
                'wallpaper' => $layout['wallpaper'] ?? 'default',
                'wallpaper_url' => $layout['wallpaper_url'] ?? '',
            ],
            'notify' => $notify,
            'wechat_quiet' => $quiet,
            'wechat_binding' => (new WechatBindingService())->contextForUser((int) $userId, (string) CurrentContext::roleType()),
        ];
    }

    private function notifyInput(array $input): array
    {
        $notify = [];
        foreach (self::NOTIFY_CHANNELS as $channel) {
            if (array_key_exists($channel, $input)) $notify[$channel] = filter_var($input[$channel], FILTER_VALIDATE_BOOL);
        }

        return $notify;
    }

    private function quietInput(array $input): array
    {
        $current = ['start' => '00:00', 'end' => '24:00'];
        foreach (ChannelTable::notifySettings((int) CurrentContext::accountId()) as $row) {
            if ($row['channel'] === 'wechat') $current = ['start' => $row['quiet_start'], 'end' => $row['quiet_end']];
        }
        $start = trim((string) ($input['start'] ?? $current['start']));
        $end = trim((string) ($input['end'] ?? $current['end']));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
            || ($end !== '24:00' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end))) {
            throw new \InvalidArgumentException('通知接收时间格式不正确');
        }
        if ($start === $end) throw new \InvalidArgumentException('开始时间和结束时间不能相同，全天请选 00:00 至 24:00');
        return [$start, $end];
    }

    #[OperationLog('保存个人通知设置')]
    public function saveNotifications(Request $request): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        try {
            $accountId = (int) CurrentContext::accountId();
            if (!$accountId) return $this->fail(40100, '请先登录', 401);
            $notify = $this->notifyInput((array) $request->input('notify', []));
            [$start, $end] = $this->quietInput((array) $request->input('wechat_quiet', []));
            ChannelTable::connection()->transaction(function () use ($accountId, $notify, $start, $end): void {
                if (!User::lockProfile((int) CurrentContext::userId())) throw new \RuntimeException('用户不存在或已停用');
                $now = date('Y-m-d H:i:s');
                foreach ($notify as $channel => $enabled) ChannelTable::saveNotifySetting($accountId, $channel, $enabled, $now);
                ChannelTable::saveNotifyQuietTime($accountId, $start, $end, $now);
            });
            return $this->ok($this->profileData(), '通知设置已保存');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function assetType(Request $request): string
    {
        $type = (string) $request->input('type', 'avatar');
        if (!in_array($type, ['avatar', 'wallpaper'], true)) {
            throw new \InvalidArgumentException('素材类型无效');
        }

        return $type;
    }

    private function assetMaxSize(string $type): int
    {
        return $type === 'wallpaper' ? 8 * 1024 * 1024 : 3 * 1024 * 1024;
    }

    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        return $this->limit(trim((string) $request->input($key, '')), $maxLength);
    }

    private function nullableString(Request $request, string $key, int $maxLength): ?string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        return $value === '' ? null : $value;
    }

    private function limit(string $value, int $maxLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function decodeJson(?string $json): array
    {
        if (!$json) {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
