<?php

namespace app\server\security;

use GuzzleHttp\Client;
use InvalidArgumentException;
use RuntimeException;
use support\Redis;
use Throwable;

/** 短信验证码发送和一次性校验。 */
class SmsCodeService
{
    private const CODE_TTL = 300;
    private const SEND_INTERVAL = 60;

    /** 发送验证码并只返回过期时间，不返回验证码。 */
    public function send(string $scene, string $mobile): array
    {
        $mobile = $this->mobile($mobile);
        $key = $this->key($scene, $mobile);
        $code = (string) random_int(100000, 999999);
        $gateway = trim((string) ($_ENV['SMS_GATEWAY_URL'] ?? getenv('SMS_GATEWAY_URL') ?: ''));
        if ($gateway === '') {
            throw new RuntimeException('短信服务未配置，请先配置 SMS_GATEWAY_URL', 503);
        }
        $interval = $this->intervalKey($scene, $mobile);
        $reservation = bin2hex(random_bytes(16));
        if (!Redis::set($interval, $reservation, 'EX', self::SEND_INTERVAL, 'NX')) {
            throw new InvalidArgumentException('验证码发送过于频繁，请稍后再试', 429);
        }
        $client = new Client(['timeout' => 8, 'http_errors' => false]);
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        $token = trim((string) ($_ENV['SMS_GATEWAY_TOKEN'] ?? getenv('SMS_GATEWAY_TOKEN') ?: ''));
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        try {
            $response = $client->post($gateway, [
                'headers' => $headers,
                'json' => ['mobile' => $mobile, 'code' => $code, 'scene' => $scene],
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException('短信发送失败', 503);
            }
        } catch (Throwable $exception) {
            Redis::eval("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end", 1, $interval, $reservation);
            throw new RuntimeException('短信发送失败', 503, $exception);
        }

        Redis::eval("redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2]); redis.call('DEL', KEYS[2]); return 1", 2,
            $key, $this->attemptKey($scene, $mobile), self::CODE_TTL, password_hash($code, PASSWORD_BCRYPT));

        return ['mobile' => $this->mask($mobile), 'expires_in' => self::CODE_TTL];
    }

    /** 校验验证码并清理验证码。 */
    public function verify(string $scene, string $mobile, string $code): bool
    {
        $mobile = $this->mobile($mobile);
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = $this->key($scene, $mobile);
        $attemptKey = $this->attemptKey($scene, $mobile);
        $hash = (string) (Redis::eval("local hash = redis.call('GET', KEYS[1]); if not hash then return '' end; local attempts = redis.call('INCR', KEYS[2]); if attempts == 1 then redis.call('EXPIRE', KEYS[2], ARGV[1]) end; if attempts > 5 then return '' end; return hash", 2, $key, $attemptKey, self::CODE_TTL) ?: '');
        if ($hash === '' || !password_verify($code, $hash)) {
            return false;
        }
        return (int) Redis::eval("if redis.call('GET', KEYS[1]) == ARGV[1] then redis.call('DEL', KEYS[1], KEYS[2]); return 1 end; return 0", 2, $key, $attemptKey, $hash) === 1;
    }

    /** 标准化并校验手机号。 */
    public function mobile(string $mobile): string
    {
        $mobile = trim($mobile);
        if (!preg_match('/^1\d{10}$/', $mobile)) {
            throw new InvalidArgumentException('手机号格式不正确');
        }
        return $mobile;
    }

    private function key(string $scene, string $mobile): string
    {
        return 'practical:sms:' . $scene . ':' . hash('sha256', $mobile);
    }

    private function intervalKey(string $scene, string $mobile): string
    {
        return 'practical:sms:interval:' . $scene . ':' . hash('sha256', $mobile);
    }

    /** 生成验证码错误次数键。 */
    private function attemptKey(string $scene, string $mobile): string
    {
        return 'practical:sms:attempts:' . $scene . ':' . hash('sha256', $mobile);
    }

    private function mask(string $mobile): string
    {
        return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
    }
}
