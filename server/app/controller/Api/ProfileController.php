<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\TableRecord as ChannelTable;
use app\model\channel\User;
use app\server\auth\AuthService;
use app\server\auth\DeviceBlacklist;
use app\server\CurrentContext;
use app\server\file\FileService;
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
            $now = date('Y-m-d H:i:s');

            ChannelTable::connection()->transaction(function () use ($userId, $accountId, $name, $avatar, $mobile, $email, $layout, $notify, $now): void {
                User::updateActiveProfile($userId, [
                    'name' => $name,
                    'avatar' => $avatar,
                    'mobile' => $mobile,
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
            });

            return $this->ok($this->profileData(), '已保存');
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

    private function profileData(): array
    {
        $accountId = CurrentContext::accountId();
        $userId = CurrentContext::userId();
        $user = User::activeById((int) $userId, ['id', 'name', 'avatar', 'mobile', 'email']);
        $desktopRow = ChannelTable::latestDesktopConfig((int) $accountId, ['layout_json']);
        $notifyRows = ChannelTable::notifySettings((int) $accountId);

        $notify = ['system' => true, 'wechat' => true, 'email' => false];
        foreach ($notifyRows as $row) {
            if (in_array($row['channel'], self::NOTIFY_CHANNELS, true)) {
                $notify[$row['channel']] = $row['enabled'] !== 'false';
            }
        }

        $layout = $this->decodeJson($desktopRow->layout_json ?? null);

        return [
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name ?? '',
                'avatar' => $user?->avatar ?? '',
                'mobile' => $user?->mobile ?? '',
                'email' => $user?->email ?? '',
            ],
            'desktop' => [
                'wallpaper' => $layout['wallpaper'] ?? 'default',
                'wallpaper_url' => $layout['wallpaper_url'] ?? '',
            ],
            'notify' => $notify,
        ];
    }

    private function notifyInput(array $input): array
    {
        $notify = [];
        foreach (self::NOTIFY_CHANNELS as $channel) {
            $notify[$channel] = filter_var($input[$channel] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $notify;
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
