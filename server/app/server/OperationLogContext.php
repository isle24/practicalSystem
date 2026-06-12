<?php

namespace app\server;

use support\Context;

class OperationLogContext
{
    private const NAME_KEY = 'operation_log.name';

    /**
     * 设置当前请求的操作名称。
     */
    public static function setName(string $name): void
    {
        $name = trim($name);
        if ($name !== '') {
            Context::set(self::NAME_KEY, mb_substr($name, 0, 120));
        }
    }

    /**
     * 获取当前请求的操作名称。
     */
    public static function name(): ?string
    {
        $name = Context::get(self::NAME_KEY);
        return is_string($name) && $name !== '' ? $name : null;
    }
}
