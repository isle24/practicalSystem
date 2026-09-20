<?php

namespace app\server\release;

use app\model\channel\FileRelation;
use app\model\channel\ReleaseRecord;
use app\server\CurrentContext;
use app\server\file\FileService;
use RuntimeException;
use support\Redis;
use Webman\Http\UploadFile;

/** 按既有 GitHub 清单接收分片安装包。 */
class ReleaseUploadService
{
    private const CHUNK_SIZE = 2097152;

    /** 创建账号、学校和资产绑定的短期上传会话。 */
    public function begin(int $assetId): array
    {
        (new ReleaseService())->assertAdmin();
        $asset = ReleaseRecord::asset($assetId);
        if (!$asset || $asset['status'] === 'ready') throw new RuntimeException('安装包不存在或已经就绪', 409);
        if ($asset['size'] <= 0 || $asset['size'] > config('desktop_release.max_asset_size')) throw new RuntimeException('安装包大小超出限制', 400);
        $key = 'release-upload-rate:' . CurrentContext::schoolDatabaseId() . ':' . CurrentContext::accountId();
        $count = (int) Redis::eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],3600) end; return n", 1, $key);
        if ($count > 12) throw new RuntimeException('上传过于频繁，请稍后重试', 429);
        $id = bin2hex(random_bytes(24));
        Redis::setEx($this->key($id), 7200, json_encode(['asset_id' => $assetId, 'size' => (int) $asset['size']]));
        return ['upload_id' => $id, 'chunk_size' => self::CHUNK_SIZE, 'size' => (int) $asset['size']];
    }

    /** 顺序写入分片；重复分片只在内容一致时接受。 */
    public function chunk(string $id, int $offset, ?UploadFile $file): array
    {
        $session = $this->session($id);
        if (!$file || !$file->isValid() || $file->getSize() <= 0 || $file->getSize() > self::CHUNK_SIZE || $offset < 0 || $offset + $file->getSize() > $session['size']) throw new RuntimeException('上传分片无效', 400);
        $path = $this->path($id);
        $handle = fopen($path, 'c+b');
        if (!$handle) throw new RuntimeException('无法保存上传文件', 503);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('上传文件被占用', 409);
            $size = (int) fstat($handle)['size'];
            $bytes = file_get_contents($file->getRealPath());
            if ($offset !== $size) {
                fseek($handle, $offset);
                if ($offset > $size || fread($handle, strlen($bytes)) !== $bytes) throw new RuntimeException('分片位置不一致，请重新上传', 409);
            } else {
                fseek($handle, $size);
                if (fwrite($handle, $bytes) !== strlen($bytes)) { ftruncate($handle, $size); throw new RuntimeException('保存分片失败', 503); }
                fflush($handle);
                $size += strlen($bytes);
            }
            touch($path);
            return ['offset' => $size];
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** 完整大小和哈希一致后才开放资产。 */
    public function finish(string $id): array
    {
        $session = $this->session($id);
        $path = $this->path($id);
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if (!$handle) throw new RuntimeException('上传文件不存在，请重新上传', 404);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('上传仍在处理中', 409);
            $asset = ReleaseRecord::asset($session['asset_id']);
            if (!$asset || filesize($path) !== (int) $asset['size'] || !hash_equals($asset['sha256'], hash_file('sha256', $path))) throw new RuntimeException('大小或 SHA256 与版本清单不一致，已拒绝入库', 400);
            ReleaseRecord::connection()->transaction(function () use ($asset, $path): void {
                $locked = ReleaseRecord::lockedAsset($asset['id']);
                if ($locked['status'] === 'ready') return;
                $stored = (new FileService())->storeGeneratedFile($path, ['name' => $asset['file_name'], 'ext' => pathinfo($asset['file_name'], PATHINFO_EXTENSION), 'category' => 'desktop_release', 'is_temporary' => false, 'uploader_id' => CurrentContext::accountId()]);
                ReleaseRecord::completeUpload($asset['id'], $stored['file_id']);
                FileRelation::createRelation($stored['file_id'], 'system_release', $asset['release_id'], 'package', date('Y-m-d H:i:s'));
            });
        } finally { flock($handle, LOCK_UN); fclose($handle); }
        unlink($path);
        Redis::del($this->key($id));
        return (new ReleaseService())->detail($asset['release_id']);
    }

    /** 校验上传归属和有效期。 */
    private function session(string $id): array
    {
        (new ReleaseService())->assertAdmin();
        if (!preg_match('/^[a-f0-9]{48}$/', $id)) throw new RuntimeException('上传标识无效', 400);
        $session = json_decode(Redis::get($this->key($id)) ?: 'null', true);
        if (!is_array($session)) throw new RuntimeException('上传已过期，请重新选择文件', 409);
        return $session;
    }

    /** 生成学校账号隔离的会话键。 */
    private function key(string $id): string
    {
        return 'release-upload:' . CurrentContext::schoolDatabaseId() . ':' . CurrentContext::accountId() . ':' . $id;
    }

    /** 临时上传路径不接受用户文件名。 */
    private function path(string $id): string
    {
        $directory = runtime_path('desktop-uploads');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('无法创建上传目录', 503);
        return $directory . '/' . $id . '.part';
    }

    /** 清理超过会话有效期的安装包分片。 */
    public static function cleanup(): void
    {
        foreach (glob(runtime_path('desktop-uploads/*.part')) ?: [] as $path) {
            if (!preg_match('/^[a-f0-9]{48}\.part$/', basename($path)) || filemtime($path) > time() - 10800) continue;
            $handle = fopen($path, 'rb');
            if (!$handle) continue;
            if (flock($handle, LOCK_EX | LOCK_NB)) { unlink($path); flock($handle, LOCK_UN); }
            fclose($handle);
        }
    }
}
