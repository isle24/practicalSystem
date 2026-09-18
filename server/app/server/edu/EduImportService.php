<?php

namespace app\server\edu;

use app\model\channel\EduDataRecord;
use app\model\channel\FileRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\file\FileService;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Throwable;
use Webman\Http\UploadFile;
use Webman\RedisQueue\Redis as RedisQueue;

class EduImportService
{
    public const QUEUE = 'edu_import';
    private const CHUNK_SIZE = 2000;
    private const PUBLISH_CHUNK_SIZE = 100;
    private const PUBLISH_LOCK_TTL = 1800;
    private const TYPES = ['student', 'teaching_plan', 'course_offering'];

    public function __construct(
        private ?EduTemplateService $templateService = null,
        private ?EduSpreadsheetReader $reader = null,
        private ?EduIdentityCipher $cipher = null,
        private ?EduStudentSyncService $studentSync = null
    ) {
        $this->templateService ??= new EduTemplateService();
        $this->reader ??= new EduSpreadsheetReader();
        $this->cipher ??= new EduIdentityCipher();
        $this->studentSync ??= new EduStudentSyncService();
    }

    public function template(string $type): array
    {
        return $this->templateService->template($type);
    }

    public function upload(Request $request, string $type): array
    {
        $this->assertType($type);
        $this->assertImportPermission();
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            throw new InvalidArgumentException('上传文件无效');
        }
        $validated = $this->templateService->validateUpload($file, $type);
        $sha256 = hash_file('sha256', $validated['path']);
        if (!is_string($sha256) || $sha256 === '') {
            throw new RuntimeException('文件校验失败');
        }
        $academicYear = trim((string) $request->input('academic_year', ''));
        $semester = trim((string) $request->input('semester', ''));
        if ($type !== 'student' && ($academicYear === '' || $semester === '')) {
            throw new InvalidArgumentException('开课数据必须填写学年和学期');
        }
        $scopeKey = $type === 'student' ? 'school' : $academicYear . ':' . $semester;
        $lockKey = sprintf('edu:import:%d:%s:%s', CurrentContext::schoolDatabaseId() ?: 0, $type, $scopeKey);

        return (new WorkflowLock())->run($lockKey, function () use ($request, $file, $type, $sha256, $academicYear, $semester, $scopeKey): array {
            if (EduDataRecord::duplicateFile($sha256, $type)) {
                throw new RuntimeException('相同文件已经导入过', 409);
            }
            if (EduDataRecord::runningBatch($type, $academicYear, $semester)) {
                throw new RuntimeException('同类型数据正在导入，请等待当前批次完成', 409);
            }

            $stored = (new FileService())->upload($request, [
                'category' => 'edu_import',
                'is_temporary' => false,
                'require_md5' => false,
                'max_size' => EduTemplateService::MAX_FILE_SIZE,
                'allowed_extensions' => ['xls', 'xlsx'],
                'download_name' => $file->getUploadName() ?: $type . '-import.xlsx',
            ]);
            $now = date('Y-m-d H:i:s');
            $batchId = EduDataRecord::createBatch([
                'uuid' => $this->uuid(),
                'name' => $file->getUploadName() ?: $type . '-import.xlsx',
                'code' => $type,
                'import_type' => $type,
                'file_id' => (int) ($stored['file_id'] ?? 0),
                'file_sha256' => strtolower($sha256),
                'academic_year' => $academicYear ?: null,
                'semester' => $semester ?: null,
                'scope_key' => $scopeKey,
                'status' => 'queued',
                'progress' => 0,
                'created_by' => CurrentContext::accountId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            try {
                RedisQueue::send(self::QUEUE, [
                    'database_id' => CurrentContext::schoolDatabaseId(),
                    'batch_id' => $batchId,
                    'import_type' => $type,
                ]);
            } catch (Throwable $exception) {
                EduDataRecord::updateBatch($batchId, ['status' => 'failed', 'error_message' => '导入任务入队失败', 'updated_at' => $now]);
                throw new RuntimeException('导入任务入队失败', 503, $exception);
            }

            return EduDataRecord::batchDetail($batchId) ?: ['id' => $batchId];
        }, 60, true);
    }

    public function parse(int $batchId): array
    {
        $batch = EduDataRecord::claimForParsing($batchId, date('Y-m-d H:i:s'));
        if (!$batch) {
            $batch = EduDataRecord::batchById($batchId);
            if (!$batch) {
                throw new RuntimeException('导入批次不存在');
            }
            return $batch;
        }
        $type = (string) ($batch['import_type'] ?? '');
        $this->assertType($type);
        if ((string) ($batch['status'] ?? '') !== 'parsing') {
            return $batch;
        }
        try {
            return $this->runParse($batch, $type);
        } finally {
            $this->reader->release();
        }
    }

    private function runParse(array $batch, string $type): array
    {
        $batchId = (int) $batch['id'];
        $path = $this->filePath((int) ($batch['file_id'] ?? 0));
        $file = FileRecord::detailById((int) ($batch['file_id'] ?? 0));
        $extension = strtolower((string) ($file->ext ?? ''));
        $this->reader->readHeader($path, $type, $extension);
        $totalRows = max(0, $this->reader->rowCount($path, $extension) - 1);
        $limit = $this->templateService->definitionFor($type)['row_limit'];
        if ($totalRows > $limit) {
            throw new InvalidArgumentException('数据行数超过当前类型限制');
        }
        $now = date('Y-m-d H:i:s');
        EduDataRecord::clearStage($batchId, $type);
        EduDataRecord::clearIssues($batchId);
        EduDataRecord::updateBatch($batchId, ['status' => 'parsing', 'total_rows' => $totalRows, 'progress' => 0, 'error_message' => null, 'updated_at' => $now]);
        $normalizer = new EduImportNormalizer($this->cipher);
        $offset = 0;
        $validRows = 0;
        $invalidRows = 0;
        $seenRows = 0;
        $academicYear = (string) ($batch['academic_year'] ?? '');
        $semester = (string) ($batch['semester'] ?? '');

        while ($offset < $totalRows) {
            $stageRows = [];
            $issues = [];
            foreach ($this->reader->readChunk($path, $type, $offset + 2, self::CHUNK_SIZE, $extension) as $item) {
                $rowNumber = (int) ($item['row_number'] ?? 0);
                $normalized = $normalizer->normalize($type, (array) ($item['values'] ?? []), $rowNumber);
                $sourceKey = (string) ($normalized['source_key'] ?? '');
                $data = (array) ($normalized['data'] ?? []);
                if ($academicYear === '' && isset($data['academic_year'])) {
                    $academicYear = (string) $data['academic_year'];
                }
                if ($semester === '' && isset($data['semester'])) {
                    $semester = (string) $data['semester'];
                }
                $existing = EduDataRecord::stageByKey($type, $batchId, $sourceKey);
                $rowIssues = (array) ($normalized['issues'] ?? []);
                if ($existing) {
                    $rowIssues[] = ['field_name' => '来源键', 'issue_type' => 'duplicate_key', 'message' => '文件内来源键重复', 'raw_value_masked' => $sourceKey];
                }
                if ($rowIssues) {
                    $invalidRows++;
                    foreach ($rowIssues as $issue) {
                        $issues[] = [
                            'uuid' => $this->uuid(),
                            'batch_id' => $batchId,
                            'row_number' => $rowNumber,
                            'field_name' => $issue['field_name'] ?? null,
                            'issue_type' => $issue['issue_type'] ?? 'invalid',
                            'message' => $issue['message'] ?? '数据无效',
                            'raw_value_masked' => $this->mask((string) ($issue['raw_value_masked'] ?? '')),
                            'resolved' => 'false',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                } else {
                    $validRows++;
                }
                $before = $rowIssues ? null : EduDataRecord::sourceRowByKey($type, $sourceKey);
                $changeType = null;
                if (!$rowIssues) {
                    $changeType = !$before
                        ? 'created'
                        : ((string) ($before['source_hash'] ?? '') !== (string) ($data['source_hash'] ?? '') ? 'updated' : null);
                }
                $stageRows[] = [
                    'uuid' => $this->uuid(),
                    'batch_id' => $batchId,
                    'source_key' => $sourceKey,
                    'row_number' => $rowNumber,
                    'source_hash' => (string) ($data['source_hash'] ?? ''),
                    'normalized_payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'raw_payload' => json_encode($normalized['raw_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'issue_count' => count($rowIssues),
                    'change_type' => $changeType,
                    'before_json' => json_encode($this->safeBefore($before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'diff_json' => json_encode($this->diff($before ?: [], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'classification_source' => $normalized['classification_source'] ?? null,
                    'classification_reason' => $normalized['classification_reason'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];
                $seenRows++;
            }
            EduDataRecord::insertStageRows($type, $stageRows);
            EduDataRecord::insertIssues($issues);
            $offset += self::CHUNK_SIZE;
            $progress = $totalRows > 0 ? min(99, (int) floor(($seenRows / $totalRows) * 100)) : 100;
            EduDataRecord::updateBatch($batchId, ['status' => 'validating', 'progress' => $progress, 'valid_rows' => $validRows, 'invalid_rows' => $invalidRows, 'academic_year' => $academicYear ?: null, 'semester' => $semester ?: null, 'updated_at' => $now]);
        }

        if ($seenRows === 0 || $validRows === 0) {
            EduDataRecord::updateBatch($batchId, ['status' => 'failed', 'progress' => 0, 'valid_rows' => $validRows, 'invalid_rows' => $invalidRows, 'error_message' => '没有可发布的有效数据', 'updated_at' => $now]);
            throw new InvalidArgumentException('Excel 中没有可发布的有效数据');
        }
        EduDataRecord::updateBatch($batchId, ['status' => 'pending_confirm', 'progress' => 100, 'valid_rows' => $validRows, 'invalid_rows' => $invalidRows, 'updated_at' => $now]);
        return EduDataRecord::batchDetail($batchId) ?: ['id' => $batchId, 'status' => 'pending_confirm'];
    }

    public function publish(int $batchId, int $accountId): array
    {
        $this->assertConfirmPermission();
        return (new WorkflowLock())->run('edu:publish:' . $batchId, function () use ($batchId, $accountId): array {
            try {
                return $this->runPublish($batchId, $accountId);
            } catch (Throwable $exception) {
                $this->restorePendingConfirm($batchId, $exception);
                throw $exception;
            }
        }, self::PUBLISH_LOCK_TTL);
    }

    /**
     * 按分块事务发布，并记录已提交进度，中断后可从断点继续，不会产生半个快照。
     */
    private function runPublish(int $batchId, int $accountId): array
    {
        $batch = EduDataRecord::batchById($batchId, true);
        if (!$batch) {
            throw new RuntimeException('导入批次不存在');
        }
        if (!in_array((string) ($batch['status'] ?? ''), ['pending_confirm', 'publishing', 'partial_failed'], true)) {
            throw new RuntimeException('当前批次不可发布', 409);
        }
        return $this->publishInTransaction($batchId, $accountId);
    }

    private function publishInTransaction(int $batchId, int $accountId): array
    {
        $batch = EduDataRecord::batchById($batchId, true);
        if (!$batch) {
            throw new RuntimeException('导入批次不存在');
        }
        if (!in_array((string) ($batch['status'] ?? ''), ['pending_confirm', 'publishing', 'partial_failed'], true)) {
            throw new RuntimeException('当前批次不可发布', 409);
        }
        if (EduDataRecord::unresolvedIssueCount($batchId) > 0) {
            throw new RuntimeException('存在未解决的导入问题，请处理后再发布', 422);
        }
        $type = (string) $batch['import_type'];
        $stageRows = EduDataRecord::stageRows($type, $batchId, 0, self::PUBLISH_CHUNK_SIZE);
        if (!$stageRows && EduDataRecord::stageCount($batchId, $type) === 0 && (string) ($batch['status'] ?? '') === 'pending_confirm') {
            throw new RuntimeException('批次暂存数据已失效，请取消后重新导入', 409);
        }
        $stageRows = $stageRows ?: [];
        $now = date('Y-m-d H:i:s');
        $counts = ['created' => 0, 'updated' => 0, 'missing' => 0, 'disabled' => 0, 'candidates' => 0];
        $offset = 0;
        $teacherSync = new EduTeacherSyncService();
        $candidateService = new EduBusinessCandidateService();
        while ($stageRows) {
            $chunkCounts = ['created' => 0, 'updated' => 0, 'candidates' => 0];
            $chunk = $stageRows;
            $offset += count($chunk);
            EduDataRecord::updateBatch($batchId, ['status' => 'publishing', 'confirmed_by' => $accountId, 'confirmed_at' => $now, 'updated_at' => $now]);
            EduDataRecord::transaction(function () use ($chunk, $type, $batchId, $accountId, $now, $teacherSync, $candidateService, &$chunkCounts): void {
                foreach ($chunk as $stage) {
                    $data = is_array($stage['normalized_payload'] ?? null)
                        ? $stage['normalized_payload']
                        : $this->decode((string) ($stage['normalized_payload'] ?? ''));
                    $raw = is_array($stage['raw_payload'] ?? null)
                        ? $stage['raw_payload']
                        : $this->decode((string) ($stage['raw_payload'] ?? ''));
                    $sourceKey = (string) ($stage['source_key'] ?? '');
                    $before = is_array($stage['before_json'] ?? null)
                        ? $stage['before_json']
                        : $this->decode((string) ($stage['before_json'] ?? ''));
                    $changeType = $stage['change_type'] ?? null;
                    $mapped = $this->mapAndSync($type, $data, $raw, $this->studentSync, $teacherSync, $now);
                    $data = array_merge($data, $mapped['data']);
                    $sourceId = $this->upsertSource($type, $data, $batchId, $now);
                    if ($changeType) {
                        EduDataRecord::insertChanges([[
                            'uuid' => $this->uuid(),
                            'batch_id' => $batchId,
                            'entity_type' => $type,
                            'source_key' => $sourceKey,
                            'change_type' => $changeType,
                            'confirm_status' => 'confirmed',
                            'before_json' => json_encode($this->safeBefore($before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'after_json' => json_encode($this->safeBefore($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'diff_json' => json_encode($this->diff($before, $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'confirmed_by' => $accountId,
                            'confirmed_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                            'deleted_at' => null,
                        ]]);
                        $chunkCounts[$changeType === 'created' ? 'created' : 'updated']++;
                    }
                    if ($type !== 'student' && (($stage['classification_source'] ?? null) === 'flag' || !empty($data['business_type']))) {
                        $data['batch_id'] = $batchId;
                        $candidateService->syncCandidate($type, $sourceId, $data, $stage['classification_source'] ?? null, $stage['classification_reason'] ?? null, $now);
                        $chunkCounts['candidates']++;
                    }
                }
                // 已提交的分块立即删除暂存，中断后不会重复处理
                EduDataRecord::deleteStageRows($type, array_map(static fn (array $row): int => (int) $row['id'], $chunk));
            });
            $counts['created'] += $chunkCounts['created'];
            $counts['updated'] += $chunkCounts['updated'];
            $counts['candidates'] += $chunkCounts['candidates'];
            $stageRows = EduDataRecord::stageRows($type, $batchId, 0, self::PUBLISH_CHUNK_SIZE);
            EduDataRecord::updateBatch($batchId, ['progress' => min(99, (int) floor(($offset / max(1, (int) ($batch['valid_rows'] ?? 1))) * 100)), 'updated_at' => $now]);
        }
        $scopeKey = (string) ($batch['scope_key'] ?? '');
        $counts['missing'] = EduDataRecord::markMissingSources($type, $batchId, $scopeKey, $now);
        EduDataRecord::clearStage($batchId, $type);
        $changeCounts = EduDataRecord::changeCounts($batchId);
        $counts['created'] = (int) ($changeCounts['created'] ?? 0);
        $counts['updated'] = (int) ($changeCounts['updated'] ?? 0);
        $completedAt = date('Y-m-d H:i:s');
        EduDataRecord::updateBatch($batchId, [
            'status' => 'completed',
            'progress' => 100,
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'missing_count' => $counts['missing'],
            'disabled_count' => $counts['disabled'],
            'completed_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);
        return array_merge(['batch_id' => $batchId, 'status' => 'completed'], $counts);
    }

    /**
     * 发布失败时回到待确认状态，保留暂存数据以便重试。
     */
    private function restorePendingConfirm(int $batchId, Throwable $exception): void
    {
        try {
            $batch = EduDataRecord::batchById($batchId);
            if ($batch && in_array((string) ($batch['status'] ?? ''), ['publishing', 'partial_failed'], true)) {
                EduDataRecord::updateBatch($batchId, [
                    'status' => 'partial_failed',
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (Throwable) {
        }
    }

    public function cancel(int $batchId, int $accountId): array
    {
        $this->assertConfirmPermission();
        $batch = EduDataRecord::batchById($batchId, true);
        if (!$batch || !in_array((string) ($batch['status'] ?? ''), ['queued', 'parsing', 'validating', 'pending_confirm', 'partial_failed'], true)) {
            throw new RuntimeException('当前批次不可取消', 409);
        }
        $now = date('Y-m-d H:i:s');
        EduDataRecord::clearStage($batchId, (string) $batch['import_type']);
        EduDataRecord::updateBatch($batchId, ['status' => 'cancelled', 'confirmed_by' => $accountId, 'confirmed_at' => $now, 'updated_at' => $now]);
        return ['batch_id' => $batchId, 'status' => 'cancelled'];
    }

    public function classifyCandidate(int $id, string $businessType, int $accountId): array
    {
        $this->assertConfirmPermission();
        return (new EduBusinessCandidateService())->classify($id, $businessType, $accountId, date('Y-m-d H:i:s'));
    }

    public function confirmCandidates(array $ids, int $accountId): array
    {
        $this->assertConfirmPermission();
        return (new EduBusinessCandidateService())->confirm($ids, $accountId, date('Y-m-d H:i:s'));
    }

    public function page(string $type, array $filters): array
    {
        $this->assertViewPermission();
        $this->assertType($type);
        return EduDataRecord::sourcePage($type, $filters);
    }

    public function batches(array $filters): array
    {
        $this->assertViewPermission();
        return EduDataRecord::batchPage($filters);
    }

    public function detail(int $id): array
    {
        $this->assertViewPermission();
        $detail = EduDataRecord::batchDetail($id);
        if (!$detail) {
            throw new RuntimeException('导入批次不存在');
        }
        return $detail;
    }

    public function issues(int $batchId, array $filters): array
    {
        $this->assertViewPermission();
        return EduDataRecord::issues($batchId, $filters);
    }

    public function changes(int $batchId, array $filters): array
    {
        $this->assertViewPermission();
        return EduDataRecord::changes($batchId, $filters);
    }

    public function resolveIssue(int $issueId, int $accountId): array
    {
        $this->assertIssuePermission();
        $resolved = EduDataRecord::resolveIssue($issueId, $accountId, date('Y-m-d H:i:s'));
        if ($resolved < 1) {
            throw new RuntimeException('问题不存在、已处理或无权处理', 409);
        }
        return ['issue_id' => $issueId, 'resolved' => true];
    }

    public function candidates(array $filters): array
    {
        $this->assertViewPermission();
        return EduDataRecord::candidatePage($filters);
    }

    private function mapAndSync(string $type, array $data, array $raw, EduStudentSyncService $studentSync, EduTeacherSyncService $teacherSync, string $now): array
    {
        $classificationSource = null;
        $classificationReason = null;
        if ($type === 'student') {
            $mapping = $studentSync->sync($data, $now);
            $data = array_merge($data, $mapping);
        } elseif ($type === 'teaching_plan') {
            try {
                $mapping = $studentSync->resolveScope([
                    'grade_code' => $data['grade_code'] ?? '',
                    'grade_name' => $data['grade_name'] ?? '',
                    'dep_code' => $data['dep_code'] ?? '',
                    'dep_name' => $data['dep_name'] ?? '',
                    'profession_code' => $data['profession_code'] ?? '',
                    'profession_name' => $data['profession_name'] ?? '',
                ], $now);
                $data = array_merge($data, $mapping, ['mapping_status' => 'matched']);
            } catch (Throwable) {
                $data['mapping_status'] = 'failed';
            }
            $classificationSource = $this->classificationSource($data);
            $classificationReason = $this->classificationReason($data);
        } else {
            $data['dep_id'] = $this->departmentId((string) ($data['open_dep_code'] ?? ''), (string) ($data['open_dep_name'] ?? ''));
            $teachers = $teacherSync->syncOffering($data, $raw, $now);
            $data['mapping_status'] = $data['dep_id'] ? 'matched' : 'pending';
            $classificationSource = $this->classificationSource($data);
            $classificationReason = $this->classificationReason($data);
            $data['teachers'] = $teachers;
        }
        return ['data' => $data, 'classification_source' => $classificationSource, 'classification_reason' => $classificationReason];
    }

    private function upsertSource(string $type, array $data, int $batchId, string $now): int
    {
        $teachers = (array) ($data['teachers'] ?? []);
        unset($data['teachers']);
        $data['uuid'] = $data['uuid'] ?? $this->uuid();
        $data['name'] = $data['name'] ?? ($data['student_name'] ?? $data['course_name'] ?? null);
        $data['code'] = $data['code'] ?? ($data['student_num'] ?? $data['course_code'] ?? null);
        $data['last_seen_batch_id'] = $batchId;
        $data['last_seen_at'] = $now;
        $data['updated_at'] = $now;
        $data['deleted_at'] = null;
        $raw = $data['raw_payload'] ?? [];
        $data['raw_payload'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data['created_at'] = $now;
        $data = array_intersect_key($data, array_flip($this->sourceColumns($type)));
        $columns = array_keys($data);
        $unique = ['source_key'];
        $updates = array_values(array_diff($columns, ['source_key', 'uuid', 'created_at']));
        EduDataRecord::upsertSourceRows($type, [$data], $unique, $updates);
        $source = EduDataRecord::sourceRowByKey($type, (string) ($data['source_key'] ?? ''));
        if (!$source) {
            throw new RuntimeException('教务源数据写入失败');
        }
        if ($type === 'course_offering') {
            if ($teachers) {
                $this->syncOfferingRelations((int) $source['id'], $teachers, $now);
            }
            $this->syncOfferingClasses((int) $source['id'], (string) ($data['class_composition'] ?? ''), $now);
        }
        return (int) $source['id'];
    }

    private function sourceColumns(string $type): array
    {
        $common = ['uuid', 'name', 'code', 'source_key', 'source_status', 'mapping_status', 'raw_payload', 'source_hash', 'scope_key', 'last_seen_batch_id', 'last_seen_at', 'created_at', 'updated_at', 'deleted_at'];
        return array_merge($common, match ($type) {
            'student' => ['student_num', 'student_name', 'gender', 'grade_code', 'grade_name', 'dep_code', 'dep_name', 'profession_code', 'profession_name', 'class_num', 'class_name', 'student_status', 'campus_status', 'enrollment_status', 'education_level', 'training_level', 'enrollment_date', 'graduation_year', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'student_id', 'mobile_hmac', 'identity_last_six_hmac', 'sensitive_payload_cipher'],
            'teaching_plan' => ['academic_year', 'semester', 'grade_code', 'grade_name', 'dep_code', 'dep_name', 'profession_code', 'profession_name', 'course_code', 'course_name', 'course_category', 'course_nature', 'credit', 'weekly_hours', 'total_hours', 'theory_hours', 'experiment_hours', 'practice_hours', 'other_hours', 'business_type', 'candidate_status', 'grade_id', 'dep_id', 'profession_id'],
            'course_offering' => ['teaching_class_id', 'academic_year', 'semester', 'course_code', 'course_name', 'open_dep_code', 'open_dep_name', 'class_composition', 'teacher_numbers', 'teacher_names', 'teaching_type', 'course_nature', 'start_week', 'end_week', 'time_text', 'location', 'business_type', 'candidate_status', 'grade_code', 'dep_id', 'course_id'],
            default => throw new InvalidArgumentException('教务数据类型无效'),
        });
    }

    private function syncOfferingRelations(int $offeringId, array $teachers, string $now): void
    {
        $connection = EduDataRecord::connection();
        $connection->table('edu_course_offering_teacher')->where('offering_id', $offeringId)->update(['status' => 'disabled', 'updated_at' => $now]);
        foreach ($teachers as $teacher) {
            $connection->table('edu_course_offering_teacher')->upsert([[
                'uuid' => $this->uuid(),
                'offering_id' => $offeringId,
                'teacher_num' => $teacher['teacher_num'],
                'teacher_id' => $teacher['teacher_id'],
                'name' => $teacher['teacher_name'],
                'teacher_role' => $teacher['teacher_role'],
                'sort' => $teacher['sort'],
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]], ['offering_id', 'teacher_num'], ['teacher_id', 'name', 'teacher_role', 'sort', 'status', 'updated_at', 'deleted_at']);
        }
    }

    /**
     * 教学班组成保存的是班级名称，需同时兼容班号与班级名称匹配。
     */
    private function syncOfferingClasses(int $offeringId, string $composition, string $now): void
    {
        $connection = EduDataRecord::connection();
        $connection->table('edu_course_offering_class')->where('offering_id', $offeringId)->update(['status' => 'disabled', 'updated_at' => $now]);
        foreach ($this->splitClasses($composition) as $sort => $label) {
            $classId = $this->studentSync->resolveClassId($label, $label);
            $connection->table('edu_course_offering_class')->upsert([[
                'uuid' => $this->uuid(),
                'offering_id' => $offeringId,
                'name' => $label,
                'class_num' => $label,
                'class_id' => $classId,
                'sort' => $sort,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]], ['offering_id', 'class_num'], ['name', 'class_id', 'sort', 'status', 'updated_at', 'deleted_at']);
        }
    }

    /**
     * 教学班组成使用英文分号分隔班号。
     */
    private function splitClasses(string $composition): array
    {
        $composition = trim($composition);
        if ($composition === '') {
            return [];
        }
        $parts = array_map('trim', preg_split('/[;；]/u', $composition) ?: []);
        return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
    }

    /**
     * 学院以名称为准匹配；命中后若原行缺少学院代码，用本次传入的代码回填。
     */
    private function departmentId(string $code, string $name): ?int
    {
        if ($code === '' && $name === '') {
            return null;
        }
        $connection = EduDataRecord::connection();
        $query = $connection->table('department')->whereNull('deleted_at')->where(function ($builder) use ($code, $name): void {
            if ($name !== '') {
                $builder->where('dep_name', $name);
            }
            if ($code !== '') {
                $builder->orWhere('dep_code', $code);
            }
        });
        $row = $query->first(['dep_id', 'dep_code']);
        if (!$row) {
            return null;
        }
        if ($code !== '' && (string) ($row->dep_code ?? '') === '') {
            $connection->table('department')->where('dep_id', (int) $row->dep_id)->update(['dep_code' => $code, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        return (int) $row->dep_id;
    }

    private function classificationSource(array $data): ?string
    {
        return !empty($data['business_type']) ? ($data['business_type'] === 'pending' ? 'flag' : 'auto') : null;
    }

    private function classificationReason(array $data): ?string
    {
        return !empty($data['business_type']) ? '按课程名称、学时类型或学时字段自动识别' : null;
    }

    private function safeBefore(?array $row): array
    {
        if (!$row) {
            return [];
        }
        unset($row['email'], $row['sensitive_payload_cipher'], $row['mobile_hmac'], $row['identity_last_six_hmac'], $row['teachers']);
        return $row;
    }

    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (in_array($key, ['email', 'raw_payload', 'teachers', 'updated_at', 'last_seen_at'], true)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $old = $before[$key] ?? null;
            if (is_array($old) || is_object($old)) {
                continue;
            }
            if ((string) $old !== (string) $value) {
                $changes[] = ['field' => $key, 'before' => $old, 'after' => $value];
            }
        }
        return $changes;
    }

    private function filePath(int $fileId): string
    {
        $row = FileRecord::detailById($fileId);
        if (!$row || (string) ($row->category ?? '') !== 'edu_import') {
            throw new RuntimeException('教务导入文件不存在');
        }
        $path = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row->path), DIRECTORY_SEPARATOR);
        $absolute = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
        if (!is_file($absolute)) {
            throw new RuntimeException('教务导入文件已丢失');
        }
        return $absolute;
    }

    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return mb_strlen($value) > 6 ? mb_substr($value, 0, 2) . '***' . mb_substr($value, -2) : '***';
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('教务数据类型无效');
        }
    }

    private function assertViewPermission(): void
    {
        if (!in_array('edu:data:view', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 403);
        }
    }

    private function assertImportPermission(): void
    {
        if (!in_array('edu:data:import', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 403);
        }
    }

    private function assertConfirmPermission(): void
    {
        if (!in_array('edu:data:confirm', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 403);
        }
    }

    private function assertIssuePermission(): void
    {
        if (!in_array('edu:data:issue', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 403);
        }
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
