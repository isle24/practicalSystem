<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;

class InternshipScheduledRecord extends TableRecord
{
    private static array $schemaReady = [];

    /**
     * 创建指定自然周的实习简报。
     */
    public static function createWeeklyBrief(
        string $weekKey,
        string $weekStart,
        string $weekEnd,
        string $scopeType = 'school',
        int $scopeId = 0,
        string $scopeName = '全校'
    ): array
    {
        self::ensureSchema();
        $storageKey = $weekKey . ':' . $scopeType . ':' . $scopeId;
        $existing = self::queryTable('internship_brief')
            ->where('week_key', $storageKey)
            ->whereNull('deleted_at')
            ->first(['id', 'title', 'content_json']);
        if ($existing) {
            $snapshot = self::decodeJson((string) $existing->content_json);
            return [
                'created' => false,
                'id' => (int) $existing->id,
                'title' => (string) $existing->title,
                'summary' => self::briefSummary($snapshot),
            ];
        }

        $snapshot = self::weeklySnapshot($weekStart, $weekEnd, $scopeType, $scopeId);
        $title = '实习简报（第 ' . (int) date('W', strtotime($weekStart)) . ' 周 · ' . $scopeName . '）';
        $now = date('Y-m-d H:i:s');
        $id = (int) self::queryTable('internship_brief')->insertGetId([
            'uuid' => self::uuidValue(),
            'week_key' => $storageKey,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'scope_name' => $scopeName,
            'title' => $title,
            'content_json' => self::jsonValue($snapshot),
            'generated_at' => $now,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'created' => true,
            'id' => $id,
            'title' => $title,
            'summary' => self::briefSummary($snapshot),
        ];
    }

    /**
     * 读取指定日期即将到期且有效的保险记录。
     */
    public static function expiringInsuranceRows(string $fromDate, string $toDate): array
    {
        return self::queryTable('insurance')
            ->join('students', 'insurance.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'insurance.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'arrangement.teacher_id', '=', 'teacher_list.teacher_id')
            ->whereBetween('insurance.end_date', [$fromDate, $toDate])
            ->whereIn('insurance.status', ['accept', 'enabled'])
            ->whereNull('insurance.deleted_at')
            ->whereNull('students.deleted_at')
            ->orderBy('insurance.id')
            ->get([
                'insurance.id',
                'insurance.student_id',
                'insurance.arrangement_id',
                'insurance.insurance_company',
                'insurance.policy_number',
                'insurance.end_date',
                'students.name as student_name',
                'students.student_num',
                'students.dep_id',
                'students.profession_id',
                'students.user_id as student_user_id',
                'teacher_list.user_id as teacher_user_id',
                'teacher_list.teacher_name',
                'arrangement.title as arrangement_title',
            ])
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    /**
     * 汇总自然周实习过程数据。
     */
    private static function weeklySnapshot(string $weekStart, string $weekEnd, string $scopeType = 'school', int $scopeId = 0): array
    {
        $participants = self::participantRows($scopeType, $scopeId);
        $signedStudentIds = self::signedStudentIds($weekStart, $weekEnd, $scopeType, $scopeId);
        $missingSignIns = self::missingActivityRows('sign_in', $weekStart, $weekEnd, $scopeType, $scopeId);
        $missingJournals = self::missingActivityRows('journal', $weekStart, $weekEnd, $scopeType, $scopeId);
        $overdueReports = self::overdueReportRows($weekEnd, $scopeType, $scopeId);

        return [
            'period' => ['start' => $weekStart, 'end' => $weekEnd],
            'overview' => [
                'student_count' => count(array_unique(array_column($participants, 'student_id'))),
                'arrangement_count' => count(array_unique(array_column($participants, 'arrangement_id'))),
                'type_distribution' => self::typeDistribution($scopeType, $scopeId),
                'base_count' => self::activeBaseCount($scopeType, $scopeId),
                'company_distribution' => self::companyDistribution($scopeType, $scopeId),
            ],
            'sign_in' => [
                'signed_student_count' => count($signedStudentIds),
                'coverage_rate' => self::percent(count($signedStudentIds), count(array_unique(array_column($participants, 'student_id')))),
                'missing_count' => count($missingSignIns),
                'missing_students' => $missingSignIns,
                'profession_rates' => self::professionSignInRates($weekStart, $weekEnd, $scopeType, $scopeId),
            ],
            'issues' => [
                'missing_sign_in_students' => $missingSignIns,
                'missing_journal_students' => $missingJournals,
                'overdue_report_students' => $overdueReports,
            ],
            'teachers' => self::teacherOverview($weekStart, $weekEnd, $scopeType, $scopeId),
        ];
    }

    /**
     * 读取当前实习任务绑定的学生。
     */
    private static function participantRows(string $scopeType, int $scopeId): array
    {
        return self::activePairQuery($scopeType, $scopeId)
            ->distinct()
            ->get([
                'active_pair.student_id',
                'active_pair.arrangement_id',
                'students.name as student_name',
                'students.student_num',
                'students.dep_id',
                'students.profession_id',
                'profession.profession_name',
            ])
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    /**
     * 读取本周有签到记录的学生 ID。
     */
    private static function signedStudentIds(string $weekStart, string $weekEnd, string $scopeType, int $scopeId): array
    {
        return self::activePairQuery($scopeType, $scopeId)
            ->join('sign_in as weekly_sign', function ($join) use ($weekStart, $weekEnd): void {
                $join->on('weekly_sign.student_id', '=', 'active_pair.student_id')
                    ->on('weekly_sign.entity_id', '=', 'active_pair.arrangement_id')
                    ->where('weekly_sign.entity_type', 'internship')
                    ->whereBetween('weekly_sign.date', [$weekStart, $weekEnd])
                    ->whereNull('weekly_sign.deleted_at');
            })
            ->distinct()
            ->pluck('active_pair.student_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 读取自然周内没有签到或日志的任务学生。
     */
    private static function missingActivityRows(string $table, string $weekStart, string $weekEnd, string $scopeType, int $scopeId): array
    {
        $query = self::activePairQuery($scopeType, $scopeId);
        $query->whereNotExists(function ($subQuery) use ($table, $weekStart, $weekEnd): void {
            $subQuery->selectRaw('1')
                ->from("{$table} as weekly_activity")
                ->whereColumn('weekly_activity.student_id', 'active_pair.student_id')
                ->whereColumn('weekly_activity.entity_id', 'active_pair.arrangement_id')
                ->where('weekly_activity.entity_type', 'internship')
                ->whereBetween('weekly_activity.date', [$weekStart, $weekEnd])
                ->whereNull('weekly_activity.deleted_at');
            if ($table === 'journal') {
                $subQuery->whereIn('weekly_activity.status', ['wait', 'accept', 'modify', 'enabled']);
            }
        });

        return self::studentIssueRows($query);
    }

    /**
     * 读取已结束任务中尚未提交有效报告的学生。
     */
    private static function overdueReportRows(string $weekEnd, string $scopeType, int $scopeId): array
    {
        $query = self::activePairQuery($scopeType, $scopeId)->whereDate('arrangement.end_date', '<', $weekEnd);
        $query->whereNotExists(function ($subQuery): void {
            $subQuery->selectRaw('1')
                ->from('report as submitted_report')
                ->whereColumn('submitted_report.student_id', 'active_pair.student_id')
                ->whereColumn('submitted_report.arrangement_id', 'active_pair.arrangement_id')
                ->whereIn('submitted_report.status', ['wait', 'accept', 'modify', 'enabled'])
                ->whereNull('submitted_report.deleted_at');
        });

        return self::studentIssueRows($query);
    }

    /**
     * 格式化问题学生列表。
     */
    private static function studentIssueRows(mixed $query): array
    {
        return $query
            ->orderBy('students.student_id')
            ->limit(500)
            ->get([
                'students.student_id',
                'students.name as student_name',
                'students.student_num',
                'profession.profession_name',
                'arrangement.id as arrangement_id',
                'arrangement.title as arrangement_title',
            ])
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    /**
     * 统计实习类型对应的学生数量。
     */
    private static function typeDistribution(string $scopeType, int $scopeId): array
    {
        return self::activePairQuery($scopeType, $scopeId)
            ->groupBy('arrangement.type')
            ->orderBy('arrangement.type')
            ->get([
                'arrangement.type',
                new Expression('COUNT(DISTINCT active_pair.student_id) as student_count'),
            ])
            ->map(static fn ($row): array => [
                'type' => (string) $row->type,
                'student_count' => (int) $row->student_count,
            ])
            ->all();
    }

    /**
     * 统计当前实习任务使用的基地数量。
     */
    private static function activeBaseCount(string $scopeType, int $scopeId): int
    {
        return (int) self::activePairQuery($scopeType, $scopeId)
            ->whereNotNull('arrangement.base_id')
            ->distinct()
            ->count('arrangement.base_id');
    }

    /**
     * 统计当前实习任务的企业分布。
     */
    private static function companyDistribution(string $scopeType, int $scopeId): array
    {
        return self::activePairQuery($scopeType, $scopeId)
            ->join('base', 'arrangement.base_id', '=', 'base.id')
            ->leftJoin('companies', 'base.company_id', '=', 'companies.company_id')
            ->whereNull('base.deleted_at')
            ->groupBy('base.company_id', 'companies.company_name')
            ->orderBy('companies.company_name')
            ->get([
                'base.company_id',
                'companies.company_name',
                new Expression('COUNT(DISTINCT active_pair.student_id) as student_count'),
            ])
            ->map(static fn ($row): array => [
                'company_id' => $row->company_id === null ? null : (int) $row->company_id,
                'company_name' => (string) ($row->company_name ?: '未关联企业'),
                'student_count' => (int) $row->student_count,
            ])
            ->all();
    }

    /**
     * 统计各专业本周签到覆盖率。
     */
    private static function professionSignInRates(string $weekStart, string $weekEnd, string $scopeType, int $scopeId): array
    {
        return self::activePairQuery($scopeType, $scopeId)
            ->leftJoin('sign_in as weekly_sign', function ($join) use ($weekStart, $weekEnd): void {
                $join->on('weekly_sign.student_id', '=', 'active_pair.student_id')
                    ->on('weekly_sign.entity_id', '=', 'active_pair.arrangement_id')
                    ->where('weekly_sign.entity_type', 'internship')
                    ->whereBetween('weekly_sign.date', [$weekStart, $weekEnd])
                    ->whereNull('weekly_sign.deleted_at');
            })
            ->groupBy('students.profession_id', 'profession.profession_name')
            ->orderBy('profession.profession_name')
            ->get([
                'students.profession_id',
                'profession.profession_name',
                new Expression('COUNT(DISTINCT active_pair.student_id) as student_count'),
                new Expression('COUNT(DISTINCT weekly_sign.student_id) as signed_student_count'),
            ])
            ->map(static fn ($row): array => [
                'profession_id' => $row->profession_id === null ? null : (int) $row->profession_id,
                'profession_name' => (string) ($row->profession_name ?: '未分配专业'),
                'student_count' => (int) $row->student_count,
                'signed_student_count' => (int) $row->signed_student_count,
                'coverage_rate' => self::percent((int) $row->signed_student_count, (int) $row->student_count),
            ])
            ->all();
    }

    /**
     * 统计任务老师指导和日志评阅情况。
     */
    private static function teacherOverview(string $weekStart, string $weekEnd, string $scopeType, int $scopeId): array
    {
        return self::activePairQuery($scopeType, $scopeId)
            ->leftJoin('teacher_list', 'active_pair.teacher_id', '=', 'teacher_list.teacher_id')
            ->leftJoin('journal as weekly_journal', function ($join) use ($weekStart, $weekEnd): void {
                $join->on('weekly_journal.student_id', '=', 'active_pair.student_id')
                    ->on('weekly_journal.entity_id', '=', 'active_pair.arrangement_id')
                    ->where('weekly_journal.entity_type', 'internship')
                    ->whereBetween('weekly_journal.date', [$weekStart, $weekEnd])
                    ->whereNull('weekly_journal.deleted_at');
            })
            ->groupBy('active_pair.teacher_id', 'teacher_list.teacher_name')
            ->orderBy('teacher_list.teacher_name')
            ->get([
                'active_pair.teacher_id',
                'teacher_list.teacher_name',
                new Expression('COUNT(DISTINCT active_pair.student_id) as student_count'),
                new Expression('COUNT(DISTINCT weekly_journal.id) as journal_count'),
                new Expression("COUNT(DISTINCT CASE WHEN weekly_journal.status IN ('accept','modify','enabled') THEN weekly_journal.id END) as reviewed_journal_count"),
            ])
            ->map(static fn ($row): array => [
                'teacher_id' => $row->teacher_id === null ? null : (int) $row->teacher_id,
                'teacher_name' => (string) ($row->teacher_name ?: '未分配老师'),
                'student_count' => (int) $row->student_count,
                'journal_count' => (int) $row->journal_count,
                'reviewed_journal_count' => (int) $row->reviewed_journal_count,
                'review_rate' => self::percent((int) $row->reviewed_journal_count, (int) $row->journal_count),
            ])
            ->all();
    }

    /**
     * 构建当前有效实习任务绑定查询。
     */
    private static function activePairQuery(string $scopeType = 'school', int $scopeId = 0): mixed
    {
        $query = self::queryTable('pair')
            ->from('pair as active_pair')
            ->join('students', 'active_pair.student_id', '=', 'students.student_id')
            ->join('arrangement', 'active_pair.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->where('active_pair.type', 'internship')
            ->where('active_pair.status', 'active')
            ->where('arrangement.status', '<>', 'changed')
            ->whereNull('active_pair.deleted_at')
            ->whereNull('students.deleted_at')
            ->whereNull('arrangement.deleted_at');

        if ($scopeType === 'department') {
            return $scopeId > 0 ? $query->where('students.dep_id', $scopeId) : $query->whereRaw('1 = 0');
        }
        if ($scopeType === 'profession') {
            return $scopeId > 0 ? $query->where('students.profession_id', $scopeId) : $query->whereRaw('1 = 0');
        }
        return $scopeType === 'school' ? $query : $query->whereRaw('1 = 0');
    }

    /**
     * 生成简报消息摘要。
     */
    private static function briefSummary(array $snapshot): string
    {
        $overview = (array) ($snapshot['overview'] ?? []);
        $signIn = (array) ($snapshot['sign_in'] ?? []);
        $issues = (array) ($snapshot['issues'] ?? []);
        return implode('；', [
            '参与学生' . (int) ($overview['student_count'] ?? 0) . '人',
            '实习任务' . (int) ($overview['arrangement_count'] ?? 0) . '个',
            '签到覆盖率' . (string) ($signIn['coverage_rate'] ?? '0%'),
            '未交日志' . count((array) ($issues['missing_journal_students'] ?? [])) . '人',
            '报告逾期' . count((array) ($issues['overdue_report_students'] ?? [])) . '人',
        ]);
    }

    /**
     * 计算百分比文本。
     */
    private static function percent(int $numerator, int $denominator): string
    {
        return $denominator > 0 ? round($numerator * 100 / $denominator, 1) . '%' : '0%';
    }

    /**
     * 创建实习简报表。
     */
    private static function ensureSchema(): void
    {
        $connection = self::connection();
        $key = method_exists($connection, 'getDatabaseName') ? (string) $connection->getDatabaseName() : spl_object_hash($connection);
        if (isset(self::$schemaReady[$key])) {
            return;
        }

        $connection->statement("CREATE TABLE IF NOT EXISTS `internship_brief` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `week_key` VARCHAR(80) NOT NULL,
            `week_start` DATE NOT NULL,
            `week_end` DATE NOT NULL,
            `scope_type` VARCHAR(20) DEFAULT 'school',
            `scope_id` BIGINT UNSIGNED DEFAULT 0,
            `scope_name` VARCHAR(120) DEFAULT NULL,
            `title` VARCHAR(180) DEFAULT NULL,
            `content_json` JSON DEFAULT NULL,
            `generated_at` DATETIME DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_week_key` (`week_key`),
            KEY `idx_period` (`week_start`, `week_end`),
            KEY `idx_scope_period` (`scope_type`, `scope_id`, `week_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = self::queryTable('information_schema.columns')
            ->where('TABLE_SCHEMA', $key)
            ->where('TABLE_NAME', 'internship_brief')
            ->get(['COLUMN_NAME', 'CHARACTER_MAXIMUM_LENGTH'])
            ->keyBy('COLUMN_NAME');
        if (!$columns->has('scope_type')) {
            $connection->statement("ALTER TABLE `internship_brief` ADD COLUMN `scope_type` VARCHAR(20) DEFAULT 'school' AFTER `week_end`");
        }
        if (!$columns->has('scope_id')) {
            $connection->statement("ALTER TABLE `internship_brief` ADD COLUMN `scope_id` BIGINT UNSIGNED DEFAULT 0 AFTER `scope_type`");
        }
        if (!$columns->has('scope_name')) {
            $connection->statement("ALTER TABLE `internship_brief` ADD COLUMN `scope_name` VARCHAR(120) DEFAULT NULL AFTER `scope_id`");
        }
        $weekKeyColumn = $columns->get('week_key');
        if ($weekKeyColumn && (int) $weekKeyColumn->CHARACTER_MAXIMUM_LENGTH < 80) {
            $connection->statement("ALTER TABLE `internship_brief` MODIFY COLUMN `week_key` VARCHAR(80) NOT NULL");
        }

        $scopeIndex = self::queryTable('information_schema.statistics')
            ->where('TABLE_SCHEMA', $key)
            ->where('TABLE_NAME', 'internship_brief')
            ->where('INDEX_NAME', 'idx_scope_period')
            ->exists();
        if (!$scopeIndex) {
            $connection->statement("ALTER TABLE `internship_brief` ADD KEY `idx_scope_period` (`scope_type`, `scope_id`, `week_start`)");
        }
        self::$schemaReady[$key] = true;
    }

    /**
     * 编码简报 JSON 字段。
     */
    private static function jsonValue(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * 解码简报 JSON 字段。
     */
    private static function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 生成简报 UUID。
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
