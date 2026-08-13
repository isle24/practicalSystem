<?php

namespace app\server\practice;

use app\model\channel\Account;
use app\model\channel\PracticePeriod;
use app\model\channel\PracticeRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\message\MessageService;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Throwable;

class PracticeService
{
    private const MODULES = [
        'training' => [
            'name' => '实训',
        ],
        'lab' => [
            'name' => '实验',
        ],
        'all' => ['name' => '实验实训'],
    ];

    private const PERMISSIONS = [
        'view' => 'practice:view',
        'manage' => 'practice:manage',
        'approve' => 'practice:approve',
        'period' => 'practice:period:manage',
    ];

    private const ENTITIES = [
        'plan' => ['permission' => 'manage', 'title' => '教学计划', 'review' => true],
        'schedule' => ['permission' => 'manage', 'title' => '课表安排', 'review' => false],
        'project' => ['permission' => 'manage', 'title' => '项目发布', 'review' => false],
        'syllabus' => ['permission' => 'manage', 'title' => '大纲', 'review' => true],
        'lessonPlan' => ['permission' => 'manage', 'title' => '教案', 'review' => true],
        'gradeRule' => ['permission' => 'manage', 'title' => '成绩比例', 'review' => false],
        'score' => ['permission' => 'manage', 'title' => '成绩', 'review' => true],
        'reflection' => ['permission' => 'manage', 'title' => '反思报告', 'review' => true],
        'room' => ['permission' => 'manage', 'title' => '实验实训室', 'review' => false],
    ];

    private const EXECUTIONS = [
        'sign_in' => ['title' => '签到', 'review' => false],
        'journal' => ['title' => '日志', 'review' => true],
        'report' => ['title' => '报告', 'review' => true],
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
        'journal' => [
            'accept' => ['min' => 0, 'max' => 200],
            'modify' => ['min' => 5, 'max' => 500],
        ],
        'report' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 8, 'max' => 800],
        ],
        'score' => [
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
            'periods' => PracticePeriod::optionRows(),
        ]);
    }

    /** 查询开课任务课程负责人和任课教师。 */
    public function planTeachers(Request $request): array
    {
        $this->requirePermission('view');
        $planId = $this->requiredInt($request, 'plan_id');
        $this->assertEntityVisible('plan', $planId);

        return [
            'plan_id' => $planId,
            'teachers' => PracticeRecord::planTeacherRows($this->scopeContext(), $this->moduleType, $planId),
        ];
    }

    /** 查询项目绑定学生及当前成绩。 */
    public function projectStudents(Request $request): array
    {
        $this->requirePermission('view');
        $projectId = $this->requiredInt($request, 'project_id');

        return [
            'project_id' => $projectId,
            'items' => PracticeRecord::projectStudentScoreRows($this->scopeContext(), $this->moduleType, $projectId),
        ];
    }

    /** 保存开课任务课程负责人和任课教师。 */
    public function savePlanTeachers(Request $request): array
    {
        $this->assertWritableModule();
        $this->requirePermission('manage');
        if ($this->isTeacher() || $this->isStudent()) {
            throw new RuntimeException('仅管理员可维护开课任务教师关系', 40300);
        }
        $planId = $this->requiredInt($request, 'plan_id');
        $leaderId = $this->requiredInt($request, 'course_leader_id');
        $teacherIds = $this->intList($request->input('teacher_ids', []));
        $allTeacherIds = array_values(array_unique(array_merge([$leaderId], $teacherIds)));
        $visibleIds = PracticeRecord::visibleTeacherIds($this->scopeContext(), $allTeacherIds);
        sort($allTeacherIds);
        sort($visibleIds);
        if ($allTeacherIds !== $visibleIds) {
            throw new RuntimeException('所选教师不存在或超出数据范围', 40301);
        }
        $this->assertEntityVisible('plan', $planId);

        return $this->workflowLock('practice', 'plan_teachers', $planId, function () use ($leaderId, $planId, $teacherIds): array {
            return PracticeRecord::connection()->transaction(function () use ($leaderId, $planId, $teacherIds): array {
                $plan = PracticeRecord::lockActiveRowByEntity($this->moduleType, 'plan', $planId);
                if (!$plan) {
                    throw new RuntimeException('开课任务不存在');
                }
                $leaderAccountIds = $this->teacherAccountIds($leaderId);
                PracticeRecord::replacePlanTeachers(
                    $this->moduleType,
                    $planId,
                    $leaderId,
                    $teacherIds,
                    $leaderAccountIds[0] ?? null,
                    $this->now()
                );
                $this->invalidatePlanArchives($planId, '开课任务教师关系已变更');

                return [
                    'plan_id' => $planId,
                    'course_leader_id' => $leaderId,
                    'teacher_ids' => array_values(array_unique(array_merge([$leaderId], $teacherIds))),
                ];
            });
        });
    }

    /** 查询课节配置 */
    public function periods(Request $request): array
    {
        $this->requirePermission('period');
        $filters = [
            'page' => $request->input('page', 1),
            'page_size' => $request->input('page_size', 50),
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
        ];

        return PracticePeriod::page($filters);
    }

    /** 保存课节配置 */
    public function savePeriod(Request $request): array
    {
        $this->requirePermission('period');
        $id = $this->optionalInt($request, 'id');
        $name = $this->requiredString($request, 'name', 60);
        $startTime = $this->timeInput($request, 'start_time');
        $endTime = $this->timeInput($request, 'end_time');
        if (strcmp($endTime, $startTime) <= 0) {
            throw new InvalidArgumentException('课节结束时间必须晚于开始时间');
        }
        $now = $this->now();
        $values = [
            'name' => $name,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'sort' => max(0, (int) ($request->input('sort', 0))),
            'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        if (!$id) {
            $values['uuid'] = $this->uuid();
            $values['created_at'] = $now;
        }

        return ['id' => PracticePeriod::saveRecord($id, $values)];
    }

    /** 查询专业周课表 */
    public function scheduleWeek(Request $request): array
    {
        $this->requirePermission('view');
        $weekStart = $this->dateInput($request, 'week_start');
        if (!$weekStart) {
            throw new InvalidArgumentException('请选择课表周');
        }
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $filters = $this->requestFilters($request);
        foreach (['grade_id' => '年级', 'dep_id' => '学院', 'profession_id' => '专业'] as $key => $label) {
            if (empty($filters[$key])) {
                throw new InvalidArgumentException("请选择{$label}");
            }
        }

        $items = PracticeRecord::scheduleWeekRows($this->scopeContext(), $this->moduleType, $weekStart, $weekEnd, $filters);
        $periodReferences = [];
        foreach ($items as $item) {
            $periodReferences[] = [
                'start_id' => (int) ($item['period_start_id'] ?? 0),
                'end_id' => (int) ($item['period_end_id'] ?? 0),
            ];
        }

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'periods' => PracticePeriod::scheduleRows($periodReferences),
            'items' => $items,
        ];
    }

    public function list(Request $request): array
    {
        $this->requirePermission('view');
        $entity = $this->entityInput($request);

        return PracticeRecord::entityPage($this->scopeContext(), $this->moduleType, $entity, $this->requestFilters($request));
    }

    public function save(Request $request): array
    {
        $this->assertWritableModule();
        $entity = $this->entityInput($request);
        if ($entity === 'score') {
            return $this->saveScore($request);
        }
        $this->requireEntityPermission($entity);
        $existingId = $this->inputEntityId($request, $entity);
        $fromStatus = $existingId ? PracticeRecord::statusById($entity, $existingId) : 'draft';
        $values = $this->entityValues($request, $entity);
        $this->assertEntityWriter($entity, $values);
        if ($this->entityRequiresReview($entity) && $existingId && !in_array($fromStatus, ['draft', 'modify'], true)) {
            throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
        }

        $save = function () use ($entity, $existingId, $request, $values, $fromStatus): array {
            return PracticeRecord::connection()->transaction(function () use ($entity, $existingId, $request, $values, $fromStatus): array {
                $currentFromStatus = $fromStatus;
                if ($existingId) {
                    $row = PracticeRecord::lockActiveRowByEntity($this->moduleType, $entity, $existingId);
                    if (!$row) {
                        throw new RuntimeException('数据不存在');
                    }
                    $this->assertEntityVisible($entity, $existingId);
                    $currentFromStatus = (string) $row->status;
                    if ($this->entityRequiresReview($entity) && !in_array($currentFromStatus, ['draft', 'modify'], true)) {
                        throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
                    }
                    $this->assertEntityRowWriter($entity, $row);
                }

                if ($entity === 'schedule') {
                    $values = $this->scheduleValues($request, $values, $existingId);
                }
                if ($entity === 'project') {
                    $values = $this->projectValues($request, $values);
                    if ($this->isTeacher()) {
                        $this->assertCourseLeader((int) ($values['plan_id'] ?? 0));
                    }
                }
                $this->assertEntityValuesVisible($entity, $values);

                $id = $this->saveEntity($entity, $request, $values);
                if ($entity === 'project') {
                    $this->syncProjectStudents($id, $values);
                    PracticeRecord::syncGradeRuleProjects($this->moduleType, $values, $this->uuid(), $this->now());
                }
                if (in_array($entity, ['plan', 'schedule', 'project', 'syllabus', 'lessonPlan', 'gradeRule', 'reflection'], true)) {
                    $this->invalidatePlanArchives(
                        $entity === 'plan' ? $id : (int) ($values['plan_id'] ?? 0),
                        $this->businessName($entity) . '已变更'
                    );
                }
                if (($values['status'] ?? '') === 'wait' && $this->entityRequiresReview($entity)) {
                    $this->recordWorkflow($entity, $id, 'submit', $currentFromStatus, 'wait', $this->workflowContent($entity, $values), 'wait');
                    $this->notifyWorkflowSubmitted($entity, $id, $values);
                }

                return ['id' => $id, 'uuid' => PracticeRecord::uuidById($entity, $id)];
            });
        };

        if ($entity === 'schedule') {
            $scheduleSave = fn (): array => $this->workflowLock('practice', 'schedule_date', $this->scheduleLockId($values), $save);
            return $existingId
                ? $this->workflowLock('practice', $this->entityType($entity), $existingId, $scheduleSave)
                : $scheduleSave();
        }
        if ($entity === 'project') {
            $projectSave = fn (): array => $this->workflowLock('practice', 'project_schedule', (int) ($values['schedule_id'] ?? $existingId ?? 0), $save);
            return $existingId
                ? $this->workflowLock('practice', $this->entityType($entity), $existingId, $projectSave)
                : $projectSave();
        }

        return $existingId ? $this->workflowLock('practice', $this->entityType($entity), $existingId, $save) : $save();
    }

    public function review(Request $request): array
    {
        $this->assertWritableModule();
        $entity = $this->entityInput($request);
        if (!$this->entityRequiresReview($entity)) {
            throw new InvalidArgumentException('该业务不需要审核');
        }
        $this->requirePermission('approve');

        $id = $this->requiredEntityId($request, $entity);
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinionInput($request, $entity, $status);

        return $this->workflowLock('practice', $this->entityType($entity), $id, function () use ($entity, $id, $status, $opinion): array {
            return PracticeRecord::connection()->transaction(function () use ($entity, $id, $status, $opinion): array {
            $row = PracticeRecord::lockActiveRowByEntity($this->moduleType, $entity, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertEntityVisible($entity, $id);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $this->assertEntityReviewer($entity, $row);
            $this->assertNotSelfReview($this->entityType($entity), $id, $row);

            PracticeRecord::updateEntityById($entity, $id, [
                'status' => $status,
                'updated_at' => $this->now(),
            ]);
            $this->recordWorkflow($entity, $id, 'review', (string) $row->status, $status, $opinion ?: '审核处理', $status);
            PracticeRecord::clearReviewOpinionDraft($this->entityType($entity), $id, $this->accountId(), $this->now());
            $this->notifyWorkflowReviewed($entity, $id, $row, $status, $opinion ?: '审核处理');

            return ['id' => $id, 'status' => $status];
            });
        });
    }

    public function reviewDraft(Request $request): array
    {
        $this->requirePermission('approve');
        $target = $this->reviewDraftTarget($request);
        $draft = PracticeRecord::reviewOpinionDraftRow($target['entity_type'], $target['id'], $this->accountId());

        return [
            'entity_type' => $target['entity_type'],
            'id' => $target['id'],
            'draft' => $draft,
        ];
    }

    public function saveReviewDraft(Request $request): array
    {
        $this->assertWritableModule();
        $this->requirePermission('approve');
        $target = $this->reviewDraftTarget($request);
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewDraftOpinionInput($request, $target['rule_entity'], $status);
        $score = $this->decimalInput($request, 'score');
        $now = $this->now();

        return $this->workflowLock('practice', $target['entity_type'], $target['id'], function () use ($target, $status, $opinion, $score, $now): array {
            $id = PracticeRecord::saveReviewOpinionDraft([
                'entity_type' => $target['entity_type'],
                'entity_id' => $target['id'],
                'reviewer_id' => $this->accountId(),
                'teacher_id' => $this->currentTeacherId(false),
                'review_status' => $status,
                'opinion' => $opinion,
                'score' => $score,
                'updated_at' => $now,
            ]);

            return [
                'id' => $id,
                'entity_type' => $target['entity_type'],
                'entity_id' => $target['id'],
                'status' => $status,
            ];
        });
    }

    public function requestModification(Request $request): array
    {
        $this->assertWritableModule();
        $entity = $this->entityInput($request);
        if (!$this->entityRequiresReview($entity)) {
            throw new InvalidArgumentException('该业务不支持通过后修改');
        }
        $this->requirePermission('approve');

        $id = $this->requiredEntityId($request, $entity);
        $opinion = $this->reviewOpinionInput($request, $entity, 'modify', '修改理由');

        return $this->workflowLock('practice', $this->entityType($entity), $id, function () use ($entity, $id, $opinion): array {
            return PracticeRecord::connection()->transaction(function () use ($entity, $id, $opinion): array {
            $row = PracticeRecord::lockActiveRowByEntity($this->moduleType, $entity, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertEntityVisible($entity, $id);
            if ((string) $row->status !== 'accept') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $this->assertEntityReviewer($entity, $row);
            $this->assertNotSelfReview($this->entityType($entity), $id, $row);

            PracticeRecord::updateEntityById($entity, $id, [
                'status' => 'modify',
                'updated_at' => $this->now(),
            ]);
            $this->recordWorkflow($entity, $id, 'modify_after_accept', 'accept', 'modify', $opinion ?: '通过后要求修改', 'modify');
            $this->invalidatePlanArchives(PracticeRecord::entityPlanId($entity, $id), $this->businessName($entity) . '已发起通过后修改');
            $this->notifyWorkflowReopened($entity, $id, $row, $opinion ?: '通过后要求修改');

            return ['id' => $id, 'status' => 'modify'];
            });
        });
    }

    public function timeline(Request $request): array
    {
        $this->requirePermission('view');
        $entity = $this->entityInput($request);
        $id = $this->requiredEntityId($request, $entity);
        $this->assertEntityVisible($entity, $id);
        $moduleType = $this->moduleType;
        if ($moduleType === 'all') {
            $row = PracticeRecord::activeRowByEntity('all', $entity, $id);
            $moduleType = (string) ($row?->module_type ?? '');
        }
        if (!in_array($moduleType, ['training', 'lab'], true)) {
            throw new RuntimeException('数据不存在');
        }
        $entityType = "{$moduleType}_{$entity}";
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

    public function signIns(Request $request): array
    {
        $this->requirePermission('view');

        return PracticeRecord::executionPage($this->scopeContext(), $this->moduleType, 'sign_in', $this->requestFilters($request));
    }

    public function saveSignIn(Request $request): array
    {
        $this->assertWritableModule();
        $this->requirePermission('view');
        $project = $this->projectForExecution($request);
        $studentId = $this->executionStudentId($request, $project);
        $projectStudent = PracticeRecord::projectStudentRow($this->moduleType, (int) $project['id'], $studentId);
        if (!$projectStudent) {
            throw new RuntimeException('学生未绑定该项目', 40301);
        }

        $values = [
            'uuid' => $this->uuid(),
            'student_id' => $studentId,
            'entity_type' => $this->moduleType,
            'entity_id' => (int) $project['id'],
            'teacher_id' => (int) ($projectStudent['teacher_id'] ?? ($project['teacher_id'] ?? 0)) ?: null,
            'sign_time' => $this->dateTimeInput($request, 'sign_time') ?: $this->now(),
            'date' => $this->dateInput($request, 'date') ?: date('Y-m-d'),
            'longitude' => $this->decimalInput($request, 'longitude'),
            'latitude' => $this->decimalInput($request, 'latitude'),
            'location' => $this->nullableString($request, 'location', 255),
            'sign_type' => $this->enum($request, 'sign_type', ['gps', 'qrcode', 'manual'], $this->isStudent() ? 'gps' : 'manual'),
            'remark' => $this->nullableString($request, 'remark', 1000),
            'status' => 'signed',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];
        $id = PracticeRecord::insertExecution('sign_in', $values);
        $this->recordExecutionWorkflow('sign_in', $id, 'submit', 'draft', 'signed', $this->executionContent('sign_in', $values), null);

        return ['id' => $id];
    }

    public function journals(Request $request): array
    {
        $this->requirePermission('view');

        return PracticeRecord::executionPage($this->scopeContext(), $this->moduleType, 'journal', $this->requestFilters($request));
    }

    public function saveJournal(Request $request): array
    {
        return $this->saveReviewExecution($request, 'journal');
    }

    public function reviewJournal(Request $request): array
    {
        return $this->reviewExecution($request, 'journal');
    }

    public function reports(Request $request): array
    {
        $this->requirePermission('view');

        return PracticeRecord::executionPage($this->scopeContext(), $this->moduleType, 'report', $this->requestFilters($request));
    }

    /** 查询开课任务归档条件。 */
    public function archiveCheck(Request $request): array
    {
        $this->requirePermission('view');
        $this->assertArchiveAccess();
        $planId = $this->requiredInt($request, 'plan_id');
        $result = PracticeRecord::archiveCheckRows($this->scopeContext(), $this->moduleType, $planId);
        if (!$result) {
            throw new RuntimeException('开课任务不存在或无权限', 40301);
        }

        return $result;
    }

    /** 生成开课任务归档版本。 */
    public function archive(Request $request): array
    {
        $this->assertWritableModule();
        $this->requirePermission('manage');
        $this->assertArchiveAccess();
        $planId = $this->requiredInt($request, 'plan_id');
        if ($this->isTeacher()) {
            $this->assertCourseLeader($planId);
        }

        return $this->workflowLock('practice', 'archive', $planId, function () use ($planId): array {
            return PracticeRecord::connection()->transaction(function () use ($planId): array {
                PracticeRecord::lockCurrentArchive($this->moduleType, $planId);
                $snapshot = PracticeRecord::archiveSnapshotRows($this->scopeContext(), $this->moduleType, $planId);
                if (!$snapshot) {
                    throw new RuntimeException('开课任务不存在或无权限', 40301);
                }
                if (!$snapshot['ready']) {
                    throw new InvalidArgumentException('必交材料尚未齐全或仍有材料未通过审核', 42201);
                }

                PracticeRecord::invalidatePlanArchives(
                    $this->moduleType,
                    $planId,
                    $this->accountId(),
                    '已生成新的归档版本',
                    $this->now()
                );
                $version = PracticeRecord::nextArchiveVersion($this->moduleType, $planId);
                $id = PracticeRecord::insertArchive([
                    'uuid' => $this->uuid(),
                    'status' => 'archived',
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                    'deleted_at' => null,
                    'module_type' => $this->moduleType,
                    'plan_id' => $planId,
                    'version_no' => $version,
                    'snapshot_json' => $this->jsonValue($snapshot),
                    'missing_items_json' => $this->jsonValue([]),
                    'pending_items_json' => $this->jsonValue([]),
                    'created_by' => $this->accountId(),
                ]);

                return ['id' => $id, 'plan_id' => $planId, 'version_no' => $version, 'status' => 'archived'];
            });
        });
    }

    /** 分页查询归档版本。 */
    public function archiveList(Request $request): array
    {
        $this->requirePermission('view');
        $this->assertArchiveAccess();
        return PracticeRecord::practiceArchivePage($this->scopeContext(), $this->moduleType, $this->requestFilters($request));
    }

    /** 查看归档版本详情。 */
    public function archiveDetail(Request $request): array
    {
        $this->requirePermission('view');
        $this->assertArchiveAccess();
        $id = $this->requiredInt($request, 'id');
        $row = PracticeRecord::archiveDetailRow($this->scopeContext(), $this->moduleType, $id);
        if (!$row) {
            throw new RuntimeException('归档版本不存在或无权限', 40301);
        }

        return $row;
    }

    public function saveReport(Request $request): array
    {
        return $this->saveReviewExecution($request, 'report');
    }

    public function reviewReport(Request $request): array
    {
        return $this->reviewExecution($request, 'report');
    }

    public function requestExecutionModification(Request $request): array
    {
        $this->assertWritableModule();
        $execution = $this->executionInput($request);
        if (!(self::EXECUTIONS[$execution]['review'] ?? false)) {
            throw new InvalidArgumentException('该执行记录不支持通过后修改');
        }
        $this->requirePermission('approve');
        $id = $this->requiredInt($request, 'id');
        $opinion = $this->reviewOpinionInput($request, $execution, 'modify', '修改理由');

        return $this->workflowLock('practice', $this->executionEntityType($execution), $id, function () use ($execution, $id, $opinion): array {
            return PracticeRecord::connection()->transaction(function () use ($execution, $id, $opinion): array {
            $row = PracticeRecord::lockExecutionRow($this->scopeContext(), $this->moduleType, $execution, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在或无权限', 40301);
            }
            if ((string) $row->status !== 'accept') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $this->assertExecutionReviewer();
            $this->assertNotSelfReview($this->executionEntityType($execution), $id, $row);

            PracticeRecord::updateExecution($execution, $id, [
                'status' => 'modify',
                'updated_at' => $this->now(),
            ]);
            $this->recordExecutionWorkflow($execution, $id, 'modify_after_accept', 'accept', 'modify', $opinion ?: '通过后要求修改', 'modify');
            $this->invalidatePlanArchives(PracticeRecord::executionPlanId($execution, $id), $this->businessName($execution) . '已发起通过后修改');
            $this->notifyExecutionReopened($execution, $id, $row, $opinion ?: '通过后要求修改');

            return ['id' => $id, 'status' => 'modify'];
            });
        });
    }

    public function saveScore(Request $request): array
    {
        $this->assertWritableModule();
        $this->requireEntityPermission('score');
        if ($this->isStudent()) {
            throw new RuntimeException('学生无成绩录入权限', 40300);
        }
        $project = $this->projectForExecution($request);
        $studentId = $this->executionStudentId($request, $project);
        $projectStudent = PracticeRecord::projectStudentRow($this->moduleType, (int) $project['id'], $studentId);
        if (!$projectStudent) {
            throw new RuntimeException('学生未绑定该项目', 40301);
        }
        if ($this->isTeacher() && (int) ($projectStudent['teacher_id'] ?? 0) !== (int) $this->currentTeacherId(true)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }

        $scoreItems = $request->input('score_items', []);
        if (!is_array($scoreItems)) {
            $scoreItems = [];
        }
        foreach (['attendance_score', 'material_score', 'report_score'] as $key) {
            $value = $this->decimalInput($request, $key);
            if ($value !== null) {
                $scoreItems[$key] = $value;
            }
        }
        $scoreValue = $this->decimalInput($request, 'score_value');
        if ($scoreValue === null) {
            $scores = array_filter($scoreItems, 'is_numeric');
            $scoreValue = $scores ? round(array_sum(array_map('floatval', $scores)) / count($scores), 2) : null;
        }

        $status = $this->enum($request, 'status', ['draft', 'wait'], 'draft');
        $existingId = PracticeRecord::currentProjectScoreId($this->moduleType, (int) $project['id'], $studentId);
        $values = [
            'uuid' => $this->uuid(),
            'module_type' => $this->moduleType,
            'project_id' => (int) $project['id'],
            'plan_id' => (int) ($project['plan_id'] ?? 0) ?: null,
            'grade_id' => (int) ($projectStudent['grade_id'] ?? 0) ?: null,
            'dep_id' => (int) ($projectStudent['dep_id'] ?? 0) ?: null,
            'profession_id' => (int) ($projectStudent['profession_id'] ?? 0) ?: null,
            'class_id' => (int) ($projectStudent['class_id'] ?? 0) ?: null,
            'teacher_id' => (int) ($projectStudent['teacher_id'] ?? ($project['teacher_id'] ?? 0)) ?: null,
            'student_id' => $studentId,
            'course_name' => $project['course_name'] ?? null,
            'title' => $this->nullableString($request, 'title', 180) ?: (($project['title'] ?? '') . '成绩'),
            'score_items' => $this->jsonValue($scoreItems),
            'score_value' => $scoreValue,
            'submitter_id' => $this->accountId(),
            'status' => $status,
            'updated_at' => $this->now(),
            'deleted_at' => null,
            'created_at' => $this->now(),
        ];

        $lockId = $existingId ?: max(1, (int) sprintf('%u', crc32($this->moduleType . ':' . (int) $project['id'] . ':' . $studentId)));
        return $this->workflowLock('practice', $this->entityType('score'), $lockId, function () use ($existingId, $status, $values): array {
            return PracticeRecord::connection()->transaction(function () use ($existingId, $status, $values): array {
                $id = $existingId;
                $fromStatus = 'draft';
                if ($id > 0) {
                    $row = PracticeRecord::lockActiveRowByEntity($this->moduleType, 'score', $id);
                    if (!$row) {
                        throw new RuntimeException('成绩记录不存在或无权限', 40301);
                    }
                    $this->assertEntityVisible('score', $id);
                    $fromStatus = (string) $row->status;
                    if (!in_array($fromStatus, ['draft', 'modify'], true)) {
                        throw new InvalidArgumentException('成绩已提交或审核，请刷新后重试', 409);
                    }
                    $updates = $values;
                    unset($updates['uuid'], $updates['created_at']);
                    PracticeRecord::updateEntityById('score', $id, $updates);
                } else {
                    $this->assertEntityValuesVisible('score', $values);
                    $id = PracticeRecord::insertEntity('score', $values);
                }

                $this->invalidatePlanArchives((int) ($values['plan_id'] ?? 0), '项目成绩已变更');
                if ($status === 'wait') {
                    $this->recordWorkflow('score', $id, 'submit', $fromStatus, 'wait', $this->workflowContent('score', $values), 'wait');
                    $this->notifyWorkflowSubmitted('score', $id, $values);
                }

                return ['id' => $id, 'status' => $status];
            });
        });
    }

    public function executionTimeline(Request $request): array
    {
        $this->requirePermission('view');
        $execution = $this->executionInput($request);
        $id = $this->requiredInt($request, 'id');
        $row = PracticeRecord::activeExecutionRow($this->scopeContext(), $this->moduleType, $execution, $id);
        if (!$row) {
            throw new RuntimeException('数据不存在或无权限', 40301);
        }
        $moduleType = (string) ($row->entity_type ?? $this->moduleType);
        if (!in_array($moduleType, ['training', 'lab'], true)) {
            throw new RuntimeException('数据不存在');
        }
        $entityType = "{$moduleType}_{$execution}";
        $records = PracticeRecord::recordingRows($entityType, $id);
        $reviews = PracticeRecord::reviewOpinionRows($entityType, $id);

        return [
            'execution' => $execution,
            'id' => $id,
            'records' => $records,
            'reviews' => $reviews,
            'cycles' => $this->timelineCycles($records, $reviews),
        ];
    }

    /**
     * 保存需审核的执行材料。
     */
    private function saveReviewExecution(Request $request, string $execution): array
    {
        $this->assertWritableModule();
        if (!(self::EXECUTIONS[$execution]['review'] ?? false)) {
            throw new InvalidArgumentException('该执行记录不需要审核');
        }
        $this->requirePermission('view');
        if (!$this->isStudent()) {
            throw new RuntimeException('项目报告由学生本人提交', 40300);
        }
        $project = $this->projectForExecution($request);
        $studentId = $this->executionStudentId($request, $project);
        $projectStudent = PracticeRecord::projectStudentRow($this->moduleType, (int) $project['id'], $studentId);
        if (!$projectStudent) {
            throw new RuntimeException('学生未绑定该项目', 40301);
        }

        $id = $this->optionalInt($request, 'id');
        if (!$id && $execution === 'report') {
            $id = PracticeRecord::currentProjectReportId($this->moduleType, (int) $project['id'], $studentId) ?: null;
        }
        $status = $this->enum($request, 'status', ['draft', 'wait'], 'wait');
        $values = $this->executionValues($request, $execution, $project, $projectStudent, $studentId, $status);

        $save = function () use ($execution, $id, $values, $status): array {
            return PracticeRecord::connection()->transaction(function () use ($execution, $id, $values, $status): array {
            $fromStatus = 'draft';
            if ($id) {
                $row = PracticeRecord::lockExecutionRow($this->scopeContext(), $this->moduleType, $execution, $id);
                if (!$row) {
                    throw new RuntimeException('数据不存在或无权限', 40301);
                }
                if (!in_array((string) $row->status, ['draft', 'modify'], true)) {
                    throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
                }
                $fromStatus = (string) $row->status;
                PracticeRecord::updateExecution($execution, $id, $values);
            } else {
                $id = PracticeRecord::insertExecution($execution, array_merge($values, [
                    'uuid' => $this->uuid(),
                    'created_at' => $this->now(),
                ]));
            }

            if ($status === 'wait') {
                $this->recordExecutionWorkflow($execution, $id, 'submit', $fromStatus, 'wait', $this->executionContent($execution, $values), 'wait');
                $this->notifyExecutionSubmitted($execution, $id, $values);
            }

            return ['id' => $id, 'status' => $status];
            });
        };

        return $id
            ? $this->workflowLock('practice', $this->executionEntityType($execution), $id, $save)
            : $save();
    }

    /**
     * 审核执行材料。
     */
    private function reviewExecution(Request $request, string $execution): array
    {
        $this->assertWritableModule();
        if (!(self::EXECUTIONS[$execution]['review'] ?? false)) {
            throw new InvalidArgumentException('该执行记录不需要审核');
        }
        $this->requirePermission('approve');
        $id = $this->requiredInt($request, 'id');
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinionInput($request, $execution, $status);

        return $this->workflowLock('practice', $this->executionEntityType($execution), $id, function () use ($execution, $id, $status, $opinion): array {
            return PracticeRecord::connection()->transaction(function () use ($execution, $id, $status, $opinion): array {
            $row = PracticeRecord::lockExecutionRow($this->scopeContext(), $this->moduleType, $execution, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在或无权限', 40301);
            }
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $this->assertExecutionReviewer();
            $this->assertNotSelfReview($this->executionEntityType($execution), $id, $row);

            PracticeRecord::updateExecution($execution, $id, [
                'status' => $status,
                'updated_at' => $this->now(),
            ]);
            $this->recordExecutionWorkflow($execution, $id, 'review', 'wait', $status, $opinion ?: '审核处理', $status);
            PracticeRecord::clearReviewOpinionDraft($this->executionEntityType($execution), $id, $this->accountId(), $this->now());
            $this->notifyExecutionReviewed($execution, $id, $row, $status, $opinion ?: '审核处理');

            return ['id' => $id, 'status' => $status];
            });
        });
    }

    private function reviewDraftTarget(Request $request): array
    {
        $execution = trim((string) $request->input('execution', ''));
        if ($execution !== '') {
            if (!(self::EXECUTIONS[$execution]['review'] ?? false)) {
                throw new InvalidArgumentException('该执行记录不需要审核');
            }
            $id = $this->requiredInt($request, 'id');
            $row = PracticeRecord::activeExecutionRow($this->scopeContext(), $this->moduleType, $execution, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在或无权限', 40301);
            }
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $this->assertExecutionReviewer();
            $this->assertNotSelfReview($this->executionEntityType($execution), $id, $row);
            $moduleType = (string) ($row->entity_type ?? '');
            if (!in_array($moduleType, ['training', 'lab'], true)) {
                throw new RuntimeException('数据不存在');
            }

            return [
                'id' => $id,
                'rule_entity' => $execution,
                'entity_type' => "{$moduleType}_{$execution}",
            ];
        }

        $entity = $this->entityInput($request);
        if (!$this->entityRequiresReview($entity)) {
            throw new InvalidArgumentException('该业务不需要审核');
        }
        $id = $this->requiredEntityId($request, $entity);
        $row = PracticeRecord::activeRowByEntity($this->moduleType, $entity, $id);
        if (!$row) {
            throw new RuntimeException('数据不存在');
        }
        $this->assertEntityVisible($entity, $id);
        if ((string) $row->status !== 'wait') {
            throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
        }
        $this->assertEntityReviewer($entity, $row);
        $this->assertNotSelfReview($this->entityType($entity), $id, $row);
        $moduleType = (string) ($row->module_type ?? '');
        if (!in_array($moduleType, ['training', 'lab'], true)) {
            throw new RuntimeException('数据不存在');
        }

        return [
            'id' => $id,
            'rule_entity' => $entity,
            'entity_type' => "{$moduleType}_{$entity}",
        ];
    }

    private function reviewDraftOpinionInput(Request $request, string $entity, string $status): ?string
    {
        $value = trim((string) $request->input('opinion', ''));
        $rule = self::REVIEW_RULES[$entity][$status] ?? ['min' => 0, 'max' => null];
        $max = $rule['max'] ?? null;
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($max !== null && $length > (int) $max) {
            throw new InvalidArgumentException('审核意见最多 ' . (int) $max . ' 字', 42202);
        }

        return $value === '' ? null : $value;
    }

    /**
     * 读取可执行项目。
     */
    private function projectForExecution(Request $request): array
    {
        $projectId = $this->requiredInt($request, 'project_id');
        $project = PracticeRecord::projectExecutionRow($this->scopeContext(), $this->moduleType, $projectId);
        if (!$project) {
            throw new RuntimeException('项目不存在或无权限', 40301);
        }

        return $project;
    }

    /**
     * 解析执行学生。
     */
    private function executionStudentId(Request $request, array $project): int
    {
        if ($this->isStudent()) {
            return $this->currentStudentId(true);
        }

        $studentId = $this->requiredInt($request, 'student_id');
        $projectStudent = PracticeRecord::projectStudentRow($this->moduleType, (int) $project['id'], $studentId);
        if (!$projectStudent) {
            throw new RuntimeException('学生未绑定该项目', 40301);
        }
        if ($this->isTeacher() && (int) ($projectStudent['teacher_id'] ?? 0) !== (int) $this->currentTeacherId(true)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }

        return $studentId;
    }

    /**
     * 生成执行材料保存数据。
     */
    private function executionValues(Request $request, string $execution, array $project, array $projectStudent, int $studentId, string $status): array
    {
        $title = $this->nullableString($request, 'title', 180)
            ?: ((self::EXECUTIONS[$execution]['title'] ?? '材料') . ' - ' . ($project['title'] ?? '项目'));
        $content = $this->requiredString($request, 'content', 30000);
        $values = [
            'student_id' => $studentId,
            'teacher_id' => (int) ($projectStudent['teacher_id'] ?? ($project['teacher_id'] ?? 0)) ?: null,
            'entity_type' => $this->moduleType,
            'entity_id' => (int) $project['id'],
            'date' => $this->dateInput($request, 'date') ?: date('Y-m-d'),
            'title' => $title,
            'content' => $content,
            'remark' => $this->nullableString($request, 'remark', 2000),
            'status' => $status,
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        if ($execution === 'report') {
            $values['template_id'] = $this->optionalInt($request, 'template_id');
            $values['submitted_at'] = $status === 'wait' ? $this->now() : $this->dateTimeInput($request, 'submitted_at');
            $values['practice_project_id'] = (int) $project['id'];
            $values['report_type'] = 'project_report';
            $values['reflection_summary'] = $status === 'wait'
                ? $this->requiredString($request, 'reflection_summary', 5000)
                : $this->nullableString($request, 'reflection_summary', 5000);
        }

        return $values;
    }

    /**
     * 写入执行流转记录。
     */
    private function recordExecutionWorkflow(string $execution, int $entityId, string $action, ?string $from, string $to, ?string $content, ?string $reviewStatus): int
    {
        $entityType = $this->executionEntityType($execution);
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

    /**
     * 生成执行流转摘要。
     */
    private function executionContent(string $execution, array $values): string
    {
        return trim(implode("\n", array_filter([
            self::EXECUTIONS[$execution]['title'] ?? $execution,
            $values['title'] ?? null,
            $values['date'] ?? null,
            $values['content'] ?? null,
            $values['location'] ?? null,
            $values['remark'] ?? null,
        ]))) ?: '提交记录';
    }

    /**
     * 解析执行类型。
     */
    private function executionInput(Request $request): string
    {
        $execution = (string) $request->input('execution', '');
        if (!isset(self::EXECUTIONS[$execution])) {
            throw new InvalidArgumentException('execution 无效');
        }

        return $execution;
    }

    /**
     * 生成执行记录类型。
     */
    private function executionEntityType(string $execution): string
    {
        if (!isset(self::EXECUTIONS[$execution])) {
            throw new InvalidArgumentException('execution 无效');
        }

        return "{$this->moduleType}_{$execution}";
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
                'period_start_id' => $this->optionalInt($request, 'period_start_id'),
                'period_end_id' => $this->optionalInt($request, 'period_end_id'),
                'start_time' => $this->nullableString($request, 'start_time', 20),
                'end_time' => $this->nullableString($request, 'end_time', 20),
                'location' => $this->nullableString($request, 'location', 255),
                'student_count' => $this->optionalInt($request, 'student_count') ?? 0,
                'roster_printed_at' => $this->dateTimeInput($request, 'roster_printed_at'),
                'status' => $this->enum($request, 'status', ['draft', 'enabled', 'disabled'], 'enabled'),
            ]),
            'project' => array_merge($common, [
                'schedule_id' => $this->optionalInt($request, 'schedule_id'),
                'start_date' => $this->dateInput($request, 'start_date'),
                'end_date' => $this->dateInput($request, 'end_date'),
                'student_count' => $this->optionalInt($request, 'student_count') ?? 0,
                'published_at' => $this->dateTimeInput($request, 'published_at'),
                'submitter_id' => CurrentContext::accountId(),
                'title' => $this->requiredTitle($request, self::ENTITIES[$entity]['title']),
                'status' => $this->enum($request, 'status', ['draft', 'enabled', 'disabled', 'completed'], 'enabled'),
            ]),
            'syllabus', 'lessonPlan', 'reflection' => array_merge($common, [
                'title' => $this->requiredTitle($request, self::ENTITIES[$entity]['title']),
                'submitter_id' => CurrentContext::accountId(),
                'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
            ]),
            'gradeRule' => array_merge($common, [
                'title' => $this->requiredTitle($request, '成绩比例'),
                'ratio_json' => $this->jsonValue($this->gradeRuleRatio($request)),
                'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
            ]),
            'score' => array_merge($common, [
                'submitter_id' => CurrentContext::accountId(),
                'student_id' => $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id'),
                'rule_id' => $this->optionalInt($request, 'rule_id'),
                'score_items' => $this->jsonValue($request->input('score_items', [])),
                'score_value' => $this->decimalInput($request, 'score_value'),
                'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
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

    /**
     * 生成课表保存数据。
     */
    private function scheduleValues(Request $request, array $values, ?int $existingId): array
    {
        $planId = (int) ($values['plan_id'] ?? 0);
        if ($planId <= 0) {
            throw new InvalidArgumentException('请选择已审核通过的教学计划');
        }

        $plan = PracticeRecord::planRowForSchedule($this->scopeContext(), $this->moduleType, $planId);
        if (!$plan) {
            throw new RuntimeException('教学计划不存在或无权限', 40301);
        }
        if (!in_array((string) ($plan['status'] ?? ''), ['accept', 'enabled'], true)) {
            throw new InvalidArgumentException('教学计划审核通过后才可安排课表');
        }

        foreach (['grade_id', 'dep_id', 'profession_id', 'course_name'] as $field) {
            $values[$field] = $plan[$field] ?? null;
        }

        if (empty($values['teacher_id'])) {
            throw new InvalidArgumentException('请选择任课教师');
        }
        if (empty($values['grade_id']) || empty($values['dep_id']) || empty($values['profession_id'])) {
            throw new InvalidArgumentException('请选择完整的年级、学院和专业');
        }
        if (empty($values['schedule_date'])) {
            throw new InvalidArgumentException('请选择课表日期');
        }
        $periodRange = PracticePeriod::activeRange((int) ($values['period_start_id'] ?? 0), (int) ($values['period_end_id'] ?? 0));
        if (!$periodRange) {
            throw new InvalidArgumentException('请选择有效的起止课节');
        }
        $values['start_time'] = substr((string) $periodRange['start']['start_time'], 0, 5);
        $values['end_time'] = substr((string) $periodRange['end']['end_time'], 0, 5);
        $values['class_id'] = null;

        $studentCount = PracticeRecord::enabledStudentCountByProfession((int) $values['grade_id'], (int) $values['profession_id']);
        if ($studentCount <= 0) {
            throw new InvalidArgumentException('所选年级和专业暂无可参与学生');
        }
        $values['student_count'] = $studentCount;

        if (($values['place_type'] ?? 'inside') === 'inside') {
            $room = PracticeRecord::roomRowForSchedule($this->scopeContext(), $this->moduleType, (int) ($values['room_id'] ?? 0));
            if (!$room) {
                throw new InvalidArgumentException('请选择可用实验实训室');
            }
            if ((int) ($room['capacity'] ?? 0) > 0 && (int) $room['capacity'] < $studentCount) {
                throw new InvalidArgumentException('实验实训室容量不足');
            }
            $values['base_id'] = null;
        } else {
            $values['room_id'] = null;
            $baseId = (int) ($values['base_id'] ?? 0);
            if ($baseId > 0 && !PracticeRecord::baseRowForSchedule($this->scopeContext(), $baseId)) {
                throw new InvalidArgumentException('请选择当前数据范围内的可用基地');
            }
            if ($baseId <= 0 && trim((string) ($values['location'] ?? '')) === '') {
                throw new InvalidArgumentException('校外安排需选择基地或填写地点');
            }
        }

        if (PracticeRecord::scheduleConflictExists($values, $existingId)) {
            throw new InvalidArgumentException('课表时间与已有安排冲突');
        }

        return $values;
    }

    /**
     * 生成项目发布数据。
     */
    private function projectValues(Request $request, array $values): array
    {
        $scheduleId = (int) ($values['schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            throw new InvalidArgumentException('请选择已发布课表');
        }

        $schedule = PracticeRecord::scheduleRowForProject($this->scopeContext(), $this->moduleType, $scheduleId);
        if (!$schedule) {
            throw new RuntimeException('课表不存在或无权限', 40301);
        }

        foreach (['plan_id', 'grade_id', 'dep_id', 'profession_id', 'teacher_id', 'course_name'] as $field) {
            $values[$field] = $schedule[$field] ?? null;
        }
        if (empty($values['start_date']) && !empty($schedule['schedule_date'])) {
            $values['start_date'] = $schedule['schedule_date'];
        }
        if (empty($values['end_date']) && !empty($schedule['schedule_date'])) {
            $values['end_date'] = $schedule['schedule_date'];
        }
        if (empty($values['teacher_id'])) {
            throw new InvalidArgumentException('请选择项目负责人');
        }
        if (empty($values['grade_id']) || empty($values['profession_id'])) {
            throw new InvalidArgumentException('课表缺少年级或专业，无法发布项目');
        }
        if (!empty($values['start_date']) && !empty($values['end_date']) && strcmp((string) $values['end_date'], (string) $values['start_date']) < 0) {
            throw new InvalidArgumentException('项目结束日期不能早于开始日期');
        }

        $values['class_id'] = null;
        $studentCount = PracticeRecord::enabledStudentCountByProfession((int) $values['grade_id'], (int) $values['profession_id']);
        if ($studentCount <= 0) {
            throw new InvalidArgumentException('所选课表专业暂无可参与学生');
        }
        $values['student_count'] = $studentCount;
        if (($values['status'] ?? '') === 'enabled' && empty($values['published_at'])) {
            $values['published_at'] = $this->now();
        }

        return $values;
    }

    /** 校验成绩方案比例。 */
    private function gradeRuleRatio(Request $request): array
    {
        $ratio = $request->input('ratio_json', []);
        if (!is_array($ratio)) {
            throw new InvalidArgumentException('成绩比例格式无效');
        }

        $weights = [];
        foreach (['attendance_weight', 'operation_weight', 'report_weight'] as $key) {
            $value = $ratio[$key] ?? null;
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                throw new InvalidArgumentException('成绩比例必须为 0 至 100 的数字');
            }
            $weights[$key] = (float) $value;
        }
        if (abs(array_sum($weights) - 100) > 0.001) {
            throw new InvalidArgumentException('成绩比例合计必须为 100%');
        }

        $projects = is_array($ratio['projects'] ?? null) ? array_values($ratio['projects']) : [];
        $activeWeights = [];
        foreach ($projects as $project) {
            if (!is_array($project) || ($project['status'] ?? 'enabled') === 'disabled') {
                continue;
            }
            $weight = $project['weight'] ?? null;
            if (!is_numeric($weight) || (float) $weight < 0 || (float) $weight > 100) {
                throw new InvalidArgumentException('项目成绩比例必须为 0 至 100 的数字');
            }
            $activeWeights[] = (float) $weight;
        }
        if ($activeWeights && abs(array_sum($activeWeights) - 100) > 0.001) {
            throw new InvalidArgumentException('项目成绩比例合计必须为 100%');
        }

        return array_merge($ratio, $weights, ['projects' => $projects]);
    }

    /**
     * 同步项目学生范围。
     */
    private function syncProjectStudents(int $projectId, array $values): void
    {
        $students = PracticeRecord::enabledStudentsByProfession((int) ($values['grade_id'] ?? 0), (int) ($values['profession_id'] ?? 0));
        $students = array_map(function (array $student): array {
            $student['uuid'] = $this->uuid();
            return $student;
        }, $students);
        $count = PracticeRecord::syncProjectStudents($this->moduleType, $projectId, $values, $students, $this->uuid(), $this->now());
        if ($count !== (int) ($values['student_count'] ?? 0)) {
            PracticeRecord::updateEntityById('project', $projectId, [
                'student_count' => $count,
                'updated_at' => $this->now(),
            ]);
        }
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
            'schedule_id', 'room_id', 'base_id', 'place_type', 'source_type', 'date',
            'grade_id', 'dep_id', 'profession_id', 'class_id',
        ];
        $filters = [];
        foreach ($keys as $key) {
            $filters[$key] = $request->input($key);
        }

        $this->applyDefaultScopeFilters($filters, $keys);

        return $filters;
    }

    private function applyDefaultScopeFilters(array &$filters, array $keys): void
    {
        if (in_array('grade_id', $keys, true) && !$this->hasFilterValue($filters, 'grade_id')) {
            $filters['grade_id'] = PracticeRecord::currentGradeId();
        }

        $roleType = CurrentContext::roleType();
        if ($roleType === 'college_admin' && in_array('dep_id', $keys, true) && !$this->hasFilterValue($filters, 'dep_id')) {
            $filters['dep_id'] = $this->singleScopeId('dep_id');
        }

        if ($roleType === 'profession_admin') {
            if (in_array('profession_id', $keys, true) && !$this->hasFilterValue($filters, 'profession_id')) {
                $filters['profession_id'] = $this->singleScopeId('profession_id');
            }
            if (in_array('dep_id', $keys, true) && !$this->hasFilterValue($filters, 'dep_id')) {
                $depIds = PracticeRecord::depIdsByProfessionIds($this->scopeIds('profession_id'));
                $filters['dep_id'] = count($depIds) === 1 ? $depIds[0] : null;
            }
        }
    }

    private function singleScopeId(string $field): ?int
    {
        $ids = $this->scopeIds($field);
        return count($ids) === 1 ? $ids[0] : null;
    }

    private function hasFilterValue(array $filters, string $key): bool
    {
        return isset($filters[$key]) && $filters[$key] !== null && $filters[$key] !== '';
    }

    private function assertEntityVisible(string $entity, int $id): void
    {
        if (!PracticeRecord::entityVisible($this->scopeContext(), $this->moduleType, $entity, $id)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }
    }

    /** 校验待写入数据范围 */
    private function assertEntityValuesVisible(string $entity, array $values): void
    {
        if (!PracticeRecord::entityValuesVisible($this->scopeContext(), $this->moduleType, $entity, $values)) {
            throw new RuntimeException('无数据写入权限', 40301);
        }
    }

    /** 校验课程业务写入角色。 */
    private function assertEntityWriter(string $entity, array $values): void
    {
        if (!$this->isTeacher()) {
            return;
        }
        if (in_array($entity, ['plan', 'schedule', 'room'], true)) {
            throw new RuntimeException('该业务由管理员维护', 40300);
        }

        $planId = (int) ($values['plan_id'] ?? 0);
        if (in_array($entity, ['project', 'syllabus', 'gradeRule', 'reflection'], true)) {
            $this->assertCourseLeader($planId);
            return;
        }
        if ($entity === 'lessonPlan' && !PracticeRecord::teacherRelatedToPlan(
            $this->moduleType,
            $planId,
            (int) $this->currentTeacherId(true)
        )) {
            throw new RuntimeException('仅开课任务任课教师可维护教案', 40300);
        }
    }

    /** 校验教师只能修改本人提交的教案。 */
    private function assertEntityRowWriter(string $entity, object $row): void
    {
        if (!$this->isTeacher() || $entity !== 'lessonPlan') {
            return;
        }
        if ((int) ($row->submitter_id ?? 0) !== $this->accountId()) {
            throw new RuntimeException('只能修改本人提交的教案', 40300);
        }
    }

    /** 失效开课任务已有归档。 */
    private function invalidatePlanArchives(int $planId, string $reason): void
    {
        if ($planId <= 0) {
            return;
        }

        PracticeRecord::invalidatePlanArchives(
            $this->moduleType,
            $planId,
            $this->accountId(),
            $reason,
            $this->now()
        );
    }

    /** 禁止提交账号审核本人提交的材料。 */
    private function assertNotSelfReview(string $entityType, int $entityId, object $row): void
    {
        $submitterId = (int) ($row->submitter_id ?? 0);
        if ($submitterId <= 0) {
            $submitterId = PracticeRecord::latestSubmitterAccountId($entityType, $entityId);
        }
        if ($submitterId > 0 && $submitterId === $this->accountId()) {
            throw new RuntimeException('提交人不能审核本人提交的材料', 40300);
        }
    }

    /** 校验课程级材料审核角色。 */
    private function assertEntityReviewer(string $entity, object $row): void
    {
        if ($this->isStudent()) {
            throw new RuntimeException('学生无审核权限', 40300);
        }
        if (!$this->isTeacher()) {
            return;
        }
        if ($entity !== 'score') {
            throw new RuntimeException('课程级材料由管理员审核', 40300);
        }

        $this->assertCourseLeader((int) ($row->plan_id ?? 0));
    }

    /** 校验项目执行材料审核角色。 */
    private function assertExecutionReviewer(): void
    {
        if ($this->isStudent()) {
            throw new RuntimeException('学生无审核权限', 40300);
        }
    }

    /** 校验当前教师是否为开课任务课程负责人。 */
    private function assertCourseLeader(int $planId): void
    {
        if ($planId <= 0 || !PracticeRecord::teacherRelatedToPlan($this->moduleType, $planId, (int) $this->currentTeacherId(true), true)) {
            throw new RuntimeException('仅课程负责人可维护该材料', 40300);
        }
    }

    /** 限制归档中心访问角色。 */
    private function assertArchiveAccess(): void
    {
        if ($this->isStudent()) {
            throw new RuntimeException('无归档中心访问权限', 40300);
        }
    }

    private function requireEntityPermission(string $entity): void
    {
        $this->requirePermission(self::ENTITIES[$entity]['permission'] ?? 'manage');
    }

    private function requirePermission(string $type): void
    {
        $this->accountId();
        $code = self::PERMISSIONS[$type] ?? '';
        if (!$code || !in_array($code, CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    /** 校验业务写操作的类别。 */
    private function assertWritableModule(): void
    {
        if (!in_array($this->moduleType, ['training', 'lab'], true)) {
            throw new InvalidArgumentException('请选择实验或实训类别');
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

    private function notifyWorkflowSubmitted(string $entity, int $entityId, array $values): void
    {
        $this->notifyTemplateAccounts($this->workflowSubmitReceiverAccountIds($entity, $values), $this->workflowTemplateCode($entity, 'submit'), [
            'module_name' => $this->businessName($entity),
            'submitter_name' => $this->currentAccountName(),
            'entity_title' => (string) (($values['title'] ?? '') ?: $this->businessName($entity) . '#' . $entityId),
            'module_key' => $this->moduleType,
            'panel_key' => $this->entityPanelKey($entity),
        ], $this->entityType($entity), $entityId);
    }

    private function notifyWorkflowReviewed(string $entity, int $entityId, object $row, string $status, string $opinion): void
    {
        $this->notifyTemplateAccounts($this->submitterAccountIds($row), $this->workflowTemplateCode($entity, 'review'), [
            'module_name' => $this->businessName($entity),
            'status_text' => $this->statusText($status),
            'opinion_text' => $opinion,
            'entity_title' => (string) (($row->title ?? '') ?: $this->businessName($entity) . '#' . $entityId),
            'module_key' => $this->moduleType,
            'panel_key' => $this->entityPanelKey($entity),
        ], $this->entityType($entity), $entityId);
    }

    private function notifyWorkflowReopened(string $entity, int $entityId, object $row, string $opinion): void
    {
        $this->notifyTemplateAccounts($this->submitterAccountIds($row), $this->workflowTemplateCode($entity, 'reopen'), [
            'module_name' => $this->businessName($entity),
            'reviewer_name' => $this->currentAccountName(),
            'entity_title' => (string) (($row->title ?? '') ?: $this->businessName($entity) . '#' . $entityId),
            'opinion_text' => $opinion,
            'module_key' => $this->moduleType,
            'panel_key' => $this->entityPanelKey($entity),
        ], $this->entityType($entity), $entityId);
    }

    private function notifyExecutionSubmitted(string $execution, int $entityId, array $values): void
    {
        $accountIds = $this->teacherAccountIds((int) ($values['teacher_id'] ?? 0));
        if (!$accountIds) {
            $accountIds = $this->workflowAdminAccountIds();
        }

        $this->notifyTemplateAccounts($accountIds, $this->workflowTemplateCode($execution, 'submit'), [
            'module_name' => $this->businessName($execution),
            'submitter_name' => $this->currentAccountName(),
            'entity_title' => (string) (($values['title'] ?? '') ?: $this->businessName($execution) . '#' . $entityId),
            'module_key' => $this->moduleType,
            'panel_key' => $this->executionPanelKey($execution),
        ], $this->executionEntityType($execution), $entityId);
    }

    private function notifyExecutionReviewed(string $execution, int $entityId, object $row, string $status, string $opinion): void
    {
        $this->notifyTemplateAccounts($this->studentAccountIds((int) ($row->student_id ?? 0)), $this->workflowTemplateCode($execution, 'review'), [
            'module_name' => $this->businessName($execution),
            'status_text' => $this->statusText($status),
            'opinion_text' => $opinion,
            'entity_title' => (string) (($row->title ?? '') ?: $this->businessName($execution) . '#' . $entityId),
            'module_key' => $this->moduleType,
            'panel_key' => $this->executionPanelKey($execution),
        ], $this->executionEntityType($execution), $entityId);
    }

    private function notifyExecutionReopened(string $execution, int $entityId, object $row, string $opinion): void
    {
        $this->notifyTemplateAccounts($this->studentAccountIds((int) ($row->student_id ?? 0)), $this->workflowTemplateCode($execution, 'reopen'), [
            'module_name' => $this->businessName($execution),
            'reviewer_name' => $this->currentAccountName(),
            'entity_title' => (string) (($row->title ?? '') ?: $this->businessName($execution) . '#' . $entityId),
            'opinion_text' => $opinion,
            'module_key' => $this->moduleType,
            'panel_key' => $this->executionPanelKey($execution),
        ], $this->executionEntityType($execution), $entityId);
    }

    private function notifyTemplateAccounts(array $accountIds, string $templateCode, array $variables, string $entityType, int $entityId): void
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
        if (!$accountIds) {
            return;
        }

        try {
            (new MessageService())->send([
                'send_scope' => 'custom',
                'account_ids' => $accountIds,
                'template_code' => $templateCode,
                'variables' => $variables,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'include_sender' => false,
            ], CurrentContext::accountId() ?: 0, $this->currentAccountName());
        } catch (Throwable) {
        }
    }

    private function workflowTemplateCode(string $key, string $action): string
    {
        $business = [
            'plan' => 'plan',
            'syllabus' => 'syllabus',
            'lessonPlan' => 'lesson_plan',
            'reflection' => 'reflection',
            'score' => 'score',
            'journal' => 'journal',
            'report' => 'report',
        ][$key] ?? '';
        $suffix = [
            'submit' => 'submit_todo',
            'review' => 'review_result',
            'reopen' => 'reopen_todo',
        ][$action] ?? '';

        return $business !== '' && $suffix !== ''
            ? "{$this->moduleType}_{$business}_{$suffix}"
            : match ($action) {
                'review' => 'workflow_review_result',
                'reopen' => 'workflow_reopen_todo',
                default => 'workflow_submit_todo',
            };
    }

    private function teacherAccountIds(int $teacherId): array
    {
        return Account::enabledIdsByUserIds([PracticeRecord::teacherUserId($teacherId)]);
    }

    private function workflowSubmitReceiverAccountIds(string $entity, array $values): array
    {
        $accountIds = [];
        if (in_array($entity, ['plan', 'syllabus', 'lessonPlan', 'reflection', 'score'], true)) {
            $accountIds = $this->workflowAdminAccountIds();
        }

        if ($entity === 'score') {
            $accountIds = array_merge(
                $accountIds,
                $this->teacherAccountIds(PracticeRecord::planCourseLeaderId($this->moduleType, (int) ($values['plan_id'] ?? 0)))
            );
        }

        if (!$accountIds) {
            $accountIds = $this->teacherAccountIds((int) ($values['teacher_id'] ?? 0));
        }

        if (!$accountIds) {
            $accountIds = $this->workflowAdminAccountIds();
        }

        return $accountIds;
    }

    private function workflowAdminAccountIds(): array
    {
        $ids = [];
        foreach (['school_admin', 'college_admin', 'profession_admin'] as $roleType) {
            $ids = array_merge($ids, Account::messageTargetIds(['role_type' => $roleType]));
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    private function studentAccountIds(int $studentId): array
    {
        return Account::enabledIdsByUserIds([PracticeRecord::studentUserId($studentId)]);
    }

    private function submitterAccountIds(object $row): array
    {
        $submitterId = (int) ($row->submitter_id ?? 0);
        if ($submitterId > 0) {
            return [$submitterId];
        }
        if ((int) ($row->student_id ?? 0) > 0) {
            return $this->studentAccountIds((int) $row->student_id);
        }

        return [];
    }

    private function businessName(string $key): string
    {
        return self::ENTITIES[$key]['title'] ?? self::EXECUTIONS[$key]['title'] ?? $key;
    }

    private function entityPanelKey(string $entity): string
    {
        return [
            'plan' => 'plans',
            'syllabus' => 'syllabus',
            'lessonPlan' => 'lessonPlans',
            'reflection' => 'reflections',
            'score' => 'scores',
        ][$entity] ?? 'overview';
    }

    private function executionPanelKey(string $execution): string
    {
        return [
            'journal' => 'journals',
            'report' => 'reports',
            'sign_in' => 'signIns',
        ][$execution] ?? 'overview';
    }

    private function statusText(string $status): string
    {
        return [
            'accept' => '通过',
            'modify' => '退回修改',
            'refuse' => '未通过',
            'wait' => '待审核',
        ][$status] ?? $status;
    }

    private function currentAccountName(): string
    {
        return (string) (CurrentContext::get('user_name') ?: CurrentContext::get('login_name') ?: self::MODULES[$this->moduleType]['name']);
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

    private function workflowLock(string $module, string $entity, int $id, callable $callback): mixed
    {
        return (new WorkflowLock())->run(WorkflowLock::key($module, $entity, $id), $callback);
    }

    private function requiredInt(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$key} 无效");
        }

        return (int) $value;
    }

    /** 读取正整数列表。 */
    private function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
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

    /** 读取标准时间。 */
    private function timeInput(Request $request, string $key): string
    {
        $value = trim((string) $request->input($key, ''));
        if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', $value)) {
            throw new InvalidArgumentException("{$key} 无效");
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    /** 生成排课日期锁标识。 */
    private function scheduleLockId(array $values): int
    {
        $date = (string) ($values['schedule_date'] ?? date('Y-m-d'));
        return max(1, (int) sprintf('%u', crc32($date)));
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
