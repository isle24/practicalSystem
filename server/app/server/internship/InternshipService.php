<?php

namespace app\server\internship;

use app\model\channel\InternshipRecord;
use app\model\channel\PracticeRecord;
use app\server\CurrentContext;
use app\server\config\ConfigService;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use support\Request;
use Throwable;
use Webman\Http\UploadFile;

class InternshipService
{
    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const WORKFLOW_STATUS = ['draft', 'wait', 'accept', 'modify', 'enabled', 'disabled'];
    private const APPLICATION_REVIEW_STATUS = ['accept', 'modify', 'skipped'];
    private const JOIN_STATUS = ['applying', 'accept', 'refuse'];
    private const ARRANGEMENT_TYPES = ['cognition_internal', 'cognition_external', 'major_internal', 'major_external', 'production', 'graduation'];
    private const ORGANIZE_MODES = ['centralized', 'distributed', 'autonomous'];
    private const STAT_REPORTS = ['overview', 'department', 'profession', 'teacher', 'student', 'archive', 'practice_score_sheet'];
    private const BASE_FLOW_TABLES = [
        'application' => 'base_application',
        'usage' => 'base_usage',
        'result' => 'base_result',
        'expense' => 'base_expense',
    ];
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
        'delay' => [
            'accept' => ['min' => 0, 'max' => 300],
            'refuse' => ['min' => 5, 'max' => 500],
            'modify' => ['min' => 5, 'max' => 500],
        ],
    ];
    private const REVIEW_ENTITY_CONFIG = [
        'application' => ['table' => 'application', 'recording' => 'application_recording'],
        'arrangement' => ['table' => 'arrangement', 'recording' => 'arrangement_recording'],
        'journal' => ['table' => 'journal', 'recording' => 'journal_recording'],
        'report' => ['table' => 'report', 'recording' => 'report_recording'],
        'plan' => ['table' => 'internship_plan', 'recording' => 'plan_recording'],
        'delay' => ['table' => 'apply_report_delay', 'recording' => 'apply_report_delay_recording'],
    ];
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
            'status' => $this->enum($request, 'status', ['draft', 'wait', 'accept', 'modify', 'enabled', 'disabled'], 'enabled'),
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

        return $this->saveRow($table, $request, $values);
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
            'task_no' => $this->nullableString($request, 'task_no', 80),
            'batch_no' => $this->nullableString($request, 'batch_no', 80),
            'credit' => $this->decimalInput($request, 'credit'),
            'type' => $this->enum($request, 'type', self::ARRANGEMENT_TYPES, 'major_external'),
            'organize_mode' => $this->enum($request, 'organize_mode', self::ORGANIZE_MODES, 'centralized'),
            'start_date' => $this->requiredDate($request, 'start_date'),
            'end_date' => $this->requiredDate($request, 'end_date'),
            'location' => $this->nullableString($request, 'location', 255),
            'description' => $this->nullableString($request, 'description', 2000),
            'status' => $this->enum($request, 'status', ['enabled', 'disabled', 'draft', 'wait', 'accept', 'modify'], 'enabled'),
        ], '手工维护实习任务');
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
        $teacherIds = $this->intArray($request->input('teacher_ids', []));
        if (!$teacherIds && $this->isStudent()) {
            $teacherIds = InternshipRecord::activePairTeacherIds($studentId, $arrangementId);
        }
        $this->syncJoinTeachers($id, $studentId, $arrangementId, $teacherIds);
        if ($status === 'wait') {
            $this->recordWorkflow('application_recording', 'application', $id, 'submit', $fromStatus, 'wait', $values['remark'] ?: '提交实习申请', 'wait');
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
        $teacherIds = InternshipRecord::activePairTeacherIds((int) $row->student_id, (int) $row->arrangement_id);
        $this->syncJoinTeachers($id, (int) $row->student_id, (int) $row->arrangement_id, $teacherIds);
        $this->recordWorkflow('application_recording', 'application', $id, 'submit', (string) $row->status, 'wait', (string) ($row->remark ?: '提交实习申请'), 'wait');

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
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('仅待审核实习申请可处理', 42204);
            }

            $updates = ['updated_at' => $this->now()];
            $action = 'review';
            if ($this->isTeacher()) {
                if (!in_array((string) $row->teacher_status, ['pending', 'wait'], true)) {
                    throw new InvalidArgumentException('当前教师审核节点已处理', 42204);
                }
                $updates['teacher_status'] = $status;
                $action = 'teacher_review';
                $this->reviewJoinTeacher($id, $status);
            } else {
                $this->requireAdminRole();
                if (!in_array((string) $row->admin_status, ['pending', 'wait'], true)) {
                    throw new InvalidArgumentException('当前管理审核节点已处理', 42204);
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
            'cycles' => $this->timelineCycles($records, $reviews),
            'items' => $this->timelineItems($records, $reviews),
        ];
    }

    public function requestModification(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $entity = $this->reviewEntity($request);
        $config = self::REVIEW_ENTITY_CONFIG[$entity];
        $id = $this->requiredRowId($request, $config['table']);
        $opinion = $this->reviewOpinionInput($request, $entity, 'modify', '修改理由');

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
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
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
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
    }

    public function saveSignIn(Request $request): array
    {
        $this->requirePermission($this->isStudent() ? 'internship:sign' : 'internship:manage');
        $studentId = $this->isStudent() ? $this->currentStudentId(true) : $this->requiredInt($request, 'student_id');
        $arrangementId = $this->requiredInt($request, 'arrangement_id');
        $this->assertStudentVisible($studentId);
        $this->assertArrangementVisible($arrangementId);
        $signType = $this->enum($request, 'sign_type', ['gps', 'qrcode', 'manual'], $this->isStudent() ? 'gps' : 'manual');
        $longitude = $this->coordinateInput($request, 'longitude', -180, 180);
        $latitude = $this->coordinateInput($request, 'latitude', -90, 90);
        if ($this->isStudent() && $signType === 'gps' && ($longitude === null || $latitude === null)) {
            throw new InvalidArgumentException('GPS 定位坐标不能为空', 42201);
        }

        $values = [
            'student_id' => $studentId,
            'entity_type' => 'internship',
            'entity_id' => $arrangementId,
            'date' => $this->dateInput($request, 'date') ?: date('Y-m-d'),
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

        return $this->saveRow('sign_in', $request, $values);
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
            $this->recordWorkflow('journal_recording', 'journal', (int) $result['id'], 'submit', $fromStatus, 'wait', $values['content'], 'wait');
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
            $this->recordWorkflow('report_recording', 'report', (int) $result['id'], 'submit', $fromStatus, 'wait', $values['content'], 'wait');
        }

        return $result;
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

        $this->assertStudentVisible($studentId);
        if ($entityType === 'internship') {
            $this->assertArrangementVisible($entityId);
        }
        if ($existingId) {
            $row = $this->row('apply_report_delay', $existingId);
            $this->assertStudentVisible((int) $row->student_id);
        }

        $values = [
            'student_id' => $studentId,
            'config_key' => $configKey,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'requested_date' => $this->requiredDate($request, 'requested_date'),
            'reason' => $this->requiredString($request, 'reason', 2000),
            'status' => 'wait',
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $result = $this->saveRow('apply_report_delay', $request, $values);
        $this->recordWorkflow('apply_report_delay_recording', 'delay', (int) $result['id'], 'submit', $fromStatus, 'wait', $values['reason'], 'wait');

        return $result;
    }

    public function reviewDelay(Request $request): array
    {
        $this->requirePermission('internship:approve');
        $id = $this->requiredRowId($request, 'apply_report_delay');
        $status = $this->enum($request, 'status', ['accept', 'refuse'], 'accept');
        $opinion = $this->reviewOpinionInput($request, 'delay', $status);

        return $this->connection()->transaction(function () use ($id, $status, $opinion): array {
            $row = InternshipRecord::lockActiveRowById('apply_report_delay', $id);
            if (!$row) {
                throw new RuntimeException('延期申请不存在');
            }
            $this->assertStudentVisible((int) $row->student_id);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('仅待审核延期申请可处理', 42204);
            }

            InternshipRecord::updateById('apply_report_delay', $id, [
                'status' => $status,
                'updated_at' => $this->now(),
            ]);
            if ($status === 'accept') {
                $this->saveDelayConfig($row);
            }

            $action = $this->isTeacher() ? 'teacher_review' : 'admin_review';
            $this->recordWorkflow('apply_report_delay_recording', 'delay', $id, $action, (string) $row->status, $status, $opinion ?: '延期申请审核', $status);

            return ['id' => $id, 'status' => $status];
        });
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
            'page', 'page_size', 'per_page', 'status', 'grade_id', 'dep_id', 'profession_id', 'keyword',
        ]));
    }

    public function savePlan(Request $request): array
    {
        $this->requirePermission('internship:plan');
        $this->requireAdminRole();
        $existingId = $this->inputRowId($request, 'internship_plan');
        $fromStatus = $existingId ? InternshipRecord::statusById('internship_plan', $existingId) : 'draft';
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
            'student_count' => $this->optionalInt($request, 'student_count') ?? 0,
            'score_rule' => $this->enum($request, 'score_rule', ['average', 'sum', 'weighted'], 'average'),
            'plan_content' => $this->jsonValue($request->input('plan_content', [])),
            'submitter_id' => CurrentContext::accountId(),
            'status' => $this->enum($request, 'status', ['draft', 'wait'], 'draft'),
            'updated_at' => $this->now(),
            'deleted_at' => null,
        ];

        $result = $this->saveRow('internship_plan', $request, $values);
        if ($values['status'] === 'wait') {
            $this->recordWorkflow('plan_recording', 'plan', (int) $result['id'], 'submit', $fromStatus, 'wait', $this->planWorkflowContent($values['plan_content']), 'wait');
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
        $levelName = $this->nullableString($request, 'level_name', 80);

        return $this->connection()->transaction(function () use ($planId, $status, $opinion, $level, $levelName): array {
            $now = $this->now();
            $row = InternshipRecord::lockActiveRowById('internship_plan', $planId);
            if (!$row) {
                throw new RuntimeException('实习计划不存在');
            }
            $this->assertPlanVisible($planId);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('仅待审核实习计划可处理', 42204);
            }
            $from = (string) $row->status;

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
            ], $status, $now);
            $this->recordWorkflow('plan_recording', 'plan', $planId, 'review', $from, $status, $opinion ?: '实习计划审核', $status);

            return ['id' => $approvalId, 'plan_id' => $planId];
        });
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
        return InternshipRecord::syllabusGuidePage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'status',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
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
        return InternshipRecord::inspectionPage($this->scopeContext(), $this->requestFilters($request, [
            'page', 'page_size', 'per_page', 'keyword', 'arrangement_id', 'result',
            'dep_id', 'profession_id', 'grade_id', 'class_id', 'semester',
        ]));
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

        return $this->connection()->transaction(function () use ($table, $recordingTable, $id, $status, $opinion, $score, $teacherId, $now): array {
            $row = InternshipRecord::lockActiveRowById($table, $id);
            if (!$row) {
                throw new RuntimeException('数据不存在');
            }
            $this->assertStudentVisible((int) $row->student_id);
            if ((string) $row->status !== 'wait') {
                throw new InvalidArgumentException('仅待审核数据可评阅', 42204);
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

            return ['id' => $id, 'status' => $status];
        });
    }

    private function persistArrangement(array $input, string $source): array
    {
        $planId = (int) ($input['plan_id'] ?? 0);
        $teacherId = (int) ($input['teacher_id'] ?? 0);
        $classIds = $this->intArray($input['class_ids'] ?? []);
        $title = trim((string) ($input['title'] ?? ''));
        $startDate = (string) ($input['start_date'] ?? '');
        $endDate = (string) ($input['end_date'] ?? '');
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

        return $this->connection()->transaction(function () use ($classRows, $endDate, $existingId, $input, $plan, $planId, $source, $startDate, $teacherId, $title): array {
            $now = $this->now();
            $studentRows = InternshipRecord::studentRowsByClassIds(array_column($classRows, 'class_id'));
            $studentCounts = [];
            foreach ($studentRows as $studentRow) {
                $classId = (int) ($studentRow['class_id'] ?? 0);
                $studentCounts[$classId] = ($studentCounts[$classId] ?? 0) + 1;
            }

            $before = $existingId > 0 ? InternshipRecord::arrangementDetail($this->scopeContext(), $existingId) : null;
            $values = [
                'plan_id' => $planId,
                'name' => trim((string) ($input['name'] ?? '')) ?: $title,
                'base_id' => $input['base_id'] ?? null,
                'dep_id' => (int) $plan->dep_id,
                'profession_id' => (int) $plan->profession_id,
                'semester' => (string) ($plan->semester ?? ''),
                'teacher_id' => $teacherId,
                'task_no' => $input['task_no'] ?? null,
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

            if ($existingId > 0) {
                InternshipRecord::updateById('arrangement', $existingId, $values);
                $arrangementId = $existingId;
                $uuid = (string) InternshipRecord::uuidById('arrangement', $existingId);
            } else {
                $uuid = (string) ($input['uuid'] ?? '') ?: $this->uuid();
                $arrangementId = InternshipRecord::insertRow('arrangement', array_merge($values, [
                    'uuid' => $uuid,
                    'created_at' => $now,
                ]));
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
                $existingId > 0 ? 'change' : 'create',
                $before['item']['status'] ?? 'draft',
                (string) $values['status'],
                $this->arrangementWorkflowContent($source, $before, $after),
                'accept'
            );

            return [
                'id' => $arrangementId,
                'uuid' => $uuid,
                'created' => $existingId <= 0,
                'class_count' => count($classRows),
                'pair_count' => count($pairResult['pair_ids']),
                'student_count' => count($pairResult['student_ids']),
                'item' => InternshipRecord::activeRowById('arrangement', $arrangementId),
            ];
        });
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

        $now = $this->now();
        $planId = InternshipRecord::planIdForImport([
            'course_code' => trim((string) ($row['course_code'] ?? '')),
            'course_name' => $this->requiredImportValue($row, 'course_name', '课程名称'),
            'grade_id' => (int) $grade['grade_id'],
            'dep_id' => (int) $department['dep_id'],
            'profession_id' => (int) $profession['profession_id'],
            'semester' => '',
            'credit' => $credit,
            'student_count' => $this->optionalImportInt($row['student_count'] ?? null) ?? 0,
            'score_rule' => 'average',
            'submitter_id' => CurrentContext::accountId(),
            'plan_content' => [
                'source' => '任务分配导入',
                'row_number' => (int) ($row['row_number'] ?? 0),
            ],
        ], $this->uuid(), $now);

        $taskNo = $this->requiredImportValue($row, 'task_no', '任务编号');
        return $this->persistArrangement([
            'id' => InternshipRecord::arrangementIdByPlanTaskNo($planId, $taskNo),
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
            'status' => 'enabled',
        ], 'Excel导入任务分配');
    }

    private function arrangementWorkflowContent(string $source, ?array $before, ?array $after): string
    {
        $afterItem = $after['item'] ?? [];
        if (!$before) {
            return sprintf(
                '%s：%s，负责老师 %s，绑定 %d 个班级、%d 名学生。',
                $source,
                $afterItem['title'] ?? '-',
                $afterItem['teacher_name'] ?? '-',
                count($after['classes'] ?? []),
                count($after['students'] ?? [])
            );
        }

        $beforeItem = $before['item'] ?? [];
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

        return $source . '：' . ($changes ? implode('；', $changes) : '任务信息未发生实质变化');
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

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function connection(): mixed
    {
        return InternshipRecord::connection();
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
