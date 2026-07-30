<?php

namespace app\model\channel;

use InvalidArgumentException;

class AcademicArchiveRecord extends BaseModel
{
    protected $guarded = [];
    public $timestamps = false;

    /** 查询基础档案分页。 */
    public static function page(array $definition, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::queryTable($definition['table'])->whereNull('deleted_at');

        $flag = trim((string) ($filters['flag'] ?? ''));
        if ($flag !== '' && $flag !== 'all' && !empty($definition['flag_field'])) {
            $mapped = ($definition['flag_values'] ?? [])[$flag] ?? $flag;
            $query->where($definition['flag_field'], $mapped);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($definition, $like): void {
                foreach ($definition['keyword'] as $index => $column) {
                    $index === 0 ? $builder->where($column, 'like', $like) : $builder->orWhere($column, 'like', $like);
                }
            });
        }

        $total = (int) (clone $query)->count();
        foreach ($definition['order'] as $order) {
            $query->orderBy($order);
        }

        return [
            'items' => $query->forPage($page, $pageSize)->get($definition['columns'])
                ->map(static fn ($row): array => $row->toArray())->all(),
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
        ];
    }

    /** 保存基础档案并维护当前记录约束。 */
    public static function saveArchive(array $definition, ?int $id, array $values, string $now): int
    {
        return self::connection()->transaction(function () use ($definition, $id, $now, $values): int {
            if (($definition['type'] ?? '') === 'internship_category') {
                self::assertCategoryScopeEditable($id, (string) ($values['scope_type'] ?? 'grade'));
            }

            if (($values['is_current'] ?? 'false') === 'true') {
                self::clearCurrent($definition, $id, $now);
            }

            if ($id) {
                self::queryTable($definition['table'])
                    ->where($definition['id'], $id)
                    ->whereNull('deleted_at')
                    ->update(array_merge($values, ['updated_at' => $now]));
                return $id;
            }

            $payload = array_merge($values, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!empty($definition['uuid'])) {
                $payload[$definition['uuid']] = self::uuidValue();
            }

            return (int) self::queryTable($definition['table'])->insertGetId($payload, $definition['id']);
        });
    }

    /** 软删除基础档案。 */
    public static function softDelete(array $definition, int $id, string $now): void
    {
        if (($definition['type'] ?? '') === 'internship_category' && self::categoryReferenced($id)) {
            throw new InvalidArgumentException('该实习类别已被计划使用，不能删除');
        }

        $values = [
            'deleted_at' => $now,
            'updated_at' => $now,
        ];
        if (!empty($definition['flag_field'])) {
            $values[$definition['flag_field']] = $definition['disabled_value'];
        }

        self::queryTable($definition['table'])
            ->where($definition['id'], $id)
            ->whereNull('deleted_at')
            ->update($values);
    }

    /** 查询当前年级编号。 */
    public static function currentGradeId(): ?int
    {
        return self::currentId('grade_list', 'grade_id', 'flag');
    }

    /** 查询当前毕业届次编号。 */
    public static function currentGraduationCohortId(): ?int
    {
        return self::currentId('graduation_cohort', 'cohort_id', 'flag');
    }

    /** 查询启用年级选项。 */
    public static function enabledGrades(): array
    {
        return self::enabledRows('grade_list', ['grade_id', 'grade_name', 'is_current'], 'flag', ['sort', 'grade_id']);
    }

    /** 查询启用毕业届次选项。 */
    public static function enabledGraduationCohorts(): array
    {
        return self::enabledRows('graduation_cohort', ['cohort_id', 'cohort_name', 'cohort_year', 'is_current'], 'flag', ['sort', 'cohort_id']);
    }

    /** 查询启用实习类别选项。 */
    public static function enabledInternshipCategories(): array
    {
        return self::enabledRows('internship_category', ['id', 'code', 'name', 'scope_type', 'sort'], 'status', ['sort', 'id'], 'enabled');
    }

    /** 查询指定实习类别。 */
    public static function internshipCategory(int $id): ?array
    {
        $row = self::queryTable('internship_category')
            ->where('id', $id)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'name', 'scope_type']);

        return $row ? $row->toArray() : null;
    }

    /** 判断实习类别是否已被计划引用。 */
    public static function categoryReferenced(int $id): bool
    {
        return self::queryTable('internship_plan')
            ->where('category_id', $id)
            ->whereNull('deleted_at')
            ->exists();
    }

    /** 返回学校业务库连接。 */
    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    /** 返回指定业务表查询构造器。 */
    private static function queryTable(string $table): mixed
    {
        $model = new static();
        $model->setTable($table);
        return $model->newQuery();
    }

    /** 清除同类型其他当前记录。 */
    private static function clearCurrent(array $definition, ?int $excludeId, string $now): void
    {
        $query = self::queryTable($definition['table'])
            ->where('is_current', 'true')
            ->whereNull('deleted_at');
        if ($excludeId) {
            $query->where($definition['id'], '<>', $excludeId);
        }
        $query->update(['is_current' => 'false', 'updated_at' => $now]);
    }

    /** 校验已使用类别的归属维度不可更改。 */
    private static function assertCategoryScopeEditable(?int $id, string $scopeType): void
    {
        if (!$id || !self::categoryReferenced($id)) {
            return;
        }
        $current = self::queryTable('internship_category')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->value('scope_type');
        if ($current !== null && (string) $current !== $scopeType) {
            throw new InvalidArgumentException('该实习类别已被计划使用，不能修改归属维度');
        }
    }

    /** 查询当前记录编号。 */
    private static function currentId(string $table, string $idField, string $flagField): ?int
    {
        $id = self::queryTable($table)
            ->where('is_current', 'true')
            ->where($flagField, 'on')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->value($idField);

        return is_numeric($id) ? (int) $id : null;
    }

    /** 查询启用档案选项。 */
    private static function enabledRows(
        string $table,
        array $columns,
        string $flagField,
        array $orders,
        string $enabledValue = 'on'
    ): array {
        $query = self::queryTable($table)
            ->where($flagField, $enabledValue)
            ->whereNull('deleted_at');
        foreach ($orders as $order) {
            $query->orderBy($order);
        }

        return $query->get($columns)->map(static fn ($row): array => $row->toArray())->all();
    }

    /** 生成业务 UUID。 */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
