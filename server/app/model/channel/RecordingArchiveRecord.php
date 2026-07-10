<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;

class RecordingArchiveRecord extends TableRecord
{
    private static array $schemaReady = [];

    private const SOURCES = [
        'arrangement_recording' => ['parent' => 'arrangement', 'grade_source' => 'parent_plan'],
        'arrangement_change_recording' => ['parent' => 'arrangement_change', 'grade_source' => 'arrangement'],
        'application_recording' => ['parent' => 'application', 'grade_source' => 'student'],
        'join_recording' => ['parent' => 'student_join_teacher', 'grade_source' => 'student'],
        'sign_in_recording' => ['parent' => 'sign_in', 'grade_source' => 'student'],
        'journal_recording' => ['parent' => 'journal', 'grade_source' => 'student'],
        'report_recording' => ['parent' => 'report', 'grade_source' => 'student'],
        'apply_report_delay_recording' => ['parent' => 'apply_report_delay', 'grade_source' => 'student'],
        'score_recording' => ['parent' => 'score', 'grade_source' => 'student'],
        'plan_recording' => ['parent' => 'internship_plan', 'grade_source' => 'parent'],
        'insurance_recording' => ['parent' => 'insurance', 'grade_source' => 'student'],
        'safety_letter_recording' => ['parent' => 'safety_letter_sign', 'grade_source' => 'student'],
        'syllabus_guide_recording' => ['parent' => 'syllabus_guide', 'grade_source' => 'arrangement'],
        'implementation_sheet_recording' => ['parent' => 'implementation_sheet', 'grade_source' => 'arrangement'],
        'teacher_work_report_recording' => ['parent' => 'teacher_work_report', 'grade_source' => 'arrangement'],
        'inspection_recording' => ['parent' => 'inspection_record', 'grade_source' => 'arrangement'],
    ];

    private const PRACTICE_PARENT_TABLES = [
        'plan' => ['table' => 'practice_plan', 'grade_source' => 'parent'],
        'schedule' => ['table' => 'practice_schedule', 'grade_source' => 'parent'],
        'project' => ['table' => 'practice_project', 'grade_source' => 'parent'],
        'syllabus' => ['table' => 'practice_syllabus', 'grade_source' => 'parent'],
        'lessonPlan' => ['table' => 'practice_lesson_plan', 'grade_source' => 'parent'],
        'gradeRule' => ['table' => 'practice_grade_rule', 'grade_source' => 'parent'],
        'score' => ['table' => 'practice_score', 'grade_source' => 'parent'],
        'reflection' => ['table' => 'practice_reflection', 'grade_source' => 'parent'],
        'sign_in' => ['table' => 'sign_in', 'grade_source' => 'student'],
        'journal' => ['table' => 'journal', 'grade_source' => 'student'],
        'report' => ['table' => 'report', 'grade_source' => 'student'],
    ];

    /**
     * 归档非当前及上一届次的审核时间线。
     */
    public static function archiveOlderGrades(int $year, int $limitPerTable = 500): array
    {
        $retainedGradeIds = self::retainedGradeIds();
        if (!$retainedGradeIds) {
            return ['retained_grade_ids' => [], 'archived' => 0, 'by_table' => []];
        }

        $archiveTable = self::archiveTable($year);
        self::ensureArchiveTable($archiveTable);
        $archived = 0;
        $byTable = [];

        foreach (self::SOURCES as $sourceTable => $config) {
            self::ensureRecordingTable($sourceTable);
            $rows = self::eligibleRows($sourceTable, $config, $retainedGradeIds, $limitPerTable);
            $count = 0;
            foreach ($rows as $row) {
                if (self::archiveRow($archiveTable, $sourceTable, $config['parent'], $row)) {
                    $count++;
                }
            }
            if ($count > 0) {
                $byTable[$sourceTable] = $count;
                $archived += $count;
            }
        }

        $practiceCount = self::archivePracticeRows($archiveTable, $retainedGradeIds, $limitPerTable);
        if ($practiceCount > 0) {
            $byTable['practice_recording'] = $practiceCount;
            $archived += $practiceCount;
        }

        return [
            'retained_grade_ids' => $retainedGradeIds,
            'archived' => $archived,
            'by_table' => $byTable,
        ];
    }

    /**
     * 归档实验和实训共用的流程记录。
     */
    private static function archivePracticeRows(string $archiveTable, array $retainedGradeIds, int $limit): int
    {
        $archived = 0;
        foreach (['training', 'lab'] as $moduleType) {
            foreach (self::PRACTICE_PARENT_TABLES as $entity => $config) {
                $entityType = $moduleType . '_' . $entity;
                $rows = self::eligiblePracticeRows($entityType, $config, $retainedGradeIds, $limit);
                foreach ($rows as $row) {
                    if (self::archiveRow($archiveTable, 'practice_recording', $config['table'], $row)) {
                        $archived++;
                    }
                }
            }
        }
        return $archived;
    }

    /**
     * 查询具备明确届次的实验实训流程记录。
     */
    private static function eligiblePracticeRows(string $entityType, array $config, array $retainedGradeIds, int $limit): array
    {
        $parentTable = (string) $config['table'];
        $query = self::queryTable('practice_recording')
            ->from('practice_recording as recording')
            ->join("{$parentTable} as parent", 'parent.id', '=', 'recording.parent_id')
            ->where('recording.entity_type', $entityType)
            ->whereNull('recording.deleted_at');
        $gradeColumn = self::applyGradeSource($query, (string) $config['grade_source']);

        return $query
            ->whereNotNull($gradeColumn)
            ->whereNotIn($gradeColumn, $retainedGradeIds)
            ->orderBy('recording.id')
            ->limit(max(1, $limit))
            ->get([
                'recording.*',
                new Expression("{$gradeColumn} as archive_grade_id"),
            ])
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    /**
     * 获取当前届次和上一届次 ID。
     */
    public static function retainedGradeIds(): array
    {
        $rows = self::queryTable('grade_list')
            ->whereNull('deleted_at')
            ->get(['grade_id', 'grade_name', 'is_current'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->grade_id,
                'name' => (string) $row->grade_name,
                'is_current' => (string) $row->is_current === 'true',
                'year' => self::gradeYear((string) $row->grade_name),
            ])
            ->all();

        $groups = [];
        foreach ($rows as $row) {
            $groupKey = $row['year'] > 0 ? 'year:' . $row['year'] : 'name:' . trim($row['name']);
            $groups[$groupKey] ??= [
                'ids' => [],
                'year' => $row['year'],
                'is_current' => false,
            ];
            $groups[$groupKey]['ids'][] = $row['id'];
            $groups[$groupKey]['is_current'] = $groups[$groupKey]['is_current'] || $row['is_current'];
        }

        $groups = array_values($groups);
        $current = null;
        foreach ($groups as $group) {
            if ($group['is_current']) {
                $current = $group;
                break;
            }
        }

        $selected = [];
        if ($current !== null) {
            $selected[] = $current;
            $previous = array_values(array_filter($groups, static fn (array $group): bool => !$group['is_current']
                && ($current['year'] <= 0 || ($group['year'] > 0 && $group['year'] < $current['year']))));
            usort($previous, static fn (array $left, array $right): int => $right['year'] <=> $left['year']);
            if ($previous) {
                $selected[] = $previous[0];
            }
        }
        if (count($selected) < 2) {
            $remaining = array_values(array_filter($groups, static fn (array $group): bool => !in_array($group, $selected, true)));
            usort($remaining, static fn (array $left, array $right): int => $right['year'] <=> $left['year']);
            $selected = array_merge($selected, array_slice($remaining, 0, 2 - count($selected)));
        }

        $ids = [];
        foreach ($selected as $group) {
            $ids = array_merge($ids, $group['ids']);
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * 查询具备明确届次且可归档的时间线记录。
     */
    private static function eligibleRows(string $sourceTable, array $config, array $retainedGradeIds, int $limit): array
    {
        $parentTable = $config['parent'];
        $query = self::queryTable($sourceTable)
            ->from("{$sourceTable} as recording")
            ->join("{$parentTable} as parent", 'parent.id', '=', 'recording.parent_id')
            ->whereNull('recording.deleted_at');

        $gradeColumn = self::applyGradeSource($query, (string) $config['grade_source']);

        return $query
            ->whereNotNull($gradeColumn)
            ->whereNotIn($gradeColumn, $retainedGradeIds)
            ->orderBy('recording.id')
            ->limit(max(1, $limit))
            ->get([
                'recording.*',
                new Expression("{$gradeColumn} as archive_grade_id"),
            ])
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    /**
     * 应用来源主表的届次关联。
     */
    private static function applyGradeSource(mixed $query, string $source): string
    {
        if ($source === 'parent') {
            return 'parent.grade_id';
        }
        if ($source === 'parent_plan') {
            $query->join('internship_plan as plan', 'parent.plan_id', '=', 'plan.id');
            return 'plan.grade_id';
        }
        if ($source === 'arrangement') {
            $query->join('arrangement as arrangement_scope', 'parent.arrangement_id', '=', 'arrangement_scope.id')
                ->join('internship_plan as plan', 'arrangement_scope.plan_id', '=', 'plan.id');
            return 'plan.grade_id';
        }

        $query->join('students as student_scope', 'parent.student_id', '=', 'student_scope.student_id');
        return 'student_scope.grade_id';
    }

    /**
     * 事务内写入归档快照并删除来源记录。
     */
    private static function archiveRow(string $archiveTable, string $sourceTable, string $parentTable, array $candidate): bool
    {
        $sourceId = (int) ($candidate['id'] ?? 0);
        if ($sourceId <= 0) {
            return false;
        }

        $gradeId = (int) ($candidate['archive_grade_id'] ?? 0);

        return (bool) self::connection()->transaction(function () use ($archiveTable, $sourceTable, $parentTable, $sourceId, $gradeId): bool {
            $source = self::queryTable($sourceTable)
                ->where('id', $sourceId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (!$source) {
                return false;
            }

            $snapshot = $source->getAttributes();
            $entityType = (string) ($source->entity_type ?? str_replace('_recording', '', $sourceTable));
            $entityId = (int) ($source->entity_id ?? $source->parent_id ?? 0);
            $reviews = self::reviewRows($entityType, $entityId, $sourceId);
            $now = date('Y-m-d H:i:s');

            self::queryTable($archiveTable)->insertOrIgnore([
                'uuid' => self::uuidValue(),
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
                'parent_table' => $parentTable,
                'parent_id' => (int) ($source->parent_id ?? $entityId),
                'grade_id' => $gradeId ?: null,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $source->action ?? null,
                'operator_id' => $source->operator_id ?? null,
                'from_status' => $source->from_status ?? null,
                'to_status' => $source->to_status ?? null,
                'content' => $source->content ?? $source->opinion ?? null,
                'snapshot_data' => self::jsonValue($snapshot),
                'review_snapshot' => self::jsonValue($reviews),
                'original_created_at' => $source->created_at ?? null,
                'archived_at' => $now,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (!self::archiveExists($archiveTable, $sourceTable, $sourceId)) {
                return false;
            }

            self::deleteReviewRows($entityType, $entityId, $sourceId);
            self::queryTable($sourceTable)->where('id', $sourceId)->delete();
            return true;
        });
    }

    /**
     * 读取与 recording 对应的审核意见快照。
     */
    private static function reviewRows(string $entityType, int $entityId, int $recordingId): array
    {
        return self::queryTable('review_opinion')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('recording_id', $recordingId)
            ->get()
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    /**
     * 删除已经写入归档快照的审核意见。
     */
    private static function deleteReviewRows(string $entityType, int $entityId, int $recordingId): int
    {
        return (int) self::queryTable('review_opinion')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('recording_id', $recordingId)
            ->delete();
    }

    /**
     * 判断来源记录是否已经归档。
     */
    private static function archiveExists(string $archiveTable, string $sourceTable, int $sourceId): bool
    {
        return self::queryTable($archiveTable)
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * 创建年度 recording 归档表。
     */
    private static function ensureArchiveTable(string $table): void
    {
        $connection = self::connection();
        $database = method_exists($connection, 'getDatabaseName') ? (string) $connection->getDatabaseName() : '';
        $key = $database . ':' . $table;
        if (isset(self::$schemaReady[$key])) {
            return;
        }

        $connection->statement("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `source_table` VARCHAR(80) NOT NULL,
            `source_id` BIGINT UNSIGNED NOT NULL,
            `parent_table` VARCHAR(80) DEFAULT NULL,
            `parent_id` BIGINT UNSIGNED DEFAULT NULL,
            `grade_id` BIGINT UNSIGNED DEFAULT NULL,
            `entity_type` VARCHAR(40) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `action` VARCHAR(40) DEFAULT NULL,
            `operator_id` BIGINT UNSIGNED DEFAULT NULL,
            `from_status` VARCHAR(40) DEFAULT NULL,
            `to_status` VARCHAR(40) DEFAULT NULL,
            `content` MEDIUMTEXT DEFAULT NULL,
            `snapshot_data` JSON DEFAULT NULL,
            `review_snapshot` JSON DEFAULT NULL,
            `original_created_at` DATETIME DEFAULT NULL,
            `archived_at` DATETIME DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_source` (`source_table`, `source_id`),
            KEY `idx_parent` (`parent_table`, `parent_id`),
            KEY `idx_grade` (`grade_id`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_original_created` (`original_created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = self::queryTable('information_schema.columns')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->pluck('COLUMN_NAME')
            ->all();
        foreach (self::archiveColumnDefinitions($table) as $column => $ddl) {
            if (!in_array($column, $columns, true)) {
                $connection->statement($ddl);
            }
        }

        $indexes = self::queryTable('information_schema.statistics')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->pluck('INDEX_NAME')
            ->all();
        foreach (self::archiveIndexDefinitions($table) as $index => $ddl) {
            if (!in_array($index, $indexes, true)) {
                $connection->statement($ddl);
            }
        }

        self::$schemaReady[$key] = true;
    }

    /**
     * 返回年度归档表缺失列的建表语句。
     */
    private static function archiveColumnDefinitions(string $table): array
    {
        return [
            'source_table' => "ALTER TABLE `{$table}` ADD COLUMN `source_table` VARCHAR(80) DEFAULT NULL AFTER `uuid`",
            'source_id' => "ALTER TABLE `{$table}` ADD COLUMN `source_id` BIGINT UNSIGNED DEFAULT NULL AFTER `source_table`",
            'parent_table' => "ALTER TABLE `{$table}` ADD COLUMN `parent_table` VARCHAR(80) DEFAULT NULL AFTER `source_id`",
            'parent_id' => "ALTER TABLE `{$table}` ADD COLUMN `parent_id` BIGINT UNSIGNED DEFAULT NULL AFTER `parent_table`",
            'grade_id' => "ALTER TABLE `{$table}` ADD COLUMN `grade_id` BIGINT UNSIGNED DEFAULT NULL AFTER `parent_id`",
            'entity_type' => "ALTER TABLE `{$table}` ADD COLUMN `entity_type` VARCHAR(40) DEFAULT NULL AFTER `grade_id`",
            'entity_id' => "ALTER TABLE `{$table}` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
            'action' => "ALTER TABLE `{$table}` ADD COLUMN `action` VARCHAR(40) DEFAULT NULL AFTER `entity_id`",
            'operator_id' => "ALTER TABLE `{$table}` ADD COLUMN `operator_id` BIGINT UNSIGNED DEFAULT NULL AFTER `action`",
            'from_status' => "ALTER TABLE `{$table}` ADD COLUMN `from_status` VARCHAR(40) DEFAULT NULL AFTER `operator_id`",
            'to_status' => "ALTER TABLE `{$table}` ADD COLUMN `to_status` VARCHAR(40) DEFAULT NULL AFTER `from_status`",
            'content' => "ALTER TABLE `{$table}` ADD COLUMN `content` MEDIUMTEXT DEFAULT NULL AFTER `to_status`",
            'snapshot_data' => "ALTER TABLE `{$table}` ADD COLUMN `snapshot_data` JSON DEFAULT NULL AFTER `content`",
            'review_snapshot' => "ALTER TABLE `{$table}` ADD COLUMN `review_snapshot` JSON DEFAULT NULL AFTER `snapshot_data`",
            'original_created_at' => "ALTER TABLE `{$table}` ADD COLUMN `original_created_at` DATETIME DEFAULT NULL AFTER `review_snapshot`",
            'archived_at' => "ALTER TABLE `{$table}` ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `original_created_at`",
        ];
    }

    /**
     * 返回年度归档表缺失索引的建表语句。
     */
    private static function archiveIndexDefinitions(string $table): array
    {
        return [
            'uk_source' => "ALTER TABLE `{$table}` ADD UNIQUE KEY `uk_source` (`source_table`, `source_id`)",
            'idx_parent' => "ALTER TABLE `{$table}` ADD KEY `idx_parent` (`parent_table`, `parent_id`)",
            'idx_grade' => "ALTER TABLE `{$table}` ADD KEY `idx_grade` (`grade_id`)",
            'idx_entity' => "ALTER TABLE `{$table}` ADD KEY `idx_entity` (`entity_type`, `entity_id`)",
            'idx_original_created' => "ALTER TABLE `{$table}` ADD KEY `idx_original_created` (`original_created_at`)",
        ];
    }

    /**
     * 返回年度 recording 归档表名。
     */
    private static function archiveTable(int $year): string
    {
        $year = max(2000, min(2999, $year));
        return 'recording_archive_' . $year;
    }

    /**
     * 提取届次名称中的年份。
     */
    private static function gradeYear(string $name): int
    {
        return preg_match('/(19|20)\d{2}/', $name, $matches) ? (int) $matches[0] : 0;
    }

    /**
     * 编码归档 JSON 字段。
     */
    private static function jsonValue(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * 生成归档记录 UUID。
     */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
