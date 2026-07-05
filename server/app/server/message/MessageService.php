<?php

namespace app\server\message;

use app\model\channel\Account;
use app\model\channel\MessageRecord;
use InvalidArgumentException;

class MessageService
{
    public function inbox(int $accountId, array $filters): array
    {
        $this->ensureAccount($accountId);

        return MessageRecord::inboxPage($accountId, $filters);
    }

    public function summary(int $accountId): array
    {
        $this->ensureAccount($accountId);

        return MessageRecord::unreadSummary($accountId);
    }

    public function markRead(int $accountId, array $targetIds, bool $all): array
    {
        $this->ensureAccount($accountId);
        $count = MessageRecord::markRead($accountId, $targetIds, $all, date('Y-m-d H:i:s'));

        return [
            'updated' => $count,
            'summary' => MessageRecord::unreadSummary($accountId),
        ];
    }

    public function targets(array $filters): array
    {
        return [
            'items' => Account::messageTargets($filters),
        ];
    }

    public function templates(array $filters): array
    {
        return MessageRecord::templatePage($filters);
    }

    public function syncDefaultTemplates(): array
    {
        return MessageRecord::syncDefaultTemplates();
    }

    public function saveTemplate(array $payload): array
    {
        $code = $this->templateCode($payload['code'] ?? '');
        $name = $this->requiredString($payload['name'] ?? '', '模板名称', 180);
        $title = $this->requiredString($payload['title_tpl'] ?? '', '标题模板', 255);
        $content = $this->requiredString($payload['content_tpl'] ?? '', '内容模板', 10000);
        $variables = $this->jsonArray($payload['variables'] ?? []);
        $channels = $this->jsonArray($payload['channels'] ?? ['internal']);
        $status = trim((string) ($payload['status'] ?? 'enabled')) === 'disabled' ? 'disabled' : 'enabled';
        $id = MessageRecord::saveTemplate([
            'id' => is_numeric($payload['id'] ?? null) ? (int) $payload['id'] : 0,
            'name' => $name,
            'code' => $code,
            'title_tpl' => $title,
            'content_tpl' => $content,
            'type' => MessageRecord::normalizeType((string) ($payload['type'] ?? 'system')),
            'level' => MessageRecord::normalizeLevel((string) ($payload['level'] ?? 'normal')),
            'description' => $this->nullableString($payload['description'] ?? null, 500),
            'variables' => $variables,
            'link_url_tpl' => $this->nullableString($payload['link_url_tpl'] ?? null, 500),
            'channels' => $channels ?: ['internal'],
            'is_system' => !empty($payload['is_system']),
            'sort' => is_numeric($payload['sort'] ?? null) ? (int) $payload['sort'] : 100,
            'status' => $status,
        ], date('Y-m-d H:i:s'));

        return [
            'id' => $id,
            'item' => MessageRecord::templateByCode($code),
        ];
    }

    public function deleteTemplate(int $id): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('模板不存在');
        }

        $deleted = MessageRecord::deleteTemplate($id, date('Y-m-d H:i:s'));
        if ($deleted <= 0) {
            throw new InvalidArgumentException('系统模板不能删除');
        }

        return ['deleted' => $deleted];
    }

    public function send(array $payload, int $senderId, string $senderName): array
    {
        [$accountIds, $targetCount] = $this->resolveAccountIds($payload, $senderId);
        $templateCode = trim((string) ($payload['template_code'] ?? ''));
        if ($templateCode !== '') {
            $override = [
                'sender_id' => $senderId,
                'sender_name' => $senderName ?: '系统',
                'entity_type' => $this->nullableString($payload['entity_type'] ?? null, 80),
                'entity_id' => is_numeric($payload['entity_id'] ?? null) ? (int) $payload['entity_id'] : null,
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            ];
            $linkUrl = $this->nullableString($payload['link_url'] ?? null, 500);
            if ($linkUrl !== null) {
                $override['link_url'] = $linkUrl;
            }
            $result = $this->sendByTemplateCode($templateCode, $accountIds, $this->jsonArray($payload['variables'] ?? []), $override);

            return [
                ...$result,
                'target_count' => $targetCount,
                'summary' => $senderId > 0 ? MessageRecord::unreadSummary($senderId) : ['unread' => 0, 'by_type' => []],
            ];
        }

        $message = [
            'code' => $this->nullableString($payload['code'] ?? null, 120),
            'title' => $this->requiredString($payload['title'] ?? '', '消息标题', 180),
            'content' => $this->requiredString($payload['content'] ?? '', '消息内容', 10000),
            'type' => MessageRecord::normalizeType((string) ($payload['type'] ?? 'system')),
            'level' => MessageRecord::normalizeLevel((string) ($payload['level'] ?? 'normal')),
            'sender_id' => $senderId,
            'sender_name' => $senderName ?: '系统',
            'entity_type' => $this->nullableString($payload['entity_type'] ?? null, 80),
            'entity_id' => is_numeric($payload['entity_id'] ?? null) ? (int) $payload['entity_id'] : null,
            'link_url' => $this->nullableString($payload['link_url'] ?? null, 500),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        ];

        $messageId = MessageRecord::createMessage($message, $accountIds, date('Y-m-d H:i:s'));

        return [
            'message_id' => $messageId,
            'target_count' => $targetCount,
            'summary' => $senderId > 0 ? MessageRecord::unreadSummary($senderId) : ['unread' => 0, 'by_type' => []],
        ];
    }

    public function sendByTemplate(string $code, array $accountIds, array $vars, array $override = []): array
    {
        return $this->sendByTemplateCode($code, $accountIds, $vars, $override);
    }

    public function sendByTemplateCode(string $code, array $accountIds, array $vars, array $override = []): array
    {
        $template = MessageRecord::templateByCode($code);
        if (!$template) {
            throw new InvalidArgumentException('消息模板不存在或未启用');
        }

        $linkUrl = $this->render((string) ($template['link_url_tpl'] ?? ''), $vars);
        if ($linkUrl !== '' && preg_match('/\{[A-Za-z0-9_.:-]+\}/', $linkUrl)) {
            $linkUrl = '';
        }
        $metadata = ['template_code' => $code, 'variables' => $vars];
        if (isset($override['metadata']) && is_array($override['metadata'])) {
            $metadata = array_merge($metadata, $override['metadata']);
        }
        $override['metadata'] = $metadata;
        if (array_key_exists('link_url', $override) && $override['link_url'] === null) {
            unset($override['link_url']);
        }

        $message = array_merge([
            'code' => $code,
            'title' => $this->render((string) $template['title_tpl'], $vars),
            'content' => $this->render((string) $template['content_tpl'], $vars),
            'type' => MessageRecord::normalizeType((string) ($template['type'] ?? 'system')),
            'level' => MessageRecord::normalizeLevel((string) ($template['level'] ?? 'normal')),
            'sender_id' => 0,
            'sender_name' => '系统',
            'link_url' => $linkUrl !== '' ? $linkUrl : null,
            'metadata' => $metadata,
        ], $override);

        $messageId = MessageRecord::createMessage($message, $accountIds, date('Y-m-d H:i:s'));

        return [
            'message_id' => $messageId,
            'template_code' => $code,
        ];
    }

    private function ensureAccount(int $accountId): void
    {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('请先登录');
        }
    }

    private function resolveAccountIds(array $payload, int $senderId): array
    {
        $scope = trim((string) ($payload['send_scope'] ?? 'custom'));
        $roleType = trim((string) ($payload['role_type'] ?? ''));
        if (!in_array($scope, ['custom', 'role', 'all'], true)) {
            throw new InvalidArgumentException('消息发送范围不正确');
        }

        if ($scope === 'all') {
            $accountIds = Account::messageTargetIds();
        } elseif ($scope === 'role') {
            if ($roleType === '') {
                throw new InvalidArgumentException('请选择接收角色');
            }
            $accountIds = Account::messageTargetIds(['role_type' => $roleType]);
        } else {
            $accountIds = (array) ($payload['account_ids'] ?? $payload['target_account_ids'] ?? []);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (!$ids) {
            throw new InvalidArgumentException('缺少消息接收人');
        }
        $includeSender = $this->boolValue($payload['include_sender'] ?? true);
        if (!$includeSender && $senderId > 0) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $senderId));
            if (!$ids) {
                throw new InvalidArgumentException('缺少消息接收人');
            }
        }
        $receiverIds = array_values(array_filter($ids, static fn (int $id): bool => $id !== $senderId));
        $targetCount = count($receiverIds);
        if ($includeSender && $senderId > 0) {
            $ids[] = $senderId;
            $ids = array_values(array_unique($ids));
        }

        return [$ids, $targetCount];
    }

    private function requiredString(mixed $value, string $label, int $limit): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException("请填写{$label}");
        }

        return mb_substr($text, 0, $limit);
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }

        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }

    private function templateCode(mixed $value): string
    {
        $code = trim((string) $value);
        if ($code === '' || !preg_match('/^[A-Za-z0-9_.:-]{2,120}$/', $code)) {
            throw new InvalidArgumentException('模板编码只能包含字母、数字、下划线、点、冒号和横线');
        }

        return $code;
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            throw new InvalidArgumentException('JSON 格式不正确');
        }

        return [];
    }

    private function render(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($template, $replace);
    }
}
