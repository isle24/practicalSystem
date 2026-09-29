<?php

namespace app\server\workflow;

use app\model\channel\WorkflowRecord as R;
use RuntimeException;

class WorkflowNotificationService
{
    public function enqueueNode(array $instance, int $position, array $node): void
    {
        if (R::connection()->transactionLevel() < 1) throw new RuntimeException('流程通知必须与状态在同一事务写入');
        if (empty($node['notification']['enabled'])) return;
        $tasks = R::q('task')->where('instance_id', $instance['id'])->where('position', $position)->get();
        foreach ($tasks as $task) {
            foreach ($node['notification']['channels'] as $channel) {
                $key = hash('sha256', implode(':', [$instance['id'], $position, $task->account_id, $channel, 'activated']));
                R::q('outbox')->insertOrIgnore([
                    'instance_id' => $instance['id'], 'position' => $position, 'recipient_id' => $task->account_id,
                    'channel' => $channel, 'template' => $node['notification']['template'], 'dedupe_key' => $key,
                    'payload_json' => R::json(['event' => 'node_activated', 'entity_type' => $instance['entity_type'], 'entity_id' => (int) $instance['entity_id'], 'instance_id' => (int) $instance['id'], 'round' => (int) $instance['round'], 'task_id' => (int) $task->id, 'node_name' => $node['name'], 'kind' => $node['kind'], 'snapshot' => $instance['snapshot_json']]),
                    'status' => 'pending', 'available_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function claim(int $limit = 50): array
    {
        return R::connection()->transaction(function () use ($limit): array {
            $now = date('Y-m-d H:i:s');
            $rows = R::q('outbox')->where('available_at', '<=', $now)->where(function ($q) use ($now): void {
                $q->whereIn('status', ['pending', 'retry'])->orWhere(function ($stale) use ($now): void {
                    $stale->where('status', 'processing')->where('locked_until', '<=', $now);
                });
            })->orderBy('id')->limit(min(100, max(1, $limit)))->lock('FOR UPDATE SKIP LOCKED')->get();
            $items = [];
            foreach ($rows as $row) {
                $item = R::row($row);
                if (($item['payload_json']['kind'] ?? '') === 'review' && !R::q('task')->where('id', $item['payload_json']['task_id'] ?? 0)->where('status', 'pending')->exists()) {
                    R::q('outbox')->where('id', $item['id'])->update(['status' => 'skipped', 'last_error' => '审批待办已结束', 'locked_until' => null, 'claim_token' => null, 'updated_at' => $now]);
                    continue;
                }
                if ((int) $item['attempts'] >= 10) {
                    R::q('outbox')->where('id', $item['id'])->update(['status' => 'failed', 'last_error' => '通知投递超过重试次数', 'locked_until' => null, 'claim_token' => null, 'updated_at' => $now]);
                    continue;
                }
                $item['claim_token'] = bin2hex(random_bytes(16));
                $item['attempts'] = (int) $item['attempts'] + 1;
                $item['status'] = 'processing';
                $item['locked_until'] = date('Y-m-d H:i:s', time() + 300);
                R::q('outbox')->where('id', $item['id'])->update(['status' => $item['status'], 'claim_token' => $item['claim_token'], 'attempts' => $item['attempts'], 'locked_until' => $item['locked_until'], 'updated_at' => $now]);
                $items[] = $item;
            }
            return $items;
        });
    }

    public function markQueued(int $id, string $token): void
    {
        $this->finish($id, $token, ['status' => 'queued', 'last_error' => null]);
    }

    public function defer(int $id, string $token, int $delay): void
    {
        $row = R::q('outbox')->where('id', $id)->where('claim_token', $token)->first();
        $this->finish($id, $token, ['status' => 'retry', 'attempts' => max(0, (int) ($row->attempts ?? 1) - 1), 'available_at' => date('Y-m-d H:i:s', time() + $delay)]);
    }

    public function markSent(int $id, string $token): void
    {
        $this->finish($id, $token, ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
    }

    public function markSkipped(int $id, string $token, string $reason): void
    {
        $this->finish($id, $token, ['status' => 'skipped', 'last_error' => mb_substr($reason, 0, 2000)]);
    }

    public function markFailed(int $id, string $token, string $reason, bool $retry = true): void
    {
        $row = R::q('outbox')->where('id', $id)->where('claim_token', $token)->where('status', 'processing')->first();
        if (!$row) throw new RuntimeException('通知认领已失效', 409);
        $this->finish($id, $token, ['status' => $retry && (int) $row->attempts < 10 ? 'retry' : 'failed', 'last_error' => mb_substr($reason, 0, 2000), 'available_at' => date('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(7, (int) $row->attempts))))]);
    }

    private function finish(int $id, string $token, array $values): void
    {
        $changed = R::q('outbox')->where('id', $id)->where('claim_token', $token)->where('status', 'processing')->update($values + ['claim_token' => null, 'locked_until' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        if (!$changed) throw new RuntimeException('通知认领已失效', 409);
    }
}
