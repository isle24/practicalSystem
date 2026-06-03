<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\model\channel\TableRecord as ChannelTable;
use app\server\CurrentContext;
use support\Request;
use support\Response;
use Throwable;

class ArchiveController
{
    use Responds;

    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin'];

    private const DEFINITIONS = [
        'department' => [
            'table' => 'department',
            'id' => 'dep_id',
            'columns' => ['dep_id', 'dep_name', 'dep_code', 'sort', 'flag'],
            'fields' => ['dep_name', 'dep_code', 'sort', 'flag'],
            'required' => 'dep_name',
            'order' => ['sort', 'dep_id'],
        ],
        'grade' => [
            'table' => 'grade_list',
            'id' => 'grade_id',
            'columns' => ['grade_id', 'grade_name', 'dep_id', 'sort', 'flag'],
            'fields' => ['grade_name', 'dep_id', 'sort', 'flag'],
            'required' => 'grade_name',
            'order' => ['sort', 'grade_id'],
        ],
        'profession' => [
            'table' => 'profession',
            'id' => 'profession_id',
            'columns' => ['profession_id', 'profession_name', 'profession_code', 'dep_id', 'grade_id', 'sort', 'flag'],
            'fields' => ['profession_name', 'profession_code', 'dep_id', 'grade_id', 'sort', 'flag'],
            'required' => 'profession_name',
            'order' => ['sort', 'profession_id'],
        ],
        'class' => [
            'table' => 'class',
            'id' => 'class_id',
            'columns' => ['class_id', 'class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id', 'sort', 'flag'],
            'fields' => ['class_name', 'class_num', 'dep_id', 'profession_id', 'grade_id', 'sort', 'flag'],
            'required' => 'class_name',
            'order' => ['sort', 'class_id'],
        ],
        'company' => [
            'table' => 'companies',
            'id' => 'company_id',
            'columns' => ['company_id', 'company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address', 'flag'],
            'fields' => ['company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address', 'flag'],
            'required' => 'company_name',
            'order' => ['company_id'],
        ],
    ];

    public function list(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $type = $this->type($request);
            return $this->ok($this->items($type));
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function save(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $type = $this->type($request);
            $definition = self::DEFINITIONS[$type];
            $id = $this->optionalInt($request, $definition['id']) ?? $this->optionalInt($request, 'id');
            $values = $this->values($request, $definition);
            $now = date('Y-m-d H:i:s');

            if ($id) {
                ChannelTable::queryTable($definition['table'])
                    ->where($definition['id'], $id)
                    ->whereNull('deleted_at')
                    ->update(array_merge($values, ['updated_at' => $now]));
            } else {
                ChannelTable::queryTable($definition['table'])->insert(array_merge($values, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            return $this->ok($this->items($type), '已保存');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function delete(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $type = $this->type($request);
            $definition = self::DEFINITIONS[$type];
            $id = $this->requiredInt($request, 'id');
            $now = date('Y-m-d H:i:s');

            ChannelTable::queryTable($definition['table'])
                ->where($definition['id'], $id)
                ->whereNull('deleted_at')
                ->update([
                    'flag' => 'off',
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);

            return $this->ok($this->items($type), '已删除');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function isAdmin(): bool
    {
        return in_array(CurrentContext::roleType(), self::ADMIN_ROLE_TYPES, true);
    }

    private function type(Request $request): string
    {
        $type = (string) $request->input('type', 'department');
        if (!isset(self::DEFINITIONS[$type])) {
            throw new \InvalidArgumentException('档案类型无效');
        }

        return $type;
    }

    private function items(string $type): array
    {
        $definition = self::DEFINITIONS[$type];
        $query = ChannelTable::queryTable($definition['table'])
            ->whereNull('deleted_at');

        foreach ($definition['order'] as $field) {
            $query->orderBy($field);
        }

        return [
            'type' => $type,
            'id_field' => $definition['id'],
            'items' => $this->rows($query->get($definition['columns'])),
        ];
    }

    private function values(Request $request, array $definition): array
    {
        $required = $this->stringInput($request, $definition['required'], 180);
        if ($required === '') {
            throw new \InvalidArgumentException('名称不能为空');
        }

        $values = [];
        foreach ($definition['fields'] as $field) {
            $values[$field] = match ($field) {
                $definition['required'] => $required,
                'dep_id', 'profession_id', 'grade_id' => $this->optionalInt($request, $field),
                'sort' => $this->optionalInt($request, 'sort') ?? 0,
                'flag' => $this->enum($request, 'flag', ['on', 'off'], 'on'),
                default => $this->nullableString($request, $field, 255),
            };
        }

        return $values;
    }

    private function requiredInt(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException("{$key} 无效");
        }

        return (int) $value;
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private function enum(Request $request, string $key, array $values, string $default): string
    {
        $value = (string) $request->input($key, $default);
        return in_array($value, $values, true) ? $value : $default;
    }

    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        $value = trim((string) $request->input($key, ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function nullableString(Request $request, string $key, int $maxLength): ?string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        return $value === '' ? null : $value;
    }

    private function rows(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = (array) $row;
        }

        return $items;
    }
}
