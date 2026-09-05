<?php

namespace app\model\channel;

use RuntimeException;

/** 企业导师评价、邀请和验证会话查询。 */
class EnterpriseEvaluationRecord extends TableRecord
{
    /** 查询企业评价邀请。 */
    public static function invitationByToken(string $token, bool $lock = false): ?object
    {
        self::ensureTable();
        $query = self::queryTable('internship_enterprise_evaluation_invitation as invitation')
            ->join('enterprise_mentor', 'invitation.enterprise_mentor_id', '=', 'enterprise_mentor.id')
            ->join('arrangement', 'invitation.arrangement_id', '=', 'arrangement.id')
            ->where('invitation.token', $token)
            ->where('invitation.status', 'enabled')
            ->where('invitation.expires_at', '>', date('Y-m-d H:i:s'))
            ->whereNull('invitation.revoked_at')
            ->whereNull('invitation.deleted_at')
            ->whereNull('arrangement.deleted_at')
            ->where('enterprise_mentor.status', 'enabled')
            ->whereColumn('invitation.mobile', 'enterprise_mentor.phone')
            ->whereNull('enterprise_mentor.deleted_at');
        self::applyGraduationScope($query);
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->first(['invitation.*', 'enterprise_mentor.name as mentor_name', 'enterprise_mentor.phone as mentor_phone', 'arrangement.title as arrangement_title']);
    }

    /** 查询毕业实习任务与企业导师的有效绑定。 */
    public static function arrangementMentorTarget(array $scope, int $arrangementId, int $mentorId): ?object
    {
        self::ensureTable();

        $query = self::queryTable('pair')
            ->join('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->join('enterprise_mentor', 'pair.enterprise_mentor_id', '=', 'enterprise_mentor.id')
            ->join('students', 'pair.student_id', '=', 'students.student_id')
            ->where('pair.arrangement_id', $arrangementId)
            ->where('pair.enterprise_mentor_id', $mentorId)
            ->where('pair.type', 'internship')
            ->where('pair.status', 'active')
            ->whereNull('pair.deleted_at')
            ->whereNull('arrangement.deleted_at')
            ->where('enterprise_mentor.status', 'enabled')
            ->whereNull('enterprise_mentor.deleted_at')
            ->whereNull('students.deleted_at');
        self::applyGraduationScope($query);
        self::applyStudentScope($query, $scope);

        return $query->first([
                'pair.id as pair_id', 'pair.arrangement_id', 'pair.enterprise_mentor_id',
                'enterprise_mentor.name as mentor_name', 'enterprise_mentor.phone as mentor_phone',
                'arrangement.title as arrangement_title',
            ]);
    }

    /** 生成或复用一条企业评价邀请。 */
    public static function reusableInvitation(int $arrangementId, int $mentorId, string $mobile, string $now): ?object
    {
        self::ensureTable();
        $query = self::queryTable('internship_enterprise_evaluation_invitation as invitation')
            ->join('enterprise_mentor', 'invitation.enterprise_mentor_id', '=', 'enterprise_mentor.id')
            ->join('arrangement', 'invitation.arrangement_id', '=', 'arrangement.id')
            ->where('invitation.arrangement_id', $arrangementId)
            ->where('invitation.enterprise_mentor_id', $mentorId)
            ->where('invitation.mobile', $mobile)
            ->where('invitation.status', 'enabled')
            ->whereNull('invitation.revoked_at')
            ->whereNull('invitation.deleted_at')
            ->where('invitation.expires_at', '>', $now)
            ->whereNull('arrangement.deleted_at')
            ->where('enterprise_mentor.status', 'enabled')
            ->whereColumn('invitation.mobile', 'enterprise_mentor.phone')
            ->whereNull('enterprise_mentor.deleted_at');
        self::applyGraduationScope($query);

        return $query->orderByDesc('invitation.id')->first([
                'invitation.*', 'enterprise_mentor.name as mentor_name',
                'enterprise_mentor.phone as mentor_phone', 'arrangement.title as arrangement_title',
            ]);
    }

    /** 保存企业评价邀请。 */
    public static function createInvitation(array $values): int
    {
        self::ensureTable();
        return (int) self::queryTable('internship_enterprise_evaluation_invitation')->insertGetId(array_merge([
            'uuid' => self::uuidValue(), 'name' => '企业导师评价邀请', 'status' => 'enabled', 'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'), 'deleted_at' => null, 'revoked_at' => null,
        ], $values));
    }

    /** 查询已验证的企业评价会话。 */
    public static function sessionByToken(string $sessionToken, string $now, bool $lock = false): ?object
    {
        self::ensureTable();
        $query = self::queryTable('internship_enterprise_evaluation_session as session')
            ->join('internship_enterprise_evaluation_invitation as invitation', 'session.invitation_id', '=', 'invitation.id')
            ->join('enterprise_mentor', 'invitation.enterprise_mentor_id', '=', 'enterprise_mentor.id')
            ->join('arrangement', 'invitation.arrangement_id', '=', 'arrangement.id')
            ->where('session.session_token', $sessionToken)->where('session.status', 'enabled')
            ->whereNotNull('session.verified_at')->where('session.expires_at', '>', $now)
            ->where('invitation.status', 'enabled')->whereNull('invitation.revoked_at')
            ->where('invitation.expires_at', '>', $now)
            ->whereNull('session.deleted_at')->whereNull('invitation.deleted_at')
            ->where('enterprise_mentor.status', 'enabled')
            ->whereColumn('invitation.mobile', 'enterprise_mentor.phone')
            ->whereNull('enterprise_mentor.deleted_at')->whereNull('arrangement.deleted_at')
            ->whereColumn('invitation.mobile', 'session.mobile');
        self::applyGraduationScope($query);

        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->first([
                'session.*', 'session.id as session_id', 'invitation.arrangement_id', 'invitation.enterprise_mentor_id', 'invitation.token',
                'enterprise_mentor.name as mentor_name', 'enterprise_mentor.phone as mentor_phone',
                'arrangement.title as arrangement_title',
            ]);
    }

    /** 创建已通过短信验证的企业评价会话。 */
    public static function createVerifiedSession(string $token, string $mobile, string $now, string $sessionExpiresAt): ?array
    {
        self::ensureTable();

        return self::connection()->transaction(function () use ($token, $mobile, $now, $sessionExpiresAt): ?array {
            $invitation = self::invitationByToken($token, true);
            if (!$invitation || (string) $invitation->mobile !== $mobile) {
                return null;
            }

            $sessionToken = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
            $sessionId = (int) self::queryTable('internship_enterprise_evaluation_session')->insertGetId([
                'uuid' => self::uuidValue(),
                'name' => '企业评价短信验证',
                'invitation_id' => (int) $invitation->id,
                'mobile' => $mobile,
                'code_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'code_sent_at' => $now,
                'verified_at' => $now,
                'expires_at' => $sessionExpiresAt,
                'session_token' => $sessionToken,
                'attempts' => 0,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            return [
                'session_token' => $sessionToken,
                'session_id' => $sessionId,
                'invitation_id' => (int) $invitation->id,
                'arrangement_id' => (int) $invitation->arrangement_id,
                'enterprise_mentor_id' => (int) $invitation->enterprise_mentor_id,
                'mentor_name' => (string) ($invitation->mentor_name ?? ''),
                'arrangement_title' => (string) ($invitation->arrangement_title ?? ''),
                'expires_at' => $sessionExpiresAt,
            ];
        });
    }

    /** 查询邀请对应的学生及评价状态。 */
    public static function studentsForSession(object $session): array
    {
        self::ensureTable();
        $query = self::queryTable('pair')
            ->join('students', 'pair.student_id', '=', 'students.student_id')
            ->join('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('internship_enterprise_evaluation as evaluation', function ($join): void {
                $join->on('evaluation.student_id', '=', 'pair.student_id')
                    ->on('evaluation.arrangement_id', '=', 'pair.arrangement_id')
                    ->whereNull('evaluation.deleted_at');
            })
            ->where('pair.arrangement_id', (int) $session->arrangement_id)
            ->where('pair.enterprise_mentor_id', (int) $session->enterprise_mentor_id)
            ->where('pair.type', 'internship')->where('pair.status', 'active')
            ->whereNull('pair.deleted_at')->whereNull('students.deleted_at')->whereNull('arrangement.deleted_at');
        self::applyGraduationScope($query);

        return $query->orderBy('students.student_num')->get([
                'pair.id as pair_id', 'pair.student_id', 'pair.arrangement_id', 'students.name as student_name', 'students.student_num',
                'evaluation.id as evaluation_id', 'evaluation.criteria_json', 'evaluation.total_score', 'evaluation.comment as evaluation_comment', 'evaluation.submitted_at', 'evaluation.status as evaluation_status',
            ])->map(static function ($row): array {
                $item = $row->toArray();
                $item['criteria_json'] = self::jsonArray($item['criteria_json'] ?? null);
                return $item;
            })->all();
    }

    /** 查询验证码会话对应的学生绑定。 */
    public static function studentPairForSession(object $session, int $studentId, bool $lock = false): ?object
    {
        self::ensureTable();
        $query = self::queryTable('pair')
            ->join('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->join('students', 'pair.student_id', '=', 'students.student_id')
            ->where('pair.arrangement_id', (int) $session->arrangement_id)
            ->where('pair.enterprise_mentor_id', (int) $session->enterprise_mentor_id)
            ->where('pair.student_id', $studentId)
            ->where('pair.type', 'internship')
            ->where('pair.status', 'active')
            ->whereNull('pair.deleted_at')
            ->whereNull('arrangement.deleted_at')
            ->whereNull('students.deleted_at');
        self::applyGraduationScope($query);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first(['pair.id as pair_id', 'pair.student_id', 'pair.arrangement_id', 'pair.enterprise_mentor_id']);
    }

    /** 查询学生毕业实习企业评价。 */
    public static function evaluationByStudentTask(int $studentId, int $arrangementId): ?object
    {
        self::ensureTable();

        $query = self::queryTable('internship_enterprise_evaluation as evaluation')
            ->join('arrangement', 'evaluation.arrangement_id', '=', 'arrangement.id')
            ->where('evaluation.student_id', $studentId)
            ->where('evaluation.arrangement_id', $arrangementId)
            ->whereNull('evaluation.deleted_at')
            ->whereNull('arrangement.deleted_at');
        self::applyGraduationScope($query);

        return $query->first(['evaluation.*']);
    }

    /** 查询企业评价规则，缺省返回毕业实习默认规则。 */
    public static function rule(string $practiceType = 'graduation'): array
    {
        self::ensureTable();
        $row = self::queryTable('internship_enterprise_evaluation_rule')->where('practice_type', $practiceType)->where('status', 'enabled')->whereNull('deleted_at')->first();
        $items = $row ? self::jsonArray($row->criteria_json) : [
            ['code' => 'attitude', 'name' => '工作态度', 'max_score' => 10],
            ['code' => 'ability', 'name' => '专业能力', 'max_score' => 10],
            ['code' => 'performance', 'name' => '实习表现', 'max_score' => 10],
        ];
        return ['practice_type' => $practiceType, 'total_score' => (float) ($row->total_score ?? 30), 'items' => $items];
    }

    /** 保存企业评价。 */
    public static function saveEvaluation(int $studentId, int $arrangementId, int $pairId, int $mentorId, array $values, string $now, string $sessionToken): int
    {
        self::ensureTable();
        self::ensureRecordingTable('internship_enterprise_evaluation_recording');

        return (int) self::connection()->transaction(function () use ($studentId, $arrangementId, $pairId, $mentorId, $values, $now, $sessionToken): int {
            $session = self::sessionByToken($sessionToken, date('Y-m-d H:i:s'), true);
            if (!$session || (int) $session->arrangement_id !== $arrangementId || (int) $session->enterprise_mentor_id !== $mentorId) {
                throw new RuntimeException('企业评价验证已失效，请重新获取邀请', 403);
            }
            $pair = self::studentPairForSession($session, $studentId, true);
            if (!$pair || (int) $pair->pair_id !== $pairId) {
                throw new RuntimeException('学生绑定已变化，请刷新后重试', 409);
            }
            $values['verification_id'] = (int) $session->session_id;
            $values['evaluator_mobile'] = (string) $session->mobile;
            $values['evaluator_name'] = (string) $session->mentor_name;
            $existing = self::queryTable('internship_enterprise_evaluation')
                ->where('student_id', $studentId)->where('arrangement_id', $arrangementId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            if ($existing && (string) $existing->status === 'submitted') {
                throw new RuntimeException('该学生的企业评价已提交', 409);
            }

            $data = array_merge($values, [
                'name' => '毕业实习企业评价', 'student_id' => $studentId, 'arrangement_id' => $arrangementId, 'pair_id' => $pairId,
                'enterprise_mentor_id' => $mentorId, 'submitted_at' => $now, 'status' => 'submitted', 'updated_at' => $now, 'deleted_at' => null,
            ]);
            if ($existing) {
                $evaluationId = (int) $existing->id;
                self::queryTable('internship_enterprise_evaluation')->where('id', $evaluationId)->update($data);
            } else {
                $evaluationId = (int) self::queryTable('internship_enterprise_evaluation')->insertGetId(array_merge([
                    'uuid' => self::uuidValue(), 'created_at' => $now,
                ], $data));
            }

            $recordingId = (int) self::queryTable('internship_enterprise_evaluation_recording')->insertGetId([
                'uuid' => self::uuidValue(), 'name' => '企业评价提交记录', 'parent_id' => $evaluationId,
                'entity_type' => 'enterprise_evaluation', 'entity_id' => $evaluationId, 'action' => 'submit',
                'operator_id' => null, 'from_status' => $existing ? (string) $existing->status : 'draft', 'to_status' => 'submitted',
                'opinion' => (string) ($values['comment'] ?? ''), 'content' => (string) ($values['comment'] ?? ''),
                'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            ]);
            self::queryTable('review_opinion')->insert([
                'uuid' => self::uuidValue(), 'entity_type' => 'enterprise_evaluation', 'entity_id' => $evaluationId,
                'recording_id' => $recordingId, 'teacher_id' => null, 'reviewer_id' => null,
                'opinion' => (string) ($values['comment'] ?? ''), 'score' => $values['total_score'] ?? null,
                'status' => 'submitted', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            ]);

            return $evaluationId;
        });
    }

    /** 保存企业评价规则。 */
    public static function saveRule(string $practiceType, array $criteria, float $totalScore, int $operatorId, string $now): int
    {
        self::ensureTable();
        $existing = self::queryTable('internship_enterprise_evaluation_rule')
            ->where('practice_type', $practiceType)->where('status', 'enabled')->whereNull('deleted_at')->first(['id']);
        $values = [
            'name' => '企业评价规则', 'practice_type' => $practiceType,
            'criteria_json' => json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'total_score' => $totalScore, 'created_by' => $operatorId, 'status' => 'enabled', 'updated_at' => $now, 'deleted_at' => null,
        ];
        if ($existing) {
            self::queryTable('internship_enterprise_evaluation_rule')->where('id', (int) $existing->id)->update($values);
            return (int) $existing->id;
        }

        return (int) self::queryTable('internship_enterprise_evaluation_rule')->insertGetId(array_merge([
            'uuid' => self::uuidValue(), 'created_at' => $now,
        ], $values));
    }

    /** 查询教师范围内的企业评价进度。 */
    public static function progressPage(array $scope, array $filters): array
    {
        self::ensureTable();
        $query = self::queryTable('pair')
            ->join('students', 'pair.student_id', '=', 'students.student_id')
            ->join('arrangement', 'pair.arrangement_id', '=', 'arrangement.id')
            ->leftJoin('internship_plan', 'arrangement.plan_id', '=', 'internship_plan.id')
            ->leftJoin('enterprise_mentor', 'pair.enterprise_mentor_id', '=', 'enterprise_mentor.id')
            ->leftJoin('department', 'students.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'students.profession_id', '=', 'profession.profession_id')
            ->leftJoin('grade_list', 'students.grade_id', '=', 'grade_list.grade_id')
            ->leftJoin('graduation_cohort', 'students.graduation_cohort_id', '=', 'graduation_cohort.cohort_id')
            ->leftJoin('internship_enterprise_evaluation as evaluation', function ($join): void {
                $join->on('evaluation.student_id', '=', 'pair.student_id')->on('evaluation.arrangement_id', '=', 'pair.arrangement_id')->whereNull('evaluation.deleted_at');
            })
            ->where('pair.type', 'internship')->where('pair.status', 'active')
            ->whereNull('pair.deleted_at')->whereNull('students.deleted_at')->whereNull('arrangement.deleted_at');
        self::applyGraduationScope($query);
        $role = (string) ($scope['role_type'] ?? '');
        if ($role === 'teacher' && (int) ($scope['teacher_id'] ?? 0) > 0) {
            $teacherId = (int) $scope['teacher_id'];
            $query->where(function ($teacherQuery) use ($teacherId): void {
                $teacherQuery->where('pair.teacher_id', $teacherId)
                    ->orWhere('pair.second_teacher_id', $teacherId);
            });
        } elseif ($role === 'teacher') {
            $query->whereRaw('1 = 0');
        } else {
            self::applyStudentScope($query, $scope);
        }
        return self::paginateProgress($query, $filters);
    }

    /** 执行企业评价进度分页查询。 */
    private static function paginateProgress(mixed $query, array $filters): array
    {
        foreach (['arrangement_id', 'student_id'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where('pair.' . $field, $filters[$field]);
            }
        }
        foreach (['dep_id', 'profession_id', 'grade_id', 'graduation_cohort_id'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where('students.' . $field, $filters[$field]);
            }
        }
        $evaluationStatus = trim((string) ($filters['evaluation_status'] ?? ''));
        if ($evaluationStatus === 'pending') {
            $query->whereNull('evaluation.id');
        } elseif ($evaluationStatus !== '') {
            $query->where('evaluation.status', $evaluationStatus);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('students.name', 'like', $like)->orWhere('students.student_num', 'like', $like)->orWhere('arrangement.title', 'like', $like)->orWhere('internship_plan.course_name', 'like', $like);
            });
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $total = (int) (clone $query)->count('pair.id');
        $items = $query->orderByDesc('pair.id')->forPage($page, $pageSize)->get([
            'pair.id as pair_id', 'pair.student_id', 'pair.arrangement_id', 'pair.enterprise_mentor_id', 'students.name as student_name', 'students.student_num',
            'students.dep_id', 'students.profession_id', 'students.grade_id', 'students.graduation_cohort_id',
            'department.dep_name', 'profession.profession_name', 'grade_list.grade_name', 'graduation_cohort.cohort_name',
            'enterprise_mentor.name as enterprise_mentor_name', 'enterprise_mentor.phone as enterprise_mentor_phone',
            'arrangement.title as arrangement_title', 'internship_plan.course_name', 'evaluation.criteria_json', 'evaluation.total_score', 'evaluation.comment', 'evaluation.submitted_at', 'evaluation.status as evaluation_status',
        ])->map(static function ($row): array {
            $item = $row->toArray();
            $item['criteria_json'] = self::jsonArray($item['criteria_json'] ?? null);
            return $item;
        })->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total]];
    }

    /** 按账号角色限制学生数据范围。 */
    private static function applyStudentScope(mixed $query, array $scope): void
    {
        $role = (string) ($scope['role_type'] ?? '');
        if (in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        }
        if ($role === 'college_admin') {
            self::whereInOrDeny($query, 'students.dep_id', (array) ($scope['dep_ids'] ?? []));
            return;
        }
        if ($role === 'profession_admin') {
            self::whereInOrDeny($query, 'students.profession_id', (array) ($scope['profession_ids'] ?? []));
            return;
        }
        $query->whereRaw('1 = 0');
    }

    /** 应用限定范围，无可用范围时拒绝查询。 */
    private static function whereInOrDeny(mixed $query, string $column, array $values): void
    {
        $values = array_values(array_filter(array_map('intval', $values), static fn (int $value): bool => $value > 0));
        $values ? $query->whereIn($column, $values) : $query->whereRaw('1 = 0');
    }

    /** 限定毕业实习任务，兼容旧任务类型。 */
    private static function applyGraduationScope(mixed $query): void
    {
        $query->where(function ($builder): void {
            $builder->where('arrangement.type', 'graduation')
                ->orWhereExists(function ($categoryQuery): void {
                    $categoryQuery->selectRaw('1')
                        ->from('internship_plan as graduation_plan')
                        ->join('internship_category as graduation_category', 'graduation_plan.category_id', '=', 'graduation_category.id')
                        ->whereColumn('graduation_plan.id', 'arrangement.plan_id')
                        ->whereNull('graduation_plan.deleted_at')
                        ->whereNull('graduation_category.deleted_at')
                        ->where(function ($typeQuery): void {
                            $typeQuery->where('graduation_category.code', 'graduation')
                                ->orWhere('graduation_category.scope_type', 'cohort');
                        });
                });
        });
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function ensureTable(): void
    {
        self::requireTables(['internship_enterprise_evaluation_invitation', 'internship_enterprise_evaluation_session', 'internship_enterprise_evaluation', 'internship_enterprise_evaluation_recording', 'internship_enterprise_evaluation_rule']);
    }

    /** 生成企业评价 UUID。 */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
