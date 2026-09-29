<?php

namespace app\server\export;

use app\model\channel\ExpenseRecord;
use app\model\channel\WorkflowRecord;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

final class ExpenseDocumentExporter
{
    public function generate(int $id, string $format = 'docx'): array
    {
        $snapshot = $this->snapshot($id);
        return $format === 'pdf' ? $this->pdf($snapshot) : $this->docx($snapshot);
    }

    private function docx(array $data): array
    {
        $document = new PhpWord();
        $section = $document->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 900, 'marginRight' => 900]);
        $section->addTitle('基地建设费用申请', 1);
        foreach ($data['fields'] as $label => $value) $section->addText($label . '：' . $value);
        $table = $section->addTable(['borderSize' => 6, 'cellMargin' => 80, 'width' => 10000]);
        $table->addRow(null, ['tblHeader' => true]); $table->addCell(6500)->addText('建设项目'); $table->addCell(2500)->addText('项目金额');
        foreach ($data['items'] as $item) { $table->addRow(); $table->addCell(6500)->addText($item['project']); $table->addCell(2500)->addText($item['amount']); }
        $table->addRow(); $table->addCell(6500)->addText('合计'); $table->addCell(2500)->addText($data['total']);
        $section->addText('附件：' . ($data['attachments'] ? implode('、', $data['attachments']) : '无'));
        $section->addText('备注：' . ($data['fields']['备注'] ?? '无'));
        $section->addHeading('审批记录', 2);
        foreach ($data['workflow'] as $round) {
            $section->addText('第 ' . $round['round'] . ' 轮：' . $round['status']);
            foreach ($round['history'] as $event) {
                $section->addText($event['created_at'] . ' ' . $event['actor'] . ' · ' . $event['action'] . '：' . ($event['opinion'] ?: '无意见'));
                if ($event['signature']) (new SignatureRenderer())->appendToWord($section, $event['signature'], 150, 70);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'expense_') . '.docx';
        IOFactory::createWriter($document, 'Word2007')->save($path);
        return ['path' => $path, 'ext' => 'docx', 'total_rows' => count($data['items'])];
    }

    private function pdf(array $data): array
    {
        $rows = '';
        foreach ($data['items'] as $item) $rows .= '<tr><td>' . $this->escape($item['project']) . '</td><td>' . $this->escape($item['amount']) . '</td></tr>';
        $workflow = '';
        $renderer = new SignatureRenderer();
        foreach ($data['workflow'] as $round) {
            $workflow .= '<h3>第 ' . (int) $round['round'] . ' 轮：' . $this->escape($round['status']) . '</h3>';
            foreach ($round['history'] as $event) {
                $workflow .= '<div class="approval-event"><p>' . $this->escape($event['created_at'] . ' ' . $event['actor'] . ' · ' . $event['action'] . '：' . ($event['opinion'] ?: '无意见')) . '</p>';
                if ($event['signature']) $workflow .= $renderer->html($event['signature'], 150, 70);
                $workflow .= '</div>';
            }
        }
        $html = '<style>body{font-family:sans-serif;font-size:11pt;line-height:1.45}h1{text-align:center}h2,h3{page-break-after:avoid}table{width:100%;border-collapse:collapse;table-layout:fixed}td,th{border:1px solid #333;padding:6px;word-break:break-all;vertical-align:top}.meta p{margin:4px 0}.approval-event{page-break-inside:avoid;border-bottom:1px solid #ddd;padding:3px 0}.approval-signature{display:inline-block;text-align:center;page-break-inside:avoid}.approval-signature img{object-fit:contain}</style><h1>基地建设费用申请</h1><div class="meta">';
        foreach ($data['fields'] as $label => $value) $html .= '<p><b>' . $this->escape($label) . '：</b>' . $this->escape($value) . '</p>';
        $html .= '</div><table><thead><tr><th>建设项目</th><th>项目金额</th></tr></thead><tbody>' . $rows . '<tr><th>合计</th><th>' . $this->escape($data['total']) . '</th></tr></tbody></table><p>附件：' . $this->escape($data['attachments'] ? implode('、', $data['attachments']) : '无') . '</p><h2>审批记录</h2>' . $workflow;
        $tempDir = sys_get_temp_dir() . '/practical_mpdf';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) throw new RuntimeException('PDF 临时目录创建失败');
        $path = tempnam(sys_get_temp_dir(), 'expense_') . '.pdf';
        $pdf = new Mpdf(['mode' => 'zh-CN', 'format' => 'A4', 'default_font' => 'sun-exta', 'tempDir' => $tempDir]);
        $pdf->autoScriptToLang = true; $pdf->autoLangToFont = true; $pdf->WriteHTML($html); $pdf->Output($path, Destination::FILE);
        return ['path' => $path, 'ext' => 'pdf', 'total_rows' => count($data['items'])];
    }

    private function snapshot(int $id): array
    {
        $instanceRow = WorkflowRecord::q('instance')
            ->where('entity_type', 'base_expense')
            ->where('entity_id', $id)
            ->orderByDesc('round')
            ->first();
        if (!$instanceRow) {
            $record = ExpenseRecord::find($id);
            if (!$record) throw new RuntimeException('经费申请不存在');
            return $this->documentSnapshot($record, $id, (string) ($record['submitted_at'] ?? $record['created_at'] ?? ''), []);
        }

        $instance = WorkflowRecord::row($instanceRow);
        $record = $instance['snapshot_json'] ?? null;
        if (!is_array($record) || (int) ($record['id'] ?? 0) !== $id || !is_array($record['items'] ?? null) || !is_array($record['attachments'] ?? null)) {
            throw new RuntimeException('经费审批快照无效');
        }

        $events = [];
        foreach (WorkflowRecord::q('history')->where('instance_id', (int) $instance['id'])->orderBy('id')->get() as $historyRow) {
            $history = WorkflowRecord::row($historyRow);
            $actorId = (int) ($history['actor_id'] ?? 0);
            $events[] = [
                'created_at' => (string) ($history['created_at'] ?? ''),
                'actor' => $this->actorName($actorId) ?: ('账号 ' . $actorId),
                'action' => $this->actionName((string) ($history['action'] ?? '')),
                'opinion' => (string) ($history['opinion'] ?? ''),
                'signature' => is_array($history['signature_json'] ?? null) ? $history['signature_json'] : null,
            ];
        }
        $workflow = [[
            'round' => (int) ($instance['round'] ?? 0),
            'status' => $this->statusName((string) ($instance['status'] ?? '')),
            'history' => $events,
        ]];

        return $this->documentSnapshot($record, $id, (string) ($instance['created_at'] ?? ''), $workflow);
    }

    private function documentSnapshot(array $record, int $id, string $submittedAt, array $workflow): array
    {
        $submitterId = (int) ($record['submitter_id'] ?? 0);
        return [
            'fields' => ['审批编号' => (string) ($record['code'] ?? $id), '申请人' => $this->actorName($submitterId) ?: ('账号 ' . $submitterId), '申请单位' => (string) ($record['name'] ?? ''), '提交时间' => $submittedAt, '学年学期' => (string) ($record['semester'] ?? ''), '基地名称' => (string) ($record['title'] ?? ''), '基地类型' => (string) ($record['base_type'] ?? ''), '基地类别' => (string) ($record['base_category'] ?? ''), '备注' => (string) ($record['remark'] ?? '')],
            'items' => array_map(static fn (array $item): array => ['project' => (string) ($item['project'] ?? ''), 'amount' => (string) ($item['amount'] ?? '')], (array) ($record['items'] ?? [])),
            'total' => (string) ($record['total_amount'] ?? $record['amount'] ?? '0.00') . '（' . (string) ($record['amount_upper'] ?? '') . '）',
            'attachments' => array_values(array_filter(array_map(static fn (array $file): string => (string) ($file['name'] ?? ''), (array) ($record['attachments'] ?? [])))), 'workflow' => $workflow,
        ];
    }

    private function actorName(int $accountId): string { if ($accountId <= 0) return ''; $row = ExpenseRecord::queryTable('account')->join('users', 'users.id', '=', 'account.user_id')->where('account.id', $accountId)->first(['users.name']); return $row ? trim((string) $row->name) : ''; }
    private function actionName(string $action): string { return ['start' => '提交', 'accept' => '通过', 'modify' => '退回', 'cancel' => '取消'][$action] ?? $action; }
    private function statusName(string $status): string { return ['draft' => '草稿', 'wait' => '审批中', 'accept' => '已通过', 'modify' => '已退回', 'cancelled' => '已取消'][$status] ?? $status; }
    private function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
