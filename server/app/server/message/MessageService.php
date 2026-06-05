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

    public function send(array $payload, int $senderId, string $senderName): array
    {
        $accountIds = (array) ($payload['account_ids'] ?? $payload['target_account_ids'] ?? []);
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
            'summary' => $senderId > 0 ? MessageRecord::unreadSummary($senderId) : ['unread' => 0, 'by_type' => []],
        ];
    }

    public function sendByTemplate(string $code, array $accountIds, array $vars, array $override = []): array
    {
        $template = MessageRecord::templateByCode($code);
        if (!$template) {
            throw new InvalidArgumentException('消息模板不存在或未启用');
        }

        $message = array_merge($override, [
            'code' => $code,
            'title' => $this->render((string) $template['title_tpl'], $vars),
            'content' => $this->render((string) $template['content_tpl'], $vars),
        ]);
        $messageId = MessageRecord::createMessage($message, $accountIds, date('Y-m-d H:i:s'));

        return ['message_id' => $messageId];
    }

    private function ensureAccount(int $accountId): void
    {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('请先登录');
        }
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
