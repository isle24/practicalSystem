<?php

namespace app\queue\redis;

use app\model\channel\ReleaseRecord;
use app\model\channel\FileRelation;
use app\server\release\GithubReleaseService;
use app\server\file\FileService;
use app\server\school\SchoolConnectionManager;
use Webman\RedisQueue\Consumer;
use Throwable;

/** 按学校下载并校验客户端资产。 */
class DesktopReleaseConsumer implements Consumer
{
    public string $queue = 'desktop-release-download';
    public string $connection = 'default';

    /** 下载完成后才写入正式文件引用。 */
    public function consume($data): void
    {
        \support\Context::reset();
        (new SchoolConnectionManager())->bootstrapById((int) ($data['database_id'] ?? 0));
        $id = (int) ($data['asset_id'] ?? 0);
        $token = bin2hex(random_bytes(16));
        if (!ReleaseRecord::claimAsset($id, $token)) return;
        $asset = ReleaseRecord::asset($id);
        $release = ReleaseRecord::detail($asset['release_id']);
        $path = tempnam(runtime_path(), 'desktop-asset-');
        try {
            (new GithubReleaseService())->download($asset['source_asset_id'], $path, $asset['size']);
            if (filesize($path) !== (int) $asset['size'] || hash_file('sha256', $path) !== $asset['sha256']) throw new \RuntimeException('安装包大小或 SHA256 不一致，已拒绝入库');
            ReleaseRecord::connection()->transaction(function () use ($id, $token, $path, $asset, $release): void {
                $locked = ReleaseRecord::lockedAsset($id);
                if (!$locked || $locked['status'] !== 'downloading' || $locked['claim_token'] !== $token) return;
                $stored = (new FileService())->storeGeneratedFile($path, ['name' => $asset['file_name'], 'ext' => pathinfo($asset['file_name'], PATHINFO_EXTENSION),
                    'category' => 'desktop_release', 'is_temporary' => false, 'uploader_id' => $release['created_by']]);
                if (!ReleaseRecord::finishAsset($id, $token, ['status' => 'ready', 'file_id' => $stored['file_id'], 'error_message' => null])) throw new \RuntimeException('下载任务已失去执行权');
                FileRelation::createRelation($stored['file_id'], 'system_release', $release['id'], 'package', date('Y-m-d H:i:s'));
            });
        } catch (Throwable $e) {
            ReleaseRecord::finishAsset($id, $token, ['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
        } finally { if ($path && is_file($path)) unlink($path); }
    }
}
