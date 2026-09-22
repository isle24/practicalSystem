<?php

namespace app\server\database;

class SchemaComparator
{
    public function compare(array $expected, array $actual, array $families = []): array
    {
        foreach ($actual as $name => $table) {
            if (isset($expected[$name])) continue;
            foreach ($families as $family) {
                if (preg_match('~' . $family['pattern'] . '~D', $name)) {
                    $expected[$name] = $family['template'];
                    break;
                }
            }
        }
        $differences = [];
        foreach ($expected as $name => $target) {
            $quoted = SchemaMetadata::identifier($name);
            if (!isset($actual[$name])) {
                $sql = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $target['create_sql']) . ';';
                $differences[] = $this->difference($name, $name, '缺少表', '', $target['create_sql'], 'add', $sql);
                continue;
            }
            $current = $actual[$name];
            foreach (['engine' => '存储引擎', 'collation' => '默认排序规则'] as $key => $label) {
                if (strcasecmp((string) $current[$key], (string) $target[$key]) === 0) continue;
                $sql = $key === 'engine' ? "ALTER TABLE {$quoted} ENGINE=" . $target[$key] . ';'
                    : "ALTER TABLE {$quoted} DEFAULT CHARACTER SET " . explode('_', $target[$key])[0] . ' COLLATE ' . $target[$key] . ';';
                $differences[] = $this->difference($name, $label, '表定义不同', $current[$key], $target[$key], 'review', $sql);
            }
            $pendingColumns = [];
            foreach ($target['columns'] as $column => $definition) {
                $exists = isset($current['columns'][$column]);
                if ($exists && $this->columnSignature($definition) === $this->columnSignature($current['columns'][$column])) continue;
                $safe = !$exists && ($definition['nullable'] || $definition['default'] !== null)
                    && $definition['generation'] === '' && !str_contains($definition['extra'], 'auto_increment');
                $action = $safe ? 'add' : 'review';
                if (!$safe) $pendingColumns[$column] = true;
                $sql = "ALTER TABLE {$quoted} " . ($exists ? 'MODIFY' : 'ADD') . ' COLUMN ' . $definition['definition'] . ';';
                $differences[] = $this->difference($name, $column, $exists ? '字段定义不同' : '缺少字段', $current['columns'][$column]['definition'] ?? '', $definition['definition'], $action, $sql);
            }
            foreach (array_diff_key($current['columns'], $target['columns']) as $column => $definition) {
                $differences[] = $this->difference($name, $column, '额外字段', $definition['definition'], '', 'keep');
            }
            $matched = [];
            foreach ($target['indexes'] as $index => $definition) {
                foreach ($current['indexes'] as $existingName => $existing) {
                    if (($index === 'PRIMARY') !== ($existingName === 'PRIMARY')) continue;
                    if ($this->indexSignature($definition) === $this->indexSignature($existing)) {
                        $matched[$existingName] = true;
                        continue 2;
                    }
                }
                $exists = isset($current['indexes'][$index]);
                $safe = !$exists && !$definition['unique'];
                foreach ($definition['columns'] as $column) {
                    if ($column['name'] === null || isset($pendingColumns[$column['name']])) $safe = false;
                }
                $sql = "ALTER TABLE {$quoted} ";
                if ($exists) {
                    $sql .= ($index === 'PRIMARY' ? 'DROP PRIMARY KEY' : 'DROP INDEX ' . SchemaMetadata::identifier($index)) . ', ';
                    $matched[$index] = true;
                }
                $sql .= 'ADD ' . $definition['definition'] . ';';
                $differences[] = $this->difference($name, $index, $exists ? '索引定义不同' : '缺少索引', $current['indexes'][$index]['definition'] ?? '', $definition['definition'], $safe ? 'add' : 'review', $sql);
            }
            foreach ($current['indexes'] as $index => $definition) {
                if (!isset($matched[$index])) $differences[] = $this->difference($name, $index, '额外索引', $definition['definition'], '', 'keep');
            }
            foreach (array_diff($target['constraints'] ?? [], $current['constraints'] ?? []) as $constraint) {
                $differences[] = $this->difference($name, '约束', '缺少约束', '', $constraint, 'review', "ALTER TABLE {$quoted} ADD {$constraint};");
            }
            foreach (array_diff($current['constraints'] ?? [], $target['constraints'] ?? []) as $constraint) {
                $differences[] = $this->difference($name, '约束', '额外约束', $constraint, '', 'keep');
            }
        }
        foreach (array_diff_key($actual, $expected) as $name => $table) {
            $differences[] = $this->difference($name, $name, '额外表', $table['create_sql'], '', 'keep');
        }
        return $differences;
    }

    public function sql(string $database, string $version, string $checkedAt, array $differences): string
    {
        $lines = ['-- 数据库结构升级 SQL', '-- 基准版本：' . $this->singleLine($version), '-- 检查时间：' . $checkedAt,
            '-- 结构快照仅适用于检查时的目标库；执行前备份，避免与其他升级并行。',
            '-- 人工审核区均已注释；核对现有数据后逐项执行。额外对象仅记录并保留。',
            'USE ' . SchemaMetadata::identifier($database) . ';', '', '-- 补齐缺失结构'];
        foreach ($differences as $difference) {
            if ($difference['action'] !== 'add') continue;
            $lines[] = '-- ' . $this->label($difference);
            $lines[] = $difference['sql'];
            $lines[] = '';
        }
        $lines[] = '-- 人工审核：字段修改、必填字段、唯一约束及定义替换';
        foreach ($differences as $difference) {
            if ($difference['action'] !== 'review') continue;
            $lines[] = '-- ' . $this->label($difference);
            $lines[] = '-- 当前：' . $this->singleLine($difference['current']);
            foreach (explode("\n", $difference['sql']) as $line) $lines[] = '-- ' . $line;
            $lines[] = '';
        }
        $lines[] = '-- 保留的额外对象';
        foreach ($differences as $difference) {
            if ($difference['action'] === 'keep') $lines[] = '-- ' . $this->label($difference);
        }
        if (!$differences) $lines[] = '-- 数据库结构一致，无需升级。';
        return implode("\n", $lines) . "\n";
    }

    private function columnSignature(array $column): array
    {
        return array_map(static fn ($key) => $column[$key], ['type', 'nullable', 'default', 'extra', 'charset', 'collation', 'generation']);
    }

    private function indexSignature(array $index): array
    {
        return [$index['unique'], $index['type'], $index['columns'], $index['visible'] ?? 'YES'];
    }

    private function difference(string $table, string $object, string $type, string $current, string $expected, string $action, string $sql = ''): array
    {
        return compact('table', 'object', 'type', 'current', 'expected', 'action', 'sql');
    }

    private function label(array $difference): string
    {
        return $this->singleLine($difference['table'] . '.' . $difference['object'] . '：' . $difference['type']);
    }

    private function singleLine(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
