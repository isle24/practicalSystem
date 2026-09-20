<?php

namespace app\model\channel;

use RuntimeException;

/** 按账号隔离的问答会话。 */
class AssistantRecord extends TableRecord
{
    /** 分页列出个人会话。 */
    public static function threads(int $accountId, int $page): array
    {
        $query = self::queryTable('assistant_thread')->where('account_id', $accountId);
        return ['items' => (clone $query)->orderByDesc('updated_at')->orderByDesc('id')->forPage(max(1, $page), 20)->get()->toArray(),
            'total' => $query->count()];
    }

    /** 查找本人的会话，必要时锁定。 */
    public static function ownedThread(int $accountId, int $id, bool $lock = false): ?array
    {
        $query = self::queryTable('assistant_thread')->where('account_id', $accountId)->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->first()?->toArray();
    }

    /** 返回个人会话中的分页轮次。 */
    public static function turns(int $accountId, int $threadId, int $before = 0): array
    {
        if (!self::ownedThread($accountId, $threadId)) throw new RuntimeException('会话不存在', 404);
        $query = self::queryTable('assistant_turn')->where('account_id', $accountId)->where('thread_id', $threadId);
        if ($before > 0) $query->where('id', '<', $before);
        return array_reverse($query->orderByDesc('id')->limit(20)->get()->toArray());
    }

    /** 查找幂等请求。 */
    public static function byRequest(int $accountId, string $requestId): ?array
    {
        return self::queryTable('assistant_turn')->where('account_id', $accountId)->where('request_id', $requestId)->first()?->toArray();
    }

    /** 在事务中创建个人会话及待处理轮次。 */
    public static function enqueue(int $accountId, int $threadId, string $requestId, string $question, string $mode = 'school', string $revision = ''): array
    {
        return self::connection()->transaction(function () use ($accountId, $threadId, $requestId, $question, $mode, $revision): array {
            if ($existing = self::byRequest($accountId, $requestId)) return $existing;
            $now = date('Y-m-d H:i:s');
            if ($threadId > 0) {
                $thread = self::ownedThread($accountId, $threadId, true);
                if (!$thread) throw new RuntimeException('会话不存在', 404);
                if ($thread['provider_mode'] !== $mode) throw new RuntimeException('切换服务后请新建会话', 409);
            } else {
                $threadId = (int) self::queryTable('assistant_thread')->insertGetId([
                    'account_id' => $accountId, 'title' => mb_substr($question, 0, 80), 'provider_mode' => $mode, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            self::expirePending($accountId);
            if (self::queryTable('assistant_turn')->where('account_id', $accountId)->whereIn('status', ['queued', 'processing'])->exists()) {
                throw new RuntimeException('已有问题正在处理中，请稍候', 409);
            }
            $id = (int) self::queryTable('assistant_turn')->insertGetId([
                'thread_id' => $threadId, 'account_id' => $accountId, 'request_id' => $requestId, 'question' => $question,
                'provider_mode' => $mode, 'provider_revision' => $revision,
                'status' => 'queued', 'created_at' => $now, 'updated_at' => $now,
            ]);
            self::queryTable('assistant_thread')->where('id', $threadId)->update(['updated_at' => $now]);
            return self::turn($id);
        });
    }

    /** 标记中断的任务为可重试失败。 */
    public static function expirePending(int $accountId): void
    {
        self::queryTable('assistant_turn')->where('account_id', $accountId)->whereIn('status', ['queued', 'processing'])
            ->where('updated_at', '<', date('Y-m-d H:i:s', time() - 300))
            ->update(['status' => 'failed', 'error_message' => '处理超时，请重新发送问题', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** 由学校队列读取轮次。 */
    public static function turn(int $id): ?array
    {
        return self::queryTable('assistant_turn')->where('id', $id)->first()?->toArray();
    }

    /** 只允许一个消费者处理。 */
    public static function claim(int $id): bool
    {
        return self::queryTable('assistant_turn')->where('id', $id)->where('status', 'queued')
            ->update(['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s')]) === 1;
    }

    /** 构建有限长度的已完成会话上下文。 */
    public static function history(array $turn): array
    {
        $rows = self::queryTable('assistant_turn')->where('account_id', $turn['account_id'])
            ->where('thread_id', $turn['thread_id'])->where('id', '<', $turn['id'])->where('status', 'completed')
            ->where('provider_mode', $turn['provider_mode'])->where('provider_revision', $turn['provider_revision'])
            ->orderByDesc('id')->limit(6)->get()->toArray();
        $messages = [];
        foreach (array_reverse($rows) as $row) {
            $messages[] = ['role' => 'user', 'content' => $row['question']];
            $messages[] = ['role' => 'assistant', 'content' => mb_substr($row['answer'], 0, 8000)];
        }
        $messages[] = ['role' => 'user', 'content' => $turn['question']];
        return $messages;
    }

    /** 保存结果并通知本人的在线终端。 */
    public static function finish(int $id, string $answer = '', ?string $error = null): void
    {
        self::connection()->transaction(function () use ($id, $answer, $error): void {
            $turn = self::turn($id);
            if (!$turn || !in_array($turn['status'], ['queued', 'processing'], true)) return;
            $count = self::queryTable('assistant_turn')->where('id', $id)->whereIn('status', ['queued', 'processing'])->update([
                'answer' => $answer, 'status' => $error ? 'failed' : 'completed', 'error_message' => $error,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if ($count) MessageRealtimeRecord::enqueueNotification([(int) $turn['account_id']], 'assistant');
        });
    }
}
