<?php

namespace app\model\channel;

use app\server\CurrentContext;

/** 文件与业务对象的读取权限。 */
class FileAccessRecord extends TableRecord
{
    /** 区分可公开展示的界面图片和业务附件。 */
    public static function publicImage(object $file): bool
    {
        return in_array((string) $file->category, ['profile', 'login_background', 'menu_icon', 'favorite_icon'], true)
            && in_array(strtolower((string) $file->ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
            && in_array((string) $file->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /** 校验上传人、学校管理员或有权查看关联业务的账号。 */
    public static function readable(object $file): bool
    {
        if (!CurrentContext::accountId()) {
            return false;
        }
        if ((int) $file->uploader_id === CurrentContext::accountId() || self::schoolAdmin()) {
            return true;
        }
        if ((string) $file->category === 'favorite' && FavoriteRecord::iconVisible((int) CurrentContext::accountId(), (int) $file->id)) {
            return true;
        }
        if ((string) $file->category === 'export') {
            return false;
        }
        if (self::publicImage($file)) {
            return true;
        }
        foreach (FileRelation::entitiesForFile((int) $file->id) as $relation) {
            if (self::entityReadable((string) $relation->entity_type, (int) $relation->entity_id)) {
                return true;
            }
        }
        foreach ([
            'syllabus_guide' => ['file_id'], 'insurance' => ['attachment_id'],
            'safety_letter_sign' => ['signature_file_id'], 'teacher_work_report' => ['attachment_id'],
            'internship_graduation_appraisal' => ['attachment_id'],
            'internship_archive_material' => ['generated_file_id', 'signed_file_id'],
            'practice_archive' => ['archive_file_id'], 'template' => ['file_id'],
        ] as $entity => $columns) {
            $query = self::queryTable($entity)->whereNull('deleted_at')->where(function ($builder) use ($columns, $file): void {
                foreach ($columns as $column) {
                    $builder->orWhere($column, (int) $file->id);
                }
            });
            foreach ($query->pluck('id') as $id) {
                if (self::entityReadable($entity, (int) $id)) {
                    return true;
                }
            }
        }
        foreach (['journal', 'report', 'implementation_sheet'] as $entity) {
            $ids = self::queryTable($entity)->whereNull('deleted_at')
                ->whereJsonContains('attachment_ids', (int) $file->id)->pluck('id');
            foreach ($ids as $id) {
                if (self::entityReadable($entity, (int) $id)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 附件读取沿用所属模块的数据范围。 */
    public static function entityReadable(string $entity, int $id): bool
    {
        if ($id <= 0 || !CurrentContext::accountId()) {
            return false;
        }
        if (self::schoolAdmin()) {
            return true;
        }
        $permissions = CurrentContext::permissionCodes();
        $scope = self::scope();
        if ($entity === 'favorite_link') {
            return FavoriteRecord::visible((int) CurrentContext::accountId(), $id) !== null;
        }
        if ($entity === 'template') {
            return in_array('template:view', $permissions, true)
                && self::queryTable('template')->where('id', $id)->where('status', 'enabled')->where('flag', 'on')->whereNull('deleted_at')->exists();
        }
        if ($entity === 'practice_archive') {
            return in_array('practice:view', $permissions, true)
                && $scope['role_type'] !== 'student'
                && PracticeRecord::archiveDetailRow($scope, 'practice', $id) !== null;
        }
        if ($entity === 'social_practice_material') {
            return in_array('social_practice:view', $permissions, true)
                && SocialPracticeRecord::entityVisible($scope, 'material', $id);
        }
        if ($entity === 'internship_archive_material') {
            return in_array('internship:view', $permissions, true)
                && InternshipArchiveRecord::materialById($scope, $id) !== null;
        }
        $allowed = ['application', 'journal', 'report', 'insurance', 'safety_letter_sign', 'syllabus_guide',
            'implementation_sheet', 'teacher_work_report', 'internship_graduation_appraisal'];
        if (!in_array($entity, $allowed, true)) {
            return false;
        }
        $row = self::queryTable($entity)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$row) {
            return false;
        }
        $studentId = (int) ($row->student_id ?? 0);
        $ownStudent = $scope['role_type'] === 'student' && $studentId === $scope['student_id'];
        $ownTeacher = $scope['role_type'] === 'teacher'
            && ((int) ($row->created_by ?? $row->submitter_id ?? $row->applicant_id ?? 0) === CurrentContext::accountId()
                || (int) ($row->teacher_id ?? 0) === $scope['teacher_id']);
        if ((string) $row->status === 'draft' && !$ownStudent && !$ownTeacher) {
            return false;
        }
        if (in_array($entity, ['journal', 'report'], true) && in_array((string) $row->entity_type, ['lab', 'training'], true)) {
            return in_array('practice:view', $permissions, true)
                && PracticeRecord::executionVisible($scope, (string) $row->entity_type, $entity, $id);
        }
        if (!in_array('internship:view', $permissions, true)) {
            return false;
        }
        if ($entity === 'application') {
            return InternshipRecord::applicationVisible($scope, $id);
        }
        $taskId = (int) ($row->arrangement_id ?? ($entity === 'journal' ? $row->entity_id : 0));
        if ($studentId > 0) {
            return InternshipRecord::taskBindingVisible($scope, $studentId, $taskId);
        }
        if ($taskId > 0) {
            return InternshipRecord::arrangementVisible($scope, $taskId);
        }
        if ($entity === 'syllabus_guide' && (int) ($row->plan_id ?? 0) > 0) {
            return InternshipArchiveRecord::targetContext($scope, ['plan_id' => (int) $row->plan_id]) !== null;
        }
        return false;
    }

    /** 构建各模块共用的账号范围参数。 */
    private static function scope(): array
    {
        $role = (string) CurrentContext::roleType();
        $teacherId = (int) InternshipRecord::teacherIdByUser((int) CurrentContext::userId());
        $studentId = (int) InternshipRecord::studentIdByUser((int) CurrentContext::userId());
        $ids = static fn (string $field): array => array_values(array_unique(array_filter(array_map('intval', array_column(CurrentContext::organizationScopes(), $field)))));
        $depIds = $ids('dep_id');
        $professionIds = $ids('profession_id');
        return [
            'account_id' => CurrentContext::accountId(), 'user_id' => CurrentContext::userId(), 'role_type' => $role,
            'teacher_id' => $teacherId, 'student_id' => $studentId, 'dep_ids' => $depIds, 'profession_ids' => $professionIds,
            'profession_dep_ids' => InternshipRecord::depIdsByProfessionIds($professionIds),
            'student_profile' => $studentId ? (array) (InternshipRecord::studentProfile($studentId)?->toArray() ?? []) : null,
            'visible_student_ids' => match ($role) {
                'student' => [$studentId], 'teacher' => InternshipRecord::studentIdsByTeacher($teacherId),
                'college_admin' => InternshipRecord::studentIdsByDepartments($depIds),
                'profession_admin' => InternshipRecord::studentIdsByProfessions($professionIds), default => [],
            },
            'visible_arrangement_ids' => match ($role) {
                'teacher' => InternshipRecord::arrangementIdsByTeacher($teacherId),
                'student' => InternshipRecord::visibleArrangementIdsByStudent($studentId), default => [],
            },
            'owned_arrangement_ids' => $role === 'student' ? InternshipRecord::arrangementIdsByStudent($studentId) : [],
        ];
    }

    /** 学校级文件管理权限。 */
    public static function schoolAdmin(): bool
    {
        return in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true);
    }
}
