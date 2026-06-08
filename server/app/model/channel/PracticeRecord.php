<?php

namespace app\model\channel;

class PracticeRecord extends TableRecord
{
    private const ENTITY_TABLES = [
        'plan' => 'practice_plan',
        'schedule' => 'practice_schedule',
        'syllabus' => 'practice_syllabus',
        'lessonPlan' => 'practice_lesson_plan',
        'gradeRule' => 'practice_grade_rule',
        'score' => 'practice_score',
        'reflection' => 'practice_reflection',
        'room' => 'practice_room',
    ];

    private const JSON_FIELDS = ['content_json', 'ratio_json', 'score_items'];

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
            'grades' => self::rows($grades->orderBy('sort')->get(['grade_id', 'grade_name'])),
            'professions' => self::rows($professions->orderBy('sort')->get(['profession_id', 'profession_name', 'profession_code', 'dep_id', 'grade_id'])),
            'classes' => self::rows($classes->orderBy('sort')->get(['class_id', 'class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id'])),
            'teachers' => self::rows($teachers->orderBy('teacher_id')->get(['teacher_id', 'teacher_name', 'teacher_num', 'dep_id', 'profession_id'])),
            'students' => self::rows($students->orderBy('student_id')->get(['student_id', 'name', 'student_num', 'grade_id', 'dep_id', 'profession_id', 'class_id'])),
            'rooms' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_room', $moduleType)->where('practice_room.status', 'enabled'), $scope, 'practice_room', false, 'room')
                ->orderBy('practice_room.id')
                ->get(['id', 'uuid', 'name', 'code', 'dep_id', 'room_type', 'capacity', 'location'])),
            'plans' => self::rows(self::applyPracticeScope(self::moduleQuery('practice_plan', $moduleType)->whereIn('practice_plan.status', ['wait', 'accept', 'enabled']), $scope, 'practice_plan')
                ->orderByDesc('practice_plan.id')
                ->get(['id', 'uuid', 'title', 'course_name', 'grade_id', 'dep_id', 'profession_id', 'class_id', 'teacher_id', 'status'])),
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

    private static function entityTable(string $entity): string
    {
        if (!isset(self::ENTITY_TABLES[$entity])) {
            throw new \InvalidArgumentException('entity 无效');
        }

        return self::ENTITY_TABLES[$entity];
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
        if ($entity === 'score') {
            $query->leftJoin('students', 'practice_score.student_id', '=', 'students.student_id');
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
        if ($entity === 'schedule') {
            $columns[] = 'practice_room.name as room_name';
            $columns[] = 'base.name as base_name';
        }
        if ($entity === 'score') {
            $columns[] = 'students.name as student_name';
            $columns[] = 'students.student_num';
        }

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
            'score' => ["{$table}.title", 'students.name', 'students.student_num', 'teacher_list.teacher_name', 'practice_plan.title'],
            default => ["{$table}.title", "{$table}.course_name", "{$table}.content", 'department.dep_name', 'profession.profession_name', 'teacher_list.teacher_name', 'practice_plan.title'],
        });
    }

    private static function filterKeys(string $entity): array
    {
        return match ($entity) {
            'plan' => ['status', 'teacher_id', 'source_type'],
            'schedule' => ['status', 'plan_id', 'teacher_id', 'room_id', 'base_id', 'place_type'],
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
            self::applyStudentAcademicScope($query, $alias, $scope['student_profile'] ?? null);
            return $query;
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

    private static function applyStudentOptionScope(mixed $query, array $scope): mixed
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin') {
            return self::whereInOrDeny($query, 'dep_id', $scope['dep_ids'] ?? []);
        }
        if ($roleType === 'profession_admin') {
            return self::whereInOrDeny($query, 'profession_id', $scope['profession_ids'] ?? []);
        }
        if ($roleType === 'student') {
            return self::whereInOrDeny($query, 'student_id', [(int) ($scope['student_id'] ?? 0)]);
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
