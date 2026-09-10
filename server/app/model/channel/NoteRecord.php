<?php

namespace app\model\channel;

/** 个人 Markdown 笔记存储。 */
class NoteRecord extends TableRecord
{
    /** 查询账号自己的笔记。 */
    private static function owned(int $accountId): mixed
    {
        return self::queryTable('user_note')->where('account_id', $accountId);
    }

    /** 分页查询笔记摘要和回收站。 */
    public static function page(int $accountId, array $filters): array
    {
        $query = self::owned($accountId);
        ($filters['trash'] ?? false) ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('content_md', 'like', $like));
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $size = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $total = (clone $query)->count();
        $items = $query->orderByDesc('updated_at')->orderByDesc('id')->forPage($page, $size)
            ->select(['id', 'uuid', 'title', 'revision', 'created_at', 'updated_at', 'deleted_at'])
            ->selectRaw('LEFT(content_md, 160) AS preview')->get()->toArray();
        return ['items' => $items, 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    /** 按归属查询详情，可在事务内锁定。 */
    public static function detail(int $accountId, int $id, bool $lock = false): ?array
    {
        $query = self::owned($accountId)->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->first()?->toArray();
    }

    /** 根据客户端幂等标识查找笔记。 */
    public static function byUuid(int $accountId, string $uuid): ?array
    {
        return self::owned($accountId)->where('uuid', $uuid)->first()?->toArray();
    }

    /** 新建笔记，重复提交不会创建第二条。 */
    public static function createNote(array $values): int
    {
        self::queryTable('user_note')->insertOrIgnore($values);
        return (int) self::byUuid((int) $values['account_id'], $values['uuid'])['id'];
    }

    /** 更新锁定的个人笔记。 */
    public static function updateNote(int $accountId, int $id, array $values): void
    {
        self::owned($accountId)->where('id', $id)->update($values);
    }

    /** 永久删除回收站中的个人笔记。 */
    public static function purge(int $accountId, int $id): void
    {
        self::owned($accountId)->where('id', $id)->whereNotNull('deleted_at')->delete();
    }
}
