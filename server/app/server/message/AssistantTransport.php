<?php

namespace app\server\message;

use RuntimeException;

/** 受限的兼容 Chat Completions JSON 传输。 */
class AssistantTransport
{
    /** 限定 HTTPS 公网域名，不要求配置域名白名单。 */
    public static function validateEndpoint(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            || preg_match('/[\x00-\x20\\\\]/', $url) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts ?: []))
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('接口须使用 HTTPS 公网域名和 443 端口，不能包含账号、查询参数或片段', 400);
        }
        $addresses = gethostbynamel($host) ?: [];
        if (!$addresses) throw new RuntimeException('助手接口域名无法解析', 400);
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)
                || (int) explode('.', $ip)[0] >= 224) throw new RuntimeException('助手接口不能指向内网或保留地址', 400);
        }
        return [$host, $addresses[0]];
    }

    /** 固定 DNS 解析、防止跳转泄露密钥，并限制响应体。 */
    public function answer(string $url, string $key, string $model, array $messages): string
    {
        [$host, $ip] = self::validateEndpoint($url);
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'messages' => $messages, 'stream' => false, 'max_tokens' => 2048], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => ["{$host}:443:{$ip}"], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally { curl_close($curl); }
        if (!$ok || $status !== 200) throw new RuntimeException('助手服务暂时无法回答，请稍后重试');
        $data = json_decode($body, true);
        $answer = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($answer) || trim($answer) === '') throw new RuntimeException('助手未返回有效回答，请稍后重试');
        return mb_substr($answer, 0, 30000);
    }
}
