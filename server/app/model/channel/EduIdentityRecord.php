<?php

namespace app\model\channel;

/** 自助绑定所需的最小身份档案。 */
class EduIdentityRecord extends TableRecord
{
    /** 读取有效学生来源；仅服务端校验使用敏感密文。 */
    public static function student(string $studentNum): ?array
    {
        return self::queryTable('edu_student_source')->where('student_num', $studentNum)
            ->where('source_status', 'active')->whereNull('deleted_at')
            ->first(['student_id', 'student_name', 'mobile_hmac', 'sensitive_payload_cipher'])?->toArray();
    }

    /** 读取有效教师档案。 */
    public static function teacher(string $teacherNum): ?array
    {
        return self::queryTable('teacher_list')->where('teacher_num', $teacherNum)
            ->where('status', 'enabled')->whereNull('deleted_at')
            ->first(['teacher_id', 'teacher_name', 'phone'])?->toArray();
    }
}
