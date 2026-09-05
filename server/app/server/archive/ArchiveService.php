<?php

namespace app\server\archive;

use app\model\channel\AcademicArchiveRecord;
use app\model\channel\TableRecord;
use app\server\CurrentContext;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use support\Request;
use Webman\Http\UploadFile;

class ArchiveService
{
    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin'];
    private const EXCEL_IMPORT_TYPES = ['profession', 'class'];
    private const EXCEL_EXTENSIONS = ['xls', 'xlsx'];
    private const EXCEL_MAX_SIZE = 10485760;
    private const EXCEL_MAX_ROWS = 5000;
    private const EXCEL_HEADER_ALIASES = [
        'grade_name' => ['年级', '年级名称', 'grade', 'grade_name'],
        'dep_name' => ['学院', '学院名称', '院系', '院系名称', 'department', 'department_name', 'dep_name'],
        'profession_name' => ['专业', '专业名称', 'profession', 'profession_name', 'major', 'major_name'],
        'class_name' => ['班级', '班级名称', 'class', 'class_name'],
    ];
    private const DEFINITIONS = [
        'department' => [
            'type' => 'department', 'table' => 'department', 'id' => 'dep_id', 'uuid' => 'dep_uuid',
            'columns' => ['dep_id', 'dep_name', 'dep_short_name', 'dep_code', 'sort', 'flag'],
            'fields' => ['dep_name', 'dep_short_name', 'dep_code', 'sort', 'flag'],
            'required' => 'dep_name', 'order' => ['sort', 'dep_id'],
            'keyword' => ['dep_name', 'dep_short_name', 'dep_code'],
            'flag_field' => 'flag', 'disabled_value' => 'off',
        ],
        'grade' => [
            'type' => 'grade', 'table' => 'grade_list', 'id' => 'grade_id', 'uuid' => 'grade_uuid',
            'columns' => ['grade_id', 'grade_code', 'grade_name', 'is_current', 'sort', 'flag'],
            'fields' => ['grade_code', 'grade_name', 'is_current', 'sort', 'flag'],
            'required' => 'grade_name', 'order' => ['sort', 'grade_id'], 'keyword' => ['grade_code', 'grade_name'],
            'flag_field' => 'flag', 'disabled_value' => 'off',
        ],
        'graduation_cohort' => [
            'type' => 'graduation_cohort', 'table' => 'graduation_cohort', 'id' => 'cohort_id', 'uuid' => 'cohort_uuid',
            'columns' => ['cohort_id', 'cohort_name', 'cohort_year', 'is_current', 'sort', 'flag'],
            'fields' => ['cohort_name', 'cohort_year', 'is_current', 'sort', 'flag'],
            'required' => 'cohort_name', 'order' => ['sort', 'cohort_id'], 'keyword' => ['cohort_name', 'cohort_year'],
            'flag_field' => 'flag', 'disabled_value' => 'off',
        ],
        'internship_category' => [
            'type' => 'internship_category', 'table' => 'internship_category', 'id' => 'id', 'uuid' => 'uuid',
            'columns' => ['id', 'name', 'code', 'scope_type', 'sort', 'status'],
            'fields' => ['name', 'code', 'scope_type', 'sort', 'status'],
            'required' => 'name', 'order' => ['sort', 'id'], 'keyword' => ['name', 'code'],
            'flag_field' => 'status', 'disabled_value' => 'disabled',
            'flag_values' => ['on' => 'enabled', 'off' => 'disabled'],
        ],
        'profession' => [
            'type' => 'profession', 'table' => 'profession', 'id' => 'profession_id', 'uuid' => 'profession_uuid',
            'columns' => ['profession_id', 'profession_name', 'profession_short_name', 'profession_code', 'dep_id', 'grade_id', 'sort', 'flag'],
            'fields' => ['profession_name', 'profession_short_name', 'profession_code', 'dep_id', 'grade_id', 'sort', 'flag'],
            'required' => 'profession_name', 'order' => ['sort', 'profession_id'],
            'keyword' => ['profession_name', 'profession_short_name', 'profession_code'],
            'flag_field' => 'flag', 'disabled_value' => 'off',
        ],
        'class' => [
            'type' => 'class', 'table' => 'class', 'id' => 'class_id', 'uuid' => 'class_uuid',
            'columns' => ['class_id', 'class_name', 'class_short_name', 'class_num', 'dep_id', 'profession_id', 'grade_id', 'sort', 'flag'],
            'fields' => ['class_name', 'class_short_name', 'class_num', 'dep_id', 'profession_id', 'grade_id', 'sort', 'flag'],
            'required' => 'class_name', 'order' => ['sort', 'class_id'],
            'keyword' => ['class_name', 'class_short_name', 'class_num'],
            'flag_field' => 'flag', 'disabled_value' => 'off',
        ],
        'company' => [
            'type' => 'company', 'table' => 'companies', 'id' => 'company_id', 'uuid' => 'company_uuid',
            'columns' => ['company_id', 'company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address', 'unit_type', 'enterprise_level', 'flag'],
            'fields' => ['company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address', 'unit_type', 'enterprise_level', 'flag'],
            'required' => 'company_name', 'order' => ['company_id'],
            'keyword' => ['company_name', 'credit_code', 'contact_name', 'contact_mobile', 'address'],
            'flag_field' => 'flag', 'disabled_value' => 'off',
        ],
    ];

    /** 查询基础档案列表。 */
    public function list(Request $request): array
    {
        $this->assertAdmin();
        $type = $this->type($request);
        return $this->items($type, $request);
    }

    /** 保存基础档案。 */
    public function save(Request $request): array
    {
        $this->assertAdmin();
        $type = $this->type($request);
        $definition = self::DEFINITIONS[$type];
        $id = $this->optionalInt($request, $definition['id']) ?? $this->optionalInt($request, 'id');
        AcademicArchiveRecord::saveArchive($definition, $id, $this->values($request, $definition), date('Y-m-d H:i:s'));
        return $this->items($type, $request);
    }

    /** 软删除基础档案。 */
    public function delete(Request $request): array
    {
        $this->assertAdmin();
        $type = $this->type($request);
        $definition = self::DEFINITIONS[$type];
        AcademicArchiveRecord::softDelete($definition, $this->requiredInt($request, 'id'), date('Y-m-d H:i:s'));
        return $this->items($type, $request);
    }

    /** 导入专业或班级基础档案。 */
    public function importExcel(Request $request): array
    {
        $this->assertAdmin();
        $type = $this->importType($request);
        $rows = $this->excelRows($this->excelFile($request), $type);
        $summary = TableRecord::importAcademicArchiveRows($type, $rows, date('Y-m-d H:i:s'));
        return array_merge($summary, $this->items($type, $request));
    }

    /** 校验学校档案管理角色。 */
    private function assertAdmin(): void
    {
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLE_TYPES, true)) {
            throw new InvalidArgumentException('无操作权限', 403);
        }
    }

    /** 解析档案类型。 */
    private function type(Request $request): string
    {
        $type = (string) $request->input('type', 'department');
        if (!isset(self::DEFINITIONS[$type])) {
            throw new InvalidArgumentException('档案类型无效');
        }
        return $type;
    }

    /** 解析允许导入的档案类型。 */
    private function importType(Request $request): string
    {
        $type = $this->type($request);
        if (!in_array($type, self::EXCEL_IMPORT_TYPES, true)) {
            throw new InvalidArgumentException('当前档案类型不支持 Excel 导入');
        }
        return $type;
    }

    /** 查询档案分页数据。 */
    private function items(string $type, Request $request): array
    {
        $definition = self::DEFINITIONS[$type];
        return array_merge([
            'type' => $type,
            'id_field' => $definition['id'],
        ], AcademicArchiveRecord::page($definition, [
            'page' => $this->optionalInt($request, 'page') ?? 1,
            'page_size' => $this->optionalInt($request, 'page_size') ?? 20,
            'keyword' => $this->nullableString($request, 'keyword', 180) ?? '',
            'flag' => $this->enum($request, 'filter_flag', ['all', 'on', 'off'], 'all'),
        ]));
    }

    /** 读取并校验档案表单字段。 */
    private function values(Request $request, array $definition): array
    {
        $required = $this->stringInput($request, $definition['required'], 180);
        if ($required === '') {
            throw new InvalidArgumentException('名称不能为空');
        }

        $values = [];
        foreach ($definition['fields'] as $field) {
            $values[$field] = match ($field) {
                $definition['required'] => $required,
                'dep_id', 'profession_id', 'grade_id' => $this->optionalInt($request, $field),
                'cohort_year' => $this->cohortYear($request),
                'is_current' => $this->enum($request, 'is_current', ['false', 'true'], 'false'),
                'scope_type' => $this->enum($request, 'scope_type', ['grade', 'cohort'], 'grade'),
                'sort' => $this->optionalInt($request, 'sort') ?? 0,
                'flag' => $this->enum($request, 'flag', ['on', 'off'], 'on'),
                'status' => $this->enum($request, 'status', ['enabled', 'disabled'], 'enabled'),
                'code' => $this->requiredCode($request),
                default => $this->nullableString($request, $field, 255),
            };
        }
        return $values;
    }

    /** 读取毕业届次年份。 */
    private function cohortYear(Request $request): int
    {
        $year = $this->requiredInt($request, 'cohort_year');
        if ($year < 2000 || $year > 2999) {
            throw new InvalidArgumentException('毕业届次年份无效');
        }
        return $year;
    }

    /** 读取实习类别代码。 */
    private function requiredCode(Request $request): string
    {
        $code = $this->stringInput($request, 'code', 80);
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            throw new InvalidArgumentException('类别代码仅支持小写字母、数字和下划线');
        }
        return $code;
    }

    /** 校验上传文件。 */
    private function excelFile(Request $request): UploadFile
    {
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            throw new InvalidArgumentException('上传文件无效');
        }
        if (!in_array(strtolower($file->getUploadExtension()), self::EXCEL_EXTENSIONS, true)) {
            throw new InvalidArgumentException('仅支持 xls、xlsx 文件');
        }
        if ($file->getSize() > self::EXCEL_MAX_SIZE) {
            throw new InvalidArgumentException('文件大小不能超过 10MB');
        }
        return $file;
    }

    /** 解析专业或班级 Excel。 */
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
                    throw new InvalidArgumentException('单次最多导入 5000 行');
                }
            }
            if (!$items) {
                throw new InvalidArgumentException('Excel 中没有可导入的数据');
            }
            return $items;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** 定位 Excel 表头。 */
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
            'grade_name' => 'A', 'dep_name' => 'B', 'profession_name' => 'C', 'class_name' => 'D',
        ], 1];
    }

    /** 将 Excel 表头转换为字段名。 */
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

    /** 返回导入必填字段。 */
    private function excelRequiredFields(string $type): array
    {
        return $type === 'class'
            ? ['grade_name', 'dep_name', 'profession_name', 'class_name']
            : ['grade_name', 'dep_name', 'profession_name'];
    }

    /** 判断导入行是否为空。 */
    private function excelRowEmpty(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($row[$field] ?? '') !== '') {
                return false;
            }
        }
        return true;
    }

    /** 返回导入字段中文名称。 */
    private function excelFieldLabel(string $field): string
    {
        return match ($field) {
            'grade_name' => '年级', 'dep_name' => '学院', 'profession_name' => '专业', 'class_name' => '班级', default => '字段',
        };
    }

    /** 格式化 Excel 单元格。 */
    private function excelCellString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        } elseif (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, 180) : substr($value, 0, 180);
    }

    /** 规范化 Excel 表头文本。 */
    private function normalizeExcelText(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return (string) preg_replace('/[\s　_\-:：()（）]+/u', '', $value);
    }

    /** 读取必填正整数。 */
    private function requiredInt(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$key} 无效");
        }
        return (int) $value;
    }

    /** 读取可选整数。 */
    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : null;
    }

    /** 读取枚举值。 */
    private function enum(Request $request, string $key, array $values, string $default): string
    {
        $value = (string) $request->input($key, $default);
        return in_array($value, $values, true) ? $value : $default;
    }

    /** 读取限制长度字符串。 */
    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        $value = trim((string) $request->input($key, ''));
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    /** 读取可空字符串。 */
    private function nullableString(Request $request, string $key, int $maxLength): ?string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        return $value === '' ? null : $value;
    }
}
