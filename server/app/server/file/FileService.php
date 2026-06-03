<?php

namespace app\server\file;

use app\server\config\ConfigService;
use app\server\CurrentContext;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use support\Db;
use support\Request;
use Throwable;
use Tinywan\Storage\Adapter\LocalAdapter;
use Webman\Http\UploadFile;
use ZipArchive;

class FileService
{
    private const MAX_UPLOADS_PER_MINUTE = 20;

    private const EXTENSION_LIMITS = [
        'jpg' => 10485760,
        'jpeg' => 10485760,
        'png' => 10485760,
        'gif' => 10485760,
        'webp' => 10485760,
        'pdf' => 52428800,
        'doc' => 52428800,
        'docx' => 52428800,
        'xls' => 52428800,
        'xlsx' => 52428800,
        'ppt' => 52428800,
        'pptx' => 52428800,
        'zip' => 104857600,
        'rar' => 104857600,
        'txt' => 10485760,
        'csv' => 10485760,
    ];

    private const MIME_MAP = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'rar' => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const DANGEROUS_ARCHIVE_EXTENSIONS = ['exe', 'sh', 'php', 'js', 'html', 'htm', 'bat', 'cmd', 'com', 'msi'];

    public function check(Request $request): array
    {
        $accountId = $this->accountId();
        $md5 = $this->md5Input((string) $request->input('md5', ''));
        $name = $this->fileName((string) $request->input('name', ''));
        $category = $this->category((string) $request->input('category', 'general'));
        $isTemporary = $this->boolInput($request->input('is_temporary', false));
        $device = $this->deviceInfo($request);

        if (!$this->instantUploadEnabled()) {
            return $this->uploadRequired($md5);
        }

        $db = $this->db();
        $blob = $db->table('file_blob')
            ->where('md5', $md5)
            ->whereNull('deleted_at')
            ->first();

        if (!$blob || !$this->blobFileExists($blob)) {
            return $this->uploadRequired($md5);
        }

        return $db->transaction(function () use ($db, $blob, $name, $category, $isTemporary, $accountId, $device): array {
            $lockedBlob = $db->table('file_blob')
                ->where('id', $blob->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedBlob || $lockedBlob->deleted_at !== null) {
                return $this->uploadRequired((string) $blob->md5);
            }

            $db->table('file_blob')
                ->where('id', $lockedBlob->id)
                ->update([
                    'ref_count' => Db::raw('COALESCE(`ref_count`, 0) + 1'),
                    'updated_at' => $this->now(),
                ]);

            $fileId = $this->insertFile($db, $lockedBlob, $name, $category, $isTemporary, $accountId, null, $device);

            return [
                'action' => 'instant',
                'file_id' => $fileId,
                'blob_id' => (int) $lockedBlob->id,
                'url' => (string) $lockedBlob->url,
                'name' => $name,
            ];
        });
    }

    public function upload(Request $request, array $options = []): array
    {
        $accountId = (int) ($options['uploader_id'] ?? $this->accountId());
        $this->limitUploadRate($accountId);

        $file = $this->requestFile($request);
        $name = $this->fileName((string) ($options['name'] ?? $request->input('name', $file->getUploadName() ?: '')));
        $category = $this->category((string) ($options['category'] ?? $request->input('category', 'general')));
        $downloadName = $this->fileName((string) ($options['download_name'] ?? $request->input('download_name', $name)));
        $isTemporary = $this->boolInput($options['is_temporary'] ?? $request->input('is_temporary', false));
        $requireMd5 = (bool) ($options['require_md5'] ?? true);
        $declaredMd5 = $this->optionalMd5((string) ($options['md5'] ?? $request->header('x-file-md5', $request->input('md5', ''))));
        $device = $this->deviceInfo($request);

        if ($requireMd5 && $declaredMd5 === null) {
            throw new InvalidArgumentException('缺少 X-File-MD5');
        }

        $extension = $this->extension($file);
        $allowedExtensions = $this->allowedExtensions($options['allowed_extensions'] ?? null);
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException('文件扩展名不允许');
        }

        $maxSize = (int) ($options['max_size'] ?? self::EXTENSION_LIMITS[$extension]);
        if ($file->getSize() > $maxSize) {
            throw new InvalidArgumentException('文件大小超出限制');
        }

        $uploaded = $this->uploadToLocalStorage($category, $allowedExtensions, $maxSize);
        $savedPath = (string) $uploaded['save_path'];

        try {
            $mimeType = $this->assertSavedFile($savedPath, $extension);
            $actualMd5 = md5_file($savedPath);
            if ($actualMd5 === false) {
                throw new RuntimeException('文件校验失败');
            }

            $actualMd5 = strtolower($actualMd5);
            $md5 = $declaredMd5 ?? $actualMd5;
            if ($actualMd5 !== $md5) {
                throw new InvalidArgumentException('文件校验失败，请重新上传');
            }

            $lockToken = $this->acquireMd5Lock($md5);
            try {
                return $this->persistUploadedFile([
                'md5' => $md5,
                'sha1' => sha1_file($savedPath) ?: null,
                'path' => $this->relativePublicPath($savedPath),
                'url' => '/' . $this->relativePublicPath($savedPath),
                'ext' => $extension,
                'size' => (int) $uploaded['size'],
                'mime_type' => $mimeType,
                'disk' => 'public',
                'block' => $this->storageBlock(),
                'category' => $category,
            ], [
                'name' => $name,
                'download_name' => $downloadName,
                'is_temporary' => $isTemporary,
                'uploader_id' => $accountId,
                'category' => $category,
                'device' => $device,
                ], $savedPath);
            } finally {
                if ($lockToken !== null) {
                    $this->releaseMd5Lock($md5, $lockToken);
                }
            }
        } catch (Throwable $exception) {
            $this->removeLocalFile($savedPath);
            throw $exception;
        }
    }

    public function info(int $fileId): array
    {
        $this->accountId();
        if ($fileId <= 0) {
            throw new InvalidArgumentException('file_id 无效');
        }

        $row = $this->db()->table('file')
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

        if (!$row) {
            throw new RuntimeException('文件不存在');
        }

        return $this->fileInfo($row);
    }

    public function relations(string $entityType, int $entityId, ?string $tag = null): array
    {
        $this->accountId();
        $entityType = $this->entityType($entityType);
        if ($entityId <= 0) {
            throw new InvalidArgumentException('entity_id 无效');
        }

        $query = $this->db()->table('file_relation')
            ->join('file', 'file_relation.file_id', '=', 'file.id')
            ->join('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->where('file_relation.entity_type', $entityType)
            ->where('file_relation.entity_id', $entityId)
            ->whereNull('file_relation.deleted_at')
            ->whereNull('file.deleted_at')
            ->orderBy('file_relation.id');

        if ($tag !== null && $tag !== '') {
            $query->where('file_relation.tag', $this->tag($tag));
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
        ])->map(fn ($row): array => array_merge(
            ['relation_id' => (int) $row->relation_id, 'tag' => $row->tag],
            $this->fileInfo($row)
        ))->all();
    }

    public function attach(int $fileId, string $entityType, int $entityId, string $tag = ''): array
    {
        $this->accountId();
        $entityType = $this->entityType($entityType);
        $tag = $tag === '' ? '' : $this->tag($tag);
        if ($fileId <= 0 || $entityId <= 0) {
            throw new InvalidArgumentException('关联参数无效');
        }

        return $this->db()->transaction(function () use ($fileId, $entityType, $entityId, $tag): array {
            $db = $this->db();
            $file = $db->table('file')
                ->where('id', $fileId)
                ->whereNull('deleted_at')
                ->first(['id']);
            if (!$file) {
                throw new RuntimeException('文件不存在');
            }

            $query = $db->table('file_relation')
                ->where('file_id', $fileId)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->whereNull('deleted_at');
            $tag === '' ? $query->whereNull('tag') : $query->where('tag', $tag);
            $relation = $query->first(['id']);
            if ($relation) {
                return [
                    'relation_id' => (int) $relation->id,
                    'file_id' => $fileId,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'tag' => $tag,
                ];
            }

            $relationId = (int) $db->table('file_relation')->insertGetId([
                'file_id' => $fileId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'tag' => $tag === '' ? null : $tag,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            return [
                'relation_id' => $relationId,
                'file_id' => $fileId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'tag' => $tag,
            ];
        });
    }

    public function detach(Request $request): array
    {
        $this->accountId();
        $relationId = $this->intInput($request, 'relation_id');
        $now = $this->now();
        $db = $this->db();

        if ($relationId > 0) {
            $affected = $db->table('file_relation')
                ->where('id', $relationId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now]);

            return ['affected' => $affected];
        }

        $fileId = $this->intInput($request, 'file_id');
        $entityType = $this->entityType((string) $request->input('entity_type', ''));
        $entityId = $this->intInput($request, 'entity_id');
        $tag = (string) $request->input('tag', '');

        if ($fileId <= 0 || $entityId <= 0) {
            throw new InvalidArgumentException('解除关联参数无效');
        }

        $query = $db->table('file_relation')
            ->where('file_id', $fileId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at');
        $tag === '' ? $query->whereNull('tag') : $query->where('tag', $this->tag($tag));

        return [
            'affected' => $query->update(['deleted_at' => $now, 'updated_at' => $now]),
        ];
    }

    public function delete(int $fileId, bool $force = false): array
    {
        $this->accountId();
        if ($fileId <= 0) {
            throw new InvalidArgumentException('file_id 无效');
        }

        $deletePath = null;
        $result = $this->db()->transaction(function () use ($fileId, $force, &$deletePath): array {
            $db = $this->db();
            $now = $this->now();
            $file = $db->table('file')
                ->where('id', $fileId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (!$file) {
                throw new RuntimeException('文件不存在');
            }

            $relationCount = (int) $db->table('file_relation')
                ->where('file_id', $fileId)
                ->whereNull('deleted_at')
                ->count();

            if ($relationCount > 0 && !$force) {
                throw new RuntimeException('文件仍有关联，请先解除关联');
            }

            if ($force) {
                $db->table('file_relation')
                    ->where('file_id', $fileId)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            $db->table('file')
                ->where('id', $fileId)
                ->update(['deleted_at' => $now, 'updated_at' => $now, 'status' => 'deleted']);

            $db->table('file_blob')
                ->where('id', $file->blob_id)
                ->update([
                    'ref_count' => Db::raw('GREATEST(COALESCE(`ref_count`, 0) - 1, 0)'),
                    'updated_at' => $now,
                ]);

            $blob = $db->table('file_blob')
                ->where('id', $file->blob_id)
                ->lockForUpdate()
                ->first();

            $activeRelationCount = (int) $db->table('file_relation')
                ->join('file', 'file_relation.file_id', '=', 'file.id')
                ->where('file.blob_id', $file->blob_id)
                ->whereNull('file_relation.deleted_at')
                ->whereNull('file.deleted_at')
                ->count();

            $physicalDeleted = false;
            if ($blob && (int) $blob->ref_count <= 0 && $activeRelationCount === 0) {
                $deletePath = $this->absolutePublicPath((string) $blob->path);
                $db->table('file_blob')
                    ->where('id', $blob->id)
                    ->update(['deleted_at' => $now, 'updated_at' => $now, 'ref_count' => 0]);
                $physicalDeleted = true;
            }

            return [
                'file_id' => $fileId,
                'blob_id' => (int) $file->blob_id,
                'physical_delete_pending' => $physicalDeleted,
            ];
        });

        if ($deletePath) {
            $this->removeLocalFile($deletePath);
        }

        $result['physical_deleted'] = $deletePath !== null;
        unset($result['physical_delete_pending']);

        return $result;
    }

    public function downloadInfo(int $fileId): array
    {
        $info = $this->info($fileId);

        return [
            'file_id' => $info['id'],
            'url' => $info['url'],
            'download_name' => $info['download_name'],
        ];
    }

    public function list(Request $request): array
    {
        $accountId = $this->accountId();
        $db = $this->db();
        $page = max(1, $this->intInput($request, 'page') ?: 1);
        $pageSize = min(100, max(10, $this->intInput($request, 'page_size') ?: 20));
        $keyword = trim((string) $request->input('keyword', ''));
        $category = trim((string) $request->input('category', ''));
        $status = (string) $request->input('status', 'all');

        $query = $db->table('file')
            ->join('file_blob', 'file.blob_id', '=', 'file_blob.id')
            ->leftJoin('account', 'file.uploader_id', '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id');

        if (!$this->isFileAdmin()) {
            $query->where('file.uploader_id', $accountId);
        }
        if ($status === 'active') {
            $query->whereNull('file.deleted_at');
        } elseif ($status === 'deleted') {
            $query->whereNotNull('file.deleted_at');
        }
        if ($category !== '') {
            $query->where('file.category', $this->category($category));
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

        $total = (int) (clone $query)->count();
        $rows = $query
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
            ]);

        return [
            'items' => $rows->map(fn ($row): array => [
                'id' => (int) $row->id,
                'uuid' => $row->uuid,
                'blob_id' => (int) $row->blob_id,
                'name' => $row->name,
                'download_name' => $row->download_name ?: $row->name,
                'url' => $row->url,
                'category' => $row->category,
                'status' => $row->deleted_at ? 'deleted' : (string) $row->status,
                'is_temporary' => (bool) $row->is_temporary,
                'created_at' => $row->created_at,
                'deleted_at' => $row->deleted_at,
                'uploader' => [
                    'account_id' => $row->uploader_id === null ? null : (int) $row->uploader_id,
                    'login_name' => $row->login_name,
                    'name' => $row->uploader_name,
                ],
                'device' => [
                    'client' => $row->client,
                    'ip' => $row->client_ip,
                    'user_agent' => $row->user_agent,
                ],
                'blob' => [
                    'md5' => $row->md5,
                    'ext' => $row->ext,
                    'size' => (int) $row->size,
                    'mime_type' => $row->mime_type,
                    'ref_count' => (int) $row->ref_count,
                ],
            ])->all(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    private function persistUploadedFile(array $blobData, array $fileData, string $savedPath): array
    {
        $removeSavedFile = false;

        $result = $this->db()->transaction(function () use ($blobData, $fileData, &$removeSavedFile): array {
            $db = $this->db();
            $now = $this->now();
            $blob = $db->table('file_blob')
                ->where('md5', $blobData['md5'])
                ->lockForUpdate()
                ->first();

            if ($blob && $blob->deleted_at === null && $this->blobFileExists($blob)) {
                $removeSavedFile = true;
                $db->table('file_blob')
                    ->where('id', $blob->id)
                    ->update([
                        'ref_count' => Db::raw('COALESCE(`ref_count`, 0) + 1'),
                        'updated_at' => $now,
                    ]);
                $activeBlob = $blob;
            } elseif ($blob) {
                $db->table('file_blob')
                    ->where('id', $blob->id)
                    ->update(array_merge($blobData, [
                        'ref_count' => 1,
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]));
                $activeBlob = (object) array_merge((array) $blob, $blobData, ['deleted_at' => null, 'ref_count' => 1]);
            } else {
                try {
                    $blobId = (int) $db->table('file_blob')->insertGetId(array_merge($blobData, [
                        'ref_count' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                    $activeBlob = (object) array_merge($blobData, ['id' => $blobId, 'ref_count' => 1]);
                } catch (QueryException $exception) {
                    if (!$this->isDuplicateMd5($exception)) {
                        throw $exception;
                    }

                    $activeBlob = $db->table('file_blob')
                        ->where('md5', $blobData['md5'])
                        ->lockForUpdate()
                        ->first();
                    if (!$activeBlob) {
                        throw $exception;
                    }
                    $removeSavedFile = true;
                    $db->table('file_blob')
                        ->where('id', $activeBlob->id)
                        ->update([
                            'ref_count' => Db::raw('COALESCE(`ref_count`, 0) + 1'),
                            'deleted_at' => null,
                            'updated_at' => $now,
                        ]);
                }
            }

            $fileId = $this->insertFile(
                $db,
                $activeBlob,
                $fileData['name'],
                $fileData['category'],
                (bool) $fileData['is_temporary'],
                (int) $fileData['uploader_id'],
                $fileData['download_name'],
                (array) ($fileData['device'] ?? [])
            );

            return [
                'action' => 'uploaded',
                'file_id' => $fileId,
                'blob_id' => (int) $activeBlob->id,
                'url' => (string) $activeBlob->url,
                'name' => $fileData['name'],
                'download_name' => $fileData['download_name'],
                'md5' => $blobData['md5'],
                'size' => (int) $blobData['size'],
                'mime_type' => $blobData['mime_type'],
                'category' => $fileData['category'],
            ];
        });

        if ($removeSavedFile) {
            $this->removeLocalFile($savedPath);
        }

        return $result;
    }

    private function insertFile(
        mixed $db,
        object $blob,
        string $name,
        string $category,
        bool $isTemporary,
        int $accountId,
        ?string $downloadName = null,
        array $device = []
    ): int {
        return (int) $db->table('file')->insertGetId([
            'uuid' => $this->uuid(),
            'blob_id' => (int) $blob->id,
            'name' => $name,
            'download_name' => $downloadName ?: $name,
            'url' => (string) $blob->url,
            'is_temporary' => $isTemporary ? 1 : 0,
            'uploader_id' => $accountId,
            'client' => $device['client'] ?? null,
            'client_ip' => $device['client_ip'] ?? null,
            'user_agent' => $device['user_agent'] ?? null,
            'category' => $category,
            'status' => 'enabled',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    private function uploadToLocalStorage(string $category, array $allowedExtensions, int $maxSize): array
    {
        $segment = $this->schoolSegment();
        $block = $this->storageBlock();
        $uri = "/files/{$block}/{$segment}/{$category}/";
        $adapter = new LocalAdapter([
            'root' => public_path() . $uri,
            'dirname' => date('Ymd'),
            'domain' => '',
            'uri' => $uri,
            'algo' => 'sha1',
            'include' => $allowedExtensions,
            'exclude' => ['exe', 'sh', 'php', 'js', 'html'],
            'single_limit' => $maxSize,
            'total_limit' => $maxSize,
            'nums' => 1,
            '_is_file_upload' => true,
        ]);

        $uploaded = $adapter->uploadFile();
        $file = $uploaded['file'] ?? reset($uploaded);
        if (!is_array($file) || empty($file['save_path'])) {
            throw new RuntimeException('文件保存失败');
        }

        return $file;
    }

    private function assertSavedFile(string $path, string $extension): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('文件保存失败');
        }

        $mimeType = $this->detectMimeType($path);
        $allowedMimes = self::MIME_MAP[$extension] ?? [];
        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            throw new InvalidArgumentException('文件类型校验失败');
        }

        if (in_array($extension, self::IMAGE_EXTENSIONS, true) && !getimagesize($path)) {
            throw new InvalidArgumentException('图片文件校验失败');
        }

        if ($extension === 'zip') {
            $this->assertZipFile($path);
        }
        if ($extension === 'rar') {
            $this->assertRarFile($path);
        }

        return $mimeType;
    }

    private function assertZipFile(string $path): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('当前环境不支持 ZIP 安全检查');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('压缩包校验失败');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($extension, self::DANGEROUS_ARCHIVE_EXTENSIONS, true)) {
                    throw new InvalidArgumentException('压缩包包含不允许的文件类型');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function assertRarFile(string $path): void
    {
        if (!class_exists('RarArchive')) {
            throw new RuntimeException('当前环境不支持 RAR 安全检查');
        }

        $archive = \RarArchive::open($path);
        if (!$archive) {
            throw new InvalidArgumentException('压缩包校验失败');
        }

        try {
            foreach ($archive->getEntries() ?: [] as $entry) {
                $extension = strtolower(pathinfo((string) $entry->getName(), PATHINFO_EXTENSION));
                if (in_array($extension, self::DANGEROUS_ARCHIVE_EXTENSIONS, true)) {
                    throw new InvalidArgumentException('压缩包包含不允许的文件类型');
                }
            }
        } finally {
            $archive->close();
        }
    }

    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            throw new RuntimeException('文件类型检测不可用');
        }

        try {
            $mimeType = finfo_file($finfo, $path);
            if (!is_string($mimeType) || $mimeType === '') {
                throw new RuntimeException('文件类型检测失败');
            }

            return $mimeType;
        } finally {
            finfo_close($finfo);
        }
    }

    private function requestFile(Request $request): UploadFile
    {
        $files = $request->file();
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            throw new InvalidArgumentException('上传文件无效');
        }

        if (is_array($files) && count($files) !== 1) {
            throw new InvalidArgumentException('仅支持单文件上传');
        }

        return $file;
    }

    private function extension(UploadFile $file): string
    {
        $extension = strtolower($file->getUploadExtension());
        if ($extension === '' || !isset(self::EXTENSION_LIMITS[$extension])) {
            throw new InvalidArgumentException('文件扩展名不允许');
        }

        return $extension;
    }

    private function allowedExtensions(mixed $extensions): array
    {
        if ($extensions === null) {
            return array_keys(self::EXTENSION_LIMITS);
        }

        if (!is_array($extensions)) {
            throw new InvalidArgumentException('文件扩展名配置无效');
        }

        $allowed = [];
        foreach ($extensions as $extension) {
            $extension = strtolower(trim((string) $extension));
            if (isset(self::EXTENSION_LIMITS[$extension])) {
                $allowed[] = $extension;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function accountId(): int
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            throw new RuntimeException('请先登录');
        }

        return $accountId;
    }

    private function md5Input(string $md5): string
    {
        $md5 = strtolower(trim($md5));
        if (!preg_match('/^[a-f0-9]{32}$/', $md5)) {
            throw new InvalidArgumentException('md5 无效');
        }

        return $md5;
    }

    private function optionalMd5(string $md5): ?string
    {
        $md5 = trim($md5);
        return $md5 === '' ? null : $this->md5Input($md5);
    }

    private function fileName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('文件名不能为空');
        }

        return $this->limit($name, 255);
    }

    private function category(string $category): string
    {
        $category = strtolower(trim($category));
        if ($category === '') {
            return 'general';
        }

        if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/', $category)) {
            throw new InvalidArgumentException('文件分类无效');
        }

        return $category;
    }

    private function entityType(string $entityType): string
    {
        $entityType = strtolower(trim($entityType));
        if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/', $entityType)) {
            throw new InvalidArgumentException('entity_type 无效');
        }

        return $entityType;
    }

    private function tag(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '' || mb_strlen($tag) > 80) {
            throw new InvalidArgumentException('tag 无效');
        }

        return $tag;
    }

    private function intInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }

    private function boolInput(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function uploadRequired(string $md5): array
    {
        return [
            'action' => 'upload_required',
            'md5' => $md5,
            'upload_token' => sha1($md5 . '|' . CurrentContext::accountId() . '|' . date('YmdHi')),
        ];
    }

    private function instantUploadEnabled(): bool
    {
        return $this->configBool('file.instant_upload_enabled', false);
    }

    private function storageBlock(): string
    {
        return $this->pathSegment((string) $this->configValue('file.block', 'b1'), 'b1');
    }

    private function schoolSegment(): string
    {
        return $this->pathSegment(
            CurrentContext::schoolCode()
                ?: (string) $this->configValue('file.school_code', '')
                ?: (string) CurrentContext::tenantDatabase()
                ?: (string) CurrentContext::tenantDatabaseId(),
            'school'
        );
    }

    private function configBool(string $name, bool $default): bool
    {
        $value = $this->configValue($name, $default);
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function configValue(string $name, mixed $default): mixed
    {
        try {
            $value = (new ConfigService())->get($name);
            return $value === null ? $default : $value;
        } catch (Throwable) {
            return $default;
        }
    }

    private function limitUploadRate(int $accountId): void
    {
        try {
            $key = 'file_upload_rate:' . CurrentContext::tenantDatabaseId() . ':' . $accountId . ':' . date('YmdHi');
            $count = (int) $this->redisCommand(['INCR', $key]);
            if ($count === 1) {
                $this->redisCommand(['EXPIRE', $key, '70']);
            }
        } catch (Throwable) {
            return;
        }

        if ($count > self::MAX_UPLOADS_PER_MINUTE) {
            throw new RuntimeException('上传过于频繁，请稍后再试', 429);
        }
    }

    private function acquireMd5Lock(string $md5): ?string
    {
        $key = 'file_md5_lock:' . $md5;
        $token = bin2hex(random_bytes(12));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                if ($this->redisCommand(['SET', $key, $token, 'NX', 'EX', '30']) === 'OK') {
                    return $token;
                }
            } catch (Throwable) {
                return null;
            }

            usleep(100000);
        }

        return null;
    }

    private function releaseMd5Lock(string $md5, string $token): void
    {
        $key = 'file_md5_lock:' . $md5;
        try {
            if ($this->redisCommand(['GET', $key]) === $token) {
                $this->redisCommand(['DEL', $key]);
            }
        } catch (Throwable) {
        }
    }

    private function redisCommand(array $parts): mixed
    {
        [$socket, $password, $database] = $this->redisSocket();
        try {
            if ($password !== '') {
                $this->redisWrite($socket, ['AUTH', $password]);
            }
            if ($database > 0) {
                $this->redisWrite($socket, ['SELECT', (string) $database]);
            }

            return $this->redisWrite($socket, $parts);
        } finally {
            fclose($socket);
        }
    }

    private function redisSocket(): array
    {
        $config = (array) config('redis.default', []);
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $address = str_starts_with($host, 'redis://') || str_starts_with($host, 'tcp://')
            ? $host
            : "tcp://{$host}:{$port}";

        $socket = @stream_socket_client($address, $errno, $message, 1.5);
        if (!$socket) {
            throw new RuntimeException($message ?: 'Redis 连接失败');
        }

        stream_set_timeout($socket, 2);

        return [
            $socket,
            (string) ($config['password'] ?? ''),
            (int) ($config['database'] ?? 0),
        ];
    }

    private function redisWrite(mixed $socket, array $parts): mixed
    {
        fwrite($socket, $this->redisEncode($parts));
        return $this->redisRead($socket);
    }

    private function redisEncode(array $parts): string
    {
        $command = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $part = (string) $part;
            $command .= '$' . strlen($part) . "\r\n{$part}\r\n";
        }

        return $command;
    }

    private function redisRead(mixed $socket): mixed
    {
        $line = fgets($socket);
        if ($line === false || $line === '') {
            throw new RuntimeException('Redis 响应无效');
        }

        $type = $line[0];
        $payload = substr($line, 1, -2);
        if ($type === '+') {
            return $payload;
        }
        if ($type === '-') {
            throw new RuntimeException($payload);
        }
        if ($type === ':') {
            return (int) $payload;
        }
        if ($type === '$') {
            $length = (int) $payload;
            if ($length < 0) {
                return null;
            }

            $data = '';
            while (strlen($data) < $length + 2) {
                $chunk = fread($socket, $length + 2 - strlen($data));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Redis 响应读取失败');
                }
                $data .= $chunk;
            }

            return substr($data, 0, $length);
        }
        if ($type === '*') {
            $items = [];
            for ($index = 0; $index < (int) $payload; $index++) {
                $items[] = $this->redisRead($socket);
            }

            return $items;
        }

        throw new RuntimeException('Redis 响应类型无效');
    }

    private function blobFileExists(object $blob): bool
    {
        return is_file($this->absolutePublicPath((string) $blob->path));
    }

    private function relativePublicPath(string $path): string
    {
        $publicPath = rtrim(str_replace('\\', '/', public_path()), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $publicPath)) {
            throw new RuntimeException('文件路径无效');
        }

        return ltrim(substr($path, strlen($publicPath)), '/');
    }

    private function absolutePublicPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function removeLocalFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function fileInfo(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'blob_id' => (int) $row->blob_id,
            'name' => $row->name,
            'download_name' => $row->download_name ?: $row->name,
            'url' => $row->url,
            'is_temporary' => (bool) $row->is_temporary,
            'uploader_id' => $row->uploader_id === null ? null : (int) $row->uploader_id,
            'device' => [
                'client' => $row->client ?? null,
                'ip' => $row->client_ip ?? null,
                'user_agent' => $row->user_agent ?? null,
            ],
            'category' => $row->category,
            'created_at' => $row->created_at,
            'blob' => [
                'md5' => $row->md5,
                'sha1' => $row->sha1,
                'path' => $row->path,
                'ext' => $row->ext,
                'size' => (int) $row->size,
                'mime_type' => $row->mime_type,
                'disk' => $row->disk,
                'block' => $row->block,
                'ref_count' => (int) $row->ref_count,
            ],
        ];
    }

    private function pathSegment(string $value, string $default): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value));
        $value = trim((string) $value, '_-');

        return $value === '' ? $default : $value;
    }

    private function deviceInfo(Request $request): array
    {
        return [
            'client' => $this->limit((string) (CurrentContext::get('client') ?: $request->input('client', 'WEB')), 40),
            'client_ip' => $this->limit($request->getRealIp(), 80),
            'user_agent' => $this->limit((string) $request->header('user-agent', ''), 255),
        ];
    }

    private function isFileAdmin(): bool
    {
        return in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true);
    }

    private function isDuplicateMd5(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && ((string) $exception->getCode() === '23000' || str_contains($exception->getMessage(), 'uk_md5'));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function db(): mixed
    {
        return Db::connection(CurrentContext::tenantConnection());
    }

    private function limit(string $value, int $maxLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
