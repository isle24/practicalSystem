<?php
namespace app\server\workflow\adapters;
use app\model\channel\ExpenseRecord; use app\server\CurrentContext; use app\server\workflow\WorkflowEntityAdapter; use RuntimeException;
final class ExpenseAdapter implements WorkflowEntityAdapter
{
    public function load(int $id, bool $lock = false): array
    {
        $r = ExpenseRecord::find($id, $lock);
        if (!$r) throw new RuntimeException('经费申请不存在', 404);
        $r['status'] = $r['workflow_status'] ?? 'draft';
        return $r;
    }

    public function authorize(string $op, array $entity): void
    {
        $accountId = (int) CurrentContext::accountId();
        $role = (string) CurrentContext::roleType();
        $admin = in_array($role, ['super_admin', 'school_admin'], true);
        if ($op === 'view' && !ExpenseRecord::visible()->where('base_expense.id', (int) $entity['id'])->exists() && !ExpenseRecord::workflowParticipant((int) $entity['id'], $accountId)) {
            throw new RuntimeException('无权访问', 403);
        }
        if ($op === 'start' && (!in_array('expense:manage', CurrentContext::permissionCodes(), true) || (int) $entity['submitter_id'] !== $accountId)) {
            throw new RuntimeException('仅申请人可提交经费申请', 403);
        }
        if (in_array($op, ['start', 'cancel'], true) && !$admin && (!in_array('expense:manage', CurrentContext::permissionCodes(), true) || (int) $entity['submitter_id'] !== $accountId)) {
            throw new RuntimeException('无权操作', 403);
        }
    }

    public function snapshot(array $entity): array { return $entity; }

    public function transition(array $entity, string $status, array $context): void
    {
        ExpenseRecord::queryTable('base_expense')->where('id', $entity['id'])->update([
            'workflow_status' => $status === 'wait' ? 'wait' : $status,
            'status' => 'enabled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
