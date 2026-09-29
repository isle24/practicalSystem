<?php

namespace app\server\expense;

use app\model\channel\BaseVisitRecord;
use app\model\channel\ExpenseRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\file\FileService;
use RuntimeException;

final class ExpenseService
{
    private const PROJECTS = ['基地基础建设', '企业导师队伍建设', '校内教师实践', '校企课程开发', '教赛结合', '评估激励', '资料完善及成果打造'];

    public function list(array $input): array
    {
        $this->requirePermission('expense:view');
        $query = ExpenseRecord::visible();
        if (($input['status'] ?? '') !== '') $query->where('workflow_status', (string) $input['status']);
        $page = max(1, (int) ($input['page'] ?? 1));
        $size = min(100, max(1, (int) ($input['page_size'] ?? 20)));
        $total = (int) (clone $query)->count();
        return ['items' => $query->orderByDesc('id')->forPage($page, $size)->get()->map(fn ($row) => $row->toArray())->all(), 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    public function detail(int $id): array
    {
        if (!CurrentContext::accountId()) throw new RuntimeException('请先登录', 401);
        $record = ExpenseRecord::find($id);
        $visible = in_array('expense:view', CurrentContext::permissionCodes(), true)
            && ExpenseRecord::visible()->where('base_expense.id', $id)->exists();
        if (!$record || (!$visible && !ExpenseRecord::workflowParticipant($id, (int) CurrentContext::accountId()))) throw new RuntimeException('经费申请不存在', 404);
        $record['submitter_name'] = (string) (ExpenseRecord::queryTable('account')->join('users', 'users.id', '=', 'account.user_id')->where('account.id', (int) ($record['submitter_id'] ?? 0))->value('users.name') ?? '');
        $record['submitted_at'] = (string) (\app\model\channel\WorkflowRecord::q('instance')->where('entity_type', 'base_expense')->where('entity_id', $id)->orderByDesc('round')->value('created_at') ?? '');
        return ['item' => $record];
    }

    public function save(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $operation = fn (): array => ExpenseRecord::connection()->transaction(fn (): array => $this->saveUnlocked($input));
        if ($id > 0) return (new WorkflowLock())->run(WorkflowLock::key('generic_workflow', 'base_expense', $id), $operation, 30, true);
        return $operation();
    }

    private function saveUnlocked(array $input): array
    {
        $this->requirePermission('expense:manage');
        $items = array_values((array) ($input['items'] ?? []));
        if (!$items) throw new RuntimeException('费用明细不能为空', 422);
        $sumCents = 0;
        foreach ($items as &$item) {
            $project = trim((string) ($item['project'] ?? ''));
            $amount = trim((string) ($item['amount'] ?? ''));
            if ($project === '' || mb_strlen($project) > 180) throw new RuntimeException('建设项目无效', 422);
            if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D', $amount)) throw new RuntimeException('金额无效', 422);
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
            if ($cents <= 0 || $cents > 999999999999 || ($sumCents += $cents) > 999999999999) throw new RuntimeException('金额超出允许范围', 422);
            $item = ['project' => $project, 'amount' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100)];
        }
        unset($item);

        $id = (int) ($input['id'] ?? 0);
        if ($id) {
            $old = ExpenseRecord::find($id, true);
            if (!$old || !ExpenseRecord::visible()->where('base_expense.id', $id)->exists()) throw new RuntimeException('经费申请不存在', 404);
            if (!in_array((string) $old['workflow_status'], ['draft', 'modify'], true)) throw new RuntimeException('当前状态不可编辑', 409);
            if ((int) $old['submitter_id'] !== (int) CurrentContext::accountId()) throw new RuntimeException('无权编辑', 403);
        }
        $baseId = max(0, (int) ($input['base_id'] ?? 0));
        $base = $baseId ? BaseVisitRecord::base($baseId) : null;
        if (!$base) throw new RuntimeException('实习基地不存在或不在当前数据范围', 422);
        $depId = max(0, (int) ($input['dep_id'] ?? $base['dep_id'] ?? 0));
        $department = $this->unitsQuery()->where('dep_id', $depId)->first(['dep_name']);
        if (!$department) throw new RuntimeException('申请单位无效或不在当前数据范围', 422);
        $semester = trim((string) ($input['semester'] ?? ''));
        if ($semester !== '' && !ExpenseRecord::queryTable('arrangement')->where('semester', $semester)->whereNull('deleted_at')->exists()) throw new RuntimeException('学年学期无效', 422);
        $attachmentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['attachment_ids'] ?? [])), static fn (int $value): bool => $value > 0)));
        (new FileService())->assertReadableReferences(['attachment_ids' => $attachmentIds]);
        $values = [
            'name' => (string) $department->dep_name,
            'title' => trim((string) ($input['title'] ?? $base['name'] ?? '')),
            'base_id' => $baseId,
            'dep_id' => $depId,
            'term_id' => max(0, (int) ($input['term_id'] ?? 0)) ?: null,
            'semester' => $semester,
            'expense_type' => '基地建设费用',
            'base_type' => (string) ($base['base_type'] ?? ''),
            'base_category' => (string) ($base['base_category'] ?? ''),
            'total_amount' => sprintf('%d.%02d', intdiv($sumCents, 100), $sumCents % 100),
            'amount' => sprintf('%d.%02d', intdiv($sumCents, 100), $sumCents % 100),
            'amount_upper' => $this->amountUpper($sumCents),
            'attachment_ids' => json_encode($attachmentIds, JSON_UNESCAPED_UNICODE),
            'remark' => trim((string) ($input['remark'] ?? '')),
            'submitter_id' => CurrentContext::accountId(),
            'workflow_status' => 'draft',
        ];
        $id = ExpenseRecord::saveData($values, $items, $id ?: null);
        (new FileService())->replaceRelations($attachmentIds, 'base_expense', $id);
        return $this->detail($id);
    }

    public function submit(int $id, string $requestId): array
    {
        $this->requirePermission('expense:manage');
        $record = ExpenseRecord::find($id, true);
        if (!$record || (int) $record['submitter_id'] !== (int) CurrentContext::accountId()) throw new RuntimeException('经费申请不存在或无权提交', 403);
        if (!in_array((string) $record['workflow_status'], ['draft', 'modify'], true)) throw new RuntimeException('当前状态不可提交', 409);
        return (new WorkflowLock())->run(WorkflowLock::key('generic_workflow', 'base_expense', $id), function () use ($id, $requestId): array {
            return ExpenseRecord::connection()->transaction(function () use ($id, $requestId): array {
                $record = ExpenseRecord::find($id, true);
                if (!$record || (int) $record['submitter_id'] !== (int) CurrentContext::accountId()) throw new RuntimeException('经费申请不存在或无权提交', 403);
                if (!ExpenseRecord::visible()->where('base_expense.id', $id)->exists()) throw new RuntimeException('经费申请不存在或无权提交', 403);
                if (!in_array((string) $record['workflow_status'], ['draft', 'modify'], true)) throw new RuntimeException('当前状态不可提交', 409);
                return (new \app\server\workflow\WorkflowService())->startInTransaction('base_expense', $id, ['request_id' => $requestId]);
            });
        }, 30, true);
    }

    public function options(): array
    {
        $this->requirePermission('expense:view');
        $bases = BaseVisitRecord::bases('');
        $semesters = ExpenseRecord::queryTable('arrangement')->whereNotNull('semester')->where('semester', '<>', '')->whereNull('deleted_at')->distinct()->orderByDesc('semester')->limit(100)->pluck('semester')->map(fn ($value) => (string) $value)->all();
        $units = $this->unitsQuery()->orderBy('dep_id')->get(['dep_id', 'dep_name'])->map(fn ($row) => $row->getAttributes())->all();
        return ['categories' => self::PROJECTS, 'expense_types' => ['基地建设费用'], 'bases' => $bases, 'units' => $units, 'semesters' => $semesters];
    }

    public function export(int $id, string $format = 'docx'): array
    {
        $this->requirePermission('expense:export');
        if (!$id || !ExpenseRecord::visible()->where('base_expense.id', $id)->exists()) throw new RuntimeException('经费申请不存在', 404);
        $format = strtolower(trim($format));
        if (!in_array($format, ['docx', 'pdf'], true)) throw new RuntimeException('导出格式无效', 422);
        return (new \app\server\export\ExportTaskService())->create([
            'type' => $format === 'pdf' ? 'expense_document_pdf' : 'expense_document',
            'file_name' => '基地建设费用申请_' . $id . '_' . date('Ymd_His') . '.' . $format,
            'params' => ['expense_id' => $id],
        ], true);
    }

    private function requirePermission(string $permission): void
    {
        if (!CurrentContext::accountId() || !in_array($permission, CurrentContext::permissionCodes(), true)) throw new RuntimeException('无经费管理权限', 403);
    }

    private function unitsQuery(): mixed
    {
        $query = ExpenseRecord::queryTable('department')->where('flag', 'on')->whereNull('deleted_at');
        if (in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true)) return $query;
        $organizations = CurrentContext::organizationScopes();
        $departmentIds = array_values(array_filter(array_map('intval', array_column($organizations, 'dep_id')), static fn (int $id): bool => $id > 0));
        $professionIds = array_values(array_filter(array_map('intval', array_column($organizations, 'profession_id')), static fn (int $id): bool => $id > 0));
        $query->where(function ($scope) use ($departmentIds, $professionIds): void {
            if ($departmentIds) $scope->whereIn('dep_id', $departmentIds);
            if ($professionIds) {
                $scope->orWhereIn('dep_id', ExpenseRecord::queryTable('profession')->whereIn('profession_id', $professionIds)->whereNull('deleted_at')->select('dep_id'));
            }
            if (!$departmentIds && !$professionIds) $scope->whereRaw('1 = 0');
        });
        return $query;
    }

    private function amountUpper(int $cents): string
    {
        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $units = ['', '拾', '佰', '仟'];
        $groups = ['', '万', '亿', '兆'];
        $yuan = intdiv($cents, 100);
        $hasYuan = $yuan > 0;
        $fraction = $cents % 100;
        $convert = static function (int $number) use ($digits, $units): string {
            if ($number === 0) return '';
            $result = ''; $zero = false; $position = 0;
            while ($number > 0) {
                $digit = $number % 10; $number = intdiv($number, 10);
                if ($digit === 0) { $zero = $result !== ''; } else { $result = $digits[$digit] . $units[$position] . ($zero ? '零' : '') . $result; $zero = false; }
                $position++;
            }
            return $result;
        };
        if ($yuan === 0) $text = '零'; else { $text = ''; $group = 0; $zeroGroup = false; while ($yuan > 0) { $part = $yuan % 10000; $yuan = intdiv($yuan, 10000); if ($part > 0) { $prefix = $text !== '' && ($zeroGroup || $part < 1000) ? '零' : ''; $text = $convert($part) . $groups[$group] . $prefix . $text; $zeroGroup = false; } elseif ($text !== '') $zeroGroup = true; $group++; } }
        $text .= '元';
        $jiao = intdiv($fraction, 10); $fen = $fraction % 10;
        if ($jiao || $fen) { if ($jiao) $text .= $digits[$jiao] . '角'; if ($fen) $text .= ($jiao || $hasYuan ? '' : '零') . $digits[$fen] . '分'; } else $text .= '整';
        return $text;
    }
}
