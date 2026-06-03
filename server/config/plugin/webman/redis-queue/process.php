<?php

$consumerCount = max(1, (int) (getenv('REDIS_QUEUE_CONSUMER_COUNT') ?: 8));

return [
    'consumer'  => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => $consumerCount,
        'constructor' => [
            // 消费者类目录
            'consumer_dir' => app_path() . '/queue/redis'
        ]
    ]
];
