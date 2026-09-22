<?php

namespace app\model\system;

use app\server\CurrentContext;
use app\server\database\SchemaMetadata;
use RuntimeException;
use support\Db;

class DatabaseSchemaRecord
{
    public static function inspect(string $target): array
    {
        $connection = Db::connection($target === 'master' ? 'master' : CurrentContext::schoolConnection());
        if ((string) $connection->getConfig('prefix') !== '') {
            throw new RuntimeException('当前结构基准不支持带表前缀的数据库', 409);
        }
        $pdo = $connection->getPdo();
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '' || ($target === 'school' && $database !== CurrentContext::schoolDatabase())) {
            throw new RuntimeException('当前学校数据库连接不匹配，请重新登录', 409);
        }
        return ['database' => $database, 'tables' => SchemaMetadata::read($pdo)];
    }
}
