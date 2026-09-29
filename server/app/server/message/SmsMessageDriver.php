<?php

namespace app\server\message;

use InvalidArgumentException;
use RuntimeException;

class SmsMessageDriver
{
    public static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) throw new InvalidArgumentException('短信网关必须使用无凭据、无查询参数的 HTTPS 地址');
        $host = $parts['host'] ?? '';
        if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^[a-zA-Z0-9.-]+$/', $host) || preg_match('/(?:\.local|\.internal|\.localhost)$/i', $host)) throw new InvalidArgumentException('短信网关必须使用公共域名');
    }

    public function send(array $config, string $mobile, string $content, string $key): void
    {
        self::validateUrl($config['sms_url']);
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) throw new InvalidArgumentException('接收手机号无效');
        $host = parse_url($config['sms_url'], PHP_URL_HOST);
        $addresses = gethostbynamel($host) ?: [];
        if (!$addresses) throw new RuntimeException('短信网关域名解析失败');
        foreach ($addresses as $address) if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('短信网关地址不允许访问');
        $handle = curl_init($config['sms_url']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]], CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['sms_token'], 'Idempotency-Key: ' . $key],
            CURLOPT_POSTFIELDS => json_encode(['mobile' => $mobile, 'content' => mb_substr($content, 0, 1000), 'sender' => $config['sms_sender'], 'idempotency_key' => $key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $response = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($response) || ($response['success'] ?? null) !== true || ($response['idempotency_key'] ?? '') !== $key || !is_string($response['message_id'] ?? null) || $response['message_id'] === '') throw new RuntimeException('短信网关未确认成功，HTTP状态：' . $status);
    }
}
