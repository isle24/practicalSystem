<?php

namespace app\model\channel;

class InternshipArchiveRecord extends TableRecord
{
    private const EXTERNAL_ARRANGEMENT_TYPES = ['cognition_external', 'major_external', 'production', 'graduation'];

    private const GENERATED_FILE_REQUIRED_MATERIALS = ['plan', 'implementation_sheet', 'registration', 'score_register'];

    private const MATERIALS = [
        'plan' => ['label' => '实习计划', 'scope_type' => 'plan'],
        'implementation_sheet' => ['label' => '教学实习实施表', 'scope_type' => 'arrangement'],
        'syllabus' => ['label' => '实习教学大纲', 'scope_type' => 'plan'],
        'guide' => ['label' => '实习指导书', 'scope_type' => 'plan'],
        'registration' => ['label' => '实习情况登记表', 'scope_type' => 'arrangement'],
        'teacher_work_report' => ['label' => '指导教师工作报告', 'scope_type' => 'arrangement'],
        'journal' => ['label' => '实习周（日）志', 'scope_type' => 'student_task'],
        'report' => ['label' => '实习/实训报告', 'scope_type' => 'student_task'],
        'graduation_report' => ['label' => '毕业实习报告', 'scope_type' => 'student_task'],
        'graduation_appraisal' => ['label' => '毕业实习成绩鉴定表', 'scope_type' => 'student_task'],
        'score_register' => ['label' => '成绩登记表', 'scope_type' => 'plan_class'],
        'safety_commitment' => ['label' => '学生实习安全承诺书', 'scope_type' => 'student_task'],
    ];

    public static function materialDefinitions(): array
    {
        $rows = [];
        foreach (self::MATERIALS as $type => $definition) {
            $rows[] = array_merge(['material_type' => $type], $definition);
        }

        return $rows;
    }

    public static function materialDefinition(string $materialType): ?array
    {
        return isset(self::MATERIALS[$materialType])
            ? array_merge(['material_type' => $materialType], self::MATERIALS[$materialType])
            : null;
    }

    public static function planPage(array $scope, array $filters): array
    {
        $query = self::visiblePlanQuery($scope)
            ->leftJoin('department', 'internship_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'internship_plan.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'internship_plan.grade_id', '=', 'grade_list.grade_id');

        self::filterValue($query, $filters, 'internship_plan.grade_id', 'grade_id');
        self::filterValue($query, $filters, 'internship_plan.dep_id', 'dep_id');
        self::filterValue($query, $filters, 'internship_plan.profession_id', 'profession_id');
        $archiveStatus = trim((string) ($filters['archive_status'] ?? ''));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('internship_plan.course_code', 'like', $like)
                    ->orWhere('internship_plan.course_name', 'like', $like)
                    ->orWhere('department.dep_name', 'like', $like)
                    ->orWhere('profession.profession_name', 'like', $like)
                    ->orWhere('grade_list.grade_name', 'like', $like);
            });
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? $filters['per_page'] ?? 20)));
        $filterByArchiveStatus = in_array($archiveStatus, ['complete', 'incomplete'], true);
        $total = $filterByArchiveStatus ? 0 : (int) (clone $query)->count('internship_plan.id');
        $items = self::rows($query->orderByDesc('internship_plan.id')
            ->when(!$filterByArchiveStatus, static fn ($builder) => $builder->forPage($page, $pageSize))
            ->get([
                'internship_plan.id', 'internship_plan.uuid', 'internship_plan.course_code',
                'internship_plan.course_name', 'internship_plan.course_category',
                'internship_plan.grade_id', 'internship_plan.dep_id', 'internship_plan.profession_id',
                'internship_plan.business_type', 'internship_plan.status', 'internship_plan.created_at',
                'grade_list.grade_name', 'department.dep_name', 'profession.profession_name',
            ]));

        $items = self::appendPlanProgress($items, $scope);
        if ($filterByArchiveStatus) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['archive_status'] ?? '') === $archiveStatus
            ));
            $total = count($items);
            $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
            'materials' => self::materialDefinitions(),
        ];
    }

    /** 返回学生本人按任务归集的档案材料 */
    public static function studentMaterialPage(array $scope, array $filters): array
    {
        $query = self::visibleStudentTaskQuery($scope)
            ->join('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->join('internship_plan', 'arrangement.plan_id', '=', 'internship_plan.id')
            ->leftJoin('students', 'pair.student_id', '=', 'students.student_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->leftJoin('department', 'internship_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'internship_plan.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'internship_plan.grade_id', '=', 'grade_list.grade_id')
            ->where('arrangement.status', '<>', 'changed')
            ->whereNull('arrangement.deleted_at')
            ->whereNull('internship_plan.deleted_at');

        self::filterValue($query, $filters, 'internship_plan.grade_id', 'grade_id');
        self::filterValue($query, $filters, 'internship_plan.dep_id', 'dep_id');
        self::filterValue($query, $filters, 'internship_plan.profession_id', 'profession_id');
        self::filterValue($query, $filters, 'pair.arrangement_id', 'arrangement_id');
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('arrangement.title', 'like', $like)
                    ->orWhere('arrangement.task_no', 'like', $like)
                    ->orWhere('internship_plan.course_code', 'like', $like)
                    ->orWhere('internship_plan.course_name', 'like', $like);
            });
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? $filters['per_page'] ?? 20)));
        $archiveStatus = trim((string) ($filters['archive_status'] ?? ''));
        $filterByArchiveStatus = in_array($archiveStatus, ['complete', 'incomplete'], true);
        $total = $filterByArchiveStatus ? 0 : (int) (clone $query)->count('pair.id');
        $rows = self::rows($query->orderByDesc('arrangement.start_date')
            ->orderByDesc('arrangement.id')
            ->when(!$filterByArchiveStatus, static fn ($builder) => $builder->forPage($page, $pageSize))
            ->get([
                'pair.id', 'pair.student_id', 'pair.arrangement_id',
                'arrangement.plan_id', 'arrangement.title as arrangement_title', 'arrangement.type as arrangement_type',
                'arrangement.task_no', 'arrangement.batch_no', 'arrangement.start_date', 'arrangement.end_date',
                'arrangement.required_journal_count',
                'internship_plan.course_code', 'internship_plan.course_name',
                'students.name as student_name', 'students.student_num', 'students.class_id', 'class.class_name',
                'grade_list.grade_name', 'department.dep_name', 'profession.profession_name',
            ]));

        $planGroups = [];
        foreach ($rows as $row) {
            $planGroups[(int) $row['plan_id']][] = $row;
        }
        $materialRows = self::currentMaterialsForPlans(array_keys($planGroups));
        $materialMaps = [];
        foreach ($materialRows as $material) {
            $materialMaps[(int) $material['plan_id']][self::materialMapKey($material)] = $material;
        }

        $items = [];
        foreach ($planGroups as $planId => $planRows) {
            $arrangementRows = [];
            $studentGroups = [];
            foreach ($planRows as $row) {
                $arrangementId = (int) $row['arrangement_id'];
                $arrangementRows[$arrangementId] = $row;
                $studentGroups[$arrangementId][] = $row;
            }
            $sources = self::sourceStateData($planId, array_values($arrangementRows), $studentGroups, []);
            foreach ($planRows as $row) {
                $items[] = self::studentMaterialRow($row, $materialMaps[$planId] ?? [], $sources);
            }
        }

        if ($filterByArchiveStatus) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => (string) $item['archive_status'] === $archiveStatus
            ));
            $total = count($items);
            $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
        }

        return [
            'items' => $items,
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
            'materials' => self::materialDefinitions(),
        ];
    }

    public static function planDetail(array $scope, int $planId): ?array
    {
        $plan = self::visiblePlanQuery($scope)
            ->leftJoin('department', 'internship_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'internship_plan.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'internship_plan.grade_id', '=', 'grade_list.grade_id')
            ->where('internship_plan.id', $planId)
            ->first([
                'internship_plan.*', 'grade_list.grade_name',
                'department.dep_name', 'profession.profession_name',
            ]);
        if (!$plan) {
            return null;
        }

        $arrangements = self::visibleArrangementQuery($scope)
            ->where('arrangement.plan_id', $planId)
            ->orderBy('arrangement.start_date')
            ->orderBy('arrangement.id')
            ->get([
                'arrangement.id', 'arrangement.uuid', 'arrangement.title', 'arrangement.name',
                'arrangement.type', 'arrangement.task_no', 'arrangement.batch_no',
                'arrangement.teacher_id', 'arrangement.start_date', 'arrangement.end_date',
                'arrangement.location', 'arrangement.required_journal_count', 'arrangement.status',
            ]);
        $arrangementIds = self::intValues($arrangements->pluck('id'));
        $classes = self::taskClasses($arrangementIds);
        $students = self::taskStudents($scope, $arrangementIds);
        $materials = self::currentMaterialsForPlan($planId);
        $sources = self::sourceStateData($planId, self::rows($arrangements), $students, $classes);
        $materialMap = [];
        foreach ($materials as $material) {
            $materialMap[self::materialMapKey($material)] = $material;
        }

        $planRow = self::row($plan);
        $planRow['materials'] = [
            self::materialState('plan', ['plan_id' => $planId], $materialMap, $sources),
            self::materialState('syllabus', ['plan_id' => $planId], $materialMap, $sources),
            self::materialState('guide', ['plan_id' => $planId], $materialMap, $sources),
        ];

        $taskRows = [];
        foreach ($arrangements as $arrangement) {
            $arrangementRow = self::row($arrangement);
            $arrangementId = (int) $arrangement->id;
            $arrangementRow['materials'] = [
                self::materialState('implementation_sheet', ['plan_id' => $planId, 'arrangement_id' => $arrangementId], $materialMap, $sources),
                self::materialState('registration', ['plan_id' => $planId, 'arrangement_id' => $arrangementId], $materialMap, $sources),
                self::materialState('teacher_work_report', ['plan_id' => $planId, 'arrangement_id' => $arrangementId], $materialMap, $sources),
            ];
            $arrangementRow['classes'] = [];
            foreach (($scope['role_type'] ?? '') === 'student' ? [] : ($classes[$arrangementId] ?? []) as $class) {
                $class['materials'] = [self::materialState('score_register', [
                    'plan_id' => $planId,
                    'arrangement_id' => $arrangementId,
                    'class_id' => (int) $class['class_id'],
                ], $materialMap, $sources)];
                $arrangementRow['classes'][] = $class;
            }
            $arrangementRow['students'] = [];
            foreach ($students[$arrangementId] ?? [] as $student) {
                $target = [
                    'plan_id' => $planId,
                    'arrangement_id' => $arrangementId,
                    'student_id' => (int) $student['student_id'],
                ];
                $student['materials'] = [
                    self::materialState('journal', $target, $materialMap, $sources),
                    self::materialState((string) $arrangement->type === 'graduation' ? 'graduation_report' : 'report', $target, $materialMap, $sources),
                    self::materialState('safety_commitment', $target, $materialMap, $sources),
                ];
                if ((string) $arrangement->type === 'graduation') {
                    $student['materials'][] = self::materialState('graduation_appraisal', $target, $materialMap, $sources);
                }
                $student['compliance'] = self::studentComplianceState($arrangementId, (int) $student['student_id'], $sources);
                $arrangementRow['students'][] = $student;
            }
            $arrangementRow['compliance'] = self::taskComplianceState($arrangementId, $sources);
            $taskRows[] = $arrangementRow;
        }

        return [
            'plan' => $planRow,
            'arrangements' => $taskRows,
            'materials' => self::materialDefinitions(),
        ];
    }

    public static function targetContext(array $scope, array $target): ?array
    {
        $planId = (int) ($target['plan_id'] ?? 0);
        if ($planId <= 0 || !self::visiblePlanQuery($scope)->where('internship_plan.id', $planId)->exists()) {
            return null;
        }

        $arrangementId = (int) ($target['arrangement_id'] ?? 0);
        if ($arrangementId > 0 && !self::visibleArrangementQuery($scope)
            ->where('arrangement.id', $arrangementId)
            ->where('arrangement.plan_id', $planId)
            ->exists()) {
            return null;
        }

        $studentId = (int) ($target['student_id'] ?? 0);
        if ($studentId > 0 && !self::visibleStudentTaskQuery($scope)
            ->where('pair.arrangement_id', $arrangementId)
            ->where('pair.student_id', $studentId)
            ->exists()) {
            return null;
        }

        $classId = (int) ($target['class_id'] ?? 0);
        if ($classId > 0 && !self::queryTable('internship_task_class')
            ->where('arrangement_id', $arrangementId)
            ->where('class_id', $classId)
            ->whereIn('status', ['active', 'enabled'])
            ->whereNull('deleted_at')
            ->exists()) {
            return null;
        }

        return [
            'plan_id' => $planId,
            'arrangement_id' => $arrangementId ?: null,
            'student_id' => $studentId ?: null,
            'class_id' => $classId ?: null,
        ];
    }

    public static function currentMaterial(string $materialType, array $target, bool $lock = false): ?object
    {
        $query = self::materialTargetQuery($materialType, $target)
            ->whereIn('status', ['draft', 'submitted', 'archived'])
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderByDesc('id')->first();
    }

    public static function materialById(array $scope, int $id): ?array
    {
        $row = self::materialFileQuery()
            ->where('internship_archive_material.id', $id)
            ->whereNull('internship_archive_material.deleted_at')
            ->first(self::materialColumns());
        if (!$row || !self::targetContext($scope, self::row($row))) {
            return null;
        }

        return self::materialRow($row);
    }

    public static function materialHistory(array $scope, int $id): array
    {
        $material = self::materialById($scope, $id);
        if (!$material) {
            return [];
        }

        return self::materialFileQuery()
            ->where('internship_archive_material.material_type', $material['material_type'])
            ->where('internship_archive_material.scope_key', self::scopeKey($material))
            ->orderByDesc('internship_archive_material.archive_version')
            ->orderByDesc('internship_archive_material.id')
            ->get(self::materialColumns())
            ->map(static fn ($row): array => self::materialRow($row))
            ->all();
    }

    public static function insertMaterial(array $values): int
    {
        return (int) self::queryTable('internship_archive_material')->insertGetId($values);
    }

    public static function updateMaterial(int $id, array $values): int
    {
        return (int) self::queryTable('internship_archive_material')->where('id', $id)->update($values);
    }

    public static function fileExists(int $fileId): bool
    {
        return $fileId > 0 && self::queryTable('file')
            ->where('id', $fileId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->exists();
    }

    /** 返回材料是否具备符合归档规则的文件 */
    public static function archiveFileReady(string $materialType, int $generatedFileId, int $signedFileId): array
    {
        $generatedReady = self::fileExists($generatedFileId);
        $signedReady = self::fileExists($signedFileId);
        return self::archiveFilePolicyState($materialType, $generatedReady, $signedReady);
    }

    private static function archiveFilePolicyState(string $materialType, bool $generatedReady, bool $signedReady): array
    {
        if (in_array($materialType, self::GENERATED_FILE_REQUIRED_MATERIALS, true)) {
            return [
                'ready' => $generatedReady,
                'text' => $generatedReady ? '系统生成件有效' : '必须先生成并冻结系统文件',
            ];
        }

        return [
            'ready' => $generatedReady || $signedReady,
            'text' => $generatedReady || $signedReady ? '归档文件有效' : '缺少生成件或定稿件',
        ];
    }

    public static function nextArchiveVersion(string $materialType, array $target): int
    {
        $version = self::materialTargetQuery($materialType, $target)->max('archive_version');
        return max(1, (int) $version + 1);
    }

    public static function sourceReady(string $materialType, array $target): array
    {
        $planId = (int) ($target['plan_id'] ?? 0);
        $arrangementId = (int) ($target['arrangement_id'] ?? 0);
        $studentId = (int) ($target['student_id'] ?? 0);
        $classId = (int) ($target['class_id'] ?? 0);

        return match ($materialType) {
            'plan' => self::simpleSourceState('internship_plan', ['id' => $planId], ['accept', 'enabled']),
            'implementation_sheet' => self::simpleSourceState('implementation_sheet', ['arrangement_id' => $arrangementId], ['confirmed', 'accept', 'enabled']),
            'syllabus', 'guide' => self::syllabusSourceState($planId, $arrangementId, $materialType),
            'registration' => self::countSourceState('pair', [
                'arrangement_id' => $arrangementId,
                'type' => 'internship',
                'status' => 'active',
            ]),
            'teacher_work_report' => self::simpleSourceState('teacher_work_report', ['arrangement_id' => $arrangementId], ['accept', 'enabled']),
            'journal' => self::journalSourceState($studentId, $arrangementId),
            'report' => self::reportSourceState($studentId, $arrangementId, 'general'),
            'graduation_report' => self::reportSourceState($studentId, $arrangementId, 'graduation'),
            'graduation_appraisal' => self::appraisalSourceState($studentId, $arrangementId),
            'score_register' => self::scoreRegisterSourceState($arrangementId, $classId),
            'safety_commitment' => self::safetySourceState($studentId, $arrangementId),
            default => ['ready' => false, 'text' => '无数据来源'],
        };
    }

    /** 返回材料是否满足文件生成条件 */
    public static function generationReady(string $materialType, array $target): array
    {
        if ($materialType === 'graduation_appraisal') {
            return self::simpleSourceState('internship_graduation_appraisal', [
                'student_id' => (int) ($target['student_id'] ?? 0),
                'arrangement_id' => (int) ($target['arrangement_id'] ?? 0),
            ], ['accept']);
        }

        return self::sourceReady($materialType, $target);
    }

    public static function saveGraduationAppraisal(array $values, int $id = 0): int
    {
        if ($id > 0) {
            self::queryTable('internship_graduation_appraisal')->where('id', $id)->update($values);
            return $id;
        }

        return (int) self::queryTable('internship_graduation_appraisal')->insertGetId($values);
    }

    public static function graduationAppraisalId(int $studentId, int $arrangementId): int
    {
        return (int) (self::queryTable('internship_graduation_appraisal')
            ->where('student_id', $studentId)
            ->where('arrangement_id', $arrangementId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->value('id') ?: 0);
    }

    public static function graduationAppraisalById(array $scope, int $id, bool $lock = false): ?object
    {
        $query = self::queryTable('internship_graduation_appraisal')
            ->where('id', $id)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (!$row || !self::targetContext($scope, [
            'plan_id' => self::planIdByArrangement((int) $row->arrangement_id),
            'arrangement_id' => (int) $row->arrangement_id,
            'student_id' => (int) $row->student_id,
        ])) {
            return null;
        }

        return $row;
    }

    /** 查询学生任务的毕业实习成绩鉴定 */
    public static function graduationAppraisalForTask(array $scope, int $studentId, int $arrangementId): ?array
    {
        $row = self::queryTable('internship_graduation_appraisal')
            ->leftJoin('file as appraisal_file', 'internship_graduation_appraisal.attachment_id', '=', 'appraisal_file.id')
            ->where('internship_graduation_appraisal.student_id', $studentId)
            ->where('internship_graduation_appraisal.arrangement_id', $arrangementId)
            ->whereNull('internship_graduation_appraisal.deleted_at')
            ->orderByDesc('internship_graduation_appraisal.id')
            ->first([
                'internship_graduation_appraisal.*',
                'appraisal_file.name as attachment_name',
                'appraisal_file.download_name as attachment_download_name',
                'appraisal_file.url as attachment_url',
            ]);
        if (!$row || !self::targetContext($scope, [
            'plan_id' => self::planIdByArrangement($arrangementId),
            'arrangement_id' => $arrangementId,
            'student_id' => $studentId,
        ])) {
            return null;
        }

        $item = self::decodeGenerationRow(self::row($row));
        $item['attachment'] = empty($item['attachment_id']) ? null : [
            'id' => (int) $item['attachment_id'],
            'name' => $item['attachment_download_name'] ?: $item['attachment_name'],
            'url' => $item['attachment_url'],
        ];

        return $item;
    }

    public static function planIdByArrangement(int $arrangementId): int
    {
        return (int) (self::queryTable('arrangement')->where('id', $arrangementId)->value('plan_id') ?: 0);
    }

    public static function arrangementType(int $arrangementId): string
    {
        return (string) (self::queryTable('arrangement')
            ->where('id', $arrangementId)
            ->whereNull('deleted_at')
            ->value('type') ?: '');
    }

    public static function insertRecording(string $table, array $values): int
    {
        self::ensureRecordingTable($table);
        return (int) self::queryTable($table)->insertGetId($values);
    }

    public static function sourceFileId(string $materialType, array $target): int
    {
        $arrangementId = (int) ($target['arrangement_id'] ?? 0);
        $studentId = (int) ($target['student_id'] ?? 0);

        return match ($materialType) {
            'syllabus', 'guide' => (int) (self::queryTable('syllabus_guide')
                ->where('document_type', $materialType)
                ->where(function ($query) use ($target, $arrangementId): void {
                    $query->where('plan_id', (int) ($target['plan_id'] ?? 0));
                    if ($arrangementId > 0) {
                        $query->orWhere('arrangement_id', $arrangementId);
                    }
                })
                ->whereNull('deleted_at')->orderByDesc('id')->value('file_id') ?: 0),
            'teacher_work_report' => (int) (self::queryTable('teacher_work_report')
                ->where('arrangement_id', $arrangementId)->whereNull('deleted_at')
                ->orderByDesc('id')->value('attachment_id') ?: 0),
            'graduation_appraisal' => (int) (self::queryTable('internship_graduation_appraisal')
                ->where('student_id', $studentId)->where('arrangement_id', $arrangementId)
                ->whereNull('deleted_at')->orderByDesc('id')->value('attachment_id') ?: 0),
            'safety_commitment' => (int) (self::queryTable('safety_letter_sign')
                ->where('student_id', $studentId)->where('arrangement_id', $arrangementId)
                ->whereNull('deleted_at')->orderByDesc('id')->value('signature_file_id') ?: 0),
            default => 0,
        };
    }

    /** 返回档案文件生成所需的结构化数据 */
    public static function generationData(string $materialType, array $target): array
    {
        $planId = (int) ($target['plan_id'] ?? 0);
        $arrangementId = (int) ($target['arrangement_id'] ?? 0);
        $studentId = (int) ($target['student_id'] ?? 0);
        $classId = (int) ($target['class_id'] ?? 0);

        $plan = self::queryTable('internship_plan')
            ->leftJoin('department', 'internship_plan.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'internship_plan.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'internship_plan.grade_id', '=', 'grade_list.grade_id')
            ->where('internship_plan.id', $planId)
            ->whereNull('internship_plan.deleted_at')
            ->first([
                'internship_plan.*', 'department.dep_name', 'profession.profession_name', 'grade_list.grade_name',
            ]);
        if (!$plan) {
            return [];
        }

        $task = $arrangementId > 0 ? self::queryTable('arrangement')
            ->leftJoin('teacher_list', 'arrangement.teacher_id', '=', 'teacher_list.teacher_id')
            ->leftJoin('base', 'arrangement.base_id', '=', 'base.id')
            ->where('arrangement.id', $arrangementId)
            ->where('arrangement.plan_id', $planId)
            ->whereNull('arrangement.deleted_at')
            ->first([
                'arrangement.*', 'teacher_list.teacher_name', 'teacher_list.teacher_num', 'base.name as base_name',
            ]) : null;
        $student = $studentId > 0 ? self::queryTable('students')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->where('students.student_id', $studentId)
            ->whereNull('students.deleted_at')
            ->first([
                'students.*', 'class.class_name', 'department.dep_name', 'profession.profession_name', 'grade_list.grade_name',
            ]) : null;
        $class = $classId > 0 ? self::queryTable('class')
            ->where('class_id', $classId)->whereNull('deleted_at')->first() : null;

        $data = [
            'plan' => self::row($plan),
            'task' => $task ? self::row($task) : [],
            'student' => $student ? self::row($student) : [],
            'class' => $class ? self::row($class) : [],
        ];

        if ($materialType === 'plan') {
            $data['tasks'] = self::rows(self::queryTable('arrangement')
                ->leftJoin('teacher_list', 'arrangement.teacher_id', '=', 'teacher_list.teacher_id')
                ->where('arrangement.plan_id', $planId)->where('arrangement.status', '<>', 'changed')
                ->whereNull('arrangement.deleted_at')->orderBy('arrangement.start_date')->orderBy('arrangement.id')
                ->get(['arrangement.*', 'teacher_list.teacher_name']));
        } elseif (in_array($materialType, ['syllabus', 'guide'], true)) {
            $row = self::queryTable('syllabus_guide')->where('document_type', $materialType)
                ->where(function ($query) use ($arrangementId, $planId): void {
                    $query->where('plan_id', $planId);
                    if ($arrangementId > 0) {
                        $query->orWhere('arrangement_id', $arrangementId);
                    }
                })->whereNull('deleted_at')->orderByDesc('id')->first();
            $data['document'] = $row ? self::decodeGenerationRow(self::row($row)) : [];
        } elseif ($materialType === 'registration') {
            $data['students'] = self::rows(self::queryTable('pair')
                ->join('students', 'pair.student_id', '=', 'students.student_id')
                ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
                ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
                ->where('pair.arrangement_id', $arrangementId)->where('pair.type', 'internship')
                ->where('pair.status', 'active')->whereNull('pair.deleted_at')->whereNull('students.deleted_at')
                ->orderBy('students.student_num')->get([
                    'pair.student_id', 'students.name as student_name', 'students.student_num',
                    'class.class_name', 'profession.profession_name',
                ]));
            $data['report_students'] = self::ids(self::queryTable('report')->where('arrangement_id', $arrangementId)
                ->where('status', 'accept')->whereNull('deleted_at')->pluck('student_id')->all());
            $data['sign_counts'] = self::countByStudent(self::rows(self::queryTable('sign_in')
                ->where('entity_type', 'internship')->where('entity_id', $arrangementId)
                ->whereNull('deleted_at')->get(['student_id'])));
        } elseif ($materialType === 'teacher_work_report') {
            $row = self::queryTable('teacher_work_report')->where('arrangement_id', $arrangementId)
                ->whereNull('deleted_at')->orderByDesc('id')->first();
            $data['document'] = $row ? self::decodeGenerationRow(self::row($row)) : [];
        } elseif ($materialType === 'journal') {
            $data['journals'] = array_map(
                static fn (array $row): array => self::decodeGenerationRow($row),
                self::rows(self::queryTable('journal')->where('student_id', $studentId)
                    ->where('entity_type', 'internship')->where('entity_id', $arrangementId)
                    ->whereIn('status', ['accept', 'enabled'])->whereNull('deleted_at')
                    ->orderBy('date')->orderBy('id')->get())
            );
        } elseif (in_array($materialType, ['report', 'graduation_report'], true)) {
            $reportType = $materialType === 'graduation_report' ? 'graduation' : 'general';
            $row = self::queryTable('report')->where('student_id', $studentId)
                ->where('arrangement_id', $arrangementId)->where('report_type', $reportType)
                ->whereNull('deleted_at')->orderByDesc('id')->first();
            $data['document'] = $row ? self::decodeGenerationRow(self::row($row)) : [];
        } elseif ($materialType === 'graduation_appraisal') {
            $row = self::queryTable('internship_graduation_appraisal')->where('student_id', $studentId)
                ->where('arrangement_id', $arrangementId)->whereNull('deleted_at')->orderByDesc('id')->first();
            $data['document'] = $row ? self::decodeGenerationRow(self::row($row)) : [];
        } elseif ($materialType === 'score_register') {
            $data['scores'] = self::rows(self::queryTable('pair')
                ->join('students', 'pair.student_id', '=', 'students.student_id')
                ->leftJoin('score', function ($join) use ($arrangementId): void {
                    $join->on('pair.student_id', '=', 'score.student_id')
                        ->where('score.arrangement_id', '=', $arrangementId)
                        ->whereNull('score.deleted_at');
                })
                ->where('pair.arrangement_id', $arrangementId)->where('pair.type', 'internship')
                ->where('pair.status', 'active')->where('students.class_id', $classId)
                ->whereNull('pair.deleted_at')->whereNull('students.deleted_at')
                ->orderBy('students.student_num')->get([
                    'students.student_id', 'students.name as student_name', 'students.student_num',
                    'score.sign_in_score', 'score.journal_score', 'score.report_score',
                    'score.enterprise_score', 'score.final_score', 'score.comment',
                ]));
        }

        return $data;
    }

    private static function visiblePlanQuery(array $scope): mixed
    {
        $query = self::queryTable('internship_plan')->whereNull('internship_plan.deleted_at');
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin') {
            self::whereInOrDeny($query, 'internship_plan.dep_id', $scope['dep_ids'] ?? []);
        } elseif ($roleType === 'profession_admin') {
            self::whereInOrDeny($query, 'internship_plan.profession_id', $scope['profession_ids'] ?? []);
        } elseif (in_array($roleType, ['teacher', 'student', 'enterprise'], true)) {
            $arrangementIds = $roleType === 'teacher'
                ? ($scope['visible_arrangement_ids'] ?? [])
                : ($scope['owned_arrangement_ids'] ?? []);
            $arrangementIds = self::ids($arrangementIds);
            if (!$arrangementIds) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereExists(function ($subQuery) use ($arrangementIds): void {
                    $subQuery->selectRaw('1')->from('arrangement')
                        ->whereColumn('arrangement.plan_id', 'internship_plan.id')
                        ->whereIn('arrangement.id', $arrangementIds)
                        ->whereNull('arrangement.deleted_at');
                });
            }
        } elseif (!in_array($roleType, ['super_admin', 'school_admin'], true)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private static function visibleArrangementQuery(array $scope): mixed
    {
        $query = self::queryTable('arrangement')
            ->where('arrangement.status', '<>', 'changed')
            ->whereNull('arrangement.deleted_at');
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin') {
            self::whereInOrDeny($query, 'arrangement.dep_id', $scope['dep_ids'] ?? []);
        } elseif ($roleType === 'profession_admin') {
            self::whereInOrDeny($query, 'arrangement.profession_id', $scope['profession_ids'] ?? []);
        } elseif (in_array($roleType, ['teacher', 'student', 'enterprise'], true)) {
            $ids = $roleType === 'teacher' ? ($scope['visible_arrangement_ids'] ?? []) : ($scope['owned_arrangement_ids'] ?? []);
            self::whereInOrDeny($query, 'arrangement.id', $ids);
        } elseif (!in_array($roleType, ['super_admin', 'school_admin'], true)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private static function visibleStudentTaskQuery(array $scope): mixed
    {
        $query = self::queryTable('pair')
            ->where('pair.type', 'internship')
            ->where('pair.status', 'active')
            ->whereNull('pair.deleted_at');
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'teacher') {
            $teacherId = (int) ($scope['teacher_id'] ?? 0);
            $query->where(function ($builder) use ($teacherId): void {
                $builder->where('pair.teacher_id', $teacherId)->orWhere('pair.second_teacher_id', $teacherId);
            });
        } elseif ($roleType === 'student') {
            $query->where('pair.student_id', (int) ($scope['student_id'] ?? 0));
        } elseif (in_array($roleType, ['college_admin', 'profession_admin'], true)) {
            self::whereInOrDeny($query, 'pair.student_id', $scope['visible_student_ids'] ?? []);
        } elseif ($roleType === 'enterprise') {
            self::whereInOrDeny($query, 'pair.arrangement_id', $scope['owned_arrangement_ids'] ?? []);
        } elseif (!in_array($roleType, ['super_admin', 'school_admin'], true)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private static function appendPlanProgress(array $items, array $scope): array
    {
        $planIds = self::ids(array_column($items, 'id'));
        if (!$planIds) {
            return $items;
        }
        $visibleArrangements = self::visibleArrangementQuery($scope)->whereIn('arrangement.plan_id', $planIds);
        $visibleArrangementIds = self::intValues((clone $visibleArrangements)->pluck('arrangement.id'));
        $taskCounts = self::countMap((clone $visibleArrangements)
            ->selectRaw('plan_id AS plan_key, COUNT(*) AS total')->groupBy('plan_id')->get());
        $visibleStudents = self::visibleStudentTaskQuery($scope)
            ->join('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->whereIn('pair.arrangement_id', $visibleArrangementIds)
            ->whereNull('arrangement.deleted_at');
        $studentCounts = self::countMap((clone $visibleStudents)
            ->selectRaw('arrangement.plan_id AS plan_key, COUNT(DISTINCT pair.student_id, pair.arrangement_id) AS total')
            ->groupBy('arrangement.plan_id')->get());
        $graduationStudentCounts = self::countMap((clone $visibleStudents)->where('arrangement.type', 'graduation')
            ->selectRaw('arrangement.plan_id AS plan_key, COUNT(DISTINCT pair.student_id, pair.arrangement_id) AS total')
            ->groupBy('arrangement.plan_id')->get());
        $classCounts = self::countMap(self::queryTable('internship_task_class')->join('arrangement', 'internship_task_class.arrangement_id', '=', 'arrangement.id')
            ->whereIn('internship_task_class.arrangement_id', $visibleArrangementIds)->whereIn('internship_task_class.status', ['active', 'enabled'])
            ->whereNull('internship_task_class.deleted_at')->whereNull('arrangement.deleted_at')
            ->selectRaw('arrangement.plan_id AS plan_key, COUNT(*) AS total')->groupBy('arrangement.plan_id')->get());
        $archiveQuery = self::queryTable('internship_archive_material')->whereIn('plan_id', $planIds)
            ->where('status', 'archived')->whereNull('deleted_at');
        if (in_array((string) ($scope['role_type'] ?? ''), ['teacher', 'enterprise'], true)) {
            $archiveQuery->where(function ($builder) use ($visibleArrangementIds): void {
                $builder->where('scope_type', 'plan');
                if ($visibleArrangementIds) {
                    $builder->orWhereIn('arrangement_id', $visibleArrangementIds);
                }
            });
        }
        $archiveQuery->where(function ($query): void {
            $query->where(function ($builder): void {
                $builder->whereIn('material_type', ['plan', 'implementation_sheet', 'registration', 'score_register'])
                    ->whereExists(function ($fileQuery): void {
                        $fileQuery->selectRaw('1')->from('file')
                            ->whereColumn('file.id', 'internship_archive_material.generated_file_id')
                            ->where('file.status', 'enabled')->whereNull('file.deleted_at');
                    });
            })->orWhere(function ($builder): void {
                $builder->whereNotIn('material_type', ['plan', 'implementation_sheet', 'registration', 'score_register'])
                    ->where(function ($fileQuery): void {
                        $fileQuery->whereExists(function ($generatedQuery): void {
                            $generatedQuery->selectRaw('1')->from('file')
                                ->whereColumn('file.id', 'internship_archive_material.generated_file_id')
                                ->where('file.status', 'enabled')->whereNull('file.deleted_at');
                        })->orWhereExists(function ($signedQuery): void {
                            $signedQuery->selectRaw('1')->from('file')
                                ->whereColumn('file.id', 'internship_archive_material.signed_file_id')
                                ->where('file.status', 'enabled')->whereNull('file.deleted_at');
                        });
                    });
            });
        });
        $archiveCounts = self::countMap($archiveQuery
            ->selectRaw('plan_id AS plan_key, COUNT(*) AS total')->groupBy('plan_id')->get());

        foreach ($items as &$item) {
            $id = (int) $item['id'];
            $taskCount = (int) ($taskCounts[$id] ?? 0);
            $studentCount = (int) ($studentCounts[$id] ?? 0);
            $classCount = (int) ($classCounts[$id] ?? 0);
            $graduationStudentCount = (int) ($graduationStudentCounts[$id] ?? 0);
            $required = 3 + $taskCount * 3 + $classCount + $studentCount * 3 + $graduationStudentCount;
            $archived = min($required, (int) ($archiveCounts[$id] ?? 0));
            $item['task_count'] = $taskCount;
            $item['student_task_count'] = $studentCount;
            $item['class_count'] = $classCount;
            $item['required_count'] = $required;
            $item['archived_count'] = $archived;
            $item['material_progress'] = "{$archived}/{$required}";
            $item['archive_status'] = $required > 0 && $archived === $required ? 'complete' : 'incomplete';
            $item['archive_status_text'] = $item['archive_status'] === 'complete' ? '完整' : '待补齐';
        }
        unset($item);

        return $items;
    }

    private static function taskClasses(array $arrangementIds): array
    {
        if (!$arrangementIds) {
            return [];
        }
        $rows = self::queryTable('internship_task_class')
            ->leftJoin('class', 'internship_task_class.class_id', '=', 'class.class_id')
            ->whereIn('internship_task_class.arrangement_id', $arrangementIds)
            ->whereIn('internship_task_class.status', ['active', 'enabled'])
            ->whereNull('internship_task_class.deleted_at')
            ->orderBy('class.class_name')
            ->get(['internship_task_class.arrangement_id', 'internship_task_class.class_id', 'class.class_name', 'class.class_num']);
        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row->arrangement_id][] = self::row($row);
        }

        return $groups;
    }

    private static function taskStudents(array $scope, array $arrangementIds): array
    {
        if (!$arrangementIds) {
            return [];
        }
        $rows = self::visibleStudentTaskQuery($scope)
            ->leftJoin('students', 'pair.student_id', '=', 'students.student_id')
            ->leftJoin('class', 'students.class_id', '=', 'class.class_id')
            ->whereIn('pair.arrangement_id', $arrangementIds)
            ->orderBy('students.student_num')
            ->get([
                'pair.arrangement_id', 'pair.student_id', 'students.name as student_name',
                'students.student_num', 'students.class_id', 'class.class_name',
            ]);
        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row->arrangement_id][] = self::row($row);
        }

        return $groups;
    }

    private static function currentMaterialsForPlan(int $planId): array
    {
        return self::materialFileQuery()
            ->where('internship_archive_material.plan_id', $planId)
            ->whereIn('internship_archive_material.status', ['draft', 'submitted', 'archived'])
            ->whereNull('internship_archive_material.deleted_at')
            ->get(self::materialColumns())
            ->map(static fn ($row): array => self::materialRow($row))
            ->all();
    }

    /** 批量返回多个计划的当前档案材料 */
    private static function currentMaterialsForPlans(array $planIds): array
    {
        $planIds = self::ids($planIds);
        if (!$planIds) {
            return [];
        }

        return self::materialFileQuery()
            ->whereIn('internship_archive_material.plan_id', $planIds)
            ->whereIn('internship_archive_material.status', ['draft', 'submitted', 'archived'])
            ->whereNull('internship_archive_material.deleted_at')
            ->get(self::materialColumns())
            ->map(static fn ($row): array => self::materialRow($row))
            ->all();
    }

    /** 批量读取详情页所需的材料源状态 */
    private static function sourceStateData(int $planId, array $arrangements, array $students, array $classes): array
    {
        $arrangements = self::normalizeArrangementRows($arrangements);
        $arrangementIds = self::ids(array_column($arrangements, 'id'));
        $studentIds = [];
        foreach ($students as $rows) {
            $studentIds = array_merge($studentIds, array_column($rows, 'student_id'));
        }
        $studentIds = self::ids($studentIds);

        $planStatus = (string) (self::queryTable('internship_plan')->where('id', $planId)->value('status') ?: '');
        $implementationRows = self::rows(self::queryTable('implementation_sheet')
            ->whereIn('arrangement_id', $arrangementIds)->whereNull('deleted_at')
            ->orderByDesc('id')->get(['arrangement_id', 'status']));
        $documentRows = self::rows(self::queryTable('syllabus_guide')
            ->where(function ($query) use ($arrangementIds, $planId): void {
                $query->where('plan_id', $planId);
                if ($arrangementIds) {
                    $query->orWhereIn('arrangement_id', $arrangementIds);
                }
            })
            ->whereIn('document_type', ['syllabus', 'guide'])->whereNull('deleted_at')
            ->orderByDesc('id')->get(['plan_id', 'arrangement_id', 'document_type', 'status', 'file_id', 'content']));
        $pairRows = self::rows(self::queryTable('pair')->whereIn('arrangement_id', $arrangementIds)
            ->where('type', 'internship')->where('status', 'active')->whereNull('deleted_at')
            ->get(['arrangement_id', 'student_id']));
        $teacherReportRows = self::rows(self::queryTable('teacher_work_report')
            ->whereIn('arrangement_id', $arrangementIds)->whereNull('deleted_at')
            ->orderByDesc('id')->get(['arrangement_id', 'status', 'attachment_id']));
        $journalRows = self::rows(self::queryTable('journal')->whereIn('entity_id', $arrangementIds)
            ->whereIn('student_id', $studentIds)->where('entity_type', 'internship')
            ->whereIn('status', ['accept', 'enabled'])->whereNull('deleted_at')
            ->get(['entity_id as arrangement_id', 'student_id']));
        $reportRows = self::rows(self::queryTable('report')->whereIn('arrangement_id', $arrangementIds)
            ->whereIn('student_id', $studentIds)->whereNull('deleted_at')
            ->orderByDesc('id')->get(['arrangement_id', 'student_id', 'report_type', 'status']));
        $appraisalRows = self::rows(self::queryTable('internship_graduation_appraisal')
            ->whereIn('arrangement_id', $arrangementIds)->whereIn('student_id', $studentIds)
            ->whereNull('deleted_at')->orderByDesc('id')
            ->get(['id', 'arrangement_id', 'student_id', 'status', 'attachment_id']));
        $scoreRows = self::rows(self::queryTable('score')->whereIn('arrangement_id', $arrangementIds)
            ->whereIn('student_id', $studentIds)->whereNull('deleted_at')
            ->get(['arrangement_id', 'student_id', 'final_score']));
        $safetyRows = self::rows(self::queryTable('safety_letter_sign')->whereIn('arrangement_id', $arrangementIds)
            ->whereIn('student_id', $studentIds)->whereNull('deleted_at')->orderByDesc('id')
            ->get(['arrangement_id', 'student_id', 'status', 'signed_at', 'signature_file_id']));
        $insuranceRows = self::rows(self::queryTable('insurance')->whereIn('arrangement_id', $arrangementIds)
            ->whereIn('student_id', $studentIds)->whereNull('deleted_at')->orderByDesc('id')
            ->get(['id', 'arrangement_id', 'student_id', 'status', 'policy_number', 'start_date', 'end_date', 'attachment_id']));
        $inspectionRows = self::rows(self::queryTable('inspection_record')->whereIn('arrangement_id', $arrangementIds)
            ->whereNull('deleted_at')->orderByDesc('id')
            ->get(['id', 'arrangement_id', 'student_id', 'result', 'status', 'remark', 'created_at']));

        $fileIds = [];
        foreach (array_merge($appraisalRows, $safetyRows, $insuranceRows) as $row) {
            $fileIds[] = (int) ($row['attachment_id'] ?? $row['signature_file_id'] ?? 0);
        }
        $validFileIds = self::ids(self::queryTable('file')->whereIn('id', self::ids($fileIds))
            ->where('status', 'enabled')->whereNull('deleted_at')->pluck('id')->all());

        return [
            'plan_id' => $planId,
            'plan_status' => $planStatus,
            'arrangement_ids' => $arrangementIds,
            'arrangements' => self::keyBy($arrangements, 'id'),
            'students' => $students,
            'classes' => $classes,
            'implementation' => self::keyBy($implementationRows, 'arrangement_id'),
            'documents' => $documentRows,
            'pair_counts' => self::countBy($pairRows, 'arrangement_id'),
            'teacher_reports' => self::keyBy($teacherReportRows, 'arrangement_id'),
            'journal_counts' => self::countByPair($journalRows),
            'reports' => self::keyByTriple($reportRows, 'arrangement_id', 'student_id', 'report_type'),
            'appraisals' => self::keyByPair($appraisalRows),
            'scores' => self::keyByPair($scoreRows),
            'safety' => self::keyByPair($safetyRows),
            'insurances' => self::groupByPair($insuranceRows),
            'inspections' => self::groupByArrangement($inspectionRows),
            'valid_file_ids' => array_fill_keys($validFileIds, true),
        ];
    }

    private static function materialState(string $materialType, array $target, array $materialMap, array $sources): array
    {
        $definition = self::materialDefinition($materialType) ?? [];
        $target['scope_type'] = $definition['scope_type'] ?? '';
        $key = self::materialMapKey(array_merge($target, ['material_type' => $materialType]));
        $material = $materialMap[$key] ?? null;
        $source = self::sourceStateFromData($materialType, $target, $sources);
        $file = $material
            ? self::archiveFilePolicyState(
                $materialType,
                !empty($material['generated_file']),
                !empty($material['signed_file'])
            )
            : ['ready' => false, 'text' => '缺少归档文件'];
        $archived = (string) ($material['status'] ?? '') === 'archived'
            && !empty($source['ready'])
            && !empty($file['ready']);

        return array_merge($definition, $target, [
            'material' => $material,
            'archive_status' => $archived ? 'archived' : 'missing',
            'archive_status_text' => $archived ? '已归档' : '待补齐',
            'source_ready' => (bool) ($source['ready'] ?? false),
            'source_text' => (string) ($source['text'] ?? '待完善'),
            'source_id' => (int) ($source['source_id'] ?? 0),
            'source_status' => (string) ($source['source_status'] ?? ''),
            'archive_file_ready' => (bool) $file['ready'],
            'archive_file_text' => (string) $file['text'],
        ]);
    }

    /** 组装学生单个任务的档案状态 */
    private static function studentMaterialRow(array $row, array $materialMap, array $sources): array
    {
        $target = [
            'plan_id' => (int) $row['plan_id'],
            'arrangement_id' => (int) $row['arrangement_id'],
            'student_id' => (int) $row['student_id'],
        ];
        $planTarget = ['plan_id' => $target['plan_id']];
        $arrangementTarget = [
            'plan_id' => $target['plan_id'],
            'arrangement_id' => $target['arrangement_id'],
        ];
        $materials = [
            self::materialState('plan', $planTarget, $materialMap, $sources),
            self::materialState('syllabus', $planTarget, $materialMap, $sources),
            self::materialState('guide', $planTarget, $materialMap, $sources),
            self::materialState('implementation_sheet', $arrangementTarget, $materialMap, $sources),
            self::materialState('registration', $arrangementTarget, $materialMap, $sources),
            self::materialState('teacher_work_report', $arrangementTarget, $materialMap, $sources),
            self::materialState('journal', $target, $materialMap, $sources),
            self::materialState((string) $row['arrangement_type'] === 'graduation' ? 'graduation_report' : 'report', $target, $materialMap, $sources),
            self::materialState('safety_commitment', $target, $materialMap, $sources),
        ];
        if ((string) $row['arrangement_type'] === 'graduation') {
            $materials[] = self::materialState('graduation_appraisal', $target, $materialMap, $sources);
        }

        $archived = array_values(array_filter($materials, static fn (array $item): bool => $item['archive_status'] === 'archived'));
        $missing = array_values(array_filter($materials, static fn (array $item): bool => $item['archive_status'] !== 'archived'));
        $row['arrangement_type_text'] = self::arrangementTypeText((string) $row['arrangement_type']);
        $row['materials'] = $materials;
        $row['required_count'] = count($materials);
        $row['archived_count'] = count($archived);
        $row['material_progress'] = count($archived) . '/' . count($materials);
        $row['missing_materials'] = $missing ? implode('、', array_column($missing, 'label')) : '无';
        $row['archive_status'] = count($archived) === count($materials) ? 'complete' : 'incomplete';
        $row['archive_status_text'] = $row['archive_status'] === 'complete' ? '完整' : '待补齐';
        $row['status'] = $row['archive_status'];
        $row['compliance'] = self::studentComplianceState($target['arrangement_id'], $target['student_id'], $sources);

        return $row;
    }

    /** 返回任务级合规附加材料统计 */
    private static function taskComplianceState(int $arrangementId, array $sources): array
    {
        $students = $sources['students'][$arrangementId] ?? [];
        $required = self::insuranceRequired($arrangementId, $sources);
        $covered = 0;
        foreach ($students as $student) {
            if (self::studentInsuranceState($arrangementId, (int) ($student['student_id'] ?? 0), $sources)['ready']) {
                $covered++;
            }
        }

        $inspections = $sources['inspections'][$arrangementId] ?? [];
        $latest = $inspections[0] ?? null;

        return [
            'insurance' => [
                'required' => $required,
                'ready' => !$required || ($students && $covered === count($students)),
                'covered_count' => $covered,
                'student_count' => count($students),
                'text' => $required ? "已覆盖{$covered}/" . count($students) . '人' : '校内任务无需保险',
            ],
            'inspection' => [
                'count' => count($inspections),
                'latest_result' => (string) ($latest['result'] ?? ''),
                'latest_at' => (string) ($latest['created_at'] ?? ''),
                'text' => $inspections ? '已有' . count($inspections) . '条记录' : '暂无抽查记录',
            ],
        ];
    }

    /** 返回学生任务的合规附加材料状态 */
    private static function studentComplianceState(int $arrangementId, int $studentId, array $sources): array
    {
        $insurance = self::studentInsuranceState($arrangementId, $studentId, $sources);
        $inspections = array_values(array_filter(
            $sources['inspections'][$arrangementId] ?? [],
            static fn (array $row): bool => (int) ($row['student_id'] ?? 0) === $studentId
        ));
        $latest = $inspections[0] ?? null;

        return [
            'insurance' => $insurance,
            'inspection' => [
                'count' => count($inspections),
                'latest_result' => (string) ($latest['result'] ?? ''),
                'latest_at' => (string) ($latest['created_at'] ?? ''),
                'text' => $inspections ? '已有' . count($inspections) . '条记录' : '暂无抽查记录',
            ],
        ];
    }

    private static function studentInsuranceState(int $arrangementId, int $studentId, array $sources): array
    {
        $required = self::insuranceRequired($arrangementId, $sources);
        if (!$required) {
            return ['required' => false, 'ready' => true, 'text' => '校内任务无需保险'];
        }

        $task = $sources['arrangements'][$arrangementId] ?? [];
        $startDate = (string) ($task['start_date'] ?? '');
        $endDate = (string) ($task['end_date'] ?? '');
        foreach ($sources['insurances'][self::pairMapKey($arrangementId, $studentId)] ?? [] as $insurance) {
            $fileId = (int) ($insurance['attachment_id'] ?? 0);
            $covered = $startDate !== '' && $endDate !== ''
                && (string) ($insurance['start_date'] ?? '') <= $startDate
                && (string) ($insurance['end_date'] ?? '') >= $endDate;
            if ((string) ($insurance['status'] ?? '') === 'enabled' && $covered && isset($sources['valid_file_ids'][$fileId])) {
                return [
                    'required' => true,
                    'ready' => true,
                    'text' => '保单有效且覆盖任务时间',
                    'policy_number' => (string) ($insurance['policy_number'] ?? ''),
                    'start_date' => (string) ($insurance['start_date'] ?? ''),
                    'end_date' => (string) ($insurance['end_date'] ?? ''),
                ];
            }
        }

        return [
            'required' => true,
            'ready' => false,
            'text' => $startDate === '' || $endDate === '' ? '任务时间未完整设置，无法校验保险' : '缺少覆盖任务时间的有效保单',
        ];
    }

    private static function insuranceRequired(int $arrangementId, array $sources): bool
    {
        $type = (string) ($sources['arrangements'][$arrangementId]['type'] ?? $sources['arrangements'][$arrangementId]['arrangement_type'] ?? '');
        return in_array($type, self::EXTERNAL_ARRANGEMENT_TYPES, true);
    }

    /** 从批量数据中读取单项材料源状态 */
    private static function sourceStateFromData(string $materialType, array $target, array $sources): array
    {
        $arrangementId = (int) ($target['arrangement_id'] ?? 0);
        $studentId = (int) ($target['student_id'] ?? 0);
        $classId = (int) ($target['class_id'] ?? 0);
        $pairKey = self::pairMapKey($arrangementId, $studentId);

        return match ($materialType) {
            'plan' => self::statusSourceState((string) ($sources['plan_status'] ?? ''), ['accept', 'enabled']),
            'implementation_sheet' => self::statusSourceState((string) ($sources['implementation'][$arrangementId]['status'] ?? ''), ['confirmed', 'accept', 'enabled']),
            'syllabus', 'guide' => self::documentSourceState($sources['documents'] ?? [], $materialType),
            'registration' => self::countReadyState((int) ($sources['pair_counts'][$arrangementId] ?? 0), '待绑定学生'),
            'teacher_work_report' => self::statusSourceState((string) ($sources['teacher_reports'][$arrangementId]['status'] ?? ''), ['accept', 'enabled']),
            'journal' => self::journalStateFromData($arrangementId, $pairKey, $sources),
            'report', 'graduation_report' => self::statusSourceState((string) ($sources['reports'][$pairKey . '|' . ($materialType === 'graduation_report' ? 'graduation' : 'general')]['status'] ?? ''), ['accept'], '待提交'),
            'graduation_appraisal' => self::fileBackedState($sources['appraisals'][$pairKey] ?? null, 'attachment_id', 'accept', $sources),
            'score_register' => self::scoreRegisterStateFromData($arrangementId, $classId, $sources),
            'safety_commitment' => self::fileBackedState($sources['safety'][$pairKey] ?? null, 'signature_file_id', 'signed', $sources),
            default => ['ready' => false, 'text' => '无数据来源'],
        };
    }

    private static function statusSourceState(string $status, array $accepted, string $emptyText = '待填写'): array
    {
        return [
            'ready' => in_array($status, $accepted, true),
            'text' => $status === '' ? $emptyText : self::statusText($status),
        ];
    }

    private static function documentSourceState(array $rows, string $documentType): array
    {
        foreach ($rows as $row) {
            if ((string) ($row['document_type'] ?? '') !== $documentType) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $ready = in_array($status, ['published', 'accept', 'enabled'], true)
                && ((int) ($row['file_id'] ?? 0) > 0 || trim((string) ($row['content'] ?? '')) !== '');
            return ['ready' => $ready, 'text' => $status === '' ? '待填写' : self::statusText($status)];
        }

        return ['ready' => false, 'text' => '待填写'];
    }

    private static function countReadyState(int $count, string $emptyText): array
    {
        return ['ready' => $count > 0, 'text' => $count > 0 ? "已有{$count}条数据" : $emptyText];
    }

    private static function journalStateFromData(int $arrangementId, string $pairKey, array $sources): array
    {
        $required = max(1, (int) ($sources['arrangements'][$arrangementId]['required_journal_count'] ?? 1));
        $accepted = (int) ($sources['journal_counts'][$pairKey] ?? 0);
        return ['ready' => $accepted >= $required, 'text' => "已通过{$accepted}/{$required}篇"];
    }

    private static function fileBackedState(?array $row, string $fileKey, string $acceptedStatus, array $sources): array
    {
        if (!$row) {
            return ['ready' => false, 'text' => '待填写'];
        }
        $status = (string) ($row['status'] ?? '');
        $fileId = (int) ($row[$fileKey] ?? 0);
        $ready = $status === $acceptedStatus && isset($sources['valid_file_ids'][$fileId]);
        return [
            'ready' => $ready,
            'text' => $ready ? ($acceptedStatus === 'signed' ? '已签署并上传定稿' : '已通过') : self::statusText($status),
            'source_id' => (int) ($row['id'] ?? 0),
            'source_status' => $status,
        ];
    }

    private static function scoreRegisterStateFromData(int $arrangementId, int $classId, array $sources): array
    {
        $students = array_values(array_filter(
            $sources['students'][$arrangementId] ?? [],
            static fn (array $student): bool => (int) ($student['class_id'] ?? 0) === $classId
        ));
        if (!$students) {
            return ['ready' => false, 'text' => '班级暂无学生'];
        }

        $scored = 0;
        foreach ($students as $student) {
            $score = $sources['scores'][self::pairMapKey($arrangementId, (int) $student['student_id'])] ?? null;
            if ($score && is_numeric($score['final_score'] ?? null)) {
                $scored++;
            }
        }

        return ['ready' => $scored === count($students), 'text' => "已评分{$scored}/" . count($students) . '人'];
    }

    private static function materialFileQuery(): mixed
    {
        return self::queryTable('internship_archive_material')
            ->leftJoin('file as generated_file', 'internship_archive_material.generated_file_id', '=', 'generated_file.id')
            ->leftJoin('file as signed_file', 'internship_archive_material.signed_file_id', '=', 'signed_file.id');
    }

    private static function materialColumns(): array
    {
        return [
            'internship_archive_material.*',
            'generated_file.name as generated_file_name', 'generated_file.download_name as generated_download_name',
            'generated_file.url as generated_file_url', 'generated_file.status as generated_file_status',
            'generated_file.deleted_at as generated_file_deleted_at',
            'signed_file.name as signed_file_name', 'signed_file.download_name as signed_download_name',
            'signed_file.url as signed_file_url', 'signed_file.status as signed_file_status',
            'signed_file.deleted_at as signed_file_deleted_at',
        ];
    }

    private static function materialRow(object $row): array
    {
        $item = self::row($row);
        $item['content_json'] = self::jsonArray($item['content_json'] ?? null);
        $generatedReady = !empty($item['generated_file_id'])
            && (string) ($item['generated_file_status'] ?? '') === 'enabled'
            && empty($item['generated_file_deleted_at']);
        $signedReady = !empty($item['signed_file_id'])
            && (string) ($item['signed_file_status'] ?? '') === 'enabled'
            && empty($item['signed_file_deleted_at']);
        $item['generated_file'] = !$generatedReady ? null : [
            'id' => (int) $item['generated_file_id'],
            'name' => $item['generated_download_name'] ?: $item['generated_file_name'],
            'url' => $item['generated_file_url'],
        ];
        $item['signed_file'] = !$signedReady ? null : [
            'id' => (int) $item['signed_file_id'],
            'name' => $item['signed_download_name'] ?: $item['signed_file_name'],
            'url' => $item['signed_file_url'],
        ];

        return $item;
    }

    private static function materialTargetQuery(string $materialType, array $target): mixed
    {
        $definition = self::materialDefinition($materialType);
        $scopeType = (string) ($definition['scope_type'] ?? $target['scope_type'] ?? '');
        return self::queryTable('internship_archive_material')
            ->where('material_type', $materialType)
            ->where('scope_type', $scopeType)
            ->where('plan_id', (int) ($target['plan_id'] ?? 0) ?: null)
            ->where('arrangement_id', (int) ($target['arrangement_id'] ?? 0) ?: null)
            ->where('student_id', (int) ($target['student_id'] ?? 0) ?: null)
            ->where('class_id', (int) ($target['class_id'] ?? 0) ?: null);
    }

    private static function materialMapKey(array $row): string
    {
        return (string) ($row['material_type'] ?? '') . '|' . self::scopeKey($row);
    }

    private static function scopeKey(array $row): string
    {
        return implode(':', [
            (string) ($row['scope_type'] ?? ''),
            (int) ($row['plan_id'] ?? 0),
            (int) ($row['arrangement_id'] ?? 0),
            (int) ($row['student_id'] ?? 0),
            (int) ($row['class_id'] ?? 0),
        ]);
    }

    private static function simpleSourceState(string $table, array $conditions, array $accepted): array
    {
        $query = self::queryTable($table)->whereNull('deleted_at');
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        $status = (string) ($query->orderByDesc('id')->value('status') ?: '');

        return [
            'ready' => in_array($status, $accepted, true),
            'text' => $status === '' ? '待填写' : self::statusText($status),
        ];
    }

    private static function countSourceState(string $table, array $conditions): array
    {
        $query = self::queryTable($table)->whereNull('deleted_at');
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        $count = (int) $query->count();
        return ['ready' => $count > 0, 'text' => $count > 0 ? "已有{$count}条数据" : '待绑定学生'];
    }

    private static function syllabusSourceState(int $planId, int $arrangementId, string $documentType): array
    {
        $query = self::queryTable('syllabus_guide')
            ->where('document_type', $documentType)
            ->where(function ($builder) use ($planId, $arrangementId): void {
                $builder->where('plan_id', $planId);
                if ($arrangementId > 0) {
                    $builder->orWhere('arrangement_id', $arrangementId);
                }
            })
            ->whereNull('deleted_at')
            ->orderByDesc('id');
        $row = $query->first(['status', 'file_id', 'content']);
        $ready = $row && in_array((string) $row->status, ['published', 'accept', 'enabled'], true)
            && ((int) $row->file_id > 0 || trim((string) $row->content) !== '');

        return ['ready' => (bool) $ready, 'text' => $row ? self::statusText((string) $row->status) : '待填写'];
    }

    private static function journalSourceState(int $studentId, int $arrangementId): array
    {
        $required = max(1, (int) (self::queryTable('arrangement')->where('id', $arrangementId)->value('required_journal_count') ?: 1));
        $accepted = (int) self::queryTable('journal')
            ->where('student_id', $studentId)->where('entity_type', 'internship')->where('entity_id', $arrangementId)
            ->whereIn('status', ['accept', 'enabled'])->whereNull('deleted_at')->count();

        return ['ready' => $accepted >= $required, 'text' => "已通过{$accepted}/{$required}篇"];
    }

    private static function reportSourceState(int $studentId, int $arrangementId, string $reportType): array
    {
        $row = self::queryTable('report')->where('student_id', $studentId)->where('arrangement_id', $arrangementId)
            ->where('report_type', $reportType)->whereNull('deleted_at')->orderByDesc('id')->first(['status']);
        $status = (string) ($row->status ?? '');
        return ['ready' => $status === 'accept', 'text' => $status ? self::statusText($status) : '待提交'];
    }

    private static function appraisalSourceState(int $studentId, int $arrangementId): array
    {
        $row = self::queryTable('internship_graduation_appraisal')
            ->where('student_id', $studentId)->where('arrangement_id', $arrangementId)
            ->whereNull('deleted_at')->orderByDesc('id')->first(['status', 'attachment_id']);
        $ready = $row && (string) $row->status === 'accept' && self::fileExists((int) $row->attachment_id);
        return ['ready' => (bool) $ready, 'text' => $row ? self::statusText((string) $row->status) : '待填写'];
    }

    private static function scoreRegisterSourceState(int $arrangementId, int $classId): array
    {
        $students = self::queryTable('pair')->join('students', 'pair.student_id', '=', 'students.student_id')
            ->where('pair.arrangement_id', $arrangementId)->where('pair.type', 'internship')->where('pair.status', 'active')
            ->where('students.class_id', $classId)->whereNull('pair.deleted_at')->pluck('pair.student_id');
        $studentIds = self::intValues($students);
        if (!$studentIds) {
            return ['ready' => false, 'text' => '班级暂无学生'];
        }
        $scored = (int) self::queryTable('score')->where('arrangement_id', $arrangementId)
            ->whereIn('student_id', $studentIds)->whereNotNull('final_score')->whereNull('deleted_at')->count();
        return ['ready' => $scored === count($studentIds), 'text' => "已评分{$scored}/" . count($studentIds) . '人'];
    }

    private static function safetySourceState(int $studentId, int $arrangementId): array
    {
        $row = self::queryTable('safety_letter_sign')->where('student_id', $studentId)
            ->where('arrangement_id', $arrangementId)->where('status', 'signed')
            ->whereNotNull('signed_at')->whereNotNull('signature_file_id')
            ->whereNull('deleted_at')->orderByDesc('id')->first(['signature_file_id']);
        $ready = $row && self::fileExists((int) $row->signature_file_id);
        return ['ready' => (bool) $ready, 'text' => $ready ? '已签署并上传定稿' : '待签署定稿'];
    }

    private static function normalizeArrangementRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['arrangement_id'] ?? $row['id'] ?? 0);
        }
        unset($row);

        return array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0));
    }

    private static function groupByPair(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = self::pairMapKey((int) ($row['arrangement_id'] ?? 0), (int) ($row['student_id'] ?? 0));
            $groups[$key][] = $row;
        }

        return $groups;
    }

    private static function groupByArrangement(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $arrangementId = (int) ($row['arrangement_id'] ?? 0);
            if ($arrangementId > 0) {
                $groups[$arrangementId][] = $row;
            }
        }

        return $groups;
    }

    private static function filterValue(mixed $query, array $filters, string $column, string $key): void
    {
        $value = $filters[$key] ?? null;
        if (is_numeric($value) && (int) $value > 0) {
            $query->where($column, (int) $value);
        }
    }

    private static function countMap(iterable $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->plan_key] = (int) $row->total;
        }

        return $map;
    }

    private static function keyBy(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $value = (int) ($row[$key] ?? 0);
            if ($value > 0 && !isset($map[$value])) {
                $map[$value] = $row;
            }
        }

        return $map;
    }

    private static function countBy(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $value = (int) ($row[$key] ?? 0);
            if ($value > 0) {
                $map[$value] = (int) ($map[$value] ?? 0) + 1;
            }
        }

        return $map;
    }

    private static function keyByPair(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = self::pairMapKey((int) ($row['arrangement_id'] ?? 0), (int) ($row['student_id'] ?? 0));
            if ($key !== '0:0' && !isset($map[$key])) {
                $map[$key] = $row;
            }
        }

        return $map;
    }

    private static function countByPair(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = self::pairMapKey((int) ($row['arrangement_id'] ?? 0), (int) ($row['student_id'] ?? 0));
            $map[$key] = (int) ($map[$key] ?? 0) + 1;
        }

        return $map;
    }

    private static function keyByTriple(array $rows, string $first, string $second, string $third): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = (int) ($row[$first] ?? 0) . ':' . (int) ($row[$second] ?? 0) . '|' . (string) ($row[$third] ?? '');
            if (!isset($map[$key])) {
                $map[$key] = $row;
            }
        }

        return $map;
    }

    private static function pairMapKey(int $arrangementId, int $studentId): string
    {
        return $arrangementId . ':' . $studentId;
    }

    private static function countByStudent(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            if ($studentId > 0) {
                $map[$studentId] = (int) ($map[$studentId] ?? 0) + 1;
            }
        }

        return $map;
    }

    private static function decodeGenerationRow(array $row): array
    {
        foreach (['form_data', 'attachment_ids', 'sheet_json'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $decoded = json_decode($row[$key], true);
                $row[$key] = is_array($decoded) ? $decoded : [];
            }
        }

        return $row;
    }

    private static function whereInOrDeny(mixed $query, string $column, array $ids): void
    {
        $ids = self::ids($ids);
        $ids ? $query->whereIn($column, $ids) : $query->whereRaw('1 = 0');
    }

    private static function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));
    }

    private static function intValues(iterable $values): array
    {
        return self::ids(is_array($values) ? $values : iterator_to_array($values));
    }

    private static function rows(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::row($row);
        }
        return $items;
    }

    private static function row(object $row): array
    {
        return method_exists($row, 'getAttributes') ? $row->getAttributes() : get_object_vars($row);
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function statusText(string $status): string
    {
        return [
            'draft' => '草稿', 'wait' => '待审核', 'submitted' => '已提交',
            'accept' => '已通过', 'published' => '已发布', 'enabled' => '已生效',
            'modify' => '需修改', 'refuse' => '未通过', 'archived' => '已归档',
        ][$status] ?? $status;
    }

    private static function arrangementTypeText(string $type): string
    {
        return [
            'cognition_internal' => '认识校内',
            'cognition_external' => '认识校外',
            'major_internal' => '专业校内',
            'major_external' => '专业校外',
            'production' => '生产实习',
            'graduation' => '毕业实习',
        ][$type] ?? ($type ?: '-');
    }
}
