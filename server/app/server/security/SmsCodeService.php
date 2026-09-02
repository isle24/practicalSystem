<?php

namespace app\server\security;

use GuzzleHttp\Client;
use InvalidArgumentException;
use RuntimeException;
use support\Redis;
use Throwable;

/** 企业评价短信验证码发送和校验辅助服务。 */
class SmsCodeService
{
    private const CODE_TTL = 300;
    private const SEND_INTERVAL = 60;

    /** 发送验证码并只返回过期时间，不返回验证码。 */
    public function send(string $scene, string $mobile): array
    {
        $mobile = $this->mobile($mobile);
        $key = $this->key($scene, $mobile);
        if (Redis::exists($this->intervalKey($scene, $mobile))) {
            throw new InvalidArgumentException('验证码发送过于频繁，请稍后再试', 429);
        }

        $code = (string) random_int(100000, 999999);
        $gateway = trim((string) ($_ENV['SMS_GATEWAY_URL'] ?? getenv('SMS_GATEWAY_URL') ?: ''));
        if ($gateway === '') {
            throw new RuntimeException('短信服务未配置，请先配置 SMS_GATEWAY_URL', 503);
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
        } catch (Throwable $exception) {
            throw new RuntimeException('短信发送失败', 503, $exception);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('短信发送失败', 503);
        }

        Redis::setex($key, self::CODE_TTL, password_hash($code, PASSWORD_BCRYPT));
        Redis::del($this->attemptKey($scene, $mobile));
        Redis::setex($this->intervalKey($scene, $mobile), self::SEND_INTERVAL, '1');

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
        $hash = (string) (Redis::get($key) ?: '');
        $attemptKey = $this->attemptKey($scene, $mobile);
        $attempts = (int) (Redis::get($attemptKey) ?: 0);
        if ($attempts >= 5) {
            return false;
        }
        if ($hash === '' || !password_verify($code, $hash)) {
            Redis::setex($attemptKey, self::CODE_TTL, (string) ($attempts + 1));
            return false;
        }
        Redis::del($key);
        Redis::del($attemptKey);
        return true;
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
