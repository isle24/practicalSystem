<?php

namespace app\server\internship;

use app\model\channel\InternshipRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\file\FileService;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use support\Request;
use Throwable;
use Webman\Http\UploadFile;

class InternshipPlanImportService
{
    private const EXCEL_EXTENSIONS = ['xls', 'xlsx'];
    private const EXCEL_MAX_SIZE = 10485760;
    private const EXCEL_MAX_ROWS = 5000;
    private const BUSINESS_TYPES = ['internship', 'training', 'lab', 'social_practice', 'ignore'];
    private const REQUIRED_FIELDS = ['course_name', 'dep_name', 'grade_name', 'profession_name'];
    private const HEADERS = [
        'sequence' => ['序号', '编号', 'sequence'],
        'course_code' => ['课程代码', '课程编号', 'course_code'],
        'course_name' => ['课程名称', '课程', 'course_name'],
        'course_category' => ['课程类别', '课程性质', 'course_category'],
        'dep_name' => ['开课院系', '学院', '学院名称', '院系', 'dep_name'],
        'grade_name' => ['届次', '届次名称', '授课对象年级', '年级', 'grade_name'],
        'profession_name' => ['授课对象专业', '专业', '专业名称', 'profession_name'],
        'total_credit' => ['总学分', '课程总学分', 'total_credit'],
        'internship_credit' => ['实习学分', '实践学分', 'internship_credit'],
        'total_hours' => ['总学时', '课程总学时', 'total_hours'],
        'internship_hours' => ['实习学时', '实践学时', 'internship_hours'],
        'source_teacher' => ['授课教师', '教师', '老师', 'source_teacher'],
        'source_time' => ['实习时间', '实践时间', '时间', 'source_time'],
        'source_location' => ['实习地点', '实践地点', '地点', 'source_location'],
        'remark' => ['备注', '说明', 'remark'],
    ];

    /** 生成并返回届次版实习计划导入模板 */
    public function template(): array
    {
        $directory = rtrim(public_path(), DIRECTORY_SEPARATOR) . '/templates/internship';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('模板目录创建失败');
        }
        $fileName = 'internship-plan-import-template.xlsx';
        $path = $directory . '/' . $fileName;
        if (!is_file($path)) {
            $temporaryPath = $path . '.tmp';
            $this->writeTemplate($temporaryPath);
            if (!rename($temporaryPath, $path)) {
                @unlink($temporaryPath);
                throw new RuntimeException('模板文件生成失败');
            }
        }

        return [
            'url' => '/templates/internship/' . $fileName,
            'download_name' => '实习计划导入模板.xlsx',
            'version' => '2026.07',
        ];
    }

    /** 解析 Excel 并返回专业拆分后的导入预览 */
    public function preview(Request $request, array $scope): array
    {
        $file = $this->excelFile($request);
        $sourceRows = $this->readRows($file);
        $items = [];
        foreach ($sourceRows as $sourceRow) {
            foreach ($this->expandProfessionRows($sourceRow, $scope) as $item) {
                $items[] = $this->previewItem($item, $scope);
                if (count($items) > self::EXCEL_MAX_ROWS) {
                    throw new InvalidArgumentException('专业拆分后单次最多预览 5000 条计划');
                }
            }
        }

        $fileInfo = (new FileService())->upload($request, [
            'allowed_extensions' => self::EXCEL_EXTENSIONS,
            'max_size' => self::EXCEL_MAX_SIZE,
            'require_md5' => false,
            'category' => 'internship_plan_import',
            'is_temporary' => false,
        ]);
        $errorCount = count(array_filter($items, static fn (array $item): bool => !empty($item['errors'])));
        $duplicateCount = count(array_filter($items, static fn (array $item): bool => !empty($item['duplicate'])));

        return [
            'file' => $fileInfo,
            'items' => $items,
            'business_types' => [
                ['value' => 'internship', 'label' => '实习'],
                ['value' => 'training', 'label' => '实训'],
                ['value' => 'lab', 'label' => '实验'],
                ['value' => 'social_practice', 'label' => '社会实践'],
                ['value' => 'ignore', 'label' => '忽略'],
            ],
            'summary' => [
                'source_rows' => count($sourceRows),
                'preview_rows' => count($items),
                'error_rows' => $errorCount,
                'duplicate_rows' => $duplicateCount,
            ],
        ];
    }

    /** 按预览确认结果创建或更新实习计划草稿 */
    public function confirm(Request $request, array $scope, int $accountId): array
    {
        $rows = $this->requestRows($request);
        $fileId = $this->positiveInt($request->input('import_file_id'));
        if ($fileId <= 0) {
            throw new InvalidArgumentException('import_file_id 无效');
        }
        $file = (new FileService())->info($fileId);
        if (($file['category'] ?? '') !== 'internship_plan_import') {
            throw new InvalidArgumentException('导入文件类型无效');
        }
        if ((int) ($file['uploader_id'] ?? 0) !== $accountId) {
            throw new RuntimeException('无权使用该导入文件', 40301);
        }
        if (!$rows) {
            throw new InvalidArgumentException('没有待确认的计划数据');
        }
        if (count($rows) > self::EXCEL_MAX_ROWS) {
            throw new InvalidArgumentException('单次最多确认 5000 条计划');
        }

        $summary = [
            'total' => count($rows),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'classified' => 0,
            'failed' => 0,
            'results' => [],
        ];
        foreach ($rows as $index => $row) {
            try {
                $result = $this->confirmRow($row, $scope, $accountId, $fileId);
                $status = (string) ($result['result'] ?? 'skipped');
                if (isset($summary[$status])) {
                    $summary[$status]++;
                } else {
                    $summary['skipped']++;
                }
                $summary['results'][] = $result;
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['results'][] = [
                    'row_key' => $row['row_key'] ?? 'row-' . ($index + 1),
                    'row_number' => (int) ($row['row_number'] ?? 0),
                    'result' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    private function confirmRow(array $row, array $scope, int $accountId, int $fileId): array
    {
        if (filter_var($row['business_type_confirmed'] ?? false, FILTER_VALIDATE_BOOL) !== true) {
            throw new InvalidArgumentException('请先确认该行所属业务类型');
        }
        $businessType = trim((string) ($row['business_type'] ?? ''));
        if (!in_array($businessType, self::BUSINESS_TYPES, true)) {
            throw new InvalidArgumentException('业务类型无效');
        }
        if ($businessType !== 'internship') {
            return [
                'row_key' => $row['row_key'] ?? null,
                'row_number' => (int) ($row['row_number'] ?? 0),
                'business_type' => $businessType,
                'result' => 'classified',
                'message' => $businessType === 'ignore' ? '已忽略' : '已确认业务归属，未写入实习计划',
            ];
        }

        $resolved = $this->resolveRow($row, $scope);
        if ($resolved['errors']) {
            throw new InvalidArgumentException(implode('；', $resolved['errors']));
        }
        $values = $resolved['values'];
        $identity = implode('|', [
            $values['grade_id'],
            $values['dep_id'],
            $values['profession_id'],
            $values['course_code'] !== '' ? $values['course_code'] : $values['course_name'],
        ]);
        $lockKey = implode(':', [
            'workflow_lock',
            CurrentContext::schoolDatabaseId() ?: 'school',
            'internship',
            'plan_import',
            sha1($identity),
        ]);

        return (new WorkflowLock())->run($lockKey, function () use ($row, $values, $accountId, $fileId): array {
            return InternshipRecord::connection()->transaction(function () use ($row, $values, $accountId, $fileId): array {
                $duplicate = InternshipRecord::planImportDuplicate($values, true);
                $duplicateAction = trim((string) ($row['duplicate_action'] ?? 'skip'));
                if ($duplicate) {
                    if ($duplicateAction !== 'update') {
                        return [
                            'row_key' => $row['row_key'] ?? null,
                            'row_number' => (int) ($row['row_number'] ?? 0),
                            'plan_id' => (int) $duplicate['id'],
                            'result' => 'skipped',
                            'message' => '重复计划已跳过',
                        ];
                    }
                    if (!in_array((string) ($duplicate['status'] ?? ''), ['draft', 'modify'], true)) {
                        return [
                            'row_key' => $row['row_key'] ?? null,
                            'row_number' => (int) ($row['row_number'] ?? 0),
                            'plan_id' => (int) $duplicate['id'],
                            'result' => 'skipped',
                            'message' => '计划已提交或生效，禁止覆盖',
                        ];
                    }
                }

                $now = date('Y-m-d H:i:s');
                $planValues = $this->planValues($row, $values, $accountId, $fileId, $now);
                if ($duplicate) {
                    InternshipRecord::updateById('internship_plan', (int) $duplicate['id'], $planValues);
                    $planId = (int) $duplicate['id'];
                    $result = 'updated';
                } else {
                    $planId = InternshipRecord::insertRow('internship_plan', array_merge($planValues, [
                        'uuid' => $this->uuid(),
                        'created_at' => $now,
                    ]));
                    $result = 'created';
                }

                return [
                    'row_key' => $row['row_key'] ?? null,
                    'row_number' => (int) ($row['row_number'] ?? 0),
                    'plan_id' => $planId,
                    'result' => $result,
                    'message' => $result === 'created' ? '已创建计划草稿' : '已更新计划草稿',
                ];
            });
        }, 30);
    }

    private function planValues(array $row, array $values, int $accountId, int $fileId, string $now): array
    {
        $content = [
            'course_category' => $values['course_category'],
            'total_credit' => $values['total_credit'],
            'internship_credit' => $values['internship_credit'],
            'total_hours' => $values['total_hours'],
            'internship_hours' => $values['internship_hours'],
            'source_teacher' => $values['source_teacher'],
            'source_time' => $values['source_time'],
            'source_location' => $values['source_location'],
            'remark' => $values['remark'],
        ];

        return [
            'name' => $values['course_name'],
            'code' => $values['course_code'] !== '' ? $values['course_code'] : null,
            'source_type' => 'edu_system',
            'course_code' => $values['course_code'] !== '' ? $values['course_code'] : null,
            'course_name' => $values['course_name'],
            'course_category' => $values['course_category'],
            'grade_id' => $values['grade_id'],
            'dep_id' => $values['dep_id'],
            'profession_id' => $values['profession_id'],
            'semester' => null,
            'credit' => $values['internship_credit'] ?? $values['total_credit'],
            'total_credit' => $values['total_credit'],
            'internship_credit' => $values['internship_credit'],
            'total_hours' => $values['total_hours'],
            'internship_hours' => $values['internship_hours'],
            'source_teacher' => $values['source_teacher'],
            'source_time' => $values['source_time'],
            'source_location' => $values['source_location'],
            'remark' => $values['remark'],
            'source_row' => $this->json($row['source_row'] ?? $row),
            'business_type' => 'internship',
            'import_file_id' => $fileId,
            'imported_at' => $now,
            'student_count' => 0,
            'score_rule' => 'average',
            'plan_content' => $this->json($content),
            'submitter_id' => $accountId,
            'status' => 'draft',
            'updated_at' => $now,
            'deleted_at' => null,
        ];
    }

    private function previewItem(array $row, array $scope): array
    {
        $resolved = $this->resolveRow($row, $scope);
        $duplicate = $resolved['errors'] ? null : InternshipRecord::planImportDuplicate($resolved['values']);
        $suggestedType = $this->suggestBusinessType((string) ($row['course_name'] ?? ''));

        return array_merge($row, $resolved['values'], [
            'row_key' => 'row-' . (int) ($row['row_number'] ?? 0) . '-' . (int) ($row['split_index'] ?? 0),
            'business_type' => $suggestedType,
            'business_type_confirmed' => false,
            'errors' => $resolved['errors'],
            'duplicate' => $duplicate,
            'duplicate_action' => $duplicate ? 'skip' : 'create',
            'can_import' => !$resolved['errors'],
        ]);
    }

    private function resolveRow(array $row, array $scope): array
    {
        $errors = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $errors[] = $this->fieldLabel($field) . '不能为空';
            }
        }
        $grade = trim((string) ($row['grade_name'] ?? '')) !== ''
            ? InternshipRecord::gradeRowByName(trim((string) $row['grade_name']))
            : null;
        if (!$grade && trim((string) ($row['grade_name'] ?? '')) !== '') {
            $errors[] = '届次不存在';
        }
        $department = trim((string) ($row['dep_name'] ?? '')) !== ''
            ? InternshipRecord::departmentRowByName(trim((string) $row['dep_name']), $scope)
            : null;
        if (!$department && trim((string) ($row['dep_name'] ?? '')) !== '') {
            $errors[] = '学院不存在或无权限';
        }
        $profession = null;
        if ($grade && $department && trim((string) ($row['profession_name'] ?? '')) !== '') {
            $profession = InternshipRecord::professionRowByName(
                trim((string) $row['profession_name']),
                (int) $grade['grade_id'],
                (int) $department['dep_id'],
                $scope
            );
            if (!$profession) {
                $errors[] = '专业不存在或不属于所选届次学院';
            }
        }

        $totalCredit = $this->nullableDecimal($row['total_credit'] ?? null);
        $internshipCredit = $this->nullableDecimal($row['internship_credit'] ?? null);
        if (trim((string) ($row['total_credit'] ?? '')) !== '' && $totalCredit === null) {
            $errors[] = '总学分格式无效';
        }
        if (trim((string) ($row['internship_credit'] ?? '')) !== '' && $internshipCredit === null) {
            $errors[] = '实习学分格式无效';
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'values' => [
                'course_code' => $this->text($row['course_code'] ?? null, 120),
                'course_name' => $this->text($row['course_name'] ?? null, 180),
                'course_category' => $this->text($row['course_category'] ?? null, 80) ?: null,
                'grade_id' => (int) ($grade['grade_id'] ?? 0),
                'dep_id' => (int) ($department['dep_id'] ?? 0),
                'profession_id' => (int) ($profession['profession_id'] ?? 0),
                'total_credit' => $totalCredit,
                'internship_credit' => $internshipCredit,
                'total_hours' => $this->text($row['total_hours'] ?? null, 40) ?: null,
                'internship_hours' => $this->text($row['internship_hours'] ?? null, 40) ?: null,
                'source_teacher' => $this->text($row['source_teacher'] ?? null, 10000) ?: null,
                'source_time' => $this->text($row['source_time'] ?? null, 10000) ?: null,
                'source_location' => $this->text($row['source_location'] ?? null, 10000) ?: null,
                'remark' => $this->text($row['remark'] ?? null, 10000) ?: null,
            ],
        ];
    }

    private function expandProfessionRows(array $row, array $scope): array
    {
        $professionText = trim((string) ($row['profession_name'] ?? ''));
        $professionNames = $this->splitList($professionText);
        if (in_array($professionText, ['所有专业', '全部专业'], true)) {
            $grade = InternshipRecord::gradeRowByName(trim((string) ($row['grade_name'] ?? '')));
            $department = InternshipRecord::departmentRowByName(trim((string) ($row['dep_name'] ?? '')), $scope);
            if ($grade && $department) {
                $professionNames = array_column(InternshipRecord::professionRowsForImport(
                    (int) $grade['grade_id'],
                    (int) $department['dep_id'],
                    $scope
                ), 'profession_name');
            }
        }
        if (!$professionNames) {
            $professionNames = [''];
        }

        $items = [];
        foreach ($professionNames as $index => $professionName) {
            $items[] = array_merge($row, [
                'profession_name' => $professionName,
                'profession_source' => $professionText,
                'split_index' => $index + 1,
            ]);
        }

        return $items;
    }

    private function readRows(UploadFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        try {
            $sheetRows = $spreadsheet->getActiveSheet()->toArray(null, false, true, true);
            [$mapping, $startRow] = $this->headerMapping($sheetRows);
            $items = [];
            foreach ($sheetRows as $rowNumber => $sheetRow) {
                if ((int) $rowNumber < $startRow) {
                    continue;
                }
                $item = ['row_number' => (int) $rowNumber];
                foreach ($mapping as $field => $column) {
                    $item[$field] = $this->cellText($sheetRow[$column] ?? null);
                }
                if ($this->rowEmpty($item)) {
                    continue;
                }
                $item['source_row'] = $item;
                $items[] = $item;
                if (count($items) > self::EXCEL_MAX_ROWS) {
                    throw new InvalidArgumentException('单次最多读取 5000 行');
                }
            }
            if (!$items) {
                throw new InvalidArgumentException('Excel 中没有可预览的数据');
            }

            return $items;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

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
            if (count(array_intersect(self::REQUIRED_FIELDS, array_keys($mapping))) === count(self::REQUIRED_FIELDS)) {
                return [$mapping, (int) $rowNumber + 1];
            }
        }

        throw new InvalidArgumentException('未识别到计划模板表头，请下载最新模板后重试');
    }

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

    private function writeTemplate(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('计划导入');
            $headers = [
                '序号', '课程代码', '课程名称', '课程类别', '开课院系', '届次', '授课对象专业',
                '总学分', '实习学分', '总学时', '实习学时', '授课教师', '实习时间', '实习地点', '备注',
            ];
            $sheet->fromArray($headers, null, 'A1');
            $sheet->fromArray([
                1, 'B00000001', '专业实习', '必修', '示例学院', '2026级', '示例专业',
                2, 2, 32, 32, '张老师', '第1-8周', '校外实习基地', '',
            ], null, 'A2');
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:O1');
            $sheet->getStyle('A1:O1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $widths = [8, 16, 24, 12, 18, 14, 24, 12, 12, 12, 12, 20, 28, 24, 28];
            foreach ($widths as $index => $width) {
                $sheet->getColumnDimension(chr(65 + $index))->setWidth($width);
            }
            $sheet->getRowDimension(1)->setRowHeight(28);

            $guide = $spreadsheet->createSheet();
            $guide->setTitle('字段说明');
            $guide->fromArray([
                ['模板字段名', '填写要求'],
                ['届次', '填写系统中已维护的届次名称，例如 2026级。'],
                ['授课对象专业', '一个单元格可填写多个专业，以中文逗号、顿号或分号分隔；预览时自动拆分。'],
                ['授课教师/实习时间/实习地点', '保留原始内容，不自动推断教师与时间地点的对应关系。'],
                ['业务归属', '上传后在预览页逐行确认为实习、实训、实验、社会实践或忽略。'],
                ['重复处理', '默认跳过；仅草稿或需修改计划允许明确选择更新。'],
            ], null, 'A1');
            $guide->getColumnDimension('A')->setWidth(24);
            $guide->getColumnDimension('B')->setWidth(90);
            $guide->getStyle('A1:B1')->getFont()->setBold(true);

            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function excelFile(Request $request): UploadFile
    {
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            throw new InvalidArgumentException('上传文件无效');
        }
        $extension = strtolower($file->getUploadExtension());
        if (!in_array($extension, self::EXCEL_EXTENSIONS, true)) {
            throw new InvalidArgumentException('仅支持 xls、xlsx 文件');
        }
        if ($file->getSize() > self::EXCEL_MAX_SIZE) {
            throw new InvalidArgumentException('文件大小不能超过 10MB');
        }

        return $file;
    }

    private function requestRows(Request $request): array
    {
        $rows = $request->input('rows', []);
        if (is_string($rows)) {
            $decoded = json_decode($rows, true);
            $rows = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function rowEmpty(array $row): bool
    {
        foreach (array_keys(self::HEADERS) as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function splitList(string $value): array
    {
        $parts = preg_split('/[，,、;；\n\r]+/u', trim($value)) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }

    private function suggestBusinessType(string $courseName): string
    {
        if (preg_match('/社会实践/u', $courseName)) {
            return 'social_practice';
        }
        if (preg_match('/毕业论文|论文/u', $courseName)) {
            return 'ignore';
        }
        if (preg_match('/实验/u', $courseName)) {
            return 'lab';
        }
        if (preg_match('/实训|项目实践|课程设计|综合实践/u', $courseName)) {
            return 'training';
        }

        return 'internship';
    }

    private function fieldLabel(string $field): string
    {
        return [
            'course_name' => '课程名称',
            'dep_name' => '学院',
            'grade_name' => '届次',
            'profession_name' => '专业',
        ][$field] ?? $field;
    }

    private function normalizedText(mixed $value): string
    {
        $value = trim((string) $value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return (string) preg_replace('/[\s　_\-:：()（）]+/u', '', $value);
    }

    private function cellText(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        } elseif (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }

        return $this->text((string) ($value ?? ''), 10000);
    }

    private function text(mixed $value, int $maxLength): string
    {
        $value = trim((string) ($value ?? ''));
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' && is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
