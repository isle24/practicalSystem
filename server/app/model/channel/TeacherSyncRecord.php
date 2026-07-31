<?php

namespace app\model\channel;

use InvalidArgumentException;

class TeacherSyncRecord extends BaseModel
{
    protected $table = 'teacher_list';
    protected $primaryKey = 'teacher_id';
    protected $guarded = [];
    public $timestamps = false;

    /** 在学校业务库事务中执行同步写入 */
    public static function transaction(callable $callback): mixed
    {
        return (new static())->getConnection()->transaction($callback);
    }

    /** 按应用编号查询启用的开放同步应用 */
    public static function applicationByAppId(string $appId): ?array
    {
        $row = self::queryTable('open_sync_app')
            ->where('app_id', $appId)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'app_id', 'app_secret', 'allowed_ips', 'last_used_at', 'secret_updated_at']);

        return $row ? self::row($row, ['allowed_ips']) : null;
    }

    /** 按应用编号查询开放应用维护资料 */
    public static function applicationForManagement(string $appId): ?array
    {
        $row = self::queryTable('open_sync_app')
            ->where('app_id', $appId)
            ->whereNull('deleted_at')
            ->first([
                'id', 'app_id', 'app_secret', 'allowed_ips', 'status',
                'last_used_at', 'secret_updated_at', 'created_at', 'updated_at',
            ]);

        return $row ? self::row($row, ['allowed_ips']) : null;
    }

    /** 保存或轮换开放同步应用 */
    public static function saveApplication(string $appId, string $encryptedSecret, array $allowedIps, string $status, string $now): array
    {
        $query = self::queryTable('open_sync_app')->where('app_id', $appId);
        $existing = $query->first(['id', 'app_secret']);
        $values = [
            'allowed_ips' => json_encode(array_values($allowedIps), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        if ($encryptedSecret !== '') {
            $values['app_secret'] = $encryptedSecret;
            $values['secret_updated_at'] = $now;
        }

        if ($existing) {
            $query->update($values);
        } else {
            if ($encryptedSecret === '') {
                throw new InvalidArgumentException('首次创建同步应用必须填写 app_secret');
            }
            self::queryTable('open_sync_app')->insert(array_merge($values, [
                'uuid' => self::uuid(),
                'app_id' => $appId,
                'created_at' => $now,
            ]));
        }

        return self::applicationForManagement($appId) ?: [];
    }

    /** 更新开放同步应用最后使用时间 */
    public static function touchApplication(int $id, string $now): void
    {
        self::queryTable('open_sync_app')->where('id', $id)->update([
            'last_used_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** 查询已完成的同步批次 */
    public static function batchByRequestId(string $requestId): ?array
    {
        $row = self::queryTable('teacher_sync_batch')
            ->where('request_id', $requestId)
            ->whereNull('deleted_at')
            ->first(['id', 'request_id', 'sync_source', 'status', 'result_json', 'processed_at']);

        return $row ? self::row($row, ['result_json']) : null;
    }

    /** 创建同步批次占位记录 */
    public static function createBatch(string $requestId, string $source, int $received, string $now): bool
    {
        return self::queryTable('teacher_sync_batch')->insertOrIgnore([
            'uuid' => self::uuid(),
            'request_id' => $requestId,
            'sync_source' => $source,
            'received_count' => $received,
            'status' => 'processing',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]) === 1;
    }

    /** 完成同步批次并保存结果 */
    public static function completeBatch(string $requestId, array $result, string $now): void
    {
        self::queryTable('teacher_sync_batch')->where('request_id', $requestId)->update([
            'created_count' => (int) ($result['created'] ?? 0),
            'updated_count' => (int) ($result['updated'] ?? 0),
            'disabled_count' => (int) ($result['disabled'] ?? 0),
            'failed_count' => (int) ($result['failed'] ?? 0),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processed_at' => $now,
            'status' => 'completed',
            'updated_at' => $now,
        ]);
    }

    /** 按代码查询启用学院 */
    public static function departmentByCode(string $code): ?array
    {
        $row = self::queryTable('department')
            ->where('dep_code', $code)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['dep_id', 'dep_code', 'dep_name']);

        return $row ? self::row($row) : null;
    }

    /** 按学院和代码查询启用专业 */
    public static function professionByCode(string $code, int $departmentId): ?array
    {
        $row = self::queryTable('profession')
            ->where('profession_code', $code)
            ->where('dep_id', $departmentId)
            ->where('flag', 'on')
            ->whereNull('deleted_at')
            ->first(['profession_id', 'profession_code', 'profession_name', 'dep_id']);

        return $row ? self::row($row) : null;
    }

    /** 按外部编号和教师编号增量写入教师档案 */
    public static function upsertTeacher(array $values, string $now): string
    {
        $externalRows = self::queryTable('teacher_list')
            ->where('external_id', $values['external_id'])
            ->lockForUpdate()
            ->limit(2)
            ->get(['teacher_id']);
        if ($externalRows->count() > 1) {
            throw new InvalidArgumentException('external_id 存在重复教师档案，请先清理重复数据并完成唯一索引升级');
        }
        $numberRows = self::queryTable('teacher_list')
            ->where('teacher_num', $values['teacher_num'])
            ->lockForUpdate()
            ->limit(2)
            ->get(['teacher_id']);
        if ($numberRows->count() > 1) {
            throw new InvalidArgumentException('teacher_num 存在重复教师档案，请先清理重复数据并完成唯一索引升级');
        }
        $external = $externalRows->first();
        $number = $numberRows->first();
        if ($external && $number && (int) $external->teacher_id !== (int) $number->teacher_id) {
            throw new InvalidArgumentException('external_id 与 teacher_num 分别属于不同教师档案');
        }

        $teacherId = (int) ($external->teacher_id ?? $number->teacher_id ?? 0);
        $status = $values['status'] === 'disabled' ? 'disabled' : 'enabled';
        $payload = [
            'external_id' => $values['external_id'],
            'teacher_name' => $values['teacher_name'],
            'teacher_num' => $values['teacher_num'],
            'dep_id' => $values['dep_id'],
            'profession_id' => $values['profession_id'],
            'gender' => $values['gender'],
            'birth_date' => $values['birth_date'],
            'title' => $values['title'],
            'education' => $values['education'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'employment_type' => $values['employment_type'],
            'sync_source' => $values['sync_source'],
            'source_updated_at' => $values['source_updated_at'],
            'last_synced_at' => $now,
            'status' => $status,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        if ($teacherId > 0) {
            self::queryTable('teacher_list')->where('teacher_id', $teacherId)->update($payload);
            return $status === 'disabled' ? 'disabled' : 'updated';
        }

        self::queryTable('teacher_list')->insert(array_merge($payload, [
            'teacher_uuid' => self::uuid(),
            'created_at' => $now,
        ]));

        return $status === 'disabled' ? 'disabled' : 'created';
    }

    /** 按当前组织范围分页查询启用教师 */
    public static function teacherPage(array $scope, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::queryTable('teacher_list')
            ->leftJoin('department', 'teacher_list.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'teacher_list.profession_id', '=', 'profession.profession_id')
            ->where('teacher_list.status', 'enabled')
            ->whereNull('teacher_list.deleted_at');

        self::applyScope($query, $scope);
        if (!empty($filters['dep_id'])) {
            $query->where('teacher_list.dep_id', (int) $filters['dep_id']);
        }
        if (!empty($filters['profession_id'])) {
            $query->where('teacher_list.profession_id', (int) $filters['profession_id']);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $like = '%' . addcslashes($keyword, '%_\\') . '%';
                $builder->where('teacher_list.teacher_name', 'like', $like)
                    ->orWhere('teacher_list.teacher_num', 'like', $like)
                    ->orWhere('teacher_list.phone', 'like', $like)
                    ->orWhere('department.dep_name', 'like', $like)
                    ->orWhere('profession.profession_name', 'like', $like);
            });
        }

        $total = (int) (clone $query)->count('teacher_list.teacher_id');
        $items = $query->orderBy('teacher_list.teacher_name')
            ->orderBy('teacher_list.teacher_id')
            ->forPage($page, $pageSize)
            ->get([
                'teacher_list.teacher_id',
                'teacher_list.user_id',
                'teacher_list.external_id',
                'teacher_list.teacher_num',
                'teacher_list.teacher_name',
                'teacher_list.dep_id',
                'teacher_list.profession_id',
                'teacher_list.gender',
                'teacher_list.birth_date',
                'teacher_list.title',
                'teacher_list.education',
                'teacher_list.phone',
                'teacher_list.email',
                'teacher_list.employment_type',
                'teacher_list.source_updated_at',
                'teacher_list.last_synced_at',
                'department.dep_name',
                'profession.profession_name',
            ])
            ->map(static fn ($item): array => method_exists($item, 'toArray') ? $item->toArray() : (array) $item)
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    /** 按组织范围查询单个启用教师档案 */
    public static function teacherById(array $scope, int $teacherId): ?array
    {
        $query = self::queryTable('teacher_list')
            ->leftJoin('department', 'teacher_list.dep_id', '=', 'department.dep_id')
            ->leftJoin('profession', 'teacher_list.profession_id', '=', 'profession.profession_id')
            ->where('teacher_list.teacher_id', $teacherId)
            ->where('teacher_list.status', 'enabled')
            ->whereNull('teacher_list.deleted_at');
        self::applyScope($query, $scope);
        $row = $query->first([
            'teacher_list.teacher_id',
            'teacher_list.user_id',
            'teacher_list.teacher_name',
            'teacher_list.teacher_num',
            'teacher_list.dep_id',
            'teacher_list.profession_id',
            'teacher_list.gender',
            'teacher_list.birth_date',
            'teacher_list.title',
            'teacher_list.education',
            'teacher_list.phone',
            'teacher_list.email',
            'department.dep_name',
            'profession.profession_name',
        ]);

        return $row ? self::row($row) : null;
    }

    /** 创建指定表的业务库查询 */
    private static function queryTable(string $table): mixed
    {
        $model = new static();
        $model->setTable($table);
        return $model->newQuery();
    }

    /** 应用学院或专业管理范围 */
    private static function applyScope(mixed $query, array $scope): void
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if (in_array($roleType, ['super_admin', 'school_admin'], true)) {
            return;
        }
        if ($roleType === 'college_admin') {
            $ids = array_values(array_filter(array_map('intval', (array) ($scope['dep_ids'] ?? []))));
            $ids ? $query->whereIn('teacher_list.dep_id', $ids) : $query->whereRaw('1 = 0');
            return;
        }
        if ($roleType === 'profession_admin') {
            $ids = array_values(array_filter(array_map('intval', (array) ($scope['profession_ids'] ?? []))));
            $ids ? $query->whereIn('teacher_list.profession_id', $ids) : $query->whereRaw('1 = 0');
            return;
        }

        $query->whereRaw('1 = 0');
    }

    /** 转换模型行为数组并解码 JSON 字段 */
    private static function row(object $row, array $jsonFields = []): array
    {
        $values = method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
        foreach ($jsonFields as $field) {
            $decoded = json_decode((string) ($values[$field] ?? ''), true);
            $values[$field] = is_array($decoded) ? $decoded : [];
        }
        return $values;
    }

    /** 生成关系 UUID */
    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-4%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            dechex((hexdec($hex[16]) & 0x3) | 0x8),
            substr($hex, 17, 3),
            substr($hex, 20, 12)
        );
    }
}
