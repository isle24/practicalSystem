<?php

namespace app\model\channel;

class PracticeRecord extends TableRecord
{
    private const ENTITY_TABLES = [
        'plan' => 'practice_plan',
        'schedule' => 'practice_schedule',
        'project' => 'practice_project',
        'syllabus' => 'practice_syllabus',
        'lessonPlan' => 'practice_lesson_plan',
        'gradeRule' => 'practice_grade_rule',
        'score' => 'practice_score',
        'reflection' => 'practice_reflection',
        'room' => 'practice_room',
    ];

    private const JSON_FIELDS = [
        'content_json',
        'ratio_json',
        'score_items',
        'attachment_ids',
        'snapshot_json',
        'missing_items_json',
        'pending_items_json',
    ];
    private const EXECUTION_TABLES = [
        'sign_in' => 'sign_in',
        'journal' => 'journal',
        'report' => 'report',
    ];

    public static function overviewRows(array $scope, string $moduleType, string $today): array
    {
        return [
            'plans_waiting' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_plan', $moduleType)->where('status', 'wait'),
                $scope,
                'practice_plan'
            )->count(),
            'schedules' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_schedule', $moduleType),
                $scope,
                'practice_schedule'
            )->count(),
            'projects' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_project', $moduleType),
                $scope,
                'practice_project',
                false,
                'project'
            )->count(),
            'syllabus_waiting' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_syllabus', $moduleType)->where('status', 'wait'),
                $scope,
                'practice_syllabus'
            )->count(),
            'lesson_plans_waiting' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_lesson_plan', $moduleType)->where('status', 'wait'),
                $scope,
                'practice_lesson_plan'
            )->count(),
            'scores_submitted' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_score', $moduleType)->whereIn('status', ['wait', 'accept']),
                $scope,
                'practice_score',
                true
            )->count(),
            'reflections_waiting' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_reflection', $moduleType)->where('status', 'wait'),
                $scope,
                'practice_reflection'
            )->count(),
            'today_schedules' => (int) self::applyPracticeScope(
                self::moduleQuery('practice_schedule', $moduleType)->where('schedule_date', $today),
                $scope,
                'practice_schedule'
            )->count(),
        ];
    }

    public static function optionRows(array $scope, string $moduleType): array
    {
        $departments = self::applyOptionScope(self::queryTable('department')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'dep_id', null);
        $grades = self::queryTable('grade_list')->where('flag', 'on')->whereNull('deleted_at');
        $professions = self::applyOptionScope(self::queryTable('profession')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'dep_id', 'profession_id');
        $classes = self::applyOptionScope(self::queryTable('class')->where('flag', 'on')->whereNull('deleted_at'), $scope, 'dep_id', 'profession_id');
        $teachers = self::applyOptionScope(self::queryTable('teacher_list')->where('status', 'enabled')->whereNull('deleted_at'), $scope, 'dep_id', 'profession_id');
        if (($scope['role_type'] ?? '') === 'teacher') {
            self::whereInOrDeny($teachers, 'teacher_id', [(int) ($scope['teacher_id'] ?? 0)]);
        }
        $students = self::applyStudentOptionScope(self::queryTable('students')->where('status', 'enabled')->whereNull('deleted_at'), $scope);

        return [
            'current_teacher_id' => (int) ($scope['teacher_id'] ?? 0) ?: null,
            'current_student_id' => (int) ($scope['student_id'] ?? 0) ?: null,
            'departments' => self::rows($departments->orderBy('sort')->get(['dep_id', 'dep_name', 'dep_code'])),
            'grades' => self::rows($grades->orderBy('sort')->get(['grade_id', 'grade_name', 'is_current'])),
            'professions' => self::rows($professions->orderBy('sort')->get(['profession_id', 'profession_name', 'profession_code', 'dep_id', 'grade_id'])),
            'classes' => self::rows($classes->orderBy('sort')->get(['class_id', 'class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id'])),
            'teachers' => self::rows($teachers->orderBy('teacher_id')->get(['teacher_id', 'teacher_name', 'teacher_num', 'dep_id', 'profession_id'])),
            'students' => self::rows($students->orderBy('student_id')->get(['student_id', 'name', 'student_num', 'grade_id', 'dep_id', 'profession_id', 'class_id'])),
            'bases' => self::rows(self::applyPracticeBaseScope(self::queryTable('base')->where('base.status', 'enabled')->whereNull('base.deleted_at'), $scope)
                ->orderBy('base.id')
                ->get(['base.id', 'base.name', 'base.company_id', 'base.dep_id'])),
            'rooms' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_room', $moduleType)->where('practice_room.status', 'enabled'), $scope, 'practice_room', false, 'room')
                ->orderBy('practice_room.id')
                ->get(['id', 'uuid', 'module_type', 'name', 'code', 'dep_id', 'room_type', 'capacity', 'location'])),
            'plans' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_plan', $moduleType)->whereIn('practice_plan.status', ['wait', 'accept', 'enabled']), $scope, 'practice_plan', false, 'plan')
                ->orderByDesc('practice_plan.id')
                ->get(['id', 'uuid', 'module_type', 'title', 'course_name', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'course_leader_id', 'status'])),
            'schedules' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_schedule', $moduleType)->where('practice_schedule.status', 'enabled'), $scope, 'practice_schedule')
                ->orderByDesc('practice_schedule.id')
                ->get(['id', 'uuid', 'module_type', 'title', 'course_name', 'plan_id', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'schedule_date', 'period_start_id', 'period_end_id', 'start_time', 'end_time', 'status'])),
            'projects' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_project', $moduleType)->whereIn('practice_project.status', ['enabled', 'completed']), $scope, 'practice_project', false, 'project')
                ->orderByDesc('practice_project.id')
                ->get(['id', 'uuid', 'module_type', 'title', 'course_name', 'plan_id', 'schedule_id', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'start_date', 'end_date', 'student_count', 'status'])),
        ];
    }

    public static function entityPage(array $scope, string $moduleType, string $entity, array $filters): array
    {
        $table = self::entityTable($entity);
        $query = self::entityQuery($moduleType, $entity);
        self::applyPracticeScope($query, $scope, $table, $entity === 'score', $entity);
        self::applyEntityFilters($query, $table, $entity, $filters);

        return self::paginate($query->orderByDesc("{$table}.id"), $filters, self::entityColumns($entity, $table));
    }

    /** 查询指定周的专业课表 */
    public static function scheduleWeekRows(array $scope, string $moduleType, string $weekStart, string $weekEnd, array $filters): array
    {
        $query = self::entityQuery($moduleType, 'schedule')
            ->whereBetween('practice_schedule.schedule_date', [$weekStart, $weekEnd])
            ->where('practice_schedule.status', '<>', 'disabled');
        self::applyPracticeScope($query, $scope, 'practice_schedule', false, 'schedule');
        self::applyEntityFilters($query, 'practice_schedule', 'schedule', $filters);

        return self::rows($query
            ->orderBy('practice_schedule.schedule_date')
            ->orderBy('practice_schedule.start_time')
            ->orderBy('practice_schedule.id')
            ->get(self::entityColumns('schedule', 'practice_schedule')));
    }

    public static function courseScoreSheetReport(array $scope, array $filters): array
    {
        $moduleType = self::practiceModuleType($filters);
        $planId = self::optionalInt($filters['plan_id'] ?? null);
        $meta = self::scoreSheetMeta($scope, $moduleType, $planId, $filters);
        $rows = $planId
            ? self::scoreSheetRowsByPlan($scope, $moduleType, $planId, $filters)
            : self::scoreSheetRowsByScores($scope, $moduleType, $filters);
        $rows = self::scoreSheetRows($rows);
        $paged = self::paginateArrayRows($rows, $filters);

        return [
            'report' => 'practice_score_sheet',
            'title' => '课程考核及成绩记载表（实验实训）',
            'generated_at' => date('Y-m-d H:i:s'),
            'cards' => self::scoreSheetCards($rows, $moduleType),
            'columns' => self::scoreSheetColumns(),
            'rows' => $paged['items'],
            'pagination' => $paged['pagination'],
            'sheet_meta' => $meta,
        ];
    }

    /** 返回成绩记载表导出快照。 */
    public static function courseScoreSheetExportReport(array $scope, array $filters): array
    {
        $moduleType = self::practiceModuleType($filters);
        $planId = self::optionalInt($filters['plan_id'] ?? null);
        $meta = self::scoreSheetMeta($scope, $moduleType, $planId, $filters);
        $rows = $planId
            ? self::scoreSheetRowsByPlan($scope, $moduleType, $planId, $filters)
            : self::scoreSheetRowsByScores($scope, $moduleType, $filters);
        $rows = self::scoreSheetRows($rows);

        $studentIds = self::ids($filters['student_ids'] ?? []);
        if ($studentIds) {
            $visibleStudentIds = array_fill_keys($studentIds, true);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($visibleStudentIds[(int) ($row['student_id'] ?? 0)])
            ));
        }
        foreach ($rows as $index => &$row) {
            $row['sequence'] = $index + 1;
        }
        unset($row);

        return [
            'report' => 'practice_score_sheet',
            'title' => '课程考核及成绩记载表（实验实训）',
            'generated_at' => date('Y-m-d H:i:s'),
            'sheet_meta' => $meta,
            'rows' => $rows,
        ];
    }

    public static function activeRowByEntity(string $moduleType, string $entity, int $id): ?object
    {
        $table = self::entityTable($entity);
        return self::moduleQuery($table, $moduleType)
            ->where("{$table}.id", $id)
            ->first();
    }

    public static function lockActiveRowByEntity(string $moduleType, string $entity, int $id): ?object
    {
        $table = self::entityTable($entity);
        return self::moduleQuery($table, $moduleType)
            ->where("{$table}.id", $id)
            ->lockForUpdate()
            ->first();
    }

    public static function entityVisible(array $scope, string $moduleType, string $entity, int $id): bool
    {
        $table = self::entityTable($entity);
        $query = self::moduleQuery($table, $moduleType)->where("{$table}.id", $id);
        self::applyPracticeScope($query, $scope, $table, $entity === 'score', $entity);
        return $query->exists();
    }

    /** 校验待写入记录是否属于当前账号的数据范围 */
    public static function entityValuesVisible(array $scope, string $moduleType, string $entity, array $values): bool
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return true;
        }

        if ($roleType === 'college_admin') {
            $depId = (int) ($values['dep_id'] ?? 0);
            return $depId > 0 && in_array($depId, self::ids($scope['dep_ids'] ?? []), true);
        }

        if ($roleType === 'profession_admin') {
            if ($entity === 'room') {
                $depId = (int) ($values['dep_id'] ?? 0);
                return $depId > 0 && in_array($depId, self::ids($scope['profession_dep_ids'] ?? []), true);
            }
            $professionId = (int) ($values['profession_id'] ?? 0);
            return $professionId > 0 && in_array($professionId, self::ids($scope['profession_ids'] ?? []), true);
        }

        if ($roleType === 'teacher') {
            $teacherId = (int) ($values['teacher_id'] ?? 0);
            $currentTeacherId = (int) ($scope['teacher_id'] ?? 0);
            if ($teacherId > 0 && $teacherId === $currentTeacherId) {
                return true;
            }

            return self::teacherRelatedToPlan(
                $moduleType,
                (int) ($values['plan_id'] ?? 0),
                $currentTeacherId
            );
        }

        if ($roleType === 'student') {
            $studentId = (int) ($values['student_id'] ?? 0);
            return $studentId > 0 && $studentId === (int) ($scope['student_id'] ?? 0);
        }

        return false;
    }

    public static function insertEntity(string $entity, array $values): int
    {
        return (int) self::queryTable(self::entityTable($entity))->insertGetId($values);
    }

    public static function updateEntityById(string $entity, int $id, array $values): int
    {
        return self::queryTable(self::entityTable($entity))->where('id', $id)->update($values);
    }

    /** 查询开课任务关联教师。 */
    public static function planTeacherRows(array $scope, string $moduleType, int $planId): array
    {
        if (!self::entityVisible($scope, $moduleType, 'plan', $planId)) {
            return [];
        }

        return self::rows(self::queryTable('practice_plan_teacher')
            ->leftJoin('teacher_list', 'practice_plan_teacher.teacher_id', '=', 'teacher_list.teacher_id')
            ->where('practice_plan_teacher.plan_id', $planId)
            ->where('practice_plan_teacher.status', 'enabled')
            ->whereNull('practice_plan_teacher.deleted_at')
            ->orderByRaw("CASE WHEN practice_plan_teacher.teacher_role = 'leader' THEN 0 ELSE 1 END")
            ->orderBy('practice_plan_teacher.sort')
            ->orderBy('practice_plan_teacher.id')
            ->get([
                'practice_plan_teacher.id',
                'practice_plan_teacher.plan_id',
                'practice_plan_teacher.teacher_id',
                'practice_plan_teacher.teacher_role',
                'practice_plan_teacher.sort',
                'teacher_list.teacher_name',
                'teacher_list.teacher_num',
                'teacher_list.dep_id',
                'teacher_list.profession_id',
            ]));
    }

    /** 保存开课任务课程负责人和任课教师关系。 */
    public static function replacePlanTeachers(
        string $moduleType,
        int $planId,
        int $leaderId,
        array $teacherIds,
        ?int $leaderAccountId,
        string $now
    ): void {
        self::queryTable('practice_plan_teacher')
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'disabled',
                'updated_at' => $now,
                'deleted_at' => $now,
            ]);

        $teacherIds = array_values(array_unique(array_filter(array_map('intval', array_merge([$leaderId], $teacherIds)))));
        foreach ($teacherIds as $sort => $teacherId) {
            self::queryTable('practice_plan_teacher')->insert([
                'uuid' => self::uuidValue(),
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
                'module_type' => $moduleType,
                'plan_id' => $planId,
                'teacher_id' => $teacherId,
                'teacher_role' => $teacherId === $leaderId ? 'leader' : 'teacher',
                'sort' => $sort,
            ]);
        }

        self::queryTable('practice_plan')->where('id', $planId)->update([
            'course_leader_id' => $leaderId,
            'course_leader_account_id' => $leaderAccountId,
            'updated_at' => $now,
        ]);
    }

    /** 校验教师是否关联开课任务。 */
    public static function teacherRelatedToPlan(string $moduleType, int $planId, int $teacherId, bool $leaderOnly = false): bool
    {
        if ($planId <= 0 || $teacherId <= 0) {
            return false;
        }

        $query = self::queryTable('practice_plan_teacher')
            ->where('module_type', $moduleType)
            ->where('plan_id', $planId)
            ->where('teacher_id', $teacherId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at');
        if ($leaderOnly) {
            $query->where('teacher_role', 'leader');
        }

        return $query->exists();
    }

    /** 查询开课任务课程负责人教师档案。 */
    public static function planCourseLeaderId(string $moduleType, int $planId): int
    {
        if ($planId <= 0) {
            return 0;
        }

        return (int) (self::moduleQuery('practice_plan', $moduleType)
            ->where('practice_plan.id', $planId)
            ->value('course_leader_id') ?: 0);
    }

    /** 校验教师档案是否属于当前数据范围。 */
    public static function visibleTeacherIds(array $scope, array $teacherIds): array
    {
        $teacherIds = self::ids($teacherIds);
        if (!$teacherIds) {
            return [];
        }

        $query = self::applyOptionScope(
            self::queryTable('teacher_list')->whereIn('teacher_id', $teacherIds)->where('status', 'enabled')->whereNull('deleted_at'),
            $scope,
            'dep_id',
            'profession_id'
        );
        return self::ids($query->pluck('teacher_id')->all());
    }

    /** 查询业务最近提交账号。 */
    public static function latestSubmitterAccountId(string $entityType, int $entityId): int
    {
        return (int) (self::queryTable('practice_recording')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('action', 'submit')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->value('operator_id') ?: 0);
    }

    /** 查询学生项目当前报告。 */
    public static function currentProjectReportId(string $moduleType, int $projectId, int $studentId): int
    {
        return (int) (self::queryTable('report')
            ->where('entity_type', $moduleType)
            ->where(function ($query) use ($projectId): void {
                $query->where('practice_project_id', $projectId)
                    ->orWhere(function ($legacy) use ($projectId): void {
                        $legacy->whereNull('practice_project_id')->where('entity_id', $projectId);
                    });
            })
            ->where('student_id', $studentId)
            ->whereIn('status', ['draft', 'wait', 'accept', 'modify'])
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->value('id') ?: 0);
    }

    /** 查询项目学生当前成绩。 */
    public static function currentProjectScoreId(string $moduleType, int $projectId, int $studentId): int
    {
        return (int) (self::queryTable('practice_score')
            ->where('module_type', $moduleType)
            ->where('project_id', $projectId)
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->value('id') ?: 0);
    }

    /** 查询执行材料所属开课任务。 */
    public static function executionPlanId(string $execution, int $id): int
    {
        $table = self::executionTable($execution);
        $query = self::queryTable($table)
            ->join('practice_project', function ($join) use ($execution, $table): void {
                $projectColumn = $execution === 'report'
                    ? self::connection()->raw("COALESCE({$table}.practice_project_id, {$table}.entity_id)")
                    : "{$table}.entity_id";
                $join->on($projectColumn, '=', 'practice_project.id');
            })
            ->where("{$table}.id", $id)
            ->whereNull("{$table}.deleted_at")
            ->whereNull('practice_project.deleted_at');

        return (int) ($query->value('practice_project.plan_id') ?: 0);
    }

    /** 查询业务记录所属开课任务。 */
    public static function entityPlanId(string $entity, int $id): int
    {
        if ($entity === 'plan') {
            return $id;
        }
        if ($entity === 'room') {
            return 0;
        }

        return (int) (self::queryTable(self::entityTable($entity))
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->value('plan_id') ?: 0);
    }

    /** 查询开课任务当前项目。 */
    public static function planProjectRows(string $moduleType, int $planId): array
    {
        return self::rows(self::moduleQuery('practice_project', $moduleType)
            ->where('practice_project.plan_id', $planId)
            ->orderBy('practice_project.id')
            ->get(['id', 'title', 'status', 'teacher_id', 'student_count']));
    }

    /** 查询开课任务当前成绩方案。 */
    public static function gradeRuleRowByPlan(string $moduleType, int $planId): ?array
    {
        $row = self::moduleQuery('practice_grade_rule', $moduleType)
            ->where('practice_grade_rule.plan_id', $planId)
            ->where('practice_grade_rule.status', '<>', 'disabled')
            ->orderByDesc('practice_grade_rule.id')
            ->first(['practice_grade_rule.*']);

        return $row ? self::rows([$row])[0] : null;
    }

    /** 查询开课任务课程汇总成绩。 */
    public static function courseScorePage(array $scope, string $moduleType, int $planId, array $filters): ?array
    {
        $plan = self::scoreSheetPlan($scope, $moduleType, $planId);
        if (!$plan) {
            return null;
        }

        $query = self::queryTable('practice_project_student')
            ->join('practice_project', 'practice_project_student.project_id', '=', 'practice_project.id')
            ->join('students', 'practice_project_student.student_id', '=', 'students.student_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->where('practice_project_student.module_type', $moduleType)
            ->where('practice_project_student.plan_id', $planId)
            ->where('practice_project_student.status', 'active')
            ->whereNull('practice_project_student.deleted_at')
            ->whereIn('practice_project.status', ['enabled', 'completed'])
            ->whereNull('practice_project.deleted_at')
            ->where('students.status', 'enabled')
            ->whereNull('students.deleted_at');
        self::applyStudentOptionScope($query, $scope, 'students');

        $roleType = (string) ($scope['role_type'] ?? '');
        $teacherId = (int) ($scope['teacher_id'] ?? 0);
        if ($roleType === 'teacher' && !self::teacherRelatedToPlan($moduleType, $planId, $teacherId, true)) {
            self::whereInOrDeny($query, 'practice_project_student.teacher_id', [$teacherId]);
        }
        foreach (['grade_id', 'dep_id', 'profession_id', 'class_id'] as $key) {
            $value = self::optionalInt($filters[$key] ?? null);
            if ($value) {
                $query->where("students.{$key}", $value);
            }
        }
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'class.class_name']);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? $filters['per_page'] ?? 20)));
        $total = (int) (clone $query)->distinct()->count('students.student_id');
        $studentColumns = [
            'students.student_id',
            'students.name as student_name',
            'students.student_num',
            'students.grade_id',
            'students.dep_id',
            'students.profession_id',
            'students.class_id',
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
            'class.class_name',
        ];
        $studentGroupColumns = [
            'students.student_id',
            'students.name',
            'students.student_num',
            'students.grade_id',
            'students.dep_id',
            'students.profession_id',
            'students.class_id',
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
            'class.class_name',
        ];
        $students = self::rows($query
            ->groupBy($studentGroupColumns)
            ->orderBy('class.class_name')
            ->orderBy('students.student_num')
            ->forPage($page, $pageSize)
            ->get($studentColumns));
        $studentIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['student_id'] ?? 0), $students)));
        if (!$studentIds) {
            return [
                'plan' => $plan,
                'rule' => self::gradeRuleRowByPlan($moduleType, $planId),
                'items' => [],
                'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
                'summary' => ['student_count' => $total, 'page_completed_count' => 0, 'page_average_score' => null],
            ];
        }

        $assignments = self::rows(self::queryTable('practice_project_student')
            ->join('practice_project', 'practice_project_student.project_id', '=', 'practice_project.id')
            ->where('practice_project_student.module_type', $moduleType)
            ->where('practice_project_student.plan_id', $planId)
            ->whereIn('practice_project_student.student_id', $studentIds)
            ->where('practice_project_student.status', 'active')
            ->whereNull('practice_project_student.deleted_at')
            ->whereIn('practice_project.status', ['enabled', 'completed'])
            ->whereNull('practice_project.deleted_at')
            ->orderBy('practice_project.id')
            ->get([
                'practice_project_student.student_id',
                'practice_project.id as project_id',
                'practice_project.title as project_title',
            ]));
        $scores = self::rows(self::moduleQuery('practice_score', $moduleType)
            ->where('practice_score.plan_id', $planId)
            ->whereIn('practice_score.student_id', $studentIds)
            ->orderByDesc('practice_score.id')
            ->get(['id', 'project_id', 'student_id', 'score_items', 'score_value', 'status', 'updated_at']));
        $rule = self::gradeRuleRowByPlan($moduleType, $planId);
        $activeProjects = array_values(array_filter(
            self::planProjectRows($moduleType, $planId),
            static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['enabled', 'completed'], true)
        ));
        $projectWeights = self::courseProjectWeightMap($activeProjects, $rule);

        $assignmentsByStudent = [];
        foreach ($assignments as $assignment) {
            $assignmentsByStudent[(int) $assignment['student_id']][] = $assignment;
        }
        $scoresByPair = [];
        foreach ($scores as $score) {
            $key = (int) ($score['student_id'] ?? 0) . ':' . (int) ($score['project_id'] ?? 0);
            $scoresByPair[$key] ??= $score;
        }

        $completedCount = 0;
        $finalScores = [];
        foreach ($students as &$student) {
            $studentId = (int) $student['student_id'];
            $projects = [];
            $weightedPoints = 0.0;
            $scoredCount = 0;
            $acceptedCount = 0;
            foreach ($assignmentsByStudent[$studentId] ?? [] as $assignment) {
                $projectId = (int) $assignment['project_id'];
                $weight = (float) ($projectWeights[$projectId] ?? 0);
                $score = $scoresByPair["{$studentId}:{$projectId}"] ?? null;
                $scoreValue = is_numeric($score['score_value'] ?? null) ? (float) $score['score_value'] : null;
                if ($scoreValue !== null) {
                    $weightedPoints += $scoreValue * $weight;
                    $scoredCount++;
                }
                if ($scoreValue !== null && ($score['status'] ?? '') === 'accept') {
                    $acceptedCount++;
                }
                $projects[] = [
                    'project_id' => $projectId,
                    'project_title' => $assignment['project_title'] ?? '',
                    'weight' => $weight,
                    'score_value' => $scoreValue,
                    'status' => $score['status'] ?? 'missing',
                ];
            }
            $expectedCount = count($projects);
            $previewScore = $scoredCount > 0
                ? round($weightedPoints / 100, 2)
                : null;
            $completed = $expectedCount > 0 && $acceptedCount === $expectedCount && $scoredCount === $expectedCount;
            if ($completed) {
                $completedCount++;
                $finalScores[] = $previewScore;
            }
            $student['expected_project_count'] = $expectedCount;
            $student['scored_project_count'] = $scoredCount;
            $student['accepted_project_count'] = $acceptedCount;
            $student['missing_project_count'] = max(0, $expectedCount - $scoredCount);
            $student['preview_score'] = $previewScore;
            $student['final_score'] = $completed ? $previewScore : null;
            $student['status'] = $completed ? 'completed' : 'pending';
            $student['projects'] = $projects;
            $student['module_type'] = $moduleType;
            $student['plan_id'] = $planId;
            $student['plan_title'] = (string) (($plan['title'] ?? '') ?: ($plan['course_name'] ?? ''));
        }
        unset($student);

        return [
            'plan' => $plan,
            'rule' => $rule,
            'items' => $students,
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
            'summary' => [
                'student_count' => $total,
                'page_completed_count' => $completedCount,
                'page_average_score' => $finalScores ? round(array_sum($finalScores) / count($finalScores), 2) : null,
            ],
        ];
    }

    /** 保存项目变化后的动态成绩方案。 */
    public static function syncGradeRuleProjects(string $moduleType, array $project, string $uuid, string $now): int
    {
        $planId = (int) ($project['plan_id'] ?? 0);
        if ($planId <= 0) {
            return 0;
        }

        $projects = self::planProjectRows($moduleType, $planId);
        $activeProjects = array_values(array_filter($projects, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['enabled', 'completed'], true)));
        $rule = self::gradeRuleRowByPlan($moduleType, $planId);
        $ratio = is_array($rule['ratio_json'] ?? null) ? $rule['ratio_json'] : [];
        $oldProjects = is_array($ratio['projects'] ?? null) ? $ratio['projects'] : [];
        $oldMap = [];
        foreach ($oldProjects as $item) {
            $itemId = (int) ($item['project_id'] ?? 0);
            if ($itemId > 0) {
                $oldMap[$itemId] = $item;
            }
        }

        $activeIds = array_map(static fn (array $row): int => (int) $row['id'], $activeProjects);
        $sameSet = $activeIds === array_values(array_filter(array_map(static fn (array $item): int => (int) ($item['project_id'] ?? 0), $oldProjects), static fn (int $id): bool => $id > 0));
        $weights = $sameSet
            ? array_map(static fn (array $row): float => (float) ($oldMap[(int) $row['id']]['weight'] ?? 0), $activeProjects)
            : self::evenWeights(count($activeProjects));
        if ($sameSet && round(array_sum($weights), 2) !== 100.0) {
            $weights = self::evenWeights(count($activeProjects));
        }

        $ratio['attendance_weight'] = (float) ($ratio['attendance_weight'] ?? 20);
        $ratio['operation_weight'] = (float) ($ratio['operation_weight'] ?? 70);
        $ratio['report_weight'] = (float) ($ratio['report_weight'] ?? 10);
        $ratio['projects'] = [];
        foreach ($activeProjects as $index => $row) {
            $ratio['projects'][] = [
                'project_id' => (int) $row['id'],
                'title' => (string) ($row['title'] ?? ''),
                'weight' => $weights[$index] ?? 0,
                'status' => (string) ($row['status'] ?? 'enabled'),
            ];
        }
        foreach ($projects as $row) {
            $projectId = (int) $row['id'];
            if (in_array($projectId, $activeIds, true) || !isset($oldMap[$projectId])) {
                continue;
            }
            $ratio['projects'][] = array_merge($oldMap[$projectId], [
                'project_id' => $projectId,
                'title' => (string) ($row['title'] ?? ($oldMap[$projectId]['title'] ?? '')),
                'status' => (string) ($row['status'] ?? 'disabled'),
            ]);
        }

        $values = [
            'module_type' => $moduleType,
            'plan_id' => $planId,
            'grade_id' => $project['grade_id'] ?? null,
            'dep_id' => $project['dep_id'] ?? null,
            'profession_id' => $project['profession_id'] ?? null,
            'class_id' => $project['class_id'] ?? null,
            'teacher_id' => $project['teacher_id'] ?? null,
            'course_name' => $project['course_name'] ?? null,
            'title' => (($project['course_name'] ?? '') ?: '课程') . '成绩方案',
            'ratio_json' => json_encode($ratio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'enabled',
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        if ($rule) {
            self::queryTable('practice_grade_rule')->where('id', (int) $rule['id'])->update($values);
            return (int) $rule['id'];
        }

        return (int) self::queryTable('practice_grade_rule')->insertGetId(array_merge($values, [
            'uuid' => $uuid,
            'created_at' => $now,
        ]));
    }

    /** 查询开课任务归档检查结果。 */
    public static function archiveCheckRows(array $scope, string $moduleType, int $planId): ?array
    {
        $planQuery = self::moduleQuery('practice_plan', $moduleType)
            ->leftJoin('grade_list', 'practice_plan.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'practice_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'practice_plan.profession_id', '=', 'profession.profession_id')
            ->where('practice_plan.id', $planId);
        self::applyPracticeScope($planQuery, $scope, 'practice_plan', false, 'plan');
        $planRow = $planQuery->first([
            'practice_plan.*',
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
        ]);
        if (!$planRow) {
            return null;
        }

        $plan = self::rows([$planRow])[0];
        $teachers = self::planTeacherRows($scope, $moduleType, $planId);
        $projects = self::planProjectRows($moduleType, $planId);
        $activeProjectIds = array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($projects, static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['enabled', 'completed'], true))
        ));
        $projectStudents = self::projectStudentPairs($moduleType, $activeProjectIds);
        $expectedReports = count($projectStudents);
        $items = [
            self::archiveStatusItem('plan', '开课任务', in_array((string) ($plan['status'] ?? ''), ['accept', 'enabled'], true) ? 'accepted' : self::materialStatus((string) ($plan['status'] ?? ''))),
            self::archiveEntityItem($moduleType, 'practice_syllabus', $planId, 'syllabus', '课程大纲', true),
            self::archiveEntityItem($moduleType, 'practice_grade_rule', $planId, 'grade_rule', '考核计划和成绩方案', false),
            self::archiveCountItem($moduleType, 'practice_schedule', $planId, 'schedule', '课表安排', ['enabled'], 1),
            self::archiveEntityItem($moduleType, 'practice_lesson_plan', $planId, 'lesson_plan', '课程教案', true),
            self::archiveCountItem($moduleType, 'practice_project', $planId, 'project', '项目清单', ['enabled', 'completed'], 1),
            self::archiveStatusItem(
                'project_student',
                '项目学生名单',
                $expectedReports > 0 ? 'accepted' : 'missing',
                $expectedReports,
                1
            ),
            self::archiveProjectStudentExecutionItem($moduleType, $activeProjectIds, $projectStudents, 'sign_in', '签到记录'),
            self::archiveProjectStudentExecutionItem($moduleType, $activeProjectIds, $projectStudents, 'report', '学生项目报告'),
            self::archiveProjectStudentScoreItem($moduleType, $activeProjectIds, $projectStudents),
            self::archiveEntityItem($moduleType, 'practice_reflection', $planId, 'reflection', '课程教学反思总结', true),
        ];
        $missing = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'missing'));
        $pending = array_values(array_filter($items, static fn (array $item): bool => in_array($item['status'], ['pending', 'modify'], true)));

        return [
            'ready' => !$missing && !$pending,
            'plan' => $plan,
            'teachers' => $teachers,
            'projects' => $projects,
            'items' => $items,
            'missing_items' => $missing,
            'pending_items' => $pending,
            'expected_report_count' => $expectedReports,
        ];
    }

    /** 生成开课任务归档快照。 */
    public static function archiveSnapshotRows(array $scope, string $moduleType, int $planId): ?array
    {
        $check = self::archiveCheckRows($scope, $moduleType, $planId);
        if (!$check) {
            return null;
        }

        $projectIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $check['projects'])));
        $studentRows = [];
        $scheduleRows = [];
        $signInRows = [];
        $reportRows = [];
        $scoreRows = [];
        if ($projectIds) {
            $studentRows = self::rows(self::queryTable('practice_project_student')
                ->leftJoin('students', 'practice_project_student.student_id', '=', 'students.student_id')
                ->where('practice_project_student.module_type', $moduleType)
                ->whereIn('practice_project_student.project_id', $projectIds)
                ->where('practice_project_student.status', 'active')
                ->whereNull('practice_project_student.deleted_at')
                ->orderBy('practice_project_student.project_id')
                ->orderBy('students.student_num')
                ->get([
                    'practice_project_student.project_id',
                    'practice_project_student.student_id',
                    'practice_project_student.teacher_id',
                    'students.name as student_name',
                    'students.student_num',
                    'students.class_id',
                ]));
            $signInRows = self::rows(self::queryTable('sign_in')
                ->leftJoin('students', 'sign_in.student_id', '=', 'students.student_id')
                ->where('sign_in.entity_type', $moduleType)
                ->whereIn('sign_in.entity_id', $projectIds)
                ->whereNull('sign_in.deleted_at')
                ->orderBy('sign_in.entity_id')
                ->orderBy('students.student_num')
                ->get([
                    'sign_in.id',
                    'sign_in.entity_id as project_id',
                    'sign_in.student_id',
                    'students.name as student_name',
                    'students.student_num',
                    'sign_in.date',
                    'sign_in.location',
                    'sign_in.longitude',
                    'sign_in.latitude',
                    'sign_in.status',
                    'sign_in.created_at',
                ]));
            $reportRows = self::rows(self::queryTable('report')
                ->where('entity_type', $moduleType)
                ->where(function ($query) use ($projectIds): void {
                    $query->whereIn('practice_project_id', $projectIds)
                        ->orWhere(function ($legacy) use ($projectIds): void {
                            $legacy->whereNull('practice_project_id')->whereIn('entity_id', $projectIds);
                        });
                })
                ->whereNull('deleted_at')
                ->orderByRaw('COALESCE(practice_project_id, entity_id)')
                ->orderBy('student_id')
                ->get([
                    'id',
                    self::connection()->raw('COALESCE(practice_project_id, entity_id) as project_id'),
                    'student_id',
                    'title',
                    'content',
                    'remark',
                    'attachment_ids',
                    'status',
                    'submitted_at',
                    'reflection_summary',
                ]));
            $scoreRows = self::rows(self::moduleQuery('practice_score', $moduleType)
                ->where('practice_score.plan_id', $planId)
                ->whereIn('practice_score.project_id', $projectIds)
                ->orderBy('practice_score.project_id')
                ->orderBy('practice_score.student_id')
                ->get(['id', 'project_id', 'student_id', 'teacher_id', 'score_items', 'score_value', 'status']));
        }

        $scheduleRows = self::rows(self::moduleQuery('practice_schedule', $moduleType)
            ->where('practice_schedule.plan_id', $planId)
            ->orderBy('practice_schedule.schedule_date')
            ->orderBy('practice_schedule.start_time')
            ->get(['practice_schedule.*']));
        $syllabusRows = self::rows(self::moduleQuery('practice_syllabus', $moduleType)
            ->where('practice_syllabus.plan_id', $planId)
            ->orderBy('practice_syllabus.id')
            ->get(['practice_syllabus.*']));
        $lessonPlanRows = self::rows(self::moduleQuery('practice_lesson_plan', $moduleType)
            ->where('practice_lesson_plan.plan_id', $planId)
            ->orderBy('practice_lesson_plan.id')
            ->get(['practice_lesson_plan.*']));
        $reflectionRows = self::rows(self::moduleQuery('practice_reflection', $moduleType)
            ->where('practice_reflection.plan_id', $planId)
            ->orderBy('practice_reflection.id')
            ->get(['practice_reflection.*']));
        $courseScoreRows = [];
        $courseScorePage = 1;
        do {
            $courseScores = self::courseScorePage($scope, $moduleType, $planId, [
                'page' => $courseScorePage,
                'page_size' => 100,
            ]);
            $courseScoreRows = array_merge($courseScoreRows, $courseScores['items'] ?? []);
            $courseScorePage++;
        } while ($courseScores && count($courseScoreRows) < (int) ($courseScores['pagination']['total'] ?? 0));

        return array_merge($check, [
            'students' => $studentRows,
            'schedules' => $scheduleRows,
            'sign_ins' => $signInRows,
            'reports' => $reportRows,
            'scores' => $scoreRows,
            'course_scores' => $courseScoreRows,
            'syllabus' => $syllabusRows,
            'lesson_plans' => $lessonPlanRows,
            'reflections' => $reflectionRows,
            'grade_rule' => self::gradeRuleRowByPlan($moduleType, $planId),
            'snapshot_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 查询开课任务归档版本。 */
    public static function practiceArchivePage(array $scope, string $moduleType, array $filters): array
    {
        $query = self::queryTable('practice_archive')
            ->leftJoin('practice_plan', 'practice_archive.plan_id', '=', 'practice_plan.id')
            ->leftJoin('grade_list', 'practice_plan.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'practice_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'practice_plan.profession_id', '=', 'profession.profession_id')
            ->whereNull('practice_archive.deleted_at');
        if (in_array($moduleType, ['training', 'lab'], true)) {
            $query->where('practice_archive.module_type', $moduleType);
        }
        self::applyPracticeScope($query, $scope, 'practice_plan', false, 'plan');
        foreach (['plan_id', 'status'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $query->where("practice_archive.{$key}", $value);
            }
        }
        foreach (['grade_id', 'dep_id', 'profession_id'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $query->where("practice_plan.{$key}", $value);
            }
        }
        self::keyword($query, $filters, ['practice_plan.title', 'practice_plan.course_name', 'department.dep_name', 'profession.profession_name']);

        return self::paginate($query->orderByDesc('practice_archive.id'), $filters, [
            'practice_archive.*',
            'practice_plan.title as plan_title',
            'practice_plan.course_name',
            'practice_plan.grade_id',
            'practice_plan.dep_id',
            'practice_plan.profession_id',
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
        ]);
    }

    /** 查询归档版本详情。 */
    public static function archiveDetailRow(array $scope, string $moduleType, int $id): ?array
    {
        $query = self::queryTable('practice_archive')
            ->leftJoin('practice_plan', 'practice_archive.plan_id', '=', 'practice_plan.id')
            ->where('practice_archive.id', $id)
            ->whereNull('practice_archive.deleted_at');
        if (in_array($moduleType, ['training', 'lab'], true)) {
            $query->where('practice_archive.module_type', $moduleType);
        }
        self::applyPracticeScope($query, $scope, 'practice_plan', false, 'plan');
        $row = $query->first([
            'practice_archive.*',
            'practice_plan.title as plan_title',
            'practice_plan.course_name',
        ]);

        return $row ? self::rows([$row])[0] : null;
    }

    /** 锁定开课任务当前归档版本。 */
    public static function lockCurrentArchive(string $moduleType, int $planId): ?object
    {
        return self::queryTable('practice_archive')
            ->where('module_type', $moduleType)
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->orderByDesc('version_no')
            ->lockForUpdate()
            ->first();
    }

    /** 查询下一个归档版本号。 */
    public static function nextArchiveVersion(string $moduleType, int $planId): int
    {
        return (int) self::queryTable('practice_archive')
            ->where('module_type', $moduleType)
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->max('version_no') + 1;
    }

    /** 新增归档版本。 */
    public static function insertArchive(array $values): int
    {
        return (int) self::queryTable('practice_archive')->insertGetId($values);
    }

    /** 失效开课任务已有归档。 */
    public static function invalidatePlanArchives(string $moduleType, int $planId, int $accountId, string $reason, string $now): int
    {
        return self::queryTable('practice_archive')
            ->where('module_type', $moduleType)
            ->where('plan_id', $planId)
            ->where('status', 'archived')
            ->whereNull('deleted_at')
            ->update([
                'status' => 'invalidated',
                'invalidated_by' => $accountId,
                'invalidated_at' => $now,
                'invalidate_reason' => $reason,
                'updated_at' => $now,
            ]);
    }

    public static function idByUuid(string $entity, string $uuid): int
    {
        return (int) (self::queryTable(self::entityTable($entity))->where('uuid', $uuid)->value('id') ?: 0);
    }

    public static function uuidById(string $entity, int $id): ?string
    {
        $uuid = self::queryTable(self::entityTable($entity))->where('id', $id)->value('uuid');
        return $uuid ? (string) $uuid : null;
    }

    public static function statusById(string $entity, int $id, string $default = 'draft'): string
    {
        return (string) (self::queryTable(self::entityTable($entity))->where('id', $id)->value('status') ?: $default);
    }

    public static function recordingRows(string $entityType, int $entityId): array
    {
        return self::queryTable('practice_recording')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
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

    public static function insertRecording(array $values): int
    {
        return (int) self::queryTable('practice_recording')->insertGetId($values);
    }

    public static function insertReviewOpinion(array $values): int
    {
        return (int) self::queryTable('review_opinion')->insertGetId($values);
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

    public static function teacherUserId(int $teacherId): ?int
    {
        if ($teacherId <= 0) {
            return null;
        }

        $userId = self::queryTable('teacher_list')
            ->where('teacher_id', $teacherId)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $userId ? (int) $userId : null;
    }

    public static function studentUserId(int $studentId): ?int
    {
        if ($studentId <= 0) {
            return null;
        }

        $userId = self::queryTable('students')
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $userId ? (int) $userId : null;
    }

    public static function studentProfile(int $studentId): ?array
    {
        $row = self::queryTable('students')
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->first(['student_id', 'grade_id', 'dep_id', 'profession_id', 'class_id']);

        return $row ? $row->getAttributes() : null;
    }

    public static function planRowForSchedule(array $scope, string $moduleType, int $planId): ?array
    {
        if ($planId <= 0) {
            return null;
        }

        $query = self::moduleQuery('practice_plan', $moduleType)
            ->where('practice_plan.id', $planId);
        self::applyPracticeScope($query, $scope, 'practice_plan', false, 'plan');
        $row = $query->first([
            'practice_plan.id',
            'practice_plan.status',
            'practice_plan.grade_id',
            'practice_plan.dep_id',
            'practice_plan.profession_id',
            'practice_plan.class_id',
            'practice_plan.teacher_id',
            'practice_plan.course_name',
            'practice_plan.title',
        ]);

        return $row ? $row->getAttributes() : null;
    }

    public static function roomRowForSchedule(array $scope, string $moduleType, int $roomId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }

        $query = self::moduleQuery('practice_room', $moduleType)
            ->where('practice_room.id', $roomId)
            ->where('practice_room.status', 'enabled');
        self::applyPracticeScope($query, $scope, 'practice_room', false, 'room');
        $row = $query->first(['practice_room.id', 'practice_room.capacity']);

        return $row ? $row->getAttributes() : null;
    }

    /** 查询当前账号可用的校外基地 */
    public static function baseRowForSchedule(array $scope, int $baseId): ?array
    {
        if ($baseId <= 0) {
            return null;
        }

        $query = self::queryTable('base')
            ->where('base.id', $baseId)
            ->where('base.status', 'enabled')
            ->whereNull('base.deleted_at');
        self::applyPracticeBaseScope($query, $scope);
        $row = $query->first(['base.id', 'base.name', 'base.dep_id']);

        return $row ? $row->getAttributes() : null;
    }

    public static function scheduleRowForProject(array $scope, string $moduleType, int $scheduleId): ?array
    {
        if ($scheduleId <= 0) {
            return null;
        }

        $query = self::moduleQuery('practice_schedule', $moduleType)
            ->where('practice_schedule.id', $scheduleId)
            ->where('practice_schedule.status', 'enabled');
        self::applyPracticeScope($query, $scope, 'practice_schedule');
        $row = $query->first([
            'practice_schedule.id',
            'practice_schedule.plan_id',
            'practice_schedule.grade_id',
            'practice_schedule.dep_id',
            'practice_schedule.profession_id',
            'practice_schedule.class_id',
            'practice_schedule.teacher_id',
            'practice_schedule.course_name',
            'practice_schedule.title',
            'practice_schedule.schedule_date',
            'practice_schedule.period_start_id',
            'practice_schedule.period_end_id',
            'practice_schedule.start_time',
            'practice_schedule.end_time',
            'practice_schedule.student_count',
        ]);

        return $row ? $row->getAttributes() : null;
    }

    public static function enabledStudentCountByClass(int $classId): int
    {
        if ($classId <= 0) {
            return 0;
        }

        return (int) self::queryTable('students')
            ->where('class_id', $classId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->count();
    }

    public static function enabledStudentsByClass(int $classId): array
    {
        if ($classId <= 0) {
            return [];
        }

        return self::rows(self::queryTable('students')
            ->where('class_id', $classId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('student_id')
            ->get(['student_id', 'grade_id', 'dep_id', 'profession_id', 'class_id']));
    }

    /** 统计年级专业下的启用学生 */
    public static function enabledStudentCountByProfession(int $gradeId, int $professionId): int
    {
        if ($gradeId <= 0 || $professionId <= 0) {
            return 0;
        }

        return (int) self::queryTable('students')
            ->where('grade_id', $gradeId)
            ->where('profession_id', $professionId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->count();
    }

    /** 查询年级专业下的启用学生 */
    public static function enabledStudentsByProfession(int $gradeId, int $professionId): array
    {
        if ($gradeId <= 0 || $professionId <= 0) {
            return [];
        }

        return self::rows(self::queryTable('students')
            ->where('grade_id', $gradeId)
            ->where('profession_id', $professionId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('student_id')
            ->get(['student_id', 'grade_id', 'dep_id', 'profession_id', 'class_id']));
    }

    public static function syncProjectStudents(string $moduleType, int $projectId, array $project, array $students, string $batchCode, string $now): int
    {
        if ($projectId <= 0) {
            return 0;
        }

        self::queryTable('practice_project_student')
            ->where('module_type', $moduleType)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'disabled',
                'updated_at' => $now,
                'deleted_at' => $now,
            ]);

        $count = 0;
        foreach ($students as $student) {
            self::queryTable('practice_project_student')->insert([
                'uuid' => $student['uuid'] ?? null,
                'name' => $project['title'] ?? null,
                'code' => $batchCode,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
                'module_type' => $moduleType,
                'project_id' => $projectId,
                'student_id' => (int) ($student['student_id'] ?? 0),
                'teacher_id' => $project['teacher_id'] ?? null,
                'plan_id' => $project['plan_id'] ?? null,
                'schedule_id' => $project['schedule_id'] ?? null,
                'grade_id' => $student['grade_id'] ?? ($project['grade_id'] ?? null),
                'dep_id' => $student['dep_id'] ?? ($project['dep_id'] ?? null),
                'profession_id' => $student['profession_id'] ?? ($project['profession_id'] ?? null),
                'class_id' => $student['class_id'] ?? ($project['class_id'] ?? null),
            ]);
            $count++;
        }

        return $count;
    }

    public static function projectStudentCount(string $moduleType, int $projectId): int
    {
        if ($projectId <= 0) {
            return 0;
        }

        return (int) self::queryTable('practice_project_student')
            ->where('module_type', $moduleType)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->count();
    }

    public static function projectExecutionRow(array $scope, string $moduleType, int $projectId): ?array
    {
        if ($projectId <= 0) {
            return null;
        }

        $query = self::moduleQuery('practice_project', $moduleType)
            ->where('practice_project.id', $projectId)
            ->whereIn('practice_project.status', ['enabled', 'completed']);
        self::applyPracticeScope($query, $scope, 'practice_project', false, 'project');
        $row = $query->first([
            'practice_project.id',
            'practice_project.plan_id',
            'practice_project.schedule_id',
            'practice_project.grade_id',
            'practice_project.dep_id',
            'practice_project.profession_id',
            'practice_project.class_id',
            'practice_project.teacher_id',
            'practice_project.course_name',
            'practice_project.title',
            'practice_project.status',
        ]);

        return $row ? $row->getAttributes() : null;
    }

    public static function projectStudentRow(string $moduleType, int $projectId, int $studentId): ?array
    {
        if ($projectId <= 0 || $studentId <= 0) {
            return null;
        }

        $row = self::queryTable('practice_project_student')
            ->where('module_type', $moduleType)
            ->where('project_id', $projectId)
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['student_id', 'teacher_id', 'plan_id', 'schedule_id', 'grade_id', 'dep_id', 'profession_id', 'class_id']);

        return $row ? $row->getAttributes() : null;
    }

    /** 查询可见项目的学生及当前成绩。 */
    public static function projectStudentScoreRows(array $scope, string $moduleType, int $projectId): array
    {
        if (!self::projectExecutionRow($scope, $moduleType, $projectId)) {
            return [];
        }

        $latestScores = self::queryTable('practice_score')
            ->selectRaw('MAX(id) as id, module_type, project_id, student_id')
            ->where('module_type', $moduleType)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->groupBy('module_type', 'project_id', 'student_id');
        $query = self::queryTable('practice_project_student')
            ->join('students', 'practice_project_student.student_id', '=', 'students.student_id')
            ->leftJoinSub($latestScores, 'current_score', function ($join): void {
                $join->on('current_score.student_id', '=', 'practice_project_student.student_id')
                    ->on('current_score.module_type', '=', 'practice_project_student.module_type')
                    ->on('current_score.project_id', '=', 'practice_project_student.project_id');
            })
            ->leftJoin('practice_score', 'practice_score.id', '=', 'current_score.id')
            ->where('practice_project_student.module_type', $moduleType)
            ->where('practice_project_student.project_id', $projectId)
            ->where('practice_project_student.status', 'active')
            ->whereNull('practice_project_student.deleted_at')
            ->whereNull('students.deleted_at');
        if (($scope['role_type'] ?? '') === 'teacher') {
            self::whereInOrDeny($query, 'practice_project_student.teacher_id', [(int) ($scope['teacher_id'] ?? 0)]);
        } elseif (($scope['role_type'] ?? '') === 'student') {
            self::whereInOrDeny($query, 'practice_project_student.student_id', [(int) ($scope['student_id'] ?? 0)]);
        }

        return self::rows($query
            ->orderBy('students.student_num')
            ->orderBy('students.student_id')
            ->get([
                'students.student_id',
                'students.name as student_name',
                'students.student_num',
                'students.class_id',
                'practice_score.id as score_id',
                'practice_score.score_items',
                'practice_score.score_value',
                'practice_score.status as score_status',
            ]));
    }

    public static function executionPage(array $scope, string $moduleType, string $execution, array $filters): array
    {
        $table = self::executionTable($execution);
        $query = self::executionQuery($moduleType, $execution);
        self::applyExecutionScope($query, $scope, $moduleType, $table);
        self::applyExecutionFilters($query, $table, $execution, $filters);

        return self::paginate($query->orderByDesc("{$table}.id"), $filters, self::executionColumns($execution, $table));
    }

    public static function insertExecution(string $execution, array $values): int
    {
        return (int) self::queryTable(self::executionTable($execution))->insertGetId($values);
    }

    public static function updateExecution(string $execution, int $id, array $values): int
    {
        return self::queryTable(self::executionTable($execution))->where('id', $id)->update($values);
    }

    public static function activeExecutionRow(array $scope, string $moduleType, string $execution, int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $table = self::executionTable($execution);
        $query = self::queryTable($table)
            ->where("{$table}.id", $id)
            ->whereNull("{$table}.deleted_at");
        if (in_array($moduleType, ['training', 'lab'], true)) {
            $query->where("{$table}.entity_type", $moduleType);
        } else {
            $query->whereIn("{$table}.entity_type", ['training', 'lab']);
        }
        self::applyExecutionScope($query, $scope, $moduleType, $table);

        return $query->first(["{$table}.*"]);
    }

    public static function lockExecutionRow(array $scope, string $moduleType, string $execution, int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $table = self::executionTable($execution);
        $query = self::queryTable($table)
            ->where("{$table}.id", $id)
            ->whereNull("{$table}.deleted_at");
        if (in_array($moduleType, ['training', 'lab'], true)) {
            $query->where("{$table}.entity_type", $moduleType);
        } else {
            $query->whereIn("{$table}.entity_type", ['training', 'lab']);
        }
        self::applyExecutionScope($query, $scope, $moduleType, $table);

        return $query->lockForUpdate()->first(["{$table}.*"]);
    }

    public static function executionVisible(array $scope, string $moduleType, string $execution, int $id): bool
    {
        return self::activeExecutionRow($scope, $moduleType, $execution, $id) !== null;
    }

    public static function upsertPracticeScore(string $moduleType, array $values): int
    {
        $studentId = (int) ($values['student_id'] ?? 0);
        $projectId = (int) ($values['project_id'] ?? 0);
        if ($studentId <= 0 || $projectId <= 0) {
            return 0;
        }

        $existingId = (int) (self::queryTable('practice_score')
            ->where('module_type', $moduleType)
            ->where('student_id', $studentId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->value('id') ?: 0);

        if ($existingId > 0) {
            unset($values['uuid'], $values['created_at']);
            self::queryTable('practice_score')->where('id', $existingId)->update($values);
            return $existingId;
        }

        return (int) self::queryTable('practice_score')->insertGetId($values);
    }

    public static function scheduleConflictExists(array $values, ?int $excludeId = null): bool
    {
        $date = (string) ($values['schedule_date'] ?? '');
        $start = (string) ($values['start_time'] ?? '');
        $end = (string) ($values['end_time'] ?? '');
        if ($date === '' || $start === '' || $end === '') {
            return false;
        }

        $query = self::moduleQuery('practice_schedule', 'all')
            ->where('practice_schedule.schedule_date', $date)
            ->where('practice_schedule.start_time', '<', $end)
            ->where('practice_schedule.end_time', '>', $start)
            ->where('practice_schedule.status', '<>', 'disabled');
        if ($excludeId && $excludeId > 0) {
            $query->where('practice_schedule.id', '<>', $excludeId);
        }

        $teacherId = (int) ($values['teacher_id'] ?? 0);
        $gradeId = (int) ($values['grade_id'] ?? 0);
        $professionId = (int) ($values['profession_id'] ?? 0);
        $roomId = (int) ($values['room_id'] ?? 0);
        $baseId = (int) ($values['base_id'] ?? 0);
        $placeType = (string) ($values['place_type'] ?? 'inside');
        if ($teacherId <= 0 && ($gradeId <= 0 || $professionId <= 0) && !($placeType === 'inside' && $roomId > 0) && !($placeType === 'outside' && $baseId > 0)) {
            return false;
        }

        return $query->where(function ($builder) use ($baseId, $gradeId, $placeType, $professionId, $roomId, $teacherId): void {
            if ($teacherId > 0) {
                $builder->orWhere('practice_schedule.teacher_id', $teacherId);
            }
            if ($gradeId > 0 && $professionId > 0) {
                $builder->orWhere(function ($academic) use ($gradeId, $professionId): void {
                    $academic->where('practice_schedule.grade_id', $gradeId)
                        ->where('practice_schedule.profession_id', $professionId);
                });
            }
            if ($placeType === 'inside' && $roomId > 0) {
                $builder->orWhere('practice_schedule.room_id', $roomId);
            }
            if ($placeType === 'outside' && $baseId > 0) {
                $builder->orWhere('practice_schedule.base_id', $baseId);
            }
        })->exists();
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

    private static function practiceModuleType(array $filters): string
    {
        $moduleType = (string) ($filters['module_type'] ?? 'training');
        return in_array($moduleType, ['training', 'lab'], true) ? $moduleType : 'training';
    }

    private static function scoreSheetRowsByPlan(array $scope, string $moduleType, int $planId, array $filters): array
    {
        $plan = self::scoreSheetPlan($scope, $moduleType, $planId);
        if (!$plan) {
            return [];
        }

        $query = self::queryTable('students')
            ->leftJoin('practice_score', function ($join) use ($moduleType, $planId): void {
                $join->on('students.student_id', '=', 'practice_score.student_id')
                    ->where('practice_score.module_type', $moduleType)
                    ->where('practice_score.plan_id', $planId)
                    ->whereNull('practice_score.deleted_at');
            })
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->leftJoin('teacher_list', 'practice_score.teacher_id', '=', 'teacher_list.teacher_id')
            ->where('students.status', 'enabled')
            ->whereNull('students.deleted_at');

        foreach (['grade_id', 'dep_id', 'profession_id', 'class_id'] as $field) {
            $value = (int) ($plan[$field] ?? 0);
            if ($value > 0) {
                $query->where("students.{$field}", $value);
            }
        }

        self::applyStudentOptionScope($query, $scope, 'students');
        self::listScoreSheetFilters($query, $filters, 'students', false);
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'class.class_name', 'practice_score.title']);

        return self::rows($query
            ->orderBy('class.class_name')
            ->orderBy('students.student_num')
            ->orderBy('practice_score.project_id')
            ->orderByDesc('practice_score.id')
            ->get(self::scoreSheetStudentColumns()));
    }

    private static function scoreSheetRowsByScores(array $scope, string $moduleType, array $filters): array
    {
        $query = self::moduleQuery('practice_score', $moduleType)
            ->leftJoin('practice_plan', 'practice_score.plan_id', '=', 'practice_plan.id')
            ->leftJoin('students', 'practice_score.student_id', '=', 'students.student_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->leftJoin('teacher_list', 'practice_score.teacher_id', '=', 'teacher_list.teacher_id');

        self::applyPracticeScope($query, $scope, 'practice_score', true, 'score');
        self::listScoreSheetFilters($query, $filters, 'practice_score');
        self::keyword($query, $filters, ['students.name', 'students.student_num', 'class.class_name', 'practice_score.title', 'practice_plan.title', 'teacher_list.teacher_name']);

        return self::rows($query
            ->orderBy('practice_score.plan_id')
            ->orderBy('students.student_num')
            ->orderBy('practice_score.project_id')
            ->orderByDesc('practice_score.id')
            ->get(self::scoreSheetStudentColumns()));
    }

    private static function scoreSheetStudentColumns(): array
    {
        return [
            'practice_score.id as score_id',
            'practice_score.plan_id',
            'practice_score.score_items',
            'practice_score.score_value',
            'practice_score.project_id',
            'practice_score.status as score_status',
            'practice_score.teacher_id as score_teacher_id',
            'practice_score.title as score_title',
            'students.student_id',
            'students.name',
            'students.student_num',
            'students.grade_id',
            'students.dep_id',
            'students.profession_id',
            'students.class_id',
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
            'class.class_name',
            'class.class_num',
            'teacher_list.teacher_name',
            'teacher_list.teacher_num',
        ];
    }

    private static function scoreSheetRows(array $rows): array
    {
        $items = [];
        $projectKeys = [];
        foreach ($rows as $row) {
            $scoreItems = is_array($row['score_items'] ?? null) ? $row['score_items'] : [];
            $attendance = self::scoreItemList($scoreItems, ['attendance', 'attendance_scores', 'class_performance', 'classroom_performance', 'performance'], 16);
            $projects = self::scoreItemList($scoreItems, ['project', 'project_scores', 'operation', 'practice', 'practical_scores'], 12);
            if (!$projects) {
                $projectScore = self::scoreItemValue($scoreItems, ['material_score', 'project_score', 'operation_score']);
                if ($projectScore !== null) {
                    $projects = [$projectScore];
                }
            }
            $item = [
                'student_id' => $row['student_id'] ?? null,
                'student_num' => $row['student_num'] ?? null,
                'student_name' => $row['name'] ?? null,
                'class_name' => $row['class_name'] ?? ($row['class_num'] ?? null),
                'grade_name' => $row['grade_name'] ?? null,
                'dep_name' => $row['dep_name'] ?? null,
                'profession_name' => $row['profession_name'] ?? null,
                'score_id' => $row['score_id'] ?? null,
                'score_status' => $row['score_status'] ?? null,
                'project_id' => $row['project_id'] ?? null,
            ];

            for ($i = 1; $i <= 16; $i++) {
                $item["attendance_{$i}"] = $attendance[$i - 1] ?? null;
            }
            $item['attendance_total'] = self::scoreItemValue($scoreItems, ['attendance_total', 'class_performance_total', 'performance_total'])
                ?? self::scoreTotal($attendance);

            for ($i = 1; $i <= 12; $i++) {
                $item["project_{$i}"] = $projects[$i - 1] ?? null;
            }
            $item['project_total'] = self::scoreItemValue($scoreItems, ['project_total', 'operation_total', 'practice_total']);
            $item['report_score'] = self::scoreItemValue($scoreItems, ['report_score', 'report', 'course_report', 'course_report_score']);
            $item['total_score'] = self::scoreItemValue($scoreItems, ['total_score', 'final_score', 'score_value']) ?? ($row['score_value'] ?? null);
            $groupKey = (int) ($item['student_id'] ?? 0) . ':' . (int) ($row['plan_id'] ?? 0);
            if (!isset($items[$groupKey])) {
                $items[$groupKey] = $item;
                $projectKeys[$groupKey] = [];
                if ($item['project_id'] !== null) {
                    $projectKeys[$groupKey][(string) $item['project_id']] = true;
                }
                continue;
            }

            self::mergeScoreSheetItem($items[$groupKey], $item, $projectKeys[$groupKey]);
        }

        $items = array_values($items);
        foreach ($items as $index => &$item) {
            $item['sequence'] = $index + 1;
            unset($item['project_id']);
        }
        unset($item);

        return $items;
    }

    /** 合并同一教学计划下同一学生的多个项目成绩记录。 */
    private static function mergeScoreSheetItem(array &$target, array $item, array &$projectKeys): void
    {
        $projectId = $item['project_id'];
        if ($projectId !== null && isset($projectKeys[(string) $projectId])) {
            return;
        }
        if ($projectId !== null) {
            $projectKeys[(string) $projectId] = true;
        }
        if (self::scoreValueMissing($target['score_id'] ?? null) && !self::scoreValueMissing($item['score_id'] ?? null)) {
            $target['score_id'] = $item['score_id'];
            $target['score_status'] = $item['score_status'];
        }

        for ($index = 1; $index <= 16; $index++) {
            $key = "attendance_{$index}";
            if (self::scoreValueMissing($target[$key] ?? null) && !self::scoreValueMissing($item[$key] ?? null)) {
                $target[$key] = $item[$key];
            }
        }
        for ($sourceIndex = 1; $sourceIndex <= 12; $sourceIndex++) {
            $key = "project_{$sourceIndex}";
            if (self::scoreValueMissing($item[$key] ?? null)) {
                continue;
            }
            $targetIndex = $sourceIndex;
            while ($targetIndex <= 12 && !self::scoreValueMissing($target["project_{$targetIndex}"] ?? null)) {
                $targetIndex++;
            }
            if ($targetIndex <= 12) {
                $target["project_{$targetIndex}"] = $item[$key];
            }
        }
        foreach (['attendance_total', 'project_total', 'report_score', 'total_score'] as $key) {
            if (self::scoreValueMissing($target[$key] ?? null) && !self::scoreValueMissing($item[$key] ?? null)) {
                $target[$key] = $item[$key];
            }
        }
    }

    private static function scoreValueMissing(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private static function scoreSheetMeta(array $scope, string $moduleType, ?int $planId, array $filters): array
    {
        $plan = $planId ? self::scoreSheetPlan($scope, $moduleType, $planId) : null;
        $schedule = $planId ? self::scoreSheetScheduleMeta($moduleType, $planId) : ['class_time' => '', 'location' => ''];

        return [
            'school_name' => (string) ($scope['school_name'] ?? '成都锦城学院'),
            'module_type' => $moduleType,
            'module_name' => $moduleType === 'lab' ? '实验' : '实训',
            'semester' => (string) ($filters['semester'] ?? ''),
            'course_number' => (string) ($plan['code'] ?? ''),
            'course_name' => (string) (($plan['course_name'] ?? '') ?: ($plan['title'] ?? '')),
            'teacher_name' => (string) ($plan['teacher_name'] ?? ''),
            'teacher_unit' => (string) ($plan['dep_name'] ?? ''),
            'class_time' => $schedule['class_time'],
            'location' => $schedule['location'],
        ];
    }

    private static function scoreSheetPlan(array $scope, string $moduleType, int $planId): ?array
    {
        $query = self::moduleQuery('practice_plan', $moduleType)
            ->leftJoin('grade_list', 'practice_plan.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'practice_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'practice_plan.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class', 'practice_plan.class_id', '=', 'class.class_id')
            ->leftJoin('teacher_list', 'practice_plan.teacher_id', '=', 'teacher_list.teacher_id')
            ->where('practice_plan.id', $planId);
        self::applyPracticeScope($query, $scope, 'practice_plan', false, 'plan');
        $row = $query->first([
            'practice_plan.id',
            'practice_plan.code',
            'practice_plan.title',
            'practice_plan.course_name',
            'practice_plan.grade_id',
            'practice_plan.dep_id',
            'practice_plan.profession_id',
            'practice_plan.class_id',
            'practice_plan.teacher_id',
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
            'class.class_name',
            'teacher_list.teacher_name',
        ]);

        return $row ? $row->getAttributes() : null;
    }

    private static function scoreSheetScheduleMeta(string $moduleType, int $planId): array
    {
        $rows = self::rows(self::moduleQuery('practice_schedule', $moduleType)
            ->where('plan_id', $planId)
            ->orderBy('schedule_date')
            ->orderBy('start_time')
            ->limit(8)
            ->get(['schedule_date', 'start_time', 'end_time', 'location']));
        $times = [];
        $locations = [];
        foreach ($rows as $row) {
            $time = trim(implode(' ', array_filter([
                $row['schedule_date'] ?? '',
                trim(($row['start_time'] ?? '') . '-' . ($row['end_time'] ?? ''), '-'),
            ])));
            if ($time !== '') {
                $times[] = $time;
            }
            if (!empty($row['location'])) {
                $locations[] = (string) $row['location'];
            }
        }

        return [
            'class_time' => implode('；', array_slice(array_values(array_unique($times)), 0, 3)),
            'location' => implode('；', array_slice(array_values(array_unique($locations)), 0, 3)),
        ];
    }

    private static function listScoreSheetFilters(mixed $query, array $filters, string $alias, bool $withScoreFilters = true): void
    {
        foreach (['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id', 'status'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!$withScoreFilters && $key === 'plan_id') {
                continue;
            }
            $column = match ($key) {
                'status' => 'practice_score.status',
                'plan_id' => 'practice_score.plan_id',
                default => "{$alias}.{$key}",
            };
            $query->where($column, $value);
        }
    }

    private static function scoreItemList(array $items, array $keys, int $limit): array
    {
        $value = self::scoreItemValue($items, $keys, true);
        if (!is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            return array_map(static fn ($item): mixed => self::scoreItemScalar($item), array_slice($value, 0, $limit));
        }

        $result = [];
        for ($i = 1; $i <= $limit; $i++) {
            $result[] = self::scoreItemScalar($value[$i] ?? $value[(string) $i] ?? $value["item_{$i}"] ?? null);
        }

        return $result;
    }

    private static function scoreItemValue(array $items, array $keys, bool $allowArray = false): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $items)) {
                continue;
            }
            $value = $items[$key];
            if (is_array($value)) {
                return $allowArray ? $value : self::scoreItemScalar($value);
            }

            return $value;
        }

        return null;
    }

    private static function scoreItemScalar(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach (['score', 'value', 'point', 'points', 'text'] as $key) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }
        }

        return null;
    }

    private static function scoreTotal(array $values): ?float
    {
        $total = 0.0;
        $hasValue = false;
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $total += (float) $value;
                $hasValue = true;
            }
        }

        return $hasValue ? round($total, 2) : null;
    }

    private static function scoreSheetCards(array $rows, string $moduleType): array
    {
        $scores = array_values(array_filter(array_map(static fn (array $row): mixed => $row['total_score'] ?? null, $rows), 'is_numeric'));

        return [
            ['name' => '模块类型', 'value' => $moduleType === 'lab' ? '实验' : '实训', 'desc' => '当前成绩记载表来源'],
            ['name' => '学生人数', 'value' => count($rows), 'desc' => '当前筛选范围内学生'],
            ['name' => '已录成绩', 'value' => count(array_filter($rows, static fn (array $row): bool => !empty($row['score_id']))), 'desc' => '已有成绩记录的学生'],
            ['name' => '平均总分', 'value' => self::averageScoreText($scores), 'desc' => '已录总分平均值'],
        ];
    }

    private static function scoreSheetColumns(): array
    {
        $columns = [
            ['key' => 'sequence', 'label' => '序号', 'width' => 70],
            ['key' => 'student_num', 'label' => '学号', 'width' => 130],
            ['key' => 'student_name', 'label' => '姓名', 'width' => 100],
            ['key' => 'class_name', 'label' => '行政班级', 'width' => 130],
        ];
        for ($i = 1; $i <= 16; $i++) {
            $columns[] = ['key' => "attendance_{$i}", 'label' => "考勤{$i}", 'width' => 72];
        }
        $columns[] = ['key' => 'attendance_total', 'label' => '考勤小计', 'width' => 88];
        for ($i = 1; $i <= 12; $i++) {
            $columns[] = ['key' => "project_{$i}", 'label' => "项目{$i}", 'width' => 72];
        }
        $columns[] = ['key' => 'report_score', 'label' => '课程报告', 'width' => 88];
        $columns[] = ['key' => 'total_score', 'label' => '总分', 'width' => 88];

        return $columns;
    }

    private static function averageScoreText(array $values): string
    {
        if (!$values) {
            return '-';
        }

        return (string) round(array_sum(array_map('floatval', $values)) / count($values), 2);
    }

    /** 生成合计为 100 的均分权重。 */
    private static function evenWeights(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $base = floor(10000 / $count) / 100;
        $weights = array_fill(0, $count, $base);
        $weights[$count - 1] = round(100 - $base * ($count - 1), 2);
        return $weights;
    }

    /** 生成课程汇总使用的项目权重。 */
    private static function courseProjectWeightMap(array $projects, ?array $rule): array
    {
        $projectRows = [];
        foreach ($projects as $project) {
            $projectId = (int) ($project['id'] ?? 0);
            if ($projectId > 0) {
                $projectRows[$projectId] = true;
            }
        }
        $projectIds = array_keys($projectRows);
        $configured = [];
        foreach (($rule['ratio_json']['projects'] ?? []) as $item) {
            $projectId = (int) ($item['project_id'] ?? 0);
            if ($projectId > 0 && in_array($projectId, $projectIds, true) && ($item['status'] ?? 'enabled') !== 'disabled') {
                $configured[$projectId] = (float) ($item['weight'] ?? 0);
            }
        }
        if (count($configured) === count($projectIds) && abs(array_sum($configured) - 100) < 0.001) {
            return $configured;
        }

        return array_combine($projectIds, self::evenWeights(count($projectIds))) ?: [];
    }

    /** 查询有效的项目学生绑定。 */
    private static function projectStudentPairs(string $moduleType, array $projectIds): array
    {
        if (!$projectIds) {
            return [];
        }

        return self::rows(self::queryTable('practice_project_student')
            ->where('module_type', $moduleType)
            ->whereIn('project_id', $projectIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('project_id')
            ->orderBy('student_id')
            ->get(['project_id', 'student_id']));
    }

    /** 生成归档清单状态项。 */
    private static function archiveStatusItem(string $key, string $name, string $status, int $count = 1, int $requiredCount = 1): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'status' => $status,
            'count' => $count,
            'required_count' => $requiredCount,
        ];
    }

    /** 生成单项业务材料归档状态。 */
    private static function archiveEntityItem(string $moduleType, string $table, int $planId, string $key, string $name, bool $reviewRequired): array
    {
        $row = self::moduleQuery($table, $moduleType)
            ->where("{$table}.plan_id", $planId)
            ->orderByDesc("{$table}.id")
            ->first(["{$table}.id", "{$table}.status"]);
        if (!$row) {
            return self::archiveStatusItem($key, $name, 'missing', 0);
        }

        $status = $reviewRequired ? self::materialStatus((string) $row->status) : 'accepted';
        return self::archiveStatusItem($key, $name, $status);
    }

    /** 生成数量型业务材料归档状态。 */
    private static function archiveCountItem(string $moduleType, string $table, int $planId, string $key, string $name, array $acceptedStatuses, int $requiredCount): array
    {
        $count = (int) self::moduleQuery($table, $moduleType)
            ->where("{$table}.plan_id", $planId)
            ->whereIn("{$table}.status", $acceptedStatuses)
            ->count();
        return self::archiveStatusItem($key, $name, $count >= $requiredCount ? 'accepted' : 'missing', $count, $requiredCount);
    }

    /** 按项目学生绑定生成执行材料归档状态。 */
    private static function archiveProjectStudentExecutionItem(
        string $moduleType,
        array $projectIds,
        array $projectStudents,
        string $execution,
        string $name
    ): array
    {
        $requiredCount = count($projectStudents);
        if ($requiredCount <= 0 || !$projectIds) {
            return self::archiveStatusItem($execution, $name, 'accepted', 0, 0);
        }

        $table = self::executionTable($execution);
        $projectColumn = $execution === 'report'
            ? "COALESCE({$table}.practice_project_id, {$table}.entity_id)"
            : "{$table}.entity_id";
        $rows = self::rows(self::queryTable($table)
            ->where("{$table}.entity_type", $moduleType)
            ->whereIn(self::connection()->raw($projectColumn), $projectIds)
            ->whereNull("{$table}.deleted_at")
            ->orderByDesc("{$table}.id")
            ->get([
                "{$table}.id",
                self::connection()->raw("{$projectColumn} as project_id"),
                "{$table}.student_id",
                "{$table}.status",
            ]));
        $latestStatuses = self::latestProjectStudentStatuses($rows);
        if ($execution === 'sign_in') {
            $acceptedCount = self::projectStudentStatusCount($projectStudents, $latestStatuses, ['signed']);
            return self::archiveStatusItem(
                $execution,
                $name,
                $acceptedCount >= $requiredCount ? 'accepted' : 'missing',
                $acceptedCount,
                $requiredCount
            );
        }

        return self::reviewedProjectStudentArchiveItem($execution, $name, $projectStudents, $latestStatuses);
    }

    /** 按项目学生绑定生成成绩归档状态。 */
    private static function archiveProjectStudentScoreItem(string $moduleType, array $projectIds, array $projectStudents): array
    {
        if (!$projectStudents || !$projectIds) {
            return self::archiveStatusItem('score', '成绩明细', 'accepted', 0, 0);
        }

        $rows = self::rows(self::moduleQuery('practice_score', $moduleType)
            ->whereIn('practice_score.project_id', $projectIds)
            ->orderByDesc('practice_score.id')
            ->get(['practice_score.id', 'practice_score.project_id', 'practice_score.student_id', 'practice_score.status']));

        return self::reviewedProjectStudentArchiveItem(
            'score',
            '成绩明细',
            $projectStudents,
            self::latestProjectStudentStatuses($rows)
        );
    }

    /** 生成需审核的项目学生材料状态。 */
    private static function reviewedProjectStudentArchiveItem(string $key, string $name, array $projectStudents, array $statuses): array
    {
        $requiredCount = count($projectStudents);
        $acceptedCount = self::projectStudentStatusCount($projectStudents, $statuses, ['accept']);
        if ($acceptedCount >= $requiredCount) {
            return self::archiveStatusItem($key, $name, 'accepted', $acceptedCount, $requiredCount);
        }

        $missingCount = 0;
        $modifyCount = 0;
        foreach ($projectStudents as $item) {
            $status = $statuses[self::projectStudentKey($item)] ?? '';
            if ($status === 'modify') {
                $modifyCount++;
            } elseif ($status !== 'wait') {
                $missingCount++;
            }
        }

        $status = $missingCount > 0 ? 'missing' : ($modifyCount > 0 ? 'modify' : 'pending');
        return self::archiveStatusItem($key, $name, $status, $acceptedCount, $requiredCount);
    }

    /** 提取每个项目学生的最新状态。 */
    private static function latestProjectStudentStatuses(array $rows): array
    {
        $statuses = [];
        foreach ($rows as $row) {
            $key = self::projectStudentKey($row);
            if (!isset($statuses[$key])) {
                $statuses[$key] = (string) ($row['status'] ?? '');
            }
        }

        return $statuses;
    }

    /** 统计指定状态的项目学生数量。 */
    private static function projectStudentStatusCount(array $projectStudents, array $statuses, array $acceptedStatuses): int
    {
        $count = 0;
        foreach ($projectStudents as $item) {
            if (in_array($statuses[self::projectStudentKey($item)] ?? '', $acceptedStatuses, true)) {
                $count++;
            }
        }

        return $count;
    }

    /** 生成项目学生关联键。 */
    private static function projectStudentKey(array $item): string
    {
        return (int) ($item['project_id'] ?? 0) . ':' . (int) ($item['student_id'] ?? 0);
    }

    /** 转换流程状态为归档材料状态。 */
    private static function materialStatus(string $status): string
    {
        return match ($status) {
            'accept', 'enabled', 'completed' => 'accepted',
            'modify' => 'modify',
            'wait' => 'pending',
            default => 'missing',
        };
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

    private static function entityTable(string $entity): string
    {
        if (!isset(self::ENTITY_TABLES[$entity])) {
            throw new \InvalidArgumentException('entity 无效');
        }

        return self::ENTITY_TABLES[$entity];
    }

    private static function executionTable(string $execution): string
    {
        if (!isset(self::EXECUTION_TABLES[$execution])) {
            throw new \InvalidArgumentException('execution 无效');
        }

        return self::EXECUTION_TABLES[$execution];
    }

    private static function moduleQuery(string $table, string $moduleType): mixed
    {
        $query = self::queryTable($table)->whereNull("{$table}.deleted_at");
        if (in_array($moduleType, ['training', 'lab'], true)) {
            $query->where("{$table}.module_type", $moduleType);
        }

        return $query;
    }

    private static function entityQuery(string $moduleType, string $entity): mixed
    {
        $table = self::entityTable($entity);
        $query = self::moduleQuery($table, $moduleType);

        if (!in_array($entity, ['room', 'plan'], true)) {
            $query->leftJoin('practice_plan', "{$table}.plan_id", '=', 'practice_plan.id');
        }
        if ($entity === 'room') {
            $query->leftJoin('department', "{$table}.dep_id", '=', 'department.dep_id');
            return $query;
        }

        $query->leftJoin('grade_list', "{$table}.grade_id", '=', 'grade_list.grade_id')
            ->leftJoin('department', "{$table}.dep_id", '=', 'department.dep_id')
            ->leftJoin('profession', "{$table}.profession_id", '=', 'profession.profession_id')
            ->leftJoin('class', "{$table}.class_id", '=', 'class.class_id')
            ->leftJoin('teacher_list', "{$table}.teacher_id", '=', 'teacher_list.teacher_id');

        if ($entity === 'schedule') {
            $query->leftJoin('practice_room', 'practice_schedule.room_id', '=', 'practice_room.id')
                ->leftJoin('base', 'practice_schedule.base_id', '=', 'base.id')
                ->leftJoin('practice_period as period_start', 'practice_schedule.period_start_id', '=', 'period_start.id')
                ->leftJoin('practice_period as period_end', 'practice_schedule.period_end_id', '=', 'period_end.id');
        }
        if ($entity === 'project') {
            $query->leftJoin('practice_schedule', 'practice_project.schedule_id', '=', 'practice_schedule.id');
        }
        if ($entity === 'score') {
            $query->leftJoin('students', 'practice_score.student_id', '=', 'students.student_id');
        }

        return $query;
    }

    private static function executionQuery(string $moduleType, string $execution): mixed
    {
        $table = self::executionTable($execution);
        $query = self::queryTable($table)
            ->leftJoin('practice_project', function ($join) use ($execution, $moduleType, $table): void {
                $projectColumn = $execution === 'report'
                    ? self::connection()->raw("COALESCE({$table}.practice_project_id, {$table}.entity_id)")
                    : "{$table}.entity_id";
                $join->on($projectColumn, '=', 'practice_project.id')
                    ->whereNull('practice_project.deleted_at');
                if (in_array($moduleType, ['training', 'lab'], true)) {
                    $join->where('practice_project.module_type', $moduleType);
                }
            })
            ->leftJoin('practice_plan', 'practice_project.plan_id', '=', 'practice_plan.id')
            ->leftJoin('students', "{$table}.student_id", '=', 'students.student_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->leftJoin('teacher_list', "{$table}.teacher_id", '=', 'teacher_list.teacher_id')
            ->whereNull("{$table}.deleted_at");
        if (in_array($moduleType, ['training', 'lab'], true)) {
            $query->where("{$table}.entity_type", $moduleType);
        } else {
            $query->whereIn("{$table}.entity_type", ['training', 'lab']);
        }

        return $query;
    }

    private static function entityColumns(string $entity, string $table): array
    {
        $columns = ["{$table}.*"];
        if ($entity === 'room') {
            $columns[] = 'department.dep_name';
            return $columns;
        }

        $columns = array_merge($columns, [
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
            'class.class_name',
            'teacher_list.teacher_name',
        ]);
        if (!in_array($entity, ['room', 'plan'], true)) {
            $columns[] = 'practice_plan.title as plan_title';
            $columns[] = 'practice_plan.course_name as plan_course_name';
        }
        if ($entity === 'plan') {
            $columns[] = self::connection()->raw('(SELECT teacher_name FROM teacher_list WHERE teacher_list.teacher_id = practice_plan.course_leader_id AND teacher_list.deleted_at IS NULL LIMIT 1) as course_leader_name');
            $columns[] = self::connection()->raw('(SELECT COUNT(*) FROM practice_plan_teacher WHERE practice_plan_teacher.plan_id = practice_plan.id AND practice_plan_teacher.status = \'enabled\' AND practice_plan_teacher.deleted_at IS NULL) as related_teacher_count');
        }
        if ($entity === 'schedule') {
            $columns[] = 'practice_room.name as room_name';
            $columns[] = 'base.name as base_name';
            $columns[] = 'period_start.name as period_start_name';
            $columns[] = 'period_end.name as period_end_name';
        }
        if ($entity === 'project') {
            $columns[] = 'practice_schedule.title as schedule_title';
            $columns[] = 'practice_schedule.schedule_date';
            $columns[] = 'practice_schedule.start_time';
            $columns[] = 'practice_schedule.end_time';
            $columns[] = self::connection()->raw('(SELECT COUNT(*) FROM practice_project_student WHERE practice_project_student.project_id = practice_project.id AND practice_project_student.status = \'active\' AND practice_project_student.deleted_at IS NULL) as bound_student_count');
        }
        if ($entity === 'score') {
            $columns[] = 'students.name as student_name';
            $columns[] = 'students.student_num';
        }

        return $columns;
    }

    private static function executionColumns(string $execution, string $table): array
    {
        $columns = [
            "{$table}.*",
            'practice_project.title as project_title',
            'practice_project.course_name as project_course_name',
            'practice_project.plan_id',
            'practice_project.schedule_id',
            'practice_plan.title as plan_title',
            'students.name as student_name',
            'students.student_num',
            'students.grade_id',
            'students.dep_id',
            'students.profession_id',
            'students.class_id',
            'grade_list.grade_name',
            'department.dep_name',
            'profession.profession_name',
            'class.class_name',
            'teacher_list.teacher_name',
        ];

        $columns[] = 'practice_project.id as project_id';

        return $columns;
    }

    private static function applyEntityFilters(mixed $query, string $table, string $entity, array $filters): void
    {
        foreach (self::filterKeys($entity) as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $query->where("{$table}.{$key}", $value);
            }
        }
        $scopeFilterKeys = $entity === 'room' ? ['dep_id'] : ['grade_id', 'dep_id', 'profession_id', 'class_id'];
        foreach ($scopeFilterKeys as $key) {
            $value = self::optionalInt($filters[$key] ?? null);
            if ($value) {
                $query->where("{$table}.{$key}", $value);
            }
        }

        $date = trim((string) ($filters['date'] ?? ''));
        if ($entity === 'schedule' && $date !== '') {
            $query->where("{$table}.schedule_date", $date);
        }

        self::keyword($query, $filters, match ($entity) {
            'room' => ["{$table}.name", "{$table}.code", "{$table}.location", 'department.dep_name'],
            'schedule' => ["{$table}.title", "{$table}.course_name", "{$table}.location", 'practice_room.name', 'base.name', 'teacher_list.teacher_name'],
            'project' => ["{$table}.title", "{$table}.course_name", "{$table}.content", 'practice_schedule.title', 'teacher_list.teacher_name'],
            'score' => ["{$table}.title", 'students.name', 'students.student_num', 'teacher_list.teacher_name', 'practice_plan.title'],
            default => ["{$table}.title", "{$table}.course_name", "{$table}.content", 'department.dep_name', 'profession.profession_name', 'teacher_list.teacher_name', 'practice_plan.title'],
        });
    }

    private static function applyExecutionFilters(mixed $query, string $table, string $execution, array $filters): void
    {
        foreach (['status', 'teacher_id', 'student_id'] as $key) {
            $value = self::optionalInt($filters[$key] ?? null) ?? trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $query->where("{$table}.{$key}", $value);
            }
        }
        $projectId = self::optionalInt($filters['project_id'] ?? ($filters['entity_id'] ?? null));
        if ($projectId) {
            $query->where('practice_project.id', $projectId);
        }
        foreach (['grade_id', 'dep_id', 'profession_id', 'class_id', 'plan_id'] as $key) {
            $value = self::optionalInt($filters[$key] ?? null);
            if (!$value) {
                continue;
            }
            $column = $key === 'plan_id' ? 'practice_project.plan_id' : "students.{$key}";
            $query->where($column, $value);
        }
        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '') {
            $query->where("{$table}.date", $date);
        }

        $keywordColumns = match ($execution) {
            'sign_in' => ['students.name', 'students.student_num', 'practice_project.title', "{$table}.location", "{$table}.remark"],
            'journal', 'report' => ['students.name', 'students.student_num', 'practice_project.title', "{$table}.title", "{$table}.content"],
            default => ['students.name', 'students.student_num', 'practice_project.title'],
        };
        self::keyword($query, $filters, $keywordColumns);
    }

    private static function filterKeys(string $entity): array
    {
        return match ($entity) {
            'plan' => ['status', 'teacher_id', 'source_type'],
            'schedule' => ['status', 'plan_id', 'teacher_id', 'room_id', 'base_id', 'place_type'],
            'project' => ['status', 'plan_id', 'schedule_id', 'teacher_id'],
            'syllabus', 'lessonPlan', 'reflection' => ['status', 'plan_id', 'teacher_id'],
            'gradeRule' => ['status', 'plan_id', 'teacher_id'],
            'score' => ['status', 'plan_id', 'teacher_id'],
            'room' => ['status'],
            default => ['status'],
        };
    }

    private static function applyPracticeScope(mixed $query, array $scope, string $alias, bool $hasStudent = false, string $entity = ''): mixed
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return $query;
        }
        if ($roleType === 'college_admin') {
            return self::whereInOrDeny($query, "{$alias}.dep_id", $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin') {
            if ($entity === 'room') {
                return self::whereInOrDeny($query, "{$alias}.dep_id", $scope['profession_dep_ids'] ?? []);
            }
            return self::whereInOrDeny($query, "{$alias}.profession_id", $scope['profession_ids'] ?? []);
        }
        if ($entity === 'room') {
            return $query->whereRaw('1 = 0');
        }
        if ($roleType === 'teacher') {
            $teacherId = (int) ($scope['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($builder) use ($alias, $entity, $teacherId): void {
                if (in_array($entity, ['syllabus', 'gradeRule'], true)) {
                    $builder->whereExists(function ($relation) use ($alias, $teacherId): void {
                        $relation->selectRaw('1')
                            ->from('practice_plan_teacher')
                            ->whereColumn('practice_plan_teacher.plan_id', "{$alias}.plan_id")
                            ->where('practice_plan_teacher.teacher_id', $teacherId)
                            ->where('practice_plan_teacher.teacher_role', 'leader')
                            ->where('practice_plan_teacher.status', 'enabled')
                            ->whereNull('practice_plan_teacher.deleted_at');
                    });
                    return;
                }

                $builder->where("{$alias}.teacher_id", $teacherId);
                if ($entity === 'plan') {
                    $builder->orWhere("{$alias}.course_leader_id", $teacherId);
                }
                if ($entity === 'score') {
                    $builder->orWhereExists(function ($relation) use ($alias, $teacherId): void {
                        $relation->selectRaw('1')
                            ->from('practice_plan_teacher')
                            ->whereColumn('practice_plan_teacher.plan_id', "{$alias}.plan_id")
                            ->where('practice_plan_teacher.teacher_id', $teacherId)
                            ->where('practice_plan_teacher.teacher_role', 'leader')
                            ->where('practice_plan_teacher.status', 'enabled')
                            ->whereNull('practice_plan_teacher.deleted_at');
                    });
                }
                if (in_array($entity, ['plan', 'lessonPlan', 'reflection'], true)) {
                    $planColumn = $entity === 'plan' ? "{$alias}.id" : "{$alias}.plan_id";
                    $builder->orWhereExists(function ($relation) use ($planColumn, $teacherId): void {
                        $relation->selectRaw('1')
                            ->from('practice_plan_teacher')
                            ->whereColumn('practice_plan_teacher.plan_id', $planColumn)
                            ->where('practice_plan_teacher.teacher_id', $teacherId)
                            ->where('practice_plan_teacher.status', 'enabled')
                            ->whereNull('practice_plan_teacher.deleted_at');
                    });
                    if ($entity === 'plan') {
                        $builder->orWhereExists(function ($relation) use ($planColumn, $teacherId): void {
                            $relation->selectRaw('1')
                                ->from('practice_project')
                                ->whereColumn('practice_project.plan_id', $planColumn)
                                ->where('practice_project.teacher_id', $teacherId)
                                ->whereNull('practice_project.deleted_at');
                        });
                    }
                }
                if ($entity === 'project') {
                    $builder->orWhereExists(function ($relation) use ($alias, $teacherId): void {
                        $relation->selectRaw('1')
                            ->from('practice_plan_teacher')
                            ->whereColumn('practice_plan_teacher.plan_id', "{$alias}.plan_id")
                            ->where('practice_plan_teacher.teacher_id', $teacherId)
                            ->where('practice_plan_teacher.status', 'enabled')
                            ->whereNull('practice_plan_teacher.deleted_at');
                    });
                }
            });
        }
        if ($roleType === 'student') {
            if ($hasStudent) {
                return self::whereInOrDeny($query, "{$alias}.student_id", [(int) ($scope['student_id'] ?? 0)]);
            }
            if ($entity === 'plan') {
                return self::applyStudentPlanScope($query, $alias, (int) ($scope['student_id'] ?? 0));
            }
            if ($entity === 'project') {
                return self::applyStudentProjectScope($query, $alias, (int) ($scope['student_id'] ?? 0));
            }
            self::applyStudentAcademicScope($query, $alias, $scope['student_profile'] ?? null);
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    private static function applyStudentProjectScope(mixed $query, string $alias, int $studentId): mixed
    {
        if ($studentId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($builder) use ($alias, $studentId): void {
            $builder->selectRaw('1')
                ->from('practice_project_student')
                ->whereColumn('practice_project_student.project_id', "{$alias}.id")
                ->where('practice_project_student.student_id', $studentId)
                ->where('practice_project_student.status', 'active')
                ->whereNull('practice_project_student.deleted_at');
        });
    }

    /** 限定学生已绑定项目所属开课任务。 */
    private static function applyStudentPlanScope(mixed $query, string $alias, int $studentId): mixed
    {
        if ($studentId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($builder) use ($alias, $studentId): void {
            $builder->selectRaw('1')
                ->from('practice_project')
                ->join('practice_project_student', 'practice_project_student.project_id', '=', 'practice_project.id')
                ->whereColumn('practice_project.plan_id', "{$alias}.id")
                ->where('practice_project_student.student_id', $studentId)
                ->where('practice_project_student.status', 'active')
                ->whereNull('practice_project_student.deleted_at')
                ->whereNull('practice_project.deleted_at');
        });
    }

    private static function applyExecutionScope(mixed $query, array $scope, string $moduleType, string $alias): mixed
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return $query;
        }
        if ($roleType === 'college_admin') {
            return self::whereInOrDeny($query, 'students.dep_id', $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin') {
            return self::whereInOrDeny($query, 'students.profession_id', $scope['profession_ids'] ?? []);
        }
        if ($roleType === 'teacher') {
            $teacherId = (int) ($scope['teacher_id'] ?? 0);
            if ($teacherId <= 0) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($builder) use ($alias, $teacherId): void {
                $builder->where("{$alias}.teacher_id", $teacherId)
                    ->orWhere('practice_project.teacher_id', $teacherId);
            });
        }
        if ($roleType === 'student') {
            self::whereInOrDeny($query, "{$alias}.student_id", [(int) ($scope['student_id'] ?? 0)]);
            return $query->whereExists(function ($builder) use ($moduleType): void {
                $builder->selectRaw('1')
                    ->from('practice_project_student')
                    ->whereColumn('practice_project_student.project_id', 'practice_project.id')
                    ->whereColumn('practice_project_student.student_id', 'students.student_id')
                    ->where('practice_project_student.status', 'active')
                    ->whereNull('practice_project_student.deleted_at');
                if (in_array($moduleType, ['training', 'lab'], true)) {
                    $builder->where('practice_project_student.module_type', $moduleType);
                }
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /** 生成模型内部 UUID。 */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function applyStudentAcademicScope(mixed $query, string $alias, ?array $student): void
    {
        if (!$student) {
            $query->whereRaw('1 = 0');
            return;
        }

        $matchedFields = [];
        foreach (['grade_id', 'dep_id', 'profession_id', 'class_id'] as $field) {
            $value = (int) ($student[$field] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $matchedFields[$field] = $value;
            $query->where(function ($builder) use ($alias, $field, $value): void {
                $builder->whereNull("{$alias}.{$field}")->orWhere("{$alias}.{$field}", $value);
            });
        }

        if (!$matchedFields) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($builder) use ($alias, $matchedFields): void {
            foreach ($matchedFields as $field => $value) {
                $builder->orWhere("{$alias}.{$field}", $value);
            }
        });
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

    private static function applyStudentOptionScope(mixed $query, array $scope, string $alias = ''): mixed
    {
        $column = static fn (string $field): string => $alias !== '' ? "{$alias}.{$field}" : $field;
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin') {
            return self::whereInOrDeny($query, $column('dep_id'), $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin') {
            return self::whereInOrDeny($query, $column('profession_id'), $scope['profession_ids'] ?? []);
        }
        if ($roleType === 'student') {
            return self::whereInOrDeny($query, $column('student_id'), [(int) ($scope['student_id'] ?? 0)]);
        }

        return $query;
    }

    private static function applyPracticeBaseScope(mixed $query, array $scope): mixed
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin') {
            return self::whereInOrDeny($query, 'base.dep_id', $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin') {
            return self::whereInOrDeny($query, 'base.dep_id', $scope['profession_dep_ids'] ?? []);
        }

        return $query;
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
            foreach (self::JSON_FIELDS as $jsonField) {
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

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
        $query->where(function ($builder) use ($columns, $like): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $builder->where($column, 'like', $like)
                    : $builder->orWhere($column, 'like', $like);
            }
        });
    }

    private static function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function intValues(mixed $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $result[] = (int) $value;
            }
        }

        return array_values(array_unique($result));
    }

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
