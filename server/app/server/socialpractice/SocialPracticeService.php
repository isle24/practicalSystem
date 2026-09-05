<?php

namespace app\server\socialpractice;

use app\model\channel\SocialPracticeRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\export\ExportTaskService;
use app\server\file\FileService;
use app\server\message\MessageService;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Throwable;

class SocialPracticeService
{
    private const RESOURCES = [
        'plan', 'project', 'implementation', 'declaration', 'participant', 'teacher',
        'safety', 'material', 'attendance', 'patch_sign', 'score', 'archive',
    ];

    private const WORKFLOW_RESOURCES = ['plan', 'project', 'implementation', 'declaration', 'material', 'patch_sign', 'score'];

    private const PERMISSIONS = [
        'view' => 'social_practice:view',
        'plan' => 'social_practice:plan:manage',
        'project' => 'social_practice:project:manage',
        'declare' => 'social_practice:declare',
        'approve' => 'social_practice:approve',
        'teacher_confirm' => 'social_practice:teacher:confirm',
        'material' => 'social_practice:material:manage',
        'attendance' => 'social_practice:attendance:manage',
        'score' => 'social_practice:score:manage',
        'score_approve' => 'social_practice:score:approve',
        'archive' => 'social_practice:archive',
        'export' => 'social_practice:export',
    ];

    private const REVIEW_RULES = [
        'plan' => ['accept' => ['min' => 0, 'max' => 300], 'modify' => ['min' => 8, 'max' => 800]],
        'implementation' => ['accept' => ['min' => 0, 'max' => 300], 'modify' => ['min' => 8, 'max' => 800]],
        'declaration' => ['accept' => ['min' => 0, 'max' => 300], 'modify' => ['min' => 8, 'max' => 800]],
        'material' => ['accept' => ['min' => 0, 'max' => 300], 'modify' => ['min' => 5, 'max' => 500]],
        'patch_sign' => ['accept' => ['min' => 0, 'max' => 300], 'modify' => ['min' => 5, 'max' => 500]],
        'score' => ['accept' => ['min' => 0, 'max' => 300], 'modify' => ['min' => 5, 'max' => 500]],
    ];

    private const TITLES = [
        'plan' => '社会实践计划',
        'project' => '集中实践项目',
        'implementation' => '集中实践实施申请',
        'declaration' => '分散实践申报',
        'material' => '社会实践材料',
        'patch_sign' => '社会实践补签',
        'score' => '社会实践成绩',
        'archive' => '社会实践归档',
    ];

    private const MATERIAL_TITLES = [
        'safety_agreement' => '安全承诺书',
        'insurance' => '保险材料',
        'emergency_plan' => '应急预案',
        'parent_notice' => '家长知情书',
        'practice_report' => '实践报告',
        'practice_photo' => '实践照片',
        'practice_proof' => '实践证明',
        'social_practice_report' => '社会实践报告',
    ];

    private readonly WorkflowLock $lock;
    private readonly MessageService $messages;

    public function __construct()
    {
        $this->lock = new WorkflowLock();
        $this->messages = new MessageService();
    }

    /** 读取社会实践总览。 */
    public function overview(Request $request): array
    {
        $this->requirePermission('view');
        return ['statistics' => SocialPracticeRecord::overviewRows($this->scopeContext())];
    }

    /** 读取社会实践统计报表。 */
    public function statistics(Request $request): array
    {
        $this->requirePermission('view');
        return SocialPracticeRecord::statisticsRows($this->scopeContext(), $this->filters($request));
    }

    /** 创建社会实践统计报表导出任务。 */
    public function exportStatistics(Request $request): array
    {
        $this->requirePermission('export');
        $statistics = SocialPracticeRecord::statisticsRows($this->scopeContext(), $this->filters($request));
        $rows = [];
        foreach ((array) ($statistics['by_grade'] ?? []) as $row) {
            $rows[] = ['年级', (string) ($row['grade_name'] ?? '-'), '计划数', (int) ($row['plan_count'] ?? 0), '', ''];
        }
        foreach ((array) ($statistics['by_mode'] ?? []) as $row) {
            $rows[] = ['实践模式', (string) ($row['practice_mode'] ?? '-'), '项目数', (int) ($row['project_count'] ?? 0), '计划数', (int) ($row['plan_count'] ?? 0)];
        }
        foreach ((array) ($statistics['by_college'] ?? []) as $row) {
            $rows[] = ['学院', (string) ($row['dep_name'] ?? '-'), '学生数', (int) ($row['student_count'] ?? 0), '已分配人数', (int) ($row['assigned_count'] ?? 0)];
        }

        return (new ExportTaskService())->create([
            'type' => 'social_practice_statistics',
            'file_name' => '社会实践统计_' . date('Ymd_His') . '.xlsx',
            'params' => [
                'title' => '社会实践统计',
                'headers' => ['统计维度', '对象', '指标一', '数值一', '指标二', '数值二'],
                'rows' => $rows,
                'summary' => $statistics['summary'] ?? [],
            ],
        ], true);
    }

    /** 返回社会实践计划导入模板。 */
    public function planImportTemplate(Request $request): array
    {
        $this->requirePermission('plan');
        return (new SocialPracticePlanSourceService())->template();
    }

    /** 预览社会实践计划 Excel 导入。 */
    public function previewPlanImport(Request $request): array
    {
        $this->requirePermission('plan');
        return (new SocialPracticePlanSourceService())->preview($request, $this->scopeContext());
    }

    /** 确认社会实践计划 Excel 导入。 */
    public function confirmPlanImport(Request $request): array
    {
        $this->requirePermission('plan');
        return (new SocialPracticePlanSourceService())->confirm($request, $this->scopeContext(), $this->accountId());
    }

    /** 读取社会实践表单选项。 */
    public function options(Request $request): array
    {
        $this->requirePermission('view');
        return SocialPracticeRecord::optionRows($this->scopeContext());
    }

    /** 读取计划范围内的学生选项。 */
    public function eligibleStudents(Request $request): array
    {
        $this->requirePermission('view');
        $planId = $this->requiredInt($request, 'plan_id');
        $this->assertVisible('plan', $planId, $this->scopeContext());
        return ['items' => SocialPracticeRecord::eligibleStudentOptionRowsForPlan($planId)];
    }

    /** 读取社会实践分页列表。 */
    public function list(Request $request): array
    {
        $this->requirePermission('view');
        $resource = $this->resource($request);
        return SocialPracticeRecord::resourcePage($this->scopeContext(), $resource, $this->filters($request));
    }

    /** 读取社会实践详情。 */
    public function detail(Request $request): array
    {
        $this->requirePermission('view');
        $resource = $this->workflowResource($request);
        $id = $this->requiredId($request);
        $detail = SocialPracticeRecord::resourceDetail($this->scopeContext(), $resource, $id);
        if (!$detail) {
            throw new RuntimeException('数据不存在或无访问权限', 40400);
        }
        if ($resource === 'material') {
            $detail['attachments'] = (new FileService())->relations(SocialPracticeRecord::entityType('material'), $id, 'attachment');
        }
        return $detail;
    }

    /** 读取社会实践时间线。 */
    public function timeline(Request $request): array
    {
        $this->requirePermission('view');
        $resource = $this->workflowResource($request);
        $id = $this->requiredId($request);
        $detail = SocialPracticeRecord::resourceDetail($this->scopeContext(), $resource, $id);
        if (!$detail) {
            throw new RuntimeException('数据不存在或无访问权限', 40400);
        }
        $entityType = SocialPracticeRecord::entityType($resource);
        return [
            'entity_type' => $entityType,
            'entity_id' => $id,
            'records' => SocialPracticeRecord::recordingRows($entityType, $id),
            'reviews' => SocialPracticeRecord::reviewOpinionRows($entityType, $id),
        ];
    }

    /** 保存社会实践基础资料草稿。 */
    public function save(Request $request): array
    {
        $resource = $this->workflowResource($request);
        return match ($resource) {
            'plan' => $this->savePlan($request),
            'project' => $this->saveProject($request),
            'implementation' => $this->saveImplementation($request),
            'declaration' => $this->saveDeclaration($request),
            default => throw new InvalidArgumentException('该资源使用专用保存接口'),
        };
    }

    /** 提交社会实践业务审核。 */
    public function submit(Request $request): array
    {
        $resource = $this->workflowResource($request);
        $id = $this->requiredId($request);
        $this->requireSubmitPermission($resource);
        $scope = $this->scopeContext();
        $this->assertVisible($resource, $id, $scope);

        $result = $this->withLock($resource, $id, function () use ($resource, $id, $scope): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($resource, $id, $scope): array {
                $row = SocialPracticeRecord::lockEntity($resource, $id);
                if (!$row || !SocialPracticeRecord::entityVisible($scope, $resource, $id)) {
                    throw new RuntimeException('数据不存在或无访问权限', 40400);
                }
                $this->assertSubmitter($resource, $row, $scope);
                if (!in_array((string) $row->status, ['draft', 'modify'], true)) {
                    throw new RuntimeException('当前状态不能提交审核', 409);
                }

                if ($resource === 'plan') {
                    $this->assertPlanReadyForSubmission($row, $scope);
                }
                if ($resource === 'implementation') {
                    $this->assertImplementationReady($row);
                }
                if ($resource === 'declaration') {
                    $this->assertDeclarationReady($row);
                }
                if (in_array($resource, ['material', 'patch_sign', 'score'], true)) {
                    $this->assertExecutionReady($resource, $row);
                }

                $now = $this->now();
                $updates = ['status' => 'wait', 'updated_at' => $now];
                if ($resource === 'plan') {
                    $updates['current_node_id'] = $this->firstReviewNodeId((int) ($row->approval_flow_id ?? 0));
                }
                if ($resource === 'implementation') {
                    $updates['current_node_id'] = $this->firstReviewNodeId((int) ($row->approval_flow_id ?? 0));
                    $updates['submitted_at'] = $now;
                }
                if ($resource === 'declaration') {
                    $updates['submitted_at'] = $now;
                    $updates['teacher_confirm_status'] = 'pending';
                }
                if (in_array($resource, ['plan', 'implementation'], true) && !$updates['current_node_id']) {
                    $updates['status'] = 'accept';
                }
                SocialPracticeRecord::saveEntity($resource, $id, $updates);
                $recordingId = $this->recordWorkflow($resource, $id, 'submit', (string) $row->status, (string) $updates['status'], $this->workflowContent($resource, $row), null);
                return ['id' => $id, 'recording_id' => $recordingId, 'status' => $updates['status']];
            });
        });

        if ($resource === 'declaration') {
            $declaration = SocialPracticeRecord::declarationRow($id) ?: [];
            $teacherId = (int) (($declaration['selected_teacher_id'] ?? 0) ?: ($declaration['assigned_teacher_id'] ?? 0));
            if ($teacherId > 0) {
                $this->notifyTeacherConfirmation($id, $teacherId, (string) ($declaration['teacher_confirm_deadline_at'] ?? ''));
            }
        } else {
            $this->notifySubmitted($resource, $id);
        }
        return $result;
    }

    /** 审核社会实践业务。 */
    public function review(Request $request): array
    {
        $resource = $this->workflowResource($request);
        $id = $this->requiredId($request);
        $this->requireReviewPermission($resource);
        $status = $this->enum($request, 'status', ['accept', 'modify']);
        $opinion = $this->reviewOpinion($request, $resource, $status);
        $scope = $this->scopeContext();
        $this->assertVisible($resource, $id, $scope);

        $result = $this->withLock($resource, $id, function () use ($resource, $id, $status, $opinion, $scope): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($resource, $id, $status, $opinion, $scope): array {
                $row = SocialPracticeRecord::lockEntity($resource, $id);
                if (!$row || !SocialPracticeRecord::entityVisible($scope, $resource, $id)) {
                    throw new RuntimeException('数据不存在或无访问权限', 40400);
                }
                if ((string) $row->status !== 'wait') {
                    throw new RuntimeException('当前状态不可审核', 409);
                }
                if ($resource === 'declaration' && (string) ($row->teacher_confirm_status ?? '') !== 'accepted') {
                    throw new RuntimeException('指导教师尚未确认申报', 42201);
                }
                $this->assertReviewNode($resource, $row, $scope);
                if ($status === 'modify' && in_array($resource, ['plan', 'implementation'], true)) {
                    $node = SocialPracticeRecord::approvalNodeRow((int) ($row->current_node_id ?? 0));
                    if ($node && (string) ($node['can_return'] ?? 'true') !== 'true') {
                        throw new RuntimeException('当前审批节点不允许退回修改', 409);
                    }
                }

                $now = $this->now();
                $from = (string) $row->status;
                $to = $status;
                $updates = ['updated_at' => $now];
                if ($status === 'modify') {
                    $updates['status'] = 'modify';
                    if (in_array($resource, ['plan', 'implementation'], true)) {
                        $updates['current_node_id'] = null;
                    }
                } else {
                    [$to, $nextNodeId] = $this->advanceApprovalNode($resource, $row, $scope, $now, $opinion);
                    $updates['status'] = $to;
                    if (in_array($resource, ['plan', 'implementation'], true)) {
                        $updates['current_node_id'] = $nextNodeId;
                    }
                    if ($resource === 'score' && $to === 'accept') {
                        $updates['reviewer_id'] = $this->accountId();
                        $updates['reviewed_at'] = $now;
                        $updates['credit_recognized'] = 'true';
                    }
                    if ($resource === 'declaration' && $to === 'accept') {
                        if ((string) ($row->teacher_confirm_status ?? '') !== 'accepted') {
                            throw new RuntimeException('指导教师尚未确认申报', 42201);
                        }
                        SocialPracticeRecord::activateDeclaration($row, $this->accountId(), $now);
                    }
                    if ($resource === 'patch_sign' && $to === 'accept') {
                        SocialPracticeRecord::createPatchSignIn($id, $now);
                    }
                }
                SocialPracticeRecord::saveEntity($resource, $id, $updates);
                $recordingId = $this->recordWorkflow($resource, $id, 'review', $from, $to, $opinion, $status);
                return ['id' => $id, 'recording_id' => $recordingId, 'status' => $to, 'opinion' => $opinion];
            });
        });

        $this->notifyReviewed($resource, $id, (string) ($result['status'] ?? $status), $opinion);
        return $result;
    }

    /** 发布审核通过的社会实践计划。 */
    public function publish(Request $request): array
    {
        $this->requirePermission('plan');
        $this->requireAdminRole();
        $id = $this->requiredId($request);
        $scope = $this->scopeContext();
        $this->assertVisible('plan', $id, $scope);
        $result = $this->withLock('plan', $id, function () use ($id, $scope): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($id, $scope): array {
                $row = SocialPracticeRecord::lockEntity('plan', $id);
                if (!$row || !SocialPracticeRecord::entityVisible($scope, 'plan', $id)) {
                    throw new RuntimeException('计划不存在或无访问权限', 40400);
                }
                if ((string) $row->status !== 'accept') {
                    throw new RuntimeException('只有审核通过的计划才能发布', 409);
                }
                $now = $this->now();
                SocialPracticeRecord::saveEntity('plan', $id, [
                    'phase' => 'enrolling',
                    'published_at' => $now,
                    'updated_at' => $now,
                ]);
                $rosterCreated = (string) ($row->participation_mode ?? '') === 'mandatory'
                    ? SocialPracticeRecord::syncMandatoryPlanParticipants($id, $now)
                    : 0;
                $recordingId = $this->recordWorkflow('plan', $id, 'publish', 'accept', 'accept', '发布社会实践计划', null);
                return ['id' => $id, 'recording_id' => $recordingId, 'status' => 'accept', 'phase' => 'enrolling', 'roster_created' => $rosterCreated];
            });
        });
        $this->notifyPlanPublished($id);
        return $result;
    }

    /** 发起通过后修改。 */
    public function requestModification(Request $request): array
    {
        $resource = $this->workflowResource($request);
        $id = $this->requiredId($request);
        $this->requireReviewPermission($resource);
        $opinion = $this->reviewOpinion($request, $resource, 'modify');
        $scope = $this->scopeContext();
        $this->assertVisible($resource, $id, $scope);

        $result = $this->withLock($resource, $id, function () use ($resource, $id, $opinion, $scope): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($resource, $id, $opinion, $scope): array {
                $row = SocialPracticeRecord::lockEntity($resource, $id);
                if (!$row || !SocialPracticeRecord::entityVisible($scope, $resource, $id)) {
                    throw new RuntimeException('数据不存在或无访问权限', 40400);
                }
                if ((string) $row->status !== 'accept') {
                    throw new RuntimeException('只有已通过数据可以发起修改', 409);
                }
                $now = $this->now();
                $updates = ['status' => 'modify', 'updated_at' => $now];
                if (in_array($resource, ['plan', 'implementation'], true)) {
                    $updates['current_node_id'] = null;
                }
                if ($resource === 'plan') {
                    $updates['phase'] = 'ready';
                    $updates['published_at'] = null;
                }
                SocialPracticeRecord::saveEntity($resource, $id, $updates);
                $recordingId = $this->recordWorkflow($resource, $id, 'modify_after_accept', 'accept', 'modify', $opinion, 'modify');
                return ['id' => $id, 'recording_id' => $recordingId, 'status' => 'modify'];
            });
        });
        $this->notifyReviewed($resource, $id, 'modify', $opinion);
        return $result;
    }

    /** 分配集中实践指导教师。 */
    public function assignTeachers(Request $request): array
    {
        $this->requirePermission('project');
        $this->requireAdminRole();
        $projectId = $this->requiredId($request, 'project_id');
        $project = $this->visibleRow('project', $projectId);
        if ((string) ($project['practice_mode'] ?? '') !== 'centralized') {
            throw new InvalidArgumentException('只有集中实践项目可以分配教师');
        }
        $teachers = $this->arrayInput($request, 'teachers');
        $normalized = [];
        $leaderCount = 0;
        $teacherIds = [];
        $scope = $this->scopeContext();
        foreach ($teachers as $teacher) {
            $teacherId = (int) ($teacher['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                continue;
            }
            if (in_array($teacherId, $teacherIds, true)) {
                throw new InvalidArgumentException('同一教师不能重复分配');
            }
            if (!SocialPracticeRecord::teacherOptionRow($scope, $teacherId)) {
                throw new RuntimeException('存在无效或超出管理范围的教师', 40301);
            }
            $teacherIds[] = $teacherId;
            $role = (string) ($teacher['teacher_role'] ?? 'guide');
            if (!in_array($role, ['leader', 'guide'], true)) {
                throw new InvalidArgumentException('教师角色无效');
            }
            $leaderCount += $role === 'leader' ? 1 : 0;
            $normalized[] = [
                'teacher_id' => $teacherId,
                'teacher_role' => $role,
                'capacity' => max(0, (int) ($teacher['capacity'] ?? 0)),
            ];
        }
        if ($leaderCount !== 1 || !$normalized) {
            throw new InvalidArgumentException('集中实践必须配置一名负责人和至少一名指导教师');
        }
        $result = $this->withLock('project', $projectId, function () use ($projectId, $normalized): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($projectId, $normalized): array {
                SocialPracticeRecord::syncProjectTeachers($projectId, $normalized, $this->now());
                $recordingId = $this->recordWorkflow('project', $projectId, 'assign_teachers', null, 'enabled', '配置集中实践指导教师', null);
                return ['id' => $projectId, 'recording_id' => $recordingId, 'teachers' => $normalized];
            });
        });
        return $result;
    }

    /** 分配集中实践学生及具体指导教师。 */
    public function assignStudents(Request $request): array
    {
        $this->requirePermission('project');
        $this->requireAdminRole();
        $projectId = $this->requiredId($request, 'project_id');
        $project = $this->visibleRow('project', $projectId);
        if ((string) ($project['practice_mode'] ?? '') !== 'centralized') {
            throw new InvalidArgumentException('只有集中实践项目可以分配学生');
        }
        $participants = [];
        $studentIds = [];
        foreach ($this->arrayInput($request, 'participants') as $item) {
            $studentId = (int) ($item['student_id'] ?? 0);
            $teacherId = (int) ($item['teacher_id'] ?? 0);
            if ($studentId > 0 && $teacherId > 0) {
                if (in_array($studentId, $studentIds, true)) {
                    throw new InvalidArgumentException('同一学生不能重复分配');
                }
                $studentIds[] = $studentId;
                $participants[] = ['student_id' => $studentId, 'teacher_id' => $teacherId];
            }
        }
        if (!$participants) {
            throw new InvalidArgumentException('请至少选择一名学生');
        }
        $eligibleStudentIds = SocialPracticeRecord::eligibleStudentIdsForPlan((int) $project['plan_id'], $studentIds);
        if (array_diff($studentIds, $eligibleStudentIds)) {
            throw new InvalidArgumentException('存在不属于当前计划年级或适用范围的学生');
        }
        $teacherRows = SocialPracticeRecord::projectTeacherRows($projectId);
        $capacities = [];
        foreach ($teacherRows as $teacher) {
            $capacities[(int) $teacher['teacher_id']] = max(0, (int) $teacher['capacity']);
        }
        $counts = [];
        foreach ($participants as $participant) {
            $teacherId = $participant['teacher_id'];
            if (!array_key_exists($teacherId, $capacities)) {
                throw new InvalidArgumentException('存在未绑定到项目的指导教师');
            }
            $counts[$teacherId] = ($counts[$teacherId] ?? 0) + 1;
        }
        foreach ($counts as $teacherId => $count) {
            if ($capacities[$teacherId] > 0 && $count > $capacities[$teacherId]) {
                throw new InvalidArgumentException('指导教师分配人数超过容量');
            }
        }
        if ((int) ($project['capacity'] ?? 0) > 0 && count($participants) > (int) $project['capacity']) {
            throw new InvalidArgumentException('项目分配人数超过项目容量');
        }
        $result = $this->withLock('project', $projectId, function () use ($project, $projectId, $participants): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($project, $projectId, $participants): array {
                SocialPracticeRecord::syncProjectParticipants($projectId, (int) $project['plan_id'], 'centralized', $participants, $this->now());
                $recordingId = $this->recordWorkflow('project', $projectId, 'assign_students', null, 'enabled', '配置集中实践学生和指导教师', null);
                return ['id' => $projectId, 'recording_id' => $recordingId, 'participant_count' => count($participants)];
            });
        });
        return $result;
    }

    /** 分配分散实践指导教师。 */
    public function assignDeclarationTeacher(Request $request): array
    {
        $this->requirePermission('project');
        $this->requireAdminRole();
        $declarationId = $this->requiredId($request, 'declaration_id');
        $teacherId = $this->requiredInt($request, 'teacher_id');
        $scope = $this->scopeContext();
        $declaration = SocialPracticeRecord::declarationRow($declarationId);
        if (!$declaration || !SocialPracticeRecord::entityVisible($scope, 'declaration', $declarationId)) {
            throw new RuntimeException('申报不存在或无访问权限', 40400);
        }
        if (!in_array((string) ($declaration['status'] ?? ''), ['draft', 'wait', 'modify'], true)) {
            throw new RuntimeException('当前申报不可直接调整指导教师，请先发起通过后修改', 409);
        }
        $teacher = SocialPracticeRecord::teacherOptionRow($scope, $teacherId);
        if (!$teacher) {
            throw new RuntimeException('指导教师不存在或不在当前管理范围', 40301);
        }
        $plan = SocialPracticeRecord::planRow((int) ($declaration['plan_id'] ?? 0)) ?: [];
        $deadline = $this->dateAfterHours((int) ($plan['teacher_confirm_hours'] ?? 48));
        $result = $this->withLock('declaration', $declarationId, function () use ($declarationId, $teacherId, $deadline, $teacher): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($declarationId, $teacherId, $deadline, $teacher): array {
                $row = SocialPracticeRecord::lockEntity('declaration', $declarationId);
                if (!$row || !in_array((string) $row->status, ['draft', 'wait', 'modify'], true)) {
                    throw new RuntimeException('当前申报不可直接调整指导教师，请刷新后重试', 409);
                }
                $now = $this->now();
                SocialPracticeRecord::assignDeclarationTeacher($declarationId, $teacherId, $deadline, $now);
                $recordingId = $this->recordWorkflow('declaration', $declarationId, 'assign_teacher', (string) ($row->teacher_confirm_status ?? 'pending'), 'pending', '管理员分配指导教师：' . (string) ($teacher['teacher_name'] ?? $teacherId), null);
                return ['id' => $declarationId, 'recording_id' => $recordingId, 'teacher_id' => $teacherId, 'teacher_name' => $teacher['teacher_name'] ?? '', 'status' => 'pending', 'deadline_at' => $deadline];
            });
        });
        $this->notifyTeacherConfirmation($declarationId, $teacherId, $deadline);
        return $result;
    }

    /** 确认分散实践团队成员。 */
    public function confirmMember(Request $request): array
    {
        $this->requirePermission('declare');
        $this->requireStudentRole();
        $declarationId = $this->requiredId($request, 'declaration_id');
        $status = $this->enum($request, 'status', ['accepted', 'refused']);
        $studentId = $this->currentStudentId(true);
        $row = SocialPracticeRecord::declarationMemberRow($declarationId, $studentId);
        if (!$row) {
            throw new RuntimeException('你不是该申报的团队成员', 40301);
        }
        $result = $this->withLock('declaration', $declarationId, function () use ($declarationId, $studentId, $status): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($declarationId, $studentId, $status): array {
                $now = $this->now();
                if (SocialPracticeRecord::confirmDeclarationMember($declarationId, $studentId, $status, $now) !== 1) {
                    throw new RuntimeException('成员确认状态已变化，请刷新后重试', 409);
                }
                $recordingId = $this->recordWorkflow('declaration', $declarationId, 'confirm_member', null, $status, $status === 'accepted' ? '成员确认加入' : '成员拒绝加入', null);
                return ['id' => $declarationId, 'recording_id' => $recordingId, 'status' => $status];
            });
        });
        return $result;
    }

    /** 确认分散实践指导教师。 */
    public function confirmTeacher(Request $request): array
    {
        $this->requirePermission('teacher_confirm');
        $this->requireTeacherRole();
        $declarationId = $this->requiredId($request, 'declaration_id');
        $status = $this->enum($request, 'status', ['accepted', 'refused']);
        $teacherId = $this->currentTeacherId(true);
        $declaration = SocialPracticeRecord::declarationRow($declarationId);
        if (!$declaration || !in_array($teacherId, [(int) ($declaration['selected_teacher_id'] ?? 0), (int) ($declaration['assigned_teacher_id'] ?? 0)], true)) {
            throw new RuntimeException('该申报未分配给当前教师', 40301);
        }
        if (!empty($declaration['teacher_confirm_deadline_at']) && (string) $declaration['teacher_confirm_deadline_at'] < $this->now()) {
            throw new RuntimeException('指导教师确认已超时，请联系管理员重新分配', 409);
        }
        $result = $this->withLock('teacher_capacity', $teacherId, function () use ($declarationId, $teacherId, $status): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($declarationId, $teacherId, $status): array {
                if (!SocialPracticeRecord::lockTeacher($teacherId)) {
                    throw new RuntimeException('指导教师不存在或已停用', 409);
                }
                $row = SocialPracticeRecord::lockEntity('declaration', $declarationId);
                if (!$row || (string) ($row->teacher_confirm_status ?? '') !== 'pending') {
                    throw new RuntimeException('教师确认状态已变化，请刷新后重试', 409);
                }
                if (!in_array($teacherId, [(int) ($row->selected_teacher_id ?? 0), (int) ($row->assigned_teacher_id ?? 0)], true)) {
                    throw new RuntimeException('该申报已分配给其他指导教师', 409);
                }
                if ($status === 'accepted') {
                    $capacity = SocialPracticeRecord::teacherCapacity($teacherId);
                    $memberCount = SocialPracticeRecord::declarationMemberCount($declarationId);
                    if ($capacity > 0 && SocialPracticeRecord::teacherAssignedCount($teacherId, $declarationId) + $memberCount > $capacity) {
                        throw new InvalidArgumentException('确认后将超过指导教师容量');
                    }
                }
                $now = $this->now();
                if (SocialPracticeRecord::confirmDeclarationTeacher($declarationId, $teacherId, $status, $now) !== 1) {
                    throw new RuntimeException('教师确认状态已变化，请刷新后重试', 409);
                }
                $recordingId = $this->recordWorkflow('declaration', $declarationId, 'confirm_teacher', null, $status, $status === 'accepted' ? '指导教师确认' : '指导教师拒绝', null);
                return ['id' => $declarationId, 'recording_id' => $recordingId, 'status' => $status];
            });
        });
        return $result;
    }

    /** 重新选择分散实践指导教师。 */
    public function reselectTeacher(Request $request): array
    {
        $this->requirePermission('declare');
        $this->requireStudentRole();
        $declarationId = $this->requiredId($request, 'declaration_id');
        $teacherId = $this->requiredInt($request, 'teacher_id');
        $studentId = $this->currentStudentId(true);
        $declaration = SocialPracticeRecord::declarationRow($declarationId);
        if (!$declaration || (int) ($declaration['applicant_student_id'] ?? 0) !== $studentId) {
            throw new RuntimeException('申报不存在或无操作权限', 40400);
        }
        $plan = SocialPracticeRecord::planRow((int) ($declaration['plan_id'] ?? 0)) ?: [];
        if (!in_array((string) ($plan['teacher_match_mode'] ?? ''), ['student_choose', 'mixed'], true)) {
            throw new RuntimeException('当前计划由管理员分配指导教师', 409);
        }
        $expired = (string) ($declaration['teacher_confirm_status'] ?? '') === 'pending'
            && !empty($declaration['teacher_confirm_deadline_at'])
            && (string) $declaration['teacher_confirm_deadline_at'] < $this->now();
        if ((string) ($declaration['teacher_confirm_status'] ?? '') !== 'refused' && !$expired) {
            throw new RuntimeException('只有教师拒绝或确认超时后才能重新选择', 409);
        }
        if ((int) ($declaration['selected_teacher_id'] ?? 0) === $teacherId) {
            throw new InvalidArgumentException('请选择其他指导教师');
        }
        if (!SocialPracticeRecord::teacherOptionRow($this->scopeContext(), $teacherId)) {
            throw new RuntimeException('指导教师不存在或已停用', 40301);
        }
        $currentCount = (int) ($declaration['teacher_reselect_count'] ?? 0);
        $maxCount = (int) ($plan['max_reselect_count'] ?? 0);
        if ($currentCount >= $maxCount) {
            throw new RuntimeException('重新选择次数已用完，请联系管理员分配', 409);
        }
        $deadline = $this->dateAfterHours((int) ($plan['teacher_confirm_hours'] ?? 48));
        $result = $this->withLock('declaration', $declarationId, function () use ($declarationId, $teacherId, $currentCount, $deadline): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($declarationId, $teacherId, $currentCount, $deadline): array {
                $row = SocialPracticeRecord::lockEntity('declaration', $declarationId);
                $expired = $row && (string) ($row->teacher_confirm_status ?? '') === 'pending'
                    && !empty($row->teacher_confirm_deadline_at)
                    && (string) $row->teacher_confirm_deadline_at < $this->now();
                if (!$row || ((string) ($row->teacher_confirm_status ?? '') !== 'refused' && !$expired)) {
                    throw new RuntimeException('教师确认状态已变化，请刷新后重试', 409);
                }
                $now = $this->now();
                $nextCount = $currentCount + 1;
                if (SocialPracticeRecord::reselectDeclarationTeacher($declarationId, $teacherId, $nextCount, $deadline, $now) !== 1) {
                    throw new RuntimeException('重新选择失败，请刷新后重试', 409);
                }
                $recordingId = $this->recordWorkflow('declaration', $declarationId, 'reselect_teacher', (string) $row->teacher_confirm_status, 'pending', '学生重新选择指导教师', null);
                return ['id' => $declarationId, 'recording_id' => $recordingId, 'teacher_id' => $teacherId, 'reselect_count' => $nextCount, 'deadline_at' => $deadline];
            });
        });
        $this->notifyTeacherConfirmation($declarationId, $teacherId, $deadline);
        return $result;
    }

    /** 保存或提交社会实践材料。 */
    public function saveMaterial(Request $request): array
    {
        $this->requirePermission('material');
        $id = $this->optionalInt($request, 'id');
        $planId = $this->requiredInt($request, 'plan_id');
        $projectId = $this->optionalInt($request, 'project_id');
        $declarationId = $this->optionalInt($request, 'declaration_id');
        $studentId = $this->optionalInt($request, 'student_id');
        $materialType = $this->requiredString($request, 'material_type', 80);
        $submitScope = $this->enum($request, 'submit_scope', ['student', 'team', 'project'], 'student');
        if (CurrentContext::roleType() === 'student' && $submitScope === 'student') {
            $studentId = $this->currentStudentId(true);
        }
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $this->arrayInput($request, 'file_ids')), static fn (int $fileId): bool => $fileId > 0)));
        $this->assertMaterialTarget($planId, $projectId, $declarationId, $studentId, $submitScope);
        $existing = $id ? $this->visibleRow('material', $id) : SocialPracticeRecord::materialTargetRow($planId, $projectId, $declarationId, $studentId, $materialType);
        if ($existing) {
            $id = (int) $existing['id'];
            if (in_array((string) ($existing['status'] ?? ''), ['accept', 'wait'], true)) {
                throw new RuntimeException('当前材料不可直接修改，请先发起通过后修改', 409);
            }
        }
        $submit = $this->boolInput($request, 'submit', false);
        $now = $this->now();
        $values = [
            'plan_id' => $planId,
            'project_id' => $projectId,
            'declaration_id' => $declarationId,
            'student_id' => $studentId,
            'material_type' => $materialType,
            'submit_scope' => $submitScope,
            'version' => $existing ? (int) ($existing['version'] ?? 1) + ($submit && (string) ($existing['status'] ?? '') === 'modify' ? 1 : 0) : 1,
            'title' => $this->stringInput($request, 'title', 180),
            'content' => $this->stringInput($request, 'content', 500000),
            'status' => $submit ? 'wait' : 'draft',
            'submitted_at' => $submit ? $now : null,
            'updated_at' => $now,
        ];
        if ($submit) {
            $this->assertMaterialDeadline($planId, $materialType);
        }
        $fileService = new FileService();
        $fromStatus = (string) ($existing['status'] ?? 'draft');
        $lockId = max(1, (int) sprintf('%u', crc32(implode(':', [$planId, $projectId, $declarationId, $studentId, $materialType]))));
        $result = $this->withLock('material', $lockId, function () use ($id, $values, $fileIds, $fileService, $fromStatus, $submit): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($id, $values, $fileIds, $fileService, $fromStatus, $submit): array {
                if (!SocialPracticeRecord::lockEntity('plan', (int) $values['plan_id'])) {
                    throw new RuntimeException('计划已变更，请刷新后重试', 409);
                }
                $this->assertMaterialTarget($values['plan_id'], $values['project_id'], $values['declaration_id'], $values['student_id'], $values['submit_scope']);
                if (!$id) {
                    $existing = SocialPracticeRecord::materialTargetRow($values['plan_id'], $values['project_id'], $values['declaration_id'], $values['student_id'], $values['material_type']);
                    $id = $existing ? (int) $existing['id'] : null;
                }
                if ($id) {
                    $current = SocialPracticeRecord::lockEntity('material', $id);
                    if (!$current || !SocialPracticeRecord::entityVisible($this->scopeContext(), 'material', $id)) {
                        throw new RuntimeException('材料不存在或无权限', 40301);
                    }
                    if (!in_array((string) $current->status, ['draft', 'modify'], true)) {
                        throw new RuntimeException('材料已提交或审核，请刷新后重试', 409);
                    }
                    foreach (['plan_id', 'project_id', 'declaration_id', 'student_id', 'material_type', 'submit_scope'] as $field) {
                        if ((string) $current->$field !== (string) $values[$field]) {
                            throw new RuntimeException('不可修改已有材料的所属对象', 409);
                        }
                    }
                    $fromStatus = (string) $current->status;
                    $values['version'] = (int) $current->version + ($submit && $fromStatus === 'modify' ? 1 : 0);
                }
                $materialId = SocialPracticeRecord::saveEntity('material', $id, $values);
                $attachments = $fileService->replaceRelations($fileIds, SocialPracticeRecord::entityType('material'), $materialId, 'attachment');
                $recordingId = null;
                if ($submit) {
                    $recordingId = $this->recordWorkflow('material', $materialId, 'submit', $fromStatus, 'wait', $this->workflowContent('material', (object) $values), null);
                }
                return ['id' => $materialId, 'recording_id' => $recordingId, 'status' => $values['status'], 'attachments' => $attachments];
            });
        });
        if ($submit) {
            $this->notifySubmitted('material', (int) $result['id']);
        }
        return $result;
    }

    /** 保存或提交社会实践签到。 */
    public function saveSignIn(Request $request): array
    {
        $this->requirePermission('attendance');
        $this->requireStudentRole();
        $projectId = $this->requiredInt($request, 'project_id');
        $studentId = $this->currentStudentId(true);
        $participant = SocialPracticeRecord::activeParticipant($projectId, $studentId);
        if (!$participant) {
            throw new RuntimeException('当前学生不是项目参与人', 40301);
        }
        $project = SocialPracticeRecord::projectRow($projectId);
        $plan = $project ? SocialPracticeRecord::planRow((int) ($project['plan_id'] ?? 0)) : null;
        if (!$project || !$plan) {
            throw new RuntimeException('实践项目不存在', 40400);
        }
        $date = $this->stringInput($request, 'date', 10) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('签到日期格式不正确');
        }
        $this->assertDateInWindow($date, $project['start_at'] ?? $plan['practice_start_at'] ?? null, $project['end_at'] ?? $plan['practice_end_at'] ?? null, '签到日期不在实践时间范围内');
        $longitude = $this->requiredDecimal($request, 'longitude');
        $latitude = $this->requiredDecimal($request, 'latitude');
        if ($longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('GPS 坐标超出有效范围', 42201);
        }
        $location = $this->stringInput($request, 'location', 255);
        $remark = $this->stringInput($request, 'remark', 500);
        $lockId = abs(crc32('social_practice:sign:' . $projectId . ':' . $studentId . ':' . $date));
        return $this->withLock('attendance', $lockId, function () use ($projectId, $studentId, $participant, $date, $longitude, $latitude, $location, $remark): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($projectId, $studentId, $participant, $date, $longitude, $latitude, $location, $remark): array {
                if (SocialPracticeRecord::signInRow($projectId, $studentId, $date)) {
                    throw new RuntimeException('当天已经签到，请勿重复提交', 409);
                }
                $now = $this->now();
                $id = SocialPracticeRecord::insertSignIn([
                    'uuid' => $this->uuid(),
                    'entity_type' => 'social_practice',
                    'entity_id' => $projectId,
                    'student_id' => $studentId,
                    'teacher_id' => $participant['teacher_id'] ?? null,
                    'date' => $date,
                    'sign_time' => $now,
                    'longitude' => $longitude,
                    'latitude' => $latitude,
                    'location' => $location,
                    'sign_type' => 'gps',
                    'remark' => $remark,
                    'status' => 'enabled',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
                $recordingId = SocialPracticeRecord::insertActivityRecording('social_practice_attendance', $id, $this->accountId(), 'sign_in', '完成社会实践 GPS 签到', $now);
                return ['id' => $id, 'recording_id' => $recordingId, 'status' => 'enabled', 'date' => $date, 'sign_time' => $now];
            });
        });
    }

    /** 保存或提交社会实践补签申请。 */
    public function savePatchSign(Request $request): array
    {
        $this->requirePermission('attendance');
        $this->requireStudentRole();
        $studentId = $this->currentStudentId(true);
        $projectId = $this->requiredInt($request, 'project_id');
        $project = $this->visibleRow('project', $projectId);
        $participant = SocialPracticeRecord::activeParticipant($projectId, $studentId);
        if (!$participant) {
            throw new RuntimeException('当前学生不是项目参与人', 40301);
        }
        $projectRow = SocialPracticeRecord::projectRow($projectId);
        $planRow = $projectRow ? SocialPracticeRecord::planRow((int) ($projectRow['plan_id'] ?? 0)) : null;
        if (!$projectRow || !$planRow) {
            throw new RuntimeException('实践项目不存在', 40400);
        }
        $id = $this->optionalInt($request, 'id');
        $existing = $id ? $this->visibleRow('patch_sign', $id) : null;
        if ($existing && (int) ($existing['student_id'] ?? 0) !== $studentId) {
            throw new RuntimeException('无权修改该补签申请', 40301);
        }
        if ($existing && in_array((string) ($existing['status'] ?? ''), ['wait', 'accept'], true)) {
            throw new RuntimeException('当前补签申请不可直接修改', 409);
        }
        $submit = $this->boolInput($request, 'submit', false);
        $now = $this->now();
        $values = [
            'plan_id' => (int) $project['plan_id'],
            'project_id' => $projectId,
            'student_id' => $studentId,
            'sign_date' => $this->requiredString($request, 'sign_date', 10),
            'reason' => $this->requiredString($request, 'reason', 10000),
            'proof' => $this->stringInput($request, 'proof', 100000),
            'submitted_at' => $submit ? $now : null,
            'status' => $submit ? 'wait' : 'draft',
            'updated_at' => $now,
        ];
        $this->assertDateInWindow($values['sign_date'], $projectRow['start_at'] ?? $planRow['practice_start_at'] ?? null, $projectRow['end_at'] ?? $planRow['practice_end_at'] ?? null, '补签日期不在实践时间范围内');
        if ($submit && !empty($planRow['result_deadline_at']) && $this->now() > (string) $planRow['result_deadline_at']) {
            throw new RuntimeException('补签申请已超过成果截止时间', 409);
        }
        $result = $this->withLock('patch_sign', $id ?: $projectId, function () use ($id, $values, $submit): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($id, $values, $submit): array {
                $patchId = SocialPracticeRecord::saveEntity('patch_sign', $id, $values);
                $recordingId = $submit ? $this->recordWorkflow('patch_sign', $patchId, 'submit', 'draft', 'wait', '提交社会实践补签申请', null) : null;
                return ['id' => $patchId, 'recording_id' => $recordingId, 'status' => $values['status']];
            });
        });
        if ($submit) {
            $this->notifySubmitted('patch_sign', (int) $result['id']);
        }
        return $result;
    }

    /** 保存或提交社会实践成绩。 */
    public function saveScore(Request $request): array
    {
        $this->requirePermission('score');
        $projectId = $this->requiredInt($request, 'project_id');
        $studentId = $this->requiredInt($request, 'student_id');
        $project = $this->visibleRow('project', $projectId);
        $participant = SocialPracticeRecord::activeParticipant($projectId, $studentId);
        if (!$participant) {
            throw new RuntimeException('学生不是当前项目参与人', 42201);
        }
        $teacherId = CurrentContext::roleType() === 'teacher' ? $this->currentTeacherId(true) : $this->optionalInt($request, 'teacher_id');
        if (!$teacherId) {
            $teacherId = (int) ($participant['teacher_id'] ?? 0);
        }
        if (CurrentContext::roleType() === 'teacher' && (int) ($participant['teacher_id'] ?? 0) !== $teacherId) {
            throw new RuntimeException('只能评阅本人指导的学生', 40301);
        }
        $items = $this->arrayInput($request, 'items');
        $submit = $this->boolInput($request, 'submit', false);
        $plan = SocialPracticeRecord::planRow((int) $project['plan_id']) ?: [];
        if ($submit && !empty($plan['practice_end_at']) && $this->now() < (string) $plan['practice_end_at']) {
            throw new RuntimeException('实践尚未结束，暂不能提交成绩', 409);
        }
        if ($submit && !empty($plan['score_deadline_at']) && $this->now() > (string) $plan['score_deadline_at']) {
            throw new RuntimeException('成绩提交已超过截止时间', 409);
        }
        if ($submit) {
            $missing = SocialPracticeRecord::missingRequiredMaterials(
                (int) $project['plan_id'],
                $projectId,
                $studentId,
                (string) $project['practice_mode'],
                true
            );
            if ($missing) {
                throw new RuntimeException('必交材料尚未全部审核通过：' . implode('、', array_map(fn (string $type): string => self::MATERIAL_TITLES[$type] ?? $type, $missing)), 42201);
            }
        }
        $rules = SocialPracticeRecord::scoreRuleRows((int) $project['plan_id'], (string) $project['practice_mode']);
        $ruleMap = [];
        foreach ($rules as $rule) {
            $ruleMap[(string) $rule['item_code']] = $rule;
        }
        if (!$ruleMap) {
            throw new RuntimeException('当前计划尚未配置成绩规则', 42201);
        }
        $details = [];
        $finalScore = 0.0;
        foreach ($items as $item) {
            $code = (string) ($item['item_code'] ?? '');
            if (!isset($ruleMap[$code])) {
                throw new InvalidArgumentException('成绩项不属于当前计划');
            }
            $scoreValue = (float) ($item['score_value'] ?? 0);
            $maxScore = (float) ($ruleMap[$code]['max_score'] ?? 100);
            if ($scoreValue < 0 || $scoreValue > $maxScore) {
                throw new InvalidArgumentException('成绩超出允许范围');
            }
            $weight = (float) $ruleMap[$code]['weight'];
            $weighted = round($scoreValue / $maxScore * $weight, 2);
            $finalScore += $weighted;
            $details[] = [
                'rule_id' => (int) $ruleMap[$code]['id'],
                'item_code' => $code,
                'score_value' => $scoreValue,
                'weight' => $weight,
                'weighted_score' => $weighted,
            ];
        }
        if (count($details) !== count($ruleMap)) {
            throw new InvalidArgumentException('请完整填写成绩项');
        }
        $existing = SocialPracticeRecord::scoreTargetRow((int) $project['plan_id'], $projectId, $studentId);
        if ($existing && in_array((string) ($existing['status'] ?? ''), ['accept', 'wait'], true)) {
            throw new RuntimeException('当前成绩不可直接修改，请先发起通过后修改', 409);
        }
        $now = $this->now();
        $comment = $this->stringInput($request, 'comment', 10000);
        $result = $this->withLock('score', max(1, (int) sprintf('%u', crc32($projectId . ':' . $studentId))), function () use ($project, $projectId, $studentId, $teacherId, $finalScore, $details, $comment, $submit, $now): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($project, $projectId, $studentId, $teacherId, $finalScore, $details, $comment, $submit, $now): array {
                if (!SocialPracticeRecord::lockEntity('project', $projectId)) {
                    throw new RuntimeException('项目已变更，请刷新后重试', 409);
                }
                $fromStatus = 'draft';
                $existing = SocialPracticeRecord::scoreTargetRow((int) $project['plan_id'], $projectId, $studentId);
                if ($existing) {
                    $current = SocialPracticeRecord::lockEntity('score', (int) $existing['id']);
                    if (!$current || !SocialPracticeRecord::entityVisible($this->scopeContext(), 'score', (int) $existing['id'])) {
                        throw new RuntimeException('成绩不存在或无权限', 40301);
                    }
                    if (!in_array((string) $current->status, ['draft', 'modify'], true)) {
                        throw new RuntimeException('成绩已提交或审核，请刷新后重试', 409);
                    }
                    $fromStatus = (string) $current->status;
                }
                $scoreId = SocialPracticeRecord::saveEntity('score', $existing ? (int) $existing['id'] : null, [
                    'plan_id' => (int) $project['plan_id'],
                    'project_id' => $projectId,
                    'student_id' => $studentId,
                    'teacher_id' => $teacherId,
                    'final_score' => $finalScore,
                    'comment' => $comment,
                    'status' => $submit ? 'wait' : 'draft',
                    'updated_at' => $now,
                ]);
                SocialPracticeRecord::syncScoreDetails($scoreId, $details, $now);
                $recordingId = null;
                if ($submit) {
                    $recordingId = $this->recordWorkflow('score', $scoreId, 'submit', $fromStatus, 'wait', '提交社会实践成绩', null);
                }
                return ['id' => $scoreId, 'recording_id' => $recordingId, 'status' => $submit ? 'wait' : 'draft', 'final_score' => $finalScore];
            });
        });
        if ($submit) {
            $this->notifySubmitted('score', (int) $result['id']);
        }
        return $result;
    }

    /** 保存审核意见草稿。 */
    public function saveReviewDraft(Request $request): array
    {
        $resource = $this->workflowResource($request);
        $id = $this->requiredId($request);
        $this->requireReviewPermission($resource);
        $this->assertVisible($resource, $id, $this->scopeContext());
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinion($request, $resource, $status);
        $draftId = SocialPracticeRecord::saveReviewOpinionDraft([
            'entity_type' => SocialPracticeRecord::entityType($resource),
            'entity_id' => $id,
            'reviewer_id' => $this->accountId(),
            'review_status' => $status,
            'opinion' => $opinion,
            'score' => $this->optionalDecimal($request, 'score'),
            'updated_at' => $this->now(),
        ]);
        return ['id' => $draftId, 'entity_type' => SocialPracticeRecord::entityType($resource), 'entity_id' => $id, 'status' => $status];
    }

    /** 读取审核意见草稿。 */
    public function reviewDraft(Request $request): array
    {
        $resource = $this->workflowResource($request);
        $id = $this->requiredId($request);
        $this->requireReviewPermission($resource);
        $this->assertVisible($resource, $id, $this->scopeContext());
        return ['entity_type' => SocialPracticeRecord::entityType($resource), 'entity_id' => $id, 'draft' => SocialPracticeRecord::reviewOpinionDraftRow(SocialPracticeRecord::entityType($resource), $id, $this->accountId())];
    }

    /** 归档社会实践记录。 */
    public function archive(Request $request): array
    {
        $this->requirePermission('archive');
        $this->requireAdminRole();
        $planId = $this->requiredInt($request, 'plan_id');
        $projectId = $this->optionalInt($request, 'project_id');
        $studentId = $this->optionalInt($request, 'student_id');
        $scope = $this->scopeContext();
        $this->assertVisible('plan', $planId, $scope);
        if ($projectId) {
            $this->assertVisible('project', $projectId, $scope);
        }
        if (!$studentId) {
            throw new InvalidArgumentException('归档必须指定学生，保证材料和成绩快照完整');
        }
        $participant = $projectId ? SocialPracticeRecord::activeParticipant($projectId, $studentId) : null;
        if ($projectId && !$participant) {
            throw new InvalidArgumentException('学生不是当前项目参与人');
        }
        $teacherId = $participant ? (int) ($participant['teacher_id'] ?? 0) : null;
        $project = $projectId ? SocialPracticeRecord::projectRow($projectId) : null;
        $mode = (string) ($project['practice_mode'] ?? 'centralized');
        $missing = $projectId ? SocialPracticeRecord::missingRequiredMaterials($planId, $projectId, $studentId, $mode, true) : [];
        if ($missing) {
            throw new InvalidArgumentException('尚有未通过的必交材料：' . implode('、', array_map(fn (string $type): string => self::MATERIAL_TITLES[$type] ?? $type, $missing)));
        }
        $score = $projectId ? SocialPracticeRecord::scoreTargetRow($planId, $projectId, $studentId) : null;
        if (!$score || (string) ($score['status'] ?? '') !== 'accept') {
            throw new InvalidArgumentException('成绩尚未审核通过，不能归档');
        }
        $archiveKey = $projectId . ':' . $studentId;
        return $this->withLock('archive', abs(crc32($archiveKey)), function () use ($planId, $projectId, $studentId, $teacherId, $scope): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($planId, $projectId, $studentId, $teacherId, $scope): array {
                $now = $this->now();
                $version = SocialPracticeRecord::nextArchiveVersion($planId, $projectId, $studentId);
                $snapshot = SocialPracticeRecord::archiveSnapshot($scope, $planId, $projectId, $studentId);
                $archiveId = SocialPracticeRecord::saveEntity('archive', null, [
                    'plan_id' => $planId,
                    'project_id' => $projectId,
                    'student_id' => $studentId,
                    'teacher_id' => $teacherId ?: null,
                    'version' => $version,
                    'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'archived_by' => $this->accountId(),
                    'archived_at' => $now,
                    'status' => 'archived',
                    'updated_at' => $now,
                ]);
                $recordingId = $this->recordWorkflow('archive', $archiveId, 'archive', null, 'archived', '完成社会实践归档', null);
                return ['id' => $archiveId, 'recording_id' => $recordingId, 'version' => $version, 'status' => 'archived'];
            });
        });
    }

    /** 保存社会实践计划。 */
    private function savePlan(Request $request): array
    {
        $this->requirePermission('plan');
        $this->requireAdminRole();
        $id = $this->optionalInt($request, 'id');
        $scope = $this->scopeContext();
        $existing = $id ? $this->visibleRow('plan', $id) : null;
        if ($id && !$existing) {
            throw new RuntimeException('计划不存在或无访问权限', 40400);
        }
        if ($existing && !in_array((string) $existing['status'], ['draft', 'modify'], true)) {
            throw new RuntimeException('当前计划不可直接修改，请先发起通过后修改', 409);
        }
        $gradeId = $this->requiredInt($request, 'grade_id');
        $title = $this->requiredString($request, 'title', 180);
        $scopes = $this->normalizeScopes($this->arrayInput($request, 'scopes'));
        $requirements = $this->normalizeRequirements($this->arrayInput($request, 'requirements'));
        $scoreRules = $this->normalizeScoreRules($this->arrayInput($request, 'score_rules'));
        $this->validateScoreRules($scoreRules);
        $organizerDepId = $this->optionalInt($request, 'organizer_dep_id');
        $approvalFlowId = $this->optionalInt($request, 'approval_flow_id');
        $this->assertPlanRelations($scope, $gradeId, $organizerDepId, $scopes, $approvalFlowId);
        $dateValues = [
            'register_start_at' => $this->optionalDateTime($request, 'register_start_at'),
            'register_end_at' => $this->optionalDateTime($request, 'register_end_at'),
            'practice_start_at' => $this->optionalDateTime($request, 'practice_start_at'),
            'practice_end_at' => $this->optionalDateTime($request, 'practice_end_at'),
            'result_deadline_at' => $this->optionalDateTime($request, 'result_deadline_at'),
            'score_deadline_at' => $this->optionalDateTime($request, 'score_deadline_at'),
        ];
        $this->assertPlanDates($dateValues, false);
        $values = [
            'source_type' => $this->enum($request, 'source_type', ['manual', 'edu_system', 'excel'], 'manual'),
            'source_key' => $this->stringInput($request, 'source_key', 180) ?: null,
            'code' => $this->stringInput($request, 'plan_code', 120) ?: $this->stringInput($request, 'code', 120),
            'title' => $title,
            'description' => $this->stringInput($request, 'description', 100000),
            'grade_id' => $gradeId,
            'organizer_dep_id' => $organizerDepId,
            'credit' => $this->optionalDecimal($request, 'credit') ?? 0,
            'participation_mode' => $this->enum($request, 'participation_mode', ['mandatory', 'voluntary'], 'mandatory'),
            'teacher_match_mode' => $this->enum($request, 'teacher_match_mode', ['student_choose', 'admin_assign', 'mixed'], 'mixed'),
            'teacher_confirm_hours' => max(1, (int) ($this->optionalInt($request, 'teacher_confirm_hours') ?? 48)),
            'max_reselect_count' => max(0, (int) ($this->optionalInt($request, 'max_reselect_count') ?? 2)),
            'default_team_submit_mode' => $this->enum($request, 'default_team_submit_mode', ['individual', 'shared'], 'individual'),
            ...$dateValues,
            'approval_flow_id' => $approvalFlowId,
            'phase' => $existing['phase'] ?? 'ready',
            'submitter_id' => $this->accountId(),
            'status' => 'draft',
            'updated_at' => $this->now(),
        ];
        $result = $this->withLock('plan', $id ?: $gradeId, function () use ($id, $values, $scopes, $requirements, $scoreRules): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($id, $values, $scopes, $requirements, $scoreRules): array {
                $planId = SocialPracticeRecord::saveEntity('plan', $id, $values);
                SocialPracticeRecord::syncPlanScopes($planId, $scopes, $this->now());
                SocialPracticeRecord::syncRequirements($planId, $requirements, $this->now());
                SocialPracticeRecord::syncScoreRules($planId, $scoreRules, $this->now());
                return ['id' => $planId, 'status' => 'draft'];
            });
        });
        return $result;
    }

    /** 保存集中实践项目。 */
    private function saveProject(Request $request): array
    {
        $this->requirePermission('project');
        $this->requireAdminRole();
        $id = $this->optionalInt($request, 'id');
        $existing = $id ? $this->visibleRow('project', $id) : null;
        $planId = $this->requiredInt($request, 'plan_id');
        $plan = SocialPracticeRecord::planRow($planId);
        if (!$plan || !SocialPracticeRecord::entityVisible($this->scopeContext(), 'plan', $planId)) {
            throw new RuntimeException('计划不存在或无访问权限', 40301);
        }
        if ((string) ($plan['status'] ?? '') !== 'accept' || empty($plan['published_at'])) {
            throw new RuntimeException('计划审核通过并发布后才能创建实践项目', 409);
        }
        $mode = $this->enum($request, 'practice_mode', ['centralized'], 'centralized');
        $startAt = $this->optionalDateTime($request, 'start_at');
        $endAt = $this->optionalDateTime($request, 'end_at');
        $this->assertDateOrder($startAt, $endAt, '项目开始时间必须早于结束时间');
        $this->assertWithinWindow($startAt, $plan['practice_start_at'] ?? null, $plan['practice_end_at'] ?? null, '项目开始时间必须在计划实践时间内');
        $this->assertWithinWindow($endAt, $plan['practice_start_at'] ?? null, $plan['practice_end_at'] ?? null, '项目结束时间必须在计划实践时间内');
        $values = [
            'plan_id' => $planId,
            'practice_mode' => $mode,
            'source_type' => 'admin_created',
            'project_code' => $this->stringInput($request, 'project_code', 120) ?: 'SP-C-' . date('YmdHis') . '-' . random_int(100, 999),
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->stringInput($request, 'content', 100000),
            'objective' => $this->stringInput($request, 'objective', 10000),
            'location' => $this->stringInput($request, 'location', 255),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'capacity' => max(0, (int) ($this->optionalInt($request, 'capacity') ?? 0)),
            'phase' => $existing['phase'] ?? 'ready',
            'created_by' => $this->accountId(),
            'status' => 'enabled',
            'updated_at' => $this->now(),
        ];
        return $this->withLock('project', $id ?: $planId, fn (): array => SocialPracticeRecord::connection()->transaction(fn (): array => ['id' => SocialPracticeRecord::saveEntity('project', $id, $values), 'status' => 'enabled']));
    }

    /** 保存集中实践实施申请。 */
    private function saveImplementation(Request $request): array
    {
        $this->requirePermission('project');
        $projectId = $this->requiredInt($request, 'project_id');
        $project = $this->visibleRow('project', $projectId);
        $this->assertProjectOperator($projectId, $project);
        $id = $this->optionalInt($request, 'id');
        $existing = $id ? $this->visibleRow('implementation', $id) : null;
        if ($existing && !in_array((string) $existing['status'], ['draft', 'modify'], true)) {
            throw new RuntimeException('当前实施申请不可直接修改', 409);
        }
        $plan = SocialPracticeRecord::planRow((int) $project['plan_id']) ?: [];
        $values = [
            'project_id' => $projectId,
            'applicant_id' => $this->accountId(),
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->stringInput($request, 'content', 100000),
            'budget_amount' => $this->optionalDecimal($request, 'budget_amount') ?? 0,
            'material_requirement' => $this->stringInput($request, 'material_requirement', 10000),
            'venue_requirement' => $this->stringInput($request, 'venue_requirement', 10000),
            'approval_flow_id' => (int) ($project['approval_flow_id'] ?? ($plan['approval_flow_id'] ?? 0)),
            'status' => 'draft',
            'updated_at' => $this->now(),
        ];
        return $this->withLock('implementation', $id ?: $projectId, fn (): array => SocialPracticeRecord::connection()->transaction(fn (): array => ['id' => SocialPracticeRecord::saveEntity('implementation', $id, $values), 'status' => 'draft']));
    }

    /** 保存分散实践申报。 */
    private function saveDeclaration(Request $request): array
    {
        $this->requirePermission('declare');
        $this->requireStudentRole();
        $studentId = $this->currentStudentId(true);
        $planId = $this->requiredInt($request, 'plan_id');
        $plan = SocialPracticeRecord::planRow($planId);
        if (!$plan || !SocialPracticeRecord::entityVisible($this->scopeContext(), 'plan', $planId)) {
            throw new RuntimeException('计划不存在或当前学生不可参与', 40301);
        }
        $id = $this->optionalInt($request, 'id');
        $existing = $id ? $this->visibleRow('declaration', $id) : null;
        if ($existing && (int) ($existing['applicant_student_id'] ?? 0) !== $studentId) {
            throw new RuntimeException('无权修改该申报', 40301);
        }
        if ($existing && !in_array((string) $existing['status'], ['draft', 'modify'], true)) {
            throw new RuntimeException('当前申报不可直接修改', 409);
        }
        if (!$existing) {
            $this->assertRegistrationOpen($plan);
        }
        $type = $this->enum($request, 'declaration_type', ['individual', 'team'], 'individual');
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $this->arrayInput($request, 'member_student_ids')))));
        $memberIds[] = $studentId;
        $memberIds = array_values(array_unique($memberIds));
        $eligibleMemberIds = SocialPracticeRecord::eligibleStudentIdsForPlan($planId, $memberIds);
        if (array_diff($memberIds, $eligibleMemberIds)) {
            throw new InvalidArgumentException('存在不属于当前计划年级或适用范围的团队成员');
        }
        foreach ($memberIds as $memberId) {
            if (SocialPracticeRecord::studentPlanConflict($planId, $memberId, $id)) {
                throw new InvalidArgumentException('团队成员已参加当前计划其他申报或项目');
            }
        }
        $selectedTeacherId = $this->optionalInt($request, 'selected_teacher_id');
        $teacherMatchMode = (string) ($plan['teacher_match_mode'] ?? 'mixed');
        if ($teacherMatchMode === 'admin_assign' && $selectedTeacherId) {
            throw new InvalidArgumentException('当前计划由管理员分配指导教师');
        }
        if ($selectedTeacherId && !SocialPracticeRecord::teacherOptionRow($this->scopeContext(), $selectedTeacherId)) {
            throw new InvalidArgumentException('所选指导教师不存在或已停用');
        }
        if ($type === 'team' && !$memberIds) {
            throw new InvalidArgumentException('团队申报至少需要一名成员');
        }
        $values = [
            'plan_id' => $planId,
            'applicant_student_id' => $studentId,
            'declaration_type' => $type,
            'team_submit_mode' => $this->enum($request, 'team_submit_mode', ['individual', 'shared'], (string) ($plan['default_team_submit_mode'] ?? 'individual')),
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->stringInput($request, 'content', 100000),
            'objective' => $this->stringInput($request, 'objective', 10000),
            'expected_duration' => $this->stringInput($request, 'expected_duration', 120),
            'expected_result' => $this->stringInput($request, 'expected_result', 10000),
            'location' => $this->stringInput($request, 'location', 255),
            'selected_teacher_id' => $selectedTeacherId,
            'assigned_teacher_id' => CurrentContext::roleType() === 'student' ? null : $this->optionalInt($request, 'assigned_teacher_id'),
            'teacher_confirm_deadline_at' => $this->dateAfterHours((int) ($plan['teacher_confirm_hours'] ?? 48)),
            'teacher_confirm_status' => 'pending',
            'status' => 'draft',
            'updated_at' => $this->now(),
        ];
        return $this->withLock('declaration', $id ?: $planId, function () use ($id, $values, $memberIds, $studentId): array {
            return SocialPracticeRecord::connection()->transaction(function () use ($id, $values, $memberIds, $studentId): array {
                $declarationId = SocialPracticeRecord::saveEntity('declaration', $id, $values);
                SocialPracticeRecord::syncDeclarationMembers($declarationId, $studentId, $memberIds, $this->now());
                return ['id' => $declarationId, 'status' => 'draft'];
            });
        });
    }

    /** 处理审批节点推进。 */
    private function advanceApprovalNode(string $resource, object $row, array $scope, string $now, string $opinion): array
    {
        if (!in_array($resource, ['plan', 'implementation'], true)) {
            return ['accept', null];
        }
        $flowId = (int) ($row->approval_flow_id ?? 0);
        $nodes = SocialPracticeRecord::approvalNodeRows($flowId);
        $currentId = (int) ($row->current_node_id ?? 0);
        $currentIndex = -1;
        foreach ($nodes as $index => $node) {
            if ((int) $node['id'] === $currentId) {
                $currentIndex = $index;
                break;
            }
        }
        for ($index = $currentIndex + 1; $index < count($nodes); $index++) {
            $node = $nodes[$index];
            if (($node['auto_pass'] ?? 'false') === 'true') {
                $this->recordWorkflow($resource, (int) $row->id, 'auto_filing', 'wait', 'wait', (string) ($node['node_name'] ?? '自动备案'), 'accept');
                continue;
            }
            return ['wait', (int) $node['id']];
        }
        return ['accept', null];
    }

    /** 校验当前审批节点。 */
    private function assertReviewNode(string $resource, object $row, array $scope): void
    {
        if (!in_array($resource, ['plan', 'implementation'], true)) {
            return;
        }
        $flowId = (int) ($row->approval_flow_id ?? 0);
        $nodeId = (int) ($row->current_node_id ?? 0);
        $node = null;
        foreach (SocialPracticeRecord::approvalNodeRows($flowId) as $item) {
            if ((int) $item['id'] === $nodeId) {
                $node = $item;
                break;
            }
        }
        if (!$node) {
            throw new RuntimeException('当前审批节点不存在，请重新提交', 409);
        }
        $this->assertNodeRole((string) ($node['scope_type'] ?? 'school'), $scope);
        if (!in_array((string) ($node['permission_code'] ?? ''), CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无当前审批节点权限', 40300);
        }
    }

    /** 校验节点组织范围。 */
    private function assertNodeRole(string $scopeType, array $scope): void
    {
        $role = (string) ($scope['role_type'] ?? '');
        $allowed = match ($scopeType) {
            'school' => ['super_admin', 'school_admin'],
            'college' => ['super_admin', 'school_admin', 'college_admin'],
            'profession' => ['super_admin', 'school_admin', 'college_admin', 'profession_admin'],
            default => ['super_admin', 'school_admin'],
        };
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('当前角色不能处理该审批节点', 40300);
        }
    }

    /** 校验分散实践提交条件。 */
    private function assertDeclarationReady(object $row): void
    {
        if ((string) ($row->status ?? '') === 'draft') {
            $plan = SocialPracticeRecord::planRow((int) ($row->plan_id ?? 0));
            $this->assertRegistrationOpen($plan ?: []);
        }
        if ((string) ($row->declaration_type ?? '') === 'team' && !SocialPracticeRecord::declarationMembersConfirmed((int) $row->id)) {
            throw new RuntimeException('团队成员尚未全部确认', 42201);
        }
        if (!(int) ($row->selected_teacher_id ?? 0) && !(int) ($row->assigned_teacher_id ?? 0)) {
            throw new RuntimeException('请先选择或分配指导教师', 42201);
        }
    }

    /** 校验计划提交前的完整配置。 */
    private function assertPlanReadyForSubmission(object $row, array $scope): void
    {
        $gradeId = (int) ($row->grade_id ?? 0);
        $scopes = SocialPracticeRecord::planScopeRows((int) $row->id);
        $this->assertPlanRelations($scope, $gradeId, (int) ($row->organizer_dep_id ?? 0) ?: null, $scopes, (int) ($row->approval_flow_id ?? 0) ?: null);
        $this->assertPlanDates([
            'register_start_at' => $row->register_start_at ?? null,
            'register_end_at' => $row->register_end_at ?? null,
            'practice_start_at' => $row->practice_start_at ?? null,
            'practice_end_at' => $row->practice_end_at ?? null,
            'result_deadline_at' => $row->result_deadline_at ?? null,
            'score_deadline_at' => $row->score_deadline_at ?? null,
        ], true);
        if (SocialPracticeRecord::planRequirementCount((int) $row->id) <= 0) {
            throw new InvalidArgumentException('请至少配置一项计划要求', 42201);
        }
        $rules = SocialPracticeRecord::scoreRuleRows((int) $row->id);
        foreach (['centralized', 'distributed'] as $mode) {
            $modeRules = array_values(array_filter($rules, static fn (array $rule): bool => (string) ($rule['practice_mode'] ?? '') === $mode));
            if (!$modeRules) {
                throw new InvalidArgumentException($mode . ' 尚未配置成绩规则', 42201);
            }
            $weight = array_sum(array_map(static fn (array $rule): float => (float) ($rule['weight'] ?? 0), $modeRules));
            if (abs($weight - 100) > 0.01) {
                throw new InvalidArgumentException($mode . ' 成绩权重必须合计 100%', 42201);
            }
        }
    }

    /** 校验计划组织层级和审批流。 */
    private function assertPlanRelations(array $scope, int $gradeId, ?int $organizerDepId, array $scopes, ?int $approvalFlowId): void
    {
        if (!SocialPracticeRecord::gradeOptionRow($gradeId)) {
            throw new InvalidArgumentException('年级不存在或未启用', 42201);
        }
        $role = (string) ($scope['role_type'] ?? '');
        if ($organizerDepId && !SocialPracticeRecord::departmentOptionRow($organizerDepId)) {
            throw new InvalidArgumentException('组织学院不存在或未启用', 42201);
        }
        if ($role === 'college_admin' && (!$organizerDepId || !in_array($organizerDepId, $scope['dep_ids'] ?? [], true))) {
            throw new RuntimeException('学院管理员只能维护授权学院的计划', 40301);
        }
        if ($role === 'profession_admin' && (!$organizerDepId || !in_array($organizerDepId, $scope['profession_dep_ids'] ?? [], true))) {
            throw new RuntimeException('专业管理员只能维护授权专业所属学院的计划', 40301);
        }
        $keys = [];
        foreach ($scopes as $item) {
            $type = (string) ($item['scope_type'] ?? '');
            $depId = (int) ($item['dep_id'] ?? 0);
            $professionId = (int) ($item['profession_id'] ?? 0);
            $classId = (int) ($item['class_id'] ?? 0);
            if (!in_array($type, ['school', 'college', 'profession', 'class'], true)) {
                throw new InvalidArgumentException('计划范围类型无效', 42201);
            }
            if ($type === 'school' && ($depId || $professionId || $classId)) {
                throw new InvalidArgumentException('全校范围不能同时指定学院、专业或班级', 42201);
            }
            if ($type === 'college' && (!$depId || $professionId || $classId)) {
                throw new InvalidArgumentException('学院范围必须只选择一个学院', 42201);
            }
            if ($type === 'profession' && (!$depId || !$professionId || $classId)) {
                throw new InvalidArgumentException('专业范围必须选择学院和专业', 42201);
            }
            if ($type === 'class' && (!$depId || !$professionId || !$classId)) {
                throw new InvalidArgumentException('班级范围必须选择学院、专业和班级', 42201);
            }
            if ($depId && !SocialPracticeRecord::departmentOptionRow($depId)) {
                throw new InvalidArgumentException('计划范围学院不存在或未启用', 42201);
            }
            $profession = $professionId ? SocialPracticeRecord::professionOptionRow($professionId) : null;
            if ($professionId && (!$profession || (int) ($profession['dep_id'] ?? 0) !== $depId || (!empty($profession['grade_id']) && (int) $profession['grade_id'] !== $gradeId))) {
                throw new InvalidArgumentException('计划范围专业与年级或学院不匹配', 42201);
            }
            $class = $classId ? SocialPracticeRecord::classOptionRow($classId) : null;
            if ($classId && (!$class || (int) ($class['dep_id'] ?? 0) !== $depId || (int) ($class['profession_id'] ?? 0) !== $professionId || (!empty($class['grade_id']) && (int) $class['grade_id'] !== $gradeId))) {
                throw new InvalidArgumentException('计划范围班级与年级、学院或专业不匹配', 42201);
            }
            if ($role === 'college_admin' && ($type === 'school' || !in_array($depId, $scope['dep_ids'] ?? [], true))) {
                throw new RuntimeException('学院管理员不能扩大到学校或其他学院范围', 40301);
            }
            if ($role === 'profession_admin' && ($type !== 'profession' && $type !== 'class' || !in_array($professionId, $scope['profession_ids'] ?? [], true))) {
                throw new RuntimeException('专业管理员只能维护授权专业或其班级范围', 40301);
            }
            $key = implode(':', [$type, $depId, $professionId, $classId]);
            if (isset($keys[$key])) {
                throw new InvalidArgumentException('计划适用范围不能重复', 42201);
            }
            $keys[$key] = true;
        }
        if (!$scopes) {
            throw new InvalidArgumentException('请至少配置一项计划适用范围', 42201);
        }
        if (!$approvalFlowId || !SocialPracticeRecord::approvalFlowOptionRow($scope, $approvalFlowId)) {
            throw new InvalidArgumentException('审批流程不存在、未启用或无权使用', 42201);
        }
    }

    /** 校验计划时间窗口。 */
    private function assertPlanDates(array $dates, bool $complete): void
    {
        $ordered = ['register_start_at', 'register_end_at', 'practice_start_at', 'practice_end_at', 'result_deadline_at', 'score_deadline_at'];
        if ($complete) {
            foreach ($ordered as $field) {
                if (empty($dates[$field])) {
                    throw new InvalidArgumentException('计划时间窗口不完整：' . $field, 42201);
                }
            }
        }
        $previous = null;
        foreach ($ordered as $field) {
            $value = $dates[$field] ?? null;
            if (!$value) {
                continue;
            }
            $timestamp = strtotime((string) $value);
            if ($timestamp === false) {
                throw new InvalidArgumentException($field . ' 日期格式不正确', 42201);
            }
            if ($previous !== null && $timestamp < $previous) {
                throw new InvalidArgumentException('计划时间窗口必须按报名、实践、成果、成绩顺序递进', 42201);
            }
            $previous = $timestamp;
        }
    }

    /** 校验项目时间顺序。 */
    private function assertDateOrder(?string $start, ?string $end, string $message): void
    {
        if ($start && $end && strtotime($start) >= strtotime($end)) {
            throw new InvalidArgumentException($message, 42201);
        }
    }

    /** 校验时间是否位于给定窗口。 */
    private function assertWithinWindow(?string $value, ?string $start, ?string $end, string $message): void
    {
        if (!$value) {
            return;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false || ($start && $timestamp < strtotime($start)) || ($end && $timestamp > strtotime($end))) {
            throw new InvalidArgumentException($message, 42201);
        }
    }

    /** 校验日期是否位于给定窗口。 */
    private function assertDateInWindow(string $date, ?string $start, ?string $end, string $message): void
    {
        $timestamp = strtotime($date . ' 00:00:00');
        if ($timestamp === false || ($start && $timestamp < strtotime(date('Y-m-d', strtotime($start)) . ' 00:00:00')) || ($end && $timestamp > strtotime(date('Y-m-d', strtotime($end)) . ' 00:00:00'))) {
            throw new InvalidArgumentException($message, 42201);
        }
    }

    /** 校验材料提交截止时间。 */
    private function assertMaterialDeadline(int $planId, string $materialType): void
    {
        $plan = SocialPracticeRecord::planRow($planId) ?: [];
        if (in_array($materialType, ['safety_agreement', 'insurance', 'emergency_plan'], true) && !empty($plan['practice_start_at']) && $this->now() > (string) $plan['practice_start_at']) {
            throw new RuntimeException('安全材料提交已超过实践开始时间', 409);
        }
        if (in_array($materialType, ['practice_report', 'practice_photo', 'practice_proof', 'social_practice_report'], true) && !empty($plan['result_deadline_at']) && $this->now() > (string) $plan['result_deadline_at']) {
            throw new RuntimeException('成果材料提交已超过截止时间', 409);
        }
    }

    /** 校验集中实践实施申请。 */
    private function assertImplementationReady(object $row): void
    {
        $project = SocialPracticeRecord::projectRow((int) ($row->project_id ?? 0));
        if (!$project) {
            throw new RuntimeException('集中实践项目不存在', 40400);
        }
        $plan = SocialPracticeRecord::planRow((int) ($project['plan_id'] ?? 0)) ?: [];
        if ((string) ($plan['status'] ?? '') !== 'accept' || empty($plan['published_at'])) {
            throw new RuntimeException('计划尚未发布，不能提交实施申请', 409);
        }
        $summary = SocialPracticeRecord::projectConfigurationSummary((int) $project['id']);
        if ((int) $summary['leader_count'] !== 1 || (int) $summary['teacher_count'] < 1) {
            throw new RuntimeException('项目尚未完成负责人和指导教师配置', 42201);
        }
        if ((int) $summary['participant_count'] < 1) {
            throw new RuntimeException('项目尚未分配学生', 42201);
        }
    }

    /** 校验社会实践报名窗口。 */
    private function assertRegistrationOpen(array $plan): void
    {
        if (($plan['status'] ?? '') !== 'accept' || ($plan['phase'] ?? '') !== 'enrolling' || empty($plan['published_at'])) {
            throw new RuntimeException('当前计划未开放报名', 409);
        }
        $now = $this->now();
        if (!empty($plan['register_start_at']) && (string) $plan['register_start_at'] > $now) {
            throw new RuntimeException('社会实践报名尚未开始', 409);
        }
        if (!empty($plan['register_end_at']) && (string) $plan['register_end_at'] < $now) {
            throw new RuntimeException('社会实践报名已结束', 409);
        }
    }

    /** 校验材料、补签和成绩提交条件。 */
    private function assertExecutionReady(string $resource, object $row): void
    {
        if ($resource === 'patch_sign' && trim((string) ($row->reason ?? '')) === '') {
            throw new InvalidArgumentException('补签原因不能为空', 42201);
        }
        if ($resource === 'score' && $row->final_score === null) {
            throw new InvalidArgumentException('成绩不能为空', 42201);
        }
    }

    /** 校验提交人和业务关系。 */
    private function assertSubmitter(string $resource, object $row, array $scope): void
    {
        $role = (string) ($scope['role_type'] ?? '');
        if (in_array($role, ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            return;
        }
        if ($resource === 'declaration' && $role === 'student' && (int) $row->applicant_student_id === (int) $scope['student_id']) {
            return;
        }
        if ($resource === 'implementation' && $role === 'teacher' && SocialPracticeRecord::projectTeacherRelation((int) $row->project_id, (int) $scope['teacher_id'])) {
            return;
        }
        if (in_array($resource, ['material', 'patch_sign'], true) && $role === 'student' && (int) ($row->student_id ?? 0) === (int) $scope['student_id']) {
            return;
        }
        if (in_array($resource, ['material', 'score'], true) && $role === 'teacher' && (int) ($row->teacher_id ?? 0) === (int) $scope['teacher_id']) {
            return;
        }
        throw new RuntimeException('无提交权限', 40301);
    }

    /** 校验集中项目操作人。 */
    private function assertProjectOperator(int $projectId, array $project): void
    {
        $role = CurrentContext::roleType();
        if (in_array($role, ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            return;
        }
        if ($role === 'teacher' && SocialPracticeRecord::projectTeacherRelation($projectId, $this->currentTeacherId(true))) {
            return;
        }
        throw new RuntimeException('无项目操作权限', 40301);
    }

    /** 校验材料归属。 */
    private function assertMaterialTarget(int $planId, ?int $projectId, ?int $declarationId, ?int $studentId, string $submitScope): void
    {
        if (($projectId ? 1 : 0) + ($declarationId ? 1 : 0) !== 1) {
            throw new InvalidArgumentException('材料必须且只能关联一个项目或申报');
        }
        $scope = $this->scopeContext();
        $plan = SocialPracticeRecord::planRow($planId);
        if (!$plan || !SocialPracticeRecord::entityVisible($scope, 'plan', $planId)) {
            throw new RuntimeException('计划不存在或无访问权限', 40301);
        }
        $project = $projectId ? SocialPracticeRecord::projectRow($projectId) : null;
        if ($projectId && (!$project || (int) ($project['plan_id'] ?? 0) !== $planId || !SocialPracticeRecord::entityVisible($scope, 'project', $projectId))) {
            throw new RuntimeException('项目与计划不匹配或无访问权限', 40301);
        }
        $declaration = $declarationId ? SocialPracticeRecord::declarationRow($declarationId) : null;
        if ($declarationId && (!$declaration || (int) ($declaration['plan_id'] ?? 0) !== $planId || !SocialPracticeRecord::entityVisible($scope, 'declaration', $declarationId))) {
            throw new RuntimeException('申报与计划不匹配或无访问权限', 40301);
        }
        if ($projectId && $studentId && !SocialPracticeRecord::activeParticipant($projectId, $studentId)) {
            throw new RuntimeException('学生不是该项目参与人', 40301);
        }
        if ($projectId && !$studentId && !in_array($submitScope, ['team', 'project'], true)) {
            throw new InvalidArgumentException('分别提交材料必须指定学生');
        }
        if ($projectId && !$studentId && $submitScope === 'team' && CurrentContext::roleType() === 'student') {
            $currentStudentId = $this->currentStudentId(true);
            if (!SocialPracticeRecord::activeParticipant($projectId, $currentStudentId)) {
                throw new RuntimeException('当前学生不是该项目参与人', 40301);
            }
        }
        $role = CurrentContext::roleType();
        if (in_array($role, ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            return;
        }
        $currentStudentId = $this->currentStudentId(false);
        if ($role === 'student' && $currentStudentId && $studentId && $currentStudentId === $studentId) {
            if ($projectId) {
                return;
            }
            $member = SocialPracticeRecord::declarationMemberRow((int) $declarationId, $currentStudentId);
            if ($member && (string) ($member['confirm_status'] ?? '') === 'accepted') {
                return;
            }
        }
        if ($role === 'student' && $currentStudentId && $projectId && !$studentId && in_array($submitScope, ['team', 'project'], true)) {
            if (SocialPracticeRecord::activeParticipant($projectId, $currentStudentId)) {
                return;
            }
        }
        if ($role === 'teacher') {
            $teacherId = $this->currentTeacherId(true);
            if ($projectId && SocialPracticeRecord::projectTeacherRelation($projectId, $teacherId)) {
                return;
            }
            if ($declaration && in_array($teacherId, [(int) ($declaration['selected_teacher_id'] ?? 0), (int) ($declaration['assigned_teacher_id'] ?? 0)], true)) {
                return;
            }
        }
        throw new RuntimeException('无材料操作权限', 40301);
    }

    /** 读取可见实体。 */
    private function visibleRow(string $resource, int $id): array
    {
        $row = SocialPracticeRecord::resourceDetail($this->scopeContext(), $resource, $id);
        if (!$row) {
            throw new RuntimeException('数据不存在或无访问权限', 40400);
        }
        return $row;
    }

    /** 检查资源可见性。 */
    private function assertVisible(string $resource, int $id, array $scope): void
    {
        if (!SocialPracticeRecord::entityVisible($scope, $resource, $id)) {
            throw new RuntimeException('数据不存在或无访问权限', 40400);
        }
    }

    /** 记录业务流程。 */
    private function recordWorkflow(string $resource, int $id, string $action, ?string $from, string $to, ?string $content, ?string $reviewStatus): int
    {
        $now = $this->now();
        $entityType = SocialPracticeRecord::entityType($resource);
        $recordingId = SocialPracticeRecord::insertRecording([
            'uuid' => $this->uuid(),
            'parent_id' => $id,
            'entity_type' => $entityType,
            'entity_id' => $id,
            'action' => $action,
            'operator_id' => $this->accountId(),
            'from_status' => $from,
            'to_status' => $to,
            'opinion' => $content,
            'content' => $content,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($reviewStatus !== null) {
            SocialPracticeRecord::insertReviewOpinion([
                'uuid' => $this->uuid(),
                'entity_type' => $entityType,
                'entity_id' => $id,
                'recording_id' => $recordingId,
                'reviewer_id' => $this->accountId(),
                'opinion' => $action === 'submit' ? null : $content,
                'status' => $reviewStatus,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            SocialPracticeRecord::clearReviewDraft($entityType, $id, $this->accountId(), $now);
        }
        return $recordingId;
    }

    /** 发送提交待办消息。 */
    private function notifySubmitted(string $resource, int $id): void
    {
        $targets = $this->reviewerAccountIds($resource, $id);
        if (!$targets) {
            return;
        }
        $row = SocialPracticeRecord::resourceDetail($this->scopeContext(), $resource, $id) ?: [];
        $this->messages->sendByTemplateCode($this->templateCode($resource, 'submit'), $targets, [
            'submitter_name' => $this->accountName(),
            'entity_title' => $this->rowTitle($resource, $row, $id),
            'module_name' => self::TITLES[$resource] ?? $resource,
        ], $this->messageEntity($resource, $id));
    }

    /** 发送教师确认提醒。 */
    private function notifyTeacherConfirmation(int $declarationId, int $teacherId, string $deadline): void
    {
        $targets = SocialPracticeRecord::teacherAccountIds($teacherId);
        if (!$targets) {
            return;
        }
        $row = SocialPracticeRecord::resourceDetail($this->scopeContext(), 'declaration', $declarationId) ?: [];
        $this->messages->sendByTemplateCode('social_practice_teacher_confirm_pending', $targets, [
            'student_name' => (string) ($row['applicant_name'] ?? '学生'),
            'entity_title' => $this->rowTitle('declaration', $row, $declarationId),
            'deadline_text' => $deadline,
        ], $this->messageEntity('declaration', $declarationId));
    }

    /** 发送审核结果消息。 */
    private function notifyReviewed(string $resource, int $id, string $status, string $opinion): void
    {
        $targets = $this->submitterAccountIds($resource, $id);
        if (!$targets) {
            return;
        }
        $row = SocialPracticeRecord::resourceDetail($this->scopeContext(), $resource, $id) ?: [];
        $this->messages->sendByTemplateCode($this->templateCode($resource, 'review'), $targets, [
            'status_text' => $this->statusText($status),
            'opinion_text' => $opinion,
            'entity_title' => $this->rowTitle($resource, $row, $id),
        ], $this->messageEntity($resource, $id));
    }

    /** 发送计划发布消息。 */
    private function notifyPlanPublished(int $id): void
    {
        $plan = SocialPracticeRecord::planRow($id);
        $targets = SocialPracticeRecord::planStudentAccountIds($id);
        if (!$targets || !$plan) {
            return;
        }
        $this->messages->sendByTemplateCode('social_practice_plan_published', $targets, [
            'entity_title' => (string) ($plan['title'] ?? '社会实践计划'),
            'date_text' => trim((string) ($plan['practice_start_at'] ?? '') . ' 至 ' . (string) ($plan['practice_end_at'] ?? '')),
        ], $this->messageEntity('plan', $id));
    }

    /** 读取提交审核人账号。 */
    private function reviewerAccountIds(string $resource, int $id): array
    {
        if ($resource === 'declaration') {
            $row = SocialPracticeRecord::declarationRow($id);
            $teacherId = (int) (($row['selected_teacher_id'] ?? 0) ?: ($row['assigned_teacher_id'] ?? 0));
            return $teacherId > 0 ? SocialPracticeRecord::teacherAccountIds($teacherId) : SocialPracticeRecord::workflowAdminAccountIds();
        }
        if (in_array($resource, ['material', 'patch_sign', 'score'], true)) {
            $row = SocialPracticeRecord::resourceDetail($this->scopeContext(), $resource, $id) ?: [];
            $teacherId = (int) ($row['teacher_id'] ?? 0);
            return $teacherId > 0 ? SocialPracticeRecord::teacherAccountIds($teacherId) : SocialPracticeRecord::workflowAdminAccountIds();
        }
        return SocialPracticeRecord::workflowAdminAccountIds();
    }

    /** 读取提交人账号。 */
    private function submitterAccountIds(string $resource, int $id): array
    {
        $row = SocialPracticeRecord::resourceDetail($this->scopeContext(), $resource, $id);
        if (!$row) {
            return [];
        }
        if ($resource === 'score' && !empty($row['teacher_id'])) {
            return SocialPracticeRecord::teacherAccountIds((int) $row['teacher_id']);
        }
        if ($resource === 'implementation' && !empty($row['applicant_id'])) {
            return [(int) $row['applicant_id']];
        }
        if ($resource === 'plan' && !empty($row['submitter_id'])) {
            return [(int) $row['submitter_id']];
        }
        if (!empty($row['student_id'])) {
            return SocialPracticeRecord::studentAccountIds((int) $row['student_id']);
        }
        if (!empty($row['applicant_student_id'])) {
            return SocialPracticeRecord::studentAccountIds((int) $row['applicant_student_id']);
        }
        $accountId = SocialPracticeRecord::latestSubmitterAccountId(SocialPracticeRecord::entityType($resource), $id);
        return $accountId ? [$accountId] : [];
    }

    /** 返回消息业务上下文。 */
    private function messageEntity(string $resource, int $id): array
    {
        return ['entity_type' => SocialPracticeRecord::entityType($resource), 'entity_id' => $id];
    }

    /** 读取审批流首节点。 */
    private function firstReviewNodeId(int $flowId): ?int
    {
        foreach (SocialPracticeRecord::approvalNodeRows($flowId) as $node) {
            if (($node['auto_pass'] ?? 'false') !== 'true') {
                return (int) $node['id'];
            }
        }
        return null;
    }

    /** 返回资源名称。 */
    private function resource(Request $request): string
    {
        $resource = trim((string) $request->input('resource', ''));
        if (!in_array($resource, self::RESOURCES, true)) {
            throw new InvalidArgumentException('resource 无效');
        }
        return $resource;
    }

    /** 返回可工作流资源名称。 */
    private function workflowResource(Request $request): string
    {
        $resource = $this->resource($request);
        if (!in_array($resource, self::WORKFLOW_RESOURCES, true)) {
            throw new InvalidArgumentException('该资源不支持当前操作');
        }
        return $resource;
    }

    /** 读取列表筛选条件。 */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (['page', 'page_size', 'per_page', 'keyword', 'status', 'phase', 'plan_id', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'practice_mode', 'date'] as $key) {
            $filters[$key] = $request->input($key);
        }
        $profile = $this->scopeContext()['student_profile'] ?? [];
        if (($filters['grade_id'] ?? null) === null || $filters['grade_id'] === '') {
            $filters['grade_id'] = CurrentContext::roleType() === 'student' ? ($profile['grade_id'] ?? null) : SocialPracticeRecord::currentGradeId();
        }
        if (CurrentContext::roleType() === 'college_admin' && (($filters['dep_id'] ?? null) === null || $filters['dep_id'] === '')) {
            $ids = $this->scopeIds('dep_id');
            $filters['dep_id'] = count($ids) === 1 ? $ids[0] : null;
        }
        if (CurrentContext::roleType() === 'profession_admin' && (($filters['profession_id'] ?? null) === null || $filters['profession_id'] === '')) {
            $ids = $this->scopeIds('profession_id');
            $filters['profession_id'] = count($ids) === 1 ? $ids[0] : null;
        }
        return $filters;
    }

    /** 读取权限范围上下文。 */
    private function scopeContext(): array
    {
        $professionIds = $this->scopeIds('profession_id');
        $studentId = $this->currentStudentId(false);
        return [
            'role_type' => CurrentContext::roleType(),
            'dep_ids' => $this->scopeIds('dep_id'),
            'profession_ids' => $professionIds,
            'profession_dep_ids' => SocialPracticeRecord::depIdsByProfessionIds($professionIds),
            'teacher_id' => $this->currentTeacherId(false),
            'student_id' => $studentId,
            'student_profile' => $studentId ? SocialPracticeRecord::studentProfile($studentId) : null,
        ];
    }

    /** 读取组织权限 ID。 */
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

    /** 校验查询权限。 */
    private function requirePermission(string $permission): void
    {
        $this->accountId();
        $code = self::PERMISSIONS[$permission] ?? $permission;
        if (!in_array($code, CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    /** 校验管理员角色。 */
    private function requireAdminRole(): void
    {
        if (!in_array(CurrentContext::roleType(), ['super_admin', 'school_admin', 'college_admin', 'profession_admin'], true)) {
            throw new RuntimeException('仅管理员可以执行此操作', 40300);
        }
    }

    /** 校验教师角色。 */
    private function requireTeacherRole(): void
    {
        if (CurrentContext::roleType() !== 'teacher') {
            throw new RuntimeException('仅教师可以执行此操作', 40300);
        }
    }

    /** 校验学生角色。 */
    private function requireStudentRole(): void
    {
        if (CurrentContext::roleType() !== 'student') {
            throw new RuntimeException('仅学生可以执行此操作', 40300);
        }
    }

    /** 校验提交权限。 */
    private function requireSubmitPermission(string $resource): void
    {
        if (in_array($resource, ['plan'], true)) {
            $this->requirePermission('plan');
            return;
        }
        if ($resource === 'implementation') {
            $this->requirePermission('project');
            return;
        }
        if ($resource === 'declaration') {
            $this->requirePermission('declare');
            return;
        }
        if ($resource === 'score') {
            $this->requirePermission('score');
            return;
        }
        if (in_array($resource, ['material', 'patch_sign'], true)) {
            $this->requirePermission($resource === 'patch_sign' ? 'attendance' : 'material');
            return;
        }
        throw new RuntimeException('资源不支持提交', 40300);
    }

    /** 校验审核权限。 */
    private function requireReviewPermission(string $resource): void
    {
        if ($resource === 'score') {
            $this->requirePermission('score_approve');
            return;
        }
        $this->requirePermission('approve');
    }

    /** 读取当前账号。 */
    private function accountId(): int
    {
        $id = (int) (CurrentContext::accountId() ?: 0);
        if ($id <= 0) {
            throw new RuntimeException('请先登录', 40100);
        }
        return $id;
    }

    /** 读取当前教师档案。 */
    private function currentTeacherId(bool $required): ?int
    {
        $id = SocialPracticeRecord::teacherIdByUser((int) CurrentContext::userId());
        if (!$id && $required) {
            throw new RuntimeException('当前账号未绑定教师档案', 42201);
        }
        return $id;
    }

    /** 读取当前学生档案。 */
    private function currentStudentId(bool $required): ?int
    {
        $id = SocialPracticeRecord::studentIdByUser((int) CurrentContext::userId());
        if (!$id && $required) {
            throw new RuntimeException('当前账号未绑定学生档案', 42201);
        }
        return $id;
    }

    /** 加 Redis 锁执行事务。 */
    private function withLock(string $entity, int $id, callable $callback): mixed
    {
        return $this->lock->run(WorkflowLock::key('social_practice', $entity, max(1, $id)), $callback, 30, true);
    }

    /** 读取请求 ID。 */
    private function requiredId(Request $request, string $field = 'id'): int
    {
        $id = (int) ($request->input($field) ?: 0);
        if ($id <= 0) {
            throw new InvalidArgumentException($field . ' 无效');
        }
        return $id;
    }

    /** 读取必填整数。 */
    private function requiredInt(Request $request, string $field): int
    {
        $value = $this->requiredId($request, $field);
        return $value;
    }

    /** 读取可选整数。 */
    private function optionalInt(Request $request, string $field): ?int
    {
        $value = $request->input($field);
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** 读取必填字符串。 */
    private function requiredString(Request $request, string $field, int $max): string
    {
        $value = $this->stringInput($request, $field, $max);
        if ($value === '') {
            throw new InvalidArgumentException($field . ' 不能为空');
        }
        return $value;
    }

    /** 读取字符串。 */
    private function stringInput(Request $request, string $field, int $max): string
    {
        $value = trim((string) $request->input($field, ''));
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > $max) {
            throw new InvalidArgumentException($field . ' 超过长度限制', 42202);
        }
        return $value;
    }

    /** 读取并规范化可选日期时间。 */
    private function optionalDateTime(Request $request, string $field): ?string
    {
        $value = trim((string) $request->input($field, ''));
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        $format = strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException($field . ' 日期格式不正确', 42201);
        }
        return $date->format('Y-m-d H:i:s');
    }

    /** 读取请求数组。 */
    private function arrayInput(Request $request, string $field): array
    {
        $value = $request->input($field, []);
        return is_array($value) ? $value : [];
    }

    /** 读取枚举值。 */
    private function enum(Request $request, string $field, array $allowed, ?string $default = null): string
    {
        $value = trim((string) $request->input($field, $default ?? ''));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($field . ' 无效');
        }
        return $value;
    }

    /** 读取开关值。 */
    private function boolInput(Request $request, string $field, bool $default): bool
    {
        $value = $request->input($field, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** 读取金额。 */
    private function optionalDecimal(Request $request, string $field): ?float
    {
        $value = $request->input($field);
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }

    /** 读取必填经纬度。 */
    private function requiredDecimal(Request $request, string $field): float
    {
        $value = $this->optionalDecimal($request, $field);
        if ($value === null) {
            throw new InvalidArgumentException($field . ' 不能为空');
        }
        return $value;
    }

    /** 生成教师确认截止时间。 */
    private function dateAfterHours(int $hours): string
    {
        return date('Y-m-d H:i:s', time() + max(1, $hours) * 3600);
    }

    /** 规范化计划范围。 */
    private function normalizeScopes(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $type = (string) ($item['scope_type'] ?? 'school');
            if (!in_array($type, ['school', 'college', 'profession', 'class'], true)) {
                throw new InvalidArgumentException('计划范围类型无效');
            }
            $result[] = [
                'scope_type' => $type,
                'dep_id' => $this->nullableValue($item['dep_id'] ?? null),
                'profession_id' => $this->nullableValue($item['profession_id'] ?? null),
                'class_id' => $this->nullableValue($item['class_id'] ?? null),
            ];
        }
        return $result ?: [['scope_type' => 'school', 'dep_id' => null, 'profession_id' => null, 'class_id' => null]];
    }

    /** 规范化计划要求。 */
    private function normalizeRequirements(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $type = trim((string) ($item['requirement_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $result[] = [
                'practice_mode' => in_array(($item['practice_mode'] ?? 'all'), ['all', 'centralized', 'distributed'], true) ? $item['practice_mode'] : 'all',
                'requirement_type' => substr($type, 0, 80),
                'required_flag' => $type === 'parent_notice' ? 'false' : ($this->boolValue($item['required_flag'] ?? true) ? 'true' : 'false'),
                'submit_scope' => in_array(($item['submit_scope'] ?? 'student'), ['student', 'team', 'project'], true) ? $item['submit_scope'] : 'student',
                'deadline_at' => $item['deadline_at'] ?? null,
                'config_json' => is_array($item['config_json'] ?? null) ? json_encode($item['config_json'], JSON_UNESCAPED_UNICODE) : ($item['config_json'] ?? null),
            ];
        }
        return $result;
    }

    /** 规范化成绩规则。 */
    private function normalizeScoreRules(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!in_array(($item['practice_mode'] ?? ''), ['centralized', 'distributed'], true)) {
                continue;
            }
            $code = trim((string) ($item['item_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $result[] = [
                'practice_mode' => $item['practice_mode'],
                'item_code' => substr($code, 0, 80),
                'item_name' => substr(trim((string) ($item['item_name'] ?? $code)), 0, 120),
                'weight' => round((float) ($item['weight'] ?? 0), 2),
                'max_score' => round((float) ($item['max_score'] ?? 100), 2),
                'sort' => (int) ($item['sort'] ?? 0),
            ];
        }
        return $result;
    }

    /** 校验成绩权重。 */
    private function validateScoreRules(array $items): void
    {
        foreach (['centralized', 'distributed'] as $mode) {
            $total = 0.0;
            foreach ($items as $item) {
                if ($item['practice_mode'] === $mode) {
                    $total += (float) $item['weight'];
                }
            }
            if ($total > 0 && abs($total - 100) > 0.01) {
                throw new InvalidArgumentException($mode . ' 成绩权重必须合计 100%');
            }
        }
    }

    /** 读取审核意见。 */
    private function reviewOpinion(Request $request, string $resource, string $status): string
    {
        $opinion = trim((string) $request->input('opinion', ''));
        $rule = self::REVIEW_RULES[$resource][$status] ?? ['min' => 0, 'max' => 500];
        $length = function_exists('mb_strlen') ? mb_strlen($opinion) : strlen($opinion);
        if ($length < (int) ($rule['min'] ?? 0)) {
            throw new InvalidArgumentException('审核意见至少 ' . $rule['min'] . ' 字', 42201);
        }
        if (isset($rule['max']) && $length > (int) $rule['max']) {
            throw new InvalidArgumentException('审核意见最多 ' . $rule['max'] . ' 字', 42202);
        }
        return $opinion;
    }

    /** 返回工作流摘要。 */
    private function workflowContent(string $resource, object|array $row): string
    {
        $data = is_array($row) ? $row : get_object_vars($row);
        return trim(implode('：', array_filter([(string) (self::TITLES[$resource] ?? $resource), (string) ($data['title'] ?? '')]))) ?: '提交审核';
    }

    /** 返回资源标题。 */
    private function rowTitle(string $resource, array $row, int $id): string
    {
        return (string) (($row['title'] ?? '') ?: (self::TITLES[$resource] ?? $resource) . '#' . $id);
    }

    /** 返回模板编码。 */
    private function templateCode(string $resource, string $action): string
    {
        return 'social_practice_' . $resource . '_' . ($action === 'submit' ? 'submit_todo' : 'review_result');
    }

    /** 返回状态中文名。 */
    private function statusText(string $status): string
    {
        return ['accept' => '通过', 'modify' => '退回修改', 'wait' => '待审核', 'draft' => '草稿'][$status] ?? $status;
    }

    /** 返回当前账号名称。 */
    private function accountName(): string
    {
        return (string) (CurrentContext::get('user_name') ?: CurrentContext::get('login_name') ?: '系统');
    }

    /** 读取布尔值。 */
    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** 读取可空 ID。 */
    private function nullableValue(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** 生成 UUID。 */
    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** 当前时间。 */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
