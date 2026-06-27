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

    private const JSON_FIELDS = ['content_json', 'ratio_json', 'score_items'];
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
                ->get(['id', 'uuid', 'name', 'code', 'dep_id', 'room_type', 'capacity', 'location'])),
            'plans' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_plan', $moduleType)->whereIn('practice_plan.status', ['wait', 'accept', 'enabled']), $scope, 'practice_plan')
                ->orderByDesc('practice_plan.id')
                ->get(['id', 'uuid', 'title', 'course_name', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'status'])),
            'schedules' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_schedule', $moduleType)->where('practice_schedule.status', 'enabled'), $scope, 'practice_schedule')
                ->orderByDesc('practice_schedule.id')
                ->get(['id', 'uuid', 'title', 'course_name', 'plan_id', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'schedule_date', 'start_time', 'end_time', 'status'])),
            'projects' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_project', $moduleType)->whereIn('practice_project.status', ['enabled', 'completed']), $scope, 'practice_project', false, 'project')
                ->orderByDesc('practice_project.id')
                ->get(['id', 'uuid', 'title', 'course_name', 'plan_id', 'schedule_id', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'start_date', 'end_date', 'student_count', 'status'])),
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

    public static function insertEntity(string $entity, array $values): int
    {
        return (int) self::queryTable(self::entityTable($entity))->insertGetId($values);
    }

    public static function updateEntityById(string $entity, int $id, array $values): int
    {
        return self::queryTable(self::entityTable($entity))->where('id', $id)->update($values);
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
        self::applyPracticeScope($query, $scope, 'practice_plan');
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
            ->where("{$table}.entity_type", $moduleType)
            ->whereNull("{$table}.deleted_at");
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
            ->where("{$table}.entity_type", $moduleType)
            ->whereNull("{$table}.deleted_at");
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

    public static function scheduleConflictExists(string $moduleType, array $values, ?int $excludeId = null): bool
    {
        $date = (string) ($values['schedule_date'] ?? '');
        $start = (string) ($values['start_time'] ?? '');
        $end = (string) ($values['end_time'] ?? '');
        if ($date === '' || $start === '' || $end === '') {
            return false;
        }

        $query = self::moduleQuery('practice_schedule', $moduleType)
            ->where('practice_schedule.schedule_date', $date)
            ->where('practice_schedule.start_time', '<', $end)
            ->where('practice_schedule.end_time', '>', $start)
            ->where('practice_schedule.status', '<>', 'disabled');
        if ($excludeId && $excludeId > 0) {
            $query->where('practice_schedule.id', '<>', $excludeId);
        }

        $teacherId = (int) ($values['teacher_id'] ?? 0);
        $classId = (int) ($values['class_id'] ?? 0);
        $roomId = (int) ($values['room_id'] ?? 0);
        $baseId = (int) ($values['base_id'] ?? 0);
        $placeType = (string) ($values['place_type'] ?? 'inside');
        if ($teacherId <= 0 && $classId <= 0 && !($placeType === 'inside' && $roomId > 0) && !($placeType === 'outside' && $baseId > 0)) {
            return false;
        }

        return $query->where(function ($builder) use ($baseId, $classId, $placeType, $roomId, $teacherId): void {
            if ($teacherId > 0) {
                $builder->orWhere('practice_schedule.teacher_id', $teacherId);
            }
            if ($classId > 0) {
                $builder->orWhere('practice_schedule.class_id', $classId);
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

        return self::rows($query->orderBy('class.class_name')->orderBy('students.student_num')->get(self::scoreSheetStudentColumns()));
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

        return self::rows($query->orderByDesc('practice_score.id')->get(self::scoreSheetStudentColumns()));
    }

    private static function scoreSheetStudentColumns(): array
    {
        return [
            'practice_score.id as score_id',
            'practice_score.plan_id',
            'practice_score.score_items',
            'practice_score.score_value',
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
        foreach ($rows as $index => $row) {
            $scoreItems = is_array($row['score_items'] ?? null) ? $row['score_items'] : [];
            $attendance = self::scoreItemList($scoreItems, ['attendance', 'attendance_scores', 'class_performance', 'classroom_performance', 'performance'], 16);
            $projects = self::scoreItemList($scoreItems, ['project', 'project_scores', 'operation', 'practice', 'practical_scores'], 12);
            $item = [
                'sequence' => $index + 1,
                'student_id' => $row['student_id'] ?? null,
                'student_num' => $row['student_num'] ?? null,
                'student_name' => $row['name'] ?? null,
                'class_name' => $row['class_name'] ?? ($row['class_num'] ?? null),
                'grade_name' => $row['grade_name'] ?? null,
                'dep_name' => $row['dep_name'] ?? null,
                'profession_name' => $row['profession_name'] ?? null,
                'score_id' => $row['score_id'] ?? null,
                'score_status' => $row['score_status'] ?? null,
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
            $items[] = $item;
        }

        return $items;
    }

    private static function scoreSheetMeta(array $scope, string $moduleType, ?int $planId, array $filters): array
    {
        $plan = $planId ? self::scoreSheetPlan($scope, $moduleType, $planId) : null;
        $schedule = $planId ? self::scoreSheetScheduleMeta($moduleType, $planId) : ['class_time' => '', 'location' => ''];

        return [
            'module_type' => $moduleType,
            'module_name' => $moduleType === 'lab' ? '实验' : '实训',
            'academic_year' => (string) ($filters['academic_year'] ?? ''),
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
        self::applyPracticeScope($query, $scope, 'practice_plan');
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
        return self::queryTable($table)
            ->where("{$table}.module_type", $moduleType)
            ->whereNull("{$table}.deleted_at");
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
                ->leftJoin('base', 'practice_schedule.base_id', '=', 'base.id');
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
            ->leftJoin('practice_project', function ($join) use ($moduleType, $table): void {
                $join->on("{$table}.entity_id", '=', 'practice_project.id')
                    ->where('practice_project.module_type', $moduleType)
                    ->whereNull('practice_project.deleted_at');
            })
            ->leftJoin('practice_plan', 'practice_project.plan_id', '=', 'practice_plan.id')
            ->leftJoin('students', "{$table}.student_id", '=', 'students.student_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->leftJoin('teacher_list', "{$table}.teacher_id", '=', 'teacher_list.teacher_id')
            ->where("{$table}.entity_type", $moduleType)
            ->whereNull("{$table}.deleted_at");

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
        if ($entity === 'schedule') {
            $columns[] = 'practice_room.name as room_name';
            $columns[] = 'base.name as base_name';
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

        $columns[] = "{$table}.entity_id as project_id";

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
        $projectColumn = "{$table}.entity_id";
        foreach (['status', 'teacher_id', 'student_id'] as $key) {
            $value = self::optionalInt($filters[$key] ?? null) ?? trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $query->where("{$table}.{$key}", $value);
            }
        }
        $projectId = self::optionalInt($filters['project_id'] ?? ($filters['entity_id'] ?? null));
        if ($projectId) {
            $query->where($projectColumn, $projectId);
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
            return self::whereInOrDeny($query, "{$alias}.teacher_id", [(int) ($scope['teacher_id'] ?? 0)]);
        }
        if ($roleType === 'student') {
            if ($hasStudent) {
                return self::whereInOrDeny($query, "{$alias}.student_id", [(int) ($scope['student_id'] ?? 0)]);
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
            return self::whereInOrDeny($query, "{$alias}.teacher_id", [(int) ($scope['teacher_id'] ?? 0)]);
        }
        if ($roleType === 'student') {
            self::whereInOrDeny($query, "{$alias}.student_id", [(int) ($scope['student_id'] ?? 0)]);
            return $query->whereExists(function ($builder) use ($alias, $moduleType): void {
                $builder->selectRaw('1')
                    ->from('practice_project_student')
                    ->where('practice_project_student.module_type', $moduleType)
                    ->whereColumn('practice_project_student.project_id', "{$alias}.entity_id")
                    ->whereColumn('practice_project_student.student_id', "{$alias}.student_id")
                    ->where('practice_project_student.status', 'active')
                    ->whereNull('practice_project_student.deleted_at');
            });
        }

        return $query->whereRaw('1 = 0');
    }

    private static function applyStudentAcademicScope(mixed $query, string $alias, ?array $student): void
    {
        if (!$student) {
            $query->whereRaw('1 = 0');
            return;
        }

        foreach (['grade_id', 'dep_id', 'profession_id', 'class_id'] as $field) {
            $value = (int) ($student[$field] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $query->where(function ($builder) use ($alias, $field, $value): void {
                $builder->whereNull("{$alias}.{$field}")->orWhere("{$alias}.{$field}", $value);
            });
        }
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
