<?php

namespace app\queue\redis;

use app\server\school\SchoolConnectionManager;
use app\server\wechat\WechatMessageService;
use support\Context;
use Throwable;
use Webman\RedisQueue\Consumer;

class WechatMessageConsumer implements Consumer
{
    public string $queue = 'wechat-message';
    public string $connection = 'default';

    public function consume($data): void
    {
        Context::reset();
        try {
            (new SchoolConnectionManager())->bootstrapById((int) ($data['database_id'] ?? 0));
            (new WechatMessageService())->send((int) ($data['log_id'] ?? 0));
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            Context::reset();
        }
    }
}
