<?php

namespace app\model\channel;

/** 学校版本、发布快照、升级 SQL 和下载资产查询。 */
class ReleaseRecord extends TableRecord
{
    /** 分页读取后台版本或已发布快照。 */
    public static function page(array $input, bool $admin): array
    {
        $q = self::queryTable('system_release');
        if (!$admin) $q->where('status', 'published');
        if (in_array($input['product'] ?? '', ['system', 'desktop'], true)) $q->where('product', $input['product']);
        $page = max(1, (int) ($input['page'] ?? 1));
        $size = min(100, max(1, (int) ($input['page_size'] ?? 20)));
        $total = (clone $q)->count();
        $columns = $admin ? ['*'] : ['id', 'product', 'version', 'published_title as title', 'published_notes_html as notes_html', 'published_at'];
        if (!$admin) $q->orderByDesc('published_at');
        return ['items' => $q->orderByDesc('id')->forPage($page, $size)->get($columns)->toArray(),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    /** 读取版本，可在事务内锁定。 */
    public static function detail(int $id, bool $lock = false): ?array
    {
        $q = self::queryTable('system_release')->where('id', $id);
        return ($lock ? $q->lockForUpdate() : $q)->first()?->toArray();
    }

    /** 导入同一来源版本时幂等创建，不覆盖管理员编辑。 */
    public static function import(array $values): array
    {
        self::queryTable('system_release')->insertOrIgnore($values);
        return self::queryTable('system_release')->where('product', $values['product'])->where('version', $values['version'])->first()->toArray();
    }

    /** 保存已锁定版本字段。 */
    public static function updateRelease(int $id, array $values): void
    {
        self::queryTable('system_release')->where('id', $id)->update($values);
    }

    /** 最新已发布客户端，按版本号取最高版本。 */
    public static function latestDesktop(): ?array
    {
        $rows = self::queryTable('system_release')->where('product', 'desktop')->where('status', 'published')->get()->toArray();
        usort($rows, fn ($a, $b) => version_compare($b['version'], $a['version']));
        return $rows[0] ?? null;
    }

    /** 按固定顺序锁定客户端版本，串行处理并发发布。 */
    public static function lockPublication(): void
    {
        self::queryTable('system_release')->where('product', 'desktop')->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** 导入版本附带的固定 SQL 文件。 */
    public static function importSql(array $values): void
    {
        self::queryTable('system_release_sql')->insertOrIgnore($values);
    }

    /** 查询版本 SQL，仅供服务层权限检查后调用。 */
    public static function sqlFiles(int $id, bool $super): array
    {
        $q = self::queryTable('system_release_sql')->where('release_id', $id);
        if (!$super) $q->where('target_scope', 'school');
        return $q->orderBy('sort')->get()->toArray();
    }

    /** 登记人工执行结果。 */
    public static function markSql(int $releaseId, int $sqlId, array $values, bool $super): int
    {
        $q = self::queryTable('system_release_sql')->where('release_id', $releaseId)->where('id', $sqlId);
        if (!$super) $q->where('target_scope', 'school');
        return $q->update($values);
    }

    /** 所有目标库的 SQL 都需登记处理。 */
    public static function pendingSql(int $id): bool
    {
        return self::queryTable('system_release_sql')->where('release_id', $id)->whereNull('executed_at')->exists();
    }

    /** 导入不可变下载来源。 */
    public static function importAsset(array $values): array
    {
        self::queryTable('desktop_release_asset')->insertOrIgnore($values);
        return self::queryTable('desktop_release_asset')->where('release_id', $values['release_id'])->where('source_asset_id', $values['source_asset_id'])->first()->toArray();
    }

    /** 查询版本资产。 */
    public static function assets(int $releaseId): array
    {
        return self::queryTable('desktop_release_asset')->where('release_id', $releaseId)->orderBy('id')->get()->toArray();
    }

    /** 查询单个资产。 */
    public static function asset(int $id): ?array
    {
        return self::queryTable('desktop_release_asset')->where('id', $id)->first()?->toArray();
    }

    /** 领取待下载或超时的资产，阻止重复消费者同时写入。 */
    public static function claimAsset(int $id, string $token): bool
    {
        return self::queryTable('desktop_release_asset')->where('id', $id)->where(function ($q): void {
            $q->whereIn('status', ['pending', 'failed'])->orWhere(fn ($q) => $q->where('status', 'downloading')->where('started_at', '<', date('Y-m-d H:i:s', time() - 3600)));
        })->update(['status' => 'downloading', 'claim_token' => $token, 'started_at' => date('Y-m-d H:i:s'), 'error_message' => null]) > 0;
    }

    /** 仅当前下载持有人可以提交结果。 */
    public static function finishAsset(int $id, string $token, array $values): bool
    {
        return self::queryTable('desktop_release_asset')->where('id', $id)->where('claim_token', $token)->where('status', 'downloading')->update($values + ['updated_at' => date('Y-m-d H:i:s')]) > 0;
    }
}
