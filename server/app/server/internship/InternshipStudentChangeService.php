<?php

namespace app\server\internship;

use app\model\channel\InternshipRecord;
use app\model\channel\InternshipStudentChangeRecord;
use app\model\channel\InternshipStudentProfileRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\message\MessageService;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Throwable;

/** 学生实习资料变更及校内指导教师审核服务。 */
class InternshipStudentChangeService
{
    /** 查询当前账号可见的学生实习资料。 */
    public function profiles(Request $request): array
    {
        $this->requireView();

        $studentId = $this->optionalInt($request->input('student_id'));
        if ($this->isStudent()) {
            $studentId = $this->studentId(true);
            InternshipStudentProfileRecord::ensureForStudentTasks(
                $studentId,
                InternshipRecord::visibleArrangementIdsByStudent($studentId),
                $this->now()
            );
        }

        return InternshipStudentProfileRecord::page($this->scope(), [
            'page' => $request->input('page'),
            'page_size' => $request->input('page_size'),
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'arrangement_id' => $request->input('arrangement_id'),
            'student_id' => $studentId,
            'category_id' => $request->input('category_id'),
            'dep_id' => $request->input('dep_id'),
            'profession_id' => $request->input('profession_id'),
            'grade_id' => $request->input('grade_id'),
            'graduation_cohort_id' => $request->input('graduation_cohort_id'),
        ]);
    }

    /** 查询当前账号可见的学生实习资料变更。 */
    public function changes(Request $request): array
    {
        $this->requireView();

        return InternshipStudentChangeRecord::page($this->scope(), [
            'page' => $request->input('page'),
            'page_size' => $request->input('page_size'),
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'arrangement_id' => $request->input('arrangement_id'),
            'student_id' => $request->input('student_id'),
            'category_id' => $request->input('category_id'),
            'dep_id' => $request->input('dep_id'),
            'profession_id' => $request->input('profession_id'),
            'grade_id' => $request->input('grade_id'),
            'graduation_cohort_id' => $request->input('graduation_cohort_id'),
        ]);
    }

    /** 查看学生实习资料变更及流程记录。 */
    public function detail(Request $request): array
    {
        $this->requireView();
        $id = $this->requiredInt($request->input('id'));
        $row = InternshipStudentChangeRecord::visibleById($this->scope(), $id);
        if (!$row) {
            throw new RuntimeException('学生实习资料变更不存在或无权限', 40301);
        }

        return [
            'item' => $this->rowArray($row),
            'records' => InternshipRecord::recordingRows('internship_student_change_recording', $id),
            'reviews' => InternshipRecord::reviewOpinionRows('student_change', $id),
        ];
    }

    /** 保存或提交学生个人实习资料变更。 */
    public function save(Request $request): array
    {
        $this->requireView();
        if (!$this->isStudent()) {
            throw new RuntimeException('只有学生可以提交个人实习资料变更', 40300);
        }

        $studentId = $this->studentId(true);
        $arrangementId = $this->requiredInt($request->input('arrangement_id'));
        $scope = $this->scope();
        if (!InternshipRecord::currentArrangementVisible($scope, $arrangementId)) {
            throw new RuntimeException('实习任务不存在或无权限', 40301);
        }
        if (!InternshipRecord::taskBindingVisible($scope, $studentId, $arrangementId)) {
            throw new RuntimeException('学生未绑定该实习任务或无权限', 40301);
        }

        $id = $this->optionalInt($request->input('id'));
        $status = $this->enum($request->input('status'), ['draft', 'wait'], 'draft');
        $changeType = $this->enum($request->input('change_type'), ['profile', 'company', 'mentor', 'location', 'termination', 'other'], 'profile');
        $reason = $this->stringValue($request->input('reason'), 2000);
        $effectiveDate = $this->optionalDateValue($request->input('effective_date'), '期望生效日期');
        $payload = $this->payload((array) $request->input('after_payload', []), $changeType, $reason, $effectiveDate);
        if ($status === 'wait' && $reason === '') {
            throw new InvalidArgumentException('提交审核前请填写变更原因');
        }

        $lockId = $id ?: abs((int) crc32($studentId . ':' . $arrangementId));
        return (new WorkflowLock())->run(
            WorkflowLock::key('internship', 'student_change', $lockId),
            function () use ($arrangementId, $changeType, $effectiveDate, $id, $payload, $reason, $status, $studentId): array {
                return InternshipRecord::connection()->transaction(function () use ($arrangementId, $changeType, $effectiveDate, $id, $payload, $reason, $status, $studentId): array {
                    $profile = InternshipStudentProfileRecord::ensureForTask($studentId, $arrangementId, $this->now());
                    $payload = array_replace(
                        InternshipStudentProfileRecord::snapshot($profile),
                        $payload
                    );
                    $referenceIssue = InternshipStudentProfileRecord::referenceIssue($payload);
                    if ($referenceIssue) {
                        throw new InvalidArgumentException($referenceIssue);
                    }
                    $existing = $id ? InternshipStudentChangeRecord::byId($id, true) : null;
                    if ($id && (!$existing || (int) $existing->student_id !== $studentId || (int) $existing->arrangement_id !== $arrangementId)) {
                        throw new RuntimeException('学生实习资料变更不存在或无权限', 40301);
                    }
                    $fromStatus = $existing ? (string) $existing->status : 'draft';
                    if ($existing && !in_array($fromStatus, ['draft', 'modify'], true)) {
                        throw new InvalidArgumentException('只有草稿或退回修改的变更可以再次提交', 409);
                    }
                    $now = $this->now();
                    $changeId = InternshipStudentChangeRecord::saveChange($id ?: 0, [
                        'student_id' => $studentId,
                        'arrangement_id' => $arrangementId,
                        'profile_id' => (int) $profile->id,
                        'change_type' => $changeType,
                        'before_payload' => $this->json(InternshipStudentProfileRecord::snapshot($profile)),
                        'after_payload' => $this->json($payload),
                        'reason' => $reason !== '' ? $reason : null,
                        'effective_date' => $effectiveDate,
                        'submitter_id' => CurrentContext::accountId(),
                        'reviewer_id' => null,
                        'review_opinion' => null,
                        'submitted_at' => $status === 'wait' ? $now : null,
                        'reviewed_at' => null,
                        'status' => $status,
                    ], $now);

                    $content = $reason !== '' ? $reason : '保存学生实习资料变更草稿';
                    $this->recordWorkflow($changeId, $fromStatus, $status, $status === 'wait' ? 'submit' : 'save_draft', $content, $status);

                    if ($status === 'wait') {
                        $this->notifyTeachers($studentId, $arrangementId, $changeId, $content);
                    }

                    return ['id' => $changeId, 'status' => $status, 'item' => $this->changeItem($changeId)];
                });
            }
        );
    }

    /** 由校内指导教师审核学生个人实习资料变更。 */
    public function review(Request $request): array
    {
        $this->requireApprove();
        if (!$this->isTeacher()) {
            throw new RuntimeException('学生个人实习资料变更仅由校内指导教师审核', 40300);
        }

        $id = $this->requiredInt($request->input('id'));
        $status = $this->enum($request->input('status'), ['accept', 'modify'], 'accept');
        $opinion = $this->opinion($request->input('opinion'), $status);
        $row = InternshipStudentChangeRecord::visibleById($this->scope(), $id);
        if (!$row) {
            throw new RuntimeException('学生实习资料变更不存在或无权限', 40301);
        }

        return (new WorkflowLock())->run(
            WorkflowLock::key('internship', 'student_change', $id),
            function () use ($id, $opinion, $status): array {
                return InternshipRecord::connection()->transaction(function () use ($id, $opinion, $status): array {
                    $row = InternshipStudentChangeRecord::byId($id, true);
                    if (!$row) {
                        throw new RuntimeException('学生实习资料变更不存在');
                    }
                    if (!InternshipRecord::taskBindingVisible($this->scope(), (int) $row->student_id, (int) $row->arrangement_id)) {
                        throw new RuntimeException('无数据访问权限', 40301);
                    }
                    if ((string) $row->status !== 'wait') {
                        throw new InvalidArgumentException('只有待审核变更可以审核', 409);
                    }

                    $now = $this->now();
                    if ($status === 'accept') {
                        $profile = InternshipStudentProfileRecord::ensureForTask((int) $row->student_id, (int) $row->arrangement_id, $now);
                        $before = $this->decode($row->before_payload);
                        $after = $this->decode($row->after_payload);
                        $current = InternshipStudentProfileRecord::snapshot($profile);
                        $changes = [];
                        foreach ($after as $field => $value) {
                            if (($before[$field] ?? null) === $value) {
                                continue;
                            }
                            if (($current[$field] ?? null) !== ($before[$field] ?? null)) {
                                throw new InvalidArgumentException('申请涉及的资料已变更，请退回后由学生重新提交', 409);
                            }
                            $changes[$field] = $value;
                        }
                        $updated = array_replace($current, $changes);
                        $issue = InternshipStudentProfileRecord::referenceIssue($updated);
                        if ($issue) {
                            throw new InvalidArgumentException($issue);
                        }
                        if (!empty($updated['start_date']) && !empty($updated['end_date']) && $updated['end_date'] < $updated['start_date']) {
                            throw new InvalidArgumentException('资料已变更，结束日期不能早于开始日期', 409);
                        }
                        InternshipStudentProfileRecord::applyChange((int) $profile->id, $updated, $now);
                    }
                    InternshipStudentChangeRecord::review($id, $status, (int) CurrentContext::accountId(), $opinion ?: ($status === 'accept' ? '审核通过' : '请修改后重新提交'), $now);
                    $content = $opinion ?: ($status === 'accept' ? '审核通过' : '请修改后重新提交');
                    $this->recordWorkflow($id, 'wait', $status, 'teacher_review', $content, $status);
                    InternshipRecord::clearReviewOpinionDraft('student_change', $id, (int) CurrentContext::accountId(), $now);
                    $this->notifyStudent((int) $row->student_id, $id, $status, $content);

                    return ['id' => $id, 'status' => $status, 'item' => $this->changeItem($id)];
                });
            }
        );
    }

    /** 读取当前请求的数据范围。 */
    private function scope(): array
    {
        $role = (string) CurrentContext::roleType();
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
            'role_type' => $role,
            'teacher_id' => InternshipRecord::teacherIdByUser((int) CurrentContext::userId()),
            'student_id' => InternshipRecord::studentIdByUser((int) CurrentContext::userId()),
            'dep_ids' => array_values(array_unique($depIds)),
            'profession_ids' => array_values(array_unique($professionIds)),
        ];
    }

    /** 写入一条统一的变更流程记录和审核记录。 */
    private function recordWorkflow(int $changeId, string $fromStatus, string $toStatus, string $action, string $content, string $reviewStatus): void
    {
        InternshipRecord::ensureRecordingTable('internship_student_change_recording');
        $now = $this->now();
        $recordingId = InternshipRecord::insertRow('internship_student_change_recording', [
            'uuid' => $this->uuid(), 'name' => '学生实习资料变更记录', 'parent_id' => $changeId,
            'entity_type' => 'student_change', 'entity_id' => $changeId, 'action' => $action,
            'operator_id' => CurrentContext::accountId(), 'from_status' => $fromStatus, 'to_status' => $toStatus,
            'opinion' => $content, 'content' => $content, 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ]);
        if ($reviewStatus === 'draft') {
            return;
        }
        InternshipRecord::insertRow('review_opinion', [
            'uuid' => $this->uuid(), 'entity_type' => 'student_change', 'entity_id' => $changeId,
            'recording_id' => $recordingId, 'teacher_id' => $this->teacherId(false), 'reviewer_id' => CurrentContext::accountId(),
            'opinion' => $content, 'status' => $reviewStatus, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ]);
    }

    /** 通知变更对应的校内指导教师。 */
    private function notifyTeachers(int $studentId, int $arrangementId, int $changeId, string $content): void
    {
        $accounts = [];
        foreach (InternshipRecord::activePairTeacherIds($studentId, $arrangementId) as $teacherId) {
            $accountId = InternshipRecord::teacherAccountId((int) $teacherId);
            if ($accountId) {
                $accounts[] = $accountId;
            }
        }
        if (!$accounts) {
            return;
        }

        try {
            (new MessageService())->sendByTemplateCode('internship_student_change_submit_todo', array_values(array_unique($accounts)), [
                'module_name' => '学生实习资料变更',
                'submitter_name' => (string) (CurrentContext::get('user_name') ?: CurrentContext::get('login_name') ?: '学生'),
                'entity_title' => '变更申请#' . $changeId,
                'opinion_text' => $content,
                'module_key' => 'internship',
                'panel_key' => 'studentChanges',
            ], [
                'entity_type' => 'student_change', 'entity_id' => $changeId,
                'sender_id' => CurrentContext::accountId() ?: 0,
            ]);
        } catch (Throwable) {
        }
    }

    /** 通知学生审核结果。 */
    private function notifyStudent(int $studentId, int $changeId, string $status, string $opinion): void
    {
        $accountId = InternshipRecord::studentAccountId($studentId);
        if (!$accountId) {
            return;
        }

        try {
            (new MessageService())->sendByTemplateCode('internship_student_change_review_result', [$accountId], [
                'module_name' => '学生实习资料变更',
                'status_text' => $status === 'accept' ? '审核通过' : '退回修改',
                'entity_title' => '变更申请#' . $changeId,
                'opinion_text' => $opinion,
                'module_key' => 'internship',
                'panel_key' => 'studentChanges',
            ], [
                'entity_type' => 'student_change', 'entity_id' => $changeId,
                'sender_id' => CurrentContext::accountId() ?: 0,
            ]);
        } catch (Throwable) {
        }
    }

    /** 读取变更申请并转换为接口数据。 */
    private function changeItem(int $id): array
    {
        $row = InternshipStudentChangeRecord::visibleById($this->scope(), $id);
        return $row ? $this->rowArray($row) : [];
    }

    /** 转换变更申请 JSON 字段。 */
    private function rowArray(object $row): array
    {
        $item = $row->toArray();
        foreach (['before_payload', 'after_payload'] as $key) {
            $item[$key] = $this->decode($item[$key] ?? null);
        }
        return $item;
    }

    /** 规范化允许提交的学生实习资料字段。 */
    private function payload(array $payload, string $changeType, string $reason, ?string $effectiveDate): array
    {
        $allowed = match ($changeType) {
            'company' => ['company_id', 'base_id', 'enterprise_mentor_id'],
            'mentor' => ['enterprise_mentor_id'],
            'location' => ['location', 'position', 'start_date', 'end_date'],
            'termination' => ['status', 'terminated_at', 'termination_reason'],
            default => ['company_id', 'base_id', 'enterprise_mentor_id', 'location', 'position', 'start_date', 'end_date'],
        };
        $result = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if (in_array($field, ['company_id', 'base_id', 'enterprise_mentor_id'], true)) {
                $result[$field] = $this->optionalInt($value);
            } elseif (in_array($field, ['start_date', 'end_date'], true)) {
                $result[$field] = $this->optionalDateValue($value, $field === 'start_date' ? '开始日期' : '结束日期');
            } else {
                $result[$field] = $this->stringValue($value, 5000);
            }
        }
        if ($changeType === 'termination') {
            $result['status'] = 'terminated';
            $result['terminated_at'] = $result['terminated_at'] ?? ($effectiveDate ?: $this->now());
            $result['termination_reason'] = $result['termination_reason'] ?? $reason;
        } else {
            unset($result['status'], $result['terminated_at'], $result['termination_reason']);
        }
        if (!empty($result['start_date']) && !empty($result['end_date']) && $result['end_date'] < $result['start_date']) {
            throw new InvalidArgumentException('结束日期不能早于开始日期');
        }
        return $result;
    }

    /** 读取可选日期并校验格式。 */
    private function optionalDateValue(mixed $value, string $label): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || date('Y-m-d', strtotime($value)) !== $value) {
            throw new InvalidArgumentException($label . '格式不正确');
        }
        return $value;
    }

    /** 标准化审核意见。 */
    private function opinion(mixed $value, string $status): ?string
    {
        $text = $this->stringValue($value, 500);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($status === 'modify' && $length < 5) {
            throw new InvalidArgumentException('退回原因至少 5 字');
        }
        return $text === '' ? null : $text;
    }

    /** 读取当前学生 ID。 */
    private function studentId(bool $required): ?int
    {
        $id = InternshipRecord::studentIdByUser((int) CurrentContext::userId());
        if (!$id && $required) {
            throw new RuntimeException('当前账号未绑定学生档案');
        }
        return $id;
    }

    /** 读取当前教师 ID。 */
    private function teacherId(bool $required): ?int
    {
        $id = InternshipRecord::teacherIdByUser((int) CurrentContext::userId());
        if (!$id && $required) {
            throw new RuntimeException('当前账号未绑定教师档案');
        }
        return $id;
    }

    /** 校验查看权限。 */
    private function requireView(): void
    {
        $this->account();
        if (!in_array('internship:view', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    /** 校验审核权限。 */
    private function requireApprove(): void
    {
        $this->account();
        if (!in_array('internship:approve', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 40300);
        }
    }

    /** 校验登录账号。 */
    private function account(): int
    {
        $id = (int) CurrentContext::accountId();
        if ($id <= 0) {
            throw new RuntimeException('请先登录', 40100);
        }
        return $id;
    }

    /** 判断当前账号是否为学生。 */
    private function isStudent(): bool
    {
        return CurrentContext::roleType() === 'student';
    }

    /** 判断当前账号是否为校内教师。 */
    private function isTeacher(): bool
    {
        return CurrentContext::roleType() === 'teacher';
    }

    /** 读取正整数。 */
    private function requiredInt(mixed $value): int
    {
        $id = $this->optionalInt($value);
        if (!$id) {
            throw new InvalidArgumentException('参数无效');
        }
        return $id;
    }

    /** 读取可选整数。 */
    private function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** 读取枚举值。 */
    private function enum(mixed $value, array $allowed, string $default): string
    {
        $value = (string) ($value ?? $default);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** 限制文本长度。 */
    private function stringValue(mixed $value, int $max): string
    {
        $value = trim((string) ($value ?? ''));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    /** JSON 编码。 */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** JSON 解码。 */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** 当前时间。 */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** 生成 UUID。 */
    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
