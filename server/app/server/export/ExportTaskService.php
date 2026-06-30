<?php

namespace app\server\export;

use app\model\channel\ExportTaskRecord;
use app\server\CurrentContext;
use app\server\message\MessageService;
use InvalidArgumentException;

class ExportTaskService
{
    private const ADMIN_ROLES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];

    public function list(array $filters): array
    {
        $accountId = $this->accountId();

        return ExportTaskRecord::taskPage($filters, $accountId, $this->canViewAll($filters));
    }

    public function create(array $payload): array
    {
        $accountId = $this->accountId();
        if (!in_array('export:create', CurrentContext::permissionCodes(), true) && !$this->isAdmin()) {
            throw new InvalidArgumentException('无操作权限', 403);
        }

        $type = $this->requiredString($payload['type'] ?? '', '导出类型', 80);
        $fileName = $this->nullableString($payload['file_name'] ?? null, 255) ?: $this->defaultFileName($type);
        $taskId = ExportTaskRecord::createTask([
            'user_id' => $accountId,
            'type' => $type,
            'file_name' => $fileName,
            'params' => ExportTaskRecord::jsonValue($payload['params'] ?? []),
        ]);

        return [
            'task' => ExportTaskRecord::taskById($taskId),
        ];
    }

    public function retry(int $id): array
    {
        $accountId = $this->accountId();
        if ($id <= 0) {
            throw new InvalidArgumentException('id 无效');
        }

        $includeAll = $this->isAdmin();
        $affected = ExportTaskRecord::resetTask($id, $accountId, $includeAll, date('Y-m-d H:i:s'));
        if ($affected <= 0) {
            throw new InvalidArgumentException('导出任务不存在或不可重试');
        }

        $task = ExportTaskRecord::taskById($id);
        if ($task && !empty($task['user_id'])) {
            $this->sendTaskMessage((int) $task['user_id'], '导出任务已重新排队', '导出任务已重新进入待处理队列。', $id);
        }

        return [
            'task' => $task,
        ];
    }

    public function detail(int $id): array
    {
        $accountId = $this->accountId();
        if ($id <= 0) {
            throw new InvalidArgumentException('id 无效');
        }

        $task = ExportTaskRecord::taskById($id);
        if (!$task || (!$this->isAdmin() && (int) ($task['user_id'] ?? 0) !== $accountId)) {
            throw new InvalidArgumentException('导出任务不存在');
        }

        return ['task' => $task];
    }

    private function canViewAll(array $filters): bool
    {
        return $this->isAdmin() && (string) ($filters['scope'] ?? '') === 'all';
    }

    private function isAdmin(): bool
    {
        return in_array(CurrentContext::roleType(), self::ADMIN_ROLES, true);
    }

    private function accountId(): int
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            throw new InvalidArgumentException('请先登录', 401);
        }

        return (int) $accountId;
    }

    private function sendTaskMessage(int $accountId, string $title, string $content, int $taskId): void
    {
        try {
            (new MessageService())->send([
                'account_ids' => [$accountId],
                'template_code' => 'export_task_result',
                'variables' => [
                    'export_title' => $title,
                    'export_content' => $content,
                ],
                'entity_type' => 'export_task',
                'entity_id' => $taskId,
            ], 0, '系统');
        } catch (\Throwable) {
        }
    }

    private function defaultFileName(string $type): string
    {
        return $type . '_' . date('Ymd_His') . '.xlsx';
    }

    private function requiredString(mixed $value, string $label, int $maxLength): string
    {
        $text = $this->nullableString($value, $maxLength) ?? '';
        if ($text === '') {
            throw new InvalidArgumentException("请填写{$label}");
        }

        return $text;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }
}
