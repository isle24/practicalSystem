<?php

namespace app\server\export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** 导出文件生成器 */
class ExportGenerator
{
    /**
     * @return array{path:string, ext:string, total_rows:int}
     */
    public function generate(string $type, array $params): array
    {
        return match ($type) {
            'internship_base_word' => (new InternshipDocumentExporter())->baseWord((int) ($params['base_id'] ?? 0)),
            'internship_implementation_pdf' => (new InternshipDocumentExporter())->implementationPdf((int) ($params['arrangement_id'] ?? 0)),
            default => $this->tabular($params),
        };
    }

    /**
     * 通用表格：params = { title?, headers?: string[], rows?: array<array> }。
     * headers 缺省时用首行数据键；rows 缺省时导出一张仅含说明的表。
     */
    private function tabular(array $params): array
    {
        $title = $this->sheetTitle((string) ($params['title'] ?? '导出数据'));
        $rows = is_array($params['rows'] ?? null) ? array_values($params['rows']) : [];
        $headers = $this->resolveHeaders($params, $rows);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);

        $rowIndex = 1;
        if ($headers) {
            $this->writeRow($sheet, $rowIndex++, array_values($headers));
        }

        $dataRows = 0;
        foreach ($rows as $row) {
            $this->writeRow($sheet, $rowIndex++, $this->normalizeRow($row, $headers));
            $dataRows++;
        }

        if (!$headers && !$dataRows) {
            $sheet->setCellValue('A1', '无数据');
        }

        $path = $this->tempPath();
        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return ['path' => $path, 'ext' => 'xlsx', 'total_rows' => $dataRows];
    }

    private function resolveHeaders(array $params, array $rows): array
    {
        if (is_array($params['headers'] ?? null) && $params['headers']) {
            return array_map(static fn ($h): string => (string) $h, array_values($params['headers']));
        }

        $first = $rows[0] ?? null;
        if (is_array($first) && $this->isAssoc($first)) {
            return array_map(static fn ($k): string => (string) $k, array_keys($first));
        }

        return [];
    }

    private function normalizeRow(mixed $row, array $headers): array
    {
        if (!is_array($row)) {
            return [(string) $row];
        }

        if ($headers && $this->isAssoc($row)) {
            $keys = array_keys($row);
            // 关联数组按 headers 顺序对齐；headers 来自首行键时顺序一致
            $ordered = [];
            foreach ($keys as $i => $key) {
                $ordered[] = $row[$key];
            }
            return array_map([$this, 'scalar'], $ordered);
        }

        return array_map([$this, 'scalar'], array_values($row));
    }

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) ($value ?? '');
    }

    private function writeRow(mixed $sheet, int $rowIndex, array $values): void
    {
        $col = 1;
        foreach ($values as $value) {
            $coordinate = Coordinate::stringFromColumnIndex($col++) . $rowIndex;
            $sheet->setCellValueExplicit(
                $coordinate,
                is_string($value) ? $value : $this->scalar($value),
                DataType::TYPE_STRING
            );
        }
    }

    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function sheetTitle(string $title): string
    {
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $title) ?: '导出数据';
        $title = trim($title) ?: '导出数据';
        return mb_substr($title, 0, 31);
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export_');
        if ($path === false) {
            throw new RuntimeException('无法创建导出临时文件');
        }
        return $path;
    }
}
