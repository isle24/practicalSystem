<?php

namespace app\model\channel;

use app\server\CurrentContext;
use RuntimeException;

class WorkflowRecord extends TableRecord
{
    private const TABLES = ['definition', 'version', 'node', 'instance', 'task', 'history', 'outbox'];

    public static function q(string $resource): mixed
    {
        if (!in_array($resource, self::TABLES, true)) throw new RuntimeException('流程资源无效', 422);
        if (!CurrentContext::schoolDatabaseId() || !CurrentContext::schoolDatabase()) throw new RuntimeException('学校上下文无效', 403);
        return self::queryTable('workflow_' . $resource);
    }

    public static function row(mixed $row): array
    {
        if (!$row) return [];
        $values = is_array($row) ? $row : $row->getAttributes();
        foreach ($values as $key => $value) {
            if (str_ends_with($key, '_json') && is_string($value)) $values[$key] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        return $values;
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function actor(): int
    {
        $id = (int) CurrentContext::accountId();
        if (!$id || !CurrentContext::schoolDatabaseId() || !CurrentContext::schoolDatabase()) throw new RuntimeException('登录或学校上下文无效', 401);
        return $id;
    }
}
