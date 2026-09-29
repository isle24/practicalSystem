<?php

namespace app\server\export;

use app\model\channel\FileRecord;
use app\model\channel\SignatureRecord;
use app\server\CurrentContext;
use app\server\file\FileService;
use RuntimeException;

class SignatureRenderer
{
    /** 调用方须先授权所属审批记录或导出任务。 */
    public function image(?array $snapshot, int $maxWidth = 150, int $maxHeight = 70): ?array
    {
        if (!$snapshot) return null;
        if ((int) ($snapshot['school_id'] ?? 0) !== (int) CurrentContext::schoolDatabaseId() || !CurrentContext::schoolDatabaseId()) throw new RuntimeException('签名快照学校不匹配', 403);
        $row = SignatureRecord::signatures()->where('id', (int) ($snapshot['signature_id'] ?? 0))->where('file_id', (int) ($snapshot['file_id'] ?? 0))->where('user_id', (int) ($snapshot['user_id'] ?? 0))->where('version', (int) ($snapshot['version'] ?? 0))->first();
        $hash = (string) ($snapshot['sha256'] ?? '');
        $signedAt = (string) ($snapshot['signed_at'] ?? '');
        if (!$row || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals((string) $row->sha256, $hash) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $signedAt)) throw new RuntimeException('签名快照无效', 409);
        $file = FileRecord::detailById((int) $row->file_id);
        $path = $file ? (new FileService())->localPath((string) $file->path) : null;
        if (!$file || $file->category !== 'personal_signature' || $file->mime_type !== 'image/png' || !$path || !hash_equals($hash, (string) hash_file('sha256', $path))) throw new RuntimeException('历史签名文件缺失或摘要不匹配', 409);
        $size = getimagesize($path);
        if (!$size || $size[2] !== IMAGETYPE_PNG || $size[0] !== (int) $row->width || $size[1] !== (int) $row->height) throw new RuntimeException('历史签名尺寸不匹配', 409);
        $ratio = min(max(1, $maxWidth) / $size[0], max(1, $maxHeight) / $size[1], 1);
        return ['path' => $path, 'width' => max(1, (int) round($size[0] * $ratio)), 'height' => max(1, (int) round($size[1] * $ratio)), 'signed_at' => $signedAt];
    }

    public function html(?array $snapshot, int $maxWidth = 150, int $maxHeight = 70): string
    {
        $image = $this->image($snapshot, $maxWidth, $maxHeight);
        if (!$image) return '';
        $data = base64_encode((string) file_get_contents($image['path']));
        $date = htmlspecialchars(substr($image['signed_at'], 0, 10), ENT_QUOTES, 'UTF-8');
        return '<span class="approval-signature"><img alt="审批签名" src="data:image/png;base64,' . $data . '" width="' . $image['width'] . '" height="' . $image['height'] . '"><br>' . $date . '</span>';
    }

    public function appendToWord(object $container, ?array $snapshot, int $maxWidth = 150, int $maxHeight = 70): void
    {
        $image = $this->image($snapshot, $maxWidth, $maxHeight);
        if (!$image) return;
        $container->addImage($image['path'], ['width' => $image['width'], 'height' => $image['height'], 'wrappingStyle' => 'inline']);
        $container->addText(substr($image['signed_at'], 0, 10));
    }

    public function appendToSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, ?array $snapshot, int $maxWidth = 150, int $maxHeight = 70): void
    {
        $image = $this->image($snapshot, $maxWidth, $maxHeight);
        if (!$image) return;
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName('审批签名');
        $drawing->setDescription('审批日期 ' . substr($image['signed_at'], 0, 10));
        $drawing->setPath($image['path']);
        $drawing->setCoordinates($cell);
        $drawing->setOffsetY(20);
        $sheet->setCellValue($cell, substr($image['signed_at'], 0, 10));
        [, $rowNumber] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($cell);
        $row = $sheet->getRowDimension((int) $rowNumber);
        $row->setRowHeight(max($row->getRowHeight(), ($image['height'] + 24) * 0.75));
        $drawing->setResizeProportional(true);
        $drawing->setWidth($image['width']);
        $drawing->setWorksheet($sheet);
    }
}
