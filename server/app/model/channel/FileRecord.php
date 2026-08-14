<?php

namespace app\model\channel;

class FileRecord extends BaseModel
{
    protected $table = 'file';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    public static function detailById(int $fileId): ?object
    {
        return self::query()
            ->join('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->where('file.id', $fileId)
            ->whereNull('file.deleted_at')
            ->first([
                'file.id',
                'file.uuid',
                'file.blob_id',
                'file.name',
                'file.download_name',
                'file.url',
                'file.is_temporary',
                'file.uploader_id',
                'file.client',
                'file.client_ip',
                'file.user_agent',
                'file.category',
                'file.created_at',
                'file_blob.md5',
                'file_blob.sha1',
                'file_blob.path',
                'file_blob.ext',
                'file_blob.size',
                'file_blob.mime_type',
                'file_blob.disk',
                'file_blob.block',
                'file_blob.ref_count',
            ]);
    }

    /** 查询多个有效文件及物理存储信息。 */
    public static function detailsByIds(array $fileIds): array
    {
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0)));
        if (!$fileIds) {
            return [];
        }

        return self::query()
            ->join('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->whereIn('file.id', $fileIds)
            ->whereNull('file.deleted_at')
            ->whereNull('file_blob.deleted_at')
            ->get([
                'file.id',
                'file.name',
                'file.download_name',
                'file.url',
                'file_blob.path',
                'file_blob.ext',
                'file_blob.size',
                'file_blob.mime_type',
            ])
            ->all();
    }

    public static function pagedRows(array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $accountId = (int) ($filters['account_id'] ?? 0);
        $isAdmin = (bool) ($filters['is_admin'] ?? false);

        $query = self::query()
            ->join('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->leftJoin('account', 'file.uploader_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id');

        if (!$isAdmin) {
            $query->where('file.uploader_id', $accountId);
        }
        if ($status === 'active') {
            $query->whereNull('file.deleted_at');
        } elseif ($status === 'deleted') {
            $query->whereNotNull('file.deleted_at');
        }
        if ($category !== '') {
            $query->where('file.category', $category);
        }
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
                $builder->where('file.name', 'like', $like)
                    ->orWhere('file.download_name', 'like', $like)
                    ->orWhere('file_blob.md5', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhere('account.login_name', 'like', $like);
            });
        }

        return [
            'total' => (int) (clone $query)->count(),
            'rows' => $query
                ->orderByDesc('file.id')
                ->forPage($page, $pageSize)
                ->get([
                    'file.id',
                    'file.uuid',
                    'file.blob_id',
                    'file.name',
                    'file.download_name',
                    'file.url',
                    'file.is_temporary',
                    'file.uploader_id',
                    'file.client',
                    'file.client_ip',
                    'file.user_agent',
                    'file.category',
                    'file.status',
                    'file.created_at',
                    'file.deleted_at',
                    'file_blob.md5',
                    'file_blob.ext',
                    'file_blob.size',
                    'file_blob.mime_type',
                    'file_blob.ref_count',
                    'account.login_name',
                    'users.name as uploader_name',
                ]),
        ];
    }

    public static function createFromBlob(object $blob, array $values): int
    {
        return (int) self::query()->insertGetId([
            'uuid' => $values['uuid'],
            'blob_id' => (int) $blob->id,
            'name' => $values['name'],
            'download_name' => $values['download_name'],
            'url' => (string) $blob->url,
            'is_temporary' => $values['is_temporary'] ? 1 : 0,
            'uploader_id' => (int) $values['uploader_id'],
            'client' => $values['client'] ?? null,
            'client_ip' => $values['client_ip'] ?? null,
            'user_agent' => $values['user_agent'] ?? null,
            'category' => $values['category'],
            'status' => 'enabled',
            'created_at' => $values['created_at'],
            'updated_at' => $values['updated_at'],
        ]);
    }

    public static function activeId(int $fileId): ?self
    {
        return self::query()
            ->where('id', $fileId)
            ->whereNull('deleted_at')
            ->first(['id']);
    }

    public static function lockActiveById(int $fileId): ?self
    {
        return self::query()
            ->where('id', $fileId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * 读取超过保留期限的临时文件 ID。
     */
    public static function expiredTemporaryIds(string $before, int $limit = 500): array
    {
        return self::query()
            ->where('is_temporary', 1)
            ->where('created_at', '<=', $before)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 统计物理文件对应的有效文件记录。
     */
    public static function activeCountByBlob(int $blobId): int
    {
        return (int) self::query()
            ->where('blob_id', $blobId)
            ->whereNull('deleted_at')
            ->count();
    }

    public static function softDeleteById(int $fileId, string $now): int
    {
        return self::query()
            ->where('id', $fileId)
            ->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'status' => 'deleted',
            ]);
    }
}
