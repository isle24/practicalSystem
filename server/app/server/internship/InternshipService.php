<?php

namespace app\server\internship;

use app\model\channel\Account;
use app\model\channel\InternshipRecord;
use app\model\channel\PracticeRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\config\ConfigService;
use app\server\message\MessageService;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use support\Request;
use Throwable;
use Webman\Http\UploadFile;

class InternshipService
{
    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const WORKFLOW_STATUS = ['draft', 'wait', 'accept', 'modify', 'enabled', 'completed', 'changing', 'changed', 'disabled'];
    private const APPLICATION_REVIEW_STATUS = ['accept', 'modify', 'skipped'];
    private const JOIN_STATUS = ['applying', 'accept', 'refuse'];
    private const ARRANGEMENT_TYPES = ['cognition_internal', 'cognition_external', 'major_internal', 'major_external', 'production', 'graduation'];
    private const ORGANIZE_MODES = ['centralized', 'distributed', 'autonomous'];
    private const STAT_REPORTS = ['overview', 'department', 'profession', 'teacher', 'student', 'archive', 'practice_score_sheet'];
    private const PLAN_APPROVAL_LEVELS = [
        1 => ['name' => '系主任', 'role_types' => ['college_admin']],
        2 => ['name' => '教务科', 'role_types' => ['school_admin']],
        3 => ['name' => '主管院长', 'role_types' => ['college_admin']],
        4 => ['name' => '教务处', 'role_types' => ['school_admin']],
        5 => ['name' => '处领导', 'role_types' => ['school_admin']],
    ];
    private const PLAN_APPROVER_DISTINCT_LEVELS = [
        2 => [1],
        3 => [1, 2],
        4 => [3],
        5 => [2, 4],
    ];
    private const BASE_FLOW_TABLES = [
        'application' => 'base_application',
        'usage' => 'base_usage',
        'result' => 'base_result',
        'expense' => 'base_expense',
    ];
    private const BASE_FLOW_ENTITIES = [
        'base_application' => 'application',
        'base_usage' => 'usage',
        'base_result' => 'result',
        'base_expense' => 'expense',
    ];
    private const REVIEW_OPINION_RULES = [
        'arrangement_change' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 5, 'max' => 500],
            'refuse' => ['min' => 5, 'max' => 500],
        ],
        'application' => [
            'accept' => ['min' => 0, 'max' => 200],
            'modify' => ['min' => 5, 'max' => 500],
            'skipped' => ['min' => 0, 'max' => 200],
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
            'modify' => ['min' => 5, 'max' => 500],
        ],
        'plan' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 8, 'max' => 800],
        ],
        'delay' => [
            'accept' => ['min' => 0, 'max' => 300],
            'refuse' => ['min' => 5, 'max' => 500],
            'modify' => ['min' => 5, 'max' => 500],
        ],
        'base_application' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 5, 'max' => 500],
        ],
        'base_usage' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 5, 'max' => 500],
        ],
        'base_result' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 5, 'max' => 500],
        ],
        'base_expense' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 5, 'max' => 500],
        ],
    ];
    private const REVIEW_ENTITY_CONFIG = [
        'application' => ['table' => 'application', 'recording' => 'application_recording'],
        'arrangement' => ['table' => 'arrangement', 'recording' => 'arrangement_recording'],
        'arrangement_change' => ['table' => 'arrangement_change', 'recording' => 'arrangement_change_recording'],
        'sign_in' => ['table' => 'sign_in', 'recording' => 'sign_in_recording'],
        'journal' => ['table' => 'journal', 'recording' => 'journal_recording'],
        'report' => ['table' => 'report', 'recording' => 'report_recording'],
        'score' => ['table' => 'score', 'recording' => 'score_recording'],
        'plan' => ['table' => 'internship_plan', 'recording' => 'plan_recording'],
        'delay' => ['table' => 'apply_report_delay', 'recording' => 'apply_report_delay_recording'],
        'insurance' => ['table' => 'insurance', 'recording' => 'insurance_recording'],
        'safety_letter' => ['table' => 'safety_letter_sign', 'recording' => 'safety_letter_recording'],
        'syllabus_guide' => ['table' => 'syllabus_guide', 'recording' => 'syllabus_guide_recording'],
        'implementation_sheet' => ['table' => 'implementation_sheet', 'recording' => 'implementation_sheet_recording'],
        'teacher_work_report' => ['table' => 'teacher_work_report', 'recording' => 'teacher_work_report_recording'],
        'inspection' => ['table' => 'inspection_record', 'recording' => 'inspection_recording'],
        'base_application' => ['table' => 'base_application', 'recording' => 'base_application_recording'],
        'base_usage' => ['table' => 'base_usage', 'recording' => 'base_usage_recording'],
        'base_result' => ['table' => 'base_result', 'recording' => 'base_result_recording'],
        'base_expense' => ['table' => 'base_expense', 'recording' => 'base_expense_recording'],
    ];
    private const REQUEST_MODIFICATION_ENTITIES = ['application', 'journal', 'report', 'plan', 'delay', 'base_application', 'base_usage', 'base_result', 'base_expense'];
    private const DELAY_CONFIG_KEYS = ['report_deadline', 'journal_deadline'];
    private const EXCEL_EXTENSIONS = ['xls', 'xlsx'];
    private const EXCEL_MAX_SIZE = 10485760;
    private const EXCEL_MAX_ROWS = 5000;
    private const ARRANGEMENT_IMPORT_REQUIRED = [
        'grade_name',
        'dep_name',
        'profession_name',
        'course_name',
        'credit',
        'task_no',
        'teacher_num',
        'start_date',
        'end_date',
        'location',
        'class_names',
    ];
    private const ARRANGEMENT_IMPORT_HEADERS = [
        'grade_name' => ['届次', '届次名称', '年级', '年级名称', 'grade', 'grade_name'],
        'dep_name' => ['学院', '学院名称', '院系', '院系名称', 'department', 'department_name', 'dep_name'],
        'profession_name' => ['专业', '专业名称', 'profession', 'profession_name', 'major', 'major_name'],
        'course_code' => ['课程代码', '课程编号', 'course_code', 'coursecode'],
        'course_name' => ['课程名称', '课程', 'course_name', 'coursename'],
        'credit' => ['学分', 'credit'],
        'task_no' => ['任务编号', '任务号', 'task_no', 'taskno'],
        'batch_no' => ['批次', '批次号', 'batch', 'batch_no'],
        'teacher_num' => ['老师工号', '教师工号', '教师编号', 'teacher_num', 'teachernum'],
        'teacher_name' => ['老师姓名', '教师姓名', '负责老师', 'teacher_name', 'teachername'],
        'start_date' => ['开始日期', '开始时间', 'start_date', 'startdate'],
        'end_date' => ['结束日期', '结束时间', 'end_date', 'enddate'],
        'location' => ['地点', '实习地点', 'location'],
        'class_names' => ['班级', '班级名称', '任务班级', 'class', 'class_name'],
        'student_count' => ['学生人数', '人数', 'student_count', 'studentcount'],
        'type' => ['类型', '实习类型', 'type'],
        'organize_mode' => ['组织方式', '实习方式', 'organize_mode', 'organizemode'],
        'change_reason' => ['变更原因', '调整原因', '修改原因', 'change_reason', 'changereason'],
    ];
    private const STUDENT_DOCUMENT_COLUMNS = [
        'students.name as student_name',
        'students.student_num',
        'students.grade_id',
        'grade_list.grade_name',
        'arrangement.title as arrangement_title',
        'arrangement.semester as arrangement_semester',
    ];

    public function overview(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::overviewRows($this->scopeContext(), date('Y-m-d'));
    }

    public function options(Request $request): array
    {
        $this->requirePermission('internship:view');

        return array_merge([
            'types' => self::ARRANGEMENT_TYPES,
            'organize_modes' => self::ORGANIZE_MODES,
        ], InternshipRecord::optionRows($this->scopeContext()), [
            'review_rules' => self::REVIEW_OPINION_RULES,
            'deadline_configs' => $this->deadlineConfigs(),
        ]);
    }

    public function bases(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::basePage($this->scopeContext(), $this->requestFilters($request, ['page', 'page_size', 'per_page', 'keyword']));
    }

    public function saveBase(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();

        $values = [
            'name' => $this->requiredString($request, 'name', 180),
            'code' => $this->nullableString($request, 'code', 120),
            'company_id' => $this->optionalInt($request, 'company_id'),
            'dep_id' => $this->optionalInt($request, 'dep_id'),
            'address' => $this->nullableString($request, 'address', 255),
            'capacity' => $this->optionalInt($request, 'capacity') ?? 0,
            'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveRow('base', $request, $values);
    }

    public function baseFlows(Request $request): array
    {
        $this->requirePermission('internship:view');
        $table = $this->baseFlowTable($request);

        return InternshipRecord::baseFlowPage($table, $this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'base_id', 'dep_id',
        ]));
    }

    public function saveBaseFlow(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();
        $type = $this->baseFlowType($request);
        $table = self::BASE_FLOW_TABLES[$type];
        $existingId = $this->inputRowId($request, $table);
        if ($existingId > 0) {
            $fromStatus = InternshipRecord::statusById($table, $existingId);
            if (!in_array($fromStatus, ['draft', 'modify'], true)) {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
        }
        $baseId = $this->requiredInt($request, 'base_id');
        $base = $this->row('base', $baseId);
        $depId = $this->optionalInt($request, 'dep_id') ?? (int) ($base->dep_id ?? 0) ?: null;
        if ($depId) {
            $this->assertDepartmentVisible($depId);
        }

        $values = [
            'base_id' => $baseId,
            'dep_id' => $depId,
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->nullableString($request, 'content', 10000),
            'submitter_id' => CurrentContext::accountId(),
            'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];
        if ($type === 'application') {
            $values['base_type'] = $this->enum($request, 'base_type', ['fixed', 'spot'], 'fixed');
        }
        if ($type === 'usage') {
            $values['usage_type'] = $this->nullableString($request, 'usage_type', 80);
        }
        if ($type === 'result') {
            $values['result_type'] = $this->nullableString($request, 'result_type', 80);
        }
        if ($type === 'expense') {
            $values['amount'] = $this->decimalInput($request, 'amount');
        }

        $entity = array_search($type, self::BASE_FLOW_ENTITIES, true) ?: 'base_application';
        return $this->saveWorkflowRow($table, $entity, $entity . '_recording', $request, $values, $this->workflowContent('保存' . $this->entityDisplayName($entity), $values, [
            'title' => '标题',
            'content' => '内容',
            'status' => '状态',
        ]));
    }

    public function reviewBaseFlow(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $entity = $this->baseFlowReviewEntity($request);
        $config = self::REVIEW_ENTITY_CONFIG[$entity];
        $id = $this->requiredRowId($request, $config['table']);
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinionInput($request, $entity, $status);
        $this->ensureRecordingTable($config['recording']);

        return $this->workflowLock('internship', $entity, $id, function () use ($entity, $config, $id, $status, $opinion): array {
            return $this->connection()->transaction(function () use ($entity, $config, $id, $status, $opinion): array {
                $row = InternshipRecord::lockActiveRowById($config['table'], $id);
                if (!$row) {
                    throw new RuntimeException('数据不存在');
                }
                $this->assertReviewEntityWritable($entity, $row);
                if ((string) $row->status !== 'wait') {
                    throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
                }

                InternshipRecord::updateById($config['table'], $id, [
                    'status' => $status,
                    'updated_at' => $this->now(),
                ]);
                $this->recordWorkflow($config['recording'], $entity, $id, 'review', 'wait', $status, $opinion ?: '审核处理', $status);
                InternshipRecord::clearReviewOpinionDraft($entity, $id, $this->accountId(), $this->now());
                $this->notifyWorkflowReviewed($entity, $id, $this->reviewEntitySubmitterAccountId($entity, $row), $this->entityDisplayName($entity), $status, $opinion ?: '审核处理');

                return ['id' => $id, 'status' => $status];
            });
        });
    }

    public function mentors(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::mentorPage($this->scopeContext(), $this->requestFilters($request, ['page', 'page_size', 'per_page', 'keyword']));
    }

    public function saveMentor(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $values = [
            'company_id' => $this->optionalInt($request, 'company_id'),
            'name' => $this->requiredString($request, 'name', 80),
            'phone' => $this->nullableString($request, 'phone', 40),
            'position' => $this->nullableString($request, 'position', 120),
            'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveRow('enterprise_mentor', $request, $values);
    }

    public function arrangements(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::arrangementPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'type', 'organize_mode',
            'plan_id', 'dep_id', 'profession_id', 'grade_id', 'semester',
        ]));
    }

    public function arrangementDetail(Request $request): array
    {
        $this->requirePermission('internship:view');
        $id = $this->requiredRowId($request, 'arrangement');
        $detail = InternshipRecord::arrangementDetail($this->scopeContext(), $id);
        if (!$detail) {
            throw new RuntimeException('实习任务不存在或无权限', 40301);
        }

        return $detail;
    }

    public function arrangementChanges(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::arrangementChangePage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'semester',
        ]));
    }

    public function saveArrangement(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();

        return $this->persistArrangement([
            'id' => $this->inputRowId($request, 'arrangement'),
            'uuid' => $this->nullableString($request, 'uuid', 36),
            'plan_id' => $this->requiredInt($request, 'plan_id'),
            'teacher_id' => $this->requiredInt($request, 'teacher_id'),
            'class_ids' => $this->intArray($request->input('class_ids', [])),
            'title' => $this->requiredString($request, 'title', 180),
            'name' => $this->stringInput($request, 'name', 180) ?: $this->requiredString($request, 'title', 180),
            'base_id' => $this->optionalInt($request, 'base_id'),
            'enterprise_mentor_id' => $this->optionalInt($request, 'enterprise_mentor_id'),
            'task_no' => $this->requiredString($request, 'task_no', 80),
            'batch_no' => $this->nullableString($request, 'batch_no', 80),
            'credit' => $this->decimalInput($request, 'credit'),
            'type' => $this->enum($request, 'type', self::ARRANGEMENT_TYPES, 'major_external'),
            'organize_mode' => $this->enum($request, 'organize_mode', self::ORGANIZE_MODES, 'centralized'),
            'start_date' => $this->requiredDate($request, 'start_date'),
            'end_date' => $this->requiredDate($request, 'end_date'),
            'location' => $this->nullableString($request, 'location', 255),
            'description' => $this->nullableString($request, 'description', 2000),
            'change_reason' => $this->nullableString($request, 'change_reason', 1000),
            'status' => $this->enum($request, 'status', ['enabled', 'disabled', 'draft', 'wait', 'accept', 'modify'], 'enabled'),
        ], '手工维护实习任务');
    }

    public function saveArrangementChange(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();

        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertCurrentArrangementVisible($arrangementId);
        $detail = InternshipRecord::arrangementDetail($this->scopeContext(), $arrangementId);
        if (!$detail) {
            throw new RuntimeException('实习任务不存在或无权限', 40301);
        }
        $item = $detail['item'] ?? [];
        if (($item['status'] ?? '') === 'changed') {
            throw new InvalidArgumentException('历史任务不可发起变更');
        }

        $status = $this->enum($request, 'status', ['draft', 'wait'], 'wait');
        $reason = $this->nullableString($request, 'reason', 1000)
            ?: ($this->nullableString($request, 'change_reason', 1000) ?: '');
        if ($reason === '') {
            throw new InvalidArgumentException('变更原因不能为空');
        }
        $payload = $this->arrangementPayloadFromRequest($request, $detail);
        $changeId = $this->optionalInt($request, 'change_id');
        return $this->submitArrangementChange($arrangementId, $detail, $payload, $reason, $status, $changeId);
    }

    public function reviewArrangementChange(Request $request): array
    {
        $this->requireAnyPermission(['internship:approve', 'internship:manage']);
        $this->requireAdminRole();

        $changeId = $this->requiredRowId($request, 'arrangement_change');
        $status = $this->enum($request, 'status', ['accept', 'modify', 'refuse'], 'accept');
        $opinion = $this->reviewOpinionInput($request, 'arrangement_change', $status);
        $this->ensureRecordingTable('arrangement_change_recording');
        if ($status === 'accept') {
            $this->ensureRecordingTable('arrangement_recording');
        }

        $change = InternshipRecord::lockArrangementChangeRow($this->scopeContext(), $changeId);
        if (!$change) {
            throw new RuntimeException('任务变更单不存在或无权限', 40301);
        }
        if ((string) $change->status !== 'wait') {
            throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
        }
        $arrangementId = (int) $change->arrangement_id;
        $original = InternshipRecord::activeRowById('arrangement', $arrangementId);
        if (!$original) {
            throw new RuntimeException('原任务不存在');
        }

        $newArrangementId = null;
        $toStatus = $status;
        if ($status === 'accept') {
            $payload = $this->arrangementChangePayload($change);
            $arrangementResult = $this->persistArrangement(array_merge($payload, [
                'id' => $arrangementId,
                'change_reason' => (string) $change->reason,
                'approved_change' => true,
                'suppress_notify' => true,
                'status' => 'enabled',
            ]), '审核通过任务变更');
            $newArrangementId = (int) ($arrangementResult['id'] ?? 0);
            $toStatus = 'accept';
        }

        $result = $this->connection()->transaction(function () use ($arrangementId, $change, $changeId, $newArrangementId, $opinion, $status, $toStatus): array {
            $change = InternshipRecord::lockArrangementChangeRow($this->scopeContext(), $changeId);
            if (!$change) {
                throw new RuntimeException('任务变更单不存在或无权限', 40301);
            }
            if ((string) $change->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }

            $now = $this->now();
            if ($status !== 'accept') {
                $restoreStatus = in_array((string) ($change->from_status ?? ''), self::WORKFLOW_STATUS, true)
                    ? (string) $change->from_status
                    : 'enabled';
                InternshipRecord::updateById('arrangement', $arrangementId, [
                    'status' => $restoreStatus,
                    'updated_at' => $now,
                ]);
            }

            InternshipRecord::updateById('arrangement_change', $changeId, [
                'status' => $status,
                'reviewer_id' => CurrentContext::accountId(),
                'review_opinion' => $opinion,
                'reviewed_at' => $now,
                'new_arrangement_id' => $newArrangementId,
                'updated_at' => $now,
            ]);
            $this->recordWorkflow(
                'arrangement_change_recording',
                'arrangement_change',
                $changeId,
                'review',
                'wait',
                $toStatus,
                $opinion ?: ($status === 'accept' ? '同意任务变更' : '任务变更退回'),
                $status
            );
            InternshipRecord::clearReviewOpinionDraft('arrangement_change', $changeId, $this->accountId(), $now);
            if ($status !== 'accept') {
                $this->recordWorkflow(
                    'arrangement_recording',
                    'arrangement',
                    $arrangementId,
                    'change_reject',
                    'changing',
                    in_array((string) ($change->from_status ?? ''), self::WORKFLOW_STATUS, true) ? (string) $change->from_status : 'enabled',
                    $opinion ?: '任务变更退回，维持原任务',
                    $status
                );
            }

            return [
                'id' => $changeId,
                'status' => $status,
                'arrangement_id' => $arrangementId,
                'new_arrangement_id' => $newArrangementId,
            ];
        });

        $this->notifyArrangementChangeReviewed($change, $original, $status, $opinion, $newArrangementId);

        return $result;
    }

    public function importArrangementAssignments(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();

        $rows = $this->arrangementImportRows($this->excelFile($request));
        $summary = [
            'total' => count($rows),
            'created' => 0,
            'updated' => 0,
            'change_submitted' => 0,
            'failed' => 0,
            'class_count' => 0,
            'student_count' => 0,
            'errors' => [],
        ];
        foreach ($rows as $row) {
            try {
                $result = $this->importArrangementRow($row);
                if (($result['created'] ?? false) === true) {
                    $summary['created']++;
                } elseif (($result['change_submitted'] ?? false) === true) {
                    $summary['change_submitted']++;
                } else {
                    $summary['updated']++;
                }
                $summary['class_count'] += (int) ($result['class_count'] ?? 0);
                $summary['student_count'] += (int) ($result['student_count'] ?? 0);
            } catch (Throwable $exception) {
                $summary['failed']++;
                if (count($summary['errors']) < 30) {
                    $summary['errors'][] = [
                        'row' => (int) ($row['row_number'] ?? 0),
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        return $summary;
    }

    public function applications(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::applicationPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveApplication(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:apply' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $status = $this->enum($request, 'status', ['draft', 'wait'], 'draft');
        $now = $this->now();
        $existingId = $this->inputRowId($request, 'application');

        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        $this->assertTaskBindingVisible($studentId, $arrangementId);

        if (!$existingId) {
            $existingId = InternshipRecord::applicationIdByStudentArrangement($studentId, $arrangementId);
        }
        $fromStatus = $existingId ? InternshipRecord::statusById('application', $existingId) : 'draft';

        if ($this->isStudent()) {
            if ($fromStatus === 'accept') {
                throw new RuntimeException('该实习安排不可重复申请', 42201);
            }
        }
        if ($existingId && !in_array($fromStatus, ['draft', 'modify'], true)) {
            throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
        }

        $values = [
            'student_id' => $studentId,
            'arrangement_id' => $arrangementId,
            'type' => $this->enum($request, 'type', self::ORGANIZE_MODES, 'centralized'),
            'status' => $status,
            'teacher_status' => 'pending',
            'admin_status' => 'pending',
            'remark' => $this->nullableString($request, 'remark', 2000),
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        $save = function () use ($request, $values, $studentId, $arrangementId, $status, $fromStatus): array {
            $id = $this->saveRow('application', $request, $values, [
                'student_id' => $studentId,
                'arrangement_id' => $arrangementId,
            ])['id'];
            $this->syncJoinTeachers($id, $studentId, $arrangementId, InternshipRecord::activePairTeacherIds($studentId, $arrangementId));
            if ($status === 'wait') {
                $this->recordWorkflow('application_recording', 'application', $id, 'submit', $fromStatus, 'wait', $values['remark'] ?: '提交特殊申请', 'wait');
                $this->notifyWorkflowSubmitted('application', $id, (int) CurrentContext::accountId(), InternshipRecord::activePairTeacherIds($studentId, $arrangementId), '特殊申请', $values['remark'] ?: '提交特殊申请');
            }

            return ['id' => $id, 'item' => $this->application($id)];
        };

        return $existingId
            ? $this->workflowLock('internship', 'application', $existingId, $save)
            : $save();
    }

    public function submitApplication(Request $request): array
    {
        $this->requirePermission('internship:apply');
        $id = $this->requiredRowId($request, 'application');
        return $this->workflowLock('internship', 'application', $id, function () use ($id): array {
            $row = InternshipRecord::lockActiveRowById('application', $id);
            if (!$row) {
                throw new RuntimeException('特殊申请不存在');
            }
            $this->assertStudentVisible((int) $row->student_id);
            $this->assertTaskBindingVisible((int) $row->student_id, (int) $row->arrangement_id);
            if (!in_array((string) $row->status, ['draft', 'modify'], true)) {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }

            InternshipRecord::updateById('application', $id, [
                'status' => 'wait',
                'teacher_status' => 'pending',
                'admin_status' => 'pending',
                'updated_at' => $this->now(),
            ]);
            $this->syncJoinTeachers($id, (int) $row->student_id, (int) $row->arrangement_id, InternshipRecord::activePairTeacherIds((int) $row->student_id, (int) $row->arrangement_id));
            $this->recordWorkflow('application_recording', 'application', $id, 'submit', (string) $row->status, 'wait', (string) ($row->remark ?: '提交特殊申请'), 'wait');
            $this->notifyWorkflowSubmitted('application', $id, (int) CurrentContext::accountId(), InternshipRecord::activePairTeacherIds((int) $row->student_id, (int) $row->arrangement_id), '特殊申请', (string) ($row->remark ?: '提交特殊申请'));

            return ['id' => $id, 'item' => $this->application($id)];
        });
    }

    public function reviewApplication(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $id = $this->requiredRowId($request, 'application');
        $status = $this->enum($request, 'status', self::APPLICATION_REVIEW_STATUS, 'accept');
        $opinion = $this->reviewOpinionInput($request, 'application', $status);
        $this->ensureRecordingTable('application_recording');

        return $this->workflowLock('internship', 'application', $id, function () use ($id, $status, $opinion): array {
            return $this->connection()->transaction(function () use ($id, $status, $opinion): array {
            $row = InternshipRecord::lockActiveRowById('application', $id);
            if (!$row) {
                throw new RuntimeException('特殊申请不存在');
            }
            $this->assertApplicationVisible((int) $row->id);
            $this->assertTaskBindingVisible((int) $row->student_id, (int) $row->arrangement_id);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }

            $updates = ['updated_at' => $this->now()];
            $action = 'review';
            if ($this->isTeacher()) {
                if (!in_array((string) $row->teacher_status, ['pending', 'wait'], true)) {
                    throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
                }
                $updates['teacher_status'] = $status;
                $action = 'teacher_review';
                $this->reviewJoinTeacher($id, $status);
            } else {
                $this->requireAdminRole();
                if (!in_array((string) $row->admin_status, ['pending', 'wait'], true)) {
                    throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
                }
                $updates['admin_status'] = $status === 'skipped' ? 'accept' : $status;
                $action = 'admin_review';
            }

            if ($status === 'modify') {
                $updates['status'] = 'modify';
            }

            InternshipRecord::updateById('application', $id, $updates);
            $fresh = InternshipRecord::rowById('application', $id);
            $finalStatus = $this->refreshApplicationFinalStatus($fresh);
            $this->recordWorkflow('application_recording', 'application', $id, $action, (string) $row->status, $finalStatus, $opinion ?: '审核处理', $status);
            InternshipRecord::clearReviewOpinionDraft('application', $id, $this->accountId(), $this->now());
            $this->notifyWorkflowReviewed('application', $id, InternshipRecord::studentAccountId((int) $row->student_id), '特殊申请', $finalStatus, $opinion ?: '审核处理');

            return ['id' => $id, 'item' => $this->application($id)];
            });
        });
    }

    public function timeline(Request $request): array
    {
        $this->requirePermission('internship:view');
        $entity = $this->reviewEntity($request);
        $config = self::REVIEW_ENTITY_CONFIG[$entity];
        $id = $this->requiredRowId($request, $config['table']);
        $row = $this->row($config['table'], $id);
        $this->assertReviewEntityVisible($entity, $row);

        $records = InternshipRecord::recordingRows($config['recording'], $id);
        $reviews = InternshipRecord::reviewOpinionRows($entity, $id);

        return [
            'entity' => $entity,
            'id' => $id,
            'records' => $records,
            'reviews' => $reviews,
            'cycles' => $this->timelineCycles($records, $reviews),
            'items' => $this->timelineItems($records, $reviews),
        ];
    }

    public function requestModification(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $entity = $this->reviewEntity($request);
        if (!in_array($entity, self::REQUEST_MODIFICATION_ENTITIES, true)) {
            throw new InvalidArgumentException('该业务不支持通过后修改', 42202);
        }
        $config = self::REVIEW_ENTITY_CONFIG[$entity];
        $id = $this->requiredRowId($request, $config['table']);
        $opinion = $this->reviewOpinionInput($request, $entity, 'modify', '修改理由');
        $this->ensureRecordingTable($config['recording']);

        return $this->workflowLock('internship', $entity, $id, function () use ($entity, $config, $id, $opinion): array {
            return $this->connection()->transaction(function () use ($entity, $config, $id, $opinion): array {
            $row = InternshipRecord::lockActiveRowById($config['table'], $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertReviewEntityWritable($entity, $row);
            if ((string) $row->status !== 'accept') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }

            $updates = [
                'status' => 'modify',
                'updated_at' => $this->now(),
            ];
            if ($entity === 'application') {
                if ($this->isTeacher()) {
                    $updates['teacher_status'] = 'modify';
                } else {
                    $this->requireAdminRole();
                    $updates['admin_status'] = 'modify';
                }
            }
            if ($entity === 'report') {
                $updates['reviewed_at'] = $this->now();
            }

            InternshipRecord::updateById($config['table'], $id, $updates);
            $this->recordWorkflow($config['recording'], $entity, $id, 'modify_after_accept', 'accept', 'modify', $opinion ?: '通过后要求修改', 'modify');
            $this->notifyWorkflowReopened($entity, $id, $this->reviewEntitySubmitterAccountId($entity, $row), $this->entityDisplayName($entity), $opinion ?: '通过后要求修改');

            return ['id' => $id, 'status' => 'modify'];
            });
        });
    }

    public function reviewDraft(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $target = $this->reviewDraftTarget($request);
        $draft = InternshipRecord::reviewOpinionDraftRow($target['entity_type'], $target['id'], $this->accountId());

        return [
            'entity_type' => $target['entity_type'],
            'id' => $target['id'],
            'draft' => $draft,
        ];
    }

    public function saveReviewDraft(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $target = $this->reviewDraftTarget($request);
        $status = $this->enum($request, 'status', $this->reviewStatuses($target['entity']), 'accept');
        $opinion = $this->reviewDraftOpinionInput($request, $target['entity'], $status);
        $score = $this->decimalInput($request, 'score');
        $teacherId = $this->isTeacher() ? $this->currentTeacherId(false) : $this->optionalInt($request, 'teacher_id');
        $now = $this->now();

        return $this->workflowLock('internship', $target['entity_type'], $target['id'], function () use ($target, $status, $opinion, $score, $teacherId, $now): array {
            $id = InternshipRecord::saveReviewOpinionDraft([
                'entity_type' => $target['entity_type'],
                'entity_id' => $target['id'],
                'reviewer_id' => $this->accountId(),
                'teacher_id' => $teacherId,
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

    public function pairs(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::pairPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function savePair(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();

        $studentId = $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $teacherId = InternshipRecord::arrangementTeacherId($arrangementId);
        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        if ($teacherId <= 0) {
            throw new RuntimeException('实习任务未设置负责老师', 42201);
        }
        if (!InternshipRecord::taskClassStudentVisible($this->scopeContext(), $studentId, $arrangementId)) {
            throw new RuntimeException('学生不属于该实习任务绑定班级', 42201);
        }

        $values = [
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'dep_id' => $this->optionalInt($request, 'dep_id') ?? $this->studentDepId($studentId),
            'second_teacher_id' => $this->optionalInt($request, 'second_teacher_id'),
            'enterprise_mentor_id' => $this->optionalInt($request, 'enterprise_mentor_id'),
            'arrangement_id' => $arrangementId,
            'type' => 'internship',
            'entity_type' => 'internship',
            'entity_id' => $arrangementId,
            'application_id' => $this->optionalInt($request, 'application_id'),
            'status' => 'active',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $id = $this->upsertActivePair($values);
        $this->recordWorkflow(
            'arrangement_recording',
            'arrangement',
            $arrangementId,
            'add_pair',
            'draft',
            'active',
            sprintf('新增学生 %d 的任务绑定，负责老师 %d。', $studentId, $teacherId),
            'accept'
        );
        $this->refreshArrangementScoreWorkflow($arrangementId);

        return ['id' => $id];
    }

    public function removePair(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();
        $id = $this->requiredRowId($request, 'pair');
        $reason = $this->nullableString($request, 'remove_reason', 255);
        $this->ensureRecordingTable('arrangement_recording');

        return $this->connection()->transaction(function () use ($id, $reason): array {
            $pair = InternshipRecord::pairRowForManage($this->scopeContext(), $id);
            if (!$pair) {
                throw new RuntimeException('任务绑定不存在或无权限', 40301);
            }
            if ((string) $pair->status !== 'active') {
                throw new InvalidArgumentException('仅有效任务绑定可解除', 42204);
            }

            InternshipRecord::updateById('pair', $id, [
                'status' => 'removed',
                'remove_reason' => $reason,
                'updated_at' => $this->now(),
            ]);
            $this->recordWorkflow(
                'arrangement_recording',
                'arrangement',
                (int) $pair->arrangement_id,
                'remove_pair',
                'active',
                'removed',
                sprintf('解除学生 %d 的任务绑定：%s', (int) $pair->student_id, $reason ?: '未填写原因'),
                'modify'
            );
            $this->refreshArrangementScoreWorkflow((int) $pair->arrangement_id);

            return ['id' => $id];
        });
    }

    public function signIns(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::signInPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'date',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveSignIn(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:sign' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $signDate = $this->dateInput($request, 'date') ?: date('Y-m-d');
        $existingId = $this->inputRowId($request, 'sign_in');
        $fromStatus = $existingId ? InternshipRecord::statusById('sign_in', $existingId) : 'draft';
        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        $this->assertTaskBindingVisible($studentId, $arrangementId);
        $this->assertStudentStartPrerequisites($studentId, $arrangementId, $signDate);
        $signType = $this->enum($request, 'sign_type', ['gps', 'qrcode', 'manual'], $this->isStudent() ? 'gps' : 'manual');
        $longitude = $this->coordinateInput($request, 'longitude', -180, 180);
        $latitude = $this->coordinateInput($request, 'latitude', -90, 90);
        if ($this->isStudent() && $signType === 'gps' && ($longitude === null || $latitude === null)) {
            throw new InvalidArgumentException('GPS 定位坐标不能为空', 42201);
        }
        if ($existingId) {
            $row = $this->row('sign_in', $existingId);
            if ((int) $row->student_id !== $studentId || (int) $row->entity_id !== $arrangementId) {
                throw new RuntimeException('无数据访问权限', 40301);
            }
        }

        $values = [
            'student_id' => $studentId,
            'entity_type' => 'internship',
            'entity_id' => $arrangementId,
            'date' => $signDate,
            'sign_time' => $this->dateTimeInput($request, 'sign_time') ?: $this->now(),
            'sign_type' => $signType,
            'location' => $this->nullableString($request, 'location', 255),
            'longitude' => $longitude,
            'latitude' => $latitude,
            'remark' => $this->nullableString($request, 'remark', 1000),
            'status' => 'accept',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $result = $this->saveRow('sign_in', $request, $values);
        $this->recordWorkflow('sign_in_recording', 'sign_in', (int) $result['id'], 'submit', $fromStatus, 'accept', $this->signInWorkflowContent($values), 'accept');

        return $result;
    }

    public function journals(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::journalPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveJournal(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:journal' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $status = $this->enum($request, 'status', ['draft', 'wait'], 'draft');
        $existingId = $this->inputRowId($request, 'journal');
        $fromStatus = $existingId ? InternshipRecord::statusById('journal', $existingId) : 'draft';
        $this->assertStudentWorkCanSubmit($existingId, $fromStatus, '实习日志');
        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        $this->assertTaskBindingVisible($studentId, $arrangementId);
        if ($status === 'wait') {
            $this->assertStudentDeadlineOpen($studentId, 'journal_deadline', '实习日志');
            $this->assertStudentStartPrerequisites($studentId, $arrangementId, $this->dateInput($request, 'date') ?: date('Y-m-d'));
        }
        if ($existingId) {
            $row = $this->row('journal', $existingId);
            if ((int) $row->student_id !== $studentId || (int) $row->entity_id !== $arrangementId) {
                throw new RuntimeException('无数据访问权限', 40301);
            }
        }

        $values = [
            'student_id' => $studentId,
            'entity_type' => 'internship',
            'entity_id' => $arrangementId,
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->requiredString($request, 'content', 10000),
            'date' => $this->dateInput($request, 'date') ?: date('Y-m-d'),
            'status' => $status,
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $save = function () use ($request, $values, $existingId, $status): array {
            $currentStatus = $existingId ? InternshipRecord::statusById('journal', $existingId) : 'draft';
            $this->assertStudentWorkCanSubmit($existingId, $currentStatus, '实习日志');
            $result = $this->saveRow('journal', $request, $values);
            if ($status === 'wait') {
                $this->recordWorkflow('journal_recording', 'journal', (int) $result['id'], 'submit', $currentStatus, 'wait', $values['content'], 'wait');
                $this->notifyWorkflowSubmitted('journal', (int) $result['id'], (int) CurrentContext::accountId(), InternshipRecord::activePairTeacherIds($values['student_id'], $values['entity_id']), '实习日志', $values['title']);
            }

            return $result;
        };

        return $existingId
            ? $this->workflowLock('internship', 'journal', $existingId, $save)
            : $save();
    }

    public function reviewJournal(Request $request): array
    {
        return $this->reviewStudentWork($request, 'journal', 'journal_recording');
    }

    public function reports(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::reportPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveReport(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:report' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $status = $this->enum($request, 'status', ['draft', 'wait'], 'draft');
        $existingId = $this->inputRowId($request, 'report');
        $fromStatus = $existingId ? InternshipRecord::statusById('report', $existingId) : 'draft';
        $this->assertStudentWorkCanSubmit($existingId, $fromStatus, '实习报告');
        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        $this->assertTaskBindingVisible($studentId, $arrangementId);
        if ($status === 'wait') {
            $this->assertStudentDeadlineOpen($studentId, 'report_deadline', '实习报告');
            $this->assertStudentStartPrerequisites($studentId, $arrangementId, date('Y-m-d'));
        }
        if ($existingId) {
            $row = $this->row('report', $existingId);
            if ((int) $row->student_id !== $studentId || (int) $row->arrangement_id !== $arrangementId) {
                throw new RuntimeException('无数据访问权限', 40301);
            }
        }

        $values = [
            'student_id' => $studentId,
            'arrangement_id' => $arrangementId,
            'template_id' => $this->optionalInt($request, 'template_id'),
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->requiredString($request, 'content', 20000),
            'status' => $status,
            'submitted_at' => $status === 'wait' ? $this->now() : null,
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $save = function () use ($request, $values, $existingId, $status): array {
            $currentStatus = $existingId ? InternshipRecord::statusById('report', $existingId) : 'draft';
            $this->assertStudentWorkCanSubmit($existingId, $currentStatus, '实习报告');
            $result = $this->saveRow('report', $request, $values);
            if ($status === 'wait') {
                $this->recordWorkflow('report_recording', 'report', (int) $result['id'], 'submit', $currentStatus, 'wait', $values['content'], 'wait');
                $this->notifyWorkflowSubmitted('report', (int) $result['id'], (int) CurrentContext::accountId(), InternshipRecord::activePairTeacherIds($values['student_id'], $values['arrangement_id']), '实习报告', $values['title']);
            }

            return $result;
        };

        return $existingId
            ? $this->workflowLock('internship', 'report', $existingId, $save)
            : $save();
    }

    public function reviewReport(Request $request): array
    {
        return $this->reviewStudentWork($request, 'report', 'report_recording');
    }

    public function delays(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::delayPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'config_key', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveDelay(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:apply' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $entityType = $this->enum($request, 'entity_type', ['internship'], 'internship');
        $entityId = $this->requiredInt($request, 'entity_id');
        $configKey = $this->enum($request, 'config_key', self::DELAY_CONFIG_KEYS, 'report_deadline');
        $existingId = $this->inputRowId($request, 'apply_report_delay');
        $fromStatus = $existingId ? InternshipRecord::statusById('apply_report_delay', $existingId) : 'draft';
        $this->assertStudentWorkCanSubmit($existingId, $fromStatus, '延期申请');
        $status = $this->enum($request, 'status', ['draft', 'wait'], 'wait');

        $this->assertStudentVisible($studentId);
        if ($entityType === 'internship') {
            $this->assertCurrentArrangementVisible($entityId);
            $this->assertTaskBindingVisible($studentId, $entityId);
        }
        if ($existingId) {
            $row = $this->row('apply_report_delay', $existingId);
            $this->assertStudentVisible((int) $row->student_id);
            $this->assertTaskBindingVisible((int) $row->student_id, (int) $row->entity_id);
            if ((int) $row->student_id !== $studentId || (int) $row->entity_id !== $entityId) {
                throw new RuntimeException('无数据访问权限', 40301);
            }
        }

        $values = [
            'student_id' => $studentId,
            'config_key' => $configKey,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'requested_date' => $this->requiredDate($request, 'requested_date'),
            'reason' => $this->requiredString($request, 'reason', 2000),
            'status' => $status,
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $save = function () use ($request, $values, $status, $fromStatus): array {
            $result = $this->saveRow('apply_report_delay', $request, $values);
            if ($status === 'wait') {
                $this->recordWorkflow('apply_report_delay_recording', 'delay', (int) $result['id'], 'submit', $fromStatus, 'wait', $values['reason'], 'wait');
                $this->notifyWorkflowSubmitted('delay', (int) $result['id'], (int) CurrentContext::accountId(), InternshipRecord::activePairTeacherIds($values['student_id'], $values['entity_id']), '延期申请', $values['reason']);
            }

            return $result;
        };

        return $existingId
            ? $this->workflowLock('internship', 'delay', $existingId, $save)
            : $save();
    }

    public function reviewDelay(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $id = $this->requiredRowId($request, 'apply_report_delay');
        $status = $this->enum($request, 'status', ['accept', 'refuse'], 'accept');
        $opinion = $this->reviewOpinionInput($request, 'delay', $status);
        $this->ensureRecordingTable('apply_report_delay_recording');

        $result = $this->workflowLock('internship', 'delay', $id, function () use ($id, $status, $opinion): array {
            return $this->connection()->transaction(function () use ($id, $status, $opinion): array {
            $row = InternshipRecord::lockActiveRowById('apply_report_delay', $id);
            if (!$row) {
                throw new RuntimeException('延期申请不存在');
            }
            $this->assertStudentVisible((int) $row->student_id);
            $this->assertTaskBindingVisible((int) $row->student_id, (int) $row->entity_id);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }

            InternshipRecord::updateById('apply_report_delay', $id, [
                'status' => $status,
                'updated_at' => $this->now(),
            ]);

            $action = $this->isTeacher() ? 'teacher_review' : 'admin_review';
            $this->recordWorkflow('apply_report_delay_recording', 'delay', $id, $action, (string) $row->status, $status, $opinion ?: '延期申请审核', $status);
            InternshipRecord::clearReviewOpinionDraft('delay', $id, $this->accountId(), $this->now());
            $this->notifyWorkflowReviewed('delay', $id, InternshipRecord::studentAccountId((int) $row->student_id), '延期申请', $status, $opinion ?: '延期申请审核');

            return ['id' => $id, 'status' => $status, 'delay' => $row];
            });
        });

        if ($status === 'accept') {
            $this->saveDelayConfig($result['delay']);
        }

        unset($result['delay']);

        return $result;
    }

    public function scores(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::scorePage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function courseScores(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::courseScorePage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'plan_id', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'class_id',
        ]));
    }

    public function saveCourseScore(Request $request): array
    {
        $this->requirePermission('internship:score');
        $planId = $this->requiredInt($request, 'plan_id');
        $studentId = $this->requiredInt($request, 'student_id');
        $scoreValue = $this->decimalInput($request, 'score_value');
        if ($scoreValue === null || $scoreValue < 0 || $scoreValue > 100) {
            throw new InvalidArgumentException('课程成绩必须在 0 到 100 之间');
        }
        if (!InternshipRecord::courseScoreManualWritable($this->scopeContext(), $planId, $studentId)) {
            throw new RuntimeException('仅人工核定规则的课程成绩可维护', 40301);
        }

        $result = InternshipRecord::saveManualCourseScore(
            $planId,
            $studentId,
            $scoreValue,
            CurrentContext::accountId() ?: 0,
            $this->nullableString($request, 'remark', 1000),
            $this->uuid(),
            $this->now()
        );
        $this->notifyTemplateAccounts([InternshipRecord::courseScoreStudentAccountId($planId, $studentId)], 'internship_score_result', [
            'entity_title' => '实习课程成绩',
            'score_text' => '总评：' . $scoreValue,
            'opinion_text' => '请进入实习成绩查看。',
        ], 'course_score', (int) $result['id'], CurrentContext::accountId() ?: 0);

        return $result;
    }

    public function stats(Request $request): array
    {
        $this->requirePermission('stat:view');
        $filters = $this->requestFilters($request, [
            'report', 'page', 'page_size', 'per_page', 'keyword',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
            'module_type', 'plan_id', 'status', 'academic_year',
        ]);
        $report = trim((string) ($filters['report'] ?? 'overview'));
        $filters['report'] = in_array($report, self::STAT_REPORTS, true) ? $report : 'overview';
        if ($filters['report'] === 'practice_score_sheet') {
            return PracticeRecord::courseScoreSheetReport($this->scopeContext(), $filters);
        }

        return InternshipRecord::statReport($this->scopeContext(), $filters, date('Y-m-d'));
    }

    public function archiveMaterials(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::archiveMaterialPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'archive_status',
            'arrangement_id', 'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveScore(Request $request): array
    {
        $this->requirePermission($this->canScoreWithoutManage() ? 'internship:score' : 'internship:manage');
        $studentId = $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        $this->assertTaskBindingVisible($studentId, $arrangementId);
        $scoreId = $this->inputRowId($request, 'score')
            ?: InternshipRecord::activeIdByFields('score', ['student_id' => $studentId, 'arrangement_id' => $arrangementId]);
        $fromStatus = $scoreId > 0 ? InternshipRecord::statusById('score', $scoreId) : 'draft';

        $signInScore = $this->decimalInput($request, 'sign_in_score');
        $journalScore = $this->decimalInput($request, 'journal_score');
        $reportScore = $this->decimalInput($request, 'report_score');
        $signWeight = $this->decimalInput($request, 'sign_in_weight') ?? 20;
        $journalWeight = $this->decimalInput($request, 'journal_weight') ?? 30;
        $reportWeight = $this->decimalInput($request, 'report_weight') ?? 50;
        $enterpriseScore = $this->decimalInput($request, 'enterprise_score');
        $this->assertScoreRange([
            '签到成绩' => $signInScore,
            '日志成绩' => $journalScore,
            '报告成绩' => $reportScore,
            '企业成绩' => $enterpriseScore,
        ]);
        $this->assertWeightRange([
            '签到权重' => $signWeight,
            '日志权重' => $journalWeight,
            '报告权重' => $reportWeight,
        ]);
        $final = $this->finalScore($signInScore, $journalScore, $reportScore, $signWeight, $journalWeight, $reportWeight, $enterpriseScore);
        if ($final === null) {
            throw new InvalidArgumentException('至少填写一项任务成绩');
        }
        $this->ensureRecordingTable('score_recording');
        $this->ensureRecordingTable('arrangement_recording');

        $values = [
            'student_id' => $studentId,
            'arrangement_id' => $arrangementId,
            'sign_in_score' => $signInScore,
            'journal_score' => $journalScore,
            'report_score' => $reportScore,
            'sign_in_weight' => $signWeight,
            'journal_weight' => $journalWeight,
            'report_weight' => $reportWeight,
            'enterprise_score' => $enterpriseScore,
            'enterprise_comment' => $this->nullableString($request, 'enterprise_comment', 2000),
            'final_score' => $final,
            'teacher_id' => $this->scoreTeacherId($request, $arrangementId),
            'comment' => $this->nullableString($request, 'comment', 2000),
            'status' => 'accept',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $result = $this->saveRow('score', $request, $values, ['student_id' => $studentId, 'arrangement_id' => $arrangementId]);
        $this->recordWorkflow(
            'score_recording',
            'score',
            (int) $result['id'],
            $scoreId > 0 ? 'change' : 'submit',
            $fromStatus,
            'accept',
            $this->scoreWorkflowContent($values),
            'accept',
            $final,
            $values['teacher_id']
        );
        $this->refreshArrangementScoreWorkflow($arrangementId);
        $this->notifyTemplateAccounts([$this->studentAccountId($studentId)], 'internship_score_result', [
            'entity_title' => '实习任务#' . $arrangementId,
            'score_text' => '总评：' . ($final ?? '未填写'),
            'opinion_text' => (string) ($values['comment'] ?? '请进入实习成绩查看。'),
        ], 'score', (int) $result['id'], CurrentContext::accountId() ?: 0);

        return $result;
    }

    public function plans(Request $request): array
    {
        $this->requirePermission('internship:plan');

        return InternshipRecord::planPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'status', 'grade_id', 'dep_id', 'profession_id', 'keyword',
        ]), self::PLAN_APPROVAL_LEVELS);
    }

    public function savePlan(Request $request): array
    {
        $this->requirePermission('internship:plan');
        $this->requireAdminRole();
        $existingId = $this->inputRowId($request, 'internship_plan');
        $fromStatus = $existingId ? InternshipRecord::statusById('internship_plan', $existingId) : 'draft';
        if ($existingId && !in_array($fromStatus, ['draft', 'modify'], true)) {
            throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
        }
        $gradeId = $this->requiredInt($request, 'grade_id');
        $depId = $this->requiredInt($request, 'dep_id');
        $professionId = $this->requiredInt($request, 'profession_id');
        $this->assertDepartmentVisible($depId);
        if (!InternshipRecord::professionVisible($this->scopeContext(), $professionId)) {
            throw new RuntimeException('专业不存在或无权限', 40301);
        }
        if (!InternshipRecord::professionBelongsTo($professionId, $gradeId, $depId)) {
            throw new InvalidArgumentException('专业必须属于所选届次和学院');
        }

        $values = [
            'source_type' => $this->enum($request, 'source_type', ['edu_system', 'manual'], 'edu_system'),
            'course_code' => $this->nullableString($request, 'course_code', 120),
            'course_name' => $this->requiredString($request, 'course_name', 180),
            'grade_id' => $gradeId,
            'dep_id' => $depId,
            'profession_id' => $professionId,
            'semester' => $this->nullableString($request, 'semester', 80),
            'credit' => $this->decimalInput($request, 'credit'),
            'student_count' => 0,
            'score_rule' => $this->enum($request, 'score_rule', ['average', 'sum', 'weighted', 'manual'], 'average'),
            'plan_content' => $this->jsonValue($request->input('plan_content', [])),
            'submitter_id' => CurrentContext::accountId(),
            'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $save = function () use ($request, $values, $existingId): array {
            $currentStatus = $existingId ? InternshipRecord::statusById('internship_plan', $existingId) : 'draft';
            if ($existingId && !in_array($currentStatus, ['draft', 'modify'], true)) {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $result = $this->saveRow('internship_plan', $request, $values);
            if ($values['status'] === 'wait') {
                $this->recordWorkflow('plan_recording', 'plan', (int) $result['id'], 'submit', $currentStatus, 'wait', $this->planWorkflowContent($values['plan_content']), 'wait');
                $this->notifyWorkflowSubmitted('plan', (int) $result['id'], (int) CurrentContext::accountId(), [], '实习计划', $values['course_name']);
            }

            return $result;
        };

        return $existingId
            ? $this->workflowLock('internship', 'plan', $existingId, $save)
            : $save();
    }

    public function reviewPlan(Request $request): array
    {
        $this->requirePermission('internship:plan');
        $this->requireAdminRole();

        $planId = $this->requiredRowId($request, 'internship_plan');
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinionInput($request, 'plan', $status);
        $this->ensureRecordingTable('plan_recording');

        $result = $this->workflowLock('internship', 'plan', $planId, function () use ($planId, $status, $opinion, $request): array {
            return $this->connection()->transaction(function () use ($planId, $status, $opinion, $request): array {
            $now = $this->now();
            $row = InternshipRecord::lockActiveRowById('internship_plan', $planId);
            if (!$row) {
                throw new RuntimeException('实习计划不存在');
            }
            $this->assertPlanVisible($planId);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $from = (string) $row->status;
            $progress = InternshipRecord::planApprovalProgress($planId, self::PLAN_APPROVAL_LEVELS);
            $level = (int) ($progress['next_level'] ?? 1);
            if ($level <= 0 || !isset(self::PLAN_APPROVAL_LEVELS[$level])) {
                throw new InvalidArgumentException('实习计划审核进度异常', 42205);
            }
            $requestLevel = $this->optionalInt($request, 'approval_level');
            if ($requestLevel && $requestLevel !== $level) {
                throw new InvalidArgumentException('当前实习计划不在所选审核节点', 42205);
            }
            $levelName = (string) (self::PLAN_APPROVAL_LEVELS[$level]['name'] ?? '审核节点');
            $this->assertPlanApproverAllowed($level, $progress['approved_records_by_level'] ?? []);
            $planStatus = $status === 'modify' ? 'modify' : ($level >= count(self::PLAN_APPROVAL_LEVELS) ? 'accept' : 'wait');
            $content = $this->planReviewContent($level, $levelName, $status, $opinion, $planStatus);

            $approvalId = InternshipRecord::insertPlanApproval($planId, [
                'uuid' => $this->uuid(),
                'plan_id' => $planId,
                'approver_id' => CurrentContext::accountId(),
                'approval_level' => $level,
                'level_name' => $levelName,
                'opinion' => $opinion,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ], $planStatus, $now);
            $this->recordWorkflow('plan_recording', 'plan', $planId, 'review', $from, $planStatus, $content, $status);
            InternshipRecord::clearReviewOpinionDraft('plan', $planId, $this->accountId(), $now);

            return [
                'id' => $approvalId,
                'plan_id' => $planId,
                'approval_level' => $level,
                'level_name' => $levelName,
                'status' => $planStatus,
                'next_approval_level' => $planStatus === 'wait' ? $level + 1 : null,
                'next_level_name' => $planStatus === 'wait' ? (self::PLAN_APPROVAL_LEVELS[$level + 1]['name'] ?? null) : null,
                'notify_plan' => $row,
            ];
            });
        });

        if (($result['status'] ?? '') === 'wait' && !empty($result['next_approval_level'])) {
            $this->notifyPlanNextApproval($result['notify_plan'], $planId, (int) $result['next_approval_level'], $opinion);
        } else {
            $this->notifyPlanReviewed($result['notify_plan'], $planId, $result['status'], $result['level_name'], $opinion);
        }
        unset($result['notify_plan']);

        return $result;
    }

    public function insurances(Request $request): array
    {
        return $this->documentList($request, 'insurance', array_merge(['insurance.*'], self::STUDENT_DOCUMENT_COLUMNS));
    }

    public function saveInsurance(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $studentId = $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        $this->assertTaskBindingVisible($studentId, $arrangementId);

        $values = [
            'arrangement_id' => $arrangementId,
            'student_id' => $studentId,
            'insurance_company' => $this->nullableString($request, 'insurance_company', 120),
            'policy_number' => $this->nullableString($request, 'policy_number', 120),
            'insured_amount' => $this->decimalInput($request, 'insured_amount'),
            'start_date' => $this->dateInput($request, 'start_date'),
            'end_date' => $this->dateInput($request, 'end_date'),
            'attachment_id' => $this->optionalInt($request, 'attachment_id'),
            'status' => 'enabled',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveWorkflowRow('insurance', 'insurance', 'insurance_recording', $request, $values, $this->workflowContent('保存保险记录', $values, [
            'insurance_company' => '保险公司',
            'policy_number' => '保单号',
            'insured_amount' => '保额',
            'start_date' => '开始日期',
            'end_date' => '结束日期',
        ]));
    }

    public function safetyLetters(Request $request): array
    {
        return $this->documentList($request, 'safety_letter_sign', array_merge(['safety_letter_sign.*'], self::STUDENT_DOCUMENT_COLUMNS));
    }

    public function saveSafetyLetter(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:apply' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertStudentVisible($studentId);
        $this->assertCurrentArrangementVisible($arrangementId);
        $this->assertTaskBindingVisible($studentId, $arrangementId);
        $status = $this->enum($request, 'status', ['pending', 'signed'], 'pending');
        $signedAt = $this->dateTimeInput($request, 'signed_at');

        $values = [
            'arrangement_id' => $arrangementId,
            'student_id' => $studentId,
            'template_id' => $this->optionalInt($request, 'template_id'),
            'signed_at' => $status === 'signed' ? ($signedAt ?: $this->now()) : $signedAt,
            'signature_file_id' => $this->optionalInt($request, 'signature_file_id'),
            'status' => $status,
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveWorkflowRow('safety_letter_sign', 'safety_letter', 'safety_letter_recording', $request, $values, $this->workflowContent('保存安全承诺', $values, [
            'status' => '状态',
            'signed_at' => '签署时间',
        ]));
    }

    public function syllabusGuides(Request $request): array
    {
        $this->requirePermission('internship:view');
        return InternshipRecord::syllabusGuidePage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'status',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveSyllabusGuide(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertCurrentArrangementVisible($arrangementId);
        $values = [
            'arrangement_id' => $arrangementId,
            'dep_id' => $this->optionalInt($request, 'dep_id'),
            'profession_id' => $this->optionalInt($request, 'profession_id'),
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->nullableString($request, 'content', 20000),
            'file_id' => $this->optionalInt($request, 'file_id'),
            'created_by' => CurrentContext::accountId(),
            'status' => $this->enum($request, 'status', ['draft', 'published'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveWorkflowRow('syllabus_guide', 'syllabus_guide', 'syllabus_guide_recording', $request, $values, $this->workflowContent('保存实习大纲及指导书', $values, [
            'title' => '标题',
            'status' => '状态',
        ]));
    }

    public function implementationSheets(Request $request): array
    {
        return $this->documentList($request, 'implementation_sheet', [
            'implementation_sheet.*',
            'arrangement.title as arrangement_title',
            'arrangement.semester',
            'department.dep_name',
            'profession.profession_name',
            'profession.grade_id',
            'grade_list.grade_name',
            'teacher_list.teacher_name',
            'syllabus_guide.title as syllabus_title',
        ]);
    }

    public function saveImplementationSheet(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertCurrentArrangementVisible($arrangementId);
        $values = [
            'arrangement_id' => $arrangementId,
            'teacher_id' => $this->optionalInt($request, 'teacher_id') ?? $this->currentTeacherId(false),
            'plan_ref_id' => $this->optionalInt($request, 'plan_ref_id'),
            'syllabus_ref_id' => $this->optionalInt($request, 'syllabus_ref_id'),
            'signed_count' => $this->optionalInt($request, 'signed_count') ?? 0,
            'unsigned_count' => $this->optionalInt($request, 'unsigned_count') ?? 0,
            'insurance_verified' => $this->enum($request, 'insurance_verified', ['false', 'true'], 'false'),
            'fee_detail' => $this->jsonValue($request->input('fee_detail', [])),
            'confirmed_at' => $this->dateTimeInput($request, 'confirmed_at'),
            'status' => $this->enum($request, 'status', ['draft', 'confirmed'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveWorkflowRow('implementation_sheet', 'implementation_sheet', 'implementation_sheet_recording', $request, $values, $this->workflowContent('保存教学实习实施表', $values, [
            'signed_count' => '已签承诺',
            'unsigned_count' => '未签承诺',
            'insurance_verified' => '保险核验',
            'confirmed_at' => '确认时间',
            'status' => '状态',
        ]));
    }

    public function teacherWorkReports(Request $request): array
    {
        return $this->documentList($request, 'teacher_work_report', [
            'teacher_work_report.*',
            'arrangement.title as arrangement_title',
            'arrangement.semester',
            'department.dep_name',
            'profession.profession_name',
            'profession.grade_id',
            'grade_list.grade_name',
            'teacher_list.teacher_name',
        ]);
    }

    public function saveTeacherWorkReport(Request $request): array
    {
        $this->requirePermission('internship:report');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertCurrentArrangementVisible($arrangementId);
        $values = [
            'arrangement_id' => $arrangementId,
            'teacher_id' => $this->isTeacher() ? $this->currentTeacherId(true) : $this->requiredInt($request, 'teacher_id'),
            'guidance_count' => $this->optionalInt($request, 'guidance_count') ?? 0,
            'summary' => $this->nullableString($request, 'summary', 10000),
            'problems' => $this->nullableString($request, 'problems', 10000),
            'suggestions' => $this->nullableString($request, 'suggestions', 10000),
            'attachment_id' => $this->optionalInt($request, 'attachment_id'),
            'status' => $this->enum($request, 'status', ['draft', 'wait', 'accept', 'modify'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveWorkflowRow('teacher_work_report', 'teacher_work_report', 'teacher_work_report_recording', $request, $values, $this->workflowContent('保存实习指导教师工作报告', $values, [
            'guidance_count' => '负责人数',
            'summary' => '工作总结',
            'problems' => '问题',
            'suggestions' => '建议',
            'status' => '状态',
        ]));
    }

    public function inspections(Request $request): array
    {
        $this->requirePermission('internship:archive');
        return InternshipRecord::inspectionPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'result',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveInspection(Request $request): array
    {
        $this->requirePermission('internship:archive');
        $this->requireAdminRole();
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $studentId = $this->optionalInt($request, 'student_id');
        $this->assertCurrentArrangementVisible($arrangementId);
        if ($studentId) {
            $this->assertStudentVisible($studentId);
            $this->assertTaskBindingVisible($studentId, $arrangementId);
        }
        $semester = $this->nullableString($request, 'semester', 80) ?? InternshipRecord::arrangementSemester($arrangementId);
        $values = [
            'semester' => $semester,
            'arrangement_id' => $arrangementId,
            'student_id' => $studentId,
            'inspector_id' => CurrentContext::accountId(),
            'items' => $this->jsonValue($request->input('items', [])),
            'result' => $this->enum($request, 'result', ['pass', 'fail'], 'pass'),
            'remark' => $this->nullableString($request, 'remark', 2000),
            'status' => 'enabled',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveWorkflowRow('inspection_record', 'inspection', 'inspection_recording', $request, $values, $this->workflowContent('保存实习巡查记录', $values, [
            'result' => '巡查结果',
            'remark' => '说明',
        ]));
    }

    private function reviewStudentWork(Request $request, string $table, string $recordingTable): array
    {
        $this->requirePermission('internship:approve');
        $id = $this->requiredRowId($request, $table);
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinionInput($request, $table, $status);
        $score = $this->decimalInput($request, 'score');
        $teacherId = $this->isTeacher() ? $this->currentTeacherId(true) : $this->optionalInt($request, 'teacher_id');
        $now = $this->now();
        $this->ensureRecordingTable($recordingTable);

        return $this->workflowLock('internship', $table, $id, function () use ($table, $recordingTable, $id, $status, $opinion, $score, $teacherId, $now): array {
            return $this->connection()->transaction(function () use ($table, $recordingTable, $id, $status, $opinion, $score, $teacherId, $now): array {
            $row = InternshipRecord::lockActiveRowById($table, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertStudentVisible((int) $row->student_id);
            $this->assertTaskBindingVisible((int) $row->student_id, $this->reviewWorkArrangementId($table, $row));
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            $from = (string) $row->status;
            $updates = [
                'status' => $status,
                'teacher_id' => $teacherId,
                'updated_at' => $now,
            ];
            if ($table === 'report') {
                $updates['reviewed_at'] = $now;
            }

            InternshipRecord::updateById($table, $id, $updates);
            $this->recordWorkflow($recordingTable, $table, $id, 'review', $from, $status, $opinion ?: '评阅处理', $status, $score, $teacherId);
            InternshipRecord::clearReviewOpinionDraft($table, $id, $this->accountId(), $now);
            $this->notifyWorkflowReviewed($table, $id, InternshipRecord::studentAccountId((int) $row->student_id), $this->entityDisplayName($table), $status, $opinion ?: '评阅处理');

            return ['id' => $id, 'status' => $status];
            });
        });
    }

    private function assertStudentWorkCanSubmit(?int $existingId, string $fromStatus, string $label): void
    {
        if (!$existingId) {
            return;
        }
        if (!in_array($fromStatus, ['draft', 'modify'], true)) {
            throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
        }
    }

    private function persistArrangement(array $input, string $source): array
    {
        $planId = (int) ($input['plan_id'] ?? 0);
        $teacherId = (int) ($input['teacher_id'] ?? 0);
        $classIds = $this->intArray($input['class_ids'] ?? []);
        $title = trim((string) ($input['title'] ?? ''));
        $startDate = (string) ($input['start_date'] ?? '');
        $endDate = (string) ($input['end_date'] ?? '');
        $taskNo = trim((string) ($input['task_no'] ?? ''));
        $existingId = (int) ($input['id'] ?? 0);

        if ($planId <= 0) {
            throw new InvalidArgumentException('请选择实习计划');
        }
        if ($teacherId <= 0) {
            throw new InvalidArgumentException('请选择负责老师');
        }
        if (!$classIds) {
            throw new InvalidArgumentException('请选择任务班级');
        }
        if ($title === '') {
            throw new InvalidArgumentException('任务标题不能为空');
        }
        if ($taskNo === '') {
            throw new InvalidArgumentException('任务编号不能为空');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new InvalidArgumentException('任务日期无效');
        }
        if (strtotime($endDate) < strtotime($startDate)) {
            throw new InvalidArgumentException('结束日期不能早于开始日期');
        }

        $scope = $this->scopeContext();
        $plan = InternshipRecord::planRowForTask($planId);
        if (!$plan || !InternshipRecord::planVisible($scope, $planId)) {
            throw new RuntimeException('实习计划不存在或无权限', 40301);
        }
        if (!in_array((string) ($plan->status ?? ''), ['accept', 'enabled'], true)) {
            throw new InvalidArgumentException('实习计划审核通过后才可拆分任务');
        }
        if ($existingId > 0) {
            $this->assertArrangementVisible($existingId);
        }
        if (!InternshipRecord::teacherVisible($scope, $teacherId)) {
            throw new RuntimeException('负责老师不存在或无权限', 40301);
        }

        $classRows = InternshipRecord::classRowsByIds($classIds, $scope);
        if (count($classRows) !== count($classIds)) {
            throw new RuntimeException('任务班级不存在或无权限', 40301);
        }
        foreach ($classRows as $classRow) {
            if ((int) ($classRow['grade_id'] ?? 0) !== (int) ($plan->grade_id ?? 0)
                || (int) ($classRow['dep_id'] ?? 0) !== (int) ($plan->dep_id ?? 0)
                || (int) ($classRow['profession_id'] ?? 0) !== (int) ($plan->profession_id ?? 0)) {
                throw new InvalidArgumentException('任务班级必须属于所选计划的届次、学院和专业');
            }
        }
        if (InternshipRecord::teacherTaskTimeConflictExists($teacherId, $startDate, $endDate, $existingId ?: null)) {
            throw new InvalidArgumentException('该老师在当前时间段已有任务，请调整时间或负责老师');
        }
        if (InternshipRecord::arrangementTaskNoExists($planId, $taskNo, $existingId ?: null)) {
            throw new InvalidArgumentException('同一实习计划下任务编号不能重复');
        }
        $this->ensureRecordingTable('arrangement_recording');

        $result = $this->connection()->transaction(function () use ($classRows, $endDate, $existingId, $input, $plan, $planId, $source, $startDate, $taskNo, $teacherId, $title): array {
            $now = $this->now();
            $studentRows = InternshipRecord::studentRowsByClassIds(array_column($classRows, 'class_id'));
            if (!$studentRows) {
                throw new InvalidArgumentException('所选任务班级暂无可绑定学生');
            }
            $studentCounts = [];
            foreach ($studentRows as $studentRow) {
                $classId = (int) ($studentRow['class_id'] ?? 0);
                $studentCounts[$classId] = ($studentCounts[$classId] ?? 0) + 1;
            }

            $before = $existingId > 0 ? InternshipRecord::arrangementDetail($this->scopeContext(), $existingId) : null;
            if (($before['item']['status'] ?? null) === 'changed') {
                throw new InvalidArgumentException('历史任务不可直接修改');
            }
            $values = [
                'plan_id' => $planId,
                'name' => trim((string) ($input['name'] ?? '')) ?: $title,
                'base_id' => $input['base_id'] ?? null,
                'dep_id' => (int) $plan->dep_id,
                'profession_id' => (int) $plan->profession_id,
                'semester' => (string) ($plan->semester ?? ''),
                'teacher_id' => $teacherId,
                'task_no' => $taskNo,
                'batch_no' => $input['batch_no'] ?? null,
                'credit' => $input['credit'] ?? ($plan->credit === null ? null : (float) $plan->credit),
                'student_count' => count($studentRows),
                'type' => $input['type'] ?? 'major_external',
                'organize_mode' => $input['organize_mode'] ?? 'centralized',
                'title' => $title,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'location' => $input['location'] ?? null,
                'description' => $input['description'] ?? null,
                'created_by' => CurrentContext::accountId(),
                'status' => $input['status'] ?? 'enabled',
                'updated_at' => $now,
                'deleted_at' => null,
            ];
            $bindingRowsChanged = $existingId > 0 && $before
                ? $this->arrangementBindingRowsChanged($before['classes'] ?? [], $classRows, 'class_id')
                    || $this->arrangementBindingRowsChanged($before['students'] ?? [], $studentRows, 'student_id')
                : false;
            $actualChange = $existingId > 0 && $this->arrangementValuesChanged($before['item'] ?? [], $values, $bindingRowsChanged);
            $previousStatus = (string) ($before['item']['status'] ?? 'draft');
            $requiresVersion = in_array($previousStatus, ['enabled', 'accept', 'wait', 'modify', 'changing'], true)
                || ($existingId > 0 && InternshipRecord::arrangementHasProcessData($existingId));
            $versionedChange = $actualChange && $requiresVersion;
            if ($versionedChange && trim((string) ($input['change_reason'] ?? '')) === '') {
                throw new InvalidArgumentException('已发布或已有过程数据的任务变更必须填写变更原因');
            }
            if ($versionedChange && empty($input['approved_change'])) {
                throw new InvalidArgumentException('已发布或已有过程数据的任务变更必须提交变更申请并审核通过后生效');
            }
            $targetId = $versionedChange ? 0 : $existingId;

            if ($targetId > 0) {
                InternshipRecord::updateById('arrangement', $targetId, $values);
                $arrangementId = $targetId;
                $uuid = (string) InternshipRecord::uuidById('arrangement', $targetId);
            } else {
                $uuid = $versionedChange ? $this->uuid() : ((string) ($input['uuid'] ?? '') ?: $this->uuid());
                $arrangementId = InternshipRecord::insertRow('arrangement', array_merge($values, [
                    'uuid' => $uuid,
                    'created_at' => $now,
                ]));
            }

            if ($versionedChange) {
                InternshipRecord::updateById('arrangement', $existingId, [
                    'status' => 'changed',
                    'updated_at' => $now,
                ]);
                InternshipRecord::closeArrangementActiveBindings($existingId, '任务变更生成新版本', $now);
                $this->recordWorkflow(
                    'arrangement_recording',
                    'arrangement',
                    $existingId,
                    'change',
                    $before['item']['status'] ?? 'enabled',
                    'changed',
                    $this->arrangementWorkflowContent($source, $before, null, (string) ($input['change_reason'] ?? '')) . '；已保留旧任务历史并生成新任务。',
                    'accept'
                );
            }

            InternshipRecord::syncTaskClasses($arrangementId, $classRows, $studentCounts, fn (): string => $this->uuid(), $now);
            $pairResult = InternshipRecord::syncTaskStudentPairs(
                $arrangementId,
                $teacherId,
                $input['enterprise_mentor_id'] ?? null,
                $studentRows,
                fn (): string => $this->uuid(),
                $now
            );
            $after = InternshipRecord::arrangementDetail($this->scopeContext(), $arrangementId);
            $this->recordWorkflow(
                'arrangement_recording',
                'arrangement',
                $arrangementId,
                $existingId > 0 ? ($versionedChange ? 'create_from_change' : 'change') : 'create',
                $versionedChange ? 'draft' : ($before['item']['status'] ?? 'draft'),
                (string) $values['status'],
                $this->arrangementWorkflowContent($source, $before, $after, (string) ($input['change_reason'] ?? '')),
                'accept'
            );
            return [
                'id' => $arrangementId,
                'uuid' => $uuid,
                'created' => $existingId <= 0 || $versionedChange,
                'versioned_change' => $versionedChange,
                'previous_id' => $versionedChange ? $existingId : null,
                'class_count' => count($classRows),
                'pair_count' => count($pairResult['pair_ids']),
                'student_count' => count($pairResult['student_ids']),
                'item' => InternshipRecord::activeRowById('arrangement', $arrangementId),
            ];
        });

        if (empty($input['suppress_notify'])) {
            $this->notifyArrangementPublished((int) $result['id'], $result['item'] ? (array) $result['item'] : [], (bool) $result['versioned_change']);
        }

        return $result;
    }

    private function importArrangementRow(array $row): array
    {
        if (!empty($row['invalid_message'])) {
            throw new InvalidArgumentException((string) $row['invalid_message']);
        }

        $scope = $this->scopeContext();
        $grade = InternshipRecord::gradeRowByName($this->requiredImportValue($row, 'grade_name', '届次'));
        if (!$grade) {
            throw new InvalidArgumentException('届次不存在');
        }
        $department = InternshipRecord::departmentRowByName($this->requiredImportValue($row, 'dep_name', '学院'), $scope);
        if (!$department) {
            throw new InvalidArgumentException('学院不存在或无权限');
        }
        $profession = InternshipRecord::professionRowByName(
            $this->requiredImportValue($row, 'profession_name', '专业'),
            (int) $grade['grade_id'],
            (int) $department['dep_id'],
            $scope
        );
        if (!$profession) {
            throw new InvalidArgumentException('专业不存在或不属于所选届次学院');
        }
        $teacher = InternshipRecord::teacherImportRow(
            trim((string) ($row['teacher_num'] ?? '')),
            trim((string) ($row['teacher_name'] ?? '')) ?: null,
            $scope
        );
        if (!$teacher) {
            throw new InvalidArgumentException('负责老师不存在或无权限');
        }

        $classNames = $this->splitImportList($this->requiredImportValue($row, 'class_names', '班级'));
        $classRows = InternshipRecord::classRowsByNames(
            (int) $grade['grade_id'],
            (int) $department['dep_id'],
            (int) $profession['profession_id'],
            $classNames,
            $scope
        );
        $foundClassNames = array_map(static fn (array $item): string => (string) ($item['class_name'] ?? ''), $classRows);
        $missingClassNames = array_values(array_diff($classNames, $foundClassNames));
        if ($missingClassNames) {
            throw new InvalidArgumentException('班级不存在：' . implode('、', $missingClassNames));
        }

        $credit = $this->decimalImportValue($row['credit'] ?? null);
        if ($credit === null) {
            throw new InvalidArgumentException('学分无效');
        }
        $startDate = $this->dateImportValue($row['start_date'] ?? null);
        $endDate = $this->dateImportValue($row['end_date'] ?? null);
        if (!$startDate || !$endDate) {
            throw new InvalidArgumentException('任务日期无效');
        }

        $planId = InternshipRecord::approvedPlanIdForImport([
            'course_code' => trim((string) ($row['course_code'] ?? '')),
            'course_name' => $this->requiredImportValue($row, 'course_name', '课程名称'),
            'grade_id' => (int) $grade['grade_id'],
            'dep_id' => (int) $department['dep_id'],
            'profession_id' => (int) $profession['profession_id'],
        ]);
        if ($planId <= 0) {
            throw new InvalidArgumentException('未找到已审核通过的实习计划，请先完成计划五级审核');
        }
        $plan = InternshipRecord::planRowForTask($planId);
        if (!$plan) {
            throw new InvalidArgumentException('实习计划不存在');
        }

        $taskNo = $this->requiredImportValue($row, 'task_no', '任务编号');
        $existingArrangementId = InternshipRecord::arrangementIdByPlanTaskNo($planId, $taskNo);
        $payload = [
            'id' => $existingArrangementId,
            'plan_id' => $planId,
            'teacher_id' => (int) $teacher['teacher_id'],
            'class_ids' => array_column($classRows, 'class_id'),
            'title' => $this->requiredImportValue($row, 'course_name', '课程名称') . ' ' . $taskNo,
            'name' => $this->requiredImportValue($row, 'course_name', '课程名称') . ' ' . $taskNo,
            'task_no' => $taskNo,
            'batch_no' => trim((string) ($row['batch_no'] ?? '')) ?: null,
            'credit' => $credit,
            'type' => $this->arrangementTypeImportValue($row['type'] ?? null),
            'organize_mode' => $this->organizeModeImportValue($row['organize_mode'] ?? null),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'location' => $this->requiredImportValue($row, 'location', '地点'),
            'description' => 'Excel 导入任务分配',
            'change_reason' => trim((string) ($row['change_reason'] ?? '')),
            'status' => 'enabled',
        ];
        if ($existingArrangementId <= 0) {
            return $this->persistArrangement($payload, 'Excel导入任务分配');
        }

        $detail = InternshipRecord::arrangementDetail($scope, $existingArrangementId);
        if (!$detail) {
            throw new RuntimeException('实习任务不存在或无权限', 40301);
        }
        $item = $detail['item'] ?? [];
        if (($item['status'] ?? '') === 'changed') {
            throw new InvalidArgumentException('历史任务不可导入调整');
        }
        $payload = $this->mergeExistingArrangementImportPayload($payload, $item);
        $studentRows = InternshipRecord::studentRowsByClassIds(array_column($classRows, 'class_id'));
        if (!$this->arrangementImportRequiresChangeReview($detail, $payload, $plan, $classRows, $studentRows)) {
            return $this->persistArrangement($payload, 'Excel导入任务分配');
        }
        if ($payload['change_reason'] === '') {
            throw new InvalidArgumentException('已发布或已有过程数据的任务导入调整必须填写变更原因');
        }

        $change = $this->submitArrangementChange(
            $existingArrangementId,
            $detail,
            $payload,
            $payload['change_reason'],
            'wait',
            null
        );

        return [
            'id' => $existingArrangementId,
            'created' => false,
            'change_submitted' => true,
            'change_id' => (int) $change['id'],
            'class_count' => count($classRows),
            'student_count' => count($studentRows),
        ];
    }

    private function mergeExistingArrangementImportPayload(array $payload, array $item): array
    {
        foreach (['base_id', 'enterprise_mentor_id', 'batch_no', 'location', 'description'] as $field) {
            if (($payload[$field] ?? null) === null || trim((string) ($payload[$field] ?? '')) === '') {
                $payload[$field] = $item[$field] ?? null;
            }
        }
        if (($payload['description'] ?? '') === 'Excel 导入任务分配') {
            $payload['description'] = $item['description'] ?? null;
        }

        return $payload;
    }

    private function arrangementImportRequiresChangeReview(array $detail, array $payload, object $plan, array $classRows, array $studentRows): bool
    {
        $bindingRowsChanged = $this->arrangementBindingRowsChanged($detail['classes'] ?? [], $classRows, 'class_id')
            || $this->arrangementBindingRowsChanged($detail['students'] ?? [], $studentRows, 'student_id');
        $values = [
            'plan_id' => (int) ($payload['plan_id'] ?? 0),
            'base_id' => $payload['base_id'] ?? null,
            'dep_id' => (int) ($plan->dep_id ?? 0),
            'profession_id' => (int) ($plan->profession_id ?? 0),
            'teacher_id' => (int) ($payload['teacher_id'] ?? 0),
            'task_no' => trim((string) ($payload['task_no'] ?? '')),
            'batch_no' => $payload['batch_no'] ?? null,
            'credit' => $payload['credit'] ?? ($plan->credit === null ? null : (float) $plan->credit),
            'type' => $payload['type'] ?? 'major_external',
            'organize_mode' => $payload['organize_mode'] ?? 'centralized',
            'title' => trim((string) ($payload['title'] ?? '')),
            'start_date' => (string) ($payload['start_date'] ?? ''),
            'end_date' => (string) ($payload['end_date'] ?? ''),
            'location' => $payload['location'] ?? null,
            'description' => $payload['description'] ?? null,
            'status' => $payload['status'] ?? 'enabled',
        ];
        if (!$this->arrangementValuesChanged($detail['item'] ?? [], $values, $bindingRowsChanged)) {
            return false;
        }

        $arrangementId = (int) ($detail['item']['id'] ?? 0);
        $previousStatus = (string) ($detail['item']['status'] ?? 'draft');

        return in_array($previousStatus, ['enabled', 'accept', 'wait', 'modify', 'changing'], true)
            || InternshipRecord::arrangementHasProcessData($arrangementId);
    }

    private function submitArrangementChange(int $arrangementId, array $detail, array $payload, string $reason, string $status, ?int $changeId): array
    {
        $item = $detail['item'] ?? [];
        $now = $this->now();
        $this->ensureRecordingTable('arrangement_change_recording');
        $this->ensureRecordingTable('arrangement_recording');

        $result = $this->connection()->transaction(function () use ($arrangementId, $changeId, $item, $now, $payload, $reason, $status): array {
            if ($status === 'wait') {
                $pendingId = InternshipRecord::pendingArrangementChangeId($arrangementId);
                if ($pendingId > 0 && $pendingId !== (int) $changeId) {
                    throw new InvalidArgumentException('该任务已有待审核变更，请先处理');
                }
            }
            if ($changeId) {
                $change = InternshipRecord::arrangementChangeRow($this->scopeContext(), $changeId);
                if (!$change) {
                    throw new RuntimeException('任务变更单不存在或无权限', 40301);
                }
                if (!in_array((string) $change->status, ['draft', 'modify'], true)) {
                    throw new InvalidArgumentException('仅草稿或退回的变更单可重新提交', 42204);
                }
            }

            $values = [
                'arrangement_id' => $arrangementId,
                'payload' => $this->jsonValue($payload),
                'reason' => $reason,
                'from_status' => (string) ($item['status'] ?? 'enabled'),
                'submitter_id' => CurrentContext::accountId(),
                'submitted_at' => $status === 'wait' ? $now : null,
                'reviewer_id' => null,
                'review_opinion' => null,
                'reviewed_at' => null,
                'new_arrangement_id' => null,
                'status' => $status,
                'updated_at' => $now,
                'deleted_at' => null,
            ];
            if ($changeId) {
                InternshipRecord::updateById('arrangement_change', $changeId, $values);
                $result = ['id' => $changeId];
            } else {
                $result = [
                    'id' => InternshipRecord::insertRow('arrangement_change', array_merge($values, [
                        'uuid' => $this->uuid(),
                        'created_at' => $now,
                    ])),
                ];
            }
            if ($status === 'wait') {
                InternshipRecord::updateById('arrangement', $arrangementId, [
                    'status' => 'changing',
                    'updated_at' => $now,
                ]);
                $this->recordWorkflow(
                    'arrangement_change_recording',
                    'arrangement_change',
                    (int) $result['id'],
                    'submit',
                    (string) ($item['status'] ?? 'enabled'),
                    'wait',
                    $this->arrangementChangeContent($payload, $reason),
                    'wait'
                );
                $this->recordWorkflow(
                    'arrangement_recording',
                    'arrangement',
                    $arrangementId,
                    'change_submit',
                    (string) ($item['status'] ?? 'enabled'),
                    'changing',
                    '发起任务变更：' . $reason,
                    'wait'
                );
            }

            return ['id' => (int) $result['id'], 'status' => $status];
        });
        if ($status === 'wait') {
            $this->notifyArrangementChangeSubmitted($arrangementId, (int) $result['id'], $item, $reason);
        }

        return $result;
    }

    private function arrangementValuesChanged(array $before, array $values, bool $studentRowsChanged): bool
    {
        if ($studentRowsChanged) {
            return true;
        }

        foreach ([
            'plan_id',
            'base_id',
            'dep_id',
            'profession_id',
            'teacher_id',
            'task_no',
            'batch_no',
            'credit',
            'type',
            'organize_mode',
            'title',
            'start_date',
            'end_date',
            'location',
            'description',
            'status',
        ] as $field) {
            if ($this->normalizedCompareValue($before[$field] ?? null) !== $this->normalizedCompareValue($values[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function arrangementBindingRowsChanged(array $beforeRows, array $afterRows, string $key): bool
    {
        $beforeIds = $this->idsFromRows($beforeRows, $key);
        $afterIds = $this->idsFromRows($afterRows, $key);

        return $beforeIds !== $afterIds;
    }

    private function normalizedCompareValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value) || is_int($value) || is_numeric($value)) {
            return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
        }

        return trim((string) $value);
    }

    private function idsFromRows(array $rows, string $key): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$key] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function arrangementWorkflowContent(string $source, ?array $before, ?array $after, string $reason = ''): string
    {
        $afterItem = $after['item'] ?? [];
        if (!$before) {
            $text = sprintf(
                '%s：%s，负责老师 %s，绑定 %d 个班级、%d 名学生。',
                $source,
                $afterItem['title'] ?? '-',
                $afterItem['teacher_name'] ?? '-',
                count($after['classes'] ?? []),
                count($after['students'] ?? [])
            );

            return trim($reason) === '' ? $text : $text . '原因：' . trim($reason);
        }

        $beforeItem = $before['item'] ?? [];
        if (!$after) {
            $title = trim((string) ($beforeItem['title'] ?? ''));
            $content = $source . '：原任务' . ($title !== '' ? '「' . $title . '」' : '') . '已归档为历史版本';
            return trim($reason) === '' ? $content : $content . '；原因：' . trim($reason);
        }

        $changes = [];
        foreach ([
            'teacher_name' => '负责老师',
            'start_date' => '开始日期',
            'end_date' => '结束日期',
            'location' => '地点',
            'credit' => '学分',
            'student_count' => '学生人数',
            'status' => '状态',
        ] as $field => $label) {
            $old = (string) ($beforeItem[$field] ?? '');
            $new = (string) ($afterItem[$field] ?? '');
            if ($old !== $new) {
                $changes[] = "{$label}：{$old} -> {$new}";
            }
        }
        $beforeClasses = array_map(static fn (array $item): string => (string) ($item['class_name'] ?? ''), $before['classes'] ?? []);
        $afterClasses = array_map(static fn (array $item): string => (string) ($item['class_name'] ?? ''), $after['classes'] ?? []);
        if ($beforeClasses !== $afterClasses) {
            $changes[] = '班级：' . (implode('、', $beforeClasses) ?: '-') . ' -> ' . (implode('、', $afterClasses) ?: '-');
        }

        $content = $source . '：' . ($changes ? implode('；', $changes) : '任务信息未发生实质变化');
        return trim($reason) === '' ? $content : $content . '；原因：' . trim($reason);
    }

    private function arrangementPayloadFromRequest(Request $request, array $detail): array
    {
        $item = $detail['item'] ?? [];
        $planId = $this->optionalInt($request, 'plan_id') ?: (int) ($item['plan_id'] ?? 0);
        $teacherId = $this->optionalInt($request, 'teacher_id') ?: (int) ($item['teacher_id'] ?? 0);
        $classIds = $this->intArray($request->input('class_ids', []));
        if (!$classIds) {
            $classIds = $this->idsFromRows($detail['classes'] ?? [], 'class_id');
        }

        $payload = [
            'plan_id' => $planId,
            'teacher_id' => $teacherId,
            'class_ids' => $classIds,
            'title' => $this->stringInput($request, 'title', 180) ?: (string) ($item['title'] ?? $item['name'] ?? ''),
            'name' => $this->stringInput($request, 'name', 180) ?: ($this->stringInput($request, 'title', 180) ?: (string) ($item['name'] ?? $item['title'] ?? '')),
            'base_id' => $this->optionalInt($request, 'base_id') ?? ($item['base_id'] ?? null),
            'enterprise_mentor_id' => $this->optionalInt($request, 'enterprise_mentor_id'),
            'task_no' => $this->nullableString($request, 'task_no', 80) ?? ($item['task_no'] ?? null),
            'batch_no' => $this->nullableString($request, 'batch_no', 80) ?? ($item['batch_no'] ?? null),
            'credit' => $this->decimalInput($request, 'credit') ?? ($item['credit'] ?? null),
            'type' => $this->enum($request, 'type', self::ARRANGEMENT_TYPES, (string) ($item['type'] ?? 'major_external')),
            'organize_mode' => $this->enum($request, 'organize_mode', self::ORGANIZE_MODES, (string) ($item['organize_mode'] ?? 'centralized')),
            'start_date' => $this->dateInput($request, 'start_date') ?: (string) ($item['start_date'] ?? ''),
            'end_date' => $this->dateInput($request, 'end_date') ?: (string) ($item['end_date'] ?? ''),
            'location' => $this->nullableString($request, 'location', 255) ?? ($item['location'] ?? null),
            'description' => $this->nullableString($request, 'description', 2000) ?? ($item['description'] ?? null),
            'status' => 'enabled',
        ];
        $this->validateArrangementChangePayload($payload);

        return $payload;
    }

    private function validateArrangementChangePayload(array $payload): void
    {
        if ((int) ($payload['plan_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('请选择实习计划');
        }
        if ((int) ($payload['teacher_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('请选择负责老师');
        }
        if (!$this->intArray($payload['class_ids'] ?? [])) {
            throw new InvalidArgumentException('请选择任务班级');
        }
        if (trim((string) ($payload['title'] ?? '')) === '') {
            throw new InvalidArgumentException('任务标题不能为空');
        }
        if (trim((string) ($payload['task_no'] ?? '')) === '') {
            throw new InvalidArgumentException('任务编号不能为空');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($payload['start_date'] ?? ''))) {
            throw new InvalidArgumentException('开始日期无效');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($payload['end_date'] ?? ''))) {
            throw new InvalidArgumentException('结束日期无效');
        }
    }

    private function arrangementChangePayload(object $change): array
    {
        $payload = $change->payload ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['class_ids'] = $this->intArray($payload['class_ids'] ?? []);
        $this->validateArrangementChangePayload($payload);

        return $payload;
    }

    private function arrangementChangeContent(array $payload, string $reason): string
    {
        $parts = array_filter([
            '任务：' . (string) ($payload['title'] ?? ''),
            '时间：' . (string) ($payload['start_date'] ?? '') . ' 至 ' . (string) ($payload['end_date'] ?? ''),
            '班级数：' . count($this->intArray($payload['class_ids'] ?? [])),
            '原因：' . trim($reason),
        ], static fn (string $value): bool => trim($value) !== '');

        return implode('；', $parts);
    }

    private function documentList(Request $request, string $table, array $columns): array
    {
        $this->requirePermission('internship:view');
        return InternshipRecord::documentPage($table, $columns, $this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'status',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    private function application(int $id): array
    {
        $item = InternshipRecord::applicationWithTeachers($id);
        if (!$item) {
            throw new RuntimeException('特殊申请不存在');
        }

        return $item;
    }

    private function syncJoinTeachers(int $applicationId, int $studentId, int $arrangementId, array $teacherIds): void
    {
        $student = InternshipRecord::studentProfile($studentId);
        if (!$teacherIds) {
            return;
        }

        foreach (array_slice(array_values(array_unique($teacherIds)), 0, 3) as $teacherId) {
            $teacher = InternshipRecord::teacherProfile($teacherId);
            if (!$teacher) {
                continue;
            }

            if (InternshipRecord::joinTeacherExists($applicationId, $teacherId)) {
                continue;
            }

            InternshipRecord::insertJoinTeacher([
                'uuid' => $this->uuid(),
                'student_id' => $studentId,
                'teacher_id' => $teacherId,
                'application_id' => $applicationId,
                'arrangement_id' => $arrangementId,
                'application_type' => 'internship',
                'application_status' => 'applying',
                'student_name' => $student->name ?? null,
                'student_num' => $student->student_num ?? null,
                'teacher_name' => $teacher->teacher_name ?? null,
                'status' => 'enabled',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }
    }

    private function reviewJoinTeacher(int $applicationId, string $status): void
    {
        if (!in_array($status, ['accept', 'modify'], true)) {
            return;
        }

        $teacherId = $this->currentTeacherId(true);
        $joinStatus = $status === 'accept' ? 'accept' : 'refuse';
        InternshipRecord::updateJoinTeacherStatus($applicationId, $teacherId, $joinStatus, $this->now());
    }

    private function refreshApplicationFinalStatus(object $application): string
    {
        $teacherOk = in_array((string) $application->teacher_status, ['accept', 'skipped'], true);
        $adminOk = (string) $application->admin_status === 'accept';
        if (!$teacherOk || !$adminOk) {
            return (string) $application->status;
        }

        InternshipRecord::updateById('application', (int) $application->id, ['status' => 'accept', 'updated_at' => $this->now()]);

        return 'accept';
    }

    private function upsertActivePair(array $values): int
    {
        return InternshipRecord::upsertActivePair($values, $this->uuid(), $this->now());
    }

    private function saveRow(string $table, Request $request, array $values, array $unique = []): array
    {
        $id = $this->optionalInt($request, 'id');
        $uuid = $this->nullableString($request, 'uuid', 36);
        $now = $this->now();

        if (!$id && $uuid) {
            $id = InternshipRecord::idByUuid($table, $uuid);
        }
        if (!$id && $unique) {
            $id = InternshipRecord::activeIdByFields($table, $unique);
        }

        if ($id) {
            InternshipRecord::updateById($table, $id, $values);
            return ['id' => $id, 'uuid' => $uuid ?: (string) InternshipRecord::uuidById($table, $id)];
        }

        $uuid = $uuid ?: $this->uuid();
        $id = InternshipRecord::insertRow($table, array_merge($values, [
            'uuid' => $uuid,
            'created_at' => $now,
        ]));

        return ['id' => $id, 'uuid' => $uuid];
    }

    private function saveWorkflowRow(string $table, string $entity, string $recordingTable, Request $request, array $values, string $content, array $unique = []): array
    {
        $existingId = $this->inputRowId($request, $table);
        if (!$existingId && $unique) {
            $existingId = InternshipRecord::activeIdByFields($table, $unique);
        }
        $fromStatus = $existingId ? InternshipRecord::statusById($table, $existingId) : 'draft';
        $result = $this->saveRow($table, $request, $values, $unique);
        $toStatus = (string) ($values['status'] ?? 'enabled');
        $this->recordWorkflow($recordingTable, $entity, (int) $result['id'], $existingId ? 'change' : 'submit', $fromStatus, $toStatus, $content, $toStatus);
        $this->notifyWorkflowSubmittedOnWait($entity, (int) $result['id'], $values, (string) $fromStatus, $toStatus);

        return $result;
    }

    private function notifyArrangementChangeReviewed(object $change, object $arrangement, string $status, ?string $opinion, ?int $newArrangementId): void
    {
        $titles = [
            'accept' => '实习任务变更已通过',
            'modify' => '实习任务变更需修改',
            'refuse' => '实习任务变更未通过',
        ];
        $title = $titles[$status] ?? '实习任务变更已处理';
        $taskTitle = trim((string) ($arrangement->title ?? $arrangement->name ?? ''));
        $parts = array_filter([
            $taskTitle !== '' ? '任务：' . $taskTitle : '任务 ID：' . (int) ($change->arrangement_id ?? 0),
            $newArrangementId ? '新任务 ID：' . $newArrangementId : null,
            $opinion ? '意见：' . $opinion : null,
        ]);

        $this->notifyTemplateAccounts(
            [(int) ($change->submitter_id ?? 0)],
            'internship_arrangement_change_review_result',
            [
                'entity_title' => $taskTitle !== '' ? $taskTitle : '实习任务变更#' . (int) ($change->id ?? 0),
                'status_text' => $this->statusText($status),
                'opinion_text' => implode('；', $parts) ?: $title,
            ],
            'arrangement_change',
            (int) ($change->id ?? 0),
            CurrentContext::accountId() ?: 0
        );
    }

    private function notifyArrangementChangeSubmitted(int $arrangementId, int $changeId, array $item, string $reason): void
    {
        $accountIds = array_merge(
            Account::messageTargetIds(['role_type' => 'school_admin']),
            Account::messageTargetIds(['role_type' => 'college_admin'])
        );
        $title = trim((string) ($item['title'] ?? $item['name'] ?? '')) ?: '实习任务#' . $arrangementId;
        $this->notifyTemplateAccounts($accountIds, 'internship_arrangement_change_submit_todo', [
            'submitter_name' => $this->currentAccountName(),
            'entity_title' => $title . ($reason !== '' ? '；原因：' . $reason : ''),
        ], 'arrangement_change', $changeId, CurrentContext::accountId() ?: 0);
    }

    private function notifyPlanReviewed(object $row, int $planId, string $planStatus, string $levelName, ?string $opinion): void
    {
        if (!in_array($planStatus, ['accept', 'modify'], true)) {
            return;
        }

        $title = $planStatus === 'accept' ? '实习计划已审核通过' : '实习计划需修改';
        $course = trim((string) ($row->course_name ?? ''));
        $courseCode = trim((string) ($row->course_code ?? ''));
        $parts = array_filter([
            $course !== '' ? '课程：' . $course : '计划 ID：' . $planId,
            $courseCode !== '' ? '课程代码：' . $courseCode : null,
            '审核节点：' . $levelName,
            $opinion ? '意见：' . $opinion : null,
        ]);

        $this->notifyTemplateAccounts([(int) ($row->submitter_id ?? 0)], 'internship_plan_review_result', [
            'entity_title' => $course !== '' ? $course : '实习计划#' . $planId,
            'status_text' => $this->statusText($planStatus),
            'opinion_text' => implode('；', $parts) ?: $title,
        ], 'plan', $planId, CurrentContext::accountId() ?: 0);
    }

    private function notifyArrangementPublished(int $arrangementId, array $item, bool $changed): void
    {
        $teacherId = (int) ($item['teacher_id'] ?? 0);
        $accountIds = array_merge(
            [$this->teacherAccountId($teacherId)],
            InternshipRecord::arrangementStudentAccountIds($arrangementId)
        );
        $title = $changed ? '实习任务变更已生效' : '实习任务已发布';
        $taskTitle = trim((string) ($item['title'] ?? ''));
        $startDate = trim((string) ($item['start_date'] ?? ''));
        $endDate = trim((string) ($item['end_date'] ?? ''));
        $dateText = $startDate !== '' && $endDate !== '' ? $startDate . ' 至 ' . $endDate : ($startDate ?: $endDate);
        $location = trim((string) ($item['location'] ?? ''));
        $this->notifyTemplateAccounts($accountIds, 'internship_task_publish', [
            'task_title' => $taskTitle !== '' ? $taskTitle : '实习任务#' . $arrangementId,
            'date_text' => $dateText !== '' ? $dateText : '待确认',
            'location' => $location !== '' ? $location : '待确认',
        ], 'arrangement', $arrangementId, 0);
    }

    private function notifyWorkflowSubmittedOnWait(string $entity, int $entityId, array $values, string $fromStatus, string $toStatus): void
    {
        if ($toStatus !== 'wait' || $fromStatus === 'wait') {
            return;
        }

        $accountIds = $this->workflowSubmitReceiverAccountIds($entity, $values);
        $moduleName = $this->entityDisplayName($entity);
        $this->notifyTemplateAccounts($accountIds, $this->workflowTemplateCode($entity, 'submit'), [
            'module_name' => $moduleName,
            'submitter_name' => $this->currentAccountName(),
            'entity_title' => $this->workflowEntityTitle($entity, $entityId, $values),
            'module_key' => 'internship',
            'panel_key' => $this->entityPanelKey($entity),
        ], $entity, $entityId, CurrentContext::accountId() ?: 0);
    }

    private function workflowSubmitReceiverAccountIds(string $entity, array $values): array
    {
        $accountIds = [];
        $studentId = (int) ($values['student_id'] ?? 0);
        $arrangementId = (int) ($values['arrangement_id'] ?? $values['entity_id'] ?? 0);
        if ($studentId > 0 && $arrangementId > 0) {
            foreach (InternshipRecord::activePairTeacherIds($studentId, $arrangementId) as $teacherId) {
                $accountIds[] = InternshipRecord::teacherAccountId((int) $teacherId);
            }
        }

        if (!$accountIds || in_array($entity, ['plan', 'syllabus_guide', 'implementation_sheet', 'teacher_work_report', 'inspection'], true)) {
            $accountIds = array_merge(
                $accountIds,
                Account::messageTargetIds(['role_type' => 'school_admin']),
                Account::messageTargetIds(['role_type' => 'college_admin'])
            );
        }

        return array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
    }

    private function workflowEntityTitle(string $entity, int $entityId, array $values): string
    {
        foreach (['title', 'course_name', 'reason', 'summary', 'remark'] as $key) {
            $value = trim((string) ($values[$key] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return $this->entityDisplayName($entity) . '#' . $entityId;
    }

    private function notifyWorkflowSubmitted(string $entity, int $entityId, int $submitterAccountId, array $teacherIds, string $moduleName, string $entityTitle): void
    {
        $accountIds = [];
        foreach ($teacherIds as $teacherId) {
            $accountIds[] = InternshipRecord::teacherAccountId((int) $teacherId);
        }
        if ($entity === 'plan') {
            $accountIds = array_merge($accountIds, Account::messageTargetIds(['role_type' => 'school_admin']));
            $accountIds = array_merge($accountIds, Account::messageTargetIds(['role_type' => 'college_admin']));
        }

        $this->notifyTemplateAccounts($accountIds, $this->workflowTemplateCode($entity, 'submit'), [
            'module_name' => $moduleName,
            'submitter_name' => $this->currentAccountName(),
            'entity_title' => $entityTitle !== '' ? $entityTitle : $moduleName,
            'module_key' => 'internship',
            'panel_key' => $this->entityPanelKey($entity),
        ], $entity, $entityId, $submitterAccountId);
    }

    private function notifyPlanNextApproval(object $row, int $planId, int $nextLevel, ?string $opinion): void
    {
        $level = self::PLAN_APPROVAL_LEVELS[$nextLevel] ?? null;
        if (!$level) {
            return;
        }

        $accountIds = [];
        foreach ($level['role_types'] ?? [] as $roleType) {
            $accountIds = array_merge($accountIds, Account::messageTargetIds(['role_type' => $roleType]));
        }

        $course = trim((string) ($row->course_name ?? ''));
        $levelName = (string) ($level['name'] ?? '下一审核节点');
        $title = $course !== '' ? $course . ' - ' . $levelName : '实习计划#' . $planId . ' - ' . $levelName;
        $this->notifyTemplateAccounts($accountIds, 'internship_plan_submit_todo', [
            'module_name' => '实习计划',
            'submitter_name' => $this->currentAccountName(),
            'entity_title' => $title . ($opinion ? '；上一节点意见：' . $opinion : ''),
            'module_key' => 'internship',
            'panel_key' => 'plans',
        ], 'plan', $planId, CurrentContext::accountId() ?: 0);
    }

    private function notifyWorkflowReviewed(string $entity, int $entityId, ?int $receiverAccountId, string $moduleName, string $status, string $opinion): void
    {
        $this->notifyTemplateAccounts([$receiverAccountId], $this->workflowTemplateCode($entity, 'review'), [
            'module_name' => $moduleName,
            'status_text' => $this->statusText($status),
            'opinion_text' => $opinion,
            'entity_title' => $moduleName . '#' . $entityId,
            'module_key' => 'internship',
            'panel_key' => $this->entityPanelKey($entity),
        ], $entity, $entityId, CurrentContext::accountId() ?: 0);
    }

    private function notifyWorkflowReopened(string $entity, int $entityId, ?int $receiverAccountId, string $moduleName, string $opinion): void
    {
        $this->notifyTemplateAccounts([$receiverAccountId], $this->workflowTemplateCode($entity, 'reopen'), [
            'module_name' => $moduleName,
            'reviewer_name' => $this->currentAccountName(),
            'entity_title' => $moduleName . '#' . $entityId,
            'opinion_text' => $opinion,
            'module_key' => 'internship',
            'panel_key' => $this->entityPanelKey($entity),
        ], $entity, $entityId, CurrentContext::accountId() ?: 0);
    }

    private function notifyTemplateAccounts(array $accountIds, string $templateCode, array $variables, string $entityType, int $entityId, int $senderId): void
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
            ], $senderId, $this->currentAccountName());
        } catch (Throwable) {
        }
    }

    private function workflowTemplateCode(string $entity, string $action): string
    {
        $map = [
            'application' => 'internship_application',
            'journal' => 'internship_journal',
            'report' => 'internship_report',
            'delay' => 'internship_delay',
            'plan' => 'internship_plan',
            'insurance' => 'internship_insurance',
            'safety_letter' => 'internship_safety_letter',
            'syllabus_guide' => 'internship_syllabus_guide',
            'implementation_sheet' => 'internship_implementation_sheet',
            'teacher_work_report' => 'internship_teacher_work_report',
            'inspection' => 'internship_inspection',
            'base_application' => 'internship_base_application',
            'base_usage' => 'internship_base_usage',
            'base_result' => 'internship_base_result',
            'base_expense' => 'internship_base_expense',
        ];
        $suffix = [
            'submit' => 'submit_todo',
            'review' => 'review_result',
            'reopen' => 'reopen_todo',
        ][$action] ?? '';

        return isset($map[$entity], $suffix) && $suffix !== ''
            ? $map[$entity] . '_' . $suffix
            : match ($action) {
                'review' => 'workflow_review_result',
                'reopen' => 'workflow_reopen_todo',
                default => 'workflow_submit_todo',
            };
    }

    private function reviewEntitySubmitterAccountId(string $entity, object $row): ?int
    {
        if (isset($row->student_id)) {
            return InternshipRecord::studentAccountId((int) $row->student_id);
        }
        if (isset($row->submitter_id)) {
            return (int) $row->submitter_id ?: null;
        }

        return null;
    }

    private function entityDisplayName(string $entity): string
    {
        return [
            'application' => '特殊申请',
            'journal' => '实习日志',
            'report' => '实习报告',
            'delay' => '延期申请',
            'plan' => '实习计划',
            'insurance' => '保险记录',
            'safety_letter' => '安全承诺',
            'syllabus_guide' => '实习大纲指导书',
            'implementation_sheet' => '教学实习实施表',
            'teacher_work_report' => '指导教师工作报告',
            'inspection' => '实习巡查记录',
            'base_application' => '基地申报',
            'base_usage' => '基地使用',
            'base_result' => '基地成果',
            'base_expense' => '基地费用',
        ][$entity] ?? $entity;
    }

    private function entityPanelKey(string $entity): string
    {
        return [
            'application' => 'applications',
            'journal' => 'journals',
            'report' => 'reports',
            'delay' => 'delays',
            'plan' => 'plans',
            'insurance' => 'insurances',
            'safety_letter' => 'safetyLetters',
            'syllabus_guide' => 'syllabusGuides',
            'implementation_sheet' => 'implementationSheets',
            'teacher_work_report' => 'teacherWorkReports',
            'inspection' => 'inspections',
            'base_application' => 'baseFlows',
            'base_usage' => 'baseFlows',
            'base_result' => 'baseFlows',
            'base_expense' => 'baseFlows',
        ][$entity] ?? 'overview';
    }

    private function statusText(string $status): string
    {
        return [
            'accept' => '通过',
            'modify' => '退回修改',
            'refuse' => '未通过',
            'wait' => '待审核',
            'skipped' => '跳过',
        ][$status] ?? $status;
    }

    private function currentAccountName(): string
    {
        return (string) (CurrentContext::get('user_name') ?: CurrentContext::get('login_name') ?: '系统');
    }

    private function scopeContext(): array
    {
        $roleType = CurrentContext::roleType();
        $depIds = $this->scopeIds('dep_id');
        $professionIds = $this->scopeIds('profession_id');
        $companyIds = $this->scopeIds('company_id');

        return [
            'role_type' => $roleType,
            'dep_ids' => $depIds,
            'profession_ids' => $professionIds,
            'profession_dep_ids' => $this->professionDepIds($professionIds),
            'company_ids' => $companyIds,
            'teacher_id' => $this->currentTeacherId(false),
            'student_id' => $this->currentStudentId(false),
            'visible_student_ids' => $this->visibleStudentIds(),
            'visible_arrangement_ids' => match ($roleType) {
                'teacher' => $this->teacherArrangementIds(),
                'student' => $this->studentVisibleArrangementIds(),
                default => [],
            },
            'owned_arrangement_ids' => match ($roleType) {
                'student' => $this->studentArrangementIds(),
                'enterprise' => $this->enterpriseArrangementIds(),
                default => [],
            },
            'base_ids' => $roleType === 'enterprise' ? $this->enterpriseBaseIds() : [],
        ];
    }

    private function requestFilters(Request $request, array $keys): array
    {
        $filters = [];
        foreach ($keys as $key) {
            $filters[$key] = $request->input($key);
        }

        return $filters;
    }

    private function professionDepIds(array $professionIds): array
    {
        return InternshipRecord::depIdsByProfessionIds($professionIds);
    }

    private function visibleStudentIds(): ?array
    {
        $roleType = CurrentContext::roleType();
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return null;
        }
        if ($roleType === 'college_admin') {
            return InternshipRecord::studentIdsByDepartments($this->scopeIds('dep_id'));
        }
        if ($roleType === 'profession_admin') {
            return InternshipRecord::studentIdsByProfessions($this->scopeIds('profession_id'));
        }
        if ($roleType === 'teacher') {
            return InternshipRecord::studentIdsByTeacher((int) $this->currentTeacherId(false));
        }
        if ($roleType === 'student') {
            $studentId = $this->currentStudentId(false);
            return $studentId ? [$studentId] : [];
        }

        return [];
    }

    private function teacherArrangementIds(): array
    {
        $teacherId = $this->currentTeacherId(false);
        if (!$teacherId) {
            return [];
        }

        return InternshipRecord::arrangementIdsByTeacher($teacherId);
    }

    private function studentArrangementIds(): array
    {
        $studentId = $this->currentStudentId(false);
        if (!$studentId) {
            return [];
        }

        return InternshipRecord::arrangementIdsByStudent($studentId);
    }

    private function studentVisibleArrangementIds(): array
    {
        $studentId = $this->currentStudentId(false);
        if (!$studentId) {
            return [];
        }

        return InternshipRecord::visibleArrangementIdsByStudent($studentId);
    }

    private function enterpriseBaseIds(): array
    {
        return InternshipRecord::baseIdsByCompanies($this->scopeIds('company_id'));
    }

    private function enterpriseArrangementIds(): array
    {
        return InternshipRecord::arrangementIdsByBases($this->enterpriseBaseIds());
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

    private function assertStudentVisible(int $studentId): void
    {
        $ids = $this->visibleStudentIds();
        if ($ids !== null && !in_array($studentId, $ids, true)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }
    }

    private function assertArrangementVisible(int $arrangementId): void
    {
        if (!InternshipRecord::arrangementVisible($this->scopeContext(), $arrangementId)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }
    }

    private function assertCurrentArrangementVisible(int $arrangementId): void
    {
        if (!InternshipRecord::currentArrangementVisible($this->scopeContext(), $arrangementId)) {
            throw new RuntimeException('实习任务不存在、已变更或无数据访问权限', 40301);
        }
    }

    private function assertTaskBindingVisible(int $studentId, int $arrangementId): void
    {
        if (!InternshipRecord::taskBindingVisible($this->scopeContext(), $studentId, $arrangementId)) {
            throw new RuntimeException('学生未绑定该实习任务或无数据访问权限', 40301);
        }
    }

    private function assertStudentStartPrerequisites(int $studentId, int $arrangementId, ?string $date = null): void
    {
        $state = InternshipRecord::studentStartPrerequisiteState($studentId, $arrangementId, $date);
        $missing = $state['missing'] ?? [];
        if (empty($state['required']) || !$missing) {
            return;
        }

        $labels = [
            'insurance' => '有效保险记录',
            'safety_letter' => '已签署安全承诺书',
        ];
        $names = array_map(static fn (string $key): string => $labels[$key] ?? $key, $missing);
        throw new InvalidArgumentException('校外实习开始前需完成：' . implode('、', $names), 42207);
    }

    private function assertStudentDeadlineOpen(int $studentId, string $configKey, string $label): void
    {
        $deadline = $this->studentDeadline($studentId, $configKey);
        if ($deadline === null || $deadline >= date('Y-m-d')) {
            return;
        }

        throw new InvalidArgumentException($label . '已超过截止日期 ' . $deadline . '，请先申请延期', 42208);
    }

    private function studentDeadline(int $studentId, string $configKey): ?string
    {
        $studentUserId = InternshipRecord::studentUserId($studentId);
        $value = (string) ((new ConfigService())->get('internship.' . $configKey, 0, (int) ($studentUserId ?: 0)) ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function assertApplicationVisible(int $applicationId): void
    {
        if (!InternshipRecord::applicationVisible($this->scopeContext(), $applicationId)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }
    }

    private function assertPlanVisible(int $planId): void
    {
        if (!InternshipRecord::planVisible($this->scopeContext(), $planId)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }
    }

    private function assertPlanApproverAllowed(int $level, array $approvedRecordsByLevel): void
    {
        $config = self::PLAN_APPROVAL_LEVELS[$level] ?? null;
        if (!$config) {
            throw new InvalidArgumentException('实习计划审核节点无效', 42205);
        }

        $roleType = CurrentContext::roleType();
        $allowedRoles = $config['role_types'] ?? [];
        if (!in_array($roleType, ['super_admin', 'school_admin'], true) && !in_array($roleType, $allowedRoles, true)) {
            throw new RuntimeException('当前角色不能处理该审核节点', 40300);
        }

        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return;
        }

        $accountId = CurrentContext::accountId();
        foreach (self::PLAN_APPROVER_DISTINCT_LEVELS[$level] ?? [] as $distinctLevel) {
            $record = $approvedRecordsByLevel[$distinctLevel] ?? null;
            if ($record && (int) ($record['approver_id'] ?? 0) === (int) $accountId) {
                throw new InvalidArgumentException('同一审批人不能处理该计划的相邻或互斥审核节点', 42206);
            }
        }
    }

    private function assertDepartmentVisible(int $depId): void
    {
        if (!InternshipRecord::departmentVisible($this->scopeContext(), $depId)) {
            throw new RuntimeException('无数据访问权限', 40301);
        }
    }

    private function requirePermission(string $code): void
    {
        $this->accountId();
        if (!in_array($code, CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    private function requireAnyPermission(array $codes): void
    {
        $this->accountId();
        $permissions = CurrentContext::permissionCodes();
        foreach ($codes as $code) {
            if (in_array($code, $permissions, true)) {
                return;
            }
        }

        throw new RuntimeException('无操作权限', 40300);
    }

    private function requireAdminRole(): void
    {
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLE_TYPES, true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    private function canScoreWithoutManage(): bool
    {
        return $this->isTeacher() || $this->isEnterprise();
    }

    private function scoreTeacherId(Request $request, int $arrangementId): ?int
    {
        if ($this->isTeacher()) {
            return $this->currentTeacherId(true);
        }

        $teacherId = $this->optionalInt($request, 'teacher_id') ?: InternshipRecord::arrangementTeacherId($arrangementId);
        return $teacherId > 0 ? $teacherId : null;
    }

    private function isTeacher(): bool
    {
        return CurrentContext::roleType() === 'teacher';
    }

    private function isStudent(): bool
    {
        return CurrentContext::roleType() === 'student';
    }

    private function isEnterprise(): bool
    {
        return CurrentContext::roleType() === 'enterprise';
    }

    private function currentTeacherId(bool $required): ?int
    {
        $teacherId = InternshipRecord::teacherIdByUser((int) CurrentContext::userId());
        if (!$teacherId && $required) {
            throw new RuntimeException('当前账号未绑定教师档案');
        }

        return $teacherId;
    }

    private function currentStudentId(bool $required): ?int
    {
        $studentId = InternshipRecord::studentIdByUser((int) CurrentContext::userId());
        if (!$studentId && $required) {
            throw new RuntimeException('当前账号未绑定学生档案');
        }

        return $studentId;
    }

    private function studentDepId(int $studentId): ?int
    {
        return InternshipRecord::studentDepId($studentId);
    }

    private function studentAccountId(int $studentId): ?int
    {
        return InternshipRecord::studentAccountId($studentId);
    }

    private function teacherAccountId(int $teacherId): ?int
    {
        return InternshipRecord::teacherAccountId($teacherId);
    }

    private function row(string $table, int $id): object
    {
        $row = InternshipRecord::activeRowById($table, $id);
        if (!$row) {
            throw new RuntimeException('数据不存在');
        }

        return $row;
    }

    private function requiredRowId(Request $request, string $table): int
    {
        $id = $this->inputRowId($request, $table);
        if ($id) {
            return $id;
        }

        throw new InvalidArgumentException('id 无效');
    }

    private function inputRowId(Request $request, string $table): ?int
    {
        $id = $this->optionalInt($request, 'id');
        if ($id) {
            return $id;
        }
        $uuid = $this->nullableString($request, 'uuid', 36);
        if ($uuid) {
            $id = InternshipRecord::idByUuid($table, $uuid);
        }

        return $id ?: null;
    }

    private function reviewEntity(Request $request): string
    {
        $entity = (string) $request->input('entity', '');
        if (!isset(self::REVIEW_ENTITY_CONFIG[$entity])) {
            throw new InvalidArgumentException('entity 无效');
        }

        return $entity;
    }

    private function reviewDraftTarget(Request $request): array
    {
        $entity = $this->reviewEntity($request);
        $config = self::REVIEW_ENTITY_CONFIG[$entity];
        $id = $this->requiredRowId($request, $config['table']);
        $row = InternshipRecord::activeRowById($config['table'], $id);
        if (!$row) {
            throw new RuntimeException('数据不存在');
        }
        $this->assertReviewEntityWritable($entity, $row);
        if ((string) ($row->status ?? '') !== 'wait') {
            throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
        }

        return [
            'entity' => $entity,
            'entity_type' => $entity,
            'id' => $id,
        ];
    }

    private function reviewStatuses(string $entity): array
    {
        $statuses = array_keys(self::REVIEW_OPINION_RULES[$entity] ?? []);
        return $statuses ?: ['accept', 'modify'];
    }

    private function reviewDraftOpinionInput(Request $request, string $entity, string $status): ?string
    {
        $value = trim((string) $request->input('opinion', ''));
        $rule = self::REVIEW_OPINION_RULES[$entity][$status] ?? ['min' => 0, 'max' => null];
        $max = $rule['max'] ?? null;
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($max !== null && $length > (int) $max) {
            throw new InvalidArgumentException('审核意见最多 ' . (int) $max . ' 字', 42202);
        }

        return $value === '' ? null : $value;
    }

    private function baseFlowType(Request $request): string
    {
        $type = (string) $request->input('type', '');
        if (!isset(self::BASE_FLOW_TABLES[$type])) {
            throw new InvalidArgumentException('type 无效');
        }

        return $type;
    }

    private function baseFlowTable(Request $request): string
    {
        return self::BASE_FLOW_TABLES[$this->baseFlowType($request)];
    }

    private function baseFlowReviewEntity(Request $request): string
    {
        $entity = (string) $request->input('entity', '');
        if (isset(self::BASE_FLOW_ENTITIES[$entity])) {
            return $entity;
        }

        $type = $this->baseFlowType($request);
        $entity = array_search($type, self::BASE_FLOW_ENTITIES, true);
        if (!$entity) {
            throw new InvalidArgumentException('entity 无效');
        }

        return (string) $entity;
    }

    private function assertReviewEntityVisible(string $entity, object $row): void
    {
        if (isset(self::BASE_FLOW_ENTITIES[$entity])) {
            $table = self::REVIEW_ENTITY_CONFIG[$entity]['table'] ?? '';
            if (!$table || !InternshipRecord::baseFlowVisible($table, $this->scopeContext(), (int) $row->id)) {
                throw new RuntimeException('无数据访问权限', 40301);
            }
            return;
        }
        if ($entity === 'application') {
            $this->assertApplicationVisible((int) $row->id);
            return;
        }
        if ($entity === 'plan') {
            $this->assertPlanVisible((int) $row->id);
            return;
        }
        if ($entity === 'arrangement') {
            $this->assertArrangementVisible((int) $row->id);
            return;
        }
        if ($entity === 'arrangement_change') {
            if (!InternshipRecord::arrangementChangeVisible($this->scopeContext(), (int) $row->id)) {
                throw new RuntimeException('无数据访问权限', 40301);
            }
            return;
        }
        if (in_array($entity, ['syllabus_guide', 'implementation_sheet', 'teacher_work_report', 'inspection'], true)) {
            $this->assertArrangementVisible((int) $row->arrangement_id);
            if ($entity === 'inspection' && (int) ($row->student_id ?? 0) > 0) {
                $this->assertStudentVisible((int) $row->student_id);
                $this->assertTaskBindingVisible((int) $row->student_id, (int) $row->arrangement_id);
            }
            return;
        }
        $this->assertStudentVisible((int) $row->student_id);
        if (in_array($entity, ['sign_in', 'journal', 'report', 'score', 'delay', 'insurance', 'safety_letter'], true)) {
            $this->assertTaskBindingVisible((int) $row->student_id, $this->reviewEntityArrangementId($entity, $row));
        }
    }

    private function assertReviewEntityWritable(string $entity, object $row): void
    {
        if ($entity === 'application') {
            $this->assertApplicationVisible((int) $row->id);
            $this->assertTaskBindingVisible((int) $row->student_id, (int) $row->arrangement_id);
            return;
        }
        if (in_array($entity, ['syllabus_guide', 'implementation_sheet', 'teacher_work_report', 'inspection'], true)) {
            $this->assertCurrentArrangementVisible((int) $row->arrangement_id);
            if ($entity === 'inspection' && (int) ($row->student_id ?? 0) > 0) {
                $this->assertStudentVisible((int) $row->student_id);
                $this->assertTaskBindingVisible((int) $row->student_id, (int) $row->arrangement_id);
            }
            return;
        }

        $this->assertReviewEntityVisible($entity, $row);
    }

    private function reviewWorkArrangementId(string $table, object $row): int
    {
        return match ($table) {
            'journal' => (int) $row->entity_id,
            'report' => (int) $row->arrangement_id,
            default => 0,
        };
    }

    private function reviewEntityArrangementId(string $entity, object $row): int
    {
        return match ($entity) {
            'sign_in', 'journal', 'delay' => (int) $row->entity_id,
            'report', 'score', 'insurance', 'safety_letter' => (int) $row->arrangement_id,
            default => 0,
        };
    }

    private function timelineCycles(array $records, array $reviews): array
    {
        $reviewsByRecording = $this->reviewsByRecording($reviews);
        $events = [];
        foreach ($records as $record) {
            $events[] = [
                'kind' => 'recording',
                'created_at' => $record['created_at'] ?? null,
                'order' => (int) ($record['id'] ?? 0),
                'record' => $record,
            ];
        }
        foreach ($reviews as $review) {
            if ((int) ($review['recording_id'] ?? 0) === 0) {
                $events[] = [
                    'kind' => 'review',
                    'created_at' => $review['created_at'] ?? null,
                    'order' => (int) ($review['id'] ?? 0),
                    'review' => $review,
                ];
            }
        }

        usort($events, static function (array $left, array $right): int {
            $time = strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
            if ($time !== 0) {
                return $time;
            }
            $kindOrder = ['recording' => 0, 'review' => 1];
            $kind = ($kindOrder[$left['kind']] ?? 9) <=> ($kindOrder[$right['kind']] ?? 9);
            return $kind !== 0 ? $kind : ((int) ($left['order'] ?? 0) <=> (int) ($right['order'] ?? 0));
        });

        $cycles = [];
        $current = null;
        $sequence = 0;
        foreach ($events as $event) {
            if ($event['kind'] === 'recording') {
                $record = $event['record'];
                $recordId = (int) ($record['id'] ?? 0);
                if (($record['action'] ?? '') === 'submit') {
                    $sequence++;
                    $cycles[] = [
                        'kind' => 'cycle',
                        'sequence' => $sequence,
                        'created_at' => $record['created_at'] ?? null,
                        'record' => $record,
                        'branches' => $this->timelineReviewBranches($record, $reviewsByRecording[$recordId] ?? []),
                    ];
                    $current = count($cycles) - 1;
                    continue;
                }

                $index = $this->timelineCycleIndex($cycles, $current, $sequence, $record['created_at'] ?? null);
                $cycles[$index]['branches'][] = [
                    'kind' => 'branch',
                    'type' => 'recording',
                    'created_at' => $record['created_at'] ?? null,
                    'record' => $record,
                    'review' => null,
                    'reviews' => $this->workflowReviewsForRecord($record, $reviewsByRecording[$recordId] ?? []),
                ];
                continue;
            }

            $index = $this->timelineCycleIndex($cycles, $current, $sequence, $event['created_at'] ?? null);
            $review = $event['review'];
            $cycles[$index]['branches'][] = [
                'kind' => 'branch',
                'type' => 'review',
                'created_at' => $review['created_at'] ?? null,
                'record' => null,
                'review' => $review,
                'reviews' => [$review],
            ];
        }

        return $cycles;
    }

    private function timelineCycleIndex(array &$cycles, ?int &$current, int &$sequence, ?string $createdAt): int
    {
        if ($current !== null) {
            return $current;
        }

        $sequence++;
        $cycles[] = [
            'kind' => 'cycle',
            'sequence' => $sequence,
            'created_at' => $createdAt,
            'record' => null,
            'branches' => [],
        ];
        $current = count($cycles) - 1;

        return $current;
    }

    private function timelineItems(array $records, array $reviews): array
    {
        $reviewsByRecording = $this->reviewsByRecording($reviews);
        $standaloneReviews = [];
        foreach ($reviews as $review) {
            if ((int) ($review['recording_id'] ?? 0) === 0) {
                $standaloneReviews[] = [
                    'kind' => 'review',
                    'created_at' => $review['created_at'] ?? null,
                    'review' => $review,
                    'reviews' => [$review],
                ];
            }
        }

        $items = [];
        foreach ($records as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            $items[] = [
                'kind' => 'recording',
                'created_at' => $record['created_at'] ?? null,
                'record' => $record,
                'reviews' => $this->workflowReviewsForRecord($record, $reviewsByRecording[$recordId] ?? []),
            ];
        }

        $items = array_merge($items, $standaloneReviews);
        usort($items, fn (array $left, array $right): int => strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? '')));

        return $items;
    }

    private function reviewsByRecording(array $reviews): array
    {
        $grouped = [];
        foreach ($reviews as $review) {
            $recordingId = (int) ($review['recording_id'] ?? 0);
            if ($recordingId > 0) {
                $grouped[$recordingId][] = $review;
            }
        }

        return $grouped;
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

    private function recordWorkflow(
        string $recordingTable,
        string $entityType,
        int $entityId,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?string $content,
        string $reviewStatus,
        ?float $score = null,
        ?int $teacherId = null
    ): int {
        $recordingId = $this->record($recordingTable, $entityId, $action, $fromStatus, $toStatus, $content);
        $this->reviewOpinion($entityType, $entityId, $recordingId, $reviewStatus, $content, $score, $teacherId);

        return $recordingId;
    }

    private function record(string $table, int $parentId, string $action, ?string $from, string $to, ?string $content): int
    {
        InternshipRecord::ensureRecordingTable($table);
        return InternshipRecord::insertRow($table, [
            'uuid' => $this->uuid(),
            'parent_id' => $parentId,
            'entity_type' => str_replace('_recording', '', $table),
            'entity_id' => $parentId,
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
    }

    private function reviewOpinion(string $entityType, int $entityId, ?int $recordingId, string $status, ?string $opinion, ?float $score = null, ?int $teacherId = null): void
    {
        InternshipRecord::insertRow('review_opinion', [
            'uuid' => $this->uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'recording_id' => $recordingId,
            'teacher_id' => $teacherId ?? $this->currentTeacherId(false),
            'reviewer_id' => CurrentContext::accountId(),
            'opinion' => $opinion,
            'score' => $score,
            'status' => $status,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    private function finalScore(?float $sign, ?float $journal, ?float $report, float $signWeight, float $journalWeight, float $reportWeight, ?float $enterprise): ?float
    {
        if ($sign === null && $journal === null && $report === null && $enterprise === null) {
            return null;
        }

        $base = (($sign ?? 0) * $signWeight + ($journal ?? 0) * $journalWeight + ($report ?? 0) * $reportWeight) / max(1, $signWeight + $journalWeight + $reportWeight);
        if ($enterprise !== null) {
            $base = $base * 0.8 + $enterprise * 0.2;
        }

        return round($base, 2);
    }

    private function accountId(): int
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            throw new RuntimeException('请先登录', 40100);
        }

        return $accountId;
    }

    private function requiredInt(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$key} 无效");
        }

        return (int) $value;
    }

    private function requiredImportValue(array $row, string $field, string $label): string
    {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("缺少{$label}");
        }

        return $value;
    }

    private function optionalImportInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        return is_numeric($value) ? (int) $value : null;
    }

    private function decimalImportValue(mixed $value): ?float
    {
        $value = trim((string) $value);
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function dateImportValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            $timestamp = ((int) $value - 25569) * 86400;
            if ($timestamp > 0) {
                return gmdate('Y-m-d', $timestamp);
            }
        }
        $text = trim((string) $value);
        if (preg_match('/^\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}$/', $text)) {
            $timestamp = strtotime(str_replace(['/', '.'], '-', $text));
            return $timestamp ? date('Y-m-d', $timestamp) : null;
        }

        return null;
    }

    private function splitImportList(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,，、;；\\s]+/u', $value) ?: [])));
    }

    private function arrangementTypeImportValue(mixed $value): string
    {
        $text = trim((string) $value);
        $map = [
            '认知校内' => 'cognition_internal',
            '认知校外' => 'cognition_external',
            '专业校内' => 'major_internal',
            '专业校外' => 'major_external',
            '生产实习' => 'production',
            '毕业实习' => 'graduation',
        ];
        $value = $map[$text] ?? $text;

        return in_array($value, self::ARRANGEMENT_TYPES, true) ? $value : 'major_external';
    }

    private function organizeModeImportValue(mixed $value): string
    {
        $text = trim((string) $value);
        $map = [
            '集中' => 'centralized',
            '分散' => 'distributed',
            '自主' => 'autonomous',
            '集中实习' => 'centralized',
            '分散实习' => 'distributed',
            '自主实习' => 'autonomous',
        ];
        $value = $map[$text] ?? $text;

        return in_array($value, self::ORGANIZE_MODES, true) ? $value : 'centralized';
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private function intArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    private function requiredString(Request $request, string $key, int $maxLength): string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        if ($value === '') {
            throw new InvalidArgumentException("{$key} 不能为空");
        }

        return $value;
    }

    private function nullableString(Request $request, string $key, int $maxLength): ?string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        return $value === '' ? null : $value;
    }

    private function reviewOpinionInput(Request $request, string $entity, string $status, ?string $label = null): ?string
    {
        $value = trim((string) $request->input('opinion', ''));
        $rule = self::REVIEW_OPINION_RULES[$entity][$status] ?? ['min' => 0, 'max' => null];
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        $label ??= in_array($status, ['modify', 'refuse'], true) ? '退回原因' : '审核意见';
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

    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        $value = trim((string) $request->input($key, ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function enum(Request $request, string $key, array $values, string $default): string
    {
        $value = (string) $request->input($key, $default);
        return in_array($value, $values, true) ? $value : $default;
    }

    private function excelFile(Request $request): UploadFile
    {
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            throw new InvalidArgumentException('上传文件无效');
        }
        $extension = strtolower($file->getUploadExtension());
        if (!in_array($extension, self::EXCEL_EXTENSIONS, true)) {
            throw new InvalidArgumentException('仅支持 xls、xlsx 文件');
        }
        if ($file->getSize() > self::EXCEL_MAX_SIZE) {
            throw new InvalidArgumentException('文件大小不能超过 10MB');
        }

        return $file;
    }

    private function arrangementImportRows(UploadFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        try {
            $sheetRows = $spreadsheet->getActiveSheet()->toArray(null, false, true, true);
            [$mapping, $startRow] = $this->arrangementImportHeader($sheetRows);
            $items = [];
            foreach ($sheetRows as $rowNumber => $row) {
                if ((int) $rowNumber < $startRow) {
                    continue;
                }

                $item = ['row_number' => (int) $rowNumber];
                foreach ($mapping as $key => $column) {
                    $item[$key] = $this->excelCellString($row[$column] ?? '');
                }
                if ($this->importRowEmpty($item)) {
                    continue;
                }
                $missing = [];
                foreach (self::ARRANGEMENT_IMPORT_REQUIRED as $field) {
                    if (($item[$field] ?? '') === '') {
                        $missing[] = $this->arrangementImportLabel($field);
                    }
                }
                if ($missing) {
                    $item['invalid_message'] = '缺少' . implode('、', $missing);
                }
                $items[] = $item;
                if (count($items) > self::EXCEL_MAX_ROWS) {
                    throw new InvalidArgumentException('单次最多导入 5000 行');
                }
            }
            if (!$items) {
                throw new InvalidArgumentException('Excel 中没有可导入的数据');
            }

            return $items;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function arrangementImportHeader(array $sheetRows): array
    {
        foreach (array_slice($sheetRows, 0, 5, true) as $rowNumber => $row) {
            $mapping = [];
            foreach ($row as $column => $value) {
                $key = $this->arrangementImportHeaderKey($value);
                if ($key && !isset($mapping[$key])) {
                    $mapping[$key] = $column;
                }
            }
            if (count(array_intersect(self::ARRANGEMENT_IMPORT_REQUIRED, array_keys($mapping))) === count(self::ARRANGEMENT_IMPORT_REQUIRED)) {
                return [$mapping, (int) $rowNumber + 1];
            }
        }

        return [[
            'grade_name' => 'A',
            'dep_name' => 'B',
            'profession_name' => 'C',
            'course_name' => 'D',
            'credit' => 'E',
            'task_no' => 'F',
            'teacher_num' => 'G',
            'start_date' => 'H',
            'end_date' => 'I',
            'location' => 'J',
            'class_names' => 'K',
        ], 1];
    }

    private function arrangementImportHeaderKey(mixed $value): ?string
    {
        $header = $this->normalizeExcelText((string) $value);
        if ($header === '') {
            return null;
        }
        foreach (self::ARRANGEMENT_IMPORT_HEADERS as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($header === $this->normalizeExcelText($alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function importRowEmpty(array $row): bool
    {
        foreach (self::ARRANGEMENT_IMPORT_HEADERS as $field => $_aliases) {
            if (($row[$field] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    private function arrangementImportLabel(string $field): string
    {
        return match ($field) {
            'grade_name' => '届次',
            'dep_name' => '学院',
            'profession_name' => '专业',
            'course_name' => '课程名称',
            'credit' => '学分',
            'task_no' => '任务编号',
            'teacher_num' => '老师工号',
            'start_date' => '开始日期',
            'end_date' => '结束日期',
            'location' => '地点',
            'class_names' => '班级',
            default => '字段',
        };
    }

    private function excelCellString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        } elseif (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        } else {
            $value = (string) $value;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, 180) : substr($value, 0, 180);
    }

    private function normalizeExcelText(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return (string) preg_replace('/[\s　_\-:：()（）]+/u', '', $value);
    }

    private function dateInput(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function requiredDate(Request $request, string $key): string
    {
        $value = $this->dateInput($request, $key);
        if (!$value) {
            throw new InvalidArgumentException("{$key} 无效");
        }

        return $value;
    }

    private function dateTimeInput(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function decimalInput(Request $request, string $key): ?float
    {
        $value = $request->input($key);
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function coordinateInput(Request $request, string $key, float $min, float $max): ?float
    {
        $value = $request->input($key);
        if (!is_numeric($value)) {
            return null;
        }

        $number = round((float) $value, 6);
        return $number >= $min && $number <= $max ? $number : null;
    }

    private function assertScoreRange(array $scores): void
    {
        foreach ($scores as $label => $score) {
            if ($score !== null && ($score < 0 || $score > 100)) {
                throw new InvalidArgumentException("{$label}必须在 0 到 100 之间");
            }
        }
    }

    private function assertWeightRange(array $weights): void
    {
        foreach ($weights as $label => $weight) {
            if ($weight < 0 || $weight > 100) {
                throw new InvalidArgumentException("{$label}必须在 0 到 100 之间");
            }
        }
    }

    private function jsonValue(mixed $value): string
    {
        return json_encode(is_string($value) ? (json_decode($value, true) ?: $value) : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function planWorkflowContent(string $planContent): string
    {
        $decoded = json_decode($planContent, true);
        if (is_array($decoded)) {
            $text = (string) ($decoded['content'] ?? $decoded['summary'] ?? '');
            return trim($text) ?: '提交实习计划';
        }

        return trim($planContent) ?: '提交实习计划';
    }

    private function planReviewContent(int $level, string $levelName, string $reviewStatus, ?string $opinion, string $planStatus): string
    {
        $action = $reviewStatus === 'accept' ? '通过' : '退回';
        $text = sprintf('实习计划第 %d 级审核（%s）%s', $level, $levelName, $action);
        if ($planStatus === 'wait') {
            $nextName = self::PLAN_APPROVAL_LEVELS[$level + 1]['name'] ?? '下一审核节点';
            $text .= "，流转至{$nextName}";
        } elseif ($planStatus === 'accept') {
            $text .= '，五级审核完成';
        } elseif ($planStatus === 'modify') {
            $text .= '，退回修改后需重新提交';
        }

        return trim($opinion ?? '') === '' ? $text : $text . '；意见：' . trim((string) $opinion);
    }

    private function signInWorkflowContent(array $values): string
    {
        $parts = array_filter([
            '日期：' . (string) ($values['date'] ?? ''),
            '时间：' . (string) ($values['sign_time'] ?? ''),
            '方式：' . (string) ($values['sign_type'] ?? ''),
            !empty($values['location']) ? '地点：' . (string) $values['location'] : '',
            isset($values['longitude'], $values['latitude']) && $values['longitude'] !== null && $values['latitude'] !== null
                ? '坐标：' . $values['longitude'] . ',' . $values['latitude']
                : '',
            !empty($values['remark']) ? '备注：' . (string) $values['remark'] : '',
        ]);

        return '提交实习签到；' . implode('；', $parts);
    }

    private function scoreWorkflowContent(array $values): string
    {
        $parts = array_filter([
            $values['sign_in_score'] !== null ? '签到：' . $values['sign_in_score'] : '',
            $values['journal_score'] !== null ? '日志：' . $values['journal_score'] : '',
            $values['report_score'] !== null ? '报告：' . $values['report_score'] : '',
            $values['enterprise_score'] !== null ? '企业：' . $values['enterprise_score'] : '',
            $values['final_score'] !== null ? '总评：' . $values['final_score'] : '',
            !empty($values['comment']) ? '评语：' . (string) $values['comment'] : '',
        ]);

        return '核定实习任务成绩；' . implode('；', $parts);
    }

    private function refreshArrangementScoreWorkflow(int $arrangementId): void
    {
        $progress = InternshipRecord::refreshArrangementScoreStatus($arrangementId, $this->now());
        if (!($progress['status_changed'] ?? false)) {
            return;
        }

        $toStatus = (string) ($progress['to_status'] ?? '');
        $action = $toStatus === 'completed' ? 'complete' : 'reopen';
        $content = $toStatus === 'completed'
            ? sprintf('任务绑定学生 %d 人，已完成评分 %d 人，任务自动标记完成。', (int) $progress['student_count'], (int) $progress['scored_student_count'])
            : sprintf('任务绑定学生 %d 人，已完成评分 %d 人，任务恢复为启用状态。', (int) $progress['student_count'], (int) $progress['scored_student_count']);

        $this->recordWorkflow(
            'arrangement_recording',
            'arrangement',
            $arrangementId,
            $action,
            (string) ($progress['from_status'] ?? 'enabled'),
            $toStatus,
            $content,
            $toStatus === 'completed' ? 'accept' : 'modify'
        );
    }

    private function workflowContent(string $title, array $values, array $labels): string
    {
        $parts = [];
        foreach ($labels as $key => $label) {
            $value = $values[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $label . '：' . $this->workflowValue($value);
        }

        return $title . ($parts ? '；' . implode('；', $parts) : '');
    }

    private function workflowValue(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : $this->jsonValue($value);
        return mb_strlen($text) > 180 ? mb_substr($text, 0, 180) . '...' : $text;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function connection(): mixed
    {
        return InternshipRecord::connection();
    }

    private function workflowLock(string $module, string $entity, int $id, callable $callback): mixed
    {
        return (new WorkflowLock())->run(WorkflowLock::key($module, $entity, $id), $callback);
    }

    private function ensureRecordingTable(string $table): void
    {
        InternshipRecord::ensureRecordingTable($table);
    }

    private function deadlineConfigs(): array
    {
        $service = new ConfigService();
        $userId = (int) (CurrentContext::userId() ?: 0);
        $values = [];
        foreach (self::DELAY_CONFIG_KEYS as $key) {
            $values[$key] = (string) ($service->get("internship.{$key}", 0, $userId) ?? '');
        }

        return $values;
    }

    private function saveDelayConfig(object $delay): void
    {
        $studentUserId = InternshipRecord::studentUserId((int) $delay->student_id);
        if (!$studentUserId) {
            return;
        }

        (new ConfigService())->set(
            'internship',
            (string) $delay->config_key,
            (string) $delay->requested_date,
            '延期申请通过后生成的学生截止日期',
            0,
            $studentUserId
        );
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
