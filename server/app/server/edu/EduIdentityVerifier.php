<?php

namespace app\server\edu;

use app\model\channel\EduDataRecord;
use app\server\CurrentContext;
use InvalidArgumentException;
use RuntimeException;
use support\Redis;

class EduIdentityVerifier
{
    private const MAX_ATTEMPTS = 5;
    private const ATTEMPT_TTL = 600;

    public function __construct(private ?EduIdentityCipher $cipher = null)
    {
        $this->cipher ??= new EduIdentityCipher();
    }

    public function verifyStudent(string $studentNum, string $name, ?string $mobile, ?string $identityLastSix): array
    {
        $studentNum = $this->required($studentNum, '学号', 80);
        $name = $this->required($name, '姓名', 80);
        $this->guardAttempts('student', $studentNum);

        $row = EduDataRecord::connection()->table('edu_student_source')
            ->where('student_num', $studentNum)
            ->where('source_status', 'active')
            ->whereNull('deleted_at')
            ->first(['student_id', 'student_name', 'mobile_hmac', 'identity_last_six_hmac']);
        if (!$row || !$this->sameText($name, (string) $row->student_name)) {
            throw new RuntimeException('学生信息校验失败', 422);
        }

        $verifiedBy = '';
        $mobileHash = $mobile ? $this->cipher->mobileHash($mobile) : '';
        if ($row->mobile_hmac) {
            if ($mobileHash === '' || !hash_equals((string) $row->mobile_hmac, $mobileHash)) {
                throw new RuntimeException('学生信息校验失败', 422);
            }
            $verifiedBy = 'mobile';
        } elseif ($identityLastSix !== null && $identityLastSix !== '') {
            $lastSixHash = $this->cipher->identityLastSixHash($identityLastSix);
            if (!$row->identity_last_six_hmac || !hash_equals((string) $row->identity_last_six_hmac, $lastSixHash)) {
                throw new RuntimeException('学生信息校验失败', 422);
            }
            $verifiedBy = 'identity_last_six';
        } else {
            throw new InvalidArgumentException('请提供企业微信手机号或身份证后 6 位');
        }

        return [
            'verified' => true,
            'student_id' => (int) ($row->student_id ?? 0),
            'verified_by' => $verifiedBy,
        ];
    }

    public function verifyTeacher(string $teacherNum, string $name, ?string $mobile = null): array
    {
        $teacherNum = $this->required($teacherNum, '工号', 80);
        $name = $this->required($name, '姓名', 80);
        $this->guardAttempts('teacher', $teacherNum);

        $row = EduDataRecord::connection()->table('teacher_list')
            ->where('teacher_num', $teacherNum)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['teacher_id', 'teacher_name', 'phone']);
        if (!$row || !$this->sameText($name, (string) $row->teacher_name)) {
            throw new RuntimeException('教师信息校验失败', 422);
        }
        if ($mobile !== null && trim($mobile) !== '' && trim((string) ($row->phone ?? '')) !== '') {
            $expected = preg_replace('/\D+/', '', trim((string) $row->phone)) ?: '';
            $actual = preg_replace('/\D+/', '', trim($mobile)) ?: '';
            if ($expected === '' || $expected !== $actual) {
                throw new RuntimeException('教师信息校验失败', 422);
            }
        }

        return [
            'verified' => true,
            'teacher_id' => (int) $row->teacher_id,
            'verified_by' => $mobile ? 'teacher_num_name_mobile' : 'teacher_num_name',
        ];
    }

    private function guardAttempts(string $type, string $identifier): void
    {
        $school = (string) (CurrentContext::schoolDatabaseId() ?: 'unknown');
        $ip = (string) (CurrentContext::get('client_ip') ?: 'unknown');
        $key = 'practical:edu_identity:attempts:' . $school . ':' . $type . ':' . hash('sha256', $identifier . '|' . $ip);
        $attempts = (int) Redis::eval(
            "local value = redis.call('INCR', KEYS[1]); if value == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end; return value",
            1,
            $key,
            self::ATTEMPT_TTL
        );
        if ($attempts > self::MAX_ATTEMPTS) {
            throw new RuntimeException('校验尝试次数过多，请稍后再试', 429);
        }
    }

    private function required(string $value, string $label, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function sameText(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            $value = preg_replace('/\s+/u', '', trim($value)) ?: '';
            return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        };
        return $normalize($left) !== '' && $normalize($left) === $normalize($right);
    }
}
