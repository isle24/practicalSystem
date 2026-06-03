<?php

namespace app\model\channel;

class InternshipRecord extends TableRecord
{
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
        $grades = self::applyOptionScope(self::queryTable('grade_list')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'dep_id', null);
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
            'grades' => self::rows($grades->orderBy('sort')->get(['grade_id', 'grade_name', 'dep_id'])),
            'professions' => self::rows($professions->orderBy('sort')->get(['profession_id', 'profession_name', 'profession_code', 'dep_id'])),
            'classes' => self::rows($classes->orderBy('sort')->get(['class_id', 'class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id'])),
            'companies' => self::rows($companies->orderBy('company_id')->get(['company_id', 'company_name', 'contact_name', 'contact_mobile'])),
            'teachers' => self::rows($teachers->orderBy('teacher_id')->get(['teacher_id', 'teacher_name', 'teacher_num', 'dep_id', 'profession_id'])),
            'students' => self::rows($students->orderBy('student_id')->get(['student_id', 'name', 'student_num', 'grade_id', 'dep_id', 'profession_id', 'class_id'])),
            'bases' => self::rows(self::applyBaseScope(self::queryTable('base')->where('base.status', 'enabled')->whereNull('base.deleted_at'), $scope)->orderBy('base.id')->get(['base.id', 'base.name', 'base.company_id', 'base.dep_id'])),
            'arrangements' => self::rows(self::applyArrangementScope(self::queryTable('arrangement')->whereNull('deleted_at'), $scope)->orderByDesc('id')->get(['id', 'uuid', 'title', 'name', 'type', 'organize_mode', 'semester', 'dep_id', 'profession_id', 'status'])),
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
            ->whereNull('arrangement.deleted_at'), $scope);
        self::filter($query, $filters, 'arrangement.status', 'status');
        self::filter($query, $filters, 'arrangement.type', 'type');
        self::filter($query, $filters, 'arrangement.organize_mode', 'organize_mode');
        self::listFilters($query, $filters, [
            'dep_id' => 'arrangement.dep_id',
            'profession_id' => 'arrangement.profession_id',
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
        ]);
    }

    public static function applicationPage(array $scope, array $filters): array
    {
        $query = self::applyApplicationScope(self::queryTable('application')
            ->leftJoin('students', 'application.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'application.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->whereNull('application.deleted_at'), $scope);
        self::filter($query, $filters, 'application.status', 'status');
        self::filter($query, $filters, 'application.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'semester' => 'arrangement.semester',
        ]);
        self::joinTeacherFilter($query, $filters, 'application.id');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title']);

        return self::paginate($query->orderByDesc('application.id'), $filters, [
            'application.id', 'application.uuid', 'application.student_id', 'application.arrangement_id',
            'application.type', 'application.status', 'application.teacher_status', 'application.admin_status',
            'application.remark', 'application.created_at', 'students.name as student_name',
            'students.student_num', 'department.dep_name', 'profession.profession_name',
            'arrangement.title as arrangement_title', 'arrangement.type as arrangement_type',
        ]);
    }

    public static function pairPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('pair')
            ->leftJoin('students', 'pair.student_id', '=', 'students.student_id')
            ->leftJoin('teacher_list', 'pair.teacher_id', '=', 'teacher_list.teacher_id')
            ->leftJoin('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->where('pair.type', 'internship')
            ->whereNull('pair.deleted_at'), $scope, 'pair.student_id');
        self::filter($query, $filters, 'pair.status', 'status');
        self::filter($query, $filters, 'pair.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'teacher_id' => 'pair.teacher_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'teacher_list.teacher_name', 'arrangement.title']);

        return self::paginate($query->orderByDesc('pair.id'), $filters, [
            'pair.id', 'pair.uuid', 'pair.student_id', 'pair.teacher_id', 'pair.dep_id',
            'pair.second_teacher_id', 'pair.enterprise_mentor_id', 'pair.arrangement_id',
            'pair.application_id', 'pair.status', 'pair.remove_reason', 'pair.created_at',
            'students.name as student_name', 'students.student_num',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function signInPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('sign_in')
            ->leftJoin('students', 'sign_in.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'sign_in.entity_id', '=', 'arrangement.id')
            ->where('sign_in.entity_type', 'internship')
            ->whereNull('sign_in.deleted_at'), $scope, 'sign_in.student_id');
        self::filter($query, $filters, 'sign_in.entity_id', 'arrangement_id');
        self::filter($query, $filters, 'sign_in.date', 'date');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'semester' => 'arrangement.semester',
        ]);
        self::pairTeacherFilter($query, $filters, 'sign_in.student_id', 'sign_in.entity_id');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title', 'sign_in.location']);

        return self::paginate($query->orderByDesc('sign_in.sign_time'), $filters, [
            'sign_in.id', 'sign_in.uuid', 'sign_in.student_id', 'sign_in.entity_id as arrangement_id',
            'sign_in.date', 'sign_in.sign_time', 'sign_in.sign_type', 'sign_in.location',
            'sign_in.longitude', 'sign_in.latitude', 'sign_in.remark', 'sign_in.status',
            'students.name as student_name', 'students.student_num', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function journalPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('journal')
            ->leftJoin('students', 'journal.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'journal.entity_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'journal.teacher_id', '=', 'teacher_list.teacher_id')
            ->where('journal.entity_type', 'internship')
            ->whereNull('journal.deleted_at'), $scope, 'journal.student_id');
        self::filter($query, $filters, 'journal.status', 'status');
        self::filter($query, $filters, 'journal.entity_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'semester' => 'arrangement.semester',
        ]);
        self::pairTeacherFilter($query, $filters, 'journal.student_id', 'journal.entity_id');
        self::keyword($query, $filters, ['journal.title', 'students.name', 'students.student_num']);

        return self::paginate($query->orderByDesc('journal.date')->orderByDesc('journal.id'), $filters, [
            'journal.id', 'journal.uuid', 'journal.student_id', 'journal.entity_id as arrangement_id',
            'journal.title', 'journal.content', 'journal.date', 'journal.status', 'journal.teacher_id',
            'journal.created_at', 'students.name as student_name', 'students.student_num',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function reportPage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('report')
            ->leftJoin('students', 'report.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'report.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'report.teacher_id', '=', 'teacher_list.teacher_id')
            ->whereNull('report.deleted_at'), $scope, 'report.student_id');
        self::filter($query, $filters, 'report.status', 'status');
        self::filter($query, $filters, 'report.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'semester' => 'arrangement.semester',
        ]);
        self::pairTeacherFilter($query, $filters, 'report.student_id', 'report.arrangement_id');
        self::keyword($query, $filters, ['report.title', 'students.name', 'students.student_num']);

        return self::paginate($query->orderByDesc('report.id'), $filters, [
            'report.id', 'report.uuid', 'report.student_id', 'report.arrangement_id', 'report.template_id',
            'report.title', 'report.content', 'report.status', 'report.teacher_id',
            'report.submitted_at', 'report.reviewed_at', 'report.created_at',
            'students.name as student_name', 'students.student_num',
            'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
    }

    public static function scorePage(array $scope, array $filters): array
    {
        $query = self::applyStudentScope(self::queryTable('score')
            ->leftJoin('students', 'score.student_id', '=', 'students.student_id')
            ->leftJoin('arrangement', 'score.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('teacher_list', 'score.teacher_id', '=', 'teacher_list.teacher_id')
            ->whereNull('score.deleted_at'), $scope, 'score.student_id');
        self::filter($query, $filters, 'score.arrangement_id', 'arrangement_id');
        self::listFilters($query, $filters, [
            'dep_id' => 'students.dep_id',
            'profession_id' => 'students.profession_id',
            'grade_id' => 'students.grade_id',
            'teacher_id' => 'score.teacher_id',
            'semester' => 'arrangement.semester',
        ]);
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title']);

        return self::paginate($query->orderByDesc('score.id'), $filters, [
            'score.id', 'score.uuid', 'score.student_id', 'score.arrangement_id',
            'score.sign_in_score', 'score.journal_score', 'score.report_score',
            'score.sign_in_weight', 'score.journal_weight', 'score.report_weight',
            'score.enterprise_score', 'score.enterprise_comment', 'score.final_score',
            'score.teacher_id', 'score.comment', 'students.name as student_name',
            'students.student_num', 'teacher_list.teacher_name', 'arrangement.title as arrangement_title',
        ]);
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
                ->leftJoin('arrangement', "{$table}.arrangement_id", '=', 'arrangement.id');
            self::applyStudentScope($query, $scope, "{$table}.student_id");
            self::listFilters($query, $filters, [
                'dep_id' => 'students.dep_id',
                'profession_id' => 'students.profession_id',
                'grade_id' => 'students.grade_id',
                'semester' => 'arrangement.semester',
            ]);
            self::pairTeacherFilter($query, $filters, "{$table}.student_id", "{$table}.arrangement_id");
            self::keyword($query, $filters, ['students.name', 'students.student_num', 'arrangement.title']);
        }
        if (in_array($table, ['implementation_sheet', 'teacher_work_report'], true)) {
            self::applyArrangementIdScope($query, $scope, "{$table}.arrangement_id");
        }
        self::filter($query, $filters, "{$table}.arrangement_id", 'arrangement_id');

        return self::paginate($query->orderByDesc("{$table}.id"), $filters, $columns);
    }

    public static function inspectionPage(array $filters): array
    {
        return self::paginate(self::queryTable('inspection_record')
            ->whereNull('deleted_at')
            ->orderByDesc('id'), $filters, ['inspection_record.*']);
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

    public static function applicationIdsByJoinTeacher(int $teacherId): array
    {
        return self::applicationIdsByTeacher($teacherId);
    }

    public static function pairsByTeacher(int $teacherId): array
    {
        if ($teacherId <= 0) {
            return [];
        }

        return self::queryTable('pair')
            ->where('teacher_id', $teacherId)
            ->where('type', 'internship')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get(['student_id', 'arrangement_id'])
            ->map(static fn ($row): array => [
                'student_id' => (int) $row->student_id,
                'arrangement_id' => (int) $row->arrangement_id,
            ])
            ->all();
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

    private static function keyword(mixed $query, array $filters, array $columns): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $query->where(function ($builder) use ($columns, $keyword): void {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            foreach ($columns as $index => $column) {
                $index === 0 ? $builder->where($column, 'like', $like) : $builder->orWhere($column, 'like', $like);
            }
        });
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
        foreach (['dep_id', 'profession_id', 'grade_id', 'teacher_id'] as $key) {
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

    private static function joinTeacherFilter(mixed $query, array $filters, string $applicationColumn): void
    {
        $teacherId = self::optionalInt($filters['teacher_id'] ?? null);
        if (!$teacherId) {
            return;
        }

        self::whereInOrDeny($query, $applicationColumn, self::applicationIdsByJoinTeacher($teacherId));
    }

    private static function pairTeacherFilter(mixed $query, array $filters, string $studentColumn, string $arrangementColumn): void
    {
        $teacherId = self::optionalInt($filters['teacher_id'] ?? null);
        if (!$teacherId) {
            return;
        }

        $studentIds = [];
        $arrangementIds = [];
        foreach (self::pairsByTeacher($teacherId) as $pair) {
            $studentIds[] = (int) $pair['student_id'];
            $arrangementIds[] = (int) $pair['arrangement_id'];
        }

        self::whereInOrDeny($query, $studentColumn, $studentIds);
        self::whereInOrDeny($query, $arrangementColumn, $arrangementIds);
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
