<?php

namespace app\process;

use app\model\channel\WechatMessageRecord;
use app\model\system\Database;
use app\server\school\SchoolConnectionManager;
use support\Context;
use support\Log;
use support\Redis;
use Throwable;
use Webman\RedisQueue\Redis as RedisQueue;
use Workerman\Timer;

class WechatMessageRelay
{
    private array $schools = [];
    private int $lastErrorAt = 0;

    public function onWorkerStart(): void
    {
        $this->reloadSchools();
        Timer::add(60, fn () => $this->reloadSchools());
        Timer::add(5, fn () => $this->relay());
    }

    private function reloadSchools(): void
    {
        try { $this->schools = Database::enabledConnectionConfigs(); }
        catch (Throwable) { $this->logError(); }
    }

    private function relay(): void
    {
        foreach ($this->schools as $school) {
            Context::reset();
            try {
                $id = (int) $school['database_id'];
                (new SchoolConnectionManager())->ensureConnection($id, $school);
                foreach (WechatMessageRecord::pendingIds() as $logId) {
                    $key = 'wechat:dispatch:' . $id . ':' . (int) $logId;
                    if (!Redis::set($key, '1', 'EX', 60, 'NX')) continue;
                    RedisQueue::send('wechat-message', ['database_id' => $id, 'log_id' => (int) $logId]);
                }
            } catch (Throwable) { $this->logError(); }
            finally { Context::reset(); }
        }
    }

    private function logError(): void
    {
        if (time() - $this->lastErrorAt < 60) return;
        $this->lastErrorAt = time();
        Log::error('企业微信消息转发失败，持久化任务将继续重试');
    }
}
