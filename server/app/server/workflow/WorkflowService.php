<?php

namespace app\server\workflow;

use app\model\channel\WorkflowRecord as R;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use RuntimeException;

class WorkflowService
{
    public function legacy(LegacyWorkflowAdapter $adapter, string $operation, \support\Request $request): array
    {
        R::actor();
        $registry = [
            \app\server\internship\InternshipStudentChangeService::class => ['review'],
            \app\server\internship\InternshipService::class => ['requestModification', 'reviewBaseFlow', 'reviewArrangement', 'reviewArrangementChange', 'reviewApplication', 'reviewJournal', 'reviewReport', 'reviewDelay', 'reviewPlan', 'reviewDocument', 'timeline'],
            \app\server\practice\PracticeService::class => ['requestModification', 'review', 'reviewJournal', 'reviewReport', 'timeline'],
            \app\server\socialpractice\SocialPracticeService::class => ['requestModification', 'review', 'confirmTeacher', 'timeline'],
        ];
        if (!in_array($operation, $registry[get_class($adapter)] ?? [], true)) {
            throw new RuntimeException('兼容流程适配器或操作未注册', 422);
        }
        return $adapter->executeLegacyWorkflow($operation, $request);
    }

    public function latest(string $entityType, int $entityId): ?array
    {
        $row = R::q('instance')->where('entity_type', $entityType)->where('entity_id', $entityId)->orderByDesc('round')->first();
        if (!$row) return null;
        $instance = R::row($row);
        $result = $this->result($instance);
        $result['can_review'] = $instance['status'] === 'wait' && R::q('task')->where('instance_id', $instance['id'])
            ->where('position', $instance['active_position'])->where('account_id', R::actor())
            ->where('kind', 'review')->where('status', 'pending')->exists();
        $result['signature_required'] = (bool) ($instance['nodes_json'][(int) $instance['active_position'] - 1]['signature_required'] ?? false);
        return $result;
    }

    public function startInTransaction(string $entityType, int $entityId, array $context): array
    {
        if (R::connection()->transactionLevel() < 1) throw new RuntimeException('提交需要主体事务', 409);
        return $this->startOperation($entityType, $entityId, $context, false);
    }

    public function start(string $entityType, int $entityId, array $context): array
    {
        return $this->startOperation($entityType, $entityId, $context, true);
    }

    private function startOperation(string $entityType, int $entityId, array $context, bool $acquireLock): array
    {
        $actor = R::actor();
        $requestId = trim((string) ($context['request_id'] ?? ''));
        if ($entityId <= 0 || !preg_match('/^[a-zA-Z0-9_.:-]{8,120}$/D', $requestId)) throw new RuntimeException('实体编号或请求幂等标识无效', 422);
        $definitions = new WorkflowDefinitionService();
        $adapter = $definitions->adapter($entityType);
        $key = hash('sha256', implode(':', ['start', $actor, $entityType, $entityId, $requestId]));
        $operation = function () use ($adapter, $definitions, $entityType, $entityId, $actor, $key): array {
            $entity = $adapter->load($entityId, true);
            $adapter->authorize('view', $entity);
            $prior = R::q('history')->where('idempotency_key', $key)->first();
            if ($prior) return R::row($prior)['result_json'];
            $adapter->authorize('start', $entity);
            if (!in_array($entity['status'] ?? '', ['draft', 'modify'], true)) throw new RuntimeException('仅草稿或退回状态允许提交', 409);
            $latest = R::q('instance')->where('entity_type', $entityType)->where('entity_id', $entityId)->orderByDesc('round')->lockForUpdate()->first();
            if ($latest && $latest->status === 'wait') throw new RuntimeException('该申请已有进行中的审批', 409);
            $flow = $definitions->resolveFor($entityType, (int) ($entity['dep_id'] ?? 0));
            $snapshot = $adapter->snapshot($entity);
            $id = R::q('instance')->insertGetId([
                'entity_type' => $entityType, 'entity_id' => $entityId, 'round' => $latest ? (int) $latest->round + 1 : 1,
                'definition_version_id' => $flow['version_id'], 'applicant_id' => $actor, 'status' => 'wait', 'revision' => 1,
                'request_key' => $key, 'snapshot_json' => R::json($snapshot), 'nodes_json' => R::json($flow['nodes']),
            ]);
            $instance = R::row(R::q('instance')->find($id));
            $this->advance($instance, 0);
            $adapter->transition($entity, $instance['status'], ['instance_id' => (int) $id, 'round' => $instance['round'], 'action' => 'start', 'actor_id' => $actor]);
            $this->saveInstance($instance);
            $result = $this->result($instance);
            $this->record($instance, 0, 'start', (string) $entity['status'], '', null, $key, $key, $result);
            return $result;
        };
        return $acquireLock ? $this->locked($entityType, $entityId, $operation) : $operation();
    }

    public function review(int $instanceId, string $action, string $opinion, ?int $signatureId, string $revision): array
    {
        $actor = R::actor();
        $opinion = trim($opinion);
        if (!in_array($action, ['accept', 'modify'], true) || !ctype_digit($revision) || (int) $revision < 1 || mb_strlen($opinion) > 2000 || ($action === 'modify' && $opinion === '')) throw new RuntimeException('审批动作、意见或版本无效', 422);
        $reference = R::q('instance')->find($instanceId);
        if (!$reference) throw new RuntimeException('审批实例不存在', 404);
        $adapter = (new WorkflowDefinitionService())->adapter($reference->entity_type);
        $key = hash('sha256', implode(':', ['review', $instanceId, $actor, $revision]));
        $hash = hash('sha256', R::json([$action, $opinion, $signatureId]));
        return $this->locked($reference->entity_type, (int) $reference->entity_id, function () use ($reference, $instanceId, $adapter, $actor, $action, $opinion, $signatureId, $revision, $key, $hash): array {
            $entity = $adapter->load((int) $reference->entity_id, true);
            $instance = R::row(R::q('instance')->where('id', $instanceId)->lockForUpdate()->first());
            $prior = R::q('history')->where('idempotency_key', $key)->first();
            if ($prior) {
                if (!hash_equals($prior->request_hash, $hash)) throw new RuntimeException('相同版本的审批请求内容不一致', 409);
                return R::row($prior)['result_json'];
            }
            if ($instance['status'] !== 'wait' || (string) $instance['revision'] !== $revision || ($entity['status'] ?? '') !== 'wait') throw new RuntimeException('审批状态或版本已改变，请刷新', 409);
            $position = (int) $instance['active_position'];
            $task = R::q('task')->where('instance_id', $instanceId)->where('position', $position)->where('account_id', $actor)->where('kind', 'review')->where('status', 'pending')->lockForUpdate()->first();
            if (!$task || !$this->activeAccount($actor)) throw new RuntimeException('当前账号不是该节点有效审批人', 403);
            $adapter->authorize('review', $entity);
            $node = $instance['nodes_json'][$position - 1] ?? null;
            if (!$node || $node['kind'] !== 'review') throw new RuntimeException('活动审批节点无效', 409);
            $signature = $this->signature($signatureId, (bool) $node['signature_required']);
            $now = date('Y-m-d H:i:s');
            R::q('task')->where('id', $task->id)->update(['status' => $action, 'handled_at' => $now, 'updated_at' => $now]);
            if ($action === 'modify') {
                $instance['status'] = 'modify';
                $instance['finished_at'] = $now;
                $this->cancelTasks($instanceId);
            } elseif ($node['mode'] === 'any' || !R::q('task')->where('instance_id', $instanceId)->where('position', $position)->where('status', 'pending')->exists()) {
                $this->cancelTasks($instanceId, $position);
                $this->advance($instance, $position);
            }
            $instance['revision'] = (int) $instance['revision'] + 1;
            $adapter->transition($entity, $instance['status'], ['instance_id' => $instanceId, 'round' => $instance['round'], 'action' => $action, 'opinion' => $opinion, 'actor_id' => $actor, 'signature' => $signature]);
            $this->saveInstance($instance);
            $result = $this->result($instance);
            $this->record($instance, $position, $action, 'wait', $opinion, $signature, $key, $hash, $result);
            return $result;
        });
    }

    public function cancel(string $entityType, int $entityId, string $reason): void
    {
        R::actor();
        $reason = trim($reason);
        if ($entityId <= 0 || $reason === '' || mb_strlen($reason) > 2000) throw new RuntimeException('取消原因无效', 422);
        $adapter = (new WorkflowDefinitionService())->adapter($entityType);
        $this->locked($entityType, $entityId, function () use ($adapter, $entityType, $entityId, $reason): void {
            $entity = $adapter->load($entityId, true);
            $adapter->authorize('cancel', $entity);
            $instance = R::row(R::q('instance')->where('entity_type', $entityType)->where('entity_id', $entityId)->orderByDesc('round')->lockForUpdate()->first());
            if (!$instance || $instance['status'] !== 'wait') return;
            $instance['status'] = 'cancelled';
            $instance['revision'] = (int) $instance['revision'] + 1;
            $instance['finished_at'] = date('Y-m-d H:i:s');
            $this->cancelTasks((int) $instance['id']);
            $adapter->transition($entity, 'cancelled', ['instance_id' => $instance['id'], 'round' => $instance['round'], 'action' => 'cancel', 'reason' => $reason, 'actor_id' => R::actor()]);
            $this->saveInstance($instance);
            $key = hash('sha256', 'cancel:' . $instance['id']);
            $this->record($instance, (int) $instance['active_position'], 'cancel', 'wait', $reason, null, $key, hash('sha256', $reason), $this->result($instance));
        });
    }

    public function cancelDeletedInternshipPlan(int $planId): void
    {
        R::actor();
        if (R::connection()->transactionLevel() < 1) throw new RuntimeException('删除取消需要主体事务', 409);
        $plan = R::queryTable('internship_plan')->where('id', $planId)->lockForUpdate()->first();
        if (!$plan || $plan->deleted_at === null) throw new RuntimeException('计划尚未删除', 409);
        $arrangements = R::queryTable('arrangement')->where('plan_id', $planId)->orderBy('id')->lockForUpdate()->pluck('id')->all();
        $subjects = ['plan' => [$planId], 'internship_plan' => [$planId], 'arrangement' => $arrangements, 'internship_arrangement' => $arrangements];
        $tables = [
            'application' => ['application', 'arrangement_id'],
            'student_change' => ['internship_student_change', 'arrangement_id'],
            'arrangement_change' => ['arrangement_change', 'arrangement_id'],
            'journal' => ['journal', 'entity_id'],
            'sign_in' => ['sign_in', 'entity_id'],
            'score' => ['score', 'arrangement_id'],
            'insurance' => ['insurance', 'arrangement_id'],
            'safety_letter' => ['safety_letter_sign', 'arrangement_id'],
            'inspection' => ['inspection_record', 'arrangement_id'],
            'graduation_appraisal' => ['internship_graduation_appraisal', 'arrangement_id'],
            'report' => ['report', 'arrangement_id'],
            'delay' => ['apply_report_delay', 'entity_id'],
            'syllabus_guide' => ['syllabus_guide', 'arrangement_id'],
            'implementation_sheet' => ['implementation_sheet', 'arrangement_id'],
            'teacher_work_report' => ['teacher_work_report', 'arrangement_id'],
        ];
        foreach ($tables as $type => [$table, $foreignKey]) {
            $query = R::queryTable($table)->whereIn($foreignKey, $arrangements);
            if (in_array($type, ['sign_in', 'journal', 'delay'], true)) $query->where('entity_type', 'internship');
            $ids = $query->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $subjects[$type] = $ids;
            $subjects['internship_' . $type] = $ids;
        }
        foreach ($subjects as $type => $ids) {
            if (!$ids) continue;
            $instances = R::q('instance')->where('entity_type', $type)->whereIn('entity_id', $ids)->where('status', 'wait')->orderBy('id')->lockForUpdate()->get();
            foreach ($instances as $row) {
                $instance = R::row($row);
                $instance['status'] = 'cancelled';
                $instance['revision'] = (int) $instance['revision'] + 1;
                $instance['finished_at'] = date('Y-m-d H:i:s');
                $this->cancelTasks((int) $instance['id']);
                R::q('outbox')->where('instance_id', $instance['id'])->whereIn('status', ['pending', 'retry', 'processing'])->update([
                    'status' => 'skipped', 'last_error' => '所属实习计划已删除', 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $this->saveInstance($instance);
                $key = hash('sha256', 'cancel:' . $instance['id']);
                $reason = '所属实习计划已删除';
                $this->record($instance, (int) $instance['active_position'], 'cancel', 'wait', $reason, null, $key, hash('sha256', $reason), $this->result($instance));
            }
        }
    }

    public function history(string $entityType, int $entityId): array
    {
        $actor = R::actor();
        $adapter = (new WorkflowDefinitionService())->adapter($entityType);
        $entity = $adapter->load($entityId);
        $participant = R::q('task')->join('workflow_instance', 'workflow_instance.id', '=', 'workflow_task.instance_id')->where('workflow_instance.entity_type', $entityType)->where('workflow_instance.entity_id', $entityId)->where('workflow_task.account_id', $actor)->exists();
        if (!$participant) $adapter->authorize('view', $entity);
        $items = R::q('instance')->where('entity_type', $entityType)->where('entity_id', $entityId)->orderByDesc('round')->get()->map(fn ($row) => R::row($row))->all();
        foreach ($items as &$item) {
            $item['revision'] = (string) $item['revision'];
            $item['tasks'] = R::q('task')->where('instance_id', $item['id'])->orderBy('position')->orderBy('id')->get()->map(fn ($row) => R::row($row))->all();
            $item['history'] = R::q('history')->where('instance_id', $item['id'])->orderBy('id')->get()->map(fn ($row) => R::row($row))->all();
            unset($item['request_key']);
        }
        unset($item);
        return ['items' => $items];
    }

    public function inbox(array $filters = []): array
    {
        $actor = R::actor();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $size = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $query = R::q('task')->join('workflow_instance', 'workflow_instance.id', '=', 'workflow_task.instance_id')->where('workflow_task.account_id', $actor);
        $kind = ($filters['kind'] ?? '') === 'cc' ? 'cc' : 'review';
        $query->where('workflow_task.kind', $kind);
        if ($kind === 'review') $query->where('workflow_task.status', 'pending')->where('workflow_instance.status', 'wait')->whereColumn('workflow_task.position', 'workflow_instance.active_position');
        $total = (int) (clone $query)->count();
        $items = $query->orderByDesc('workflow_task.id')->forPage($page, $size)->get(['workflow_task.id as task_id', 'workflow_task.kind', 'workflow_task.position', 'workflow_task.status as task_status', 'workflow_instance.id as instance_id', 'workflow_instance.entity_type', 'workflow_instance.entity_id', 'workflow_instance.round', 'workflow_instance.status', 'workflow_instance.revision', 'workflow_instance.snapshot_json', 'workflow_instance.nodes_json'])->map(function ($row): array {
            $item = R::row($row);
            $item['revision'] = (string) $item['revision'];
            return $item;
        })->all();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    private function advance(array &$instance, int $afterPosition): void
    {
        $notifications = new WorkflowNotificationService();
        foreach ($instance['nodes_json'] as $index => $node) {
            $position = $index + 1;
            if ($position <= $afterPosition) continue;
            foreach ($node['candidates'] as $candidate) R::q('task')->insert(['instance_id' => $instance['id'], 'position' => $position, 'account_id' => $candidate['account_id'], 'kind' => $node['kind'], 'status' => $node['kind'] === 'cc' ? 'notified' : 'pending']);
            $notifications->enqueueNode($instance, $position, $node);
            $instance['active_position'] = $position;
            if ($node['kind'] === 'review') return;
        }
        $instance['status'] = 'accept';
        $instance['active_position'] = 0;
        $instance['finished_at'] = date('Y-m-d H:i:s');
    }

    private function saveInstance(array $instance): void
    {
        R::q('instance')->where('id', $instance['id'])->update(['status' => $instance['status'], 'active_position' => $instance['active_position'], 'revision' => $instance['revision'], 'finished_at' => $instance['finished_at'] ?? null, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function result(array $instance): array
    {
        return ['instance_id' => (int) $instance['id'], 'entity_type' => $instance['entity_type'], 'entity_id' => (int) $instance['entity_id'], 'round' => (int) $instance['round'], 'status' => $instance['status'], 'active_position' => (int) $instance['active_position'], 'revision' => (string) $instance['revision']];
    }

    private function record(array $instance, int $position, string $action, string $from, string $opinion, ?array $signature, string $key, string $hash, array $result): void
    {
        R::q('history')->insert(['instance_id' => $instance['id'], 'position' => $position, 'actor_id' => R::actor(), 'action' => $action, 'from_status' => $from, 'to_status' => $instance['status'], 'opinion' => $opinion, 'signature_json' => $signature === null ? null : R::json($signature), 'idempotency_key' => $key, 'request_hash' => $hash, 'result_json' => R::json($result)]);
    }

    private function cancelTasks(int $instanceId, ?int $position = null): void
    {
        $query = R::q('task')->where('instance_id', $instanceId)->where('status', 'pending');
        if ($position !== null) $query->where('position', $position);
        $query->update(['status' => 'cancelled', 'handled_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function signature(?int $id, bool $required): ?array
    {
        if (!$id) {
            if ($required) throw new RuntimeException('该节点需要本人电子签名', 422);
            return null;
        }
        $class = config('workflow.signature_snapshot');
        if (!is_string($class) || !class_exists($class) || !method_exists($class, 'snapshotForApproval')) throw new RuntimeException('签名服务尚未配置', 422);
        $snapshot = (new $class())->snapshotForApproval($id);
        if (!is_array($snapshot) || !$snapshot) throw new RuntimeException('签名快照无效', 422);
        return $snapshot;
    }

    private function activeAccount(int $id): bool
    {
        return R::queryTable('account')->join('users', 'users.id', '=', 'account.user_id')->where('account.id', $id)->where('account.status', 'enabled')->where('users.status', 'enabled')->whereNull('account.deleted_at')->whereNull('users.deleted_at')->exists();
    }

    private function locked(string $type, int $id, callable $callback): mixed
    {
        return (new WorkflowLock())->run(WorkflowLock::key('generic_workflow', $type, $id), fn () => R::connection()->transaction($callback), 30, true);
    }
}
