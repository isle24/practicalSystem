<?php

namespace app\server\security;

use RuntimeException;

class SecretCipher
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /** 加密同步密钥 */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('教师同步密钥加密失败');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /** 解密同步密钥 */
    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }
        if (!str_starts_with($encrypted, self::PREFIX)) {
            throw new RuntimeException('教师同步密钥未加密或格式无效');
        }

        $payload = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('教师同步密钥密文无效');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('教师同步密钥解密失败');
        }

        return $plaintext;
    }

    /** 返回密钥掩码 */
    public static function mask(string $value): string
    {
        return $value === '' ? '' : '******';
    }

    /** 读取并派生加密密钥 */
    private function key(): string
    {
        $value = (string) ($_ENV['TEACHER_SYNC_ENCRYPTION_KEY'] ?? getenv('TEACHER_SYNC_ENCRYPTION_KEY') ?: '');
        if (trim($value) === '') {
            throw new RuntimeException('未配置 TEACHER_SYNC_ENCRYPTION_KEY');
        }

        return hash('sha256', $value, true);
    }
}
