<?php

namespace app\model\channel;

use PDO;
use RuntimeException;

class FileStorageSchema extends TableRecord
{
    public static function apply(): void
    {
        if (self::connection()->getTablePrefix() !== '' || self::connection()->transactionLevel() > 0) throw new RuntimeException('文件存储升级需要无表前缀且无活动事务');
        self::applyToPdo(self::connection()->getPdo());
    }

    public static function applyToPdo(PDO $pdo): void
    {
        if ($pdo->inTransaction()) throw new RuntimeException('文件存储升级不能在事务内执行');
        $columns = $pdo->query("SHOW COLUMNS FROM `file_blob` LIKE 'storage_scope'")->fetchAll(PDO::FETCH_ASSOC);
        $indexes = $pdo->query('SHOW INDEX FROM `file_blob`')->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($indexes as $index) {
            $names[$index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name'];
            if (in_array($index['Key_name'], ['uk_md5', 'uk_md5_scope'], true) && (int) $index['Non_unique'] !== 0) throw new RuntimeException('文件摘要索引必须唯一');
        }
        $clauses = [];
        if (!$columns) $clauses[] = "ADD COLUMN `storage_scope` VARCHAR(40) NOT NULL DEFAULT 'general' AFTER `md5`";
        if (isset($names['uk_md5'])) {
            ksort($names['uk_md5']);
            if (array_values($names['uk_md5']) !== ['md5']) throw new RuntimeException('file_blob.uk_md5 结构不符合预期');
            $clauses[] = 'DROP INDEX `uk_md5`';
        }
        if (!isset($names['uk_md5_scope'])) $clauses[] = 'ADD UNIQUE KEY `uk_md5_scope` (`md5`,`storage_scope`)';
        else {
            ksort($names['uk_md5_scope']);
            if (array_values($names['uk_md5_scope']) !== ['md5', 'storage_scope']) throw new RuntimeException('file_blob.uk_md5_scope 结构不符合预期');
        }
        if ($clauses) $pdo->exec('ALTER TABLE `file_blob` ' . implode(', ', $clauses));
    }
}
