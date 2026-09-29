<?php

namespace app\model\channel;

use app\server\CurrentContext;

final class ExpenseRecord extends TableRecord
{
    public static function queryBase(): mixed
    {
        return self::queryTable('base_expense')->whereNull('deleted_at');
    }

    public static function find(int $id, bool $lock = false): ?array
    {
        $query = self::queryBase()->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        if (!$row) return null;
        $data = $row->toArray();
        $data['items'] = self::queryTable('base_expense_item')->where('expense_id', $id)->orderBy('sort')->get()->map(fn ($item) => $item->toArray())->all();
        $data['attachment_ids'] = json_decode((string) ($data['attachment_ids'] ?? '[]'), true) ?: [];
        $files = FileRecord::detailsByIds($data['attachment_ids']);
        $data['attachments'] = array_map(static fn ($file): array => [
            'file_id' => (int) $file->id,
            'name' => (string) ($file->download_name ?: $file->name),
            'url' => (string) $file->url,
        ], $files);
        return $data;
    }

    public static function visible(): mixed
    {
        $query = self::queryBase();
        $role = CurrentContext::roleType();
        if (!in_array($role, ['super_admin', 'school_admin'], true)) {
            $organizations = CurrentContext::organizationScopes();
            $scopes = array_values(array_filter(array_map('intval', array_column($organizations, 'dep_id')), static fn (int $value): bool => $value > 0));
            $professionIds = array_values(array_filter(array_map('intval', array_column($organizations, 'profession_id')), static fn (int $value): bool => $value > 0));
            $query->where(function ($scope) use ($scopes, $professionIds): void {
                $scope->where('submitter_id', CurrentContext::accountId());
                if ($scopes) $scope->orWhereIn('dep_id', $scopes);
                if ($professionIds) {
                    $scope->orWhereIn('base_id', self::queryTable('base_profession')->whereIn('profession_id', $professionIds)->whereNull('deleted_at')->select('base_id'));
                }
            });
        }
        return $query;
    }

    public static function workflowParticipant(int $id, int $accountId): bool
    {
        if ($id <= 0 || $accountId <= 0) return false;
        return WorkflowRecord::q('task')
            ->join('workflow_instance', 'workflow_instance.id', '=', 'workflow_task.instance_id')
            ->where('workflow_instance.entity_type', 'base_expense')
            ->where('workflow_instance.entity_id', $id)
            ->where('workflow_task.account_id', $accountId)
            ->exists();
    }

    public static function activeWorkflowParticipant(int $id, int $accountId): bool
    {
        if ($id <= 0 || $accountId <= 0 || !self::queryBase()->where('id', $id)->exists()) return false;
        return WorkflowRecord::q('task')
            ->join('workflow_instance', 'workflow_instance.id', '=', 'workflow_task.instance_id')
            ->where('workflow_instance.entity_type', 'base_expense')
            ->where('workflow_instance.entity_id', $id)
            ->where('workflow_instance.status', 'wait')
            ->where('workflow_task.account_id', $accountId)
            ->where(function ($query): void {
                $query->where(function ($review): void {
                    $review->where('workflow_task.kind', 'review')
                        ->where('workflow_task.status', 'pending')
                        ->whereColumn('workflow_task.position', 'workflow_instance.active_position');
                })->orWhere(function ($cc): void {
                    $cc->where('workflow_task.kind', 'cc')
                        ->where('workflow_task.status', 'notified')
                        ->whereColumn('workflow_task.position', '<', 'workflow_instance.active_position');
                });
            })
            ->exists();
    }

    public static function saveData(array $values, array $items, ?int $id = null): int
    {
        $now = date('Y-m-d H:i:s');
        return self::connection()->transaction(function () use ($values, $items, $id, $now): int {
            if ($id) {
                self::queryTable('base_expense')->where('id', $id)->update($values + ['updated_at' => $now]);
                self::queryTable('base_expense_item')->where('expense_id', $id)->delete();
            } else {
                $id = (int) self::queryTable('base_expense')->insertGetId($values + ['created_at' => $now, 'updated_at' => $now, 'uuid' => self::uuid(), 'status' => 'enabled']);
            }
            foreach ($items as $index => $item) {
                self::queryTable('base_expense_item')->insert([
                    'expense_id' => $id,
                    'project' => $item['project'],
                    'amount' => $item['amount'],
                    'sort' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            return $id;
        });
    }
}
