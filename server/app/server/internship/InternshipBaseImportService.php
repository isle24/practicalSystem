<?php

namespace app\server\internship;

use app\model\channel\InternshipBaseImportRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\file\FileService;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use support\Request;
use Webman\Http\UploadFile;

class InternshipBaseImportService
{
    private const EXCEL_EXTENSIONS = ['xls', 'xlsx'];
    private const EXCEL_MAX_SIZE = 10485760;
    private const EXCEL_MAX_ROWS = 1000;
    private const REQUIRED_FIELDS = ['base_name', 'dep_name', 'declaration_year', 'profession_text', 'company_name'];
    private const BUDGET_FIELDS = [
        'budget_infrastructure' => '实习基地基础建设',
        'budget_enterprise_mentor' => '企业导师队伍建设',
        'budget_school_teacher' => '校内教师实践计划',
        'budget_course_development' => '校企联合课程开发',
        'budget_competition' => '教赛结合竞赛提升',
        'budget_evaluation' => '实习基地评估激励',
        'budget_material' => '实践教学资料完善及成果打造',
    ];
    private const HEADERS = [
        'base_name' => ['基地名称'],
        'base_category' => ['基地类别'],
        'dep_name' => ['依托学院', '学院'],
        'base_level' => ['基地类型', '基地等级'],
        'declaration_year' => ['申报年份'],
        'budget_infrastructure' => ['经费预算-实习基地基础建设'],
        'budget_enterprise_mentor' => ['经费预算-企业导师队伍建设'],
        'budget_school_teacher' => ['经费预算-校内教师实践计划'],
        'budget_course_development' => ['经费预算-校企联合课程开发'],
        'budget_competition' => ['经费预算-教赛结合竞赛提升'],
        'budget_evaluation' => ['经费预算-实习基地评估激励'],
        'budget_material' => ['经费预算-实践教学资料完善及成果打造'],
        'approved_amount' => ['立项经费'],
        'project_status' => ['是否立项'],
        'industry_cobuilt' => ['是否与行业企业共建'],
        'service_profession_count' => ['服务校内专业个数'],
        'profession_text' => ['服务校内专业'],
        'service_courses' => ['基地对应课程'],
        'curriculum_in_plan' => ['课程是否列入培养方案'],
        'manager_name' => ['基地负责人'],
        'manager_phone' => ['联系电话'],
        'company_name' => ['合作或依托单位'],
        'unit_type' => ['单位类型'],
        'enterprise_level' => ['是否为世界500强/中国100强'],
        'district' => ['基地所在市区（县）', '基地所在市区县'],
        'address' => ['基地地址'],
        'company_contact_name' => ['合作方联系人'],
        'company_contact_phone' => ['联系电话.1', '合作方联系电话'],
        'teacher_count' => ['基地指导教师总数'],
        'external_teacher_count' => ['其中校外教师数'],
        'planned_content' => ['计划实习内容'],
        'expected_student_visits' => ['年预计接纳学生人次数'],
        'expected_student_days' => ['年接纳学生生天数'],
        'reception_2023' => ['2023年接纳人数'],
        'reception_2024' => ['2024年接纳人数'],
        'reception_2025' => ['2025年接纳人数'],
        'has_signboard' => ['是否挂牌'],
        'has_agreement' => ['是否签订协议'],
        'remark' => ['备注'],
        'source_edited_at' => ['最后编辑时间'],
        'source_editor' => ['最后编辑人'],
        'source_admin' => ['管理员'],
        'source_number' => ['数字'],
    ];

    /** 生成并返回实习基地汇总表导入模板。 */
    public function template(): array
    {
        $directory = rtrim(public_path(), DIRECTORY_SEPARATOR) . '/templates/internship';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('模板目录创建失败');
        }
        $fileName = 'internship-base-import-template.xlsx';
        $path = $directory . '/' . $fileName;
        if (!is_file($path)) {
            $temporaryPath = tempnam($directory, 'base-template-');
            if ($temporaryPath === false) {
                throw new RuntimeException('模板临时文件创建失败');
            }
            try {
                $this->writeTemplate($temporaryPath);
                if (!chmod($temporaryPath, 0644) || !rename($temporaryPath, $path)) {
                    throw new RuntimeException('模板文件生成失败');
                }
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        }

        return [
            'url' => '/templates/internship/' . $fileName,
            'download_name' => '实习基地导入模板.xlsx',
            'version' => '2026.09',
        ];
    }

    /** 解析基地 Excel 并保存导入文件 */
    public function preview(Request $request, array $scope): array
    {
        $file = $this->excelFile($request);
        $items = $this->resolvedRows($this->readRows($file->getPathname()), $scope);
        $fileInfo = (new FileService())->upload($request, [
            'allowed_extensions' => self::EXCEL_EXTENSIONS,
            'max_size' => self::EXCEL_MAX_SIZE,
            'require_md5' => false,
            'category' => 'internship_base_import',
            'is_temporary' => false,
        ]);

        return $this->previewResult($items, $fileInfo);
    }

    /** 重新读取已保存文件并事务导入基地资料 */
    public function confirm(Request $request, array $scope, int $accountId): array
    {
        $fileId = $this->positiveInt($request->input('import_file_id'));
        if ($fileId <= 0) {
            throw new InvalidArgumentException('import_file_id 无效');
        }
        $file = (new FileService())->info($fileId);
        if (($file['category'] ?? '') !== 'internship_base_import') {
            throw new InvalidArgumentException('导入文件类型无效');
        }
        if ((int) ($file['uploader_id'] ?? 0) !== $accountId) {
            throw new RuntimeException('无权使用该导入文件', 40301);
        }

        $items = $this->resolvedRows($this->readRows($this->savedFilePath($file)), $scope);
        $errors = array_filter($items, static fn (array $item): bool => !empty($item['errors']));
        $skipErrors = filter_var($request->input('skip_errors', false), FILTER_VALIDATE_BOOL);
        if ($errors && !$skipErrors) {
            throw new InvalidArgumentException('存在错误行，请选择跳过错误行后继续导入');
        }

        $lockKey = implode(':', [
            'workflow_lock',
            CurrentContext::schoolDatabaseId() ?: 'school',
            'internship',
            'base_import',
        ]);

        return (new WorkflowLock())->run(
            $lockKey,
            fn (): array => array_merge(
                InternshipBaseImportRecord::importRows(array_values(array_filter($items, static fn (array $item): bool => empty($item['errors']))), $accountId, date('Y-m-d H:i:s')),
                $errors && $skipErrors ? ['skipped_errors' => count($errors)] : []
            ),
            60
        );
    }

    /** 读取本地工作簿并创建当前测试库缺失基础档案 */
    public function prepareAcademicScopes(string $path): array
    {
        $rows = $this->readRows($path);
        return InternshipBaseImportRecord::createMissingAcademicScopes($rows, date('Y-m-d H:i:s'));
    }

    /** 读取本地工作簿并通过正式规则导入当前测试数据 */
    public function importLocalWorkbook(string $path, array $scope, int $accountId): array
    {
        $items = $this->resolvedRows($this->readRows($path), $scope);
        $errors = array_filter($items, static fn (array $item): bool => !empty($item['errors']));
        if ($errors) {
            throw new InvalidArgumentException('本地工作簿仍有 ' . count($errors) . ' 行错误');
        }

        return InternshipBaseImportRecord::importRows($items, $accountId, date('Y-m-d H:i:s'));
    }

    /** 关联导入行的数据范围并标记重复资料 */
    private function resolvedRows(array $rows, array $scope): array
    {
        $rows = InternshipBaseImportRecord::resolveAcademicScopes($rows, $scope);
        foreach ($rows as &$row) {
            $row['duplicate'] = empty($row['errors']) ? InternshipBaseImportRecord::duplicate($row) : null;
            $row['can_import'] = empty($row['errors']);
        }
        unset($row);

        return $rows;
    }

    /** 汇总导入预览结果 */
    private function previewResult(array $items, array $fileInfo): array
    {
        $errorCount = count(array_filter($items, static fn (array $item): bool => !empty($item['errors'])));
        $warningCount = count(array_filter($items, static fn (array $item): bool => !empty($item['warnings'])));
        $duplicateCount = count(array_filter($items, static fn (array $item): bool => !empty($item['duplicate']['declaration_exists'])));

        return [
            'file' => $fileInfo,
            'items' => $items,
            'can_confirm' => $errorCount === 0,
            'summary' => [
                'source_rows' => count($items),
                'error_rows' => $errorCount,
                'valid_rows' => count($items) - $errorCount,
                'warning_rows' => $warningCount,
                'duplicate_rows' => $duplicateCount,
            ],
        ];
    }

    /** 读取工作簿中的有效数据行 */
    private function readRows(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Excel 文件不存在');
        }
        $spreadsheet = IOFactory::load($path);
        try {
            $sheetRows = $spreadsheet->getActiveSheet()->toArray(null, false, true, true);
            [$mapping, $startRow] = $this->headerMapping($sheetRows);
            $rawItems = [];
            foreach ($sheetRows as $rowNumber => $sheetRow) {
                if ((int) $rowNumber < $startRow) {
                    continue;
                }
                $raw = ['row_number' => (int) $rowNumber];
                foreach ($mapping as $field => $column) {
                    $raw[$field] = $this->cellText($sheetRow[$column] ?? null);
                }
                if ($this->rowEmpty($raw)) {
                    continue;
                }
                $rawItems[] = $raw;
                if (count($rawItems) > self::EXCEL_MAX_ROWS) {
                    throw new InvalidArgumentException('单次最多读取 1000 行基地资料');
                }
            }
            if (!$rawItems) {
                throw new InvalidArgumentException('Excel 中没有可导入的基地资料');
            }

            return array_map(
                fn (array $raw): array => $this->prepareRow($raw),
                $this->inheritBaseFields($rawItems)
            );
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** 规范化并校验单行基地资料 */
    private function prepareRow(array $raw): array
    {
        $errors = [];
        $warnings = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (trim((string) ($raw[$field] ?? '')) === '') {
                $errors[] = $this->fieldLabel($field) . '不能为空';
            }
        }
        $year = $this->nullableInt($raw['declaration_year'] ?? null);
        if ($year === null || $year < 2000 || $year > 2100) {
            $errors[] = '申报年份格式无效';
        }
        $professionNames = $this->professionNames((string) ($raw['profession_text'] ?? ''));
        if (!$professionNames) {
            $errors[] = '服务校内专业无法识别';
        }

        $numericFields = [
            'service_profession_count', 'teacher_count', 'external_teacher_count',
            'expected_student_visits', 'expected_student_days',
            'reception_2023', 'reception_2024', 'reception_2025',
        ];
        $numbers = [];
        foreach ($numericFields as $field) {
            $numbers[$field] = $this->nullableInt($raw[$field] ?? null);
            if (trim((string) ($raw[$field] ?? '')) !== '' && $numbers[$field] === null) {
                $warnings[] = $this->fieldLabel($field) . '不是纯数字，数值字段留空';
            }
        }
        $budgets = [];
        foreach (self::BUDGET_FIELDS as $field => $label) {
            $amount = $this->nullableDecimal($raw[$field] ?? null);
            if (trim((string) ($raw[$field] ?? '')) !== '' && $amount === null) {
                $warnings[] = $label . '金额格式无效，未写入该预算';
            }
            $budgets[] = ['item_name' => $label, 'amount' => $amount];
        }
        $approvedAmount = $this->nullableDecimal($raw['approved_amount'] ?? null);
        if (trim((string) ($raw['approved_amount'] ?? '')) !== '' && $approvedAmount === null) {
            $warnings[] = '立项经费格式无效，数值字段留空';
        }
        $sourceEditedAt = $this->dateTime((string) ($raw['source_edited_at'] ?? ''));
        if (trim((string) ($raw['source_edited_at'] ?? '')) !== '' && $sourceEditedAt === null) {
            $warnings[] = '最后编辑时间格式无法识别';
        }

        return [
            'row_number' => (int) ($raw['row_number'] ?? 0),
            'base_name' => $this->text($raw['base_name'] ?? null, 180),
            'base_category' => $this->text($raw['base_category'] ?? null, 120),
            'dep_name' => $this->text($raw['dep_name'] ?? null, 120),
            'base_level' => $this->text($raw['base_level'] ?? null, 80),
            'declaration_year' => $year ?? 0,
            'approved_amount' => $approvedAmount,
            'project_status' => $this->text($raw['project_status'] ?? null, 20),
            'industry_cobuilt' => $this->text($raw['industry_cobuilt'] ?? null, 20),
            'service_profession_count' => $numbers['service_profession_count'],
            'profession_text' => $this->text($raw['profession_text'] ?? null, 10000),
            'profession_names' => $professionNames,
            'service_courses' => $this->text($raw['service_courses'] ?? null, 10000),
            'curriculum_in_plan' => $this->text($raw['curriculum_in_plan'] ?? null, 20),
            'manager_name' => $this->text($raw['manager_name'] ?? null, 80),
            'manager_phone' => $this->text($raw['manager_phone'] ?? null, 40),
            'company_name' => $this->text($raw['company_name'] ?? null, 180),
            'unit_type' => $this->text($raw['unit_type'] ?? null, 80),
            'enterprise_level' => $this->text($raw['enterprise_level'] ?? null, 120),
            'district' => $this->text($raw['district'] ?? null, 120),
            'address' => $this->text($raw['address'] ?? null, 255),
            'company_contact_name' => $this->text($raw['company_contact_name'] ?? null, 80),
            'company_contact_phone' => $this->text($raw['company_contact_phone'] ?? null, 40),
            'teacher_count' => $numbers['teacher_count'],
            'external_teacher_count' => $numbers['external_teacher_count'],
            'planned_content' => $this->text($raw['planned_content'] ?? null, 10000),
            'expected_student_visits' => $numbers['expected_student_visits'],
            'expected_student_days' => $numbers['expected_student_days'],
            'has_signboard' => $this->text($raw['has_signboard'] ?? null, 20),
            'has_agreement' => $this->text($raw['has_agreement'] ?? null, 20),
            'remark' => $this->text($raw['remark'] ?? null, 10000),
            'source_edited_at' => $sourceEditedAt,
            'source_editor' => $this->text($raw['source_editor'] ?? null, 120),
            'source_admin' => $this->text($raw['source_admin'] ?? null, 120),
            'budgets' => $budgets,
            'reception_stats' => [
                2023 => $numbers['reception_2023'],
                2024 => $numbers['reception_2024'],
                2025 => $numbers['reception_2025'],
            ],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** 为同一基地的合并行补齐稳定字段 */
    private function inheritBaseFields(array $rows): array
    {
        $stableFields = ['profession_text', 'company_name'];
        $groupValues = [];
        foreach ($rows as $row) {
            $key = $this->normalizedText($row['base_name'] ?? '') . '|' . $this->normalizedText($row['dep_name'] ?? '');
            if ($key === '|') {
                continue;
            }
            foreach ($stableFields as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                if ($value !== '' && !isset($groupValues[$key][$field])) {
                    $groupValues[$key][$field] = $value;
                }
            }
        }
        foreach ($rows as &$row) {
            $key = $this->normalizedText($row['base_name'] ?? '') . '|' . $this->normalizedText($row['dep_name'] ?? '');
            foreach ($stableFields as $field) {
                if (trim((string) ($row[$field] ?? '')) === '' && isset($groupValues[$key][$field])) {
                    $row[$field] = $groupValues[$key][$field];
                }
            }
        }
        unset($row);

        return $rows;
    }

    /** 识别模板表头及数据起始行 */
    private function headerMapping(array $sheetRows): array
    {
        foreach (array_slice($sheetRows, 0, 10, true) as $rowNumber => $row) {
            $mapping = [];
            foreach ($row as $column => $value) {
                $field = $this->headerField($value);
                if ($field && !isset($mapping[$field])) {
                    $mapping[$field] = $column;
                }
            }
            if (count(array_intersect(array_keys(self::HEADERS), array_keys($mapping))) === count(self::HEADERS)) {
                return [$mapping, (int) $rowNumber + 1];
            }
        }

        throw new InvalidArgumentException('未识别到 43 列实习基地汇总表表头');
    }

    /** 将表头名称映射为字段名 */
    private function headerField(mixed $value): ?string
    {
        $header = $this->normalizedText($value);
        foreach (self::HEADERS as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($header === $this->normalizedText($alias)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** 拆分服务专业名称 */
    private function professionNames(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        if (preg_match('/^([^（）()]+)[（(]([^（）()]+[，,、][^（）()]*)[）)]$/u', $value, $matches)) {
            $parts = array_merge([$matches[1]], $this->splitOutsideParentheses($matches[2]));
        } else {
            $parts = $this->splitOutsideParentheses($value);
        }
        $names = [];
        foreach ($parts as $part) {
            $part = trim((string) preg_replace('/专业$/u', '', trim($part)));
            if ($part !== '') {
                $names[] = $part;
            }
        }

        return array_values(array_unique($names));
    }

    /** 按括号外分隔符拆分文本 */
    private function splitOutsideParentheses(string $value): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (in_array($char, ['（', '('], true)) {
                $depth++;
            } elseif (in_array($char, ['）', ')'], true)) {
                $depth = max(0, $depth - 1);
            }
            if ($depth === 0 && in_array($char, ['，', ',', '、', ';', '；', '/'], true)) {
                if (trim($buffer) !== '') {
                    $parts[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }
        if (count($parts) === 1 && preg_match('/\s+/u', $parts[0])) {
            $spaceParts = preg_split('/\s+/u', $parts[0]) ?: [];
            if (count($spaceParts) > 1) {
                $parts = $spaceParts;
            }
        }

        return $parts;
    }

    /** 获取并校验上传的 Excel 文件 */
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

    /** 解析已保存导入文件的绝对路径 */
    private function savedFilePath(array $file): string
    {
        $relativePath = ltrim(str_replace('\\', '/', (string) ($file['blob']['path'] ?? '')), '/');
        if ($relativePath === '' || preg_match('#(^|/)\.\.(?:/|$)#', $relativePath)) {
            throw new RuntimeException('导入文件路径无效');
        }

        $root = realpath(rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'files');
        $path = realpath(rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('导入文件已丢失，请重新上传');
        }

        return $path;
    }

    /** 写入基地导入模板及字段说明。 */
    private function writeTemplate(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('基地导入');
            $headers = array_map(static fn (array $aliases): string => $aliases[0], self::HEADERS);
            $headers['company_contact_phone'] = '合作方联系电话';
            $headers['base_level'] = '基地等级';
            $sheet->fromArray(array_values($headers), null, 'A1');
            $sheet->freezePane('A2');
            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->setAutoFilter('A1:' . $lastColumn . '1');
            $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            foreach (array_keys($headers) as $index => $field) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getColumnDimension($column)->setWidth(24);
                if (in_array($field, self::REQUIRED_FIELDS, true)) {
                    $sheet->getComment($column . '1')->getText()->createTextRun('必填；填写要求见字段说明页。');
                }
            }
            $sheet->getRowDimension(1)->setRowHeight(58);

            $guide = $spreadsheet->createSheet();
            $guide->setTitle('字段说明');
            $guideRows = [
                ['字段名', '是否必填', '填写说明'],
                ['导入范围', '-', '本模板用于长期基地及年度申报；临时基地通过新增基地录入。'],
                ['填写方式', '-', '在基地导入页第2行开始填写，保留全部43列表头；不需要填写的字段留空。最多1000行、10MB。'],
                ['校验规则', '-', '学院、专业必须已存在且在当前账号权限范围内；存在错误时整批禁止导入。'],
                ['重复处理', '-', '已有基地复用，已有年度申报跳过，不覆盖原申报数据。'],
            ];
            $instructions = [
                'base_name' => '填写长期基地完整名称。',
                'dep_name' => '填写系统中已启用的学院完整名称。',
                'declaration_year' => '填写2000至2100之间的四位年份，例如2026。',
                'profession_text' => '填写所选学院已有的专业名称；多个专业用逗号、顿号或分号分隔。',
                'company_name' => '填写企业、单位或基地依托主体的完整名称。',
                'base_level' => '填写基地等级，例如核心基地、特色基地，不是长期/临时基地。',
                'manager_phone' => '填写基地负责人电话；建议将单元格设为文本以保留前导零。',
                'company_contact_phone' => '填写合作方联系电话，与基地负责人联系电话区分。',
                'source_edited_at' => '填写可识别的日期时间，例如2026-09-22 09:00:00。',
                'source_number' => '原汇总表兼容列，可留空，不作为业务字段写入。',
            ];
            $numericFields = ['service_profession_count', 'teacher_count', 'external_teacher_count', 'expected_student_visits', 'expected_student_days', 'reception_2023', 'reception_2024', 'reception_2025'];
            foreach ($headers as $field => $label) {
                $instruction = $instructions[$field] ?? '按实际情况填写，可留空。';
                if (isset(self::BUDGET_FIELDS[$field]) || $field === 'approved_amount') {
                    $instruction = '填写金额，最多保留两位小数；无效金额会警告并留空。';
                } elseif (in_array($field, $numericFields, true)) {
                    $instruction = '填写非负整数，不带人数、天数等单位；无效数值会警告并留空。';
                }
                $guideRows[] = [$label, in_array($field, self::REQUIRED_FIELDS, true) ? '是' : '否', $instruction];
            }
            $guide->fromArray($guideRows, null, 'A1');
            $guide->getStyle('A1:C1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $guide->getColumnDimension('A')->setWidth(28);
            $guide->getColumnDimension('B')->setWidth(12);
            $guide->getColumnDimension('C')->setWidth(90);
            $guide->getStyle('A1:C' . $guide->getHighestRow())->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $guide->getDefaultRowDimension()->setRowHeight(36);
            $guide->freezePane('A2');
            $spreadsheet->setActiveSheetIndex(0);

            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** 判断模板行是否为空 */
    private function rowEmpty(array $row): bool
    {
        foreach (array_keys(self::HEADERS) as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    /** 解析可空金额 */
    private function nullableDecimal(mixed $value): ?float
    {
        $value = preg_replace('/[￥¥,，\s]/u', '', trim((string) ($value ?? '')));
        return $value !== '' && is_numeric($value) ? round((float) $value, 2) : null;
    }

    /** 解析可空非负整数 */
    private function nullableInt(mixed $value): ?int
    {
        $number = $this->nullableDecimal($value);
        return $number !== null && $number >= 0 && floor($number) === $number ? (int) $number : null;
    }

    /** 解析可空日期时间 */
    private function dateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u', $value, $matches)) {
            return sprintf('%04d-%02d-%02d 00:00:00', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** 返回导入字段显示名称 */
    private function fieldLabel(string $field): string
    {
        return [
            'base_name' => '基地名称',
            'dep_name' => '依托学院',
            'declaration_year' => '申报年份',
            'profession_text' => '服务校内专业',
            'company_name' => '合作或依托单位',
            'service_profession_count' => '服务校内专业个数',
            'teacher_count' => '基地指导教师总数',
            'external_teacher_count' => '其中校外教师数',
            'expected_student_visits' => '年预计接纳学生人次数',
            'expected_student_days' => '年接纳学生生天数',
            'reception_2023' => '2023年接纳人数',
            'reception_2024' => '2024年接纳人数',
            'reception_2025' => '2025年接纳人数',
        ][$field] ?? $field;
    }

    /** 将单元格值转换为文本 */
    private function cellText(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        return $this->text($value, 10000);
    }

    /** 截取并清理文本 */
    private function text(mixed $value, int $maxLength): string
    {
        $value = trim((string) ($value ?? ''));
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    /** 生成宽松文本匹配键 */
    private function normalizedText(mixed $value): string
    {
        $value = trim((string) $value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return (string) preg_replace('/[\s　_\-:：()（）]+/u', '', $value);
    }

    /** 转换正整数参数 */
    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
