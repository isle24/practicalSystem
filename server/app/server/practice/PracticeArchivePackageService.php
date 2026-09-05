<?php

namespace app\server\practice;

use app\model\channel\FileRecord;
use app\server\file\FileService;
use RuntimeException;
use ZipArchive;

class PracticeArchivePackageService
{
    /** 生成并登记正式归档 ZIP。 */
    public function create(array $snapshot, string $moduleType, int $planId, int $version, int $uploaderId): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('服务器未安装 ZIP 扩展');
        }

        $directory = runtime_path('practice-archives');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('归档临时目录创建失败');
        }
        $moduleName = $moduleType === 'lab' ? '实验' : '实训';
        $planName = $this->segment((string) (($snapshot['plan']['course_name'] ?? '') ?: ($snapshot['plan']['title'] ?? '开课任务')));
        $downloadName = "{$moduleName}-{$planName}-归档V{$version}.zip";
        $path = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('归档 ZIP 创建失败');
        }

        try {
            $this->addJson($zip, '01-归档清单/归档快照.json', $snapshot);
            $this->addCsv($zip, '01-归档清单/归档清单.csv', $snapshot['items'] ?? [], [
                'name' => '材料',
                'status' => '状态',
                'count' => '已完成数量',
                'required_count' => '应完成数量',
            ]);
            $this->addJson($zip, '02-开课任务/开课任务.json', $snapshot['plan'] ?? []);
            $this->addCsv($zip, '02-开课任务/任课教师.csv', $snapshot['teachers'] ?? [], [
                'teacher_name' => '教师姓名',
                'teacher_num' => '教师工号',
                'teacher_role' => '课程角色',
                'dep_id' => '学院ID',
                'profession_id' => '专业ID',
            ]);
            $this->addCsv($zip, '02-开课任务/课表安排.csv', $snapshot['schedules'] ?? [], [
                'title' => '课表名称',
                'schedule_date' => '日期',
                'start_time' => '开始时间',
                'end_time' => '结束时间',
                'location' => '地点',
                'student_count' => '学生人数',
                'status' => '状态',
            ]);
            $this->addCsv($zip, '02-开课任务/项目清单.csv', $snapshot['projects'] ?? [], [
                'id' => '项目ID',
                'title' => '项目名称',
                'teacher_id' => '负责教师ID',
                'start_date' => '开始日期',
                'end_date' => '结束日期',
                'student_count' => '学生人数',
                'status' => '状态',
            ]);
            $this->addCsv($zip, '02-开课任务/项目学生名单.csv', $snapshot['students'] ?? [], [
                'project_id' => '项目ID',
                'student_num' => '学号',
                'student_name' => '姓名',
                'teacher_id' => '负责教师ID',
                'class_id' => '班级ID',
            ]);

            $this->addMaterialText($zip, '03-教学材料/课程大纲', $snapshot['syllabus'] ?? []);
            $this->addMaterialText($zip, '03-教学材料/教案', $snapshot['lesson_plans'] ?? []);
            $this->addMaterialText($zip, '03-教学材料/课程教学反思', $snapshot['reflections'] ?? []);
            $this->addCsv($zip, '04-执行材料/签到记录.csv', $snapshot['sign_ins'] ?? [], [
                'project_id' => '项目ID',
                'student_num' => '学号',
                'student_name' => '姓名',
                'date' => '签到日期',
                'location' => '签到位置',
                'longitude' => '经度',
                'latitude' => '纬度',
                'status' => '状态',
            ]);
            $this->addReports($zip, $snapshot);
            $this->addJson($zip, '05-成绩/成绩方案.json', $snapshot['grade_rule'] ?? []);
            $this->addCsv($zip, '05-成绩/项目成绩明细.csv', $snapshot['scores'] ?? [], [
                'project_id' => '项目ID',
                'student_id' => '学生ID',
                'teacher_id' => '评分教师ID',
                'score_items' => '分项成绩',
                'score_value' => '项目成绩',
                'status' => '状态',
            ]);
            $this->addCsv($zip, '05-成绩/课程汇总成绩.csv', $snapshot['course_scores'] ?? [], [
                'student_num' => '学号',
                'student_name' => '姓名',
                'class_name' => '班级',
                'expected_project_count' => '应完成项目数',
                'accepted_project_count' => '已通过项目数',
                'preview_score' => '试算成绩',
                'final_score' => '最终成绩',
                'status' => '完成状态',
            ]);
        } finally {
            $zip->close();
        }

        if (!is_file($path) || (int) filesize($path) <= 0) {
            @unlink($path);
            throw new RuntimeException('归档 ZIP 生成失败');
        }

        return (new FileService())->storeGeneratedFile($path, [
            'category' => 'practice_archive',
            'ext' => 'zip',
            'name' => $downloadName,
            'download_name' => $downloadName,
            'uploader_id' => $uploaderId,
            'is_temporary' => false,
        ]);
    }

    /** 写入项目报告和有效附件。 */
    private function addReports(ZipArchive $zip, array $snapshot): void
    {
        $projects = [];
        foreach ($snapshot['projects'] ?? [] as $project) {
            $projects[(int) ($project['id'] ?? 0)] = (string) ($project['title'] ?? '项目');
        }
        $students = [];
        foreach ($snapshot['students'] ?? [] as $student) {
            $students[(int) ($student['student_id'] ?? 0)] = trim((string) (($student['student_num'] ?? '') . '-' . ($student['student_name'] ?? '学生')), '-');
        }
        $fileIds = [];
        foreach ($snapshot['reports'] ?? [] as $report) {
            foreach ($this->ids($report['attachment_ids'] ?? []) as $fileId) {
                $fileIds[] = $fileId;
            }
        }
        $files = [];
        foreach (FileRecord::detailsByIds($fileIds) as $file) {
            $files[(int) $file->id] = $file;
        }

        foreach ($snapshot['reports'] ?? [] as $report) {
            $projectId = (int) ($report['project_id'] ?? 0);
            $studentId = (int) ($report['student_id'] ?? 0);
            $directory = '04-执行材料/项目报告/'
                . $this->segment($projects[$projectId] ?? "项目{$projectId}") . '/'
                . $this->segment($students[$studentId] ?? "学生{$studentId}");
            $title = $this->segment((string) ($report['title'] ?? '项目报告'));
            $zip->addFromString("{$directory}/{$title}.txt", $this->textDocument(
                (string) ($report['title'] ?? '项目报告'),
                (string) ($report['content'] ?? ''),
                (string) ($report['reflection_summary'] ?? ''),
                (string) ($report['submitted_at'] ?? '')
            ));
            foreach ($this->ids($report['attachment_ids'] ?? []) as $fileId) {
                $file = $files[$fileId] ?? null;
                $absolutePath = $file ? $this->publicFilePath((string) $file->path) : null;
                if (!$absolutePath) {
                    continue;
                }
                $name = $this->segment((string) ($file->download_name ?: $file->name ?: "附件{$fileId}"));
                $zip->addFile($absolutePath, "{$directory}/附件/{$fileId}-{$name}");
            }
        }
    }

    /** 写入课程级富文本材料。 */
    private function addMaterialText(ZipArchive $zip, string $directory, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $title = (string) (($row['title'] ?? '') ?: ('材料' . ($index + 1)));
            $zip->addFromString(
                $directory . '/' . $this->segment($title) . '.txt',
                $this->textDocument($title, (string) ($row['content'] ?? ''), '', (string) ($row['updated_at'] ?? ''))
            );
        }
    }

    /** 写入 JSON 文件。 */
    private function addJson(ZipArchive $zip, string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('归档数据序列化失败');
        }
        $zip->addFromString($name, $json);
    }

    /** 写入 CSV 文件。 */
    private function addCsv(ZipArchive $zip, string $name, array $rows, array $columns): void
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('归档 CSV 创建失败');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values($columns), ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $value = $row[$key] ?? null;
                $line[] = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $value;
            }
            fputcsv($stream, $line, ',', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $zip->addFromString($name, $content === false ? '' : $content);
    }

    /** 生成富文本材料的安全纯文本版本。 */
    private function textDocument(string $title, string $content, string $reflection, string $time): string
    {
        $content = preg_replace('/<br\s*\/?>/iu', "\n", $content) ?? $content;
        $content = preg_replace('/<\/(p|div|li|tr|h[1-6])>/iu', "\n", $content) ?? $content;
        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/[\t ]+\n/u', "\n", $content) ?? $content;
        $content = preg_replace('/\n{3,}/u', "\n\n", $content) ?? $content;

        $parts = [trim($title), $time !== '' ? '归档时间：' . trim($time) : '', trim($content)];
        if ($reflection !== '') {
            $parts[] = "反思小结\n" . trim($reflection);
        }

        return "\xEF\xBB\xBF" . implode("\n\n", array_values(array_filter($parts, static fn (string $value): bool => $value !== '')));
    }

    /** 解析文件 ID。 */
    private function ids(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
    }

    /** 生成安全的 ZIP 路径片段。 */
    private function segment(string $value): string
    {
        $value = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', trim($value));
        $value = trim((string) $value, '. -');
        if ($value === '') {
            return '未命名';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 100) : substr($value, 0, 100);
    }

    /** 解析公共文件绝对路径。 */
    private function publicFilePath(string $path): ?string
    {
        $public = rtrim(str_replace('\\', '/', public_path()), '/') . '/';
        $absolute = str_starts_with($path, '/') ? $path : public_path($path);
        $real = realpath($absolute);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $normalized = str_replace('\\', '/', $real);

        return str_starts_with($normalized, $public) ? $real : null;
    }
}
