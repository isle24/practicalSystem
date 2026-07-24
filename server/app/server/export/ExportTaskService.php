<?php

namespace app\server\export;

use app\model\channel\ExportTaskRecord;
use app\server\CurrentContext;
use app\server\message\MessageService;
use InvalidArgumentException;
use Throwable;
use Webman\RedisQueue\Redis as RedisQueue;

class ExportTaskService
{
    private const ADMIN_ROLES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const MANAGE_ALL_ROLES = ['super_admin', 'school_admin'];
    private const BUSINESS_EXPORT_TYPES = ['internship_base_word', 'internship_implementation_pdf'];

    public const QUEUE = 'export_task';

    public function list(array $filters): array
    {
        $accountId = $this->accountId();

        return ExportTaskRecord::taskPage($filters, $accountId, $this->canViewAll($filters));
    }

    public function create(array $payload, bool $businessValidated = false): array
    {
        $accountId = $this->accountId();
        if (!in_array('export:create', CurrentContext::permissionCodes(), true) && !$this->isAdminRole()) {
            throw new InvalidArgumentException('无操作权限', 403);
        }

        $type = $this->requiredString($payload['type'] ?? '', '导出类型', 80);
        if (in_array($type, self::BUSINESS_EXPORT_TYPES, true) && !$businessValidated) {
            throw new InvalidArgumentException('请从对应业务页面创建导出任务');
        }
        $fileName = $this->nullableString($payload['file_name'] ?? null, 255) ?: $this->defaultFileName($type);
        $taskId = ExportTaskRecord::createTask([
            'user_id' => $accountId,
            'type' => $type,
            'file_name' => $fileName,
            'params' => ExportTaskRecord::jsonValue($payload['params'] ?? []),
        ]);

        $this->enqueue($taskId);

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
        if (!in_array('export:retry', CurrentContext::permissionCodes(), true) && !$this->canManageAll()) {
            throw new InvalidArgumentException('无操作权限', 403);
        }

        $includeAll = $this->canManageAll();
        $task = ExportTaskRecord::taskById($id);
        if (!$task || (!$includeAll && (int) ($task['user_id'] ?? 0) !== $accountId)) {
            throw new InvalidArgumentException('导出任务不存在或不可重试');
        }
        if (in_array((string) ($task['type'] ?? ''), self::BUSINESS_EXPORT_TYPES, true)) {
            throw new InvalidArgumentException('业务导出请返回对应业务页面重新创建任务');
        }

        $affected = ExportTaskRecord::resetTask($id, $accountId, $includeAll, date('Y-m-d H:i:s'));
        if ($affected <= 0) {
            throw new InvalidArgumentException('仅失败或超时任务可重试');
        }

        $this->enqueue($id);

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
        if (!$task || (!$this->canManageAll() && (int) ($task['user_id'] ?? 0) !== $accountId)) {
            throw new InvalidArgumentException('导出任务不存在');
        }

        return ['task' => $task];
    }

    private function canViewAll(array $filters): bool
    {
        return $this->canManageAll() && (string) ($filters['scope'] ?? '') === 'all';
    }

    private function isAdminRole(): bool
    {
        return in_array(CurrentContext::roleType(), self::ADMIN_ROLES, true);
    }

    private function canManageAll(): bool
    {
        return in_array(CurrentContext::roleType(), self::MANAGE_ALL_ROLES, true);
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

    /**
     * 将导出任务推入 Redis 队列，交由 ExportConsumer 后台生成文件。
     * 入队失败不抛错：任务已落库为 pending，CronTask 会定时补投。
     */
    private function enqueue(int $taskId): void
    {
        $databaseId = (int) (CurrentContext::schoolDatabaseId() ?: 0);
        if ($taskId <= 0 || $databaseId <= 0) {
            return;
        }

        try {
            RedisQueue::send(self::QUEUE, [
                'database_id' => $databaseId,
                'task_id' => $taskId,
            ]);
        } catch (\Throwable) {
        }
    }

    private function defaultFileName(string $type): string
    {
        $extension = match ($type) {
            'internship_base_word' => 'docx',
            'internship_implementation_pdf' => 'pdf',
            default => 'xlsx',
        };

        return $type . '_' . date('Ymd_His') . '.' . $extension;
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
