<?php

namespace app\server\edu;

use app\model\channel\EduDataRecord;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EduBusinessCandidateService
{
    public function syncCandidate(string $sourceType, int $sourceId, array $data, ?string $classificationSource, ?string $classificationReason, string $now): void
    {
        $businessType = $data['business_type'] ?? null;
        if ($businessType === null && $classificationSource !== 'flag') {
            return;
        }
        EduDataRecord::upsertCandidates([[
            'uuid' => $this->uuid(),
            'name' => (string) ($data['course_name'] ?? $data['student_name'] ?? ''),
            'code' => 'batch:' . (string) ($data['batch_id'] ?? ''),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_key' => (string) ($data['source_key'] ?? ''),
            'business_type' => $businessType ?: 'pending',
            'classification_source' => $classificationSource ?: 'manual',
            'classification_reason' => $classificationReason,
            'candidate_status' => 'pending',
            'mapping_status' => (string) ($data['mapping_status'] ?? 'pending'),
            'grade_id' => $data['grade_id'] ?? null,
            'dep_id' => $data['dep_id'] ?? null,
            'profession_id' => $data['profession_id'] ?? null,
            'class_id' => $data['class_id'] ?? null,
            'raw_payload' => json_encode($data['raw_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]]);
    }

    public function classify(int $id, string $businessType, int $accountId, string $now): array
    {
        if (!in_array($businessType, ['internship', 'training', 'lab', 'social_practice', 'ignore'], true)) {
            throw new InvalidArgumentException('业务分类无效');
        }
        $candidate = EduDataRecord::candidateById($id, true);
        if (!$candidate) {
            throw new RuntimeException('业务候选不存在');
        }
        $status = $businessType === 'ignore' ? 'ignored' : 'pending';
        EduDataRecord::updateCandidate($id, [
            'business_type' => $businessType === 'ignore' ? 'pending' : $businessType,
            'classification_source' => 'manual',
            'candidate_status' => $status,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'updated_at' => $now,
        ]);
        return ['id' => $id, 'business_type' => $businessType, 'candidate_status' => $status, 'operator_id' => $accountId];
    }

    /**
     * 草稿写入与候选状态更新在同一事务内完成；已生成的候选直接返回既有草稿，重复确认保持幂等。
     */
    public function confirm(array $ids, int $accountId, string $now): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))) as $id) {
            try {
                $existing = EduDataRecord::connection()->table('edu_business_candidate')
                    ->where('id', $id)
                    ->whereNotNull('generated_business_id')
                    ->value('generated_business_id');
                if ($existing) {
                    $skipped++;
                    continue;
                }
                EduDataRecord::transaction(function () use ($id, $accountId, $now): void {
                    $candidate = EduDataRecord::candidateById($id, true);
                    if (!$candidate || ($candidate['candidate_status'] ?? '') !== 'pending') {
                        throw new InvalidArgumentException('候选项状态不允许确认');
                    }
                    if ((string) ($candidate['business_type'] ?? '') === 'pending') {
                        throw new InvalidArgumentException('候选项尚未完成分类');
                    }
                    $payload = is_array($candidate['raw_payload'] ?? null) ? $candidate['raw_payload'] : [];
                    $businessId = $this->createPlan($candidate, $payload, $accountId, $now);
                    EduDataRecord::updateCandidate($id, ['candidate_status' => 'generated', 'generated_business_id' => $businessId, 'confirmed_by' => $accountId, 'confirmed_at' => $now, 'updated_at' => $now]);
                });
                $created++;
            } catch (InvalidArgumentException $exception) {
                if ($exception->getMessage() === '候选项状态不允许确认') {
                    $skipped++;
                    continue;
                }
                $errors[] = ['id' => $id, 'message' => $exception->getMessage()];
            } catch (Throwable $exception) {
                $errors[] = ['id' => $id, 'message' => $exception->getMessage()];
            }
        }
        return ['created' => $created, 'skipped' => $skipped, 'failed' => count($errors), 'errors' => $errors];
    }

    private function createPlan(array $candidate, array $payload, int $accountId, string $now): int
    {
        $type = (string) ($candidate['business_type'] ?? '');
        $connection = EduDataRecord::connection();
        if ($type === 'internship') {
            return (int) $connection->table('internship_plan')->insertGetId([
                'uuid' => $this->uuid(),
                'source_type' => 'excel',
                'source_plan_id' => (string) ($candidate['source_key'] ?? ''),
                'course_code' => $payload['课程代码'] ?? null,
                'course_name' => $payload['课程名称'] ?? null,
                'course_category' => $payload['课程类别'] ?? null,
                'category_id' => null,
                'grade_id' => $candidate['grade_id'] ?? null,
                'dep_id' => $candidate['dep_id'] ?? null,
                'profession_id' => $candidate['profession_id'] ?? null,
                'semester' => trim((string) (($payload['学年'] ?? '') . '-' . ($payload['学期'] ?? ''))),
                'credit' => $payload['学分'] ?? null,
                'total_hours' => $payload['总学时'] ?? null,
                'source_row' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'imported_at' => $now,
                'submitter_id' => $accountId,
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        if ($type === 'social_practice') {
            return (int) $connection->table('social_practice_plan')->insertGetId([
                'uuid' => $this->uuid(),
                'source_type' => 'edu_excel',
                'source_key' => (string) ($candidate['source_key'] ?? ''),
                'title' => (string) ($payload['课程名称'] ?? ''),
                'grade_id' => (int) ($candidate['grade_id'] ?? 0),
                'organizer_dep_id' => $candidate['dep_id'] ?? null,
                'credit' => (float) ($payload['学分'] ?? 0),
                'submitter_id' => $accountId,
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return (int) $connection->table('practice_plan')->insertGetId([
            'uuid' => $this->uuid(),
            'module_type' => $type === 'lab' ? 'lab' : 'training',
            'grade_id' => $candidate['grade_id'] ?? null,
            'dep_id' => $candidate['dep_id'] ?? null,
            'profession_id' => $candidate['profession_id'] ?? null,
            'course_name' => (string) ($payload['课程名称'] ?? ''),
            'title' => (string) ($payload['课程名称'] ?? ''),
            'content_json' => json_encode(['source_key' => $candidate['source_key'] ?? '', 'source_payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_type' => 'edu_excel',
            'submitter_id' => $accountId,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
