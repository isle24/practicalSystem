<?php

namespace app\model\channel;

class FileRelation extends BaseModel
{
    protected $table = 'file_relation';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function rowsForEntity(string $entityType, int $entityId, ?string $tag = null): mixed
    {
        $query = self::query()
            ->join('file', 'file_relation.file_id', '=', 'file.id')
            ->join('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->where('file_relation.entity_type', $entityType)
            ->where('file_relation.entity_id', $entityId)
            ->whereNull('file_relation.deleted_at')
            ->whereNull('file.deleted_at')
            ->orderBy('file_relation.id');

        if ($tag !== null && $tag !== '') {
            $query->where('file_relation.tag', $tag);
        }

        return $query->get([
            'file_relation.id as relation_id',
            'file_relation.tag',
            'file.id',
            'file.uuid',
            'file.blob_id',
            'file.name',
            'file.download_name',
            'file.url',
            'file.is_temporary',
            'file.uploader_id',
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

    public static function activeByUnique(int $fileId, string $entityType, int $entityId, string $tag): ?self
    {
        $query = self::query()
            ->where('file_id', $fileId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at');
        $tag === '' ? $query->whereNull('tag') : $query->where('tag', $tag);

        return $query->first(['id']);
    }

    public static function createRelation(int $fileId, string $entityType, int $entityId, string $tag, string $now): int
    {
        return (int) self::query()->insertGetId([
            'file_id' => $fileId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'tag' => $tag === '' ? null : $tag,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function softDeleteById(int $relationId, string $now): int
    {
        return self::query()
            ->where('id', $relationId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
    }

    public static function softDeleteByUnique(int $fileId, string $entityType, int $entityId, string $tag, string $now): int
    {
        $query = self::query()
            ->where('file_id', $fileId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at');
        $tag === '' ? $query->whereNull('tag') : $query->where('tag', $tag);

        return $query->update(['deleted_at' => $now, 'updated_at' => $now]);
    }

    public static function activeCountByFile(int $fileId): int
    {
        return (int) self::query()
            ->where('file_id', $fileId)
            ->whereNull('deleted_at')
            ->count();
    }

    public static function softDeleteByFile(int $fileId, string $now): int
    {
        return self::query()
            ->where('file_id', $fileId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
    }

    public static function activeCountByBlob(int $blobId): int
    {
        return (int) self::query()
            ->join('file', 'file_relation.file_id', '=', 'file.id')
            ->where('file.blob_id', $blobId)
            ->whereNull('file_relation.deleted_at')
            ->whereNull('file.deleted_at')
            ->count();
    }
}
