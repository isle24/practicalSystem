<?php

namespace app\server\export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** 生成实验实训课程考核及成绩记载表。 */
class PracticeScoreSheetExporter
{
    private const TEMPLATE = 'resources/templates/practice/practice-score-sheet-2026.xls';
    private const DATA_START_ROW = 7;
    private const TEMPLATE_DATA_ROWS = 17;
    private const DATA_END_ROW = self::DATA_START_ROW + self::TEMPLATE_DATA_ROWS - 1;
    private const FOOTER_START_ROW = 24;

    /**
     * @return array{path:string, ext:string, total_rows:int}
     */
    public function generate(array $params): array
    {
        $rows = array_values(array_filter(
            (array) ($params['rows'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        if (!$rows) {
            throw new RuntimeException('没有可导出的学生成绩数据');
        }

        $template = base_path(self::TEMPLATE);
        if (!is_file($template)) {
            throw new RuntimeException('实验实训成绩记载表模板不存在');
        }

        $spreadsheet = IOFactory::load($template);
        $sheet = $spreadsheet->getSheet(0);
        try {
            $this->writeTitle($sheet, (array) ($params['sheet_meta'] ?? []));
            $this->writeMeta($sheet, (array) ($params['sheet_meta'] ?? []));
            $this->prepareDataRows($sheet, count($rows));
            foreach ($rows as $index => $row) {
                $this->writeStudentRow($sheet, self::DATA_START_ROW + $index, $row, $index + 1);
            }
            $sheet->getPageSetup()
                ->setOrientation('landscape')
                ->setFitToWidth(1)
                ->setFitToHeight(0)
                ->setFitToPage(true)
                ->setPrintArea('A1:AJ' . $sheet->getHighestRow());

            $path = tempnam(sys_get_temp_dir(), 'practice_score_');
            if ($path === false) {
                throw new RuntimeException('无法创建成绩记载表导出文件');
            }
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return [
            'path' => $path,
            'ext' => 'xlsx',
            'total_rows' => count($rows),
        ];
    }

    private function writeTitle(Worksheet $sheet, array $meta): void
    {
        $schoolName = trim((string) ($meta['school_name'] ?? '')) ?: '成都锦城学院';
        $sheet->setCellValue('A1', $schoolName . '课程考核及成绩记载表（实验实训）');
    }

    private function writeMeta(Worksheet $sheet, array $meta): void
    {
        $values = [
            'C2' => $meta['academic_year'] ?? '',
            'F2' => $meta['semester'] ?? '',
            'K2' => $meta['course_number'] ?? '',
            'X2' => $meta['course_name'] ?? '',
            'C3' => $meta['teacher_name'] ?? '',
            'F3' => $meta['teacher_unit'] ?? '',
            'O3' => $meta['class_time'] ?? '',
            'X3' => $meta['location'] ?? '',
        ];
        foreach ($values as $coordinate => $value) {
            $this->writeString($sheet, $coordinate, $value);
        }
    }

    private function prepareDataRows(Worksheet $sheet, int $rowCount): int
    {
        $extraRows = max(0, $rowCount - self::TEMPLATE_DATA_ROWS);
        if ($extraRows > 0) {
            $sheet->insertNewRowBefore(self::FOOTER_START_ROW, $extraRows);
            $sourceStyle = $sheet->getStyle('A' . self::DATA_END_ROW . ':AJ' . self::DATA_END_ROW);
            $sourceHeight = $sheet->getRowDimension(self::DATA_END_ROW)->getRowHeight();
            for ($index = 0; $index < $extraRows; $index++) {
                $row = self::DATA_END_ROW + $index + 1;
                $sheet->duplicateStyle($sourceStyle, 'A' . $row . ':AJ' . $row);
                if ($sourceHeight !== null) {
                    $sheet->getRowDimension($row)->setRowHeight($sourceHeight);
                }
                $sheet->mergeCells('B' . $row . ':C' . $row);
            }
        }

        $lastDataRow = self::DATA_START_ROW + $rowCount - 1;
        for ($row = self::DATA_START_ROW; $row <= max(self::DATA_END_ROW, $lastDataRow); $row++) {
            for ($column = 1; $column <= 36; $column++) {
                $name = Coordinate::stringFromColumnIndex($column);
                if ($name === 'C') {
                    continue;
                }
                $sheet->setCellValue($name . $row, null);
            }
        }

        return $lastDataRow;
    }

    private function writeStudentRow(Worksheet $sheet, int $rowNumber, array $row, int $sequence): void
    {
        $this->writeValue($sheet, 'A' . $rowNumber, $sequence);
        $this->writeString($sheet, 'B' . $rowNumber, $row['student_num'] ?? '');
        $this->writeString($sheet, 'D' . $rowNumber, $row['student_name'] ?? '');
        $this->writeString($sheet, 'E' . $rowNumber, $row['class_name'] ?? '');

        for ($index = 1; $index <= 16; $index++) {
            $this->writeValue($sheet, $this->cell('F', $index, $rowNumber), $row['attendance_' . $index] ?? null);
        }
        $this->writeValue($sheet, 'V' . $rowNumber, $row['attendance_total'] ?? null);

        for ($index = 1; $index <= 12; $index++) {
            $this->writeValue($sheet, $this->cell('W', $index, $rowNumber), $row['project_' . $index] ?? null);
        }
        $this->writeValue($sheet, 'AI' . $rowNumber, $row['report_score'] ?? null);
        $this->writeValue($sheet, 'AJ' . $rowNumber, $row['total_score'] ?? null);
    }

    private function cell(string $firstColumn, int $offset, int $rowNumber): string
    {
        $column = Coordinate::columnIndexFromString($firstColumn) + $offset - 1;
        return Coordinate::stringFromColumnIndex($column) . $rowNumber;
    }

    private function writeString(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        $value = trim((string) ($value ?? ''));
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }

    private function writeValue(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValue($coordinate, null);
            return;
        }
        if (is_int($value) || is_float($value)) {
            $sheet->setCellValue($coordinate, $value);
            return;
        }

        $value = trim((string) $value);
        if ($value !== '' && is_numeric($value)) {
            $sheet->setCellValue($coordinate, (float) $value);
            return;
        }
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }
}
