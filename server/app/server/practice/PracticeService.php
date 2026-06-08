<?php

namespace app\server\practice;

use app\model\channel\PracticeRecord;
use app\server\CurrentContext;
use InvalidArgumentException;
use RuntimeException;
use support\Request;

class PracticeService
{
    private const MODULES = [
        'training' => [
            'view' => 'training:view',
            'manage' => 'training:manage',
            'approve' => 'training:approve',
            'name' => '实训',
        ],
        'lab' => [
            'view' => 'lab:view',
            'manage' => 'lab:manage',
            'approve' => 'lab:approve',
            'name' => '实验',
        ],
    ];

    private const ENTITIES = [
        'plan' => ['permission' => 'manage', 'title' => '教学计划', 'review' => true],
        'schedule' => ['permission' => 'manage', 'title' => '课表安排', 'review' => false],
        'syllabus' => ['permission' => 'manage', 'title' => '大纲', 'review' => true],
        'lessonPlan' => ['permission' => 'manage', 'title' => '教案', 'review' => true],
        'gradeRule' => ['permission' => 'manage', 'title' => '成绩比例', 'review' => false],
        'score' => ['permission' => 'manage', 'title' => '成绩', 'review' => false],
        'reflection' => ['permission' => 'manage', 'title' => '反思报告', 'review' => true],
        'room' => ['permission' => 'manage', 'title' => '实验实训室', 'review' => false],
    ];

    private const REVIEW_RULES = [
        'plan' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 8, 'max' => 800],
        ],
        'syllabus' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 8, 'max' => 800],
        ],
        'lessonPlan' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 8, 'max' => 800],
        ],
        'reflection' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 8, 'max' => 800],
        ],
    ];

    public function __construct(private readonly string $moduleType)
    {
        if (!isset(self::MODULES[$moduleType])) {
            throw new InvalidArgumentException('模块无效');
        }
    }

    public function overview(Request $request): array
    {
        $this->requirePermission('view');

        return PracticeRecord::overviewRows($this->scopeContext(), $this->moduleType, date('Y-m-d'));
    }

    public function options(Request $request): array
    {
        $this->requirePermission('view');

        return array_merge(PracticeRecord::optionRows($this->scopeContext(), $this->moduleType), [
            'module_type' => $this->moduleType,
            'module_name' => self::MODULES[$this->moduleType]['name'],
            'review_rules' => self::REVIEW_RULES,
            'source_types' => ['jw', 'manual'],
            'place_types' => ['inside', 'outside'],
        ]);
    }

    public function list(Request $request): array
    {
        $this->requirePermission('view');
        $entity = $this->entityInput($request);

        return PracticeRecord::entityPage($this->scopeContext(), $this->moduleType, $entity, $this->requestFilters($request));
    }

    public function save(Request $request): array
    {
        $entity = $this->entityInput($request);
        $this->requireEntityPermission($entity);
        $existingId = $this->inputEntityId($request, $entity);
        $fromStatus = $existingId ? PracticeRecord::statusById($entity, $existingId) : 'draft';
        $values = $this->entityValues($request, $entity);

        if ($existingId) {
            $row = PracticeRecord::activeRowByEntity($this->moduleType, $entity, $existingId);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertEntityVisible($entity, $existingId);
        }

        $id = $this->saveEntity($entity, $request, $values);
        if (($values['status'] ?? '') === 'wait' && $this->entityRequiresReview($entity)) {
            $this->recordWorkflow($entity, $id, 'submit', $fromStatus, 'wait', $this->workflowContent($entity, $values), 'wait');
        }

        return ['id' => $id, 'uuid' => PracticeRecord::uuidById($entity, $id)];
    }

    public function review(Request $request): array
    {
        $entity = $this->entityInput($request);
        if (!$this->entityRequiresReview($entity)) {
            throw new InvalidArgumentException('该业务不需要审核');
        }
        $this->requirePermission('approve');

        $id = $this->requiredEntityId($request, $entity);
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinionInput($request, $entity, $status);

        return PracticeRecord::connection()->transaction(function () use ($entity, $id, $status, $opinion): array {
            $row = PracticeRecord::lockActiveRowByEntity($this->moduleType, $entity, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertEntityVisible($entity, $id);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('仅待审核数据可处理', 42204);
            }

            PracticeRecord::updateEntityById($entity, $id, [
                'status' => $status,
                'updated_at' => $this->now(),
            ]);
            $this->recordWorkflow($entity, $id, 'review', (string) $row->status, $status, $opinion ?: '审核处理', $status);

            return ['id' => $id, 'status' => $status];
        });
    }

    public function requestModification(Request $request): array
    {
        $entity = $this->entityInput($request);
        if (!$this->entityRequiresReview($entity)) {
            throw new InvalidArgumentException('该业务不支持通过后修改');
        }
        $this->requirePermission('approve');

        $id = $this->requiredEntityId($request, $entity);
        $opinion = $this->reviewOpinionInput($request, $entity, 'modify', '修改理由');

        return PracticeRecord::connection()->transaction(function () use ($entity, $id, $opinion): array {
            $row = PracticeRecord::lockActiveRowByEntity($this->moduleType, $entity, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertEntityVisible($entity, $id);
            if ((string) $row->status !== 'accept') {
                throw new InvalidArgumentException('仅已通过数据可改回修改', 42203);
            }

            PracticeRecord::updateEntityById($entity, $id, [
                'status' => 'modify',
                'updated_at' => $this->now(),
            ]);
            $this->recordWorkflow($entity, $id, 'modify_after_accept', 'accept', 'modify', $opinion ?: '通过后要求修改', 'modify');

            return ['id' => $id, 'status' => 'modify'];
        });
    }

    public function timeline(Request $request): array
    {
        $this->requirePermission('view');
        $entity = $this->entityInput($request);
        $id = $this->requiredEntityId($request, $entity);
        $this->assertEntityVisible($entity, $id);
        $entityType = $this->entityType($entity);
        $records = PracticeRecord::recordingRows($entityType, $id);
        $reviews = PracticeRecord::reviewOpinionRows($entityType, $id);

        return [
            'entity' => $entity,
            'id' => $id,
            'records' => $records,
            'reviews' => $reviews,
            'cycles' => $this->timelineCycles($records, $reviews),
        ];
    }

    private function entityValues(Request $request, string $entity): array
    {
        $now = $this->now();
        $common = [
            'module_type' => $this->moduleType,
            'plan_id' => $this->optionalInt($request, 'plan_id'),
            'grade_id' => $this->optionalInt($request, 'grade_id'),
            'dep_id' => $this->optionalInt($request, 'dep_id'),
            'profession_id' => $this->optionalInt($request, 'profession_id'),
            'class_id' => $this->optionalInt($request, 'class_id'),
            'teacher_id' => $this->resolveTeacherId($request),
            'course_name' => $this->nullableString($request, 'course_name', 180),
            'title' => $this->nullableString($request, 'title', 180),
            'content' => $this->nullableString($request, 'content', 30000),
            'content_json' => $this->jsonValue($request->input('content_json', [])),
            'remark' => $this->nullableString($request, 'remark', 2000),
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        return match ($entity) {
            'plan' => array_merge($common, [
                'source_type' => $this->enum($request, 'source_type', ['jw', 'manual'], 'manual'),
                'submitter_id' => CurrentContext::accountId(),
                'title' => $this->requiredTitle($request, self::ENTITIES[$entity]['title']),
                'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
            ]),
            'schedule' => array_merge($common, [
                'plan_id' => $this->optionalInt($request, 'plan_id'),
                'room_id' => $this->optionalInt($request, 'room_id'),
                'base_id' => $this->optionalInt($request, 'base_id'),
                'place_type' => $this->enum($request, 'place_type', ['inside', 'outside'], 'inside'),
                'schedule_date' => $this->dateInput($request, 'schedule_date'),
                'start_time' => $this->nullableString($request, 'start_time', 20),
                'end_time' => $this->nullableString($request, 'end_time', 20),
                'location' => $this->nullableString($request, 'location', 255),
                'student_count' => $this->optionalInt($request, 'student_count') ?? 0,
                'roster_printed_at' => $this->dateTimeInput($request, 'roster_printed_at'),
                'status' => $this->enum($request, 'status', ['draft', 'enabled', 'disabled'], 'enabled'),
            ]),
            'syllabus', 'lessonPlan', 'reflection' => array_merge($common, [
                'title' => $this->requiredTitle($request, self::ENTITIES[$entity]['title']),
                'submitter_id' => CurrentContext::accountId(),
                'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
            ]),
            'gradeRule' => array_merge($common, [
                'title' => $this->requiredTitle($request, '成绩比例'),
                'ratio_json' => $this->jsonValue($request->input('ratio_json', [])),
                'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
            ]),
            'score' => array_merge($common, [
                'student_id' => $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id'),
                'rule_id' => $this->optionalInt($request, 'rule_id'),
                'score_items' => $this->jsonValue($request->input('score_items', [])),
                'score_value' => $this->decimalInput($request, 'score_value'),
                'status' => $this->enum($request, 'status', ['draft', 'wait', 'accept'], 'accept'),
            ]),
            'room' => [
                'module_type' => $this->moduleType,
                'dep_id' => $this->optionalInt($request, 'dep_id'),
                'name' => $this->requiredString($request, 'name', 180),
                'code' => $this->nullableString($request, 'code', 120),
                'room_type' => $this->nullableString($request, 'room_type', 80),
                'capacity' => $this->optionalInt($request, 'capacity') ?? 0,
                'location' => $this->nullableString($request, 'location', 255),
                'manager_id' => $this->optionalInt($request, 'manager_id'),
                'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            default => throw new InvalidArgumentException('entity 无效'),
        };
    }

    private function saveEntity(string $entity, Request $request, array $values): int
    {
        $id = $this->inputEntityId($request, $entity);
        if ($id) {
            PracticeRecord::updateEntityById($entity, $id, $values);
            return $id;
        }

        return PracticeRecord::insertEntity($entity, array_merge($values, [
            'uuid' => $this->uuid(),
            'created_at' => $this->now(),
        ]));
    }

    private function recordWorkflow(string $entity, int $entityId, string $action, ?string $from, string $to, ?string $content, ?string $reviewStatus): int
    {
        $entityType = $this->entityType($entity);
        $recordingId = PracticeRecord::insertRecording([
            'uuid' => $this->uuid(),
            'parent_id' => $entityId,
            'module_type' => $this->moduleType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'operator_id' => CurrentContext::accountId(),
            'from_status' => $from,
            'to_status' => $to,
            'opinion' => $content,
            'content' => $content,
            'status' => 'enabled',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        if ($reviewStatus !== null) {
            PracticeRecord::insertReviewOpinion([
                'uuid' => $this->uuid(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'recording_id' => $recordingId,
                'teacher_id' => $this->currentTeacherId(false),
                'reviewer_id' => CurrentContext::accountId(),
                'opinion' => $action === 'submit' ? null : $content,
                'status' => $reviewStatus,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }

        return $recordingId;
    }

    private function timelineCycles(array $records, array $reviews): array
    {
        $reviewsByRecording = [];
        foreach ($reviews as $review) {
            $recordingId = (int) ($review['recording_id'] ?? 0);
            if ($recordingId > 0) {
                $reviewsByRecording[$recordingId][] = $review;
            }
        }

        $cycles = [];
        $current = null;
        $sequence = 0;
        foreach ($records as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            if (($record['action'] ?? '') === 'submit') {
                $sequence++;
                $cycles[] = [
                    'sequence' => $sequence,
                    'created_at' => $record['created_at'] ?? null,
                    'record' => $record,
                    'branches' => $this->timelineReviewBranches($record, $reviewsByRecording[$recordId] ?? []),
                ];
                $current = count($cycles) - 1;
                continue;
            }

            if ($current === null) {
                $sequence++;
                $cycles[] = [
                    'sequence' => $sequence,
                    'created_at' => $record['created_at'] ?? null,
                    'record' => null,
                    'branches' => [],
                ];
                $current = count($cycles) - 1;
            }

            $cycles[$current]['branches'][] = [
                'kind' => 'branch',
                'type' => 'recording',
                'created_at' => $record['created_at'] ?? null,
                'record' => $record,
                'reviews' => $this->workflowReviewsForRecord($record, $reviewsByRecording[$recordId] ?? []),
            ];
        }

        return $cycles;
    }

    private function timelineReviewBranches(array $record, array $reviews): array
    {
        $reviews = $this->workflowReviewsForRecord($record, $reviews);
        if (!$reviews) {
            return [];
        }

        $firstReview = $reviews[0];
        return [[
            'kind' => 'branch',
            'type' => 'review',
            'created_at' => $firstReview['created_at'] ?? ($record['created_at'] ?? null),
            'record' => null,
            'review' => $firstReview,
            'reviews' => $reviews,
        ]];
    }

    private function workflowReviewsForRecord(array $record, array $reviews): array
    {
        if (($record['action'] ?? '') !== 'submit') {
            return array_values($reviews);
        }

        return array_values(array_map(static function (array $review): array {
            if (($review['status'] ?? '') === 'wait') {
                $review['opinion'] = null;
            }

            return $review;
        }, $reviews));
    }

    private function scopeContext(): array
    {
        $professionIds = $this->scopeIds('profession_id');
        $studentId = $this->currentStudentId(false);

        return [
            'role_type' => CurrentContext::roleType(),
            'dep_ids' => $this->scopeIds('dep_id'),
            'profession_ids' => $professionIds,
            'profession_dep_ids' => PracticeRecord::depIdsByProfessionIds($professionIds),
            'teacher_id' => $this->currentTeacherId(false),
            'student_id' => $studentId,
            'student_profile' => $studentId ? PracticeRecord::studentProfile($studentId) : null,
        ];
    }

    private function scopeIds(string $field): array
    {
        $ids = [];
        foreach (CurrentContext::organizationScopes() as $scope) {
            if (!empty($scope[$field])) {
                $ids[] = (int) $scope[$field];
            }
        }

        return array_values(array_unique($ids));
    }

    private function requestFilters(Request $request): array
    {
        $keys = [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'plan_id', 'teacher_id',
            'room_id', 'base_id', 'place_type', 'source_type', 'date',
            'grade_id', 'dep_id', 'profession_id', 'class_id',
        ];
        $filters = [];
        foreach ($keys as $key) {
            $filters[$key] = $request->input($key);
        }

        return $filters;
    }

    private function assertEntityVisible(string $entity, int $id): void
    {
        if (!PracticeRecord::entityVisible($this->scopeContext(), $this->moduleType, $entity, $id)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }
    }

    private function requireEntityPermission(string $entity): void
    {
        $this->requirePermission(self::ENTITIES[$entity]['permission'] ?? 'manage');
    }

    private function requirePermission(string $type): void
    {
        $this->accountId();
        $code = self::MODULES[$this->moduleType][$type] ?? '';
        if (!$code || !in_array($code, CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    private function accountId(): int
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            throw new RuntimeException('请先登录', 40100);
        }

        return $accountId;
    }

    private function entityInput(Request $request): string
    {
        $entity = (string) $request->input('entity', '');
        if (!isset(self::ENTITIES[$entity])) {
            throw new InvalidArgumentException('entity 无效');
        }

        return $entity;
    }

    private function entityRequiresReview(string $entity): bool
    {
        return (bool) (self::ENTITIES[$entity]['review'] ?? false);
    }

    private function entityType(string $entity): string
    {
        return "{$this->moduleType}_{$entity}";
    }

    private function inputEntityId(Request $request, string $entity): ?int
    {
        $id = $this->optionalInt($request, 'id');
        if ($id) {
            return $id;
        }
        $uuid = $this->nullableString($request, 'uuid', 36);
        if ($uuid) {
            $id = PracticeRecord::idByUuid($entity, $uuid);
        }

        return $id ?: null;
    }

    private function requiredEntityId(Request $request, string $entity): int
    {
        $id = $this->inputEntityId($request, $entity);
        if (!$id) {
            throw new InvalidArgumentException('id 无效');
        }

        return $id;
    }

    private function resolveTeacherId(Request $request): ?int
    {
        return $this->isTeacher() ? $this->currentTeacherId(true) : $this->optionalInt($request, 'teacher_id');
    }

    private function currentTeacherId(bool $required): ?int
    {
        $teacherId = PracticeRecord::teacherIdByUser((int) CurrentContext::userId());
        if (!$teacherId && $required) {
            throw new RuntimeException('当前账号未绑定教师档案');
        }

        return $teacherId;
    }

    private function currentStudentId(bool $required): ?int
    {
        $studentId = PracticeRecord::studentIdByUser((int) CurrentContext::userId());
        if (!$studentId && $required) {
            throw new RuntimeException('当前账号未绑定学生档案');
        }

        return $studentId;
    }

    private function isTeacher(): bool
    {
        return CurrentContext::roleType() === 'teacher';
    }

    private function isStudent(): bool
    {
        return CurrentContext::roleType() === 'student';
    }

    private function requiredTitle(Request $request, string $label): string
    {
        return $this->nullableString($request, 'title', 180)
            ?: $this->requiredString($request, 'course_name', 180)
            ?: $label;
    }

    private function workflowContent(string $entity, array $values): string
    {
        return trim(implode("\n", array_filter([
            self::ENTITIES[$entity]['title'] ?? $entity,
            $values['title'] ?? null,
            $values['course_name'] ?? null,
            $values['content'] ?? null,
        ]))) ?: '提交审核';
    }

    private function reviewOpinionInput(Request $request, string $entity, string $status, ?string $label = null): ?string
    {
        $value = trim((string) $request->input('opinion', ''));
        $rule = self::REVIEW_RULES[$entity][$status] ?? ['min' => 0, 'max' => null];
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        $label ??= $status === 'modify' ? '退回原因' : '审核意见';
        $min = (int) ($rule['min'] ?? 0);
        $max = $rule['max'] ?? null;

        if ($min > 0 && $length < $min) {
            throw new InvalidArgumentException("{$label}至少 {$min} 字", 42201);
        }
        if ($max !== null && $length > (int) $max) {
            throw new InvalidArgumentException("{$label}最多 {$max} 字", 42202);
        }

        return $value === '' ? null : $value;
    }

    private function requiredInt(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$key} 无效");
        }

        return (int) $value;
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function decimalInput(Request $request, string $key): ?float
    {
        $value = $request->input($key);
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function requiredString(Request $request, string $key, int $maxLength): string
    {
        $value = $this->nullableString($request, $key, $maxLength) ?? '';
        if ($value === '') {
            throw new InvalidArgumentException("{$key} 不能为空");
        }

        return $value;
    }

    private function nullableString(Request $request, string $key, int $maxLength): ?string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function enum(Request $request, string $key, array $values, string $default): string
    {
        $value = (string) $request->input($key, $default);
        return in_array($value, $values, true) ? $value : $default;
    }

    private function dateInput(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function dateTimeInput(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}(?:\s|T)\d{2}:\d{2}/', $value) ? str_replace('T', ' ', substr($value, 0, 19)) : null;
    }

    private function jsonValue(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
