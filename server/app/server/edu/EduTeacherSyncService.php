<?php

namespace app\server\edu;

use app\model\channel\EduDataRecord;
use app\model\channel\TeacherSyncRecord;

class EduTeacherSyncService
{
    public function syncOffering(array $data, array $raw, string $now): array
    {
        $numbers = $this->parts($data['teacher_numbers'] ?? '');
        $names = $this->parts($data['teacher_names'] ?? '');
        $departments = $this->parts($raw['教师部门'] ?? '');
        $genders = $this->parts($raw['教师性别'] ?? '');
        $birthDates = $this->parts($raw['教师出生日期'] ?? '');
        $titles = $this->parts($raw['职称'] ?? '');
        $roles = $this->parts($raw['是否主讲'] ?? '');
        $teachers = [];
        foreach ($numbers as $index => $number) {
            if ($number === '') {
                continue;
            }
            $department = $this->departmentId($departments[$index] ?? ($departments[0] ?? ''), $now);
            $result = TeacherSyncRecord::upsertTeacher([
                'external_id' => $number,
                'teacher_num' => $number,
                'teacher_name' => $names[$index] ?? ($names[0] ?? $number),
                'dep_id' => $department,
                'profession_id' => null,
                'gender' => $this->optional($genders[$index] ?? ($genders[0] ?? '')),
                'birth_date' => $this->date($birthDates[$index] ?? ($birthDates[0] ?? '')),
                'title' => $this->optional($titles[$index] ?? ($titles[0] ?? '')),
                'education' => null,
                'phone' => null,
                'email' => null,
                'employment_type' => $this->optional($raw['教职工类别'] ?? ''),
                'sync_source' => 'edu_excel',
                'source_updated_at' => $this->dateTime($raw['任务落实时间'] ?? ''),
                'status' => 'enabled',
            ], $now);
            $teacher = EduDataRecord::connection()->table('teacher_list')->where('teacher_num', $number)->whereNull('deleted_at')->first(['teacher_id', 'teacher_num', 'teacher_name']);
            if ($teacher) {
                $teachers[] = [
                    'teacher_id' => (int) $teacher->teacher_id,
                    'teacher_num' => (string) $teacher->teacher_num,
                    'teacher_name' => (string) $teacher->teacher_name,
                    'teacher_role' => ($roles[$index] ?? ($roles[0] ?? '')) === '辅讲' ? 'assistant' : 'main',
                    'sort' => $index,
                    'result' => $result,
                ];
            }
        }
        return $teachers;
    }

    private function departmentId(string $name, string $now): ?int
    {
        $name = $this->optional($name);
        if ($name === null) {
            return null;
        }
        $row = EduDataRecord::connection()->table('department')->where('dep_name', $name)->whereNull('deleted_at')->first(['dep_id']);
        return $row ? (int) $row->dep_id : null;
    }

    private function parts(mixed $value): array
    {
        $text = trim((string) $value);
        return $text === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $item): bool => $item !== ''));
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' || $value === '无' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        $value = $this->optional($value);
        if ($value === null) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function dateTime(mixed $value): ?string
    {
        $value = $this->optional($value);
        $timestamp = $value ? strtotime($value) : false;
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}
