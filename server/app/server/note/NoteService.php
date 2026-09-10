<?php

namespace app\server\note;

use app\model\channel\NoteRecord;
use app\server\CurrentContext;
use RuntimeException;

/** 个人笔记内容与并发保护。 */
class NoteService
{
    /** 分页读取当前账号笔记。 */
    public function page(array $input): array
    {
        $input['trash'] = filter_var($input['trash'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return NoteRecord::page((int) CurrentContext::accountId(), $input);
    }

    /** 获取个人笔记详情。 */
    public function detail(int $id): array
    {
        $row = NoteRecord::detail((int) CurrentContext::accountId(), $id);
        if (!$row) {
            throw new RuntimeException('笔记不存在或无权访问', 404);
        }
        unset($row['account_id'], $row['user_id']);
        return $row;
    }

    /** 保存同一笔记并防止旧窗口覆盖。 */
    public function save(array $input): array
    {
        $title = trim((string) ($input['title'] ?? '')) ?: '未命名笔记';
        $content = (string) ($input['content_md'] ?? '');
        $uuid = (string) ($input['uuid'] ?? '');
        if (mb_strlen($title) > 180 || strlen($content) > 1024 * 1024 || !preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            throw new RuntimeException('标题最多180字，正文最多1MB，请使用有效笔记标识', 400);
        }
        return NoteRecord::connection()->transaction(function () use ($input, $title, $content, $uuid): array {
            $account = (int) CurrentContext::accountId();
            $id = (int) ($input['id'] ?? 0);
            $now = date('Y-m-d H:i:s');
            if (!$id) {
                $id = NoteRecord::createNote(['uuid' => $uuid, 'account_id' => $account, 'user_id' => CurrentContext::userId(),
                    'title' => $title, 'content_md' => $content, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $saved = $this->detail($id);
                if ($saved['deleted_at'] || $saved['title'] !== $title || $saved['content_md'] !== $content) throw new RuntimeException('此笔记已保存过不同内容，请保留当前草稿并从列表重新打开', 409);
                return $saved;
            }
            $row = $this->locked($id, $input);
            if ($row['deleted_at']) {
                throw new RuntimeException('请先恢复笔记再编辑', 409);
            }
            NoteRecord::updateNote($account, $id, ['title' => $title, 'content_md' => $content,
                'revision' => (int) $row['revision'] + 1, 'updated_at' => $now]);
            return $this->detail($id);
        });
    }

    /** 移入回收站、恢复或永久删除个人笔记。 */
    public function change(array $input, string $action): void
    {
        NoteRecord::connection()->transaction(function () use ($input, $action): void {
            $id = (int) ($input['id'] ?? 0);
            $row = $this->locked($id, $input);
            if ($action === 'purge') {
                if (!$row['deleted_at']) {
                    throw new RuntimeException('请先将笔记移入回收站', 409);
                }
                NoteRecord::purge((int) CurrentContext::accountId(), $id);
            } else {
                $now = date('Y-m-d H:i:s');
                NoteRecord::updateNote((int) CurrentContext::accountId(), $id, ['deleted_at' => $action === 'delete' ? $now : null,
                    'updated_at' => $now, 'revision' => (int) $row['revision'] + 1]);
            }
        });
    }

    /** 锁定归属当前账号且修订号一致的记录。 */
    private function locked(int $id, array $input): array
    {
        $row = NoteRecord::detail((int) CurrentContext::accountId(), $id, true);
        if (!$row) {
            throw new RuntimeException('笔记不存在或无权访问', 404);
        }
        if ((int) ($input['revision'] ?? 0) !== (int) $row['revision']) {
            throw new RuntimeException('笔记已在其他窗口修改，请保留当前内容并重新打开', 409);
        }
        return $row;
    }
}
