<?php

namespace app\server\teacher;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;
use ZipArchive;

class TeacherImportReader
{
    public const MAX_FILE_SIZE = 10485760;
    public const MAX_ROWS = 10000;

    private const HEADERS = [
        '职工号', '姓名', '性别', '出生日期', '部门（学院）', '科室（系）', '联系电话', 'E_mail地址',
        '教职工类别', '学历', '学位', '职务', '职称', '派监考老师可用否', '教学研究方向', '教学质量评价',
        '教师简介', '专业名称', '毕业院校', '教师资格', '人事职工号', '在职类别', '教师级别', '手机号码',
        '手机类型', '是否实验室人员', '是否外聘', '工作量系数', '政治面貌', '民族', '参加工作时间',
        '教师姓名拼音', '社会服务方向', '教龄', '校龄', '英文姓名', '学缘结构', '教师任职期限时间', '登陆名',
    ];

    private array $metadataCache = [];

    public function metadata(string $path): array
    {
        $key = $this->fileKey($path);
        if (isset($this->metadataCache[$key])) {
            return $this->metadataCache[$key];
        }
        $this->assertArchive($path);
        $reader = $this->reader($path);
        $sheets = $reader->listWorksheetInfo($path);
        $first = $sheets[0] ?? null;
        if (!is_array($first) || empty($first['worksheetName'])) {
            throw new InvalidArgumentException('Excel 缺少工作表');
        }
        $totalRows = max(0, (int) ($first['totalRows'] ?? 0) - 1);
        if ($totalRows > self::MAX_ROWS) {
            throw new InvalidArgumentException('教师文件最多支持 10000 条数据');
        }
        if ((int) ($first['totalColumns'] ?? 0) !== count(self::HEADERS)) {
            throw new InvalidArgumentException('教师文件必须包含模板的 39 列');
        }
        $sheetName = (string) $first['worksheetName'];
        $reader->setLoadSheetsOnly($sheetName);
        $reader->setReadFilter($this->filter(1, 1));
        $spreadsheet = $reader->load($path);
        try {
            $sheet = $spreadsheet->getSheet(0);
            foreach (self::HEADERS as $index => $header) {
                $cell = $sheet->getCell([$index + 1, 1]);
                if ($cell->getDataType() === DataType::TYPE_FORMULA || $this->text($cell) !== $header) {
                    throw new InvalidArgumentException('教师文件表头与模板不一致');
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        return $this->metadataCache[$key] = ['sheet' => $sheetName, 'total_rows' => $totalRows];
    }

    public function rows(string $path, int $startRow = 2, int $limit = 100): array
    {
        $metadata = $this->metadata($path);
        $startRow = max(2, $startRow);
        $endRow = min($metadata['total_rows'] + 1, $startRow + min(1000, max(1, $limit)) - 1);
        if ($endRow < $startRow) {
            return [];
        }
        $reader = $this->reader($path);
        $reader->setLoadSheetsOnly($metadata['sheet']);
        $reader->setReadFilter($this->filter($startRow, $endRow));
        $spreadsheet = $reader->load($path);
        $result = [];
        try {
            $sheet = $spreadsheet->getSheet(0);
            for ($rowNumber = $startRow; $rowNumber <= $endRow; $rowNumber++) {
                $raw = [];
                $errors = [];
                foreach (self::HEADERS as $index => $header) {
                    $cell = $sheet->getCell([$index + 1, $rowNumber]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $errors[] = $header . '不允许使用公式';
                        $raw[$header] = '';
                        continue;
                    }
                    if ($cell->getDataType() === DataType::TYPE_ERROR) {
                        $errors[] = $header . '包含 Excel 错误值';
                        $raw[$header] = '';
                        continue;
                    }
                    try {
                        $raw[$header] = $header === '出生日期'
                            ? $this->date($cell, $spreadsheet->getExcelCalendar())
                            : $this->text($cell, $header === '职工号');
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = $exception->getMessage();
                        $raw[$header] = '';
                    }
                }
                if (!$errors && !array_filter($raw, static fn ($value): bool => $value !== '')) {
                    continue;
                }
                $values = [
                    'teacher_num' => $raw['职工号'],
                    'teacher_name' => $raw['姓名'],
                    'dep_name' => $raw['部门（学院）'],
                    'gender' => $raw['性别'],
                    'birth_date' => $raw['出生日期'],
                    'title' => $raw['职称'],
                    'education' => $raw['学历'],
                    'phone' => $raw['手机号码'] !== '' ? $raw['手机号码'] : $raw['联系电话'],
                    'email' => $raw['E_mail地址'],
                    'employment_type' => $raw['教职工类别'],
                ];
                $limits = [
                    'teacher_num' => [80, '职工号'], 'teacher_name' => [80, '姓名'], 'dep_name' => [120, '部门（学院）'],
                    'gender' => [20, '性别'], 'title' => [120, '职称'], 'education' => [80, '学历'],
                    'phone' => [40, '联系电话'], 'email' => [120, 'E_mail地址'], 'employment_type' => [40, '教职工类别'],
                ];
                foreach ($limits as $field => [$length, $label]) {
                    if (mb_strlen($values[$field]) > $length) {
                        $errors[] = $label . '长度超限';
                    }
                }
                foreach (['teacher_num' => '职工号', 'teacher_name' => '姓名', 'dep_name' => '部门（学院）'] as $field => $label) {
                    if ($values[$field] === '') {
                        $errors[] = $label . '不能为空';
                    }
                }
                if (preg_match('/[\x00-\x20\x7f]/u', $values['teacher_num'])) {
                    $errors[] = '职工号不能包含空白或控制字符';
                }
                if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'E_mail地址格式无效';
                }
                $result[] = ['row_number' => $rowNumber, 'values' => $values, 'errors' => array_values(array_unique($errors))];
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        return $result;
    }

    public function template(): array
    {
        $directory = runtime_path() . DIRECTORY_SEPARATOR . 'teacher-templates';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('教师模板目录创建失败');
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'teacher-import-' . substr(hash('sha256', implode('|', self::HEADERS)), 0, 12) . '.xlsx';
        if (!is_file($path)) {
            $spreadsheet = new Spreadsheet();
            $temporary = tempnam($directory, 'teacher-');
            if ($temporary === false) {
                throw new RuntimeException('教师模板创建失败');
            }
            try {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Sheet1');
                foreach (self::HEADERS as $index => $header) {
                    $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
                    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth(18);
                }
                $sheet->getStyle('A1:AM1')->getFont()->setBold(true);
                $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
                $sheet->freezePane('A2');
                (new Xlsx($spreadsheet))->save($temporary);
                if (!rename($temporary, $path)) {
                    throw new RuntimeException('教师模板保存失败');
                }
            } finally {
                $spreadsheet->disconnectWorksheets();
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        return ['path' => $path, 'download_name' => '教师信息导入模板.xlsx'];
    }

    private function fileKey(string $path): string
    {
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('教师文件不存在或无法读取');
        }
        $size = (int) filesize($path);
        if ($size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('教师文件大小必须在 1B 至 10MB 之间');
        }
        return $path . ':' . $size . ':' . (int) filemtime($path);
    }

    private function reader(string $path): IReader
    {
        try {
            $reader = IOFactory::createReaderForFile($path, ['Xls', 'Xlsx']);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('教师文件必须为有效的 xls 或 xlsx 文件', 0, $exception);
        }
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        return $reader;
    }

    private function filter(int $startRow, int $endRow): IReadFilter
    {
        return new class($startRow, $endRow) implements IReadFilter {
            public function __construct(private int $startRow, private int $endRow)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->startRow && $row <= $this->endRow
                    && Coordinate::columnIndexFromString($columnAddress) <= 39;
            }
        };
    }

    private function text(Cell $cell, bool $identifier = false): string
    {
        $value = $cell->getValue();
        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }
        if ($value === null) {
            return '';
        }
        if ($identifier && (is_int($value) || is_float($value))) {
            if (!is_finite((float) $value) || $value < 0 || floor((float) $value) !== (float) $value || $value >= 1.0e15) {
                throw new InvalidArgumentException('职工号数值无效，请将职工号列设置为文本');
            }
            $format = $cell->getStyle()->getNumberFormat()->getFormatCode();
            if (preg_match('/^0+$/D', $format)) {
                return str_pad(sprintf('%.0f', $value), strlen($format), '0', STR_PAD_LEFT);
            }
            return sprintf('%.0f', $value);
        }
        return trim((string) $value);
    }

    private function date(Cell $cell, int $calendar): string
    {
        $value = $cell->getValue();
        if ($value === null || trim((string) $value) === '') {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            $minimum = $calendar === Date::CALENDAR_MAC_1904 ? 0 : 1;
            if (!is_finite((float) $value) || $value < $minimum || $value > 2958465 || floor((float) $value) !== (float) $value
                || ($calendar === Date::CALENDAR_WINDOWS_1900 && (int) $value === 60)) {
                throw new InvalidArgumentException('出生日期的 Excel 日期数值无效');
            }
            $previousCalendar = Date::getExcelCalendar();
            try {
                Date::setExcelCalendar($calendar);
                $date = Date::excelToDateTimeObject($value, new DateTimeZone('UTC'));
                if ((int) $date->format('Y') > 9999) {
                    throw new InvalidArgumentException('出生日期超出支持范围');
                }
                return $date->format('Y-m-d');
            } finally {
                Date::setExcelCalendar($previousCalendar);
            }
        }
        $text = trim((string) $value);
        if (!preg_match('/^(\d{4})([-\/.])(\d{1,2})\2(\d{1,2})$/D', $text, $matches)) {
            throw new InvalidArgumentException('出生日期格式无效，应为 YYYY-MM-DD');
        }
        $normalized = sprintf('%04d-%02d-%02d', $matches[1], $matches[3], $matches[4]);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        if (!$date || $date->format('Y-m-d') !== $normalized || (int) $matches[1] < 1900) {
            throw new InvalidArgumentException('出生日期不是有效日期');
        }
        return $normalized;
    }

    private function assertArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }
        try {
            if ($zip->numFiles > 2000) {
                throw new InvalidArgumentException('xlsx 压缩包条目超限');
            }
            $expanded = 0;
            $compressed = max(1, (int) filesize($path));
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || !empty($stat['encryption_method'])) {
                    throw new InvalidArgumentException('xlsx 压缩包条目无效或已加密');
                }
                $expanded += (int) ($stat['size'] ?? 0);
                if ($expanded > 100 * 1024 * 1024 || $expanded / $compressed > 100) {
                    throw new InvalidArgumentException('xlsx 解压大小或压缩比超限');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
