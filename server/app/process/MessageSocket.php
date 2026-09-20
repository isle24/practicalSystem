<?php

namespace app\process;

use app\server\message\MessageRealtimeService;
use support\Redis;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Redis\Client;
use Workerman\Timer;
use Workerman\Worker;

/** 按学校和账号隔离的消息刷新通道。 */
class MessageSocket
{
    private array $sessions = [];
    private ?Client $subscriber = null;

    /** 订阅跨进程消息并检查连接寿命。 */
    public function onWorkerStart(Worker $worker): void
    {
        $config = config('redis.default');
        $this->subscriber = new Client('redis://' . $config['host'] . ':' . $config['port']);
        if (!empty($config['password'])) $this->subscriber->auth($config['password']);
        if (!empty($config['database'])) $this->subscriber->select($config['database']);
        $this->subscriber->subscribe(config('message_realtime.channel'), function ($channel, $raw): void {
            $event = json_decode($raw, true);
            if (!is_array($event)) return;
            $targets = array_fill_keys($event['accounts'] ?? [], true);
            foreach ($this->sessions as $session) {
                if (($session['school'] ?? 0) !== (int) ($event['school'] ?? 0) || !isset($targets[$session['account'] ?? 0])) continue;
                $session['connection']->send(json_encode(['type' => 'invalidate', 'topic' => $event['topic'] ?? 'messages']));
            }
        });
        Timer::add(15, function (): void {
            foreach ($this->sessions as $session) {
                if (time() > $session['expires'] || time() - $session['seen'] > 65) $session['connection']->close();
            }
        });
    }

    /** 限制未认证连接及包体大小。 */
    public function onConnect(TcpConnection $connection): void
    {
        $connection->maxPackageSize = 4096;
        $connection->maxSendBufferSize = 65536;
        $this->sessions[$connection->id] = ['connection' => $connection, 'expires' => time() + 10, 'seen' => time()];
    }

    /** 接收授权帧和心跳，不接受业务写入指令。 */
    public function onMessage(TcpConnection $connection, $raw): void
    {
        try {
            $data = json_decode((string) $raw, true);
            $session = $this->sessions[$connection->id] ?? [];
            if (!isset($session['account'])) {
                $identity = MessageRealtimeService::consume((string) ($data['ticket'] ?? ''));
                if (!$identity || $this->revoked($identity)) { $connection->close(); return; }
                $this->sessions[$connection->id] = $session + $identity;
                $this->sessions[$connection->id]['expires'] = time() + 300;
                $connection->send('{"type":"ready"}');
                return;
            }
            if (($data['type'] ?? '') !== 'ping' || $this->revoked($session)) { $connection->close(); return; }
            $this->sessions[$connection->id]['seen'] = time();
            $connection->send('{"type":"pong"}');
        } catch (Throwable) { $connection->close(); }
    }

    /** 清理断开连接。 */
    public function onClose(TcpConnection $connection): void
    {
        unset($this->sessions[$connection->id]);
    }

    /** Redis 不可用时关闭通道，HTTP 消息仍可使用。 */
    private function revoked(array $session): bool
    {
        return !empty($session['jti']) && (int) Redis::exists('jwt_blacklist:' . $session['school'] . ':' . $session['jti']) > 0;
    }
}
