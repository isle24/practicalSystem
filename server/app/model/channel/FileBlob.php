<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;

class FileBlob extends BaseModel
{
    protected $table = 'file_blob';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function activeByMd5(string $md5): ?self
    {
        return self::query()
            ->where('md5', $md5)
            ->whereNull('deleted_at')
            ->first();
    }

    public static function lockById(int $id): ?self
    {
        return self::query()
            ->where('id', $id)
            ->lockForUpdate()
            ->first();
    }

    public static function lockByMd5(string $md5): ?self
    {
        return self::query()
            ->where('md5', $md5)
            ->lockForUpdate()
            ->first();
    }

    public static function incrementRefCount(int $id, string $now, bool $restore = false): int
    {
        $values = [
            'ref_count' => new Expression('COALESCE(`ref_count`, 0) + 1'),
            'updated_at' => $now,
        ];
        if ($restore) {
            $values['deleted_at'] = null;
        }

        return self::query()
            ->where('id', $id)
            ->update($values);
    }

    public static function decrementRefCount(int $id, string $now): int
    {
        return self::query()
            ->where('id', $id)
            ->update([
                'ref_count' => new Expression('GREATEST(COALESCE(`ref_count`, 0) - 1, 0)'),
                'updated_at' => $now,
            ]);
    }

    public static function updateBlob(int $id, array $values): int
    {
        return self::query()
            ->where('id', $id)
            ->update($values);
    }

    public static function createBlob(array $values): int
    {
        return (int) self::query()->insertGetId($values);
    }

    public static function softDeleteById(int $id, string $now): int
    {
        return self::query()
            ->where('id', $id)
            ->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'ref_count' => 0,
            ]);
    }

    /**
     * 读取没有文件引用的物理文件 ID。
     */
    public static function unreferencedIds(int $limit = 500): array
    {
        return self::query()
            ->where('ref_count', '<=', 0)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 恢复物理删除失败的零引用记录，供后续重试。
     */
    public static function restoreDeleteCandidate(int $id, string $now): int
    {
        return self::query()
            ->where('id', $id)
            ->where('ref_count', '<=', 0)
            ->update([
                'deleted_at' => null,
                'updated_at' => $now,
            ]);
    }
}
