<?php

namespace app\server\edu;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use XMLReader;
use ZipArchive;

class EduSpreadsheetReader
{
    private const CHUNK_SIZE = 2000;
    private const MAX_CHUNK_SIZE = 5000;
    private const MAX_SHARED_STRINGS = 3000000;
    private const SHARED_STRING_MEMORY_LIMIT = 134217728;

    /** @var array<string, array{path: string, handle: XMLReader, cursor: int, total: int}> */
    private array $streams = [];

    /** @var array<int, string>|null */
    private ?array $sharedStrings = null;

    private ?string $sharedStringsSource = null;

    /** @var string[] */
    private array $tempFiles = [];

    public function readHeader(string $path, string $type, ?string $extension = null): array
    {
        $definition = (new EduTemplateService())->definitionFor($type);
        $headers = [];
        $rows = $this->isLegacyExcel($path, $extension) ? $this->legacyRows($path, 1, 1) : $this->rows($path, 1, 1);
        foreach ($rows as $row) {
            $headers = $row['values'];
            break;
        }
        $headers = array_map(static fn ($value): string => trim((string) $value), $headers);
        if ($headers !== $definition['headers']) {
            throw new InvalidArgumentException('Excel 表头与标准模板不一致，请下载模板后重新填写');
        }
        return ['headers' => $headers, 'required' => $definition['required']];
    }

    public function readChunk(string $path, string $type, int $startRow, int $chunkSize = self::CHUNK_SIZE, ?string $extension = null): iterable
    {
        $definition = (new EduTemplateService())->definitionFor($type);
        $startRow = max(2, $startRow);
        $limit = min(self::MAX_CHUNK_SIZE, max(1, $chunkSize));
        $rows = $this->isLegacyExcel($path, $extension)
            ? $this->legacyRows($path, $startRow, $limit)
            : $this->rows($path, $startRow, $limit);
        foreach ($rows as $item) {
            $row = [];
            foreach ($definition['headers'] as $index => $header) {
                $row[$header] = trim((string) ($item['values'][$index] ?? ''));
            }
            if (!$this->rowEmpty($row)) {
                yield ['row_number' => $item['row_number'], 'values' => $row];
            }
        }
    }

    public function rowCount(string $path, ?string $extension = null): int
    {
        if ($this->isLegacyExcel($path, $extension)) {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            foreach ($reader->listWorksheetInfo($path) as $sheet) {
                if (($sheet['worksheetName'] ?? '') === 'sheet1') {
                    return max(1, (int) ($sheet['totalRows'] ?? 1));
                }
            }
            throw new InvalidArgumentException('Excel 必须包含名为 sheet1 的工作表');
        }
        $key = $this->streamKey($path);
        return $this->streams[$key]['total'];
    }

    public function assertSafeArchive(string $path, string $extension): void
    {
        if ($extension !== 'xlsx' || !class_exists(ZipArchive::class)) {
            return;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('xlsx 压缩包无法读取');
        }
        $compressed = max(1, (int) filesize($path));
        $uncompressed = 0;
        $entries = $zip->numFiles;
        if ($entries > 200000) {
            $zip->close();
            throw new InvalidArgumentException('xlsx 文件目录数量超出限制');
        }
        for ($i = 0; $i < $entries; $i++) {
            $stat = $zip->statIndex($i);
            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > 2 * 1024 * 1024 * 1024 || $uncompressed / $compressed > 100) {
                $zip->close();
                throw new InvalidArgumentException('xlsx 文件疑似压缩炸弹');
            }
        }
        $zip->close();
    }

    public function release(): void
    {
        foreach ($this->streams as $stream) {
            $stream['handle']->close();
        }
        $this->streams = [];
        $this->sharedStrings = null;
        $this->sharedStringsSource = null;
        foreach ($this->tempFiles as $temp) {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        $this->tempFiles = [];
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * 单次遍历工作表，从持久游标起产出至多 $limit 行。
     *
     * 游标由本方法维护，$startRow 仅用于首次定位，调用方按块推进即可不重不漏。
     */
    private function rows(string $path, int $startRow, int $limit): iterable
    {
        $key = $this->streamKey($path);
        $stream = &$this->streams[$key];
        if ($stream['cursor'] >= $stream['total']) {
            return;
        }

        $reader = $stream['handle'];
        $sequential = $stream['cursor'] + 1;
        $emitted = 0;
        while ($emitted < $limit && $reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $this->localName($reader) !== 'row') {
                continue;
            }
            $rowNumber = (int) ($reader->getAttribute('r') ?? 0) ?: $sequential;
            $sequential = $rowNumber + 1;
            $values = $this->readRowValues($reader, $path);
            $stream['cursor'] = $rowNumber;
            if ($rowNumber < $startRow) {
                continue;
            }
            $emitted++;
            yield ['row_number' => $rowNumber, 'values' => $values];
        }
    }

    private function legacyRows(string $path, int $startRow, int $limit): iterable
    {
        $endRow = $startRow + $limit - 1;
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter {
            public function __construct(private readonly int $startRow, private readonly int $endRow)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->startRow && $row <= $this->endRow;
            }
        });
        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName('sheet1');
        if (!$worksheet) {
            $spreadsheet->disconnectWorksheets();
            throw new InvalidArgumentException('Excel 必须包含名为 sheet1 的工作表');
        }

        try {
            foreach ($worksheet->getRowIterator($startRow, $endRow) as $row) {
                $values = [];
                $cells = $row->getCellIterator();
                $cells->setIterateOnlyExistingCells(false);
                foreach ($cells as $cell) {
                    $values[] = (string) ($cell->getValue() ?? '');
                }
                yield ['row_number' => $row->getRowIndex(), 'values' => $values];
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * 读取当前 row 元素下的所有单元格，返回按列序号排列的值。
     */
    private function readRowValues(XMLReader $reader, string $path): array
    {
        $values = [];
        $depth = $reader->depth;
        $index = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $this->localName($reader) === 'row') {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $this->localName($reader) !== 'c') {
                continue;
            }
            $reference = (string) ($reader->getAttribute('r') ?? '');
            $column = $reference !== '' ? $this->columnIndex($reference) : $index;
            $type = (string) ($reader->getAttribute('t') ?? '');
            $values[$column] = $this->readCellValue($reader, $type, $path);
            $index = $column + 1;
        }
        if (!$values) {
            return [];
        }
        $max = max(array_keys($values));
        $ordered = [];
        for ($i = 0; $i <= $max; $i++) {
            $ordered[$i] = $values[$i] ?? '';
        }
        return $ordered;
    }

    private function readCellValue(XMLReader $reader, string $type, string $path): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }
        $depth = $reader->depth;
        if ($type === 'inlineStr') {
            $value = $this->readInlineString($reader, $depth);
            return $this->normalizeValue($value, $type, $path);
        }
        $raw = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $this->localName($reader) === 'c') {
                break;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $this->localName($reader) === 'v') {
                $raw = (string) $reader->readString();
            }
        }
        return $this->normalizeValue($raw, $type, $path);
    }

    private function readInlineString(XMLReader $reader, int $cellDepth): string
    {
        $value = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $cellDepth && $this->localName($reader) === 'c') {
                break;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $this->localName($reader) === 't') {
                $value .= (string) $reader->readString();
            }
        }
        return $value;
    }

    private function normalizeValue(string $value, string $type, string $path): string
    {
        return match ($type) {
            's' => $this->sharedString((int) $value, $path),
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            default => $value,
        };
    }

    private function sharedString(int $index, string $path): string
    {
        $source = $this->sharedStringsSource;
        if ($source !== $path || $this->sharedStrings === null) {
            $this->sharedStrings = $this->loadSharedStrings($path);
            $this->sharedStringsSource = $path;
        }
        return $this->sharedStrings[$index] ?? '';
    }

    /**
     * 流式读取共享字符串表，避免一次性载入超大 XML。
     *
     * @return array<int, string>
     */
    private function loadSharedStrings(string $path): array
    {
        $archive = $this->open($path);
        try {
            $entry = $this->resolveEntry($archive, 'xl/sharedStrings.xml');
            if ($entry === null) {
                return [];
            }
            $content = $this->readEntry($archive, $entry);
        } finally {
            $archive->close();
        }
        $reader = new XMLReader();
        if (!$reader->open($content, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Excel 共享字符串表无法解析');
        }
        $strings = [];
        $depth = 0;
        $buffer = '';
        $collecting = false;
        try {
            while ($reader->read()) {
                $name = $this->localName($reader);
                if ($reader->nodeType === XMLReader::ELEMENT && $name === 'si') {
                    $depth = $reader->depth;
                    $buffer = '';
                    $collecting = true;
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $collecting = false;
                    }
                    continue;
                }
                if ($collecting && $reader->nodeType === XMLReader::ELEMENT && $name === 't') {
                    $buffer .= (string) $reader->readString();
                    continue;
                }
                if ($collecting && $reader->nodeType === XMLReader::END_ELEMENT && $name === 'si' && $reader->depth === $depth) {
                    $strings[] = $buffer;
                    $collecting = false;
                    if (count($strings) > self::MAX_SHARED_STRINGS || memory_get_usage(true) > self::SHARED_STRING_MEMORY_LIMIT) {
                        throw new InvalidArgumentException('Excel 共享字符串表超出解析上限');
                    }
                }
            }
        } finally {
            $reader->close();
        }
        return $strings;
    }

    /**
     * 首次调用时建立持久流，后续调用复用同一游标。
     */
    private function streamKey(string $path): string
    {
        $key = $path;
        if (isset($this->streams[$key])) {
            return $key;
        }
        $archive = $this->open($path);
        try {
            $name = $this->worksheetEntry($archive);
            $content = $this->readEntry($archive, $name);
        } finally {
            $archive->close();
        }
        $counter = new XMLReader();
        if (!$counter->open($content, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidArgumentException('Excel 工作表无法解析');
        }
        $total = 0;
        try {
            while ($counter->read()) {
                if ($counter->nodeType === XMLReader::ELEMENT && $this->localName($counter) === 'row') {
                    $total++;
                }
            }
        } finally {
            $counter->close();
        }
        $reader = new XMLReader();
        if (!$reader->open($content, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidArgumentException('Excel 工作表无法解析');
        }
        $this->streams[$key] = ['path' => $path, 'handle' => $reader, 'cursor' => 0, 'total' => max(1, $total)];
        return $key;
    }

    /**
     * 解压指定条目到内存受限的临时流。
     */
    private function readEntry(ZipArchive $archive, string $name)
    {
        $handle = $archive->getStream($name);
        if (!$handle) {
            throw new InvalidArgumentException('Excel 工作表无法读取');
        }
        try {
            return $this->streamContent($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * 通过 workbook 关系定位名为 sheet1 的工作表，避免依赖固定文件名。
     */
    private function worksheetEntry(ZipArchive $archive): string
    {
        $workbook = $this->resolveEntry($archive, 'xl/workbook.xml');
        $sheetPath = null;
        if ($workbook !== null) {
            $xml = $archive->getFromName($workbook);
            if (is_string($xml) && $xml !== '') {
                $document = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
                if ($document !== false) {
                    $document->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $document->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    foreach ($document->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
                        if (trim((string) $sheet['name']) !== 'sheet1') {
                            continue;
                        }
                        $relationId = '';
                        foreach ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $key => $value) {
                            if ($key === 'id') {
                                $relationId = (string) $value;
                            }
                        }
                        $sheetPath = $relationId !== '' ? $this->relationTarget($archive, $relationId) : null;
                        break;
                    }
                }
            }
        }
        if ($sheetPath === null) {
            throw new InvalidArgumentException('Excel 必须包含名为 sheet1 的工作表');
        }
        return $sheetPath;
    }

    private function relationTarget(ZipArchive $archive, string $relationId): ?string
    {
        $rels = $this->resolveEntry($archive, 'xl/_rels/workbook.xml.rels');
        if ($rels === null) {
            return null;
        }
        $xml = $archive->getFromName($rels);
        if (!is_string($xml) || $xml === '') {
            return null;
        }
        $document = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if ($document === false) {
            return null;
        }
        foreach ($document->Relationship as $relationship) {
            if ((string) $relationship['Id'] !== $relationId) {
                continue;
            }
            $target = ltrim((string) $relationship['Target'], '/');
            $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            return $this->resolveEntry($archive, $target);
        }
        return null;
    }

    /**
     * 按实际压缩包条目名解析，兼容大小写差异。
     */
    private function resolveEntry(ZipArchive $archive, string $name): ?string
    {
        $found = $archive->locateName($name, ZipArchive::FL_NOCASE);
        return $found === false ? null : $archive->getNameIndex($found);
    }

    /**
     * 将压缩流转换为可重复读取的临时文件，规避 XMLReader 对内存字符串的限制。
     */
    private function streamContent($handle): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'edu-xlsx-');
        if ($temp === false) {
            throw new RuntimeException('Excel 临时文件创建失败');
        }
        $target = fopen($temp, 'wb');
        if (!$target) {
            @unlink($temp);
            throw new RuntimeException('Excel 临时文件创建失败');
        }
        try {
            stream_copy_to_stream($handle, $target);
        } finally {
            fclose($target);
        }
        $this->tempFiles[] = $temp;
        return $temp;
    }

    private function open(string $path): ZipArchive
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Excel 文件不存在');
        }
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw new InvalidArgumentException('Excel 文件无法读取');
        }
        return $archive;
    }

    private function isLegacyExcel(string $path, ?string $extension = null): bool
    {
        return strtolower((string) ($extension ?: pathinfo($path, PATHINFO_EXTENSION))) === 'xls';
    }

    private function columnIndex(string $reference): int
    {
        $letters = '';
        $length = strlen($reference);
        for ($i = 0; $i < $length; $i++) {
            $char = $reference[$i];
            if ($char >= 'A' && $char <= 'Z') {
                $letters .= $char;
                continue;
            }
            if ($char >= 'a' && $char <= 'z') {
                $letters .= strtoupper($char);
                continue;
            }
            break;
        }
        if ($letters === '') {
            return 0;
        }
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }

    private function localName(XMLReader $reader): string
    {
        return $reader->localName ?: $reader->name;
    }

    private function rowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '') {
                return false;
            }
        }
        return true;
    }
}
