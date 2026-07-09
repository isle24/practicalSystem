<?php

namespace app\middleware;

use app\server\CurrentContext;
use support\Redis;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class LoginThrottleMiddleware implements MiddlewareInterface
{
    /**
     * 仅拦截登录相关路由。
     */
    private const GUARDED_PATHS = [
        '/api/auth/login',
    ];

    /**
     * 限流维度：[键模板, 时间窗口秒, 阈值]。
     */
    private const DIMENSIONS = [
        ['ip_account', 300, 5],
        ['ip', 60, 20],
        ['account', 300, 10],
    ];

    /**
     * INCR + EXPIRE 原子执行，避免进程中断导致键永不过期。
     */
    private const INCR_LUA = "local c = redis.call('INCR', KEYS[1]) if c == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end return c";

    /**
     * 登录失败累计限流，达到阈值返回 429。
     */
    public function process(Request $request, callable $handler): Response
    {
        if (!$this->guarded($request)) {
            return $handler($request);
        }

        $ip = (string) $request->getRealIp();
        $account = trim((string) ($request->input('login_name') ?? $request->input('username', '')));
        $keys = $this->keys($ip, $account);

        try {
            foreach (self::DIMENSIONS as $index => [, , $limit]) {
                if ((int) Redis::get($keys[$index]) >= $limit) {
                    return json(['code' => 42900, 'message' => '登录尝试过于频繁，请稍后重试', 'data' => null])->withStatus(429);
                }
            }
        } catch (Throwable) {
        }

        $response = $handler($request);

        $this->track($response, $keys);

        return $response;
    }

    /**
     * 依据登录结果累计或清除失败计数。
     */
    private function track(Response $response, array $keys): void
    {
        $payload = json_decode((string) $response->rawBody(), true);
        $success = is_array($payload) && (int) ($payload['code'] ?? -1) === 0;

        try {
            if ($success) {
                Redis::del($keys[0]);
                return;
            }

            foreach (self::DIMENSIONS as $index => [, $ttl]) {
                Redis::eval(self::INCR_LUA, 1, $keys[$index], $ttl);
            }
        } catch (Throwable) {
        }
    }

    /**
     * 生成三个限流维度的缓存键（按学校库隔离）。
     */
    private function keys(string $ip, string $account): array
    {
        $databaseId = CurrentContext::schoolDatabaseId() ?: 0;
        $account = $account !== '' ? $account : '-';

        return [
            "login_attempt:{$databaseId}:{$ip}:{$account}",
            "login_attempt:{$databaseId}:{$ip}:*",
            "login_attempt:{$databaseId}:*:{$account}",
        ];
    }

    /**
     * 判断当前请求是否为受限登录路由。
     */
    private function guarded(Request $request): bool
    {
        $path = '/' . trim($request->path(), '/');
        return in_array($path, self::GUARDED_PATHS, true);
    }
}
