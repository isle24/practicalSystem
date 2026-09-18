<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;

class SocialPracticeRecord extends TableRecord
{
    private const ENTITY_TABLES = [
        'plan' => 'social_practice_plan',
        'project' => 'social_practice_project',
        'implementation' => 'social_practice_implementation_application',
        'declaration' => 'social_practice_declaration',
        'material' => 'social_practice_material',
        'patch_sign' => 'social_practice_patch_sign',
        'score' => 'social_practice_score',
        'archive' => 'social_practice_archive',
    ];

    /** 读取社会实践总览统计。 */
    public static function overviewRows(array $scope): array
    {
        $plans = self::planQuery();
        self::applyPlanScope($plans, $scope, 'sp_plan');
        $currentPlans = $plans
            ->where('sp_plan.status', 'accept')
            ->whereNotNull('sp_plan.published_at')
            ->whereIn('sp_plan.phase', ['enrolling', 'active', 'scoring'])
            ->orderBy('sp_plan.practice_end_at')
            ->limit(5)
            ->get(['sp_plan.id', 'sp_plan.title', 'sp_plan.grade_id', 'sp_plan.register_end_at', 'sp_plan.practice_start_at', 'sp_plan.practice_end_at', 'sp_plan.result_deadline_at', 'sp_plan.score_deadline_at', 'sp_plan.phase'])
            ->map(fn ($row) => $row->getAttributes())
            ->all();
        return [
            'active_plans' => self::countScopedPlans($scope, static fn ($query) => $query->whereIn('sp_plan.phase', ['enrolling', 'active', 'scoring'])),
            'centralized_projects' => self::countScopedProjects($scope, 'centralized'),
            'distributed_projects' => self::countScopedProjects($scope, 'distributed'),
            'pending_declarations' => self::countScopedEntity($scope, 'declaration', 'wait'),
            'pending_materials' => self::countScopedEntity($scope, 'material', 'wait'),
            'pending_scores' => self::countScopedEntity($scope, 'score', 'wait'),
            'active_participants' => self::countScopedParticipants($scope),
            'archived_records' => self::countScopedEntity($scope, 'archive', 'archived'),
            'current_plans' => $currentPlans,
        ];
    }

    /** 读取社会实践统计报表数据。 */
    public static function statisticsRows(array $scope, array $filters = []): array
    {
        $plans = self::planQuery();
        self::applyPlanScope($plans, $scope, 'sp_plan');
        self::applyPlanFilters($plans, $filters, 'sp_plan');
        $projects = self::projectQuery();
        self::applyEntityScope($projects, $scope, 'project');
        self::applyPlanFilters($projects, $filters, 'sp_plan');
        $participants = self::participantQuery();
        self::applyEntityScope($participants, $scope, 'participant');
        self::applyPlanFilters($participants, $filters, 'sp_plan');

        $gradeRows = (clone $plans)
            ->groupBy('sp_plan.grade_id', 'grade.grade_name')
            ->orderBy('grade.grade_name')
            ->get([
                'sp_plan.grade_id', 'grade.grade_name',
                new Expression('COUNT(sp_plan.id) AS plan_count'),
            ])
            ->map(fn ($row) => $row->getAttributes())
            ->all();
        $modeRows = (clone $projects)
            ->groupBy('sp_project.practice_mode')
            ->get([
                'sp_project.practice_mode',
                new Expression('COUNT(sp_project.id) AS project_count'),
                new Expression('COUNT(DISTINCT sp_project.plan_id) AS plan_count'),
            ])
            ->map(fn ($row) => $row->getAttributes())
            ->all();
        $collegeRows = (clone $participants)
            ->groupBy('student.dep_id', 'dep.dep_name')
            ->orderBy('dep.dep_name')
            ->get([
                'student.dep_id', 'dep.dep_name',
                new Expression('COUNT(DISTINCT participant.student_id) AS student_count'),
                new Expression("COUNT(DISTINCT CASE WHEN participant.project_id IS NOT NULL THEN participant.student_id END) AS assigned_count"),
            ])
            ->map(fn ($row) => $row->getAttributes())
            ->all();

        return [
            'summary' => [
                'plan_count' => (int) (clone $plans)->count('sp_plan.id'),
                'published_plan_count' => (int) (clone $plans)->where('sp_plan.status', 'accept')->whereNotNull('sp_plan.published_at')->count('sp_plan.id'),
                'project_count' => (int) (clone $projects)->count('sp_project.id'),
                'participant_count' => (int) (clone $participants)->count('participant.id'),
                'assigned_participant_count' => (int) (clone $participants)->whereNotNull('participant.project_id')->count('participant.id'),
                'material_accept_count' => self::countScopedEntity($scope, 'material', 'accept'),
                'score_accept_count' => self::countScopedEntity($scope, 'score', 'accept'),
                'archive_count' => self::countScopedEntity($scope, 'archive', 'archived'),
            ],
            'by_grade' => $gradeRows,
            'by_mode' => $modeRows,
            'by_college' => $collegeRows,
        ];
    }

    /** 读取社会实践表单选项。 */
    public static function optionRows(array $scope): array
    {
        $grades = self::queryTable('grade_list')->where('flag', 'on')->whereNull('deleted_at');
        $departments = self::queryTable('department')->where('flag', 'on')->whereNull('deleted_at');
        $professions = self::queryTable('profession')->where('flag', 'on')->whereNull('deleted_at');
        $classes = self::queryTable('class')->where('flag', 'on')->whereNull('deleted_at');
        $teachers = self::queryTable('teacher_list')->where('status', 'enabled')->whereNull('deleted_at');
        $students = self::queryTable('students')->where('status', 'enabled')->whereNull('deleted_at');

        self::applyOptionScope($departments, $scope, 'dep_id', null);
        self::applyOptionScope($professions, $scope, 'dep_id', 'profession_id');
        self::applyOptionScope($classes, $scope, 'dep_id', 'profession_id');
        self::applyOptionScope($teachers, $scope, 'dep_id', 'profession_id');
        self::applyOptionScope($students, $scope, 'dep_id', 'profession_id');

        if (($scope['role_type'] ?? '') === 'teacher') {
            self::whereInOrDeny($teachers, 'teacher_id', [(int) ($scope['teacher_id'] ?? 0)]);
        }
        if (($scope['role_type'] ?? '') === 'student') {
            self::whereInOrDeny($students, 'student_id', [(int) ($scope['student_id'] ?? 0)]);
        }

        $planQuery = self::planQuery();
        self::applyPlanScope($planQuery, $scope, 'sp_plan');
        $projectQuery = self::projectQuery();
        self::applyEntityScope($projectQuery, $scope, 'project');

        return [
            'grades' => $grades->orderByDesc('is_current')->orderBy('sort')->get(['grade_id', 'grade_name', 'is_current'])->map(fn ($row) => $row->getAttributes())->all(),
            'departments' => $departments->orderBy('sort')->get(['dep_id', 'dep_name', 'dep_short_name'])->map(fn ($row) => $row->getAttributes())->all(),
            'professions' => $professions->orderBy('sort')->get(['profession_id', 'profession_name', 'profession_short_name', 'dep_id', 'grade_id'])->map(fn ($row) => $row->getAttributes())->all(),
            'classes' => $classes->orderBy('sort')->get(['class_id', 'class_name', 'class_short_name', 'dep_id', 'profession_id', 'grade_id'])->map(fn ($row) => $row->getAttributes())->all(),
            'teachers' => $teachers->orderBy('teacher_name')->get(['teacher_id', 'teacher_name', 'teacher_num', 'dep_id', 'profession_id'])->map(fn ($row) => $row->getAttributes())->all(),
            'students' => $students->orderBy('student_num')->limit(1000)->get(['student_id', 'name', 'student_num', 'grade_id', 'dep_id', 'profession_id', 'class_id'])->map(fn ($row) => $row->getAttributes())->all(),
            'plans' => $planQuery->orderByDesc('sp_plan.id')->get([
                'sp_plan.id', 'sp_plan.uuid', 'sp_plan.title', 'sp_plan.grade_id', 'sp_plan.organizer_dep_id',
                'sp_plan.participation_mode', 'sp_plan.teacher_match_mode', 'sp_plan.teacher_confirm_hours',
                'sp_plan.max_reselect_count', 'sp_plan.default_team_submit_mode', 'sp_plan.register_start_at',
                'sp_plan.register_end_at', 'sp_plan.practice_start_at', 'sp_plan.practice_end_at',
                'sp_plan.result_deadline_at', 'sp_plan.score_deadline_at', 'sp_plan.phase', 'sp_plan.status',
            ])->map(fn ($row) => $row->getAttributes())->all(),
            'projects' => $projectQuery->orderByDesc('sp_project.id')->get(['sp_project.id', 'sp_project.uuid', 'sp_project.plan_id', 'sp_project.title', 'sp_project.practice_mode', 'sp_project.capacity', 'sp_project.phase', 'sp_project.status'])->map(fn ($row) => $row->getAttributes())->all(),
            'approval_flows' => self::approvalFlowRows($scope),
            'current_grade_id' => self::currentGradeId(),
            'current_student_id' => (int) ($scope['student_id'] ?? 0) ?: null,
            'default_dep_id' => count($scope['dep_ids'] ?? []) === 1
                ? (int) $scope['dep_ids'][0]
                : (count($scope['profession_dep_ids'] ?? []) === 1 ? (int) $scope['profession_dep_ids'][0] : null),
            'default_profession_id' => count($scope['profession_ids'] ?? []) === 1 ? (int) $scope['profession_ids'][0] : null,
        ];
    }

    /** 按资源返回分页列表。 */
    public static function resourcePage(array $scope, string $resource, array $filters): array
    {
        return match ($resource) {
            'plan' => self::planPage($scope, $filters),
            'project' => self::projectPage($scope, $filters),
            'implementation' => self::implementationPage($scope, $filters),
            'declaration' => self::declarationPage($scope, $filters),
            'participant' => self::participantPage($scope, $filters),
            'teacher' => self::teacherPage($scope, $filters),
            'safety', 'material' => self::materialPage($scope, $resource, $filters),
            'attendance' => self::attendancePage($scope, $filters),
            'patch_sign' => self::patchSignPage($scope, $filters),
            'score' => self::scorePage($scope, $filters),
            'archive' => self::socialArchivePage($scope, $filters),
            default => self::emptyPage($filters),
        };
    }

    /** 读取单个资源及其关联数据。 */
    public static function resourceDetail(array $scope, string $resource, int $id): ?array
    {
        $row = self::visibleEntityRow($scope, $resource, $id);
        if (!$row) {
            return null;
        }

        if ($resource === 'plan') {
            $row['scopes'] = self::planScopeRows($id);
            $row['requirements'] = self::requirementRows($id);
            $row['score_rules'] = self::scoreRuleRows($id);
            $row['projects'] = self::projectRowsByPlan($scope, $id);
        }
        if ($resource === 'project') {
            $row['teachers'] = self::projectTeacherRows($id);
            $row['participants'] = self::projectParticipantRows($scope, $id);
            $row['requirements'] = self::requirementRows((int) $row['plan_id'], (string) $row['practice_mode']);
        }
        if ($resource === 'declaration') {
            $row['members'] = self::declarationMemberRows($id);
        }
        if ($resource === 'score') {
            $row['details'] = self::scoreDetailRows($id);
            $row['rules'] = self::scoreRuleRows((int) ($row['plan_id'] ?? 0), (string) ($row['practice_mode'] ?? ''));
        }
        if (in_array($resource, ['implementation', 'material', 'patch_sign', 'score'], true)) {
            $row['timeline'] = self::recordingRows(self::entityType($resource), $id);
        }

        return $row;
    }

    /** 锁定工作流实体。 */
    public static function lockEntity(string $entity, int $id): ?object
    {
        $table = self::entityTable($entity);
        return self::queryTable($table)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
    }

    /** 保存工作流实体。 */
    public static function saveEntity(string $entity, ?int $id, array $values): int
    {
        $table = self::entityTable($entity);
        if ($id) {
            self::queryTable($table)->where('id', $id)->whereNull('deleted_at')->update($values);
            return $id;
        }

        return (int) self::queryTable($table)->insertGetId(array_merge([
            'uuid' => self::uuid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'deleted_at' => null,
        ], $values));
    }

    /** 读取实体 UUID。 */
    public static function entityUuid(string $entity, int $id): ?string
    {
        $uuid = self::queryTable(self::entityTable($entity))->where('id', $id)->value('uuid');
        return $uuid ? (string) $uuid : null;
    }

    /** 同步计划适用范围。 */
    public static function syncPlanScopes(int $planId, array $scopes, string $now): void
    {
        self::queryTable('social_practice_plan_scope')
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->update(['status' => 'disabled', 'deleted_at' => $now, 'updated_at' => $now]);

        foreach ($scopes as $scope) {
            self::queryTable('social_practice_plan_scope')->insert([
                'uuid' => self::uuid(),
                'plan_id' => $planId,
                'scope_type' => $scope['scope_type'],
                'dep_id' => $scope['dep_id'],
                'profession_id' => $scope['profession_id'],
                'class_id' => $scope['class_id'],
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }
    }

    /** 同步计划要求。 */
    public static function syncRequirements(int $planId, array $items, string $now): void
    {
        self::queryTable('social_practice_requirement')
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->update(['status' => 'disabled', 'deleted_at' => $now, 'updated_at' => $now]);

        foreach ($items as $item) {
            self::queryTable('social_practice_requirement')->insert(array_merge([
                'uuid' => self::uuid(),
                'plan_id' => $planId,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ], $item));
        }
    }

    /** 同步计划成绩规则。 */
    public static function syncScoreRules(int $planId, array $items, string $now): void
    {
        self::queryTable('social_practice_score_rule')
            ->where('plan_id', $planId)
            ->whereNull('deleted_at')
            ->update(['status' => 'disabled', 'deleted_at' => $now, 'updated_at' => $now]);

        foreach ($items as $item) {
            self::queryTable('social_practice_score_rule')->insert(array_merge([
                'uuid' => self::uuid(),
                'plan_id' => $planId,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ], $item));
        }
    }

    /** 同步项目教师。 */
    public static function syncProjectTeachers(int $projectId, array $teachers, string $now): void
    {
        self::queryTable('social_practice_project_teacher')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->update(['status' => 'removed', 'deleted_at' => $now, 'updated_at' => $now]);

        foreach ($teachers as $teacher) {
            self::queryTable('social_practice_project_teacher')->insert([
                'uuid' => self::uuid(),
                'project_id' => $projectId,
                'teacher_id' => $teacher['teacher_id'],
                'teacher_role' => $teacher['teacher_role'],
                'capacity' => $teacher['capacity'],
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }
    }

    /** 同步项目学生及师生关系。 */
    public static function syncProjectParticipants(int $projectId, int $planId, string $practiceMode, array $participants, string $now): void
    {
        $existing = self::queryTable('social_practice_participant')
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get(['id', 'student_id'])
            ->keyBy('student_id');
        $studentIds = array_map(static fn (array $item): int => (int) $item['student_id'], $participants);

        foreach ($existing as $studentId => $row) {
            if (!in_array((int) $studentId, $studentIds, true)) {
                self::queryTable('social_practice_participant')->where('id', $row->id)->update([
                    'status' => 'removed',
                    'remove_reason' => '管理员调整项目学生',
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);
                self::queryTable('pair')
                    ->where('student_id', (int) $studentId)
                    ->where('type', 'social_practice')
                    ->where('entity_type', 'social_practice_project')
                    ->where('entity_id', $projectId)
                    ->whereNull('deleted_at')
                    ->update(['status' => 'removed', 'deleted_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach ($participants as $participant) {
            $studentId = (int) $participant['student_id'];
            $teacherId = (int) $participant['teacher_id'];
            $row = self::queryTable('social_practice_participant')
                ->where('plan_id', $planId)
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'project_id']);
            if ($row && (int) ($row->project_id ?? 0) > 0 && (int) $row->project_id !== $projectId) {
                throw new \RuntimeException('学生已参加当前计划的其他项目', 409);
            }

            if ($row) {
                self::queryTable('social_practice_participant')->where('id', $row->id)->update([
                    'project_id' => $projectId,
                    'teacher_id' => $teacherId,
                    'practice_mode' => $practiceMode,
                    'updated_at' => $now,
                ]);
            } else {
                self::queryTable('social_practice_participant')->insert([
                    'uuid' => self::uuid(),
                    'plan_id' => $planId,
                    'project_id' => $projectId,
                    'student_id' => $studentId,
                    'teacher_id' => $teacherId,
                    'practice_mode' => $practiceMode,
                    'join_source' => 'admin_assign',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }

            self::upsertPair($projectId, $studentId, $teacherId, $now);
        }
    }

    /** 同步全员参与计划的待分配名单。 */
    public static function syncMandatoryPlanParticipants(int $planId, string $now): int
    {
        $plan = self::queryTable('social_practice_plan')
            ->where('id', $planId)
            ->where('participation_mode', 'mandatory')
            ->whereNull('deleted_at')
            ->first(['id', 'grade_id']);
        if (!$plan) {
            return 0;
        }
        $scopes = self::planScopeRows($planId);
        if (!$scopes) {
            return 0;
        }
        $studentIds = self::queryTable('students')
            ->where('grade_id', (int) $plan->grade_id)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->get(['student_id', 'dep_id', 'profession_id', 'class_id'])
            ->filter(fn ($student): bool => self::studentMatchesPlanScopes($student, $scopes))
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $stale = self::queryTable('social_practice_participant')
            ->where('plan_id', $planId)
            ->where('join_source', 'scope')
            ->whereNull('project_id')
            ->where('status', 'active')
            ->whereNull('deleted_at');
        if ($studentIds) {
            $stale->whereNotIn('student_id', $studentIds);
        }
        $stale->update(['status' => 'removed', 'remove_reason' => '计划适用范围调整', 'updated_at' => $now, 'deleted_at' => $now]);

        $existingIds = self::queryTable('social_practice_participant')
            ->where('plan_id', $planId)
            ->whereIn('student_id', $studentIds ?: [0])
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $created = 0;
        foreach (array_diff($studentIds, $existingIds) as $studentId) {
            self::queryTable('social_practice_participant')->insert([
                'uuid' => self::uuid(),
                'plan_id' => $planId,
                'project_id' => null,
                'student_id' => $studentId,
                'teacher_id' => null,
                'practice_mode' => 'pending',
                'join_source' => 'scope',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            $created++;
        }
        return $created;
    }

    /** 更新团队成员确认状态。 */
    public static function confirmDeclarationMember(int $declarationId, int $studentId, string $status, string $now): int
    {
        return self::queryTable('social_practice_declaration_member')
            ->where('declaration_id', $declarationId)
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->where('confirm_status', 'pending')
            ->whereNull('deleted_at')
            ->update(['confirm_status' => $status, 'confirmed_at' => $now, 'updated_at' => $now]);
    }

    /** 更新教师容量确认状态。 */
    public static function confirmDeclarationTeacher(int $declarationId, int $teacherId, string $status, string $now): int
    {
        return self::queryTable('social_practice_declaration')
            ->where('id', $declarationId)
            ->where(function ($query) use ($teacherId): void {
                $query->where('selected_teacher_id', $teacherId)->orWhere('assigned_teacher_id', $teacherId);
            })
            ->where('teacher_confirm_status', 'pending')
            ->whereNull('deleted_at')
            ->update(['teacher_confirm_status' => $status, 'updated_at' => $now]);
    }

    /** 读取当前组织范围内的启用教师。 */
    public static function teacherOptionRow(array $scope, int $teacherId): ?array
    {
        $query = self::queryTable('teacher_list')->where('teacher_id', $teacherId)->where('status', 'enabled')->whereNull('deleted_at');
        self::applyOptionScope($query, $scope, 'dep_id', 'profession_id');
        $row = $query->first(['teacher_id', 'teacher_name', 'teacher_num', 'dep_id', 'profession_id']);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取启用年级。 */
    public static function gradeOptionRow(int $gradeId): ?array
    {
        $row = self::queryTable('grade_list')
            ->where('grade_id', $gradeId)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['grade_id', 'grade_name', 'is_current']);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取启用学院。 */
    public static function departmentOptionRow(int $depId): ?array
    {
        $row = self::queryTable('department')
            ->where('dep_id', $depId)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['dep_id', 'dep_name']);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取启用专业及其层级。 */
    public static function professionOptionRow(int $professionId): ?array
    {
        $row = self::queryTable('profession')
            ->where('profession_id', $professionId)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['profession_id', 'profession_name', 'dep_id', 'grade_id']);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取启用班级及其层级。 */
    public static function classOptionRow(int $classId): ?array
    {
        $row = self::queryTable('class')
            ->where('class_id', $classId)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['class_id', 'class_name', 'dep_id', 'profession_id', 'grade_id']);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取当前权限范围内的审批流。 */
    public static function approvalFlowOptionRow(array $scope, int $flowId): ?array
    {
        foreach (self::approvalFlowRows($scope) as $flow) {
            if ((int) ($flow['id'] ?? 0) === $flowId) {
                return $flow;
            }
        }
        return null;
    }

    /** 按名称读取社会实践计划导入所需的学业层级。 */
    public static function academicOptionByNames(string $gradeName, string $departmentName, string $professionName = '', string $className = ''): ?array
    {
        $grade = self::queryTable('grade_list')
            ->where('grade_name', trim($gradeName))
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['grade_id', 'grade_name']);
        if (!$grade) {
            return null;
        }

        $department = self::queryTable('department')
            ->where('dep_name', trim($departmentName))
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['dep_id', 'dep_name']);
        if (!$department) {
            return null;
        }

        $profession = null;
        if (trim($professionName) !== '') {
            $profession = self::queryTable('profession')
                ->where('profession_name', trim($professionName))
                ->where('dep_id', (int) $department->dep_id)
                ->where(function ($query) use ($grade): void {
                    $query->whereNull('grade_id')->orWhere('grade_id', (int) $grade->grade_id);
                })
                ->where('flag', 'on')
                ->whereNull('deleted_at')
                ->first(['profession_id', 'profession_name', 'dep_id', 'grade_id']);
            if (!$profession) {
                return null;
            }
        }

        $class = null;
        if (trim($className) !== '') {
            if (!$profession) {
                return null;
            }
            $class = self::queryTable('class')
                ->where('class_name', trim($className))
                ->where('dep_id', (int) $department->dep_id)
                ->where('profession_id', (int) $profession->profession_id)
                ->where(function ($query) use ($grade): void {
                    $query->whereNull('grade_id')->orWhere('grade_id', (int) $grade->grade_id);
                })
                ->where('flag', 'on')
                ->whereNull('deleted_at')
                ->first(['class_id', 'class_name', 'dep_id', 'profession_id', 'grade_id']);
            if (!$class) {
                return null;
            }
        }

        return [
            'grade' => $grade->getAttributes(),
            'department' => $department->getAttributes(),
            'profession' => $profession?->getAttributes(),
            'class' => $class?->getAttributes(),
        ];
    }

    /** 读取默认启用的社会实践审批流程。 */
    public static function defaultApprovalFlowId(): ?int
    {
        $id = self::queryTable('social_practice_approval_flow')
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderByDesc('version')
            ->orderBy('id')
            ->value('id');
        return is_numeric($id) ? (int) $id : null;
    }

    /** 按教务来源编号读取社会实践计划。 */
    public static function importedPlanBySourceKey(string $sourceType, string $sourceKey, bool $lock = false): ?object
    {
        $query = self::queryTable('social_practice_plan')
            ->where('source_type', $sourceType)
            ->where('source_key', $sourceKey)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->first();
    }

    /** 按导入数据创建或更新可编辑的社会实践计划草稿。 */
    public static function upsertImportedPlan(array $values, array $scopes, array $requirements, array $scoreRules, string $now): array
    {
        return self::connection()->transaction(function () use ($values, $scopes, $requirements, $scoreRules, $now): array {
            $sourceType = (string) ($values['source_type'] ?? 'edu_system');
            $sourceKey = trim((string) ($values['source_key'] ?? ''));
            $existing = self::importedPlanBySourceKey($sourceType, $sourceKey, true);
            if ($existing && !in_array((string) $existing->status, ['draft', 'modify'], true)) {
                return [
                    'result' => 'skipped',
                    'id' => (int) $existing->id,
                    'message' => '计划已提交或发布，禁止覆盖',
                ];
            }

            $planValues = array_merge([
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'status' => $existing ? (string) $existing->status : 'draft',
                'phase' => $existing ? (string) $existing->phase : 'ready',
                'updated_at' => $now,
                'deleted_at' => null,
            ], $values);
            unset($planValues['id'], $planValues['created_at']);
            if ($existing) {
                self::queryTable('social_practice_plan')->where('id', (int) $existing->id)->update($planValues);
                $planId = (int) $existing->id;
                $result = 'updated';
            } else {
                $planId = self::saveEntity('plan', null, array_merge($planValues, ['created_at' => $now]));
                $result = 'created';
            }

            self::syncPlanScopes($planId, $scopes, $now);
            self::syncRequirements($planId, $requirements, $now);
            self::syncScoreRules($planId, $scoreRules, $now);

            return ['result' => $result, 'id' => $planId, 'message' => $result === 'created' ? '已创建计划草稿' : '已更新计划草稿'];
        });
    }

    /** 锁定教师档案。 */
    public static function lockTeacher(int $teacherId): ?object
    {
        return self::queryTable('teacher_list')
            ->where('teacher_id', $teacherId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['teacher_id']);
    }

    /** 更新分散实践申报的管理员分配教师。 */
    public static function assignDeclarationTeacher(int $declarationId, int $teacherId, string $deadline, string $now): int
    {
        return self::queryTable('social_practice_declaration')
            ->where('id', $declarationId)
            ->whereNull('deleted_at')
            ->update([
                'assigned_teacher_id' => $teacherId,
                'teacher_confirm_deadline_at' => $deadline,
                'teacher_confirm_status' => 'pending',
                'updated_at' => $now,
            ]);
    }

    /** 读取指定教师已占用容量。 */
    public static function teacherAssignedCount(int $teacherId, ?int $excludeDeclarationId = null): int
    {
        $query = self::queryTable('social_practice_participant')
            ->where('teacher_id', $teacherId)
            ->where('status', 'active')
            ->whereNull('deleted_at');
        if ($excludeDeclarationId) {
            $projectId = self::queryTable('social_practice_declaration')->where('id', $excludeDeclarationId)->value('accepted_project_id');
            if ($projectId) {
                $query->where('project_id', '<>', (int) $projectId);
            }
        }
        $assigned = (int) $query->count();
        $reservedQuery = self::queryTable('social_practice_declaration')
            ->where(function ($builder) use ($teacherId): void {
                $builder->where('selected_teacher_id', $teacherId)->orWhere('assigned_teacher_id', $teacherId);
            })
            ->where('teacher_confirm_status', 'accepted')
            ->whereNull('accepted_project_id')
            ->whereIn('status', ['wait', 'modify'])
            ->whereNull('deleted_at');
        if ($excludeDeclarationId) {
            $reservedQuery->where('id', '<>', $excludeDeclarationId);
        }
        $reserved = 0;
        foreach ($reservedQuery->pluck('id')->map(fn ($id) => (int) $id)->all() as $declarationId) {
            $reserved += self::declarationMemberCount($declarationId);
        }
        return $assigned + $reserved;
    }

    /** 更新学生重新选择的指导教师。 */
    public static function reselectDeclarationTeacher(int $declarationId, int $teacherId, int $reselectCount, string $deadline, string $now): int
    {
        return self::queryTable('social_practice_declaration')
            ->where('id', $declarationId)
            ->where('status', 'wait')
            ->whereNull('deleted_at')
            ->update([
                'selected_teacher_id' => $teacherId,
                'assigned_teacher_id' => null,
                'teacher_confirm_status' => 'pending',
                'teacher_confirm_deadline_at' => $deadline,
                'teacher_reselect_count' => $reselectCount,
                'updated_at' => $now,
            ]);
    }

    /** 读取申报团队人数。 */
    public static function declarationMemberCount(int $declarationId): int
    {
        $members = (int) self::queryTable('social_practice_declaration_member')
            ->where('declaration_id', $declarationId)
            ->where('status', 'active')
            ->where('confirm_status', 'accepted')
            ->whereNull('deleted_at')
            ->count();
        return max(1, $members);
    }

    /** 读取教师在项目中的容量。 */
    public static function teacherCapacity(int $teacherId, ?int $projectId = null): int
    {
        $query = self::queryTable('social_practice_project_teacher')
            ->where('teacher_id', $teacherId)
            ->where('status', 'active')
            ->whereNull('deleted_at');
        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        $capacity = (int) ($query->max('capacity') ?: 0);
        return $capacity > 0 ? $capacity : 20;
    }

    /** 将已通过申报转为项目和参与记录。 */
    public static function activateDeclaration(object $declaration, int $operatorId, string $now): int
    {
        $projectId = (int) ($declaration->accepted_project_id ?? 0);
        if ($projectId <= 0) {
            $projectId = self::saveEntity('project', null, [
                'name' => (string) ($declaration->title ?? '分散实践项目'),
                'code' => 'SP-D-' . date('YmdHis') . '-' . (int) $declaration->id,
                'plan_id' => (int) $declaration->plan_id,
                'practice_mode' => 'distributed',
                'source_type' => 'student_declared',
                'source_declaration_id' => (int) $declaration->id,
                'project_code' => 'SP-D-' . (int) $declaration->id,
                'title' => (string) $declaration->title,
                'content' => (string) $declaration->content,
                'objective' => (string) $declaration->objective,
                'location' => (string) $declaration->location,
                'capacity' => self::declarationMemberCount((int) $declaration->id),
                'phase' => 'ready',
                'created_by' => $operatorId,
                'status' => 'enabled',
                'updated_at' => $now,
            ]);
            self::queryTable('social_practice_declaration')->where('id', $declaration->id)->update([
                'accepted_project_id' => $projectId,
                'updated_at' => $now,
            ]);
        }

        $teacherId = (int) (($declaration->assigned_teacher_id ?? 0) ?: ($declaration->selected_teacher_id ?? 0));
        self::syncProjectTeachers($projectId, [[
            'teacher_id' => $teacherId,
            'teacher_role' => 'guide',
            'capacity' => self::teacherCapacity($teacherId),
        ]], $now);

        $studentIds = [(int) $declaration->applicant_student_id];
        foreach (self::declarationMemberRows((int) $declaration->id) as $member) {
            if (($member['confirm_status'] ?? '') === 'accepted') {
                $studentIds[] = (int) $member['student_id'];
            }
        }
        $participants = array_map(static fn (int $studentId): array => [
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
        ], array_values(array_unique(array_filter($studentIds))));
        self::syncProjectParticipants($projectId, (int) $declaration->plan_id, 'distributed', $participants, $now);

        return $projectId;
    }

    /** 保存社会实践签到。 */
    public static function insertSignIn(array $values): int
    {
        return (int) self::queryTable('sign_in')->insertGetId($values);
    }

    /** 通过补签申请生成签到记录。 */
    public static function createPatchSignIn(int $patchSignId, string $now): ?int
    {
        $patch = self::queryTable('social_practice_patch_sign')->where('id', $patchSignId)->whereNull('deleted_at')->first();
        if (!$patch) {
            return null;
        }
        if ((int) ($patch->sign_in_id ?? 0) > 0) {
            return (int) $patch->sign_in_id;
        }
        $participant = self::activeParticipant((int) $patch->project_id, (int) $patch->student_id);
        if (!$participant) {
            throw new \RuntimeException('当前学生不是项目参与人', 42201);
        }
        $existing = self::signInRow((int) $patch->project_id, (int) $patch->student_id, (string) $patch->sign_date);
        $signInId = $existing ? (int) $existing['id'] : self::insertSignIn([
            'uuid' => self::uuid(),
            'entity_type' => 'social_practice',
            'entity_id' => (int) $patch->project_id,
            'student_id' => (int) $patch->student_id,
            'teacher_id' => (int) ($participant['teacher_id'] ?? 0) ?: null,
            'date' => (string) $patch->sign_date,
            'sign_time' => $now,
            'longitude' => null,
            'latitude' => null,
            'location' => '补签：' . trim((string) $patch->reason),
            'sign_type' => 'patch',
            'remark' => trim((string) $patch->proof),
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
        self::queryTable('social_practice_patch_sign')->where('id', $patchSignId)->update([
            'sign_in_id' => $signInId,
            'updated_at' => $now,
        ]);
        return $signInId;
    }

    /** 写入签到流程记录。 */
    public static function insertActivityRecording(string $entityType, int $entityId, ?int $operatorId, string $action, string $content, string $now): int
    {
        return self::insertRecording([
            'uuid' => self::uuid(),
            'parent_id' => $entityId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operator_id' => $operatorId,
            'from_status' => null,
            'to_status' => 'enabled',
            'action' => $action,
            'opinion' => $content,
            'content' => $content,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** 读取成绩明细。 */
    public static function scoreDetailRows(int $scoreId): array
    {
        return self::queryTable('social_practice_score_detail')
            ->where('score_id', $scoreId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $row->getAttributes())
            ->all();
    }

    /** 保存成绩明细。 */
    public static function syncScoreDetails(int $scoreId, array $items, string $now): void
    {
        $existing = self::queryTable('social_practice_score_detail')
            ->where('score_id', $scoreId)
            ->get(['id', 'item_code', 'deleted_at'])
            ->keyBy('item_code');
        $itemCodes = [];

        foreach ($items as $item) {
            $itemCode = (string) ($item['item_code'] ?? '');
            $itemCodes[] = $itemCode;
            $values = array_merge([
                'status' => 'enabled',
                'updated_at' => $now,
                'deleted_at' => null,
            ], $item);
            $row = $existing->get($itemCode);
            if ($row) {
                self::queryTable('social_practice_score_detail')->where('id', $row->id)->update($values);
                continue;
            }

            self::queryTable('social_practice_score_detail')->insert(array_merge([
                'uuid' => self::uuid(),
                'score_id' => $scoreId,
                'created_at' => $now,
            ], $values));
        }

        $staleIds = $existing
            ->filter(static fn ($row, string $code): bool => $row->deleted_at === null && !in_array($code, $itemCodes, true))
            ->pluck('id')
            ->all();
        if ($staleIds) {
            self::queryTable('social_practice_score_detail')->whereIn('id', $staleIds)->update([
                'status' => 'disabled',
                'updated_at' => $now,
                'deleted_at' => $now,
            ]);
        }
    }

    /** 读取计划成绩规则。 */
    public static function scoreRuleRows(int $planId, ?string $practiceMode = null): array
    {
        $query = self::queryTable('social_practice_score_rule')
            ->where('plan_id', $planId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at');
        if ($practiceMode) {
            $query->where('practice_mode', $practiceMode);
        }
        return $query->orderBy('practice_mode')->orderBy('sort')->get()->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 检查学生是否为项目有效参与人。 */
    public static function activeParticipant(int $projectId, int $studentId): ?array
    {
        $row = self::queryTable('social_practice_participant')
            ->where('project_id', $projectId)
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 检查项目必交材料是否完整。 */
    public static function missingRequiredMaterials(int $planId, int $projectId, int $studentId, string $practiceMode, bool $acceptedOnly = false): array
    {
        $requirements = self::requirementRows($planId, $practiceMode, true);
        $submitted = self::queryTable('social_practice_material')
            ->where('plan_id', $planId)
            ->where('project_id', $projectId)
            ->where(function ($query) use ($studentId): void {
                $query->where('student_id', $studentId)->orWhereNull('student_id');
            })
            ->whereIn('status', $acceptedOnly ? ['accept'] : ['wait', 'accept'])
            ->whereNull('deleted_at')
            ->pluck('material_type')
            ->all();

        $missing = [];
        foreach ($requirements as $requirement) {
            if (($requirement['required_flag'] ?? 'false') === 'true' && !in_array($requirement['requirement_type'], $submitted, true)) {
                $missing[] = (string) $requirement['requirement_type'];
            }
        }
        return $missing;
    }

    /** 生成归档快照数据。 */
    public static function archiveSnapshot(array $scope, int $planId, ?int $projectId, ?int $studentId): array
    {
        $plan = self::visibleEntityRow($scope, 'plan', $planId);
        if (!$plan) {
            return [];
        }
        $project = $projectId ? self::visibleEntityRow($scope, 'project', $projectId) : null;
        $materials = self::materialSnapshotRows($planId, $projectId, $studentId);
        $scores = self::scoreSnapshotRows($planId, $projectId, $studentId);
        $recordings = self::archiveRecordingRows($planId, $projectId, $studentId);

        return compact('plan', 'project', 'materials', 'scores', 'recordings');
    }

    /** 读取归档的下一版本号。 */
    public static function nextArchiveVersion(int $planId, ?int $projectId, ?int $studentId): int
    {
        $query = self::queryTable('social_practice_archive')->where('plan_id', $planId);
        self::whereNullable($query, 'project_id', $projectId);
        self::whereNullable($query, 'student_id', $studentId);
        return (int) ($query->max('version') ?: 0) + 1;
    }

    /** 写入流程记录。 */
    public static function insertRecording(array $values): int
    {
        return (int) self::queryTable('social_practice_recording')->insertGetId($values);
    }

    /** 写入审核意见。 */
    public static function insertReviewOpinion(array $values): int
    {
        return (int) self::queryTable('review_opinion')->insertGetId($values);
    }

    /** 读取流程记录。 */
    public static function recordingRows(string $entityType, int $entityId): array
    {
        return self::queryTable('social_practice_recording as recording')
            ->leftJoin('account', 'recording.operator_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->where('recording.entity_type', $entityType)
            ->where('recording.entity_id', $entityId)
            ->whereNull('recording.deleted_at')
            ->orderBy('recording.created_at')
            ->orderBy('recording.id')
            ->get([
                'recording.id', 'recording.uuid', 'recording.parent_id', 'recording.entity_type', 'recording.entity_id',
                'recording.action', 'recording.operator_id', 'recording.from_status', 'recording.to_status',
                'recording.opinion', 'recording.content', 'recording.status', 'recording.created_at',
                'users.name as operator_name', 'account.login_name as operator_login',
            ])->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 读取最近一次提交账号。 */
    public static function latestSubmitterAccountId(string $entityType, int $entityId): ?int
    {
        $accountId = self::queryTable('social_practice_recording')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('action', 'submit')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->value('operator_id');
        return is_numeric($accountId) && (int) $accountId > 0 ? (int) $accountId : null;
    }

    /** 读取流程审核意见。 */
    public static function reviewOpinionRows(string $entityType, int $entityId): array
    {
        return self::queryTable('review_opinion as review')
            ->leftJoin('account', 'review.reviewer_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->where('review.entity_type', $entityType)
            ->where('review.entity_id', $entityId)
            ->whereNull('review.deleted_at')
            ->orderBy('review.created_at')
            ->orderBy('review.id')
            ->get([
                'review.id', 'review.recording_id', 'review.reviewer_id', 'review.opinion', 'review.score',
                'review.status', 'review.created_at', 'users.name as reviewer_name', 'account.login_name as reviewer_login',
            ])->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 删除当前审核草稿。 */
    public static function clearReviewDraft(string $entityType, int $entityId, int $reviewerId, string $now): int
    {
        return self::clearReviewOpinionDraft($entityType, $entityId, $reviewerId, $now);
    }

    /** 读取当前用户对应的教师 ID。 */
    public static function teacherIdByUser(int $userId): ?int
    {
        $id = self::queryTable('teacher_list')->where('user_id', $userId)->where('status', 'enabled')->whereNull('deleted_at')->value('teacher_id');
        return is_numeric($id) ? (int) $id : null;
    }

    /** 读取当前用户对应的学生 ID。 */
    public static function studentIdByUser(int $userId): ?int
    {
        $id = self::queryTable('students')->where('user_id', $userId)->where('status', 'enabled')->whereNull('deleted_at')->value('student_id');
        return is_numeric($id) ? (int) $id : null;
    }

    /** 读取教师对应的账号 ID。 */
    public static function teacherAccountIds(int $teacherId): array
    {
        $userId = self::queryTable('teacher_list')->where('teacher_id', $teacherId)->whereNull('deleted_at')->value('user_id');
        return $userId ? Account::enabledIdsByUserIds([(int) $userId]) : [];
    }

    /** 读取学生对应的账号 ID。 */
    public static function studentAccountIds(int $studentId): array
    {
        $userId = self::queryTable('students')->where('student_id', $studentId)->whereNull('deleted_at')->value('user_id');
        return $userId ? Account::enabledIdsByUserIds([(int) $userId]) : [];
    }

    /** 读取实体提交人账号。 */
    public static function submitterAccountIds(string $entity, object $row): array
    {
        $accountId = (int) (($row->submitter_id ?? 0) ?: ($row->applicant_id ?? 0));
        if ($accountId > 0) {
            return [$accountId];
        }
        $studentId = (int) (($row->applicant_student_id ?? 0) ?: ($row->student_id ?? 0));
        return $studentId > 0 ? self::studentAccountIds($studentId) : [];
    }

    /** 读取有权限处理工作流的管理员账号。 */
    public static function workflowAdminAccountIds(): array
    {
        $ids = [];
        foreach (['school_admin', 'college_admin', 'profession_admin'] as $roleType) {
            $ids = array_merge($ids, Account::messageTargetIds(['role_type' => $roleType]));
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** 读取计划范围内学生账号。 */
    public static function planStudentAccountIds(int $planId): array
    {
        $plan = self::queryTable('social_practice_plan')->where('id', $planId)->whereNull('deleted_at')->first(['grade_id']);
        if (!$plan) {
            return [];
        }
        $query = self::queryTable('students')->where('grade_id', (int) $plan->grade_id)->where('status', 'enabled')->whereNull('deleted_at');
        $scopes = self::planScopeRows($planId);
        $hasSchoolScope = false;
        foreach ($scopes as $scopeRow) {
            if (($scopeRow['scope_type'] ?? '') === 'school') {
                $hasSchoolScope = true;
                break;
            }
        }
        if ($scopes && !$hasSchoolScope) {
            $query->where(function ($builder) use ($scopes): void {
                foreach ($scopes as $scope) {
                    $builder->orWhere(function ($item) use ($scope): void {
                        if (!empty($scope['dep_id'])) {
                            $item->where('dep_id', (int) $scope['dep_id']);
                        }
                        if (!empty($scope['profession_id'])) {
                            $item->where('profession_id', (int) $scope['profession_id']);
                        }
                        if (!empty($scope['class_id'])) {
                            $item->where('class_id', (int) $scope['class_id']);
                        }
                    });
                }
            });
        }
        $userIds = $query->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all();
        return Account::enabledIdsByUserIds($userIds);
    }

    /** 检查写入的组织范围。 */
    public static function valuesVisible(array $scope, array $values): bool
    {
        $role = (string) ($scope['role_type'] ?? '');
        if (in_array($role, ['super_admin', 'school_admin'], true)) {
            return true;
        }
        if ($role === 'college_admin') {
            return in_array((int) ($values['organizer_dep_id'] ?? $values['dep_id'] ?? 0), $scope['dep_ids'] ?? [], true);
        }
        if ($role === 'profession_admin') {
            $professionId = (int) ($values['profession_id'] ?? 0);
            return $professionId === 0 || in_array($professionId, $scope['profession_ids'] ?? [], true);
        }
        return in_array($role, ['teacher', 'student'], true);
    }

    /** 读取指定实体的状态。 */
    public static function entityStatus(string $entity, int $id): string
    {
        return (string) (self::queryTable(self::entityTable($entity))->where('id', $id)->value('status') ?: 'draft');
    }

    /** 读取计划的范围记录。 */
    public static function planScopeRows(int $planId): array
    {
        return self::queryTable('social_practice_plan_scope')
            ->where('plan_id', $planId)->where('status', 'enabled')->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'scope_type', 'dep_id', 'profession_id', 'class_id'])
            ->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 读取计划要求。 */
    public static function requirementRows(int $planId, ?string $practiceMode = null, bool $requiredOnly = false): array
    {
        $query = self::queryTable('social_practice_requirement')
            ->where('plan_id', $planId)->where('status', 'enabled')->whereNull('deleted_at');
        if ($practiceMode) {
            $query->whereIn('practice_mode', ['all', $practiceMode]);
        }
        if ($requiredOnly) {
            $query->where('required_flag', 'true');
        }
        return $query->orderBy('id')->get()->map(fn ($row) => self::decodeJsonFields($row->getAttributes(), ['config_json']))->all();
    }

    /** 读取项目教师。 */
    public static function projectTeacherRows(int $projectId): array
    {
        return self::queryTable('social_practice_project_teacher as relation')
            ->join('teacher_list as teacher', 'relation.teacher_id', '=', 'teacher.teacher_id')
            ->where('relation.project_id', $projectId)->where('relation.status', 'active')->whereNull('relation.deleted_at')
            ->orderByRaw("FIELD(relation.teacher_role, 'leader', 'guide')")->orderBy('teacher.teacher_name')
            ->get(['relation.id', 'relation.teacher_id', 'relation.teacher_role', 'relation.capacity', 'teacher.teacher_name', 'teacher.teacher_num'])
            ->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 读取项目学生。 */
    public static function projectParticipantRows(array $scope, int $projectId): array
    {
        $query = self::participantQuery()->where('participant.project_id', $projectId);
        self::applyEntityScope($query, $scope, 'participant');
        return $query->orderBy('student.student_num')->get(self::participantColumns())->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 读取申报团队成员。 */
    public static function declarationMemberRows(int $declarationId): array
    {
        return self::queryTable('social_practice_declaration_member as member')
            ->join('students as student', 'member.student_id', '=', 'student.student_id')
            ->where('member.declaration_id', $declarationId)->where('member.status', 'active')->whereNull('member.deleted_at')
            ->orderByRaw("FIELD(member.member_role, 'leader', 'member')")->orderBy('student.student_num')
            ->get(['member.id', 'member.student_id', 'member.member_role', 'member.confirm_status', 'member.confirmed_at', 'student.name as student_name', 'student.student_num'])
            ->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 同步申报团队成员。 */
    public static function syncDeclarationMembers(int $declarationId, int $leaderStudentId, array $studentIds, string $now): void
    {
        self::queryTable('social_practice_declaration_member')->where('declaration_id', $declarationId)->whereNull('deleted_at')->update([
            'status' => 'removed', 'deleted_at' => $now, 'updated_at' => $now,
        ]);
        foreach (array_values(array_unique(array_filter(array_map('intval', $studentIds)))) as $studentId) {
            self::queryTable('social_practice_declaration_member')->insert([
                'uuid' => self::uuid(),
                'declaration_id' => $declarationId,
                'student_id' => $studentId,
                'member_role' => $studentId === $leaderStudentId ? 'leader' : 'member',
                'confirm_status' => $studentId === $leaderStudentId ? 'accepted' : 'pending',
                'confirmed_at' => $studentId === $leaderStudentId ? $now : null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }
    }

    /** 检查申报团队成员是否全部确认。 */
    public static function declarationMembersConfirmed(int $declarationId): bool
    {
        return !self::queryTable('social_practice_declaration_member')
            ->where('declaration_id', $declarationId)
            ->where('status', 'active')
            ->where('confirm_status', '<>', 'accepted')
            ->whereNull('deleted_at')
            ->exists();
    }

    /** 读取学生组织档案。 */
    public static function studentProfile(int $studentId): ?array
    {
        $row = self::queryTable('students')->where('student_id', $studentId)->whereNull('deleted_at')->first([
            'student_id', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'name', 'student_num',
        ]);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取计划范围内的有效学生 ID。 */
    public static function eligibleStudentIdsForPlan(int $planId, array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if (!$studentIds) {
            return [];
        }

        $plan = self::queryTable('social_practice_plan')
            ->where('id', $planId)
            ->whereNull('deleted_at')
            ->first(['id', 'grade_id']);
        if (!$plan) {
            return [];
        }

        $scopes = self::planScopeRows($planId);
        if (!$scopes) {
            return [];
        }

        return self::queryTable('students')
            ->whereIn('student_id', $studentIds)
            ->where('grade_id', (int) $plan->grade_id)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->get(['student_id', 'dep_id', 'profession_id', 'class_id'])
            ->filter(fn ($student): bool => self::studentMatchesPlanScopes($student, $scopes))
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** 读取计划范围内的学生选项。 */
    public static function eligibleStudentOptionRowsForPlan(int $planId, int $limit = 1000): array
    {
        $plan = self::queryTable('social_practice_plan')
            ->where('id', $planId)
            ->whereNull('deleted_at')
            ->first(['id', 'grade_id']);
        if (!$plan) {
            return [];
        }
        $scopes = self::planScopeRows($planId);
        if (!$scopes) {
            return [];
        }
        return self::queryTable('students')
            ->where('grade_id', (int) $plan->grade_id)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('student_num')
            ->limit(max(1, min(5000, $limit)))
            ->get(['student_id', 'name', 'student_num', 'grade_id', 'dep_id', 'profession_id', 'class_id'])
            ->filter(fn ($student): bool => self::studentMatchesPlanScopes($student, $scopes))
            ->map(fn ($row) => $row->getAttributes())
            ->values()
            ->all();
    }

    /** 读取项目基本记录。 */
    public static function projectRow(int $projectId): ?array
    {
        $row = self::queryTable('social_practice_project')->where('id', $projectId)->whereNull('deleted_at')->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 读取申报基本记录。 */
    public static function declarationRow(int $declarationId): ?array
    {
        $row = self::queryTable('social_practice_declaration')->where('id', $declarationId)->whereNull('deleted_at')->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 读取计划基本记录。 */
    public static function planRow(int $planId): ?array
    {
        $row = self::queryTable('social_practice_plan')->where('id', $planId)->whereNull('deleted_at')->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 检查实体是否处于当前数据范围。 */
    public static function entityVisible(array $scope, string $entity, int $id): bool
    {
        return self::visibleEntityRow($scope, $entity, $id) !== null;
    }

    /** 读取审批流节点。 */
    public static function approvalNodeRows(int $flowId): array
    {
        return self::queryTable('social_practice_approval_node')
            ->where('flow_id', $flowId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'flow_id', 'node_code', 'node_name', 'sort', 'scope_type', 'permission_code', 'node_type', 'can_return', 'auto_pass'])
            ->map(fn ($row) => $row->getAttributes())
            ->all();
    }

    /** 读取审批节点。 */
    public static function approvalNodeRow(int $nodeId): ?array
    {
        $row = self::queryTable('social_practice_approval_node')
            ->where('id', $nodeId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'flow_id', 'node_code', 'node_name', 'sort', 'scope_type', 'permission_code', 'node_type', 'can_return', 'auto_pass']);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取教师所属项目关系。 */
    public static function projectTeacherRelation(int $projectId, int $teacherId): ?array
    {
        $row = self::queryTable('social_practice_project_teacher')
            ->where('project_id', $projectId)
            ->where('teacher_id', $teacherId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['id', 'project_id', 'teacher_id', 'teacher_role', 'capacity']);
        return $row ? $row->getAttributes() : null;
    }

    /** 读取计划内学生的有效参与记录。 */
    public static function activePlanParticipant(int $planId, int $studentId): ?array
    {
        $row = self::queryTable('social_practice_participant')
            ->where('plan_id', $planId)
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 读取申报成员关系。 */
    public static function declarationMemberRow(int $declarationId, int $studentId): ?array
    {
        $row = self::queryTable('social_practice_declaration_member')
            ->where('declaration_id', $declarationId)
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 检查学生是否存在冲突申报或参与记录。 */
    public static function studentPlanConflict(int $planId, int $studentId, ?int $excludeDeclarationId = null): bool
    {
        if (self::queryTable('social_practice_participant')
            ->where('plan_id', $planId)
            ->where('student_id', $studentId)
            ->whereNotNull('project_id')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists()) {
            return true;
        }

        $query = self::queryTable('social_practice_declaration_member as member')
            ->join('social_practice_declaration as declaration', 'member.declaration_id', '=', 'declaration.id')
            ->where('declaration.plan_id', $planId)
            ->where('member.student_id', $studentId)
            ->where('member.status', 'active')
            ->whereIn('declaration.status', ['draft', 'wait', 'accept', 'modify'])
            ->whereNull('member.deleted_at')
            ->whereNull('declaration.deleted_at');
        if ($excludeDeclarationId) {
            $query->where('declaration.id', '<>', $excludeDeclarationId);
        }
        return $query->exists();
    }

    /** 读取项目分配完整度。 */
    public static function projectConfigurationSummary(int $projectId): array
    {
        return [
            'leader_count' => (int) self::queryTable('social_practice_project_teacher')->where('project_id', $projectId)->where('teacher_role', 'leader')->where('status', 'active')->whereNull('deleted_at')->count(),
            'teacher_count' => (int) self::queryTable('social_practice_project_teacher')->where('project_id', $projectId)->where('status', 'active')->whereNull('deleted_at')->count(),
            'participant_count' => (int) self::queryTable('social_practice_participant')->where('project_id', $projectId)->where('status', 'active')->whereNull('deleted_at')->count(),
        ];
    }

    /** 读取计划材料要求数量。 */
    public static function planRequirementCount(int $planId): int
    {
        return (int) self::queryTable('social_practice_requirement')
            ->where('plan_id', $planId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->count();
    }

    /** 读取计划中的材料要求。 */
    public static function materialRequirementRow(int $planId, string $practiceMode, string $materialType): ?array
    {
        $row = self::queryTable('social_practice_requirement')
            ->where('plan_id', $planId)
            ->whereIn('practice_mode', ['all', $practiceMode])
            ->where('requirement_type', $materialType)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderByRaw("FIELD(practice_mode, ?, 'all')", [$practiceMode])
            ->first();
        return $row ? self::decodeJsonFields($row->getAttributes(), ['config_json']) : null;
    }

    /** 读取现有材料记录。 */
    public static function materialTargetRow(int $planId, ?int $projectId, ?int $declarationId, ?int $studentId, string $materialType): ?array
    {
        $query = self::queryTable('social_practice_material')
            ->where('plan_id', $planId)
            ->where('material_type', $materialType)
            ->whereNull('deleted_at');
        self::whereNullable($query, 'project_id', $projectId);
        self::whereNullable($query, 'declaration_id', $declarationId);
        self::whereNullable($query, 'student_id', $studentId);
        $row = $query->orderByDesc('version')->orderByDesc('id')->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 读取现有成绩记录。 */
    public static function scoreTargetRow(int $planId, int $projectId, int $studentId): ?array
    {
        $row = self::queryTable('social_practice_score')
            ->where('plan_id', $planId)
            ->where('project_id', $projectId)
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 读取学生当日签到记录。 */
    public static function signInRow(int $projectId, int $studentId, string $date): ?array
    {
        $row = self::queryTable('sign_in')
            ->where('entity_type', 'social_practice')
            ->where('entity_id', $projectId)
            ->where('student_id', $studentId)
            ->where('date', $date)
            ->whereNull('deleted_at')
            ->first();
        return $row ? $row->getAttributes() : null;
    }

    /** 读取专业对应学院。 */
    public static function depIdsByProfessionIds(array $professionIds): array
    {
        $professionIds = array_values(array_filter(array_map('intval', $professionIds)));
        if (!$professionIds) {
            return [];
        }
        return self::queryTable('profession')
            ->whereIn('profession_id', $professionIds)
            ->whereNull('deleted_at')
            ->pluck('dep_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** 读取项目相关账号。 */
    public static function projectAccountIds(int $projectId): array
    {
        $teacherUserIds = self::queryTable('social_practice_project_teacher as relation')
            ->join('teacher_list as teacher', 'relation.teacher_id', '=', 'teacher.teacher_id')
            ->where('relation.project_id', $projectId)
            ->where('relation.status', 'active')
            ->whereNull('relation.deleted_at')
            ->pluck('teacher.user_id')
            ->all();
        $studentUserIds = self::queryTable('social_practice_participant as participant')
            ->join('students as student', 'participant.student_id', '=', 'student.student_id')
            ->where('participant.project_id', $projectId)
            ->where('participant.status', 'active')
            ->whereNull('participant.deleted_at')
            ->pluck('student.user_id')
            ->all();
        return Account::enabledIdsByUserIds(array_values(array_unique(array_filter(array_map('intval', array_merge($teacherUserIds, $studentUserIds))))));
    }

    /** 读取实体表名。 */
    public static function entityTable(string $entity): string
    {
        if (!isset(self::ENTITY_TABLES[$entity])) {
            throw new \InvalidArgumentException('社会实践资源类型无效');
        }
        return self::ENTITY_TABLES[$entity];
    }

    /** 读取实体时间线类型。 */
    public static function entityType(string $entity): string
    {
        return 'social_practice_' . $entity;
    }

    /** 统计带范围的计划。 */
    private static function countScopedPlans(array $scope, ?callable $callback = null): int
    {
        $query = self::planQuery();
        self::applyPlanScope($query, $scope, 'sp_plan');
        if ($callback) {
            $callback($query);
        }
        return (int) $query->count('sp_plan.id');
    }

    /** 统计带范围的项目。 */
    private static function countScopedProjects(array $scope, string $mode): int
    {
        $query = self::projectQuery()->where('sp_project.practice_mode', $mode);
        self::applyEntityScope($query, $scope, 'project');
        return (int) $query->count('sp_project.id');
    }

    /** 统计带范围的实体。 */
    private static function countScopedEntity(array $scope, string $resource, string $status): int
    {
        $query = self::resourceQuery($resource)->where(self::resourceAlias($resource) . '.status', $status);
        self::applyEntityScope($query, $scope, $resource);
        return (int) $query->count(self::resourceAlias($resource) . '.id');
    }

    /** 统计有效参与人。 */
    private static function countScopedParticipants(array $scope): int
    {
        $query = self::participantQuery();
        self::applyEntityScope($query, $scope, 'participant');
        return (int) $query->count('participant.id');
    }

    /** 返回计划分页。 */
    private static function planPage(array $scope, array $filters): array
    {
        $query = self::planQuery();
        self::applyPlanScope($query, $scope, 'sp_plan');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'sp_plan', ['title', 'code', 'description']);
        if (!empty($filters['phase'])) {
            $query->where('sp_plan.phase', $filters['phase']);
        }
        return self::paginate($query->orderByDesc('sp_plan.id'), $filters, self::planColumns());
    }

    /** 返回项目分页。 */
    private static function projectPage(array $scope, array $filters): array
    {
        $query = self::projectQuery();
        self::applyEntityScope($query, $scope, 'project');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'sp_project', ['title', 'project_code', 'location']);
        if (!empty($filters['practice_mode'])) {
            $query->where('sp_project.practice_mode', $filters['practice_mode']);
        }
        return self::paginate($query->orderByDesc('sp_project.id'), $filters, self::projectColumns());
    }

    /** 返回实施申请分页。 */
    private static function implementationPage(array $scope, array $filters): array
    {
        $query = self::resourceQuery('implementation');
        self::applyEntityScope($query, $scope, 'implementation');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'implementation', ['title', 'content']);
        return self::paginate($query->orderByDesc('implementation.id'), $filters, self::implementationColumns());
    }

    /** 返回分散申报分页。 */
    private static function declarationPage(array $scope, array $filters): array
    {
        $query = self::resourceQuery('declaration');
        self::applyEntityScope($query, $scope, 'declaration');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'declaration', ['title', 'content', 'location']);
        return self::paginate($query->orderByDesc('declaration.id'), $filters, self::declarationColumns());
    }

    /** 返回参与人分页。 */
    private static function participantPage(array $scope, array $filters): array
    {
        $query = self::participantQuery();
        self::applyEntityScope($query, $scope, 'participant');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'participant', []);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = self::likeValue($keyword);
            $query->where(function ($builder) use ($like): void {
                $builder->where('student.name', 'like', $like)->orWhere('student.student_num', 'like', $like)
                    ->orWhere('teacher.teacher_name', 'like', $like)->orWhere('sp_project.title', 'like', $like);
            });
        }
        return self::paginate($query->orderByDesc('participant.id'), $filters, self::participantColumns());
    }

    /** 返回项目教师分页。 */
    private static function teacherPage(array $scope, array $filters): array
    {
        $query = self::queryTable('social_practice_project_teacher as relation')
            ->join('social_practice_project as sp_project', 'relation.project_id', '=', 'sp_project.id')
            ->join('social_practice_plan as sp_plan', 'sp_project.plan_id', '=', 'sp_plan.id')
            ->join('teacher_list as teacher', 'relation.teacher_id', '=', 'teacher.teacher_id')
            ->leftJoin('department as dep', 'teacher.dep_id', '=', 'dep.dep_id')
            ->leftJoin('profession as profession', 'teacher.profession_id', '=', 'profession.profession_id')
            ->where('relation.status', 'active')->whereNull('relation.deleted_at')->whereNull('sp_project.deleted_at')->whereNull('sp_plan.deleted_at');
        self::applyEntityScope($query, $scope, 'teacher');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = self::likeValue($keyword);
            $query->where(function ($builder) use ($like): void {
                $builder->where('teacher.teacher_name', 'like', $like)->orWhere('teacher.teacher_num', 'like', $like)->orWhere('sp_project.title', 'like', $like);
            });
        }
        return self::paginate($query->orderByDesc('relation.id'), $filters, [
            'relation.id', 'relation.project_id', 'relation.teacher_id', 'relation.teacher_role', 'relation.capacity', 'relation.status',
            'teacher.teacher_name', 'teacher.teacher_num', 'sp_project.title as project_title', 'sp_project.practice_mode',
            'sp_plan.title as plan_title', 'sp_plan.grade_id', 'dep.dep_name', 'profession.profession_name',
            new Expression('(SELECT COUNT(*) FROM social_practice_participant p WHERE p.project_id = relation.project_id AND p.teacher_id = relation.teacher_id AND p.status = \'active\' AND p.deleted_at IS NULL) AS assigned_count'),
        ]);
    }

    /** 返回材料分页。 */
    private static function materialPage(array $scope, string $resource, array $filters): array
    {
        $query = self::resourceQuery('material');
        self::applyEntityScope($query, $scope, 'material');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'material', ['title', 'content', 'material_type']);
        $safetyTypes = ['safety_agreement', 'insurance', 'emergency_plan', 'parent_notice'];
        $resource === 'safety' ? $query->whereIn('material.material_type', $safetyTypes) : $query->whereNotIn('material.material_type', $safetyTypes);
        return self::paginate($query->orderByDesc('material.id'), $filters, self::materialColumns());
    }

    /** 返回签到分页。 */
    private static function attendancePage(array $scope, array $filters): array
    {
        $query = self::queryTable('sign_in as attendance')
            ->join('social_practice_project as sp_project', 'attendance.entity_id', '=', 'sp_project.id')
            ->join('social_practice_plan as sp_plan', 'sp_project.plan_id', '=', 'sp_plan.id')
            ->join('students as student', 'attendance.student_id', '=', 'student.student_id')
            ->leftJoin('teacher_list as teacher', 'attendance.teacher_id', '=', 'teacher.teacher_id')
            ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
            ->where('attendance.entity_type', 'social_practice')
            ->whereNull('attendance.deleted_at')->whereNull('sp_project.deleted_at')->whereNull('sp_plan.deleted_at');
        self::applyEntityScope($query, $scope, 'attendance');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'attendance', ['location', 'remark']);
        if (!empty($filters['date'])) {
            $query->where('attendance.date', $filters['date']);
        }
        return self::paginate($query->orderByDesc('attendance.id'), $filters, [
            'attendance.id', 'attendance.uuid', 'attendance.entity_id as project_id', 'attendance.student_id', 'attendance.teacher_id',
            'attendance.sign_time', 'attendance.date', 'attendance.longitude', 'attendance.latitude', 'attendance.location', 'attendance.sign_type', 'attendance.remark', 'attendance.status',
            'sp_project.title as project_title', 'sp_project.practice_mode', 'sp_plan.id as plan_id', 'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name',
            'student.name as student_name', 'student.student_num', 'teacher.teacher_name',
        ]);
    }

    /** 返回补签申请分页。 */
    private static function patchSignPage(array $scope, array $filters): array
    {
        $query = self::resourceQuery('patch_sign');
        self::applyEntityScope($query, $scope, 'patch_sign');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'patch_sign', ['reason', 'proof']);
        return self::paginate($query->orderByDesc('patch_sign.id'), $filters, self::patchSignColumns());
    }

    /** 返回成绩分页。 */
    private static function scorePage(array $scope, array $filters): array
    {
        $query = self::resourceQuery('score');
        self::applyEntityScope($query, $scope, 'score');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'score', []);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = self::likeValue($keyword);
            $query->where(function ($builder) use ($like): void {
                $builder->where('score.comment', 'like', $like)->orWhere('student.name', 'like', $like)->orWhere('student.student_num', 'like', $like)
                    ->orWhere('teacher.teacher_name', 'like', $like)->orWhere('sp_project.title', 'like', $like);
            });
        }
        return self::paginate($query->orderByDesc('score.id'), $filters, self::scoreColumns());
    }

    /** 返回归档分页。 */
    private static function socialArchivePage(array $scope, array $filters): array
    {
        $query = self::resourceQuery('archive');
        self::applyEntityScope($query, $scope, 'archive');
        self::applyPlanFilters($query, $filters, 'sp_plan');
        self::applyCommonFilters($query, $filters, 'archive', []);
        return self::paginate($query->orderByDesc('archive.id'), $filters, self::archiveColumns());
    }

    /** 创建计划查询。 */
    private static function planQuery(): mixed
    {
        return self::queryTable('social_practice_plan as sp_plan')
            ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
            ->leftJoin('department as organizer', 'sp_plan.organizer_dep_id', '=', 'organizer.dep_id')
            ->whereNull('sp_plan.deleted_at');
    }

    /** 创建项目查询。 */
    private static function projectQuery(): mixed
    {
        return self::queryTable('social_practice_project as sp_project')
            ->join('social_practice_plan as sp_plan', 'sp_project.plan_id', '=', 'sp_plan.id')
            ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
            ->leftJoin('department as organizer', 'sp_plan.organizer_dep_id', '=', 'organizer.dep_id')
            ->whereNull('sp_project.deleted_at')->whereNull('sp_plan.deleted_at');
    }

    /** 创建参与人查询。 */
    private static function participantQuery(): mixed
    {
        return self::queryTable('social_practice_participant as participant')
            ->join('social_practice_plan as sp_plan', 'participant.plan_id', '=', 'sp_plan.id')
            ->leftJoin('social_practice_project as sp_project', 'participant.project_id', '=', 'sp_project.id')
            ->join('students as student', 'participant.student_id', '=', 'student.student_id')
            ->leftJoin('teacher_list as teacher', 'participant.teacher_id', '=', 'teacher.teacher_id')
            ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
            ->leftJoin('department as dep', 'student.dep_id', '=', 'dep.dep_id')
            ->leftJoin('profession as profession', 'student.profession_id', '=', 'profession.profession_id')
            ->leftJoin('class as class_row', 'student.class_id', '=', 'class_row.class_id')
            ->where('participant.status', 'active')->whereNull('participant.deleted_at')->whereNull('sp_plan.deleted_at');
    }

    /** 按资源创建查询。 */
    private static function resourceQuery(string $resource): mixed
    {
        return match ($resource) {
            'plan' => self::planQuery(),
            'project' => self::projectQuery(),
            'implementation' => self::queryTable('social_practice_implementation_application as implementation')
                ->join('social_practice_project as sp_project', 'implementation.project_id', '=', 'sp_project.id')
                ->join('social_practice_plan as sp_plan', 'sp_project.plan_id', '=', 'sp_plan.id')
                ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
                ->whereNull('implementation.deleted_at')->whereNull('sp_project.deleted_at')->whereNull('sp_plan.deleted_at'),
            'declaration' => self::queryTable('social_practice_declaration as declaration')
                ->join('social_practice_plan as sp_plan', 'declaration.plan_id', '=', 'sp_plan.id')
                ->join('students as applicant', 'declaration.applicant_student_id', '=', 'applicant.student_id')
                ->leftJoin('teacher_list as selected_teacher', 'declaration.selected_teacher_id', '=', 'selected_teacher.teacher_id')
                ->leftJoin('teacher_list as assigned_teacher', 'declaration.assigned_teacher_id', '=', 'assigned_teacher.teacher_id')
                ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
                ->whereNull('declaration.deleted_at')->whereNull('sp_plan.deleted_at'),
            'material' => self::queryTable('social_practice_material as material')
                ->join('social_practice_plan as sp_plan', 'material.plan_id', '=', 'sp_plan.id')
                ->leftJoin('social_practice_project as sp_project', 'material.project_id', '=', 'sp_project.id')
                ->leftJoin('students as student', 'material.student_id', '=', 'student.student_id')
                ->leftJoin('social_practice_participant as material_participant', function ($join): void {
                    $join->on('material_participant.project_id', '=', 'material.project_id')
                        ->on('material_participant.student_id', '=', 'material.student_id')
                        ->where('material_participant.status', '=', 'active')
                        ->whereNull('material_participant.deleted_at');
                })
                ->leftJoin('teacher_list as material_teacher', 'material_participant.teacher_id', '=', 'material_teacher.teacher_id')
                ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
                ->whereNull('material.deleted_at')->whereNull('sp_plan.deleted_at'),
            'patch_sign' => self::queryTable('social_practice_patch_sign as patch_sign')
                ->join('social_practice_plan as sp_plan', 'patch_sign.plan_id', '=', 'sp_plan.id')
                ->join('social_practice_project as sp_project', 'patch_sign.project_id', '=', 'sp_project.id')
                ->join('students as student', 'patch_sign.student_id', '=', 'student.student_id')
                ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
                ->whereNull('patch_sign.deleted_at')->whereNull('sp_plan.deleted_at'),
            'score' => self::queryTable('social_practice_score as score')
                ->join('social_practice_plan as sp_plan', 'score.plan_id', '=', 'sp_plan.id')
                ->join('social_practice_project as sp_project', 'score.project_id', '=', 'sp_project.id')
                ->join('students as student', 'score.student_id', '=', 'student.student_id')
                ->leftJoin('teacher_list as teacher', 'score.teacher_id', '=', 'teacher.teacher_id')
                ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
                ->whereNull('score.deleted_at')->whereNull('sp_plan.deleted_at'),
            'archive' => self::queryTable('social_practice_archive as archive')
                ->join('social_practice_plan as sp_plan', 'archive.plan_id', '=', 'sp_plan.id')
                ->leftJoin('social_practice_project as sp_project', 'archive.project_id', '=', 'sp_project.id')
                ->leftJoin('students as student', 'archive.student_id', '=', 'student.student_id')
                ->leftJoin('teacher_list as teacher', 'archive.teacher_id', '=', 'teacher.teacher_id')
                ->leftJoin('grade_list as grade', 'sp_plan.grade_id', '=', 'grade.grade_id')
                ->whereNull('archive.deleted_at')->whereNull('sp_plan.deleted_at'),
            default => throw new \InvalidArgumentException('社会实践资源类型无效'),
        };
    }

    /** 按角色应用实体数据范围。 */
    private static function applyEntityScope(mixed $query, array $scope, string $resource): void
    {
        $role = (string) ($scope['role_type'] ?? '');
        if (in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        }
        if (in_array($role, ['college_admin', 'profession_admin'], true)) {
            self::applyPlanScope($query, $scope, 'sp_plan');
            return;
        }
        if ($role === 'teacher') {
            self::applyTeacherScope($query, $resource, (int) ($scope['teacher_id'] ?? 0));
            return;
        }
        if ($role === 'student') {
            self::applyStudentScope($query, $resource, (int) ($scope['student_id'] ?? 0), (array) ($scope['student_profile'] ?? []));
            return;
        }
        $query->whereRaw('1 = 0');
    }

    /** 按角色应用计划数据范围。 */
    private static function applyPlanScope(mixed $query, array $scope, string $alias): void
    {
        $role = (string) ($scope['role_type'] ?? '');
        if (in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        }
        if ($role === 'college_admin') {
            $depIds = array_values(array_filter(array_map('intval', $scope['dep_ids'] ?? [])));
            $query->where(function ($builder) use ($alias, $depIds): void {
                self::whereInOrDeny($builder, "{$alias}.organizer_dep_id", $depIds);
                $builder->orWhereExists(function ($sub) use ($alias, $depIds): void {
                    $sub->selectRaw('1')->from('social_practice_plan_scope as scope')
                        ->whereColumn('scope.plan_id', "{$alias}.id")
                        ->where('scope.status', 'enabled')->whereNull('scope.deleted_at')
                        ->where(function ($target) use ($depIds): void {
                            $target->where('scope.scope_type', 'school');
                            if ($depIds) {
                                $target->orWhereIn('scope.dep_id', $depIds);
                            }
                        });
                });
            });
            return;
        }
        if ($role === 'profession_admin') {
            $professionIds = array_values(array_filter(array_map('intval', $scope['profession_ids'] ?? [])));
            $depIds = array_values(array_filter(array_map('intval', $scope['profession_dep_ids'] ?? [])));
            $query->whereExists(function ($sub) use ($alias, $professionIds, $depIds): void {
                $sub->selectRaw('1')->from('social_practice_plan_scope as scope')
                    ->whereColumn('scope.plan_id', "{$alias}.id")
                    ->where('scope.status', 'enabled')->whereNull('scope.deleted_at')
                    ->where(function ($target) use ($professionIds, $depIds): void {
                        $target->where('scope.scope_type', 'school');
                        if ($depIds) {
                            $target->orWhere(function ($item) use ($depIds): void {
                                $item->whereIn('scope.dep_id', $depIds)->whereNull('scope.profession_id')->whereNull('scope.class_id');
                            });
                        }
                        if ($professionIds) {
                            $target->orWhereIn('scope.profession_id', $professionIds)
                                ->orWhereExists(function ($classScope) use ($professionIds): void {
                                    $classScope->selectRaw('1')->from('class as scoped_class')
                                        ->whereColumn('scoped_class.class_id', 'scope.class_id')
                                        ->whereIn('scoped_class.profession_id', $professionIds)
                                        ->whereNull('scoped_class.deleted_at');
                                });
                        }
                    });
            });
            return;
        }
        if ($role === 'teacher') {
            $teacherId = (int) ($scope['teacher_id'] ?? 0);
            $query->whereExists(function ($sub) use ($alias, $teacherId): void {
                $sub->selectRaw('1')->from('social_practice_project as project')
                    ->join('social_practice_project_teacher as relation', 'project.id', '=', 'relation.project_id')
                    ->whereColumn('project.plan_id', "{$alias}.id")
                    ->where('relation.teacher_id', $teacherId)->where('relation.status', 'active')->whereNull('relation.deleted_at');
            });
            return;
        }
        if ($role === 'student') {
            self::applyStudentPlanScope($query, $alias, (array) ($scope['student_profile'] ?? []), (int) ($scope['student_id'] ?? 0));
            return;
        }
        $query->whereRaw('1 = 0');
    }

    /** 应用教师业务关系范围。 */
    private static function applyTeacherScope(mixed $query, string $resource, int $teacherId): void
    {
        if ($teacherId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }
        match ($resource) {
            'project', 'implementation' => $query->whereExists(function ($sub) use ($teacherId): void {
                $sub->selectRaw('1')->from('social_practice_project_teacher as relation')
                    ->whereColumn('relation.project_id', 'sp_project.id')->where('relation.teacher_id', $teacherId)
                    ->where('relation.status', 'active')->whereNull('relation.deleted_at');
            }),
            'declaration' => $query->where(function ($builder) use ($teacherId): void {
                $builder->where('declaration.selected_teacher_id', $teacherId)->orWhere('declaration.assigned_teacher_id', $teacherId);
            }),
            'participant' => $query->where('participant.teacher_id', $teacherId),
            'teacher' => $query->where('relation.teacher_id', $teacherId),
            'material' => $query->whereExists(function ($sub) use ($teacherId): void {
                $sub->selectRaw('1')->from('social_practice_project_teacher as relation')
                    ->whereColumn('relation.project_id', 'material.project_id')->where('relation.teacher_id', $teacherId)
                    ->where('relation.status', 'active')->whereNull('relation.deleted_at');
            }),
            'attendance' => $query->where('attendance.teacher_id', $teacherId),
            'patch_sign' => $query->whereExists(function ($sub) use ($teacherId): void {
                $sub->selectRaw('1')->from('social_practice_participant as participant')
                    ->whereColumn('participant.project_id', 'patch_sign.project_id')->whereColumn('participant.student_id', 'patch_sign.student_id')
                    ->where('participant.teacher_id', $teacherId)->where('participant.status', 'active')->whereNull('participant.deleted_at');
            }),
            'score', 'archive' => $query->where(self::resourceAlias($resource) . '.teacher_id', $teacherId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** 应用学生业务关系范围。 */
    private static function applyStudentScope(mixed $query, string $resource, int $studentId, array $profile): void
    {
        if ($studentId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }
        match ($resource) {
            'project', 'implementation' => $query->whereExists(function ($sub) use ($studentId): void {
                $sub->selectRaw('1')->from('social_practice_participant as participant')
                    ->whereColumn('participant.project_id', 'sp_project.id')->where('participant.student_id', $studentId)
                    ->where('participant.status', 'active')->whereNull('participant.deleted_at');
            }),
            'declaration' => $query->where(function ($builder) use ($studentId): void {
                $builder->where('declaration.applicant_student_id', $studentId)->orWhereExists(function ($sub) use ($studentId): void {
                    $sub->selectRaw('1')->from('social_practice_declaration_member as member')
                        ->whereColumn('member.declaration_id', 'declaration.id')->where('member.student_id', $studentId)
                        ->where('member.status', 'active')->whereNull('member.deleted_at');
                });
            }),
            'participant' => $query->where('participant.student_id', $studentId),
            'teacher' => $query->whereExists(function ($sub) use ($studentId): void {
                $sub->selectRaw('1')->from('social_practice_participant as participant')
                    ->whereColumn('participant.project_id', 'relation.project_id')->where('participant.student_id', $studentId)
                    ->where('participant.status', 'active')->whereNull('participant.deleted_at');
            }),
            'material' => $query->where(function ($builder) use ($studentId): void {
                $builder->where('material.student_id', $studentId)->orWhere(function ($projectMaterial) use ($studentId): void {
                    $projectMaterial->whereNull('material.student_id')->whereExists(function ($sub) use ($studentId): void {
                        $sub->selectRaw('1')->from('social_practice_participant as participant')
                            ->whereColumn('participant.project_id', 'material.project_id')->where('participant.student_id', $studentId)
                            ->where('participant.status', 'active')->whereNull('participant.deleted_at');
                    });
                });
            }),
            'attendance' => $query->where('attendance.student_id', $studentId),
            'patch_sign' => $query->where('patch_sign.student_id', $studentId),
            'score' => $query->where('score.student_id', $studentId),
            'archive' => $query->where('archive.student_id', $studentId),
            'plan' => self::applyStudentPlanScope($query, 'sp_plan', $profile, $studentId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** 应用学生可参与计划范围。 */
    private static function applyStudentPlanScope(mixed $query, string $alias, array $profile, int $studentId): void
    {
        $query->where("{$alias}.grade_id", (int) ($profile['grade_id'] ?? 0))
            ->where("{$alias}.status", 'accept')
            ->whereNotNull("{$alias}.published_at")
            ->where(function ($builder) use ($alias, $profile, $studentId): void {
                $builder->whereExists(function ($sub) use ($alias, $studentId): void {
                    $sub->selectRaw('1')->from('social_practice_participant as participant')
                        ->whereColumn('participant.plan_id', "{$alias}.id")->where('participant.student_id', $studentId)
                        ->where('participant.status', 'active')->whereNull('participant.deleted_at');
                })->orWhereExists(function ($sub) use ($alias, $profile): void {
                    $sub->selectRaw('1')->from('social_practice_plan_scope as scope')
                        ->whereColumn('scope.plan_id', "{$alias}.id")->where('scope.status', 'enabled')->whereNull('scope.deleted_at')
                        ->where(function ($target) use ($profile): void {
                            $target->where('scope.scope_type', 'school')
                                ->orWhere(function ($item) use ($profile): void {
                                    $item->where('scope.dep_id', (int) ($profile['dep_id'] ?? 0))->whereNull('scope.profession_id')->whereNull('scope.class_id');
                                })->orWhere('scope.profession_id', (int) ($profile['profession_id'] ?? 0))
                                ->orWhere('scope.class_id', (int) ($profile['class_id'] ?? 0));
                        });
                });
            });
    }

    /** 应用组织选项范围。 */
    private static function applyOptionScope(mixed $query, array $scope, string $depField, ?string $professionField): void
    {
        if (($scope['role_type'] ?? '') === 'college_admin') {
            self::whereInOrDeny($query, $depField, $scope['dep_ids'] ?? []);
        }
        if (($scope['role_type'] ?? '') === 'profession_admin') {
            if ($professionField) {
                self::whereInOrDeny($query, $professionField, $scope['profession_ids'] ?? []);
            } else {
                self::whereInOrDeny($query, $depField, $scope['profession_dep_ids'] ?? []);
            }
        }
    }

    /** 应用计划级筛选。 */
    private static function applyPlanFilters(mixed $query, array $filters, string $alias): void
    {
        foreach (['grade_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where("{$alias}.{$field}", (int) $filters[$field]);
            }
        }
        if (!empty($filters['dep_id'])) {
            $depId = (int) $filters['dep_id'];
            $query->where(function ($builder) use ($alias, $depId): void {
                $builder->where("{$alias}.organizer_dep_id", $depId)->orWhereExists(function ($sub) use ($alias, $depId): void {
                    $sub->selectRaw('1')->from('social_practice_plan_scope as scope')
                        ->whereColumn('scope.plan_id', "{$alias}.id")->where('scope.dep_id', $depId)
                        ->where('scope.status', 'enabled')->whereNull('scope.deleted_at');
                });
            });
        }
        foreach (['profession_id', 'class_id'] as $field) {
            if (!empty($filters[$field])) {
                $value = (int) $filters[$field];
                $query->whereExists(function ($sub) use ($alias, $field, $value): void {
                    $sub->selectRaw('1')->from('social_practice_plan_scope as scope')
                        ->whereColumn('scope.plan_id', "{$alias}.id")->where("scope.{$field}", $value)
                        ->where('scope.status', 'enabled')->whereNull('scope.deleted_at');
                });
            }
        }
        if (!empty($filters['plan_id'])) {
            $query->where("{$alias}.id", (int) $filters['plan_id']);
        }
    }

    /** 应用通用状态和关键词筛选。 */
    private static function applyCommonFilters(mixed $query, array $filters, string $alias, array $keywordFields): void
    {
        if (!empty($filters['status'])) {
            $query->where("{$alias}.status", $filters['status']);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '' && $keywordFields) {
            $like = self::likeValue($keyword);
            $query->where(function ($builder) use ($alias, $keywordFields, $like): void {
                foreach ($keywordFields as $field) {
                    $builder->orWhere("{$alias}.{$field}", 'like', $like);
                }
            });
        }
    }

    /** 返回带数量限制的分页结果。 */
    private static function paginate(mixed $query, array $filters, array $columns): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? $filters['per_page'] ?? 20)));
        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get($columns)->map(static function ($row): array {
            return self::decodeJsonFields($row->getAttributes(), ['config_json', 'snapshot_json']);
        })->all();

        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total]];
    }

    /** 返回空分页。 */
    private static function emptyPage(array $filters): array
    {
        return ['items' => [], 'pagination' => ['page' => max(1, (int) ($filters['page'] ?? 1)), 'page_size' => 20, 'total' => 0]];
    }

    /** 读取可见实体记录。 */
    private static function visibleEntityRow(array $scope, string $resource, int $id): ?array
    {
        $query = self::resourceQuery($resource);
        $alias = self::resourceAlias($resource);
        $query->where("{$alias}.id", $id);
        $resource === 'plan' ? self::applyPlanScope($query, $scope, 'sp_plan') : self::applyEntityScope($query, $scope, $resource);
        $columns = match ($resource) {
            'plan' => self::planColumns(),
            'project' => self::projectColumns(),
            'implementation' => self::implementationColumns(),
            'declaration' => self::declarationColumns(),
            'material' => self::materialColumns(),
            'patch_sign' => self::patchSignColumns(),
            'score' => self::scoreColumns(),
            'archive' => self::archiveColumns(),
            default => ["{$alias}.*"],
        };
        $row = $query->first($columns);
        return $row ? self::decodeJsonFields($row->getAttributes(), ['config_json', 'snapshot_json']) : null;
    }

    /** 读取计划下项目。 */
    private static function projectRowsByPlan(array $scope, int $planId): array
    {
        $query = self::projectQuery()->where('sp_project.plan_id', $planId);
        self::applyEntityScope($query, $scope, 'project');
        return $query->orderBy('sp_project.id')->get(self::projectColumns())->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 读取有效审批流。 */
    public static function approvalFlowRows(array $scope): array
    {
        $query = self::queryTable('social_practice_approval_flow')->where('status', 'enabled')->whereNull('deleted_at');
        if (($scope['role_type'] ?? '') === 'college_admin') {
            $query->where(function ($builder) use ($scope): void {
                $builder->where('scope_type', 'school')
                    ->orWhereIn('dep_id', array_values(array_filter(array_map('intval', $scope['dep_ids'] ?? []))));
            });
        }
        if (($scope['role_type'] ?? '') === 'profession_admin') {
            $query->where(function ($builder) use ($scope): void {
                $builder->where('scope_type', 'school')
                    ->orWhereIn('dep_id', array_values(array_filter(array_map('intval', $scope['profession_dep_ids'] ?? []))))
                    ->orWhereIn('profession_id', array_values(array_filter(array_map('intval', $scope['profession_ids'] ?? []))));
            });
        }
        return $query->orderByDesc('version')->orderBy('id')->get(['id', 'uuid', 'name', 'code', 'scope_type', 'dep_id', 'profession_id', 'version'])->map(fn ($row) => $row->getAttributes())->all();
    }

    /** 判断学生是否匹配计划适用范围。 */
    private static function studentMatchesPlanScopes(object $student, array $scopes): bool
    {
        foreach ($scopes as $scope) {
            $matches = match ((string) ($scope['scope_type'] ?? '')) {
                'school' => true,
                'college' => (int) ($scope['dep_id'] ?? 0) === (int) $student->dep_id,
                'profession' => (int) ($scope['profession_id'] ?? 0) === (int) $student->profession_id,
                'class' => (int) ($scope['class_id'] ?? 0) === (int) $student->class_id,
                default => false,
            };
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    /** 保存师生绑定关系。 */
    private static function upsertPair(int $projectId, int $studentId, int $teacherId, string $now): void
    {
        $id = self::queryTable('pair')
            ->where('student_id', $studentId)->where('type', 'social_practice')
            ->where('entity_type', 'social_practice_project')->where('entity_id', $projectId)
            ->whereNull('deleted_at')->value('id');
        $values = [
            'teacher_id' => $teacherId,
            'arrangement_id' => $projectId,
            'status' => 'active',
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        if ($id) {
            self::queryTable('pair')->where('id', (int) $id)->update($values);
            return;
        }
        self::queryTable('pair')->insert(array_merge([
            'uuid' => self::uuid(),
            'student_id' => $studentId,
            'type' => 'social_practice',
            'entity_type' => 'social_practice_project',
            'entity_id' => $projectId,
            'created_at' => $now,
        ], $values));
    }

    /** 读取归档材料快照。 */
    private static function materialSnapshotRows(int $planId, ?int $projectId, ?int $studentId): array
    {
        $query = self::queryTable('social_practice_material')->where('plan_id', $planId)->whereNull('deleted_at');
        self::whereNullable($query, 'project_id', $projectId);
        if ($studentId) {
            $query->where(function ($builder) use ($studentId): void {
                $builder->where('student_id', $studentId)->orWhereNull('student_id');
            });
        }
        return $query->orderBy('id')->get()->map(function ($row): array {
            $data = $row->getAttributes();
            $data['attachments'] = FileRelation::rowsForEntity(self::entityType('material'), (int) $row->id, 'attachment')
                ->map(fn ($file): array => [
                    'id' => (int) $file->id,
                    'name' => (string) ($file->download_name ?: $file->name),
                    'url' => (string) $file->url,
                    'size' => (int) $file->size,
                    'mime_type' => (string) $file->mime_type,
                ])->all();
            return $data;
        })->all();
    }

    /** 读取归档成绩快照。 */
    private static function scoreSnapshotRows(int $planId, ?int $projectId, ?int $studentId): array
    {
        $query = self::queryTable('social_practice_score')->where('plan_id', $planId)->whereNull('deleted_at');
        self::whereNullable($query, 'project_id', $projectId);
        self::whereNullable($query, 'student_id', $studentId);
        return $query->orderBy('id')->get()->map(function ($row): array {
            $data = $row->getAttributes();
            $data['details'] = self::queryTable('social_practice_score_detail')->where('score_id', $row->id)->whereNull('deleted_at')->orderBy('id')->get()->map(fn ($item) => $item->getAttributes())->all();
            return $data;
        })->all();
    }

    /** 读取归档流程快照。 */
    private static function archiveRecordingRows(int $planId, ?int $projectId, ?int $studentId): array
    {
        $targets = [[self::entityType('plan'), $planId]];
        if ($projectId) {
            $targets[] = [self::entityType('project'), $projectId];
        }
        if ($studentId) {
            $materialIds = self::queryTable('social_practice_material')->where('plan_id', $planId)->where('student_id', $studentId)->whereNull('deleted_at')->pluck('id')->all();
            foreach ($materialIds as $id) {
                $targets[] = [self::entityType('material'), (int) $id];
            }
        }
        $rows = [];
        foreach ($targets as [$type, $id]) {
            $rows = array_merge($rows, self::recordingRows($type, $id));
        }
        return $rows;
    }

    /** 应用可空字段条件。 */
    private static function whereNullable(mixed $query, string $field, ?int $value): void
    {
        $value ? $query->where($field, $value) : $query->whereNull($field);
    }

    /** 将 JSON 字段解析为数组。 */
    private static function decodeJsonFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }

    /** 返回 SQL LIKE 值。 */
    private static function likeValue(string $value): string
    {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $value) . '%';
    }

    /** 对 ID 列表应用筛选或拒绝全部。 */
    private static function whereInOrDeny(mixed $query, string $field, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $ids ? $query->whereIn($field, $ids) : $query->whereRaw('1 = 0');
    }

    /** 返回资源查询别名。 */
    private static function resourceAlias(string $resource): string
    {
        return [
            'plan' => 'sp_plan',
            'project' => 'sp_project',
            'implementation' => 'implementation',
            'declaration' => 'declaration',
            'material' => 'material',
            'patch_sign' => 'patch_sign',
            'score' => 'score',
            'archive' => 'archive',
        ][$resource] ?? $resource;
    }

    /** 返回计划列。 */
    private static function planColumns(): array
    {
        return [
            'sp_plan.id', 'sp_plan.uuid', 'sp_plan.name', 'sp_plan.code', 'sp_plan.source_type', 'sp_plan.source_key',
            'sp_plan.title', 'sp_plan.description', 'sp_plan.grade_id', 'grade.grade_name', 'sp_plan.organizer_dep_id', 'organizer.dep_name as organizer_dep_name',
            'sp_plan.credit', 'sp_plan.participation_mode', 'sp_plan.teacher_match_mode', 'sp_plan.teacher_confirm_hours',
            'sp_plan.max_reselect_count', 'sp_plan.default_team_submit_mode', 'sp_plan.register_start_at', 'sp_plan.register_end_at',
            'sp_plan.practice_start_at', 'sp_plan.practice_end_at', 'sp_plan.result_deadline_at', 'sp_plan.score_deadline_at',
            'sp_plan.approval_flow_id', 'sp_plan.current_node_id', 'sp_plan.phase', 'sp_plan.submitter_id', 'sp_plan.published_at', 'sp_plan.status', 'sp_plan.created_at', 'sp_plan.updated_at',
        ];
    }

    /** 返回项目列。 */
    private static function projectColumns(): array
    {
        return [
            'sp_project.id', 'sp_project.uuid', 'sp_project.name', 'sp_project.code', 'sp_project.plan_id', 'sp_project.practice_mode',
            'sp_project.source_type', 'sp_project.source_declaration_id', 'sp_project.project_code', 'sp_project.title', 'sp_project.content',
            'sp_project.objective', 'sp_project.location', 'sp_project.start_at', 'sp_project.end_at', 'sp_project.capacity', 'sp_project.phase',
            'sp_project.created_by', 'sp_project.published_at', 'sp_project.status', 'sp_project.created_at', 'sp_project.updated_at',
            'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name', 'sp_plan.organizer_dep_id', 'organizer.dep_name as organizer_dep_name',
            new Expression('(SELECT COUNT(*) FROM social_practice_participant p WHERE p.project_id = sp_project.id AND p.status = \'active\' AND p.deleted_at IS NULL) AS participant_count'),
            new Expression('(SELECT COUNT(*) FROM social_practice_project_teacher t WHERE t.project_id = sp_project.id AND t.status = \'active\' AND t.deleted_at IS NULL) AS teacher_count'),
        ];
    }

    /** 返回实施申请列。 */
    private static function implementationColumns(): array
    {
        return ['implementation.*', 'sp_project.title as project_title', 'sp_project.practice_mode', 'sp_plan.id as plan_id', 'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name'];
    }

    /** 返回申报列。 */
    private static function declarationColumns(): array
    {
        return [
            'declaration.*', 'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name',
            'applicant.name as applicant_name', 'applicant.student_num',
            'selected_teacher.teacher_name as selected_teacher_name', 'assigned_teacher.teacher_name as assigned_teacher_name',
        ];
    }

    /** 返回参与人列。 */
    private static function participantColumns(): array
    {
        return [
            'participant.id', 'participant.uuid', 'participant.plan_id', 'participant.project_id', 'participant.student_id', 'participant.teacher_id',
            'participant.practice_mode', 'participant.join_source', 'participant.status', 'participant.created_at',
            'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name', 'sp_project.title as project_title',
            'student.name as student_name', 'student.student_num', 'student.dep_id', 'dep.dep_name', 'student.profession_id', 'profession.profession_name',
            'student.class_id', 'class_row.class_name', 'teacher.teacher_name',
        ];
    }

    /** 返回材料列。 */
    private static function materialColumns(): array
    {
        return [
            'material.*', 'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name', 'sp_project.title as project_title', 'sp_project.practice_mode',
            'student.name as student_name', 'student.student_num', 'material_participant.teacher_id', 'material_teacher.teacher_name',
        ];
    }

    /** 返回补签列。 */
    private static function patchSignColumns(): array
    {
        return [
            'patch_sign.*', 'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name', 'sp_project.title as project_title', 'sp_project.practice_mode',
            'student.name as student_name', 'student.student_num',
        ];
    }

    /** 返回成绩列。 */
    private static function scoreColumns(): array
    {
        return [
            'score.*', 'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name', 'sp_project.title as project_title', 'sp_project.practice_mode',
            'student.name as student_name', 'student.student_num', 'teacher.teacher_name',
        ];
    }

    /** 返回归档列。 */
    private static function archiveColumns(): array
    {
        return [
            'archive.*', 'sp_plan.title as plan_title', 'sp_plan.grade_id', 'grade.grade_name', 'sp_project.title as project_title', 'sp_project.practice_mode',
            'student.name as student_name', 'student.student_num', 'teacher.teacher_name',
        ];
    }
}
