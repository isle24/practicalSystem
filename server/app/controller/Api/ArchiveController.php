<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\TableRecord as ChannelTable;
use app\server\CurrentContext;
use PhpOffice\PhpSpreadsheet\IOFactory;
use support\Request;
use support\Response;
use Throwable;
use Webman\Http\UploadFile;

class ArchiveController
{
    use Responds;

    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin'];
    private const EXCEL_IMPORT_TYPES = ['profession', 'class'];
    private const EXCEL_EXTENSIONS = ['xls', 'xlsx'];
    private const EXCEL_MAX_SIZE = 10485760;
    private const EXCEL_MAX_ROWS = 5000;
    private const EXCEL_HEADER_ALIASES = [
        'grade_name' => ['届次', '届次名称', '年级', '年级名称', 'grade', 'grade_name'],
        'dep_name' => ['学院', '学院名称', '院系', '院系名称', 'department', 'department_name', 'dep_name'],
        'profession_name' => ['专业', '专业名称', 'profession', 'profession_name', 'major', 'major_name'],
        'class_name' => ['班级', '班级名称', 'class', 'class_name'],
    ];

    private const DEFINITIONS = [
        'department' => [
            'table' => 'department',
            'id' => 'dep_id',
            'columns' => ['dep_id', 'dep_name', 'dep_short_name', 'dep_code', 'sort', 'flag'],
            'fields' => ['dep_name', 'dep_short_name', 'dep_code', 'sort', 'flag'],
            'required' => 'dep_name',
            'order' => ['sort', 'dep_id'],
            'keyword' => ['dep_name', 'dep_short_name', 'dep_code'],
        ],
        'grade' => [
            'table' => 'grade_list',
            'id' => 'grade_id',
            'columns' => ['grade_id', 'grade_name', 'is_current', 'sort', 'flag'],
            'fields' => ['grade_name', 'is_current', 'sort', 'flag'],
            'required' => 'grade_name',
            'order' => ['sort', 'grade_id'],
            'keyword' => ['grade_name'],
        ],
        'profession' => [
            'table' => 'profession',
            'id' => 'profession_id',
            'columns' => ['profession_id', 'profession_name', 'profession_short_name', 'profession_code', 'dep_id', 'grade_id', 'sort', 'flag'],
            'fields' => ['profession_name', 'profession_short_name', 'profession_code', 'dep_id', 'grade_id', 'sort', 'flag'],
            'required' => 'profession_name',
            'order' => ['sort', 'profession_id'],
            'keyword' => ['profession_name', 'profession_short_name', 'profession_code'],
        ],
        'class' => [
            'table' => 'class',
            'id' => 'class_id',
            'columns' => ['class_id', 'class_name', 'class_short_name', 'class_num', 'dep_id', 'profession_id', 'grade_id', 'sort', 'flag'],
            'fields' => ['class_name', 'class_short_name', 'class_num', 'dep_id', 'profession_id', 'grade_id', 'sort', 'flag'],
            'required' => 'class_name',
            'order' => ['sort', 'class_id'],
            'keyword' => ['class_name', 'class_short_name', 'class_num'],
        ],
        'company' => [
            'table' => 'companies',
            'id' => 'company_id',
            'columns' => ['company_id', 'company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address', 'flag'],
            'fields' => ['company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address', 'flag'],
            'required' => 'company_name',
            'order' => ['company_id'],
            'keyword' => ['company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address'],
        ],
    ];

    #[OperationLog('查询基础档案列表')]
    public function list(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $type = $this->type($request);
            return $this->ok($this->items($type, $request));
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    #[OperationLog('保存基础档案')]
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

            ChannelTable::connection()->transaction(function () use ($definition, $id, $now, $type, $values): void {
                if ($type === 'grade' && ($values['is_current'] ?? 'false') === 'true') {
                    ChannelTable::clearCurrentGrade($id, $now);
                }

                if ($id) {
                    ChannelTable::updateArchiveRow($definition['table'], $definition['id'], $id, array_merge($values, ['updated_at' => $now]));
                    return;
                }

                ChannelTable::insertArchiveRow($definition['table'], array_merge($values, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            });

            return $this->ok($this->items($type, $request), '已保存');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    #[OperationLog('删除基础档案')]
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

            ChannelTable::softDeleteArchiveRow($definition['table'], $definition['id'], $id, $now);

            return $this->ok($this->items($type, $request), '已删除');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    #[OperationLog('导入基础档案Excel')]
    public function importExcel(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $type = $this->importType($request);
            $rows = $this->excelRows($this->excelFile($request), $type);
            $summary = ChannelTable::importAcademicArchiveRows($type, $rows, date('Y-m-d H:i:s'));

            return $this->ok(array_merge($summary, $this->items($type, $request)), '导入完成');
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

    private function importType(Request $request): string
    {
        $type = $this->type($request);
        if (!in_array($type, self::EXCEL_IMPORT_TYPES, true)) {
            throw new \InvalidArgumentException('当前档案类型不支持 Excel 导入');
        }

        return $type;
    }

    private function items(string $type, Request $request): array
    {
        $definition = self::DEFINITIONS[$type];
        return array_merge([
            'type' => $type,
            'id_field' => $definition['id'],
        ], ChannelTable::archivePage($definition['table'], $definition['columns'], $definition['order'], [
            'page' => $this->optionalInt($request, 'page') ?? 1,
            'page_size' => $this->optionalInt($request, 'page_size') ?? 20,
            'keyword' => $this->nullableString($request, 'keyword', 180) ?? '',
            'flag' => $this->enum($request, 'filter_flag', ['all', 'on', 'off'], 'all'),
            'keyword_columns' => $definition['keyword'] ?? [$definition['required']],
        ]));
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
                'is_current' => $this->enum($request, 'is_current', ['false', 'true'], 'false'),
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

    private function excelFile(Request $request): UploadFile
    {
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            throw new \InvalidArgumentException('上传文件无效');
        }

        $extension = strtolower($file->getUploadExtension());
        if (!in_array($extension, self::EXCEL_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('仅支持 xls、xlsx 文件');
        }

        if ($file->getSize() > self::EXCEL_MAX_SIZE) {
            throw new \InvalidArgumentException('文件大小不能超过 10MB');
        }

        return $file;
    }

    private function excelRows(UploadFile $file, string $type): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());

        try {
            $sheetRows = $spreadsheet->getActiveSheet()->toArray(null, false, true, true);
            [$mapping, $startRow] = $this->excelHeader($sheetRows, $type);
            $required = $this->excelRequiredFields($type);
            $items = [];

            foreach ($sheetRows as $rowNumber => $row) {
                if ((int) $rowNumber < $startRow) {
                    continue;
                }

                $item = ['row_number' => (int) $rowNumber];
                foreach ($mapping as $key => $column) {
                    $item[$key] = $this->excelCellString($row[$column] ?? '');
                }

                if ($this->excelRowEmpty($item, $required)) {
                    continue;
                }

                $missing = [];
                foreach ($required as $field) {
                    if (($item[$field] ?? '') === '') {
                        $missing[] = $this->excelFieldLabel($field);
                    }
                }
                if ($missing) {
                    $item['invalid_message'] = '缺少' . implode('、', $missing);
                }

                $items[] = $item;
                if (count($items) > self::EXCEL_MAX_ROWS) {
                    throw new \InvalidArgumentException('单次最多导入 5000 行');
                }
            }

            if (!$items) {
                throw new \InvalidArgumentException('Excel 中没有可导入的数据');
            }

            return $items;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function excelHeader(array $sheetRows, string $type): array
    {
        $required = $this->excelRequiredFields($type);
        foreach (array_slice($sheetRows, 0, 5, true) as $rowNumber => $row) {
            $mapping = [];
            foreach ($row as $column => $value) {
                $key = $this->excelHeaderKey($value);
                if ($key && !isset($mapping[$key])) {
                    $mapping[$key] = $column;
                }
            }

            if (count(array_intersect($required, array_keys($mapping))) === count($required)) {
                return [$mapping, (int) $rowNumber + 1];
            }
        }

        return [[
            'grade_name' => 'A',
            'dep_name' => 'B',
            'profession_name' => 'C',
            'class_name' => 'D',
        ], 1];
    }

    private function excelHeaderKey(mixed $value): ?string
    {
        $header = $this->normalizeExcelText((string) $value);
        if ($header === '') {
            return null;
        }

        foreach (self::EXCEL_HEADER_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($header === $this->normalizeExcelText($alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function excelRequiredFields(string $type): array
    {
        return $type === 'class'
            ? ['grade_name', 'dep_name', 'profession_name', 'class_name']
            : ['grade_name', 'dep_name', 'profession_name'];
    }

    private function excelRowEmpty(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($row[$field] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    private function excelFieldLabel(string $field): string
    {
        return match ($field) {
            'grade_name' => '届次',
            'dep_name' => '学院',
            'profession_name' => '专业',
            'class_name' => '班级',
            default => '字段',
        };
    }

    private function excelCellString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        } elseif (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        } else {
            $value = (string) $value;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 180);
        }

        return substr($value, 0, 180);
    }

    private function normalizeExcelText(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return (string) preg_replace('/[\s　_\-:：()（）]+/u', '', $value);
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
