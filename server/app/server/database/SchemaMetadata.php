<?php

namespace app\server\database;

use PDO;
use RuntimeException;

class SchemaMetadata
{
    public static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public static function read(object $pdo): array
    {
        $tables = [];
        $rows = $pdo->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = $row['TABLE_NAME'];
            $create = $pdo->query('SHOW CREATE TABLE ' . self::identifier($name))->fetch(PDO::FETCH_NUM)[1];
            $create = preg_replace('/\) ENGINE=(\w+)(.*?) AUTO_INCREMENT=\d+/', ') ENGINE=$1$2', $create);
            $tables[$name] = ['engine' => $row['ENGINE'], 'collation' => $row['TABLE_COLLATION'], 'create_sql' => $create, 'columns' => [], 'indexes' => [], 'constraints' => []];
            foreach (explode("\n", $create) as $line) {
                $definition = rtrim(trim($line), ',');
                if (preg_match('/^`((?:``|[^`])+)` /', $definition, $matches)) {
                    $tables[$name]['columns'][str_replace('``', '`', $matches[1])] = ['definition' => $definition];
                } elseif (preg_match('/^(?:(?:UNIQUE|FULLTEXT|SPATIAL) )?KEY `((?:``|[^`])+)` /', $definition, $matches)) {
                    $tables[$name]['indexes'][str_replace('``', '`', $matches[1])] = ['definition' => $definition, 'columns' => []];
                } elseif (str_starts_with($definition, 'PRIMARY KEY ')) {
                    $tables[$name]['indexes']['PRIMARY'] = ['definition' => $definition, 'columns' => []];
                } elseif (str_starts_with($definition, 'CONSTRAINT ')) {
                    $tables[$name]['constraints'][] = $definition;
                }
            }
        }

        $columns = $pdo->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $row) {
            $table = $row['TABLE_NAME'];
            $name = $row['COLUMN_NAME'];
            if (!isset($tables[$table])) continue;
            if (!isset($tables[$table]['columns'][$name])) throw new RuntimeException('无法读取字段定义');
            $column = &$tables[$table]['columns'][$name];
            $column += [
                'type' => preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i', '$1', $row['COLUMN_TYPE']),
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'default' => $row['COLUMN_DEFAULT'],
                'extra' => trim(str_replace('DEFAULT_GENERATED', '', $row['EXTRA'])),
                'charset' => $row['CHARACTER_SET_NAME'], 'collation' => $row['COLLATION_NAME'],
                'generation' => $row['GENERATION_EXPRESSION'],
            ];
            if ($column['charset'] && !str_contains($column['definition'], ' COLLATE ')) {
                $prefix = self::identifier($name) . ' ' . $row['COLUMN_TYPE'];
                if (str_starts_with($column['definition'], $prefix)) {
                    $column['definition'] = $prefix . ' CHARACTER SET ' . $column['charset'] . ' COLLATE ' . $column['collation'] . substr($column['definition'], strlen($prefix));
                }
            }
            unset($column);
        }

        $indexes = $pdo->query('SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $row) {
            $table = $row['TABLE_NAME'];
            $name = $row['INDEX_NAME'];
            if (!isset($tables[$table])) continue;
            if (!isset($tables[$table]['indexes'][$name])) throw new RuntimeException('无法读取索引定义');
            $index = &$tables[$table]['indexes'][$name];
            $index['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $index['type'] = $row['INDEX_TYPE'];
            $index['visible'] = $row['IS_VISIBLE'] ?? 'YES';
            $index['columns'][] = ['name' => $row['COLUMN_NAME'], 'length' => $row['SUB_PART'] === null ? null : (int) $row['SUB_PART'], 'order' => $row['COLLATION'], 'expression' => $row['EXPRESSION'] ?? null];
            unset($index);
        }
        return $tables;
    }
}
