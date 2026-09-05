<?php

namespace app\server;

use RuntimeException;
use Throwable;

class WorkflowLock
{
    /** 在 Redis 互斥锁内执行回调，严格模式透传 Redis 异常 */
    public function run(string $key, callable $callback, int $ttl = 15, bool $strict = false): mixed
    {
        $token = $this->acquire($key, $ttl, $strict);
        if ($token === null) {
            throw new RuntimeException('数据已变更，请刷新后重试', 409);
        }

        $callbackFailed = false;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $callbackFailed = true;
            throw $exception;
        } finally {
            $this->release($key, $token, $strict && !$callbackFailed);
        }
    }

    /**
     * 每个锁周期只执行一次，成功后保留锁至过期。
     */
    public function runOnce(string $key, callable $callback, int $ttl): mixed
    {
        $token = $this->acquire($key, $ttl, true);
        if ($token === null) {
            throw new RuntimeException('定时任务本周期已执行或正在执行', 409);
        }

        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->release($key, $token);
            throw $exception;
        }
    }

    /** 生成学校业务实体锁键 */
    public static function key(string $module, string $entity, int $id): string
    {
        return implode(':', [
            'workflow_lock',
            CurrentContext::schoolDatabaseId() ?: 'school',
            $module,
            $entity,
            $id,
        ]);
    }

    /** 获取带令牌的 Redis 锁 */
    private function acquire(string $key, int $ttl, bool $strict = false): ?string
    {
        $token = bin2hex(random_bytes(12));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                if ($this->redisCommand(['SET', $key, $token, 'NX', 'EX', (string) max(1, $ttl)]) === 'OK') {
                    return $token;
                }
            } catch (Throwable $exception) {
                if ($strict) {
                    throw $exception;
                }
                return null;
            }
            usleep(100000);
        }

        return null;
    }

    /** 原子释放当前令牌持有的锁 */
    private function release(string $key, string $token, bool $strict = false): void
    {
        try {
            $this->redisCommand([
                'EVAL',
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end",
                '1',
                $key,
                $token,
            ]);
        } catch (Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
        }
    }

    /** 执行单条 Redis 命令 */
    private function redisCommand(array $parts): mixed
    {
        [$socket, $password, $database] = $this->redisSocket();
        try {
            if ($password !== '') {
                $this->redisWrite($socket, ['AUTH', $password]);
            }
            if ($database > 0) {
                $this->redisWrite($socket, ['SELECT', (string) $database]);
            }

            return $this->redisWrite($socket, $parts);
        } finally {
            fclose($socket);
        }
    }

    /** 创建并认证 Redis 短连接 */
    private function redisSocket(): array
    {
        $config = (array) config('redis.default', []);
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $address = str_starts_with($host, 'redis://') || str_starts_with($host, 'tcp://')
            ? $host
            : "tcp://{$host}:{$port}";

        $socket = @stream_socket_client($address, $errno, $message, 1.5);
        if (!$socket) {
            throw new RuntimeException($message ?: 'Redis 连接失败');
        }
        stream_set_timeout($socket, 2);

        return [
            $socket,
            (string) ($config['password'] ?? ''),
            (int) ($config['database'] ?? 0),
        ];
    }

    /** 写入 Redis 命令并读取响应 */
    private function redisWrite(mixed $socket, array $parts): mixed
    {
        fwrite($socket, $this->redisEncode($parts));
        return $this->redisRead($socket);
    }

    /** 编码 Redis RESP 命令 */
    private function redisEncode(array $parts): string
    {
        $command = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $part = (string) $part;
            $command .= '$' . strlen($part) . "\r\n{$part}\r\n";
        }

        return $command;
    }

    /** 解析 Redis RESP 响应 */
    private function redisRead(mixed $socket): mixed
    {
        $line = fgets($socket);
        if ($line === false || $line === '') {
            throw new RuntimeException('Redis 响应无效');
        }

        $type = $line[0];
        $payload = substr($line, 1, -2);
        if ($type === '+') {
            return $payload;
        }
        if ($type === '-') {
            throw new RuntimeException($payload);
        }
        if ($type === ':') {
            return (int) $payload;
        }
        if ($type === '$') {
            $length = (int) $payload;
            if ($length < 0) {
                return null;
            }
            $data = '';
            while (strlen($data) < $length + 2) {
                $chunk = fread($socket, $length + 2 - strlen($data));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Redis 响应读取失败');
                }
                $data .= $chunk;
            }

            return substr($data, 0, $length);
        }
        if ($type === '*') {
            $items = [];
            for ($index = 0; $index < (int) $payload; $index++) {
                $items[] = $this->redisRead($socket);
            }

            return $items;
        }

        throw new RuntimeException('Redis 响应类型无效');
    }
}
