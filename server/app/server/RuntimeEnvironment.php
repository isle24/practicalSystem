<?php

namespace app\server;

class RuntimeEnvironment
{
    /** 获取当前运行模式 */
    public static function mode(): string
    {
        $value = $_ENV['APP_MODE'] ?? getenv('APP_MODE');
        $mode = strtolower(trim((string) ($value === false ? '' : $value)));

        return $mode !== '' ? $mode : 'production';
    }

    /** 判断是否为测试环境 */
    public static function isTest(): bool
    {
        return self::mode() === 'test';
    }
}
