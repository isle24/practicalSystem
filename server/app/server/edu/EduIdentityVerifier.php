<?php

namespace app\server\edu;

use app\model\channel\EduIdentityRecord;
use app\server\CurrentContext;
use InvalidArgumentException;
use RuntimeException;
use support\Redis;

class EduIdentityVerifier
{
    private const MAX_ATTEMPTS = 5;
    private const ATTEMPT_TTL = 600;

    /** 复用教务身份加密设施。 */
    public function __construct(private ?EduIdentityCipher $cipher = null)
    {
        $this->cipher ??= new EduIdentityCipher();
    }

    /** 比对学生档案；手机号所有权仍须由绑定流程通过短信核验。 */
    public function verifyStudent(string $studentNum, string $name, ?string $mobile, ?string $identityNumber): array
    {
        $studentNum = $this->required($studentNum, '学号', 80);
        $name = $this->required($name, '姓名', 80);
        $this->guardAttempts('student', $studentNum);

        $row = EduIdentityRecord::student($studentNum);
        if (!$row || !$this->sameText($name, (string) $row['student_name'])) {
            throw new RuntimeException('学生信息校验失败', 422);
        }

        $verifiedBy = '';
        $mobileHash = $mobile ? $this->cipher->mobileHash($mobile) : '';
        if (!empty($row['mobile_hmac'])) {
            if ($mobileHash === '' || !hash_equals((string) $row['mobile_hmac'], $mobileHash)) {
                throw new RuntimeException('学生信息校验失败', 422);
            }
            $verifiedBy = 'mobile';
        } elseif ($identityNumber !== null && trim($identityNumber) !== '') {
            $sensitive = $this->cipher->decryptSensitive((string) ($row['sensitive_payload_cipher'] ?? ''));
            $expected = strtoupper(trim((string) ($sensitive['证件号'] ?? '')));
            $actual = strtoupper(trim($identityNumber));
            if (!preg_match('/^(?:\d{15}|\d{17}[\dX])$/D', $actual) || $expected === '' || !hash_equals($expected, $actual)) {
                throw new RuntimeException('学生信息校验失败', 422);
            }
            $verifiedBy = 'identity_number';
        } else {
            throw new InvalidArgumentException('档案缺少手机号，请填写完整身份证号；无证件资料时请联系管理员补齐');
        }

        return [
            'verified' => true,
            'student_id' => (int) ($row['student_id'] ?? 0),
            'verified_by' => $verifiedBy,
        ];
    }

    /** 比对教师档案，不代替手机号所有权校验。 */
    public function verifyTeacher(string $teacherNum, string $name, ?string $mobile = null): array
    {
        $teacherNum = $this->required($teacherNum, '工号', 80);
        $name = $this->required($name, '姓名', 80);
        $this->guardAttempts('teacher', $teacherNum);

        $row = EduIdentityRecord::teacher($teacherNum);
        if (!$row || !$this->sameText($name, (string) $row['teacher_name'])) {
            throw new RuntimeException('教师信息校验失败', 422);
        }
        if ($mobile !== null && trim($mobile) !== '' && trim((string) ($row['phone'] ?? '')) !== '') {
            $expected = preg_replace('/\D+/', '', trim((string) $row['phone'])) ?: '';
            $actual = preg_replace('/\D+/', '', trim($mobile)) ?: '';
            if ($expected === '' || $expected !== $actual) {
                throw new RuntimeException('教师信息校验失败', 422);
            }
        }

        return [
            'verified' => true,
            'teacher_id' => (int) $row['teacher_id'],
            'verified_by' => $mobile ? 'teacher_num_name_mobile' : 'teacher_num_name',
        ];
    }

    /** 按学校、档案编号和来源地址限制尝试次数。 */
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

    /** 校验必填身份字段。 */
    private function required(string $value, string $label, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    /** 忽略空白和大小写比对档案姓名。 */
    private function sameText(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            $value = preg_replace('/\s+/u', '', trim($value)) ?: '';
            return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        };
        return $normalize($left) !== '' && $normalize($left) === $normalize($right);
    }
}
