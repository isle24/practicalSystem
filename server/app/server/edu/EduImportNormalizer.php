<?php

namespace app\server\edu;

use InvalidArgumentException;

class EduImportNormalizer
{
    /**
     * 参与加密或派生的敏感字段；这些值一律不写入 raw_payload。
     */
    private const SENSITIVE_STUDENT_FIELDS = ['证件号', '家庭地址', '出生地', '生源地', '籍贯', '政治面貌', '手机号码', '考生号', '出生日期', '电子邮箱'];

    public function __construct(private readonly EduIdentityCipher $cipher)
    {
    }

    public function normalize(string $type, array $values, int $rowNumber): array
    {
        return match ($type) {
            'student' => $this->student($values, $rowNumber),
            'teaching_plan' => $this->teachingPlan($values, $rowNumber),
            'course_offering' => $this->courseOffering($values, $rowNumber),
            default => throw new InvalidArgumentException('教务数据类型无效'),
        };
    }

    private function student(array $values, int $rowNumber): array
    {
        $studentNum = $this->value($values, '学号');
        $studentName = $this->value($values, '姓名');
        $grade = $this->value($values, '年级');
        $depName = $this->value($values, '学院');
        $professionCode = $this->value($values, '专业代码');
        $professionName = $this->value($values, '专业名称');
        $classNum = $this->value($values, '班号');
        $className = $this->value($values, '班级');
        $issues = [];
        foreach ([
            '学号' => $studentNum,
            '姓名' => $studentName,
            '年级' => $grade,
            '学院' => $depName,
            '专业代码' => $professionCode,
            '专业名称' => $professionName,
            '班号' => $classNum,
            '班级' => $className,
        ] as $field => $value) {
            if ($value === '') {
                $issues[] = ['field_name' => $field, 'issue_type' => 'required', 'message' => $field . '不能为空', 'raw_value_masked' => ''];
            }
        }

        $sensitive = [];
        foreach (self::SENSITIVE_STUDENT_FIELDS as $field) {
            $sensitive[$field] = $this->value($values, $field);
        }
        $mobile = $this->value($values, '手机号码');
        $identityNumber = $sensitive['证件号'];
        $lastSix = $identityNumber !== '' ? substr($identityNumber, -6) : '';
        $raw = $values;
        foreach (self::SENSITIVE_STUDENT_FIELDS as $field) {
            unset($raw[$field]);
        }
        $data = [
            'source_key' => $studentNum,
            'scope_key' => 'school',
            'student_num' => $studentNum,
            'student_name' => $studentName,
            'gender' => $this->value($values, '性别'),
            'grade_code' => $grade,
            'grade_name' => $this->gradeName($grade),
            'dep_code' => '',
            'dep_name' => $depName,
            'profession_code' => $professionCode,
            'profession_name' => $professionName,
            'class_num' => $classNum,
            'class_name' => $className,
            'student_status' => $this->value($values, '学籍状态'),
            'campus_status' => $this->value($values, '是否在校'),
            'enrollment_status' => $this->value($values, '有无学籍'),
            'education_level' => $this->value($values, '学历层次'),
            'training_level' => $this->value($values, '培养层次'),
            'enrollment_date' => $this->value($values, '入学日期'),
            'graduation_year' => $this->value($values, '毕业年级'),
            'source_status' => $this->studentStatus($values),
            'mapping_status' => 'pending',
            'mobile_hmac' => $this->cipher->mobileHash($mobile),
            'identity_last_six_hmac' => $lastSix !== '' && strlen($lastSix) === 6 ? $this->cipher->identityLastSixHash($lastSix) : '',
            'sensitive_payload_cipher' => $this->cipher->encryptSensitive($sensitive),
            'raw_payload' => $raw,
        ];
        $data['source_hash'] = $this->hash($data);

        return ['source_key' => $studentNum !== '' ? $studentNum : 'row:' . $rowNumber, 'scope_key' => 'school', 'data' => $data, 'raw_payload' => $raw, 'issues' => $issues, 'business_type' => null, 'classification_source' => null, 'classification_reason' => null];
    }

    private function teachingPlan(array $values, int $rowNumber): array
    {
        $academicYear = $this->value($values, '学年');
        $semester = $this->value($values, '学期');
        $grade = $this->value($values, '年级');
        $courseCode = $this->value($values, '课程代码');
        $courseName = $this->value($values, '课程名称');
        $professionCode = $this->value($values, '专业代码');
        $sourceKey = $this->hashKey([$academicYear, $semester, $grade, $courseCode, $professionCode]);
        $issues = $this->requiredIssues([
            '学年' => $academicYear,
            '学期' => $semester,
            '年级' => $grade,
            '课程代码' => $courseCode,
            '课程名称' => $courseName,
            '专业代码' => $professionCode,
        ]);
        [$businessType, $classificationSource, $classificationReason] = $this->classify($courseName, $values['实验总学时'] ?? '', $values['实践总学时'] ?? '', $this->value($values, '课程类别'), $this->value($values, '课程性质'));
        $raw = $values;
        $data = [
            'source_key' => $sourceKey,
            'scope_key' => $academicYear . ':' . $semester,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'grade_code' => $grade,
            'grade_name' => $this->gradeName($grade),
            'dep_code' => $this->value($values, '开课部门代码'),
            'dep_name' => $this->value($values, '专业学院名称') ?: $this->value($values, '开课部门'),
            'profession_code' => $professionCode,
            'profession_name' => $this->value($values, '专业名称'),
            'course_code' => $courseCode,
            'course_name' => $courseName,
            'course_category' => $this->value($values, '课程类别'),
            'course_nature' => $this->value($values, '课程性质'),
            'credit' => $this->number($values['学分'] ?? null),
            'weekly_hours' => $this->number($values['周学时'] ?? null),
            'total_hours' => $this->number($values['总学时'] ?? null),
            'theory_hours' => $this->number($values['理论总学时'] ?? null),
            'experiment_hours' => $this->number($values['实验总学时'] ?? null),
            'practice_hours' => $this->number($values['实践总学时'] ?? null),
            'other_hours' => $this->number($values['其他总学时'] ?? null),
            'source_status' => 'active',
            'mapping_status' => 'pending',
            'business_type' => $businessType,
            'candidate_status' => $this->candidateStatus($businessType, $classificationSource),
            'raw_payload' => $raw,
        ];
        $data['source_hash'] = $this->hash($data);
        return ['source_key' => $sourceKey, 'scope_key' => $data['scope_key'], 'data' => $data, 'raw_payload' => $raw, 'issues' => $issues, 'business_type' => $businessType, 'classification_source' => $classificationSource, 'classification_reason' => $classificationReason];
    }

    private function courseOffering(array $values, int $rowNumber): array
    {
        $academicYear = $this->value($values, '学年');
        $semester = $this->value($values, '学期');
        $teachingClassId = $this->value($values, '教学班ID');
        $courseCode = $this->value($values, '课程代码');
        $courseName = $this->value($values, '课程名称');
        $issues = $this->requiredIssues([
            '学年' => $academicYear,
            '学期' => $semester,
            '课程代码' => $courseCode,
            '课程名称' => $courseName,
            '教学班ID' => $teachingClassId,
        ]);
        [$businessType, $classificationSource, $classificationReason] = $this->classify($courseName, '', '', '', $this->value($values, '课程性质'), $this->value($values, '学时类型'));
        $raw = $values;
        $data = [
            'source_key' => $teachingClassId,
            'scope_key' => $academicYear . ':' . $semester,
            'teaching_class_id' => $teachingClassId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'course_code' => $courseCode,
            'course_name' => $courseName,
            'open_dep_code' => $this->value($values, '开课学院代码'),
            'open_dep_name' => $this->value($values, '开课学院'),
            'class_composition' => $this->value($values, '教学班组成'),
            'teacher_numbers' => $this->value($values, '教工号'),
            'teacher_names' => $this->value($values, '教师名称'),
            'teaching_type' => $this->value($values, '学时类型'),
            'course_nature' => $this->value($values, '课程性质'),
            'start_week' => $this->value($values, '起始周'),
            'end_week' => $this->value($values, '结束周'),
            'time_text' => $this->value($values, '上课时间'),
            'location' => $this->value($values, '教学地点'),
            'source_status' => $this->value($values, '开课状态') === '停开' ? 'disabled' : 'active',
            'mapping_status' => 'pending',
            'business_type' => $businessType,
            'candidate_status' => $this->candidateStatus($businessType, $classificationSource),
            'grade_code' => $this->value($values, '年级组成'),
            'raw_payload' => $raw,
        ];
        $data['source_hash'] = $this->hash($data);
        $sourceKey = $teachingClassId !== '' ? $teachingClassId : 'row:' . $rowNumber;
        return ['source_key' => $sourceKey, 'scope_key' => $data['scope_key'], 'data' => $data, 'raw_payload' => $raw, 'issues' => $issues, 'business_type' => $businessType, 'classification_source' => $classificationSource, 'classification_reason' => $classificationReason];
    }

    private function classify(string $courseName, mixed $experimentHours, mixed $practiceHours, string $category, string $nature, string $teachingType = ''): array
    {
        if (str_contains($courseName, '社会实践')) {
            return ['social_practice', 'name', '课程名称包含社会实践'];
        }
        if (str_contains($courseName, '实习')) {
            return ['internship', 'name', '课程名称包含实习'];
        }
        if (str_contains($courseName, '实训') || str_contains($courseName, '实践') || str_contains($courseName, '课程设计')) {
            return ['training', 'name', '课程名称包含实践类关键词'];
        }
        if (str_contains($courseName, '实验')) {
            return ['lab', 'name', '课程名称包含实验'];
        }
        $experiment = $this->number($experimentHours) ?? 0;
        $practice = $this->number($practiceHours) ?? 0;
        if ($teachingType === '实验') {
            return ['lab', 'teaching_type', '学时类型为实验'];
        }
        if ($teachingType === '实践') {
            return ['training', 'teaching_type', '学时类型为实践'];
        }
        if ($experiment > 0 && $practice <= 0) {
            return ['lab', 'hours', '实验总学时大于零'];
        }
        if ($practice > 0 && $experiment <= 0) {
            return ['training', 'hours', '实践总学时大于零'];
        }
        if ($category === '实践课' || $nature === '实践环节') {
            return [null, 'flag', '实践类标记无法区分实验或实训'];
        }
        return [null, null, null];
    }

    /**
     * 已识别业务类型或存在实践类标记但类型待定的行，均进入待分类候选。
     */
    private function candidateStatus(?string $businessType, ?string $classificationSource): string
    {
        if ($businessType !== null && $businessType !== '') {
            return 'pending';
        }
        return $classificationSource === 'flag' ? 'pending' : 'ignored';
    }

    private function requiredIssues(array $fields): array
    {
        $issues = [];
        foreach ($fields as $field => $value) {
            if (trim((string) $value) === '') {
                $issues[] = ['field_name' => $field, 'issue_type' => 'required', 'message' => $field . '不能为空', 'raw_value_masked' => ''];
            }
        }
        return $issues;
    }

    private function studentStatus(array $values): string
    {
        return $this->value($values, '学籍状态') === '在读'
            && $this->value($values, '是否在校') === '是'
            && $this->value($values, '有无学籍') === '有' ? 'active' : 'disabled';
    }

    private function value(array $values, string $field): string
    {
        return trim((string) ($values[$field] ?? ''));
    }

    private function gradeName(string $grade): string
    {
        return $grade === '' || str_ends_with($grade, '级') ? $grade : $grade . '级';
    }

    private function number(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function hash(array $data): string
    {
        unset($data['raw_payload'], $data['sensitive_payload_cipher']);
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function hashKey(array $parts): string
    {
        return hash('sha256', implode("\0", array_map(static fn ($value): string => trim((string) $value), $parts)));
    }
}
