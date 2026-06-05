<?php

namespace app\model\channel;

class InternshipRecord extends TableRecord
{
    private const STAT_DATA_LIMIT = 20000;
    private const ARCHIVE_MATERIALS = [
        'plan' => '实习计划表',
        'implementation_sheet' => '教学实习实施表',
        'syllabus_guide' => '实习大纲及指导书',
        'score_summary' => '实习情况及成绩汇总表',
        'safety_letter' => '校外实习安全承诺书',
        'journal' => '实习周志',
        'report' => '实习/实训报告',
        'graduation_appraisal' => '毕业实习报告及成绩鉴定表',
        'teacher_work_report' => '实习指导教师工作报告',
        'insurance' => '保险单',
    ];
    private const ARCHIVE_REQUIREMENTS = [
        'cognition_internal' => ['plan', 'syllabus_guide', 'report'],
        'cognition_external' => ['plan', 'implementation_sheet', 'syllabus_guide', 'score_summary', 'safety_letter', 'report', 'teacher_work_report', 'insurance'],
        'major_internal' => ['plan', 'syllabus_guide', 'report'],
        'major_external' => ['plan', 'implementation_sheet', 'syllabus_guide', 'score_summary', 'safety_letter', 'report', 'teacher_work_report', 'insurance'],
        'production' => ['plan', 'implementation_sheet', 'syllabus_guide', 'score_summary', 'safety_letter', 'report', 'teacher_work_report', 'insurance'],
        'graduation' => ['plan', 'syllabus_guide', 'score_summary', 'safety_letter', 'journal', 'report', 'graduation_appraisal', 'teacher_work_report', 'insurance'],
    ];
    private const STAT_REPORT_NAMES = [
        'overview' => '实习总览',
        'department' => '学院统计',
        'profession' => '专业统计',
        'teacher' => '指导统计',
        'student' => '学生过程统计',
        'archive' => '归档材料统计',
    ];

    public static function overviewRows(array $scope, string $today): array
    {
        return [
            'arrangements' => (int) self::applyArrangementScope(self::queryTable('arrangement')->whereNull('deleted_at'), $scope)->count(),
            'applications_waiting' => (int) self::applyApplicationScope(self::queryTable('application')->whereNull('deleted_at')->where('status', 'wait'), $scope)->count(),
            'active_pairs' => (int) self::applyStudentScope(self::queryTable('pair')->where('type', 'internship')->where('status', 'active')->whereNull('deleted_at'), $scope, 'pair.student_id')->count(),
            'journals_waiting' => (int) self::applyStudentScope(self::queryTable('journal')->where('entity_type', 'internship')->where('status', 'wait')->whereNull('deleted_at'), $scope, 'journal.student_id')->count(),
            'reports_waiting' => (int) self::applyStudentScope(self::queryTable('report')->where('status', 'wait')->whereNull('deleted_at'), $scope, 'report.student_id')->count(),
            'today_sign_ins' => (int) self::applyStudentScope(self::queryTable('sign_in')->where('entity_type', 'internship')->where('date', $today)->whereNull('deleted_at'), $scope, 'sign_in.student_id')->count(),
        ];
    }

    public static function optionRows(array $scope): array
    {
        $departments = self::applyOptionScope(self::queryTable('department')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'dep_id', null);
        $grades = self::queryTable('grade_list')->where('flag', 'on')->whereNull('deleted_at');
        $professions = self::applyOptionScope(self::queryTable('profession')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'dep_id', 'profession_id');
        $classes = self::applyOptionScope(self::queryTable('class')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'dep_id', 'profession_id');
        $companies = self::applyCompanyScope(self::queryTable('companies')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'company_id');
        $teachers = self::applyOptionScope(self::queryTable('teacher_list')->where('status', 'enabled')->whereNull('deleted_at'), $scope, 'dep_id', 'profession_id');
        if (($scope['role_type'] ?? '') === 'teacher') {
            self::whereInOrDeny($teachers, 'teacher_id', [(int) ($scope['teacher_id'] ?? 0)]);
        }
        $students = self::applyStudentScope(self::queryTable('students')->where('status', 'enabled')->whereNull('deleted_at'), $scope, 'students.student_id');

        return [
            'departments' => self::rows($departments->orderBy('sort')->get(['dep_id', 'dep_name', 'dep_code'])),
            'grades' => self::rows($grades->orderBy('sort')->get(['grade_id', 'grade_name'])),
            'professions' => self::rows($professions->orderBy('sort')->get(['profession_id', 'profession_name', 'profession_code', 'dep_id', 'grade_id'])),
            'classes' => self::rows($classes->orderBy('sort')->get(['class_id', 'class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id'])),
            'companies' => self::rows($companies->orderBy('company_id')->get(['company_id', 'company_name', 'contact_name', 'contact_mobile'])),
            'teachers' => self::rows($teachers->orderBy('teacher_id')->get(['teacher_id', 'teacher_name', 'teacher_num', 'dep_id', 'profession_id'])),
            'students' => self::rows($students->orderBy('student_id')->get(['student_id', 'name', 'student_num', 'grade_id', 'dep_id', 'profession_id', 'class_id'])),
            'bases' => self::rows(self::applyBaseScope(self::queryTable('base')->where('base.status', 'enabled')->whereNull('base.deleted_at'), $scope)->orderBy('base.id')->get(['base.id', 'base.name', 'base.company_id', 'base.dep_id'])),
            'arrangements' => self::rows(self::applyArrangementScope(self::queryTable('arrangement')->whereNull('deleted_at'), $scope)->orderByDesc('id')->get(['id', 'uuid', 'title', 'name', 'type', 'organize_mode', 'semester', 'dep_id', 'profession_id', 'start_date', 'end_date', 'status'])),
            'report_templates' => self::rows(self::queryTable('report_template')->where('status', 'enabled')->whereNull('deleted_at')->orderBy('id')->get(['id', 'uuid', 'name', 'code', 'version', 'online_enabled'])),
        ];
    }

    public static function basePage(array $scope, array $filters): array
    {
        $query = self::applyBaseScope(self::queryTable('base')
            ->leftJoin('companies', 'base.company_id', '=', 'companies.company_id')
            ->leftJoin('department', 'base.dep_id', '=', 'department.dep_id')
            ->whereNull('base.deleted_at'), $scope);
        self::keyword($query, $filters, ['base.name', 'base.code', 'companies.company_name']);

        return self::paginate($query->orderByDesc('base.id'), $filters, [
            'base.id', 'base.uuid', 'base.name', 'base.code', 'base.company_id', 'base.dep_id',
            'base.address', 'base.capacity', 'base.used_count', 'base.status', 'base.created_at',
            'companies.company_name', 'department.dep_name',
        ]);
    }

    public static function mentorPage(array $scope, array $filters): array
    {
        $query = self::queryTable('enterprise_mentor')
            ->leftJoin('companies', 'enterprise_mentor.company_id', '=', 'companies.company_id')
            ->whereNull('enterprise_mentor.deleted_at');
        self::applyCompanyScope($query, $scope, 'enterprise_mentor.company_id');
        self::keyword($query, $filters, ['enterprise_mentor.name', 'enterprise_mentor.phone', 'companies.company_name']);

        return self::paginate($query->orderByDesc('enterprise_mentor.id'), $filters, [
            'enterprise_mentor.id', 'enterprise_mentor.uuid', 'enterprise_mentor.company_id',
            'enterprise_mentor.name', 'enterprise_mentor.phone', 'enterprise_mentor.position',
            'enterprise_mentor.status', 'companies.company_name',
        ]);
    }

    public static function arrangementPage(array $scope, array $filters): array
    {
        $query = self::applyArrangementScope(self::queryTable('arrangement')
            ->leftJoin('base', 'arrangement.base_id', '=', 'base.id')
            ->leftJoin('department', 'arrangement.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'arrangement.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'profession.grade_id', '=', 'grade_list.grade_id')
            ->whereNull('arrangement.deleted_at'), $scope);
        self::filter($query, $filters, 'arrangement.status', 'status');
        self::filter($query, $filters, 'arrangement.type', 'type');
        self::filter($query, $filters, 'arrangement.organize_mode', 'organize_mode');
        self::listFilters($query, $filters, [
            'dep_id' => 'arrangement.dep_id',
            'profession_id' => 'arrangement.profession_id',
            'grade_id' => 'profession.grade_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['arrangement.title', 'arrangement.name', 'base.name', 'department.dep_name', 'profession.profession_name']);

        return self::paginate($query->orderByDesc('arrangement.id'), $filters, [
            'arrangement.id', 'arrangement.uuid', 'arrangement.name', 'arrangement.base_id',
            'arrangement.dep_id', 'arrangement.profession_id', 'arrangement.semester',
            'arrangement.type', 'arrangement.organize_mode', 'arrangement.title',
            'arrangement.start_date', 'arrangement.end_date', 'arrangement.location',
            'arrangement.description', 'arrangement.status', 'arrangement.created_at',
            'base.name as base_name', 'department.dep_name', 'profession.profession_name',
            'profession.grade_id', 'grade_list.grade_name',
        ]);
    }

    public static function applicationPage(array $scope, array $filters): array
    {
        $query = self::applyApplicationScope(self::queryTable('application')
            ->leftJoin('students', 'application.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'application.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->whereNull('application.deleted_at'), $scope);
        self::filter($query, $filters, 'application.status', 'status');
        self::filter($query, $filters, 'application.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
            'semester' => 'arrangement.semester',
        ]);
        self::applicationKeyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title']);

        return self::paginate($query->orderByDesc('application.id'), $filters, [
            'application.id', 'application.uuid', 'application.student_id', 'application.arrangement_id',
            'application.type', 'application.status', 'application.teacher_status', 'application.admin_status',
            'application.remark', 'application.created_at', 'students.name as student_name',
            'students.student_num', 'department.dep_name', 'profession.profession_name',
            'students.grade_id', 'grade_list.grade_name',
            'arrangement.title as arrangement_title', 'arrangement.type as arrangement_type',
        ]);
    }

    public static function pairPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('pair')
            ->leftJoin('students', 'pair.student_id', '=', 'students.student_id')
            ->leftJoin('teacher_list', 'pair.teacher_id', '=', 'teacher_list.teacher_id')
            ->leftJoin('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->where('pair.type', 'internship')
            ->whereNull('pair.deleted_at'), $scope, 'pair.student_id');
        self::filter($query, $filters, 'pair.status', 'status');
        self::filter($query, $filters, 'pair.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'teacher_list.teacher_name', 'teacher_list.teacher_num', 'arrangement.title']);

        return self::paginate($query->orderByDesc('pair.id'), $filters, [
            'pair.id', 'pair.uuid', 'pair.student_id', 'pair.teacher_id', 'pair.dep_id',
            'pair.second_teacher_id', 'pair.enterprise_mentor_id', 'pair.arrangement_id',
            'pair.application_id', 'pair.status', 'pair.remove_reason', 'pair.created_at',
            'students.name as student_name', 'students.student_num',
            'students.grade_id', 'grade_list.grade_name',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function signInPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('sign_in')
            ->leftJoin('students', 'sign_in.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'sign_in.entity_id', '=', 'arrangement.id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->where('sign_in.entity_type', 'internship')
            ->whereNull('sign_in.deleted_at'), $scope, 'sign_in.student_id');
        self::filter($query, $filters, 'sign_in.entity_id', 'arrangement_id');
        self::filter($query, $filters, 'sign_in.date', 'date');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title', 'sign_in.location'], [
            self::pairTeacherKeyword('sign_in.student_id', 'sign_in.entity_id'),
        ]);

        return self::paginate($query->orderByDesc('sign_in.sign_time'), $filters, [
            'sign_in.id', 'sign_in.uuid', 'sign_in.student_id', 'sign_in.entity_id as arrangement_id',
            'sign_in.date', 'sign_in.sign_time', 'sign_in.sign_type', 'sign_in.location',
            'sign_in.longitude', 'sign_in.latitude', 'sign_in.remark', 'sign_in.status',
            'students.name as student_name', 'students.student_num',
            'students.grade_id', 'grade_list.grade_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function journalPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('journal')
            ->leftJoin('students', 'journal.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'journal.entity_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'journal.teacher_id', '=', 'teacher_list.teacher_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->where('journal.entity_type', 'internship')
            ->whereNull('journal.deleted_at'), $scope, 'journal.student_id');
        self::filter($query, $filters, 'journal.status', 'status');
        self::filter($query, $filters, 'journal.entity_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['journal.title', 'students.name', 'students.student_num', 'teacher_list.teacher_name', 'teacher_list.teacher_num'], [
            self::pairTeacherKeyword('journal.student_id', 'journal.entity_id'),
        ]);

        return self::paginate($query->orderByDesc('journal.date')->orderByDesc('journal.id'), $filters, [
            'journal.id', 'journal.uuid', 'journal.student_id', 'journal.entity_id as arrangement_id',
            'journal.title', 'journal.content', 'journal.date', 'journal.status', 'journal.teacher_id',
            'journal.created_at', 'students.name as student_name', 'students.student_num',
            'students.grade_id', 'grade_list.grade_name',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function reportPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('report')
            ->leftJoin('students', 'report.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'report.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'report.teacher_id', '=', 'teacher_list.teacher_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->whereNull('report.deleted_at'), $scope, 'report.student_id');
        self::filter($query, $filters, 'report.status', 'status');
        self::filter($query, $filters, 'report.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['report.title', 'students.name', 'students.student_num', 'teacher_list.teacher_name', 'teacher_list.teacher_num'], [
            self::pairTeacherKeyword('report.student_id', 'report.arrangement_id'),
        ]);

        return self::paginate($query->orderByDesc('report.id'), $filters, [
            'report.id', 'report.uuid', 'report.student_id', 'report.arrangement_id', 'report.template_id',
            'report.title', 'report.content', 'report.status', 'report.teacher_id',
            'report.submitted_at', 'report.reviewed_at', 'report.created_at',
            'students.name as student_name', 'students.student_num',
            'students.grade_id', 'grade_list.grade_name',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function delayPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('apply_report_delay')
            ->leftJoin('students', 'apply_report_delay.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'apply_report_delay.entity_id', '=', 'arrangement.id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->where('apply_report_delay.entity_type', 'internship')
            ->whereNull('apply_report_delay.deleted_at'), $scope, 'apply_report_delay.student_id');
        self::filter($query, $filters, 'apply_report_delay.status', 'status');
        self::filter($query, $filters, 'apply_report_delay.config_key', 'config_key');
        self::filter($query, $filters, 'apply_report_delay.entity_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, [
            'students.name',
            'students.student_num',
            'arrangement.title',
            'apply_report_delay.config_key',
            'apply_report_delay.reason',
        ], [
            self::pairTeacherKeyword('apply_report_delay.student_id', 'apply_report_delay.entity_id'),
        ]);

        return self::paginate($query->orderByDesc('apply_report_delay.id'), $filters, [
            'apply_report_delay.id', 'apply_report_delay.uuid', 'apply_report_delay.student_id',
            'apply_report_delay.config_key', 'apply_report_delay.entity_type',
            'apply_report_delay.entity_id as arrangement_id', 'apply_report_delay.requested_date',
            'apply_report_delay.reason', 'apply_report_delay.status', 'apply_report_delay.created_at',
            'students.name as student_name', 'students.student_num',
            'department.dep_name', 'profession.profession_name',
            'students.grade_id', 'grade_list.grade_name',
            'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    public static function scorePage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('score')
            ->leftJoin('students', 'score.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'score.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'score.teacher_id', '=', 'teacher_list.teacher_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->whereNull('score.deleted_at'), $scope, 'score.student_id');
        self::filter($query, $filters, 'score.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title', 'teacher_list.teacher_name', 'teacher_list.teacher_num']);

        return self::paginate($query->orderByDesc('score.id'), $filters, [
            'score.id', 'score.uuid', 'score.student_id', 'score.arrangement_id',
            'score.sign_in_score', 'score.journal_score', 'score.report_score',
            'score.sign_in_weight', 'score.journal_weight', 'score.report_weight',
            'score.enterprise_score', 'score.enterprise_comment', 'score.final_score',
            'score.teacher_id', 'score.comment', 'students.name as student_name',
            'students.student_num', 'students.grade_id', 'grade_list.grade_name',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function statReport(array $scope, array $filters, string $today): array
    {
        $report = self::statReportKey($filters);
        $data = self::statData($scope, $filters);
        [$columns, $rows] = match ($report) {
            'department' => [self::departmentStatColumns(), self::groupStatRows($data, 'dep_id', 'dep_name', '未分配学院')],
            'profession' => [self::professionStatColumns(), self::groupStatRows($data, 'profession_id', 'profession_name', '未分配专业')],
            'teacher' => [self::teacherStatColumns(), self::teacherStatRows($data)],
            'student' => [self::studentStatColumns(), self::studentStatRows($data)],
            'archive' => [self::archiveStatColumns(), self::archiveStatRows($data)],
            default => [self::overviewStatColumns(), self::overviewStatRows($data)],
        };
        $paged = self::paginateArrayRows($rows, $filters);

        return [
            'report' => $report,
            'title' => self::STAT_REPORT_NAMES[$report] ?? self::STAT_REPORT_NAMES['overview'],
            'generated_at' => date('Y-m-d H:i:s'),
            'cards' => self::statCards($data, $today),
            'columns' => $columns,
            'rows' => $paged['items'],
            'pagination' => $paged['pagination'],
        ];
    }

    public static function planPage(array $scope, array $filters): array
    {
        $query = self::queryTable('internship_plan')
            ->leftJoin('department', 'internship_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('account', 'internship_plan.submitter_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->whereNull('internship_plan.deleted_at');
        self::applyDepProfessionScope($query, $scope, 'internship_plan.dep_id', null);
        self::filter($query, $filters, 'internship_plan.status', 'status');
        self::filter($query, $filters, 'internship_plan.semester', 'semester');
        self::filter($query, $filters, 'internship_plan.dep_id', 'dep_id');
        self::keyword($query, $filters, ['internship_plan.semester', 'department.dep_name', 'users.name']);

        return self::paginate($query->orderByDesc('internship_plan.id'), $filters, [
            'internship_plan.id', 'internship_plan.uuid', 'internship_plan.dep_id',
            'internship_plan.semester', 'internship_plan.plan_content', 'internship_plan.status',
            'internship_plan.submitter_id', 'internship_plan.created_at',
            'department.dep_name', 'users.name as submitter_name',
        ]);
    }

    public static function insertPlanApproval(int $planId, array $values, string $planStatus, string $now): int
    {
        $approvalId = self::insertRow('internship_plan_approval', $values);
        self::updateById('internship_plan', $planId, [
            'status' => $planStatus,
            'updated_at' => $now,
        ]);

        return $approvalId;
    }

    public static function syllabusGuidePage(array $scope, array $filters): array
    {
        $query = self::applyArrangementScope(self::queryTable('syllabus_guide')
            ->leftJoin('arrangement', 'syllabus_guide.arrangement_id', '=', 'arrangement.id')
            ->whereNull('syllabus_guide.deleted_at'), $scope);

        return self::paginate($query->orderByDesc('syllabus_guide.id'), $filters, ['syllabus_guide.*', 'arrangement.title as arrangement_title']);
    }

    public static function documentPage(string $table, array $columns, array $scope, array $filters): array
    {
        $query = self::queryTable($table)->whereNull("{$table}.deleted_at");
        if (in_array($table, ['insurance', 'safety_letter_sign'], true)) {
            $query->leftJoin('students', "{$table}.student_id", '=', 'students.student_id')
                ->leftJoin('arrangement', "{$table}.arrangement_id", '=', 'arrangement.id')
                ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id');
            self::applyStudentScope($query, $scope, "{$table}.student_id");
            self::listFilters($query, $filters, [
                'dep_id' => 'students.dep_id',
                'profession_id' => 'students.profession_id',
                'grade_id' => 'students.grade_id',
                'class_id' => 'students.class_id',
                'semester' => 'arrangement.semester',
            ]);
            $keywordColumns = ['students.name', 'students.student_num', 'arrangement.title'];
            if ($table === 'insurance') {
                $keywordColumns[] = 'insurance.insurance_company';
                $keywordColumns[] = 'insurance.policy_number';
            }
            self::keyword($query, $filters, $keywordColumns, [
                self::pairTeacherKeyword("{$table}.student_id", "{$table}.arrangement_id"),
            ]);
        }
        if (in_array($table, ['implementation_sheet', 'teacher_work_report'], true)) {
            self::applyArrangementIdScope($query, $scope, "{$table}.arrangement_id");
        }
        self::filter($query, $filters, "{$table}.arrangement_id", 'arrangement_id');
        self::filter($query, $filters, "{$table}.status", 'status');

        return self::paginate($query->orderByDesc("{$table}.id"), $filters, $columns);
    }

    public static function inspectionPage(array $filters): array
    {
        return self::paginate(self::queryTable('inspection_record')
            ->whereNull('deleted_at')
            ->orderByDesc('id'), $filters, ['inspection_record.*']);
    }

    public static function archiveMaterialPage(array $scope, array $filters): array
    {
        $dataFilters = $filters;
        unset($dataFilters['keyword'], $dataFilters['archive_status'], $dataFilters['status']);
        $data = self::statData($scope, $dataFilters);
        $rows = self::filterArchiveRows(self::archiveMaterialRows($data), $filters);
        $paged = self::paginateArrayRows($rows, $filters);

        return [
            'items' => $paged['items'],
            'pagination' => $paged['pagination'],
            'columns' => self::archiveMaterialColumns(),
            'materials' => self::archiveMaterialDefinitions(),
        ];
    }

    public static function arrangementVisible(array $scope, int $arrangementId): bool
    {
        return self::applyArrangementScope(self::queryTable('arrangement')
            ->where('arrangement.id', $arrangementId)
            ->whereNull('arrangement.deleted_at'), $scope)
            ->exists();
    }

    public static function applicationVisible(array $scope, int $applicationId): bool
    {
        return self::applyApplicationScope(self::queryTable('application')
            ->where('application.id', $applicationId)
            ->whereNull('application.deleted_at'), $scope)
            ->exists();
    }

    public static function planVisible(array $scope, int $planId): bool
    {
        $query = self::queryTable('internship_plan')
            ->where('internship_plan.id', $planId)
            ->whereNull('internship_plan.deleted_at');
        self::applyDepProfessionScope($query, $scope, 'internship_plan.dep_id', null);
        return $query->exists();
    }

    public static function departmentVisible(array $scope, int $depId): bool
    {
        $query = self::queryTable('department')
            ->where('department.dep_id', $depId)
            ->whereNull('department.deleted_at');
        self::applyDepProfessionScope($query, $scope, 'department.dep_id', null);
        return $query->exists();
    }

    public static function teacherIdByUser(int $userId): ?int
    {
        $teacherId = self::queryTable('teacher_list')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->value('teacher_id');

        return $teacherId ? (int) $teacherId : null;
    }

    public static function studentIdByUser(int $userId): ?int
    {
        $studentId = self::queryTable('students')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->value('student_id');

        return $studentId ? (int) $studentId : null;
    }

    public static function studentDepId(int $studentId): ?int
    {
        $depId = self::queryTable('students')
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->value('dep_id');

        return $depId ? (int) $depId : null;
    }

    public static function studentUserId(int $studentId): ?int
    {
        $userId = self::queryTable('students')
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $userId ? (int) $userId : null;
    }

    public static function depIdsByProfessionIds(array $professionIds): array
    {
        $professionIds = self::ids($professionIds);
        if (!$professionIds) {
            return [];
        }

        return self::intValues(self::queryTable('profession')
            ->whereIn('profession_id', $professionIds)
            ->whereNull('deleted_at')
            ->pluck('dep_id'));
    }

    public static function studentIdsByDepartments(array $depIds): array
    {
        $depIds = self::ids($depIds);
        if (!$depIds) {
            return [];
        }

        return self::intValues(self::queryTable('students')
            ->whereIn('dep_id', $depIds)
            ->whereNull('deleted_at')
            ->pluck('student_id'));
    }

    public static function studentIdsByProfessions(array $professionIds): array
    {
        $professionIds = self::ids($professionIds);
        if (!$professionIds) {
            return [];
        }

        return self::intValues(self::queryTable('students')
            ->whereIn('profession_id', $professionIds)
            ->whereNull('deleted_at')
            ->pluck('student_id'));
    }

    public static function studentIdsByTeacher(int $teacherId): array
    {
        if ($teacherId <= 0) {
            return [];
        }

        return self::intValues(self::queryTable('pair')
            ->where('teacher_id', $teacherId)
            ->where('type', 'internship')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('student_id'));
    }

    public static function arrangementIdsByTeacher(int $teacherId): array
    {
        if ($teacherId <= 0) {
            return [];
        }

        return self::intValues(self::queryTable('pair')
            ->where('teacher_id', $teacherId)
            ->where('type', 'internship')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('arrangement_id'));
    }

    public static function arrangementIdsByStudent(int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }

        $applicationIds = self::intValues(self::queryTable('application')
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->pluck('arrangement_id'));
        $pairIds = self::intValues(self::queryTable('pair')
            ->where('student_id', $studentId)
            ->where('type', 'internship')
            ->whereNull('deleted_at')
            ->pluck('arrangement_id'));

        return self::ids(array_merge($applicationIds, $pairIds));
    }

    public static function visibleArrangementIdsByStudent(int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }

        $student = self::queryTable('students')
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->first(['dep_id', 'profession_id']);
        if (!$student) {
            return [];
        }

        $query = self::queryTable('arrangement')
            ->whereNull('deleted_at')
            ->whereIn('status', ['enabled', 'wait', 'accept']);
        $query->where(function ($builder) use ($student): void {
            $builder->whereNull('dep_id')->orWhere('dep_id', (int) $student->dep_id);
        });
        $query->where(function ($builder) use ($student): void {
            $builder->whereNull('profession_id')->orWhere('profession_id', (int) $student->profession_id);
        });

        return self::ids(array_merge(
            self::arrangementIdsByStudent($studentId),
            self::intValues($query->pluck('id'))
        ));
    }

    public static function applicationIdsByTeacher(int $teacherId): array
    {
        if ($teacherId <= 0) {
            return [];
        }

        return self::intValues(self::queryTable('student_join_teacher')
            ->where('teacher_id', $teacherId)
            ->where('application_type', 'internship')
            ->whereNull('deleted_at')
            ->pluck('application_id'));
    }

    public static function baseIdsByCompanies(array $companyIds): array
    {
        $companyIds = self::ids($companyIds);
        if (!$companyIds) {
            return [];
        }

        return self::intValues(self::queryTable('base')
            ->whereIn('company_id', $companyIds)
            ->whereNull('deleted_at')
            ->pluck('id'));
    }

    public static function arrangementIdsByBases(array $baseIds): array
    {
        $baseIds = self::ids($baseIds);
        if (!$baseIds) {
            return [];
        }

        return self::intValues(self::queryTable('arrangement')
            ->whereIn('base_id', $baseIds)
            ->whereNull('deleted_at')
            ->pluck('id'));
    }

    public static function activeRowById(string $table, int $id): ?object
    {
        return self::queryTable($table)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();
    }

    public static function lockActiveRowById(string $table, int $id): ?object
    {
        return self::queryTable($table)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
    }

    public static function rowById(string $table, int $id): ?object
    {
        return self::queryTable($table)->where('id', $id)->first();
    }

    public static function updateById(string $table, int $id, array $values): int
    {
        return self::queryTable($table)->where('id', $id)->update($values);
    }

    public static function insertRow(string $table, array $values): int
    {
        return (int) self::queryTable($table)->insertGetId($values);
    }

    public static function upsertActivePair(array $values, string $uuid, string $now): int
    {
        $existing = self::queryTable('pair')
            ->where('student_id', $values['student_id'])
            ->where('arrangement_id', $values['arrangement_id'])
            ->where('type', 'internship')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($existing) {
            self::updateById('pair', (int) $existing->id, $values);
            return (int) $existing->id;
        }

        return self::insertRow('pair', array_merge($values, [
            'uuid' => $uuid,
            'created_at' => $now,
        ]));
    }

    public static function applicationWithTeachers(int $id): ?array
    {
        $row = self::queryTable('application')
            ->leftJoin('students', 'application.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'application.arrangement_id', '=', 'arrangement.id')
            ->where('application.id', $id)
            ->first([
                'application.*',
                'students.name as student_name',
                'students.student_num',
                'arrangement.title as arrangement_title',
            ]);
        if (!$row) {
            return null;
        }

        $item = $row->getAttributes();
        $item['teachers'] = self::joinTeacherRows($id);

        return $item;
    }

    public static function applicationIdByStudentArrangement(int $studentId, int $arrangementId): int
    {
        return (int) (self::queryTable('application')
            ->where('student_id', $studentId)
            ->where('arrangement_id', $arrangementId)
            ->whereNull('deleted_at')
            ->value('id') ?: 0);
    }

    public static function statusById(string $table, int $id, string $default = 'draft'): string
    {
        return (string) (self::queryTable($table)->where('id', $id)->value('status') ?: $default);
    }

    public static function studentProfile(int $studentId): ?object
    {
        return self::queryTable('students')->where('student_id', $studentId)->first();
    }

    public static function teacherProfile(int $teacherId): ?object
    {
        return self::queryTable('teacher_list')->where('teacher_id', $teacherId)->first();
    }

    public static function joinTeacherExists(int $applicationId, int $teacherId): bool
    {
        return self::queryTable('student_join_teacher')
            ->where('application_id', $applicationId)
            ->where('teacher_id', $teacherId)
            ->whereNull('deleted_at')
            ->exists();
    }

    public static function insertJoinTeacher(array $values): int
    {
        return self::insertRow('student_join_teacher', $values);
    }

    public static function updateJoinTeacherStatus(int $applicationId, int $teacherId, string $status, string $now): int
    {
        return self::queryTable('student_join_teacher')
            ->where('application_id', $applicationId)
            ->where('teacher_id', $teacherId)
            ->whereNull('deleted_at')
            ->update([
                'application_status' => $status,
                'updated_at' => $now,
            ]);
    }

    public static function acceptedJoinTeachers(int $applicationId): array
    {
        return self::queryTable('student_join_teacher')
            ->where('application_id', $applicationId)
            ->where('application_status', 'accept')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public static function idByUuid(string $table, string $uuid): int
    {
        return (int) (self::queryTable($table)->where('uuid', $uuid)->value('id') ?: 0);
    }

    public static function uuidById(string $table, int $id): ?string
    {
        $uuid = self::queryTable($table)->where('id', $id)->value('uuid');
        return $uuid ? (string) $uuid : null;
    }

    public static function activeIdByFields(string $table, array $fields): int
    {
        $query = self::queryTable($table);
        foreach ($fields as $field => $value) {
            $query->where($field, $value);
        }

        return (int) ($query->whereNull('deleted_at')->value('id') ?: 0);
    }

    public static function recordingRows(string $table, int $parentId): array
    {
        return self::queryTable($table)
            ->where('parent_id', $parentId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'parent_id', 'entity_type', 'entity_id', 'action', 'operator_id', 'from_status', 'to_status', 'opinion', 'content', 'status', 'created_at'])
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    public static function reviewOpinionRows(string $entityType, int $entityId): array
    {
        return self::queryTable('review_opinion')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'entity_type', 'entity_id', 'recording_id', 'teacher_id', 'reviewer_id', 'opinion', 'score', 'status', 'created_at'])
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    private static function joinTeacherRows(int $applicationId): array
    {
        return self::queryTable('student_join_teacher')
            ->where('application_id', $applicationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => $row->getAttributes())
            ->all();
    }

    private static function statData(array $scope, array $filters): array
    {
        $students = self::statStudents($scope, $filters);
        $arrangements = self::statArrangements($scope, $filters);
        $teachers = self::statTeachers($scope);
        $applications = self::statApplications($scope, $filters);
        $pairs = self::statPairs($scope, $filters);
        $signIns = self::statSignIns($scope, $filters);
        $journals = self::statJournals($scope, $filters);
        $reports = self::statReports($scope, $filters);
        $scores = self::statScores($scope, $filters);
        $insurances = self::statInsurances($scope, $filters);
        $safetyLetters = self::statSafetyLetters($scope, $filters);
        $plans = self::statPlans($scope, $filters);
        $syllabusGuides = self::statSyllabusGuides($scope, $filters);
        $implementationSheets = self::statImplementationSheets($scope, $filters);
        $teacherWorkReports = self::statTeacherWorkReports($scope, $filters);

        return [
            'students' => $students,
            'student_map' => self::indexRows($students, 'student_id'),
            'arrangements' => $arrangements,
            'arrangement_map' => self::indexRows($arrangements, 'id'),
            'teachers' => $teachers,
            'teacher_map' => self::indexRows($teachers, 'teacher_id'),
            'applications' => $applications,
            'pairs' => $pairs,
            'sign_ins' => $signIns,
            'journals' => $journals,
            'reports' => $reports,
            'scores' => $scores,
            'insurances' => $insurances,
            'safety_letters' => $safetyLetters,
            'plans' => $plans,
            'syllabus_guides' => $syllabusGuides,
            'implementation_sheets' => $implementationSheets,
            'teacher_work_reports' => $teacherWorkReports,
        ];
    }

    private static function statStudents(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('students')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->whereNull('students.deleted_at'), $scope, 'students.student_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'class_id' => 'students.class_id',
        ]);
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'department.dep_name', 'profession.profession_name']);

        return self::statRows($query->orderBy('students.student_id'), [
            'students.student_id', 'students.name', 'students.student_num', 'students.dep_id',
            'students.profession_id', 'students.grade_id', 'students.class_id',
            'department.dep_name', 'profession.profession_name', 'grade_list.grade_name',
        ]);
    }

    private static function statTeachers(array $scope): array
    {
        $query = self::applyOptionScope(self::queryTable('teacher_list')
            ->leftJoin('department', 'teacher_list.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'teacher_list.profession_id', '=', 'profession.profession_id')
            ->whereNull('teacher_list.deleted_at'), $scope, 'teacher_list.dep_id', 'teacher_list.profession_id');

        return self::statRows($query->orderBy('teacher_list.teacher_id'), [
            'teacher_list.teacher_id', 'teacher_list.teacher_name', 'teacher_list.teacher_num',
            'teacher_list.dep_id', 'teacher_list.profession_id',
            'department.dep_name', 'profession.profession_name',
        ]);
    }

    private static function statArrangements(array $scope, array $filters): array
    {
        $query = self::applyArrangementScope(self::queryTable('arrangement')
            ->leftJoin('department', 'arrangement.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'arrangement.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'profession.grade_id', '=', 'grade_list.grade_id')
            ->whereNull('arrangement.deleted_at'), $scope);
        self::listFilters($query, $filters, [
            'dep_id' => 'arrangement.dep_id',
            'profession_id' => 'arrangement.profession_id',
            'grade_id' => 'profession.grade_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['arrangement.title', 'arrangement.name', 'department.dep_name', 'profession.profession_name']);

        return self::statRows($query->orderByDesc('arrangement.id'), [
            'arrangement.id', 'arrangement.title', 'arrangement.name', 'arrangement.semester',
            'arrangement.dep_id', 'arrangement.profession_id', 'arrangement.type', 'arrangement.status',
            'profession.grade_id', 'department.dep_name', 'profession.profession_name', 'grade_list.grade_name',
        ]);
    }

    private static function statApplications(array $scope, array $filters): array
    {
        $query = self::applyApplicationScope(self::queryTable('application')
            ->leftJoin('students', 'application.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'application.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->whereNull('application.deleted_at'), $scope);
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::applicationKeyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title', 'department.dep_name', 'profession.profession_name']);

        return self::statRows($query->orderByDesc('application.id'), [
            'application.id', 'application.student_id', 'application.arrangement_id',
            'application.status', 'application.teacher_status', 'application.admin_status',
            'students.name as student_name', 'students.student_num',
            'students.dep_id', 'students.profession_id', 'students.grade_id',
            'department.dep_name', 'profession.profession_name',
            'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statPairs(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('pair')
            ->leftJoin('students', 'pair.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'pair.teacher_id', '=', 'teacher_list.teacher_id')
            ->where('pair.type', 'internship')
            ->whereNull('pair.deleted_at'), $scope, 'pair.student_id');
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title', 'teacher_list.teacher_name']);

        return self::statRows($query->orderByDesc('pair.id'), [
            'pair.id', 'pair.student_id', 'pair.teacher_id', 'pair.arrangement_id', 'pair.status',
            'students.name as student_name', 'students.student_num',
            'students.dep_id', 'students.profession_id', 'students.grade_id',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statSignIns(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('sign_in')
            ->leftJoin('students', 'sign_in.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'sign_in.entity_id', '=', 'arrangement.id')
            ->where('sign_in.entity_type', 'internship')
            ->whereNull('sign_in.deleted_at'), $scope, 'sign_in.student_id');
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title', 'sign_in.location'], [
            self::pairTeacherKeyword('sign_in.student_id', 'sign_in.entity_id'),
        ]);

        return self::statRows($query->orderByDesc('sign_in.sign_time'), [
            'sign_in.id', 'sign_in.student_id', 'sign_in.entity_id as arrangement_id',
            'sign_in.date', 'sign_in.status', 'students.dep_id', 'students.profession_id',
            'students.grade_id', 'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statJournals(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('journal')
            ->leftJoin('students', 'journal.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'journal.entity_id', '=', 'arrangement.id')
            ->where('journal.entity_type', 'internship')
            ->whereNull('journal.deleted_at'), $scope, 'journal.student_id');
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'journal.title', 'arrangement.title'], [
            self::pairTeacherKeyword('journal.student_id', 'journal.entity_id'),
        ]);

        return self::statRows($query->orderByDesc('journal.date')->orderByDesc('journal.id'), [
            'journal.id', 'journal.student_id', 'journal.entity_id as arrangement_id',
            'journal.teacher_id', 'journal.status', 'journal.date',
            'students.dep_id', 'students.profession_id', 'students.grade_id',
            'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statReports(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('report')
            ->leftJoin('students', 'report.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'report.arrangement_id', '=', 'arrangement.id')
            ->whereNull('report.deleted_at'), $scope, 'report.student_id');
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'report.title', 'arrangement.title'], [
            self::pairTeacherKeyword('report.student_id', 'report.arrangement_id'),
        ]);

        return self::statRows($query->orderByDesc('report.id'), [
            'report.id', 'report.student_id', 'report.arrangement_id', 'report.teacher_id',
            'report.title', 'report.status', 'report.submitted_at',
            'students.dep_id', 'students.profession_id', 'students.grade_id',
            'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statScores(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('score')
            ->leftJoin('students', 'score.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'score.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'score.teacher_id', '=', 'teacher_list.teacher_id')
            ->whereNull('score.deleted_at'), $scope, 'score.student_id');
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title', 'teacher_list.teacher_name', 'teacher_list.teacher_num']);

        return self::statRows($query->orderByDesc('score.id'), [
            'score.id', 'score.student_id', 'score.arrangement_id', 'score.teacher_id',
            'score.sign_in_score', 'score.journal_score', 'score.report_score',
            'score.enterprise_score', 'score.final_score',
            'students.dep_id', 'students.profession_id', 'students.grade_id',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statInsurances(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('insurance')
            ->leftJoin('students', 'insurance.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'insurance.arrangement_id', '=', 'arrangement.id')
            ->whereNull('insurance.deleted_at'), $scope, 'insurance.student_id');
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'insurance.insurance_company', 'insurance.policy_number', 'arrangement.title'], [
            self::pairTeacherKeyword('insurance.student_id', 'insurance.arrangement_id'),
        ]);

        return self::statRows($query->orderByDesc('insurance.id'), [
            'insurance.id', 'insurance.student_id', 'insurance.arrangement_id',
            'insurance.insurance_company', 'insurance.policy_number',
            'students.dep_id', 'students.profession_id', 'students.grade_id',
            'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statSafetyLetters(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('safety_letter_sign')
            ->leftJoin('students', 'safety_letter_sign.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'safety_letter_sign.arrangement_id', '=', 'arrangement.id')
            ->whereNull('safety_letter_sign.deleted_at'), $scope, 'safety_letter_sign.student_id');
        self::statStudentListFilters($query, $filters, 'students', 'arrangement');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title'], [
            self::pairTeacherKeyword('safety_letter_sign.student_id', 'safety_letter_sign.arrangement_id'),
        ]);

        return self::statRows($query->orderByDesc('safety_letter_sign.id'), [
            'safety_letter_sign.id', 'safety_letter_sign.student_id',
            'safety_letter_sign.arrangement_id', 'safety_letter_sign.signed_at', 'safety_letter_sign.status',
            'students.dep_id', 'students.profession_id', 'students.grade_id',
            'arrangement.title as arrangement_title', 'arrangement.semester',
        ]);
    }

    private static function statPlans(array $scope, array $filters): array
    {
        $query = self::queryTable('internship_plan')
            ->leftJoin('department', 'internship_plan.dep_id', '=', 'department.dep_id')
            ->whereNull('internship_plan.deleted_at');
        self::applyDepProfessionScope($query, $scope, 'internship_plan.dep_id', null);
        self::filter($query, $filters, 'internship_plan.semester', 'semester');
        self::filter($query, $filters, 'internship_plan.dep_id', 'dep_id');
        self::keyword($query, $filters, ['internship_plan.semester', 'department.dep_name']);

        return self::statRows($query->orderByDesc('internship_plan.id'), [
            'internship_plan.id', 'internship_plan.dep_id', 'internship_plan.semester',
            'internship_plan.status', 'department.dep_name',
        ]);
    }

    private static function statSyllabusGuides(array $scope, array $filters): array
    {
        $query = self::applyArrangementScope(self::queryTable('syllabus_guide')
            ->leftJoin('arrangement', 'syllabus_guide.arrangement_id', '=', 'arrangement.id')
            ->whereNull('syllabus_guide.deleted_at'), $scope);
        self::statArrangementListFilters($query, $filters, 'arrangement');
        self::keyword($query, $filters, ['syllabus_guide.title', 'arrangement.title']);

        return self::statRows($query->orderByDesc('syllabus_guide.id'), [
            'syllabus_guide.id', 'syllabus_guide.arrangement_id', 'syllabus_guide.status',
            'syllabus_guide.title', 'arrangement.title as arrangement_title',
        ]);
    }

    private static function statImplementationSheets(array $scope, array $filters): array
    {
        $query = self::applyArrangementScope(self::queryTable('implementation_sheet')
            ->leftJoin('arrangement', 'implementation_sheet.arrangement_id', '=', 'arrangement.id')
            ->whereNull('implementation_sheet.deleted_at'), $scope);
        self::statArrangementListFilters($query, $filters, 'arrangement');
        self::keyword($query, $filters, ['arrangement.title']);

        return self::statRows($query->orderByDesc('implementation_sheet.id'), [
            'implementation_sheet.id', 'implementation_sheet.arrangement_id',
            'implementation_sheet.status', 'implementation_sheet.insurance_verified',
            'arrangement.title as arrangement_title',
        ]);
    }

    private static function statTeacherWorkReports(array $scope, array $filters): array
    {
        $query = self::applyArrangementScope(self::queryTable('teacher_work_report')
            ->leftJoin('arrangement', 'teacher_work_report.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'teacher_work_report.teacher_id', '=', 'teacher_list.teacher_id')
            ->whereNull('teacher_work_report.deleted_at'), $scope);
        self::statArrangementListFilters($query, $filters, 'arrangement');
        self::keyword($query, $filters, ['arrangement.title', 'teacher_list.teacher_name', 'teacher_list.teacher_num']);

        return self::statRows($query->orderByDesc('teacher_work_report.id'), [
            'teacher_work_report.id', 'teacher_work_report.arrangement_id',
            'teacher_work_report.teacher_id', 'teacher_work_report.status',
            'arrangement.title as arrangement_title', 'teacher_list.teacher_name',
        ]);
    }

    private static function statRows(mixed $query, array $columns): array
    {
        return self::rows($query->limit(self::STAT_DATA_LIMIT)->get($columns));
    }

    private static function statStudentListFilters(mixed $query, array $filters, string $studentTable, string $arrangementTable): void
    {
        self::listFilters($query, $filters, [
            'dep_id' => "{$studentTable}.dep_id",
            'profession_id' => "{$studentTable}.profession_id",
            'grade_id' => "{$studentTable}.grade_id",
            'class_id' => "{$studentTable}.class_id",
            'semester' => "{$arrangementTable}.semester",
            'arrangement_id' => "{$arrangementTable}.id",
        ]);
    }

    private static function statArrangementListFilters(mixed $query, array $filters, string $arrangementTable): void
    {
        self::listFilters($query, $filters, [
            'dep_id' => "{$arrangementTable}.dep_id",
            'profession_id' => "{$arrangementTable}.profession_id",
            'semester' => "{$arrangementTable}.semester",
            'arrangement_id' => "{$arrangementTable}.id",
        ]);
    }

    private static function statReportKey(array $filters): string
    {
        $report = (string) ($filters['report'] ?? 'overview');
        return array_key_exists($report, self::STAT_REPORT_NAMES) ? $report : 'overview';
    }

    private static function statCards(array $data, string $today): array
    {
        $participantIds = self::participantStudentIds($data);
        $accepted = self::countRows($data['applications'], static fn (array $row): bool => (string) ($row['status'] ?? '') === 'accept');
        $todaySignIns = self::countRows($data['sign_ins'], static fn (array $row): bool => (string) ($row['date'] ?? '') === $today);

        return [
            ['name' => '参与学生', 'value' => count($participantIds), 'desc' => '当前筛选范围内有过程记录的学生'],
            ['name' => '申请总数', 'value' => count($data['applications']), 'desc' => '学生提交的实习申请数量'],
            ['name' => '通过申请', 'value' => $accepted, 'desc' => '状态为通过的申请数量'],
            ['name' => '指导关系', 'value' => self::countRows($data['pairs'], static fn (array $row): bool => (string) ($row['status'] ?? '') === 'active'), 'desc' => '有效师生指导关系'],
            ['name' => '平均成绩', 'value' => self::averageText(self::scoreValues($data['scores'])), 'desc' => '已录入总评成绩平均值'],
            ['name' => '今日签到', 'value' => $todaySignIns, 'desc' => '今天完成的实习签到'],
        ];
    }

    private static function overviewStatRows(array $data): array
    {
        $rows = [];
        foreach ($data['arrangements'] as $arrangement) {
            $arrangementId = (int) ($arrangement['id'] ?? 0);
            $applications = self::filterRows($data['applications'], static fn (array $row): bool => (int) ($row['arrangement_id'] ?? 0) === $arrangementId);
            $scores = self::filterRows($data['scores'], static fn (array $row): bool => (int) ($row['arrangement_id'] ?? 0) === $arrangementId);
            $scopeText = implode(' / ', array_filter([
                $arrangement['dep_name'] ?: '全校',
                $arrangement['profession_name'] ?: '全部专业',
                $arrangement['grade_name'] ?? '',
            ]));
            $rows[] = [
                'arrangement_title' => $arrangement['title'] ?: ($arrangement['name'] ?? '-'),
                'scope' => $scopeText ?: '-',
                'applications' => count($applications),
                'accepted_applications' => self::countRows($applications, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'accept'),
                'active_pairs' => self::countRows($data['pairs'], static fn (array $row): bool => (int) ($row['arrangement_id'] ?? 0) === $arrangementId && (string) ($row['status'] ?? '') === 'active'),
                'sign_ins' => self::countRows($data['sign_ins'], static fn (array $row): bool => (int) ($row['arrangement_id'] ?? 0) === $arrangementId),
                'journals' => self::countRows($data['journals'], static fn (array $row): bool => (int) ($row['arrangement_id'] ?? 0) === $arrangementId),
                'reports' => self::countRows($data['reports'], static fn (array $row): bool => (int) ($row['arrangement_id'] ?? 0) === $arrangementId),
                'avg_score' => self::averageText(self::scoreValues($scores)),
            ];
        }

        return $rows;
    }

    private static function groupStatRows(array $data, string $idKey, string $nameKey, string $fallbackName): array
    {
        $groups = [];
        foreach (self::participantKeys($data) as $key) {
            [$studentId, $arrangementId] = self::splitPairKey($key);
            $student = $data['student_map'][$studentId] ?? [];
            $groupId = (string) ($student[$idKey] ?? 0);
            $groupName = (string) ($student[$nameKey] ?? $fallbackName);
            $groups[$groupId] ??= self::emptyStatGroup($groupName);
            $groups[$groupId]['students'][$studentId] = true;
            if ($arrangementId > 0) {
                $groups[$groupId]['arrangements'][$arrangementId] = true;
            }
        }

        foreach ($data['applications'] as $row) {
            self::appendGroupApplication($groups, $data, $row, $idKey, $nameKey, $fallbackName);
        }
        foreach ($data['pairs'] as $row) {
            if ((string) ($row['status'] ?? '') !== 'active') {
                continue;
            }
            self::appendGroupValue($groups, $data, $row, $idKey, $nameKey, $fallbackName, 'active_pairs');
        }
        foreach ($data['scores'] as $row) {
            $groupId = self::groupIdByRow($data, $row, $idKey);
            if ($groupId === null) {
                continue;
            }
            $groups[$groupId] ??= self::emptyStatGroup(self::groupNameByRow($data, $row, $nameKey, $fallbackName));
            if (is_numeric($row['final_score'] ?? null)) {
                $groups[$groupId]['scores'][] = (float) $row['final_score'];
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            $applications = (int) $group['applications'];
            $rows[] = [
                'name' => $group['name'],
                'students' => count($group['students']),
                'arrangements' => count($group['arrangements']),
                'applications' => $applications,
                'accepted_applications' => (int) $group['accepted_applications'],
                'accept_rate' => self::percentText((int) $group['accepted_applications'], $applications),
                'active_pairs' => (int) $group['active_pairs'],
                'avg_score' => self::averageText($group['scores']),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));
        return $rows;
    }

    private static function teacherStatRows(array $data): array
    {
        $groups = [];
        foreach ($data['pairs'] as $row) {
            $teacherId = (int) ($row['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                continue;
            }
            $groups[$teacherId] ??= self::emptyTeacherGroup($teacherId, $data);
            $groups[$teacherId]['students'][(int) ($row['student_id'] ?? 0)] = true;
            $groups[$teacherId]['arrangements'][(int) ($row['arrangement_id'] ?? 0)] = true;
            if ((string) ($row['status'] ?? '') === 'active') {
                $groups[$teacherId]['active_pairs']++;
            }
        }
        foreach ($data['journals'] as $row) {
            $teacherId = (int) ($row['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                continue;
            }
            $groups[$teacherId] ??= self::emptyTeacherGroup($teacherId, $data);
            if ((string) ($row['status'] ?? '') === 'wait') {
                $groups[$teacherId]['journals_waiting']++;
            }
        }
        foreach ($data['reports'] as $row) {
            $teacherId = (int) ($row['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                continue;
            }
            $groups[$teacherId] ??= self::emptyTeacherGroup($teacherId, $data);
            if ((string) ($row['status'] ?? '') === 'wait') {
                $groups[$teacherId]['reports_waiting']++;
            }
        }
        foreach ($data['scores'] as $row) {
            $teacherId = (int) ($row['teacher_id'] ?? 0);
            if ($teacherId <= 0 || !is_numeric($row['final_score'] ?? null)) {
                continue;
            }
            $groups[$teacherId] ??= self::emptyTeacherGroup($teacherId, $data);
            $groups[$teacherId]['scores'][] = (float) $row['final_score'];
        }

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                'teacher_name' => $group['teacher_name'],
                'teacher_num' => $group['teacher_num'],
                'students' => count($group['students']),
                'arrangements' => count($group['arrangements']),
                'active_pairs' => $group['active_pairs'],
                'journals_waiting' => $group['journals_waiting'],
                'reports_waiting' => $group['reports_waiting'],
                'avg_score' => self::averageText($group['scores']),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['teacher_name'], (string) $right['teacher_name']));
        return $rows;
    }

    private static function studentStatRows(array $data): array
    {
        $rows = [];
        foreach (self::participantKeys($data) as $key) {
            [$studentId, $arrangementId] = self::splitPairKey($key);
            $student = $data['student_map'][$studentId] ?? [];
            $arrangement = $data['arrangement_map'][$arrangementId] ?? [];
            $application = self::firstByPair($data['applications'], $studentId, $arrangementId);
            $pair = self::firstByPair($data['pairs'], $studentId, $arrangementId);
            $report = self::firstByPair($data['reports'], $studentId, $arrangementId);
            $score = self::firstByPair($data['scores'], $studentId, $arrangementId);
            $rows[] = [
                'student_name' => $student['name'] ?? '-',
                'student_num' => $student['student_num'] ?? '-',
                'grade_name' => $student['grade_name'] ?? '-',
                'dep_name' => $student['dep_name'] ?? '-',
                'profession_name' => $student['profession_name'] ?? '-',
                'arrangement_title' => $arrangement['title'] ?? ($application['arrangement_title'] ?? '-'),
                'application_status' => $application['status'] ?? '-',
                'pair_status' => $pair['status'] ?? '-',
                'sign_ins' => self::countByPair($data['sign_ins'], $studentId, $arrangementId),
                'journals' => self::countByPair($data['journals'], $studentId, $arrangementId),
                'report_status' => $report['status'] ?? '-',
                'final_score' => $score['final_score'] ?? '-',
            ];
        }

        return $rows;
    }

    private static function archiveStatRows(array $data): array
    {
        $rows = [];
        foreach (self::participantKeys($data) as $key) {
            [$studentId, $arrangementId] = self::splitPairKey($key);
            $row = self::archiveMaterialRow($data, $studentId, $arrangementId);
            $rows[] = [
                'student_name' => $row['student_name'],
                'student_num' => $row['student_num'],
                'grade_name' => $row['grade_name'],
                'arrangement_title' => $row['arrangement_title'],
                'required_count' => $row['required_count'],
                'archived_count' => $row['archived_count'],
                'material_progress' => $row['material_progress'],
                'missing_materials' => $row['missing_materials'],
                'archive_complete' => $row['archive_status_text'],
            ];
        }

        return $rows;
    }

    private static function archiveMaterialRows(array $data): array
    {
        $rows = [];
        foreach (self::participantKeys($data) as $key) {
            [$studentId, $arrangementId] = self::splitPairKey($key);
            $rows[] = self::archiveMaterialRow($data, $studentId, $arrangementId);
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) $left['arrangement_title'], (string) $right['arrangement_title'])
                ?: strcmp((string) $left['student_num'], (string) $right['student_num']);
        });

        return $rows;
    }

    private static function archiveMaterialRow(array $data, int $studentId, int $arrangementId): array
    {
        $student = $data['student_map'][$studentId] ?? [];
        $arrangement = $data['arrangement_map'][$arrangementId] ?? [];
        $required = self::archiveRequiredKeys((string) ($arrangement['type'] ?? ''));
        $materials = self::archiveMaterialStates($data, $studentId, $arrangementId, $arrangement, $required);
        $requiredCount = 0;
        $archivedCount = 0;
        $missing = [];
        foreach ($materials as $material) {
            if (!$material['required']) {
                continue;
            }
            $requiredCount++;
            if ($material['archived']) {
                $archivedCount++;
            } else {
                $missing[] = $material['label'];
            }
        }
        $complete = $requiredCount > 0 && $requiredCount === $archivedCount;
        $materialColumns = [];
        foreach ($materials as $material) {
            $materialColumns[$material['key'] . '_status'] = $material['text'];
        }

        return array_merge([
            'id' => $studentId . '-' . $arrangementId,
            'student_id' => $studentId,
            'student_name' => $student['name'] ?? '-',
            'student_num' => $student['student_num'] ?? '-',
            'grade_id' => $student['grade_id'] ?? null,
            'grade_name' => $student['grade_name'] ?? '-',
            'dep_name' => $student['dep_name'] ?? '-',
            'profession_name' => $student['profession_name'] ?? '-',
            'arrangement_id' => $arrangementId,
            'arrangement_title' => $arrangement['title'] ?? '-',
            'arrangement_type' => $arrangement['type'] ?? '',
            'arrangement_type_text' => self::arrangementTypeText((string) ($arrangement['type'] ?? '')),
            'semester' => $arrangement['semester'] ?? '-',
            'required_count' => $requiredCount,
            'archived_count' => $archivedCount,
            'missing_count' => count($missing),
            'material_progress' => $requiredCount > 0 ? "{$archivedCount}/{$requiredCount}" : '-',
            'missing_materials' => $missing ? implode('、', $missing) : '无',
            'materials' => $materials,
            'archive_status' => $complete ? 'complete' : 'incomplete',
            'archive_status_text' => $complete ? '完整' : '待补齐',
            'status' => $complete ? 'complete' : 'incomplete',
        ], $materialColumns);
    }

    private static function archiveMaterialStates(array $data, int $studentId, int $arrangementId, array $arrangement, array $required): array
    {
        $states = [];
        foreach (self::ARCHIVE_MATERIALS as $key => $label) {
            $isRequired = in_array($key, $required, true);
            $state = self::archiveMaterialState($key, $data, $studentId, $arrangementId, $arrangement);
            $states[] = [
                'key' => $key,
                'label' => $label,
                'required' => $isRequired,
                'archived' => $isRequired && $state['archived'],
                'status' => $isRequired ? ($state['archived'] ? 'archived' : 'missing') : 'not_required',
                'text' => $isRequired ? ($state['archived'] ? $state['text'] : '待补齐') : '不适用',
                'source_status' => $state['source_status'],
            ];
        }

        return $states;
    }

    private static function archiveMaterialState(string $key, array $data, int $studentId, int $arrangementId, array $arrangement): array
    {
        return match ($key) {
            'plan' => self::archivePlanState($data['plans'], $arrangement),
            'implementation_sheet' => self::archiveArrangementState($data['implementation_sheets'], $arrangementId, ['confirmed', 'accept', 'enabled']),
            'syllabus_guide' => self::archiveArrangementState($data['syllabus_guides'], $arrangementId, ['published', 'enabled', 'accept']),
            'score_summary' => self::archiveScoreState($data['scores'], $studentId, $arrangementId),
            'safety_letter' => self::archiveSafetyState($data['safety_letters'], $studentId, $arrangementId),
            'journal' => self::archiveJournalState($data['journals'], $studentId, $arrangementId),
            'report' => self::archiveReportState($data['reports'], $studentId, $arrangementId),
            'graduation_appraisal' => self::archiveGraduationAppraisalState($data, $studentId, $arrangementId),
            'teacher_work_report' => self::archiveArrangementState($data['teacher_work_reports'], $arrangementId, ['accept', 'enabled']),
            'insurance' => self::archiveInsuranceState($data['insurances'], $studentId, $arrangementId),
            default => ['archived' => false, 'text' => '待补齐', 'source_status' => null],
        };
    }

    private static function archivePlanState(array $plans, array $arrangement): array
    {
        foreach ($plans as $plan) {
            $sameSemester = (string) ($plan['semester'] ?? '') === (string) ($arrangement['semester'] ?? '');
            $sameDepartment = (int) ($plan['dep_id'] ?? 0) === 0 || (int) ($plan['dep_id'] ?? 0) === (int) ($arrangement['dep_id'] ?? 0);
            if ($sameSemester && $sameDepartment) {
                $status = (string) ($plan['status'] ?? '');
                return [
                    'archived' => in_array($status, ['accept', 'enabled'], true),
                    'text' => self::archiveSourceText($status),
                    'source_status' => $status,
                ];
            }
        }

        return ['archived' => false, 'text' => '待补齐', 'source_status' => null];
    }

    private static function archiveArrangementState(array $rows, int $arrangementId, array $acceptedStatuses): array
    {
        foreach ($rows as $row) {
            if ((int) ($row['arrangement_id'] ?? 0) !== $arrangementId) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            return [
                'archived' => in_array($status, $acceptedStatuses, true),
                'text' => self::archiveSourceText($status),
                'source_status' => $status,
            ];
        }

        return ['archived' => false, 'text' => '待补齐', 'source_status' => null];
    }

    private static function archiveScoreState(array $scores, int $studentId, int $arrangementId): array
    {
        $score = self::firstByPair($scores, $studentId, $arrangementId);
        $archived = $score && is_numeric($score['final_score'] ?? null);
        return [
            'archived' => $archived,
            'text' => $archived ? '已归档' : '待补齐',
            'source_status' => $archived ? 'enabled' : null,
        ];
    }

    private static function archiveSafetyState(array $rows, int $studentId, int $arrangementId): array
    {
        $row = self::firstByPair($rows, $studentId, $arrangementId);
        $signed = $row && ((string) ($row['status'] ?? '') === 'signed' || !empty($row['signed_at']));
        return [
            'archived' => $signed,
            'text' => $signed ? '已签署' : '待补齐',
            'source_status' => $row['status'] ?? null,
        ];
    }

    private static function archiveJournalState(array $rows, int $studentId, int $arrangementId): array
    {
        $count = 0;
        foreach ($rows as $row) {
            if ((int) ($row['student_id'] ?? 0) === $studentId && (int) ($row['arrangement_id'] ?? 0) === $arrangementId && in_array((string) ($row['status'] ?? ''), ['accept', 'enabled'], true)) {
                $count++;
            }
        }

        return [
            'archived' => $count > 0,
            'text' => $count > 0 ? "已归档{$count}篇" : '待补齐',
            'source_status' => $count > 0 ? 'accept' : null,
        ];
    }

    private static function archiveReportState(array $rows, int $studentId, int $arrangementId): array
    {
        $row = self::firstByPair($rows, $studentId, $arrangementId);
        $status = (string) ($row['status'] ?? '');
        return [
            'archived' => $status === 'accept',
            'text' => self::archiveSourceText($status),
            'source_status' => $status ?: null,
        ];
    }

    private static function archiveGraduationAppraisalState(array $data, int $studentId, int $arrangementId): array
    {
        $report = self::archiveReportState($data['reports'], $studentId, $arrangementId);
        $score = self::archiveScoreState($data['scores'], $studentId, $arrangementId);
        $archived = $report['archived'] && $score['archived'];

        return [
            'archived' => $archived,
            'text' => $archived ? '已归档' : '待补齐',
            'source_status' => $archived ? 'accept' : null,
        ];
    }

    private static function archiveInsuranceState(array $rows, int $studentId, int $arrangementId): array
    {
        $row = self::firstByPair($rows, $studentId, $arrangementId);
        $archived = (bool) $row;
        return [
            'archived' => $archived,
            'text' => $archived ? '已归档' : '待补齐',
            'source_status' => $archived ? 'enabled' : null,
        ];
    }

    private static function archiveRequiredKeys(string $type): array
    {
        return self::ARCHIVE_REQUIREMENTS[$type] ?? self::ARCHIVE_REQUIREMENTS['major_external'];
    }

    private static function arrangementTypeText(string $type): string
    {
        return match ($type) {
            'cognition_internal' => '认识校内',
            'cognition_external' => '认识校外',
            'major_internal' => '专业校内',
            'major_external' => '专业校外',
            'production' => '生产实习',
            'graduation' => '毕业实习',
            default => $type ?: '-',
        };
    }

    private static function archiveSourceText(?string $status): string
    {
        return match ((string) $status) {
            'accept', 'enabled', 'published', 'confirmed' => '已归档',
            'signed' => '已签署',
            'wait' => '待审核',
            'modify' => '需修改',
            'draft' => '草稿',
            default => '待补齐',
        };
    }

    private static function filterArchiveRows(array $rows, array $filters): array
    {
        $status = (string) ($filters['archive_status'] ?? $filters['status'] ?? '');
        if ($status !== '') {
            $rows = self::filterRows($rows, static fn (array $row): bool => (string) ($row['archive_status'] ?? '') === $status);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return $rows;
        }
        $keyword = mb_strtolower($keyword, 'UTF-8');

        return self::filterRows($rows, static function (array $row) use ($keyword): bool {
            $text = implode(' ', [
                $row['student_name'] ?? '',
                $row['student_num'] ?? '',
                $row['dep_name'] ?? '',
                $row['profession_name'] ?? '',
                $row['arrangement_title'] ?? '',
                $row['arrangement_type_text'] ?? '',
                $row['semester'] ?? '',
                $row['missing_materials'] ?? '',
            ]);
            return str_contains(mb_strtolower($text, 'UTF-8'), $keyword);
        });
    }

    private static function archiveMaterialDefinitions(): array
    {
        $items = [];
        foreach (self::ARCHIVE_MATERIALS as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label];
        }

        return $items;
    }

    private static function participantKeys(array $data): array
    {
        $keys = [];
        foreach (['applications', 'pairs', 'sign_ins', 'journals', 'reports', 'scores', 'insurances', 'safety_letters'] as $name) {
            foreach ($data[$name] as $row) {
                $key = self::pairKey((int) ($row['student_id'] ?? 0), (int) ($row['arrangement_id'] ?? 0));
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        ksort($keys);
        return array_keys($keys);
    }

    private static function participantStudentIds(array $data): array
    {
        $ids = [];
        foreach (self::participantKeys($data) as $key) {
            [$studentId] = self::splitPairKey($key);
            if ($studentId > 0) {
                $ids[$studentId] = true;
            }
        }

        return array_keys($ids);
    }

    private static function pairKey(int $studentId, int $arrangementId): string
    {
        return $studentId > 0 && $arrangementId > 0 ? "{$studentId}:{$arrangementId}" : '';
    }

    private static function splitPairKey(string $key): array
    {
        [$studentId, $arrangementId] = array_pad(explode(':', $key, 2), 2, 0);
        return [(int) $studentId, (int) $arrangementId];
    }

    private static function firstByPair(array $rows, int $studentId, int $arrangementId): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['student_id'] ?? 0) === $studentId && (int) ($row['arrangement_id'] ?? 0) === $arrangementId) {
                return $row;
            }
        }

        return null;
    }

    private static function countByPair(array $rows, int $studentId, int $arrangementId): int
    {
        return self::countRows($rows, static fn (array $row): bool => (int) ($row['student_id'] ?? 0) === $studentId && (int) ($row['arrangement_id'] ?? 0) === $arrangementId);
    }

    private static function emptyStatGroup(string $name): array
    {
        return [
            'name' => $name,
            'students' => [],
            'arrangements' => [],
            'applications' => 0,
            'accepted_applications' => 0,
            'active_pairs' => 0,
            'scores' => [],
        ];
    }

    private static function emptyTeacherGroup(int $teacherId, array $data): array
    {
        $teacher = $data['teacher_map'][$teacherId] ?? [];
        return [
            'teacher_name' => $teacher['teacher_name'] ?? '未分配教师',
            'teacher_num' => $teacher['teacher_num'] ?? '-',
            'students' => [],
            'arrangements' => [],
            'active_pairs' => 0,
            'journals_waiting' => 0,
            'reports_waiting' => 0,
            'scores' => [],
        ];
    }

    private static function appendGroupApplication(array &$groups, array $data, array $row, string $idKey, string $nameKey, string $fallbackName): void
    {
        $groupId = self::groupIdByRow($data, $row, $idKey);
        if ($groupId === null) {
            return;
        }
        $groups[$groupId] ??= self::emptyStatGroup(self::groupNameByRow($data, $row, $nameKey, $fallbackName));
        $groups[$groupId]['applications']++;
        if ((string) ($row['status'] ?? '') === 'accept') {
            $groups[$groupId]['accepted_applications']++;
        }
    }

    private static function appendGroupValue(array &$groups, array $data, array $row, string $idKey, string $nameKey, string $fallbackName, string $field): void
    {
        $groupId = self::groupIdByRow($data, $row, $idKey);
        if ($groupId === null) {
            return;
        }
        $groups[$groupId] ??= self::emptyStatGroup(self::groupNameByRow($data, $row, $nameKey, $fallbackName));
        $groups[$groupId][$field]++;
    }

    private static function groupIdByRow(array $data, array $row, string $idKey): ?string
    {
        $studentId = (int) ($row['student_id'] ?? 0);
        $student = $data['student_map'][$studentId] ?? $row;
        $value = $student[$idKey] ?? null;
        return $value === null || $value === '' ? '0' : (string) $value;
    }

    private static function groupNameByRow(array $data, array $row, string $nameKey, string $fallbackName): string
    {
        $studentId = (int) ($row['student_id'] ?? 0);
        $student = $data['student_map'][$studentId] ?? $row;
        return (string) ($student[$nameKey] ?? $fallbackName);
    }

    private static function overviewStatColumns(): array
    {
        return [
            ['key' => 'arrangement_title', 'label' => '实习安排', 'min_width' => 180],
            ['key' => 'scope', 'label' => '范围', 'min_width' => 180],
            ['key' => 'applications', 'label' => '申请', 'width' => 90],
            ['key' => 'accepted_applications', 'label' => '通过', 'width' => 90],
            ['key' => 'active_pairs', 'label' => '指导关系', 'width' => 100],
            ['key' => 'sign_ins', 'label' => '签到', 'width' => 90],
            ['key' => 'journals', 'label' => '日志', 'width' => 90],
            ['key' => 'reports', 'label' => '报告', 'width' => 90],
            ['key' => 'avg_score', 'label' => '平均分', 'width' => 100],
        ];
    }

    private static function departmentStatColumns(): array
    {
        return self::groupStatColumns('学院');
    }

    private static function professionStatColumns(): array
    {
        return self::groupStatColumns('专业');
    }

    private static function groupStatColumns(string $name): array
    {
        return [
            ['key' => 'name', 'label' => $name, 'min_width' => 160],
            ['key' => 'students', 'label' => '参与学生', 'width' => 100],
            ['key' => 'arrangements', 'label' => '关联安排', 'width' => 100],
            ['key' => 'applications', 'label' => '申请', 'width' => 90],
            ['key' => 'accepted_applications', 'label' => '通过', 'width' => 90],
            ['key' => 'accept_rate', 'label' => '通过率', 'width' => 100],
            ['key' => 'active_pairs', 'label' => '指导关系', 'width' => 100],
            ['key' => 'avg_score', 'label' => '平均分', 'width' => 100],
        ];
    }

    private static function teacherStatColumns(): array
    {
        return [
            ['key' => 'teacher_name', 'label' => '指导教师', 'min_width' => 150],
            ['key' => 'teacher_num', 'label' => '工号', 'width' => 120],
            ['key' => 'students', 'label' => '学生', 'width' => 90],
            ['key' => 'arrangements', 'label' => '安排', 'width' => 90],
            ['key' => 'active_pairs', 'label' => '指导关系', 'width' => 100],
            ['key' => 'journals_waiting', 'label' => '待评日志', 'width' => 100],
            ['key' => 'reports_waiting', 'label' => '待评报告', 'width' => 100],
            ['key' => 'avg_score', 'label' => '平均分', 'width' => 100],
        ];
    }

    private static function studentStatColumns(): array
    {
        return [
            ['key' => 'student_name', 'label' => '学生', 'width' => 120],
            ['key' => 'student_num', 'label' => '学号', 'width' => 130],
            ['key' => 'grade_name', 'label' => '届次', 'width' => 110],
            ['key' => 'dep_name', 'label' => '学院', 'min_width' => 150],
            ['key' => 'profession_name', 'label' => '专业', 'min_width' => 150],
            ['key' => 'arrangement_title', 'label' => '实习安排', 'min_width' => 180],
            ['key' => 'application_status', 'label' => '申请', 'width' => 90, 'type' => 'status'],
            ['key' => 'pair_status', 'label' => '指导', 'width' => 90, 'type' => 'status'],
            ['key' => 'sign_ins', 'label' => '签到', 'width' => 80],
            ['key' => 'journals', 'label' => '日志', 'width' => 80],
            ['key' => 'report_status', 'label' => '报告', 'width' => 90, 'type' => 'status'],
            ['key' => 'final_score', 'label' => '总评', 'width' => 90],
        ];
    }

    private static function archiveStatColumns(): array
    {
        return [
            ['key' => 'student_name', 'label' => '学生', 'width' => 120],
            ['key' => 'student_num', 'label' => '学号', 'width' => 130],
            ['key' => 'grade_name', 'label' => '届次', 'width' => 110],
            ['key' => 'arrangement_title', 'label' => '实习安排', 'min_width' => 180],
            ['key' => 'material_progress', 'label' => '归档进度', 'width' => 100],
            ['key' => 'missing_materials', 'label' => '缺失材料', 'min_width' => 260],
            ['key' => 'archive_complete', 'label' => '归档状态', 'width' => 100],
        ];
    }

    private static function archiveMaterialColumns(): array
    {
        return [
            ['key' => 'student_name', 'label' => '学生', 'width' => 120],
            ['key' => 'student_num', 'label' => '学号', 'width' => 130],
            ['key' => 'grade_name', 'label' => '届次', 'width' => 110],
            ['key' => 'dep_name', 'label' => '学院', 'min_width' => 150],
            ['key' => 'profession_name', 'label' => '专业', 'min_width' => 150],
            ['key' => 'arrangement_title', 'label' => '实习安排', 'min_width' => 190],
            ['key' => 'arrangement_type_text', 'label' => '实习类型', 'width' => 120],
            ['key' => 'material_progress', 'label' => '归档进度', 'width' => 100],
            ['key' => 'plan_status', 'label' => '计划表', 'width' => 100],
            ['key' => 'implementation_sheet_status', 'label' => '实施表', 'width' => 100],
            ['key' => 'syllabus_guide_status', 'label' => '大纲指导书', 'width' => 110],
            ['key' => 'score_summary_status', 'label' => '成绩汇总', 'width' => 100],
            ['key' => 'safety_letter_status', 'label' => '安全承诺', 'width' => 100],
            ['key' => 'journal_status', 'label' => '实习周志', 'width' => 110],
            ['key' => 'report_status', 'label' => '实习报告', 'width' => 100],
            ['key' => 'graduation_appraisal_status', 'label' => '鉴定表', 'width' => 100],
            ['key' => 'teacher_work_report_status', 'label' => '教师工作报告', 'width' => 120],
            ['key' => 'insurance_status', 'label' => '保险单', 'width' => 100],
            ['key' => 'missing_materials', 'label' => '缺失材料', 'min_width' => 260],
            ['key' => 'archive_status_text', 'label' => '归档状态', 'width' => 100],
        ];
    }

    private static function paginateArrayRows(array $rows, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? $filters['per_page'] ?? 20)));
        $total = count($rows);

        return [
            'items' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    private static function indexRows(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) ($row[$key] ?? 0)] = $row;
        }

        return $map;
    }

    private static function filterRows(array $rows, callable $callback): array
    {
        return array_values(array_filter($rows, $callback));
    }

    private static function countRows(array $rows, callable $callback): int
    {
        return count(self::filterRows($rows, $callback));
    }

    private static function scoreValues(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (is_numeric($row['final_score'] ?? null)) {
                $values[] = (float) $row['final_score'];
            }
        }

        return $values;
    }

    private static function averageText(array $values): string
    {
        if (!$values) {
            return '-';
        }

        return (string) round(array_sum($values) / count($values), 1);
    }

    private static function percentText(int $numerator, int $denominator): string
    {
        if ($denominator <= 0) {
            return '-';
        }

        return round($numerator * 100 / $denominator, 1) . '%';
    }

    private static function applyBaseScope(mixed $query, array $scope): mixed
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin') {
            return self::whereInOrDeny($query, 'base.dep_id', $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'enterprise') {
            return self::whereInOrDeny($query, 'base.company_id', $scope['company_ids'] ?? []);
        }

        return $query;
    }

    private static function applyArrangementScope(mixed $query, array $scope): mixed
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return $query;
        }
        if ($roleType === 'college_admin') {
            return self::whereInOrDeny($query, 'arrangement.dep_id', $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin') {
            return self::whereInOrDeny($query, 'arrangement.profession_id', $scope['profession_ids'] ?? []);
        }
        if (in_array($roleType, ['teacher', 'student'], true)) {
            return self::whereInOrDeny($query, 'arrangement.id', $scope['visible_arrangement_ids'] ?? []);
        }
        if ($roleType === 'enterprise') {
            return self::whereInOrDeny($query, 'arrangement.base_id', $scope['base_ids'] ?? []);
        }

        return $query->whereRaw('1 = 0');
    }

    private static function applyApplicationScope(mixed $query, array $scope): mixed
    {
        if (($scope['role_type'] ?? '') === 'teacher') {
            return self::whereInOrDeny($query, 'application.id', $scope['application_ids'] ?? []);
        }

        self::applyStudentScope($query, $scope, 'application.student_id');
        $roleType = (string) ($scope['role_type'] ?? '');
        if (in_array($roleType, ['student', 'enterprise'], true)) {
            self::whereInOrDeny($query, 'application.arrangement_id', $scope['owned_arrangement_ids'] ?? []);
        }

        return $query;
    }

    private static function applyStudentScope(mixed $query, array $scope, string $column): mixed
    {
        if (array_key_exists('visible_student_ids', $scope) && $scope['visible_student_ids'] !== null) {
            self::whereInOrDeny($query, $column, $scope['visible_student_ids']);
        }

        return $query;
    }

    private static function applyCompanyScope(mixed $query, array $scope, string $column): mixed
    {
        if (($scope['role_type'] ?? '') === 'enterprise') {
            self::whereInOrDeny($query, $column, $scope['company_ids'] ?? []);
        }

        return $query;
    }

    private static function applyOptionScope(mixed $query, array $scope, ?string $depColumn, ?string $professionColumn): mixed
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin' && $depColumn) {
            self::whereInOrDeny($query, $depColumn, $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin' && $professionColumn) {
            self::whereInOrDeny($query, $professionColumn, $scope['profession_ids'] ?? []);
        }
        if ($roleType === 'profession_admin' && !$professionColumn && $depColumn) {
            self::whereInOrDeny($query, $depColumn, $scope['profession_dep_ids'] ?? []);
        }

        return $query;
    }

    private static function applyDepProfessionScope(mixed $query, array $scope, ?string $depColumn, ?string $professionColumn): void
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin' && $depColumn) {
            self::whereInOrDeny($query, $depColumn, $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin' && $professionColumn) {
            self::whereInOrDeny($query, $professionColumn, $scope['profession_ids'] ?? []);
        }
        if ($roleType === 'profession_admin' && !$professionColumn && $depColumn) {
            self::whereInOrDeny($query, $depColumn, $scope['profession_dep_ids'] ?? []);
        }
    }

    private static function applyArrangementIdScope(mixed $query, array $scope, string $column): void
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if (!in_array($roleType, ['teacher', 'student', 'enterprise'], true)) {
            return;
        }

        $ids = $roleType === 'teacher' ? ($scope['visible_arrangement_ids'] ?? []) : ($scope['owned_arrangement_ids'] ?? []);
        self::whereInOrDeny($query, $column, $ids);
    }

    private static function whereInOrDeny(mixed $query, string $column, array $ids): mixed
    {
        $ids = self::ids($ids);
        if (!$ids) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $ids);
    }

    private static function rows(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $item = method_exists($row, 'getAttributes') ? $row->getAttributes() : (array) $row;
            foreach (['materials', 'plan_content', 'form_schema', 'fee_detail', 'items'] as $jsonField) {
                if (isset($item[$jsonField]) && is_string($item[$jsonField])) {
                    $decoded = json_decode($item[$jsonField], true);
                    $item[$jsonField] = json_last_error() === JSON_ERROR_NONE ? $decoded : $item[$jsonField];
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function paginate(mixed $query, array $filters, array $columns): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? $filters['per_page'] ?? 20)));
        $total = (int) (clone $query)->count();

        return [
            'items' => self::rows($query->forPage($page, $pageSize)->get($columns)),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    private static function applicationKeyword(mixed $query, array $filters, array $columns): void
    {
        self::keyword($query, $filters, $columns, [self::applicationTeacherKeyword()]);
    }

    private static function keyword(mixed $query, array $filters, array $columns, array $callbacks = []): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $query->where(function ($builder) use ($columns, $callbacks, $keyword): void {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $hasCondition = false;
            foreach ($columns as $column) {
                $hasCondition ? $builder->orWhere($column, 'like', $like) : $builder->where($column, 'like', $like);
                $hasCondition = true;
            }
            foreach ($callbacks as $callback) {
                $callback($builder, $like, $hasCondition);
                $hasCondition = true;
            }
        });
    }

    private static function applicationTeacherKeyword(): callable
    {
        return static function (mixed $builder, string $like, bool $hasCondition): void {
            $method = $hasCondition ? 'orWhereExists' : 'whereExists';
            $builder->{$method}(function ($subQuery) use ($like): void {
                $subQuery->selectRaw('1')
                    ->from('student_join_teacher')
                    ->join('teacher_list', 'student_join_teacher.teacher_id', '=', 'teacher_list.teacher_id')
                    ->whereColumn('student_join_teacher.application_id', 'application.id')
                    ->where('student_join_teacher.application_type', 'internship')
                    ->whereNull('student_join_teacher.deleted_at')
                    ->whereNull('teacher_list.deleted_at')
                    ->where(function ($teacherQuery) use ($like): void {
                        $teacherQuery->where('teacher_list.teacher_name', 'like', $like)
                            ->orWhere('teacher_list.teacher_num', 'like', $like);
                    });
            });
        };
    }

    private static function pairTeacherKeyword(string $studentColumn, string $arrangementColumn): callable
    {
        return static function (mixed $builder, string $like, bool $hasCondition) use ($studentColumn, $arrangementColumn): void {
            $method = $hasCondition ? 'orWhereExists' : 'whereExists';
            $builder->{$method}(function ($subQuery) use ($arrangementColumn, $like, $studentColumn): void {
                $subQuery->selectRaw('1')
                    ->from('pair')
                    ->join('teacher_list', 'pair.teacher_id', '=', 'teacher_list.teacher_id')
                    ->whereColumn('pair.student_id', $studentColumn)
                    ->whereColumn('pair.arrangement_id', $arrangementColumn)
                    ->where('pair.type', 'internship')
                    ->where('pair.status', 'active')
                    ->whereNull('pair.deleted_at')
                    ->whereNull('teacher_list.deleted_at')
                    ->where(function ($teacherQuery) use ($like): void {
                        $teacherQuery->where('teacher_list.teacher_name', 'like', $like)
                            ->orWhere('teacher_list.teacher_num', 'like', $like);
                    });
            });
        };
    }

    private static function filter(mixed $query, array $filters, string $column, string $key): void
    {
        $value = $filters[$key] ?? null;
        if ($value !== null && $value !== '') {
            $query->where($column, $value);
        }
    }

    private static function listFilters(mixed $query, array $filters, array $columns): void
    {
        foreach (['dep_id', 'profession_id', 'grade_id', 'class_id'] as $key) {
            if (!isset($columns[$key])) {
                continue;
            }
            $value = self::optionalInt($filters[$key] ?? null);
            if ($value) {
                $query->where($columns[$key], $value);
            }
        }

        if (isset($columns['semester'])) {
            $semester = trim((string) ($filters['semester'] ?? ''));
            if ($semester !== '') {
                $query->where($columns['semester'], $semester);
            }
        }
    }

    private static function intValues(mixed $values): array
    {
        return self::ids($values->map(static fn ($id): int => (int) $id)->all());
    }

    private static function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
