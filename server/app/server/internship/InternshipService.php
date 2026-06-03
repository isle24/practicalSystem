<?php

namespace app\server\internship;

use app\model\channel\InternshipRecord;
use app\server\CurrentContext;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Throwable;

class InternshipService
{
    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const WORKFLOW_STATUS = ['draft', 'wait', 'accept', 'modify', 'enabled', 'disabled'];
    private const APPLICATION_REVIEW_STATUS = ['accept', 'modify', 'skipped'];
    private const JOIN_STATUS = ['applying', 'accept', 'refuse'];
    private const ARRANGEMENT_TYPES = ['cognition_internal', 'cognition_external', 'major_internal', 'major_external', 'production', 'graduation'];
    private const ORGANIZE_MODES = ['centralized', 'distributed', 'autonomous'];
    private const STAT_REPORTS = ['overview', 'department', 'profession', 'teacher', 'student', 'archive'];
    private const REVIEW_OPINION_RULES = [
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
        'plan' => [
            'accept' => ['min' => 0, 'max' => 300],
            'modify' => ['min' => 8, 'max' => 800],
        ],
    ];
    private const REVIEW_ENTITY_CONFIG = [
        'application' => ['table' => 'application', 'recording' => 'application_recording'],
        'journal' => ['table' => 'journal', 'recording' => 'journal_recording'],
        'report' => ['table' => 'report', 'recording' => 'report_recording'],
        'plan' => ['table' => 'internship_plan', 'recording' => 'plan_recording'],
    ];
    private const STUDENT_DOCUMENT_COLUMNS = [
        'students.name as student_name',
        'students.student_num',
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
            'dep_id', 'profession_id', 'semester',
        ]));
    }

    public function saveArrangement(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();

        $values = [
            'name' => $this->stringInput($request, 'name', 180) ?: $this->requiredString($request, 'title', 180),
            'base_id' => $this->optionalInt($request, 'base_id'),
            'dep_id' => $this->optionalInt($request, 'dep_id'),
            'profession_id' => $this->optionalInt($request, 'profession_id'),
            'semester' => $this->nullableString($request, 'semester', 80),
            'type' => $this->enum($request, 'type', self::ARRANGEMENT_TYPES, 'major_external'),
            'organize_mode' => $this->enum($request, 'organize_mode', self::ORGANIZE_MODES, 'centralized'),
            'title' => $this->requiredString($request, 'title', 180),
            'start_date' => $this->dateInput($request, 'start_date'),
            'end_date' => $this->dateInput($request, 'end_date'),
            'location' => $this->nullableString($request, 'location', 255),
            'description' => $this->nullableString($request, 'description', 2000),
            'created_by' => CurrentContext::accountId(),
            'status' => $this->enum($request, 'status', ['enabled', 'disabled', 'draft', 'wait', 'accept', 'modify'], 'enabled'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveRow('arrangement', $request, $values);
    }

    public function applications(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::applicationPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'semester',
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
        $this->assertArrangementVisible($arrangementId);

        if (!$existingId) {
            $existingId = InternshipRecord::applicationIdByStudentArrangement($studentId, $arrangementId);
        }
        $fromStatus = $existingId ? InternshipRecord::statusById('application', $existingId) : 'draft';

        if ($this->isStudent()) {
            if ($fromStatus === 'accept') {
                throw new RuntimeException('该实习安排不可重复申请', 42201);
            }
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

        $id = $this->saveRow('application', $request, $values, [
            'student_id' => $studentId,
            'arrangement_id' => $arrangementId,
        ])['id'];
        $this->syncJoinTeachers($id, $studentId, $arrangementId, $this->intArray($request->input('teacher_ids', [])));
        if ($status === 'wait') {
            $this->recordWorkflow('application_recording', 'application', $id, 'submit', $fromStatus, 'wait', '提交实习申请', 'wait');
        }

        return ['id' => $id, 'item' => $this->application($id)];
    }

    public function submitApplication(Request $request): array
    {
        $this->requirePermission('internship:apply');
        $id = $this->requiredRowId($request, 'application');
        $row = $this->row('application', $id);
        $this->assertStudentVisible((int) $row->student_id);
        if ((string) $row->status === 'accept') {
            throw new RuntimeException('该实习安排不可重复申请', 42201);
        }

        InternshipRecord::updateById('application', $id, [
            'status' => 'wait',
            'teacher_status' => 'pending',
            'admin_status' => 'pending',
            'updated_at' => $this->now(),
        ]);
        $this->recordWorkflow('application_recording', 'application', $id, 'submit', (string) $row->status, 'wait', '提交实习申请', 'wait');

        return ['id' => $id, 'item' => $this->application($id)];
    }

    public function reviewApplication(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $id = $this->requiredRowId($request, 'application');
        $status = $this->enum($request, 'status', self::APPLICATION_REVIEW_STATUS, 'accept');
        $opinion = $this->reviewOpinionInput($request, 'application', $status);

        return $this->connection()->transaction(function () use ($id, $status, $opinion): array {
            $row = InternshipRecord::lockActiveRowById('application', $id);
            if (!$row) {
                throw new RuntimeException('实习申请不存在');
            }
            $this->assertApplicationVisible((int) $row->id);

            $updates = ['updated_at' => $this->now()];
            $action = 'review';
            if ($this->isTeacher()) {
                $updates['teacher_status'] = $status;
                $action = 'teacher_review';
                $this->reviewJoinTeacher($id, $status);
            } else {
                $this->requireAdminRole();
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

            return ['id' => $id, 'item' => $this->application($id)];
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
            'items' => $this->timelineItems($records, $reviews),
        ];
    }

    public function requestModification(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $entity = $this->reviewEntity($request);
        $config = self::REVIEW_ENTITY_CONFIG[$entity];
        $id = $this->requiredRowId($request, $config['table']);
        $opinion = $this->reviewOpinionInput($request, $entity, 'modify');

        return $this->connection()->transaction(function () use ($entity, $config, $id, $opinion): array {
            $row = InternshipRecord::lockActiveRowById($config['table'], $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertReviewEntityVisible($entity, $row);
            if ((string) $row->status !== 'accept') {
                throw new InvalidArgumentException('仅已通过数据可改回修改', 42203);
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

            return ['id' => $id, 'status' => 'modify'];
        });
    }

    public function pairs(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::pairPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'semester',
        ]));
    }

    public function savePair(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();

        $studentId = $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $teacherId = $this->requiredInt($request, 'teacher_id');
        $this->assertStudentVisible($studentId);
        $this->assertArrangementVisible($arrangementId);

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

        return ['id' => $this->upsertActivePair($values)];
    }

    public function removePair(Request $request): array
    {
        $this->requirePermission('internship:manage');
        $this->requireAdminRole();
        $id = $this->requiredRowId($request, 'pair');
        $reason = $this->nullableString($request, 'remove_reason', 255);

        InternshipRecord::updateById('pair', $id, [
            'status' => 'removed',
            'remove_reason' => $reason,
            'updated_at' => $this->now(),
        ]);

        return ['id' => $id];
    }

    public function signIns(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::signInPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'date',
            'dep_id', 'profession_id', 'grade_id', 'semester',
        ]));
    }

    public function saveSignIn(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:sign' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertStudentVisible($studentId);
        $this->assertArrangementVisible($arrangementId);

        $values = [
            'student_id' => $studentId,
            'entity_type' => 'internship',
            'entity_id' => $arrangementId,
            'date' => $this->dateInput($request, 'date') ?: date('Y-m-d'),
            'sign_time' => $this->dateTimeInput($request, 'sign_time') ?: $this->now(),
            'sign_type' => $this->enum($request, 'sign_type', ['gps', 'qrcode', 'manual'], $this->isStudent() ? 'gps' : 'manual'),
            'location' => $this->nullableString($request, 'location', 255),
            'longitude' => $this->decimalInput($request, 'longitude'),
            'latitude' => $this->decimalInput($request, 'latitude'),
            'remark' => $this->nullableString($request, 'remark', 1000),
            'status' => 'accept',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveRow('sign_in', $request, $values);
    }

    public function journals(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::journalPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'status', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'semester',
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
        $this->assertStudentVisible($studentId);
        $this->assertArrangementVisible($arrangementId);

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

        $result = $this->saveRow('journal', $request, $values);
        if ($status === 'wait') {
            $this->recordWorkflow('journal_recording', 'journal', (int) $result['id'], 'submit', $fromStatus, 'wait', '提交实习日志', 'wait');
        }

        return $result;
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
            'dep_id', 'profession_id', 'grade_id', 'semester',
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
        $this->assertStudentVisible($studentId);
        $this->assertArrangementVisible($arrangementId);

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

        $result = $this->saveRow('report', $request, $values);
        if ($status === 'wait') {
            $this->recordWorkflow('report_recording', 'report', (int) $result['id'], 'submit', $fromStatus, 'wait', '提交实习报告', 'wait');
        }

        return $result;
    }

    public function reviewReport(Request $request): array
    {
        return $this->reviewStudentWork($request, 'report', 'report_recording');
    }

    public function scores(Request $request): array
    {
        $this->requirePermission('internship:view');

        return InternshipRecord::scorePage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id',
            'dep_id', 'profession_id', 'grade_id', 'semester',
        ]));
    }

    public function stats(Request $request): array
    {
        $this->requirePermission('stat:view');
        $filters = $this->requestFilters($request, [
            'report', 'page', 'page_size', 'per_page', 'keyword',
            'dep_id', 'profession_id', 'grade_id', 'semester',
        ]);
        $report = trim((string) ($filters['report'] ?? 'overview'));
        $filters['report'] = in_array($report, self::STAT_REPORTS, true) ? $report : 'overview';

        return InternshipRecord::statReport($this->scopeContext(), $filters, date('Y-m-d'));
    }

    public function saveScore(Request $request): array
    {
        $this->requirePermission($this->isEnterprise() ? 'internship:score' : 'internship:manage');
        $studentId = $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertStudentVisible($studentId);
        $this->assertArrangementVisible($arrangementId);

        $signInScore = $this->decimalInput($request, 'sign_in_score');
        $journalScore = $this->decimalInput($request, 'journal_score');
        $reportScore = $this->decimalInput($request, 'report_score');
        $signWeight = $this->decimalInput($request, 'sign_in_weight') ?? 20;
        $journalWeight = $this->decimalInput($request, 'journal_weight') ?? 30;
        $reportWeight = $this->decimalInput($request, 'report_weight') ?? 50;
        $enterpriseScore = $this->decimalInput($request, 'enterprise_score');
        $final = $this->finalScore($signInScore, $journalScore, $reportScore, $signWeight, $journalWeight, $reportWeight, $enterpriseScore);

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
            'teacher_id' => $this->isTeacher() ? $this->currentTeacherId(true) : $this->optionalInt($request, 'teacher_id'),
            'comment' => $this->nullableString($request, 'comment', 2000),
            'status' => 'accept',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        return $this->saveRow('score', $request, $values, ['student_id' => $studentId, 'arrangement_id' => $arrangementId]);
    }

    public function plans(Request $request): array
    {
        $this->requirePermission('internship:plan');

        return InternshipRecord::planPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'status', 'semester',
        ]));
    }

    public function savePlan(Request $request): array
    {
        $this->requirePermission('internship:plan');
        $this->requireAdminRole();
        $existingId = $this->inputRowId($request, 'internship_plan');
        $fromStatus = $existingId ? InternshipRecord::statusById('internship_plan', $existingId) : 'draft';

        $values = [
            'dep_id' => $this->requiredInt($request, 'dep_id'),
            'semester' => $this->requiredString($request, 'semester', 80),
            'plan_content' => $this->jsonValue($request->input('plan_content', [])),
            'submitter_id' => CurrentContext::accountId(),
            'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $result = $this->saveRow('internship_plan', $request, $values);
        if ($values['status'] === 'wait') {
            $this->recordWorkflow('plan_recording', 'plan', (int) $result['id'], 'submit', $fromStatus, 'wait', '提交实习计划', 'wait');
        }

        return $result;
    }

    public function reviewPlan(Request $request): array
    {
        $this->requirePermission('internship:plan');
        $this->requireAdminRole();

        $planId = $this->requiredRowId($request, 'internship_plan');
        $status = $this->enum($request, 'status', ['accept', 'modify'], 'accept');
        $opinion = $this->reviewOpinionInput($request, 'plan', $status);
        $level = $this->optionalInt($request, 'approval_level') ?? 1;
        $now = $this->now();
        $row = $this->row('internship_plan', $planId);
        $this->assertPlanVisible($planId);
        $from = (string) $row->status;

        $approvalId = InternshipRecord::insertPlanApproval($planId, [
            'uuid' => $this->uuid(),
            'plan_id' => $planId,
            'approver_id' => CurrentContext::accountId(),
            'approval_level' => $level,
            'level_name' => $this->nullableString($request, 'level_name', 80),
            'opinion' => $opinion,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ], $status, $now);
        $this->recordWorkflow('plan_recording', 'plan', $planId, 'review', $from, $status, $opinion ?: '实习计划审核', $status);

        return ['id' => $approvalId, 'plan_id' => $planId];
    }

    public function insurances(Request $request): array
    {
        return $this->documentList($request, 'insurance', array_merge(['insurance.*'], self::STUDENT_DOCUMENT_COLUMNS));
    }

    public function saveInsurance(Request $request): array
    {
        $this->requirePermission('internship:manage');
        return $this->saveRow('insurance', $request, [
            'arrangement_id' => $this->requiredInt($request, 'arrangement_id'),
            'student_id' => $this->requiredInt($request, 'student_id'),
            'insurance_company' => $this->nullableString($request, 'insurance_company', 120),
            'policy_number' => $this->nullableString($request, 'policy_number', 120),
            'insured_amount' => $this->decimalInput($request, 'insured_amount'),
            'start_date' => $this->dateInput($request, 'start_date'),
            'end_date' => $this->dateInput($request, 'end_date'),
            'attachment_id' => $this->optionalInt($request, 'attachment_id'),
            'status' => 'enabled',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ]);
    }

    public function safetyLetters(Request $request): array
    {
        return $this->documentList($request, 'safety_letter_sign', array_merge(['safety_letter_sign.*'], self::STUDENT_DOCUMENT_COLUMNS));
    }

    public function saveSafetyLetter(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:apply' : 'internship:manage');
        return $this->saveRow('safety_letter_sign', $request, [
            'arrangement_id' => $this->requiredInt($request, 'arrangement_id'),
            'student_id' => $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id'),
            'template_id' => $this->optionalInt($request, 'template_id'),
            'signed_at' => $this->dateTimeInput($request, 'signed_at'),
            'signature_file_id' => $this->optionalInt($request, 'signature_file_id'),
            'status' => $this->enum($request, 'status', ['pending', 'signed'], 'pending'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ]);
    }

    public function syllabusGuides(Request $request): array
    {
        $this->requirePermission('internship:view');
        return InternshipRecord::syllabusGuidePage($this->scopeContext(), $this->requestFilters($request, ['page', 'page_size', 'per_page']));
    }

    public function saveSyllabusGuide(Request $request): array
    {
        $this->requirePermission('internship:manage');
        return $this->saveRow('syllabus_guide', $request, [
            'arrangement_id' => $this->requiredInt($request, 'arrangement_id'),
            'dep_id' => $this->optionalInt($request, 'dep_id'),
            'profession_id' => $this->optionalInt($request, 'profession_id'),
            'title' => $this->requiredString($request, 'title', 180),
            'content' => $this->nullableString($request, 'content', 20000),
            'file_id' => $this->optionalInt($request, 'file_id'),
            'created_by' => CurrentContext::accountId(),
            'status' => $this->enum($request, 'status', ['draft', 'published'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ]);
    }

    public function implementationSheets(Request $request): array
    {
        return $this->documentList($request, 'implementation_sheet', ['implementation_sheet.*']);
    }

    public function saveImplementationSheet(Request $request): array
    {
        $this->requirePermission('internship:manage');
        return $this->saveRow('implementation_sheet', $request, [
            'arrangement_id' => $this->requiredInt($request, 'arrangement_id'),
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
        ]);
    }

    public function teacherWorkReports(Request $request): array
    {
        return $this->documentList($request, 'teacher_work_report', ['teacher_work_report.*']);
    }

    public function saveTeacherWorkReport(Request $request): array
    {
        $this->requirePermission('internship:report');
        return $this->saveRow('teacher_work_report', $request, [
            'arrangement_id' => $this->requiredInt($request, 'arrangement_id'),
            'teacher_id' => $this->isTeacher() ? $this->currentTeacherId(true) : $this->requiredInt($request, 'teacher_id'),
            'guidance_count' => $this->optionalInt($request, 'guidance_count') ?? 0,
            'summary' => $this->nullableString($request, 'summary', 10000),
            'problems' => $this->nullableString($request, 'problems', 10000),
            'suggestions' => $this->nullableString($request, 'suggestions', 10000),
            'attachment_id' => $this->optionalInt($request, 'attachment_id'),
            'status' => $this->enum($request, 'status', ['draft', 'wait', 'accept', 'modify'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ]);
    }

    public function inspections(Request $request): array
    {
        $this->requirePermission('internship:archive');
        return InternshipRecord::inspectionPage($this->requestFilters($request, ['page', 'page_size', 'per_page']));
    }

    public function saveInspection(Request $request): array
    {
        $this->requirePermission('internship:archive');
        $this->requireAdminRole();
        return $this->saveRow('inspection_record', $request, [
            'semester' => $this->requiredString($request, 'semester', 80),
            'arrangement_id' => $this->requiredInt($request, 'arrangement_id'),
            'student_id' => $this->optionalInt($request, 'student_id'),
            'inspector_id' => CurrentContext::accountId(),
            'items' => $this->jsonValue($request->input('items', [])),
            'result' => $this->enum($request, 'result', ['pass', 'fail'], 'pass'),
            'remark' => $this->nullableString($request, 'remark', 2000),
            'status' => 'enabled',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ]);
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

        $row = $this->row($table, $id);
        $this->assertStudentVisible((int) $row->student_id);
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

        return ['id' => $id, 'status' => $status];
    }

    private function documentList(Request $request, string $table, array $columns): array
    {
        $this->requirePermission('internship:view');
        return InternshipRecord::documentPage($table, $columns, $this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'status',
            'dep_id', 'profession_id', 'grade_id', 'semester',
        ]));
    }

    private function application(int $id): array
    {
        $item = InternshipRecord::applicationWithTeachers($id);
        if (!$item) {
            throw new RuntimeException('实习申请不存在');
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
        $this->createPairFromApplication((int) $application->id);

        return 'accept';
    }

    private function createPairFromApplication(int $applicationId): void
    {
        $application = InternshipRecord::rowById('application', $applicationId);
        if (!$application) {
            return;
        }

        $joins = InternshipRecord::acceptedJoinTeachers($applicationId);
        if (!$joins) {
            return;
        }

        $this->upsertActivePair([
            'student_id' => (int) $application->student_id,
            'teacher_id' => (int) $joins[0]->teacher_id,
            'dep_id' => $this->studentDepId((int) $application->student_id),
            'second_teacher_id' => isset($joins[1]) ? (int) $joins[1]->teacher_id : null,
            'arrangement_id' => (int) $application->arrangement_id,
            'type' => 'internship',
            'entity_type' => 'internship',
            'entity_id' => (int) $application->arrangement_id,
            'application_id' => (int) $application->id,
            'status' => 'active',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ]);
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
            'application_ids' => $roleType === 'teacher' ? $this->teacherApplicationIds() : [],
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

    private function teacherApplicationIds(): array
    {
        $teacherId = $this->currentTeacherId(false);
        if (!$teacherId) {
            return [];
        }

        return InternshipRecord::applicationIdsByTeacher($teacherId);
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

    private function requirePermission(string $code): void
    {
        $this->accountId();
        if (!in_array($code, CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    private function requireAdminRole(): void
    {
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLE_TYPES, true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
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

    private function assertReviewEntityVisible(string $entity, object $row): void
    {
        if ($entity === 'application') {
            $this->assertApplicationVisible((int) $row->id);
            return;
        }
        if ($entity === 'plan') {
            $this->assertPlanVisible((int) $row->id);
            return;
        }
        $this->assertStudentVisible((int) $row->student_id);
    }

    private function timelineItems(array $records, array $reviews): array
    {
        $reviewsByRecording = [];
        $standaloneReviews = [];
        foreach ($reviews as $review) {
            $recordingId = (int) ($review['recording_id'] ?? 0);
            if ($recordingId > 0) {
                $reviewsByRecording[$recordingId][] = $review;
            } else {
                $standaloneReviews[] = [
                    'kind' => 'review',
                    'created_at' => $review['created_at'] ?? null,
                    'review' => $review,
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
                'reviews' => $reviewsByRecording[$recordId] ?? [],
            ];
        }

        $items = array_merge($items, $standaloneReviews);
        usort($items, fn (array $left, array $right): int => strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? '')));

        return $items;
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

    private function reviewOpinionInput(Request $request, string $entity, string $status): ?string
    {
        $value = trim((string) $request->input('opinion', ''));
        $rule = self::REVIEW_OPINION_RULES[$entity][$status] ?? ['min' => 0, 'max' => null];
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        $label = $status === 'modify' ? '退回原因' : '审核意见';
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

    private function dateInput(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
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

    private function jsonValue(mixed $value): string
    {
        return json_encode(is_string($value) ? (json_decode($value, true) ?: $value) : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function connection(): mixed
    {
        return InternshipRecord::connection();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
