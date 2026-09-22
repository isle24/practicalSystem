<?php

namespace app\server\database;

use app\model\system\DatabaseSchemaRecord;
use app\server\CurrentContext;
use RuntimeException;

class DatabaseSchemaService
{
    public function options(): array
    {
        $this->assertAdmin();
        $baseline = $this->baseline();
        return ['targets' => $this->targets(), 'baseline_version' => $baseline['version'], 'baseline_current' => $this->isCurrent($baseline)];
    }

    public function check(string $target): array
    {
        $this->assertAdmin();
        if (!in_array($target, array_column($this->targets(), 'value'), true)) {
            throw new RuntimeException('无权检查所选数据库', 403);
        }
        $baseline = $this->baseline();
        if (!$this->isCurrent($baseline)) throw new RuntimeException('结构基准与当前代码不匹配，请部署完整版本或更新基准', 409);
        $current = DatabaseSchemaRecord::inspect($target);
        $comparator = new SchemaComparator();
        $differences = $comparator->compare($baseline['schemas'][$target], $current['tables'], $baseline['families'][$target] ?? []);
        $summary = ['add' => 0, 'review' => 0, 'keep' => 0, 'total' => count($differences)];
        foreach ($differences as $difference) ++$summary[$difference['action']];
        $checkedAt = date('Y-m-d H:i:s');
        return [
            'target' => $target, 'database' => $current['database'], 'baseline_version' => $baseline['version'],
            'checked_at' => $checkedAt, 'table_count' => count($current['tables']), 'consistent' => !$differences,
            'differences' => $differences, 'summary' => $summary,
            'sql_content' => $comparator->sql($current['database'], $baseline['version'], $checkedAt, $differences),
            'file_name' => 'schema-upgrade-' . $target . '-' . date('Ymd-His') . '.sql',
        ];
    }

    private function assertAdmin(): void
    {
        if (!CurrentContext::accountId() || !in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true)
            || !in_array('config:manage', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无数据库结构维护权限', 403);
        }
    }

    private function targets(): array
    {
        $targets = [];
        if (CurrentContext::schoolDatabaseId() && CurrentContext::schoolDatabase() && CurrentContext::get('school_connection')) {
            $targets[] = ['value' => 'school', 'label' => '当前学校数据库'];
        }
        if (CurrentContext::roleType() === 'super_admin') $targets[] = ['value' => 'master', 'label' => '主数据库'];
        return $targets;
    }

    private function baseline(): array
    {
        $path = base_path('database/schema-baseline.json');
        if (!is_file($path)) throw new RuntimeException('缺少数据库结构基准，请部署完整版本', 409);
        $baseline = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (empty($baseline['source_hashes']) || empty($baseline['schemas']['master']) || empty($baseline['schemas']['school'])) {
            throw new RuntimeException('数据库结构基准不完整，请更新基准', 409);
        }
        return $baseline;
    }

    private function isCurrent(array $baseline): bool
    {
        foreach ($baseline['source_hashes'] as $relative => $hash) {
            $path = base_path($relative);
            if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) return false;
        }
        foreach (['database/updates/*.sql', 'database/migrations/*.php'] as $pattern) {
            foreach (glob(base_path($pattern)) ?: [] as $path) {
                $relative = substr($path, strlen(base_path()) + 1);
                if (!isset($baseline['source_hashes'][$relative])) return false;
            }
        }
        return true;
    }
}
