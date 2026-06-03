<?php

namespace app\model\channel;

class InternshipRecord extends TableRecord
{
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

    public static function idByUuid(string $table, string $uuid): int
    {
        return (int) (self::queryTable($table)->where('uuid', $uuid)->value('id') ?: 0);
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

    private static function intValues(mixed $values): array
    {
        return self::ids($values->map(static fn ($id): int => (int) $id)->all());
    }

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
