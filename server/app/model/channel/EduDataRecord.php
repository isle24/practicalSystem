<?php

namespace app\model\channel;

use app\server\CurrentContext;
use InvalidArgumentException;

class EduDataRecord extends BaseModel
{
    protected $table = 'edu_import_batch';
    protected $primaryKey = 'id';
    protected $guarded = [];
    public $timestamps = false;

    private const TYPES = [
        'student' => 'edu_student',
        'teaching_plan' => 'edu_teaching_plan',
        'course_offering' => 'edu_course_offering',
    ];

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    public static function batchPage(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::connection()->table('edu_import_batch')->whereNull('deleted_at');
        self::applyBatchScope($query);

        $type = trim((string) ($filters['import_type'] ?? ''));
        if ($type !== '' && array_key_exists($type, self::TYPES)) {
            $query->where('import_type', $type);
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $academicYear = trim((string) ($filters['academic_year'] ?? ''));
        if ($academicYear !== '') {
            $query->where('academic_year', $academicYear);
        }
        $semester = trim((string) ($filters['semester'] ?? ''));
        if ($semester !== '') {
            $query->where('semester', $semester);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('name', 'like', $like)->orWhere('file_sha256', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')->forPage($page, $pageSize)->get()->map(static fn ($row): array => self::batchRow($row))->all();

        return [
            'items' => $items,
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => (int) $total],
        ];
    }

    public static function batchDetail(int $id): ?array
    {
        $result = self::batchById($id);
        if (!$result) {
            return null;
        }

        $result['changes'] = self::changes($id, ['page' => 1, 'page_size' => 20]);
        $result['issues'] = self::issues($id, ['page' => 1, 'page_size' => 20]);
        return $result;
    }

    public static function createBatch(array $values): int
    {
        return (int) self::connection()->table('edu_import_batch')->insertGetId($values);
    }

    public static function updateBatch(int $id, array $values): int
    {
        return (int) self::connection()->table('edu_import_batch')->where('id', $id)->whereNull('deleted_at')->update($values);
    }

    public static function batchById(int $id, bool $lock = false): ?array
    {
        $query = self::connection()->table('edu_import_batch')->where('id', $id)->whereNull('deleted_at');
        self::applyBatchScope($query);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        return $row ? self::batchRow($row) : null;
    }

    public static function claimForParsing(int $id, string $now): ?array
    {
        $query = self::connection()->table('edu_import_batch')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['queued', 'failed']);
        self::applyBatchScope($query);
        if (!$query->update(['status' => 'parsing', 'updated_at' => $now])) {
            return null;
        }

        return self::batchById($id);
    }

    public static function runningBatch(string $type, ?string $academicYear = null, ?string $semester = null): ?array
    {
        $query = self::connection()->table('edu_import_batch')
            ->where('import_type', $type)
            ->whereIn('status', ['queued', 'parsing', 'validating', 'pending_confirm', 'publishing', 'partial_failed'])
            ->whereNull('deleted_at');
        if ($academicYear !== null && $academicYear !== '') {
            $query->where('academic_year', $academicYear);
        }
        if ($semester !== null && $semester !== '') {
            $query->where('semester', $semester);
        }
        $row = $query->orderByDesc('id')->first();
        return $row ? self::batchRow($row) : null;
    }

    public static function duplicateFile(string $sha256, string $type): ?array
    {
        $row = self::connection()->table('edu_import_batch')
            ->where('file_sha256', $sha256)
            ->where('import_type', $type)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();
        return $row ? self::batchRow($row) : null;
    }

    public static function stageRows(string $type, int $batchId, int $offset, int $limit): array
    {
        $table = self::stageTable($type);
        return self::connection()->table($table)
            ->where('batch_id', $batchId)
            ->whereNull('deleted_at')
            ->orderBy('row_number')
            ->offset(max(0, $offset))
            ->limit(min(5000, max(1, $limit)))
            ->get()
            ->map(static fn ($row): array => self::jsonRow($row, ['normalized_payload', 'raw_payload']))
            ->all();
    }

    public static function stageCount(int $batchId, string $type): int
    {
        return (int) self::connection()->table(self::stageTable($type))->where('batch_id', $batchId)->whereNull('deleted_at')->count();
    }

    public static function stageByKey(string $type, int $batchId, string $sourceKey, bool $lock = false): ?array
    {
        $query = self::connection()->table(self::stageTable($type))
            ->where('batch_id', $batchId)
            ->where('source_key', $sourceKey)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        return $row ? self::jsonRow($row, ['normalized_payload', 'raw_payload']) : null;
    }

    public static function insertStageRows(string $type, array $rows): void
    {
        if (!$rows) {
            return;
        }
        self::connection()->table(self::stageTable($type))->upsert(
            $rows,
            ['batch_id', 'source_key'],
            ['name', 'code', 'row_number', 'source_hash', 'normalized_payload', 'raw_payload', 'issue_count', 'change_type', 'before_json', 'diff_json', 'classification_source', 'classification_reason', 'updated_at', 'deleted_at']
        );
    }

    public static function clearStage(int $batchId, string $type): int
    {
        return (int) self::connection()->table(self::stageTable($type))->where('batch_id', $batchId)->delete();
    }

    /**
     * 删除已提交的暂存分块，保证中断重试时不会重复处理。
     */
    public static function deleteStageRows(string $type, array $ids): int
    {
        if (!$ids) {
            return 0;
        }
        return (int) self::connection()->table(self::stageTable($type))->whereIn('id', $ids)->delete();
    }

    public static function clearIssues(int $batchId): int
    {
        return (int) self::connection()->table('edu_import_issue')->where('batch_id', $batchId)->delete();
    }

    public static function sourceRowByKey(string $type, string $sourceKey, bool $lock = false): ?array
    {
        $query = self::connection()->table(self::sourceTable($type))->where('source_key', $sourceKey)->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        return $row ? self::jsonRow($row, ['raw_payload']) : null;
    }

    public static function sourceRowsByBatch(string $type, int $batchId, int $offset, int $limit): array
    {
        $query = self::connection()->table(self::sourceTable($type))
            ->where('last_seen_batch_id', $batchId)
            ->whereNull('deleted_at')
            ;
        self::applySourceScope($query, $type);
        return $query->orderBy('id')
            ->offset(max(0, $offset))
            ->limit(min(5000, max(1, $limit)))
            ->get()
            ->map(static fn ($row): array => self::publicSourceRow($row))
            ->all();
    }

    public static function sourcePage(string $type, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::connection()->table(self::sourceTable($type))->whereNull('deleted_at');
        self::applySourceScope($query, $type);
        foreach (['source_status', 'mapping_status', 'business_type', 'candidate_status', 'academic_year', 'semester'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('source_key', 'like', $like);
            });
        }
        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')->forPage($page, $pageSize)->get()->map(static fn ($row): array => self::publicSourceRow($row))->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => (int) $total]];
    }

    public static function upsertSourceRows(string $type, array $rows, array $uniqueBy, array $updateColumns): void
    {
        if (!$rows) {
            return;
        }
        self::connection()->table(self::sourceTable($type))->upsert($rows, $uniqueBy, $updateColumns);
    }

    public static function markMissingSources(string $type, int $batchId, string $scopeKey, string $now): int
    {
        $query = self::connection()->table(self::sourceTable($type))->whereNull('deleted_at');
        if ($scopeKey !== '') {
            $query->where(function ($builder) use ($scopeKey): void {
                $builder->where('scope_key', $scopeKey)->orWhereNull('scope_key');
            });
        }
        return (int) $query->where(function ($builder) use ($batchId): void {
            $builder->whereNull('last_seen_batch_id')->orWhere('last_seen_batch_id', '<>', $batchId);
        })->update(['source_status' => 'missing', 'updated_at' => $now]);
    }

    public static function changes(int $batchId, array $filters): array
    {
        return self::pagedRows('edu_import_change', $batchId, $filters, static function ($row): array {
            $item = self::jsonRow($row, ['before_json', 'after_json', 'diff_json']);
            $hidden = ['email', 'raw_payload', 'sensitive_payload_cipher', 'mobile_hmac', 'identity_last_six_hmac', 'source_hash'];
            foreach (['before_json', 'after_json'] as $key) {
                $item[$key] = array_diff_key((array) ($item[$key] ?? []), array_flip($hidden));
            }
            $item['diff_json'] = array_values(array_filter((array) ($item['diff_json'] ?? []), static fn ($diff): bool => is_array($diff) && !in_array($diff['field'] ?? '', $hidden, true)));
            return $item;
        });
    }

    public static function issues(int $batchId, array $filters): array
    {
        return self::pagedRows('edu_import_issue', $batchId, $filters, static fn ($row): array => self::rowArray($row));
    }

    public static function unresolvedIssueCount(int $batchId): int
    {
        return (int) self::connection()->table('edu_import_issue')
            ->where('batch_id', $batchId)
            ->where('resolved', 'false')
            ->whereNull('deleted_at')
            ->count();
    }

    public static function resolveIssue(int $id, int $accountId, string $now): int
    {
        $issue = self::connection()->table('edu_import_issue')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first(['batch_id']);
        if (!$issue || !self::batchById((int) $issue->batch_id)) {
            return 0;
        }

        return (int) self::connection()->table('edu_import_issue')
            ->where('id', $id)
            ->where('resolved', 'false')
            ->whereNull('deleted_at')
            ->update(['resolved' => 'true', 'resolved_by' => $accountId, 'resolved_at' => $now, 'updated_at' => $now]);
    }

    public static function candidatePage(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::connection()->table('edu_business_candidate')->whereNull('deleted_at');
        self::applyScope($query, 'candidate');
        foreach (['candidate_status', 'business_type', 'mapping_status'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $query->where($key, $value);
            }
        }
        $batchId = (int) ($filters['batch_id'] ?? 0);
        if ($batchId > 0) {
            $query->where('code', 'batch:' . $batchId);
        }
        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')->forPage($page, $pageSize)->get()->map(static fn ($row): array => self::jsonRow($row, ['raw_payload']))->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => (int) $total]];
    }

    public static function insertChanges(array $rows): void
    {
        if ($rows) {
            self::connection()->table('edu_import_change')->upsert($rows, ['batch_id', 'entity_type', 'source_key'], ['change_type', 'confirm_status', 'before_json', 'after_json', 'diff_json', 'updated_at']);
        }
    }

    public static function changeCounts(int $batchId): array
    {
        $rows = self::connection()->table('edu_import_change')
            ->where('batch_id', $batchId)
            ->whereNull('deleted_at')
            ->selectRaw('change_type, COUNT(*) AS total')
            ->groupBy('change_type')
            ->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->change_type] = (int) $row->total;
        }
        return $counts;
    }

    public static function insertIssues(array $rows): void
    {
        if ($rows) {
            self::connection()->table('edu_import_issue')->insert($rows);
        }
    }

    public static function upsertCandidates(array $rows): void
    {
        if ($rows) {
            self::connection()->table('edu_business_candidate')->upsert($rows, ['source_type', 'source_id', 'business_type'], ['source_key', 'classification_source', 'classification_reason', 'candidate_status', 'mapping_status', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'generated_business_id', 'raw_payload', 'updated_at', 'deleted_at']);
        }
    }

    public static function candidateById(int $id, bool $lock = false): ?array
    {
        $query = self::connection()->table('edu_business_candidate')->where('id', $id)->whereNull('deleted_at');
        self::applyScope($query, 'candidate');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        return $row ? self::jsonRow($row, ['raw_payload']) : null;
    }

    public static function updateCandidate(int $id, array $values): int
    {
        return (int) self::connection()->table('edu_business_candidate')->where('id', $id)->whereNull('deleted_at')->update($values);
    }

    public static function transaction(callable $callback): mixed
    {
        return self::connection()->transaction($callback);
    }

    private static function pagedRows(string $table, int $batchId, array $filters, callable $mapper): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::connection()->table($table)->where('batch_id', $batchId)->whereNull('deleted_at');
        if (!self::batchById($batchId)) {
            return ['items' => [], 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => 0]];
        }
        $type = trim((string) ($filters['change_type'] ?? $filters['issue_type'] ?? ''));
        if ($type !== '') {
            $query->where(str_contains($table, 'change') ? 'change_type' : 'issue_type', $type);
        }
        $resolved = trim((string) ($filters['resolved'] ?? ''));
        if ($resolved !== '' && $table === 'edu_import_issue') {
            $query->where('resolved', $resolved === 'true' ? 'true' : 'false');
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('id')->forPage($page, $pageSize)->get()->map($mapper)->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => (int) $total]];
    }

    private static function batchRow(object $row): array
    {
        return self::rowArray($row);
    }

    private static function rowArray(object $row): array
    {
        $data = [];
        foreach (get_object_vars($row) as $key => $value) {
            $data[$key] = $value;
        }
        return $data;
    }

    private static function jsonRow(object $row, array $fields): array
    {
        $data = self::rowArray($row);
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $decoded = json_decode((string) $data[$field], true);
            $data[$field] = is_array($decoded) ? $decoded : [];
        }
        return $data;
    }

    private static function publicSourceRow(object $row): array
    {
        $data = self::jsonRow($row, ['raw_payload']);
        unset($data['email'], $data['sensitive_payload_cipher'], $data['mobile_hmac'], $data['identity_last_six_hmac']);
        return $data;
    }

    private static function sourceTable(string $type): string
    {
        $table = self::TYPES[$type] ?? null;
        if (!$table) {
            throw new InvalidArgumentException('教务数据类型无效');
        }
        return $table . '_source';
    }

    private static function stageTable(string $type): string
    {
        $table = self::TYPES[$type] ?? null;
        if (!$table) {
            throw new InvalidArgumentException('教务数据类型无效');
        }
        return $table . '_stage';
    }

    private static function applyScope(mixed $query, ?string $type = null): void
    {
        $role = CurrentContext::roleType();
        if ($role === null || $role === '' || in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        }

        if ($role === 'college_admin') {
            $ids = self::scopeIds('dep_id');
            $ids ? $query->whereIn('dep_id', $ids) : $query->whereRaw('1 = 0');
        } elseif ($role === 'profession_admin') {
            $ids = self::scopeIds('profession_id');
            if (!$ids) {
                $query->whereRaw('1 = 0');
            } elseif ($type === 'course_offering') {
                $query->whereExists(function ($builder) use ($ids): void {
                    $builder->selectRaw('1')
                        ->from('edu_course_offering_class as offering_class')
                        ->join('class as scoped_class', 'scoped_class.class_id', '=', 'offering_class.class_id')
                        ->whereColumn('offering_class.offering_id', 'edu_course_offering_source.id')
                        ->whereIn('scoped_class.profession_id', $ids)
                        ->whereNull('offering_class.deleted_at')
                        ->whereNull('scoped_class.deleted_at');
                });
            } else {
                $query->whereIn('profession_id', $ids);
            }
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    private static function applySourceScope(mixed $query, string $type): void
    {
        self::applyScope($query, $type);
    }

    private static function applyBatchScope(mixed $query): void
    {
        $role = CurrentContext::roleType();
        if ($role === null || $role === '' || in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        }

        $accountId = CurrentContext::accountId();
        $field = $role === 'college_admin' ? 'dep_id' : ($role === 'profession_admin' ? 'profession_id' : null);
        $ids = $field ? self::scopeIds($field) : [];
        $query->where(function ($builder) use ($accountId, $field, $ids): void {
            if ($accountId) {
                $builder->where('created_by', $accountId);
            } else {
                $builder->whereRaw('1 = 0');
            }
            if (!$field || !$ids) {
                return;
            }
            foreach (self::TYPES as $type => $prefix) {
                $table = $prefix . '_source';
                $builder->orWhereExists(function ($source) use ($table, $field, $ids): void {
                    $source->selectRaw('1')->from($table)
                        ->whereColumn($table . '.last_seen_batch_id', 'edu_import_batch.id')
                        ->whereNull($table . '.deleted_at');
                    if ($field === 'profession_id' && $table === 'edu_course_offering_source') {
                        $source->join('edu_course_offering_class as scoped_offering_class', 'scoped_offering_class.offering_id', '=', $table . '.id')
                            ->join('class as scoped_class', 'scoped_class.class_id', '=', 'scoped_offering_class.class_id')
                            ->whereIn('scoped_class.profession_id', $ids)
                            ->whereNull('scoped_offering_class.deleted_at')
                            ->whereNull('scoped_class.deleted_at');
                        return;
                    }
                    $source->whereIn($table . '.' . $field, $ids);
                });
            }
        });
    }

    private static function scopeIds(string $field): array
    {
        $ids = [];
        foreach (CurrentContext::organizationScopes() as $scope) {
            if (!empty($scope[$field])) {
                $ids[] = (int) $scope[$field];
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }
}
