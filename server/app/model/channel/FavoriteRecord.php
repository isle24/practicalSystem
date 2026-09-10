<?php

namespace app\model\channel;

/** 学校共享和个人收藏查询。 */
class FavoriteRecord extends TableRecord
{
    /** 仅返回当前账号可用的个人或学校收藏。 */
    private static function visibleQuery(int $accountId): mixed
    {
        return self::queryTable('favorite_link')->whereNull('deleted_at')->where('status', 'enabled')
            ->where(fn ($query) => $query->where('account_id', $accountId)->orWhere('scope', 'school'));
    }

    /** 查询收藏列表或启动台项目。 */
    public static function page(int $accountId, array $filters): array
    {
        $query = self::visibleQuery($accountId);
        if (($filters['scope'] ?? '') === 'personal') {
            $query->where('account_id', $accountId)->where('scope', 'personal');
        } elseif (($filters['scope'] ?? '') === 'school') {
            $query->where('scope', 'school');
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('url', 'like', $like));
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $size = min(200, max(1, (int) ($filters['page_size'] ?? 20)));
        $total = (clone $query)->count();
        return ['items' => $query->orderBy('sort')->orderByDesc('id')->forPage($page, $size)->get()->toArray(),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    /** 读取可见收藏。 */
    public static function visible(int $accountId, int $id): ?array
    {
        return self::visibleQuery($accountId)->where('id', $id)->first()?->toArray();
    }

    /** 锁定收藏以校验编辑归属和修订号。 */
    public static function lock(int $id): ?array
    {
        return self::queryTable('favorite_link')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first()?->toArray();
    }

    /** 新建收藏。 */
    public static function createFavorite(array $values): int
    {
        return (int) self::queryTable('favorite_link')->insertGetId($values + ['uuid' => self::uuid()]);
    }

    /** 更新已经锁定的收藏。 */
    public static function updateFavorite(int $id, array $values): void
    {
        self::queryTable('favorite_link')->where('id', $id)->update($values);
    }

    /** 判断当前账号是否可以读取关联图标。 */
    public static function iconVisible(int $accountId, int $fileId): bool
    {
        return self::visibleQuery($accountId)->where('icon_file_id', $fileId)->exists();
    }
}
