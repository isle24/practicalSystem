<?php

namespace app\server\internship;

use app\model\channel\EnterpriseEvaluationRecord;
use app\model\channel\InternshipRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\security\SmsCodeService;
use InvalidArgumentException;
use RuntimeException;
use support\Request;

/** 企业导师评价邀请、短信验证和独立评价服务。 */
class InternshipEnterpriseEvaluationService
{
    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const SMS_SCENE_PREFIX = 'enterprise_evaluation:';
    private const SESSION_TTL = 1800;
    private const INVITATION_TTL = 2592000;

    /** 创建或复用毕业实习企业导师评价邀请。 */
    public function createInvitation(Request $request): array
    {
        $this->requireAdmin();
        $arrangementId = $this->requiredInt($request->input('arrangement_id'));
        $mentorId = $this->requiredInt($request->input('enterprise_mentor_id'));
        $target = EnterpriseEvaluationRecord::arrangementMentorTarget($this->scope(), $arrangementId, $mentorId);
        if (!$target) {
            throw new RuntimeException('仅毕业实习任务的有效企业导师绑定可以生成评价邀请', 422);
        }

        $mobile = trim((string) ($target->mentor_phone ?? ''));
        if ($mobile === '') {
            throw new InvalidArgumentException('企业导师未填写手机号，无法发送短信评价邀请');
        }
        (new SmsCodeService())->mobile($mobile);

        $lockId = abs((int) sprintf('%u', crc32($arrangementId . ':' . $mentorId)));
        return (new WorkflowLock())->run(
            WorkflowLock::key('internship', 'enterprise_evaluation_invitation', $lockId ?: 1),
            function () use ($arrangementId, $mentorId, $mobile, $request, $target): array {
                $now = $this->now();
                $existing = EnterpriseEvaluationRecord::reusableInvitation($arrangementId, $mentorId, $mobile, $now);
                if ($existing) {
                    return $this->invitationResult($existing, $request);
                }

                $token = $this->token();
                $expiresAt = date('Y-m-d H:i:s', time() + self::INVITATION_TTL);
                $id = EnterpriseEvaluationRecord::createInvitation([
                    'code' => 'enterprise-evaluation-' . $arrangementId . '-' . $mentorId,
                    'arrangement_id' => $arrangementId,
                    'enterprise_mentor_id' => $mentorId,
                    'token' => $token,
                    'mobile' => $mobile,
                    'expires_at' => $expiresAt,
                    'created_by' => CurrentContext::accountId(),
                ]);

                $invitation = EnterpriseEvaluationRecord::invitationByToken($token);
                return $this->invitationResult($invitation ?: (object) [
                    'id' => $id,
                    'token' => $token,
                    'arrangement_id' => $arrangementId,
                    'enterprise_mentor_id' => $mentorId,
                    'mobile' => $mobile,
                    'expires_at' => $expiresAt,
                    'mentor_name' => $target->mentor_name,
                    'arrangement_title' => $target->arrangement_title,
                ], $request);
            },
            30,
            true
        );
    }

    /** 发送企业导师评价短信验证码。 */
    public function sendCode(Request $request): array
    {
        $token = $this->requiredString($request->input('token'), '评价邀请', 180);
        $mobile = $this->requiredString($request->input('mobile'), '手机号', 40);
        $invitation = EnterpriseEvaluationRecord::invitationByToken($token);
        if (!$invitation) {
            throw new RuntimeException('评价邀请不存在、已过期或已失效', 404);
        }
        $mobile = (new SmsCodeService())->mobile($mobile);
        if ((string) $invitation->mobile !== $mobile) {
            throw new RuntimeException('手机号与评价邀请不匹配', 403);
        }

        return (new SmsCodeService())->send(self::SMS_SCENE_PREFIX . $token, $mobile);
    }

    /** 校验企业导师短信验证码并创建评价会话。 */
    public function verifyCode(Request $request): array
    {
        $token = $this->requiredString($request->input('token'), '评价邀请', 180);
        $mobile = $this->requiredString($request->input('mobile'), '手机号', 40);
        $code = $this->requiredString($request->input('sms_code', $request->input('code')), '验证码', 10);
        $invitation = EnterpriseEvaluationRecord::invitationByToken($token);
        if (!$invitation) {
            throw new RuntimeException('评价邀请不存在、已过期或已失效', 404);
        }
        $sms = new SmsCodeService();
        $mobile = $sms->mobile($mobile);
        if ((string) $invitation->mobile !== $mobile) {
            throw new RuntimeException('手机号与评价邀请不匹配', 403);
        }
        if (!$sms->verify(self::SMS_SCENE_PREFIX . $token, $mobile, $code)) {
            throw new InvalidArgumentException('验证码错误或已过期');
        }

        $sessionExpiresAt = date('Y-m-d H:i:s', time() + self::SESSION_TTL);
        $session = EnterpriseEvaluationRecord::createVerifiedSession($token, $mobile, $this->now(), $sessionExpiresAt);
        if (!$session) {
            throw new RuntimeException('评价邀请不存在、已过期或已失效', 404);
        }

        return $session;
    }

    /** 查询企业导师评价会话上下文和学生列表。 */
    public function context(Request $request): array
    {
        $session = $this->session($request);
        return [
            'session' => [
                'session_token' => (string) $session->session_token,
                'expires_at' => (string) $session->expires_at,
                'mentor_name' => (string) ($session->mentor_name ?? ''),
                'arrangement_title' => (string) ($session->arrangement_title ?? ''),
            ],
            'rule' => EnterpriseEvaluationRecord::rule('graduation'),
            'students' => EnterpriseEvaluationRecord::studentsForSession($session),
        ];
    }

    /** 提交一名学生的独立企业评价。 */
    public function submit(Request $request): array
    {
        $session = $this->session($request);
        $studentId = $this->requiredInt($request->input('student_id'));
        $pair = EnterpriseEvaluationRecord::studentPairForSession($session, $studentId);
        if (!$pair) {
            throw new RuntimeException('学生不属于当前评价邀请', 403);
        }

        $rule = EnterpriseEvaluationRecord::rule('graduation');
        $criteria = $this->criteria((array) $request->input('criteria', []), $rule['items']);
        $totalScore = $this->decimal($request->input('total_score'));
        $calculatedScore = round(array_sum(array_column($criteria, 'score')), 2);
        if ($totalScore === null) {
            $totalScore = $calculatedScore;
        }
        if (abs($totalScore - $calculatedScore) > 0.01) {
            throw new InvalidArgumentException('评价总分必须等于各项评分之和');
        }
        if ($totalScore < 0 || $totalScore > (float) $rule['total_score']) {
            throw new InvalidArgumentException('评价总分超出规则范围');
        }

        $comment = $this->stringValue($request->input('comment'), 5000);
        if ($comment === '') {
            throw new InvalidArgumentException('请填写企业评价意见');
        }

        $lockId = abs((int) sprintf('%u', crc32($studentId . ':' . (int) $session->arrangement_id)));
        $lockKey = WorkflowLock::key('internship', 'enterprise_evaluation', $lockId ?: 1);
        return (new WorkflowLock())->run($lockKey, function () use ($comment, $criteria, $pair, $request, $session, $studentId, $totalScore): array {
            $evaluationId = EnterpriseEvaluationRecord::saveEvaluation(
                $studentId,
                (int) $session->arrangement_id,
                (int) $pair->pair_id,
                (int) $session->enterprise_mentor_id,
                [
                    'evaluator_name' => (string) ($session->mentor_name ?? ''),
                    'evaluator_mobile' => (string) $session->mobile,
                    'verification_id' => (int) $session->session_id,
                    'criteria_json' => json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'total_score' => $totalScore,
                    'comment' => $comment,
                    'submitted_ip' => (string) $request->getRealIp(),
                ],
                $this->now(),
                (string) $session->session_token
            );

            return [
                'id' => $evaluationId,
                'student_id' => $studentId,
                'arrangement_id' => (int) $session->arrangement_id,
                'total_score' => $totalScore,
                'status' => 'submitted',
            ];
        }, 30, true);
    }

    /** 查询管理员或校内指导教师可见的企业评价进度。 */
    public function progress(Request $request): array
    {
        $this->requireView();
        return EnterpriseEvaluationRecord::progressPage($this->scope(), [
            'page' => $request->input('page', 1),
            'page_size' => $request->input('page_size', 20),
            'keyword' => $request->input('keyword', ''),
            'arrangement_id' => $request->input('arrangement_id'),
            'student_id' => $request->input('student_id'),
            'dep_id' => $request->input('dep_id'),
            'profession_id' => $request->input('profession_id'),
            'grade_id' => $request->input('grade_id'),
            'graduation_cohort_id' => $request->input('graduation_cohort_id'),
            'evaluation_status' => $request->input('evaluation_status'),
        ]);
    }

    /** 查询企业评价规则。 */
    public function rule(Request $request): array
    {
        $this->requireView();
        $practiceType = trim((string) $request->input('practice_type', 'graduation'));
        if ($practiceType !== '' && $practiceType !== 'graduation') {
            throw new InvalidArgumentException('企业评价仅适用于毕业实习');
        }
        return EnterpriseEvaluationRecord::rule('graduation');
    }

    /** 保存毕业实习企业评价规则。 */
    public function saveRule(Request $request): array
    {
        $this->requireSchoolAdmin();
        $criteria = (array) $request->input('criteria', []);
        $items = [];
        foreach ($criteria as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($item['code'] ?? ''));
            $name = $this->stringValue($item['name'] ?? '', 80);
            $maxScore = $this->decimal($item['max_score'] ?? null);
            if ($code === '' || $name === '' || $maxScore === null || $maxScore <= 0) {
                throw new InvalidArgumentException('企业评价规则项无效');
            }
            $items[] = ['code' => $code, 'name' => $name, 'max_score' => $maxScore];
        }
        if (!$items) {
            throw new InvalidArgumentException('至少配置一项企业评价指标');
        }
        $totalScore = $this->decimal($request->input('total_score'));
        $sum = round(array_sum(array_column($items, 'max_score')), 2);
        if ($totalScore === null || abs($totalScore - 30) > 0.01 || abs($totalScore - $sum) > 0.01) {
            throw new InvalidArgumentException('企业评价固定为 30 分，各指标最高分之和必须等于 30 分');
        }

        $id = EnterpriseEvaluationRecord::saveRule('graduation', $items, $totalScore, (int) CurrentContext::accountId(), $this->now());
        return ['id' => $id, 'rule' => EnterpriseEvaluationRecord::rule('graduation')];
    }

    /** 读取当前登录账号可见的数据范围。 */
    private function scope(): array
    {
        $depIds = [];
        $professionIds = [];
        foreach (CurrentContext::organizationScopes() as $scope) {
            if (!empty($scope['dep_id'])) {
                $depIds[] = (int) $scope['dep_id'];
            }
            if (!empty($scope['profession_id'])) {
                $professionIds[] = (int) $scope['profession_id'];
            }
        }

        return [
            'role_type' => CurrentContext::roleType(),
            'teacher_id' => InternshipRecord::teacherIdByUser((int) CurrentContext::userId()),
            'dep_ids' => array_values(array_unique($depIds)),
            'profession_ids' => array_values(array_unique($professionIds)),
        ];
    }

    /** 校验评价会话。 */
    private function session(Request $request): object
    {
        $token = trim((string) ($request->input('session_token') ?: $request->header('x-session-token', '')));
        if ($token === '') {
            throw new RuntimeException('评价会话无效，请重新验证手机号', 401);
        }
        $session = EnterpriseEvaluationRecord::sessionByToken($token, $this->now());
        if (!$session) {
            throw new RuntimeException('评价会话已过期，请重新验证手机号', 401);
        }
        return $session;
    }

    /** 校验后台管理员权限。 */
    private function requireAdmin(): void
    {
        $this->account();
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLE_TYPES, true) || !in_array('internship:manage', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无企业评价管理权限', 40300);
        }
    }

    /** 校验学校级管理员权限。 */
    private function requireSchoolAdmin(): void
    {
        $this->account();
        if (!in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true) || !in_array('internship:manage', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('仅超级管理员或学校管理员可以维护企业评价规则', 40300);
        }
    }

    /** 校验实习查看权限。 */
    private function requireView(): void
    {
        $this->account();
        if (!in_array('internship:view', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    /** 读取登录账号。 */
    private function account(): int
    {
        $id = (int) CurrentContext::accountId();
        if ($id <= 0) {
            throw new RuntimeException('请先登录', 40100);
        }
        return $id;
    }

    /** 规范化评价指标并校验各项分数。 */
    private function criteria(array $criteria, array $definitions): array
    {
        $input = [];
        foreach ($criteria as $item) {
            if (is_array($item) && isset($item['code'])) {
                $input[(string) $item['code']] = $item;
            }
        }
        $result = [];
        foreach ($definitions as $definition) {
            $code = (string) ($definition['code'] ?? '');
            $score = $this->decimal($input[$code]['score'] ?? null);
            $maxScore = (float) ($definition['max_score'] ?? 0);
            if ($score === null || $score < 0 || $score > $maxScore) {
                throw new InvalidArgumentException('评价指标「' . ($definition['name'] ?? $code) . '」分数无效');
            }
            $result[] = ['code' => $code, 'name' => (string) ($definition['name'] ?? $code), 'score' => $score, 'max_score' => $maxScore];
        }
        return $result;
    }

    /** 返回邀请结果。 */
    private function invitationResult(object $invitation, Request $request): array
    {
        $token = (string) ($invitation->token ?? '');
        return [
            'id' => (int) ($invitation->id ?? 0),
            'token' => $token,
            'mobile_masked' => $this->maskMobile((string) ($invitation->mobile ?? '')),
            'mentor_name' => (string) ($invitation->mentor_name ?? ''),
            'arrangement_title' => (string) ($invitation->arrangement_title ?? ''),
            'expires_at' => (string) ($invitation->expires_at ?? ''),
            'evaluation_url' => $this->evaluationUrl($request, $token),
        ];
    }

    /** 生成企业评价访问地址。 */
    private function evaluationUrl(Request $request, string $token): string
    {
        $host = trim((string) ($request->header('x-forwarded-host') ?: $request->header('host', '')));
        if ($host === '') {
            return '/h5/index.html?enterprise_evaluation=' . rawurlencode($token);
        }
        $proto = trim((string) ($request->header('x-forwarded-proto') ?: 'http'));
        $proto = in_array($proto, ['http', 'https'], true) ? $proto : 'http';
        return $proto . '://' . $host . '/h5/index.html?enterprise_evaluation=' . rawurlencode($token);
    }

    /** 读取正整数。 */
    private function requiredInt(mixed $value): int
    {
        $id = is_numeric($value) ? (int) $value : 0;
        if ($id <= 0) {
            throw new InvalidArgumentException('参数无效');
        }
        return $id;
    }

    /** 读取非空字符串。 */
    private function requiredString(mixed $value, string $label, int $maxLength): string
    {
        $text = $this->stringValue($value, $maxLength);
        if ($text === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        return $text;
    }

    /** 限制文本长度。 */
    private function stringValue(mixed $value, int $maxLength): string
    {
        $text = trim((string) ($value ?? ''));
        return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
    }

    /** 读取非负小数。 */
    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return round((float) $value, 2);
    }

    /** 生成评价邀请令牌。 */
    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }

    /** 脱敏手机号。 */
    private function maskMobile(string $mobile): string
    {
        return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
    }

    /** 当前时间。 */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
