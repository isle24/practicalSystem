<?php

namespace app\server\signature;

use app\model\channel\Account;
use app\model\channel\FileRecord;
use app\model\channel\FileRelation;
use app\model\channel\SignatureRecord;
use app\model\channel\TableRecord;
use app\model\channel\User;
use app\server\CurrentContext;
use app\server\file\FileService;
use app\server\wechat\WechatBindingService;
use RuntimeException;
use support\Redis;
use Webman\Http\UploadFile;

class SignatureService
{
    public const TTL = 300;

    public function current(): array
    {
        $this->identity();
        $row = SignatureRecord::owned();
        return ['signature' => $row ? $this->present($row) : null];
    }

    public function save(UploadFile $file, string $session = ''): array
    {
        $this->identity();
        $key = 'signature:upload:' . CurrentContext::schoolDatabaseId() . ':' . CurrentContext::userId();
        $count = (int) Redis::eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],60) end; return n", 1, $key);
        if ($count > 20) throw new RuntimeException('签名保存过于频繁，请稍后重试', 429);
        $path = $this->normalize($file);
        try {
            return SignatureRecord::connection()->transaction(function () use ($path, $session): array {
                $this->lockUser();
                $target = $session !== '' ? $this->session($session, true) : null;
                if ($target) {
                    $this->assertTarget($target);
                    if ((int) $target['user_id'] !== CurrentContext::userId()) throw new RuntimeException('请使用与电脑相同的系统用户登录', 403);
                    if ($target['state'] !== 'pending') throw new RuntimeException('签名会话已使用或取消', 409);
                }
                $version = 1 + (int) SignatureRecord::signatures()->where('user_id', CurrentContext::userId())->max('version');
                $size = getimagesize($path);
                $hash = hash_file('sha256', $path);
                $stored = (new FileService())->storeGeneratedFile($path, ['category' => 'personal_signature', 'ext' => 'png', 'name' => '个人签名.png', 'uploader_id' => CurrentContext::accountId(), 'is_temporary' => false]);
                $now = date('Y-m-d H:i:s');
                $row = ['user_id' => CurrentContext::userId(), 'account_id' => CurrentContext::accountId(), 'file_id' => (int) $stored['file_id'], 'version' => $version, 'sha256' => $hash, 'width' => $size[0], 'height' => $size[1], 'created_at' => $now];
                $row['id'] = SignatureRecord::signatures()->insertGetId($row);
                FileRelation::createRelation($row['file_id'], 'personal_signature', $row['id'], 'signature', $now);
                if ($target) SignatureRecord::sessions()->where('id', $target['id'])->update(['state' => 'confirmed', 'signature_id' => $row['id'], 'updated_at' => $now]);
                return ['signature' => $this->present($row), 'state' => 'confirmed'];
            });
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function createSession(): array
    {
        $this->identity();
        $origin = (new WechatBindingService())->origin();
        if (!str_starts_with($origin, 'https://')) throw new RuntimeException('手机签名需要学校 HTTPS 地址', 422);
        $device = (string) CurrentContext::deviceJti();
        if ($device === '') throw new RuntimeException('请重新登录后创建签名会话', 401);
        return SignatureRecord::connection()->transaction(function () use ($origin, $device): array {
            $this->lockUser();
            $now = date('Y-m-d H:i:s');
            SignatureRecord::sessions()->where('account_id', CurrentContext::accountId())->where('device_jti', $device)->where('state', 'pending')->update(['state' => 'cancelled', 'updated_at' => $now]);
            $token = bin2hex(random_bytes(32));
            $row = ['token_hash' => hash('sha256', $token), 'school_id' => CurrentContext::schoolDatabaseId(), 'user_id' => CurrentContext::userId(), 'account_id' => CurrentContext::accountId(), 'device_jti' => $device, 'state' => 'pending', 'expires_at' => date('Y-m-d H:i:s', time() + self::TTL), 'created_at' => $now, 'updated_at' => $now];
            $this->assertTarget($row);
            SignatureRecord::sessions()->insert($row);
            return ['session_id' => $token, 'scan_url' => $origin . '/h5/?signature_session=' . $token, 'expires_in' => self::TTL, 'state' => 'pending'];
        });
    }

    public function sessionStatus(string $token, bool $mobile = false): array
    {
        $this->identity();
        $row = $this->session($token);
        if ($mobile) {
            if ((int) $row['user_id'] !== CurrentContext::userId()) throw new RuntimeException('请使用与电脑相同的系统用户登录', 403);
        } else $this->assertOwner($row);
        $this->assertTarget($row);
        return ['state' => $row['state'], 'expires_in' => max(0, strtotime($row['expires_at']) - time()), 'signature_id' => (int) ($row['signature_id'] ?? 0)];
    }

    public function cancelSession(string $token): array
    {
        $this->identity();
        return SignatureRecord::connection()->transaction(function () use ($token): array {
            $this->lockUser();
            $row = $this->session($token, true);
            $this->assertOwner($row);
            if ($row['state'] === 'pending') SignatureRecord::sessions()->where('id', $row['id'])->update(['state' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
            return ['state' => $row['state'] === 'pending' ? 'cancelled' : $row['state']];
        });
    }

    public function cancelCurrent(): void
    {
        if (!CurrentContext::userId() || !CurrentContext::accountId() || !CurrentContext::deviceJti()) return;
        SignatureRecord::connection()->transaction(function (): void {
            $this->lockUser();
            SignatureRecord::sessions()->where('account_id', CurrentContext::accountId())->where('device_jti', CurrentContext::deviceJti())->where('state', 'pending')->update(['state' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
        });
    }

    public function snapshotForApproval(int $signatureId): array
    {
        if ($signatureId <= 0) throw new RuntimeException('请选择本人电子签名', 422);
        return $this->snapshotForCurrentUser($signatureId);
    }

    public function snapshotForCurrentUser(?int $signatureId = null): array
    {
        $this->identity();
        $row = SignatureRecord::owned($signatureId);
        if (!$row) throw new RuntimeException('个人签名不存在或不属于本人', 422);
        $snapshot = ['signature_id' => (int) $row['id'], 'school_id' => CurrentContext::schoolDatabaseId(), 'file_id' => (int) $row['file_id'], 'version' => (int) $row['version'], 'sha256' => $row['sha256'], 'user_id' => (int) $row['user_id'], 'signed_at' => date('Y-m-d H:i:s')];
        (new \app\server\export\SignatureRenderer())->image($snapshot);
        return $snapshot;
    }

    private function identity(): void
    {
        $account = Account::enabledById((int) CurrentContext::accountId());
        if (!CurrentContext::schoolDatabaseId() || !$account || (int) $account->user_id !== CurrentContext::userId() || !User::enabledById((int) CurrentContext::userId())) throw new RuntimeException('请登录有效系统账号', 401);
    }

    private function lockUser(): void
    {
        $user = User::query()->where('id', CurrentContext::userId())->lockForUpdate()->first();
        if (!$user || $user->deleted_at || $user->status !== 'enabled') throw new RuntimeException('用户已停用', 403);
    }

    private function session(string $token, bool $lock = false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('签名会话无效', 422);
        $query = SignatureRecord::sessions()->where('token_hash', hash('sha256', $token))->where('school_id', CurrentContext::schoolDatabaseId());
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        if (!$row || strtotime($row->expires_at) <= time()) throw new RuntimeException('签名会话已过期，请在电脑重新获取', 410);
        return $row->getAttributes();
    }

    private function assertOwner(array $row): void
    {
        if ((int) $row['account_id'] !== CurrentContext::accountId() || (int) $row['user_id'] !== CurrentContext::userId() || !hash_equals($row['device_jti'], (string) CurrentContext::deviceJti())) throw new RuntimeException('无权操作此电脑签名会话', 403);
    }

    private function assertTarget(array $row): void
    {
        $account = Account::enabledById((int) $row['account_id']);
        $device = TableRecord::queryTable('user_device')->where('account_id', $row['account_id'])->where('jti', $row['device_jti'])->where('status', 'enabled')->whereNull('deleted_at')->exists();
        if (!$account || (int) $account->user_id !== (int) $row['user_id'] || !$device || Redis::exists('jwt_blacklist:' . $row['school_id'] . ':' . $row['device_jti'])) throw new RuntimeException('电脑登录已失效，请重新创建签名会话', 403);
    }

    private function present(array $row): array
    {
        $file = FileRecord::detailById((int) $row['file_id']);
        if (!$file) throw new RuntimeException('签名文件不可用', 409);
        return ['id' => (int) $row['id'], 'file_id' => (int) $row['file_id'], 'version' => (int) $row['version'], 'url' => (string) $file->url, 'width' => (int) $row['width'], 'height' => (int) $row['height'], 'created_at' => $row['created_at']];
    }

    private function normalize(UploadFile $file): string
    {
        if (!function_exists('imagecreatefrompng')) throw new RuntimeException('服务器缺少 PNG 图像处理支持', 503);
        if (!$file->isValid() || $file->getSize() > 2097152) throw new RuntimeException('请上传不超过 2MB 的 PNG 签名', 422);
        $source = $file->getPathname();
        $size = @getimagesize($source);
        if (!$size || $size[2] !== IMAGETYPE_PNG || $size[0] > 2048 || $size[1] > 2048 || $size[0] * $size[1] > 2097152) throw new RuntimeException('签名必须是有效 PNG，尺寸不超过 2048 且像素不超过 2097152', 422);
        $image = @imagecreatefrompng($source);
        if (!$image) throw new RuntimeException('PNG 图像损坏', 422);
        $output = null;
        try {
            if (!imageistruecolor($image)) imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $white = imagecolorallocatealpha($image, 0, 0, 0, 127);
            $minX = $size[0]; $minY = $size[1]; $maxX = -1; $maxY = -1; $ink = 0;
            for ($y = 0; $y < $size[1]; $y++) {
                for ($x = 0; $x < $size[0]; $x++) {
                    $pixel = imagecolorat($image, $x, $y);
                    $alpha = ($pixel >> 24) & 127;
                    $light = min(($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255);
                    if ($alpha >= 120 || $light >= 245) { imagesetpixel($image, $x, $y, $white); continue; }
                    $minX = min($minX, $x); $minY = min($minY, $y); $maxX = max($maxX, $x); $maxY = max($maxY, $y); $ink++;
                }
            }
            if ($ink < 20 || $maxX - $minX < 5 || $maxY - $minY < 3) throw new RuntimeException('签名为空白或笔迹不足，请重新书写', 422);
            $width = $maxX - $minX + 17; $height = $maxY - $minY + 17;
            $output = imagecreatetruecolor($width, $height);
            imagealphablending($output, false); imagesavealpha($output, true);
            imagefill($output, 0, 0, imagecolorallocatealpha($output, 0, 0, 0, 127));
            imagecopy($output, $image, 8, 8, $minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1);
            $path = tempnam(sys_get_temp_dir(), 'signature-');
            if (!$path) throw new RuntimeException('无法创建签名临时文件');
            if (!imagepng($output, $path)) { unlink($path); throw new RuntimeException('签名保存失败'); }
            return $path;
        } finally {
            imagedestroy($image);
            if ($output) imagedestroy($output);
        }
    }
}
