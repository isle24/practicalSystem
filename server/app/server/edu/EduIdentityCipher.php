<?php

namespace app\server\edu;

use InvalidArgumentException;
use RuntimeException;

class EduIdentityCipher
{
    private const PREFIX = 'edu:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function encryptSensitive(array $payload): string
    {
        if (!$payload) {
            return '';
        }

        $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('教务敏感数据加密失败');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decryptSensitive(string $ciphertext): array
    {
        if ($ciphertext === '') {
            return [];
        }
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            throw new RuntimeException('教务敏感数据格式无效');
        }

        $payload = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('教务敏感数据密文无效');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $encrypted = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);
        $plaintext = openssl_decrypt($encrypted, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plaintext)) {
            throw new RuntimeException('教务敏感数据解密失败');
        }

        $decoded = json_decode($plaintext, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('教务敏感数据内容无效');
        }
        return $decoded;
    }

    public function mobileHash(string $mobile): string
    {
        $mobile = preg_replace('/\D+/', '', trim($mobile)) ?: '';
        if ($mobile === '') {
            return '';
        }
        return hash_hmac('sha256', $mobile, $this->hmacKey());
    }

    public function identityLastSixHash(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/', '', $value) ?: '';
        if ($value === '' || strlen($value) !== 6) {
            throw new InvalidArgumentException('身份证后 6 位格式不正确');
        }
        return hash_hmac('sha256', $value, $this->hmacKey());
    }

    private function key(): string
    {
        $value = trim((string) ($_ENV['EDU_IDENTITY_ENCRYPTION_KEY'] ?? getenv('EDU_IDENTITY_ENCRYPTION_KEY') ?: ''));
        if ($value === '') {
            throw new RuntimeException('未配置 EDU_IDENTITY_ENCRYPTION_KEY');
        }
        return hash('sha256', $value . ':cipher', true);
    }

    private function hmacKey(): string
    {
        $value = trim((string) ($_ENV['EDU_IDENTITY_ENCRYPTION_KEY'] ?? getenv('EDU_IDENTITY_ENCRYPTION_KEY') ?: ''));
        if ($value === '') {
            throw new RuntimeException('未配置 EDU_IDENTITY_ENCRYPTION_KEY');
        }
        return hash('sha256', $value . ':hmac', true);
    }
}
