<?php

namespace app\server\internship;

use app\model\channel\BaseVisitRecord;
use app\server\CurrentContext;
use app\server\file\FileService;
use DateTimeImmutable;
use RuntimeException;

class BaseVisitService
{
    private const ADMIN_ROLES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const STATES = ['pending_time', 'scheduled', 'completed', 'cancelled'];

    public function options(array $input): array
    {
        $this->assertReady();
        $assign = $this->canAssign();
        return [
            'can_assign' => $assign, 'is_teacher' => CurrentContext::roleType() === 'teacher',
            'bases' => $assign ? BaseVisitRecord::bases($this->text($input, 'base_keyword', 180)) : [],
            'teachers' => $assign ? BaseVisitRecord::teachers($this->text($input, 'teacher_keyword', 180)) : [],
            'participants' => $assign ? BaseVisitRecord::participants($this->text($input, 'participant_keyword', 180)) : [],
            'supervisors' => $assign ? BaseVisitRecord::participants($this->text($input, 'supervisor_keyword', 180), ['super_admin', 'school_admin', 'college_admin', 'profession_admin']) : [],
            'contacts' => $assign ? BaseVisitRecord::contacts($this->text($input, 'contact_keyword', 180)) : [],
            'departments' => CurrentContext::roleType() === 'teacher' ? [] : BaseVisitRecord::departments(),
        ];
    }

    public function page(array $input): array
    {
        $this->assertReady();
        $filters = [
            'page' => $this->integer($input, 'page', false) ?: 1,
            'page_size' => $this->integer($input, 'page_size', false) ?: 20,
            'keyword' => $this->text($input, 'keyword', 180),
            'status' => $this->text($input, 'status', 40),
            'dep_id' => $this->integer($input, 'dep_id', false),
            'visit_date' => $this->text($input, 'visit_date', 10),
        ];
        if ($filters['status'] !== '' && !in_array($filters['status'], self::STATES, true)) throw new RuntimeException('安排状态无效', 400);
        if ($filters['visit_date'] !== '') $this->date($filters['visit_date'], 'Y-m-d', '走访日期');
        $page = BaseVisitRecord::page($filters);
        $page['items'] = array_map(fn ($item) => $this->item($item), $page['items']);
        return $page;
    }

    public function detail(int $id): array
    {
        $this->assertReady();
        $plan = BaseVisitRecord::plan($id);
        if (!$plan) throw new RuntimeException('安排不存在或无权访问', 404);
        $plan['conflict_count'] = BaseVisitRecord::conflicts($plan);
        $record = BaseVisitRecord::record($id);
        if ($record) $record['attachments'] = (new FileService())->relations('base_visit_record', (int) $record['id'], 'attachment');
        return ['item' => $this->item($plan), 'record' => $record, 'permissions' => $this->permissions($plan)];
    }

    public function assign(array $input): array
    {
        $this->assertReady();
        if (!$this->canAssign()) throw new RuntimeException('无安排基地巡查的权限', 403);
        $base = BaseVisitRecord::base($this->integer($input, 'base_id'));
        if (!$base) throw new RuntimeException('基地不在可选范围内', 403);
        $participantIds = $this->participantIds($input);
        if (!$participantIds) throw new RuntimeException('请选择走访人员', 400);
        $participantRows = BaseVisitRecord::participantsByIds($participantIds);
        $participantRows = array_values(array_reduce($participantRows, static function (array $carry, array $row): array { $carry[(int) $row['account_id']] = $row; return $carry; }, []));
        if (count($participantRows) !== count($participantIds)) throw new RuntimeException('走访人员不在可选范围内', 403);
        $teacher = null;
        foreach ($participantRows as $row) if (($row['role_type'] ?? '') === 'teacher' && !empty($row['teacher_id'])) { $teacher = $row; break; }
        $teacherId = $teacher ? (int) $teacher['teacher_id'] : null;
        $supervisorId = $this->integer($input, 'supervisor_id', false) ?: null;
        if ($supervisorId && !in_array($supervisorId, BaseVisitRecord::accountIds([$supervisorId], ['super_admin', 'school_admin', 'college_admin', 'profession_admin']), true)) {
            throw new RuntimeException('主管院长不在可选范围内', 403);
        }
        $contactAccountId = $this->integer($input, 'contact_account_id', false) ?: null;
        if ($contactAccountId && !in_array($contactAccountId, BaseVisitRecord::visibleEnabledAccountIds([$contactAccountId]), true)) throw new RuntimeException('联系人账号无效', 403);
        $values = [
            'title' => $this->text($input, 'title', 180) ?: '基地走访',
            'base_id' => (int) $base['id'], 'teacher_id' => $teacherId, 'dep_id' => (int) ($base['dep_id'] ?? 0),
            'base_name' => $base['name'], 'base_address' => $base['address'], 'base_department' => $base['dep_name'],
            'base_category' => $base['base_category'] ?? '', 'base_location' => $base['district'] ?? '',
            'base_manager_name' => $base['manager_name'] ?? '', 'base_manager_phone' => $base['manager_phone'] ?? '',
            'teacher_name' => $teacher['name'] ?? ($teacher['teacher_name'] ?? ''), 'teacher_department' => $teacher['dep_name'] ?? '',
            'contact_account_id' => $contactAccountId, 'contact_person' => $this->text($input, 'contact_person', 180),
            'contact_phone' => $this->text($input, 'contact_phone', 40), 'supervisor_id' => $supervisorId,
            'participant_ids' => json_encode($participantIds, JSON_THROW_ON_ERROR), 'remark' => $this->text($input, 'remark', 10000),
            'news_url' => $this->url($input, 'news_url', 500),
        ];
        $id = $this->integer($input, 'id', false);
        if (!$id) {
            $id = BaseVisitRecord::connection()->transaction(function () use ($values): int {
                $id = BaseVisitRecord::createPlan($values + [
                    'status' => 'pending_time', 'revision' => 1, 'created_by' => CurrentContext::accountId(), 'updated_by' => CurrentContext::accountId(),
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                BaseVisitRecord::syncParticipants($id, json_decode((string) $values['participant_ids'], true) ?: []);
                return $id;
            });
            return $this->detail($id);
        }
        return $this->mutate($input, function ($plan) use ($values, $participantIds, $teacherId): array {
            if (!$this->permissions($plan)['can_assign']) throw new RuntimeException('已完成或已取消的安排不能修改', 409);
            $oldParticipants = BaseVisitRecord::participantIds($plan);
            $changed = (int) $plan['base_id'] !== (int) $values['base_id'] || $oldParticipants !== $participantIds;
            if ($changed) $values += ['visit_date' => null, 'visit_period' => null, 'start_time' => null, 'end_time' => null, 'scheduled_at' => null, 'status' => 'pending_time'];
            return $values;
        });
    }

    public function schedule(array $input): array
    {
        $date = $this->text($input, 'visit_date', 10, true);
        $this->date($date, 'Y-m-d', '走访日期');
        $start = $this->time($this->text($input, 'start_time', 8, true));
        $end = $this->time($this->text($input, 'end_time', 8, true));
        if ($end <= $start) throw new RuntimeException('结束时间必须晚于开始时间', 400);
        $period = $start < '12:00:00' ? 'am' : 'pm';
        return $this->mutate($input, function ($plan) use ($date, $period, $start, $end): array {
            if (!$this->permissions($plan)['can_schedule']) throw new RuntimeException('当前安排不能填写时间', 403);
            $participantIds = BaseVisitRecord::participantIds($plan);
            BaseVisitRecord::lockParticipants($participantIds);
            $conflicts = BaseVisitRecord::conflictingParticipants((int) $plan['id'], $date, $start, $end, $participantIds);
            if ($conflicts) {
                $names = implode('、', array_values(array_unique(array_map(fn (array $row): string => (string) ($row['base_name'] ?? '其他基地'), $conflicts))));
                throw new RuntimeException('走访人员存在时间冲突：' . $names, 409);
            }
            return ['visit_date' => $date, 'visit_period' => $period, 'start_time' => $start, 'end_time' => $end,
                'participant_ids' => json_encode($participantIds, JSON_THROW_ON_ERROR), 'status' => 'scheduled', 'scheduled_at' => date('Y-m-d H:i:s')];
        });
    }

    public function saveRecord(array $input): array
    {
        $actualAt = $this->text($input, 'actual_at', 19, true);
        $actual = $this->date($actualAt, 'Y-m-d H:i:s', '实际走访时间');
        if ($actual->getTimestamp() > time() + 60) throw new RuntimeException('实际走访时间不能晚于当前时间', 400);
        $ids = $input['attachment_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 20) throw new RuntimeException('附件最多 20 个', 400);
        $ids = array_values(array_unique(array_map(fn ($id) => $this->integer(['file_id' => $id], 'file_id'), $ids)));
        $values = [
            'actual_at' => $actualAt, 'participants' => $this->text($input, 'participants', 500),
            'contact_person' => $this->text($input, 'contact_person', 180), 'content' => $this->text($input, 'content', 20000, true),
            'problems' => $this->text($input, 'problems', 10000), 'follow_up' => $this->text($input, 'follow_up', 10000),
            'attachment_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
            'updated_by' => CurrentContext::accountId(), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        return $this->mutate($input, function ($plan) use ($ids, $values): array {
            if (!$this->permissions($plan)['can_record']) throw new RuntimeException('请先填写走访时间，且只能填写本人或授权范围内的记录', 403);
            $files = new FileService();
            $files->assertReadableReferences(['attachment_ids' => $ids]);
            $recordId = BaseVisitRecord::saveRecord((int) $plan['id'], $values);
            $files->replaceRelations($ids, 'base_visit_record', $recordId, 'attachment');
            return ['status' => 'completed'];
        });
    }

    public function cancel(array $input): array
    {
        $reason = $this->text($input, 'reason', 1000, true);
        return $this->mutate($input, function ($plan) use ($reason): array {
            if (!$this->permissions($plan)['can_cancel']) throw new RuntimeException('当前安排不能取消', 403);
            return ['status' => 'cancelled', 'cancel_reason' => $reason];
        });
    }

    private function participantIds(array $input): array
    {
        $raw = $input['participant_ids'] ?? $input['participants'] ?? [];
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) $raw = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) {
            $teacherId = $this->integer($input, 'teacher_id', false);
            if ($teacherId) {
                $accountId = BaseVisitRecord::teacherAccountId($teacherId);
                if ($accountId) $ids[] = $accountId;
            }
        }
        return $ids;
    }

    private function mutate(array $input, callable $callback): array
    {
        $this->assertReady();
        $id = $this->integer($input, 'id');
        $revision = $this->integer($input, 'revision');
        BaseVisitRecord::connection()->transaction(function () use ($id, $revision, $callback): void {
            $plan = BaseVisitRecord::plan($id, true);
            if (!$plan) throw new RuntimeException('安排不存在或无权访问', 404);
            if ((int) $plan['revision'] !== $revision) throw new RuntimeException('安排已被修改，请重新打开后保存', 409);
            $values = $callback($plan);
            BaseVisitRecord::updatePlan($id, $values + ['revision' => $revision + 1,
                'updated_by' => CurrentContext::accountId(), 'updated_at' => date('Y-m-d H:i:s')]);
        });
        return $this->detail($id);
    }

    private function item(array $plan): array
    {
        foreach (['id', 'base_id', 'teacher_id', 'dep_id', 'revision', 'conflict_count'] as $key) $plan[$key] = (int) ($plan[$key] ?? 0);
        return $plan + $this->permissions($plan);
    }

    private function permissions(array $plan): array
    {
        $admin = $this->canAssign();
        $own = CurrentContext::roleType() === 'teacher';
        $open = in_array($plan['status'], ['pending_time', 'scheduled'], true);
        return ['can_assign' => $admin && $open, 'can_schedule' => ($admin || $own) && $open,
            'can_record' => ($admin || $own) && in_array($plan['status'], ['scheduled', 'completed'], true), 'can_cancel' => $admin && $open];
    }

    private function canAssign(): bool
    {
        return in_array(CurrentContext::roleType(), self::ADMIN_ROLES, true) && in_array('internship:manage', CurrentContext::permissionCodes(), true);
    }

    private function assertReady(): void
    {
        if (!CurrentContext::accountId() || !CurrentContext::schoolDatabaseId() || !CurrentContext::get('school_connection')
            || !in_array(CurrentContext::roleType(), [...self::ADMIN_ROLES, 'teacher'], true)
            || !in_array('internship:view', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无基地巡查访问权限', 403);
        }
        if (!BaseVisitRecord::installed()) throw new RuntimeException('基地巡查结构尚未升级，请管理员在系统配置的数据库结构中生成升级 SQL', 409);
    }

    private function integer(array $input, string $key, bool $required = true): ?int
    {
        $value = $input[$key] ?? null;
        if (!$required && ($value === null || $value === '' || $value === 0 || $value === '0')) return null;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) throw new RuntimeException($key . ' 参数无效', 400);
        return (int) $value;
    }

    private function text(array $input, string $key, int $max, bool $required = false): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) throw new RuntimeException($key . ' 参数无效', 400);
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $max) throw new RuntimeException($key . ' 不能为空或超过长度限制', 400);
        return $value;
    }

    private function url(array $input, string $key, int $max): ?string
    {
        $value = $this->text($input, $key, $max);
        if ($value === '') return null;
        if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('/^https?:\\/\\//i', $value)) {
            throw new RuntimeException($key . ' 必须是 http 或 https 链接', 400);
        }
        return $value;
    }

    private function date(string $value, string $format, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if (!$date || $date->format($format) !== $value || (int) $date->format('Y') < 2000 || (int) $date->format('Y') > 2999) {
            throw new RuntimeException($label . ' 格式无效', 400);
        }
        return $date;
    }

    private function time(string $value): string
    {
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](?::00)?$/D', $value)) throw new RuntimeException('时间格式应为时:分', 400);
        return substr($value, 0, 5) . ':00';
    }
}
