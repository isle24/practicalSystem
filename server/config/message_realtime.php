<?php

return [
    'listen' => $_ENV['MESSAGE_WS_LISTEN'] ?? getenv('MESSAGE_WS_LISTEN') ?: 'websocket://127.0.0.1:8788',
    'path' => '/ws/messages',
    'channel' => 'practical:message:invalidate:' . ($_ENV['REDIS_DB'] ?? getenv('REDIS_DB') ?: 0),
];
