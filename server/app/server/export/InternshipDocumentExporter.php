<?php

namespace app\server\export;

use app\model\channel\InternshipArchiveRecord;
use app\model\channel\InternshipRecord;
use app\server\CurrentContext;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Throwable;

class InternshipDocumentExporter
{
    /** 生成指定档案材料 */
    public function archiveMaterial(string $materialType, array $target): array
    {
        if ($materialType === 'implementation_sheet') {
            return $this->implementationPdf((int) ($target['arrangement_id'] ?? 0));
        }
        if ($materialType === 'safety_commitment') {
            throw new RuntimeException('安全承诺书需要下载模板签署后上传定稿件');
        }

        $data = InternshipArchiveRecord::generationData($materialType, $target);
        if (!$data) {
            throw new RuntimeException('档案材料数据不存在');
        }

        return match ($materialType) {
            'plan' => $this->archivePlanWorkbook($data),
            'registration' => $this->archiveRegistrationWorkbook($data),
            'score_register' => $this->archiveScoreRegisterPdf($data),
            'syllabus', 'guide', 'teacher_work_report', 'journal', 'report',
            'graduation_report', 'graduation_appraisal' => $this->archiveWord($materialType, $data),
            default => throw new RuntimeException('该档案材料暂不支持自动生成'),
        };
    }

    /** 生成实习基地申报 Word */
    public function baseWord(int $baseId): array
    {
        $detail = InternshipRecord::baseExportDetail($baseId);
        if (!$detail) {
            throw new RuntimeException('实习基地不存在');
        }
        if (($detail['item']['base_type'] ?? '') !== 'long_term') {
            throw new RuntimeException('临时基地不使用长期基地申报书');
        }

        $template = base_path('resources/templates/internship/base-application.docx');
        if (!is_file($template)) {
            throw new RuntimeException('实习基地 Word 模板不存在');
        }

        $path = $this->tempPath('base_word');
        $escaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);
        try {
            $processor = new TemplateProcessor($template);
            $processor->cloneRowAndSetValues('staff_type', $this->staffRows($detail));
            $processor->cloneRowAndSetValues('site_no', $this->existingSiteRows($detail['existing_sites'] ?? []));
            $processor->cloneRowAndSetValues('budget_no', $this->budgetRows($detail['budgets'] ?? []));
            $processor->setValues($this->baseValues($detail));
            $processor->saveAs($path);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
        }

        return [
            'path' => $path,
            'ext' => 'docx',
            'total_rows' => count($detail['teachers'] ?? []) + count($detail['mentors'] ?? [])
                + count($detail['existing_sites'] ?? []) + count($detail['budgets'] ?? []),
        ];
    }

    /** 生成教学实习实施经费 PDF */
    public function implementationPdf(int $arrangementId): array
    {
        $detail = InternshipRecord::implementationExportDetail($arrangementId);
        if (!$detail) {
            throw new RuntimeException('实习任务不存在');
        }
        $sheet = (array) ($detail['implementation_sheet'] ?? []);
        if (!$sheet) {
            throw new RuntimeException('请先保存教学实习实施表');
        }

        $template = base_path('resources/templates/internship/implementation-sheet.html');
        if (!is_file($template)) {
            throw new RuntimeException('教学实习实施 PDF 模板不存在');
        }
        $html = file_get_contents($template);
        if ($html === false) {
            throw new RuntimeException('教学实习实施 PDF 模板读取失败');
        }

        $schedules = (array) ($detail['schedules'] ?? []);
        $expenses = (array) ($detail['expenses'] ?? []);
        $html = strtr($html, $this->implementationValues($detail, $sheet, $schedules, $expenses));
        $tempDir = sys_get_temp_dir() . '/practical_mpdf';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('PDF 临时目录创建失败');
        }
        $path = $this->tempPath('implementation_pdf');

        try {
            $pdf = new Mpdf([
                'mode' => 'zh-CN',
                'format' => 'A4',
                'default_font' => 'sun-exta',
                'tempDir' => $tempDir,
            ]);
            $pdf->autoScriptToLang = true;
            $pdf->autoLangToFont = true;
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return [
            'path' => $path,
            'ext' => 'pdf',
            'total_rows' => count($schedules) + count($expenses),
        ];
    }

    private function archivePlanWorkbook(array $data): array
    {
        $template = base_path('resources/templates/internship/archive/plan.xlsx');
        if (!is_file($template)) {
            throw new RuntimeException('实习计划模板不存在');
        }
        $plan = (array) ($data['plan'] ?? []);
        $tasks = (array) ($data['tasks'] ?? []);
        $spreadsheet = SpreadsheetIOFactory::load($template);
        $sheet = $spreadsheet->getSheet(0);
        [$scopeLabel, $scopeName] = $this->planScope($plan);
        $depName = (string) ($plan['dep_name'] ?? '');
        $categoryName = (string) ($plan['category_name'] ?? '实习');
        $sheet->setCellValue('A1', trim($depName . ' ' . $scopeName . $categoryName . '计划表'));
        $sheet->setCellValue('G2', $scopeLabel);
        $sheet->getColumnDimension('H')->setVisible(false);
        for ($row = 3; $row <= 24; $row++) {
            foreach (range('A', 'P') as $column) {
                $sheet->setCellValue($column . $row, null);
            }
        }

        $teachers = array_values(array_unique(array_filter(array_map(
            static fn (array $task): string => trim((string) ($task['teacher_name'] ?? '')),
            $tasks
        ))));
        $timeRows = array_values(array_filter(array_map(
            static fn (array $task): string => trim(implode(' 至 ', array_filter([$task['start_date'] ?? null, $task['end_date'] ?? null]))),
            $tasks
        )));
        $locations = array_values(array_unique(array_filter(array_map(
            static fn (array $task): string => trim((string) ($task['location'] ?? '')),
            $tasks
        ))));
        $values = [
            'A3' => 1,
            'B3' => $plan['course_code'] ?? '',
            'C3' => $plan['course_name'] ?? '',
            'D3' => $plan['course_category'] ?? '',
            'E3' => $depName,
            'F3' => $plan['profession_name'] ?? '',
            'G3' => $scopeName,
            'I3' => $plan['total_credit'] ?? $plan['credit'] ?? '',
            'J3' => $plan['internship_credit'] ?? $plan['credit'] ?? '',
            'K3' => $plan['total_hours'] ?? '',
            'L3' => $plan['internship_hours'] ?? '',
            'M3' => $teachers ? implode('、', $teachers) : ($plan['source_teacher'] ?? ''),
            'N3' => $timeRows ? implode('；', $timeRows) : ($plan['source_time'] ?? ''),
            'O3' => $locations ? implode('、', $locations) : ($plan['source_location'] ?? ''),
            'P3' => $plan['remark'] ?? '',
        ];
        foreach ($values as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        $path = $this->tempPath('archive_plan');
        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return ['path' => $path, 'ext' => 'xlsx', 'total_rows' => 1];
    }

    private function archiveRegistrationWorkbook(array $data): array
    {
        $template = base_path('resources/templates/internship/archive/registration.xlsx');
        if (!is_file($template)) {
            throw new RuntimeException('实习情况登记表模板不存在');
        }
        $task = (array) ($data['task'] ?? []);
        $plan = (array) ($data['plan'] ?? []);
        $students = (array) ($data['students'] ?? []);
        $reportStudents = array_fill_keys(array_map('intval', (array) ($data['report_students'] ?? [])), true);
        $signCounts = (array) ($data['sign_counts'] ?? []);
        $spreadsheet = SpreadsheetIOFactory::load($template);
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setCellValue('A1', (string) ($plan['course_name'] ?? $task['title'] ?? '实习') . '情况登记表');
        $lastRow = max(34, count($students) + 2);
        for ($row = 3; $row <= $lastRow; $row++) {
            foreach (range('A', 'K') as $column) {
                $sheet->setCellValue($column . $row, null);
            }
        }
        foreach ($students as $index => $student) {
            $row = $index + 3;
            $studentId = (int) ($student['student_id'] ?? 0);
            $values = [
                $index + 1,
                $student['student_name'] ?? '',
                $student['student_num'] ?? '',
                $student['profession_name'] ?? '',
                $plan['course_name'] ?? '',
                $task['location'] ?? $task['base_name'] ?? '',
                $this->dateRange($task['start_date'] ?? null, $task['end_date'] ?? null),
                $task['teacher_name'] ?? '',
                isset($reportStudents[$studentId]) ? '已提交' : '未提交',
                (int) ($signCounts[$studentId] ?? 0),
                isset($reportStudents[$studentId]) ? '已完成' : '进行中',
            ];
            foreach ($values as $offset => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($offset + 1) . $row, $value);
            }
        }

        $path = $this->tempPath('archive_registration');
        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return ['path' => $path, 'ext' => 'xlsx', 'total_rows' => count($students)];
    }

    private function archiveWord(string $materialType, array $data): array
    {
        $titles = [
            'syllabus' => '实习教学大纲',
            'guide' => '实习指导书',
            'teacher_work_report' => '实习指导教师工作报告',
            'journal' => '实习周（日）志',
            'report' => '实习/实训报告',
            'graduation_report' => '毕业实习报告',
            'graduation_appraisal' => '毕业实习成绩鉴定表',
        ];
        $title = $titles[$materialType] ?? '实习档案材料';
        $word = new PhpWord();
        $word->setDefaultFontName('宋体');
        $word->setDefaultFontSize(10.5);
        $section = $word->addSection([
            'marginTop' => 1000, 'marginBottom' => 1000, 'marginLeft' => 1200, 'marginRight' => 1200,
        ]);
        $section->addText((string) (CurrentContext::get('school_name') ?: '成都锦城学院'), ['bold' => true, 'size' => 15], ['alignment' => 'center']);
        $section->addText($title, ['bold' => true, 'size' => 18], ['alignment' => 'center', 'spaceAfter' => 300]);
        $this->addArchiveInfoTable($section, $data);

        if ($materialType === 'journal') {
            foreach ((array) ($data['journals'] ?? []) as $index => $journal) {
                $section->addTitle('第 ' . ($index + 1) . ' 篇  ' . ($journal['date'] ?? ''), 2);
                $section->addText((string) ($journal['title'] ?? ''), ['bold' => true]);
                $this->addArchiveSection($section, '实习地点', $journal['location'] ?? '');
                $this->addArchiveSection($section, '工作内容', $journal['work_content'] ?? $journal['content'] ?? '');
                $this->addArchiveSection($section, '收获与体会', $journal['gains'] ?? '');
                $this->addArchiveSection($section, '问题与改进', $journal['problems'] ?? '');
            }
        } else {
            $document = (array) ($data['document'] ?? []);
            if (in_array($materialType, ['syllabus', 'guide'], true)) {
                $this->addArchiveSection($section, '正文', $document['content'] ?? '');
                $this->addFormSections($section, (array) ($document['form_data'] ?? []));
            } elseif ($materialType === 'teacher_work_report') {
                $this->addArchiveSection($section, '实习过程总结', $document['summary'] ?? '');
                $this->addArchiveSection($section, '问题分析', $document['problems'] ?? '');
                $this->addArchiveSection($section, '反思与建议', $document['suggestions'] ?? '');
            } elseif (in_array($materialType, ['report', 'graduation_report'], true)) {
                $this->addArchiveSection($section, '报告标题', $document['title'] ?? '');
                $this->addArchiveSection($section, '报告正文', $document['content'] ?? '');
                $this->addFormSections($section, (array) ($document['form_data'] ?? []));
            } elseif ($materialType === 'graduation_appraisal') {
                $this->addArchiveSection($section, '自我小结', $document['form_data']['self_summary'] ?? '');
                $this->addArchiveSection($section, '企业评语', $document['enterprise_comment'] ?? '');
                $this->addArchiveSection($section, '校内指导教师评语', $document['school_comment'] ?? '');
                $this->addArchiveSection($section, '过程管理得分（50分）', $this->decimal($document['process_score'] ?? null));
                $this->addArchiveSection($section, '实习单位评分（30分）', $this->decimal($document['enterprise_score'] ?? null));
                $this->addArchiveSection($section, '校内指导教师评分（20分）', $this->decimal($document['school_score'] ?? null));
                $this->addArchiveSection($section, '综合成绩', ($document['final_score'] ?? '') . '  ' . ($document['grade_level'] ?? ''));
                $this->addFormSections($section, (array) ($document['form_data'] ?? []));
            }
        }

        $path = $this->tempPath('archive_word');
        WordIOFactory::createWriter($word, 'Word2007')->save($path);
        return ['path' => $path, 'ext' => 'docx', 'total_rows' => $materialType === 'journal' ? count((array) ($data['journals'] ?? [])) : 1];
    }

    private function archiveScoreRegisterPdf(array $data): array
    {
        $plan = (array) ($data['plan'] ?? []);
        $task = (array) ($data['task'] ?? []);
        $class = (array) ($data['class'] ?? []);
        $scores = (array) ($data['scores'] ?? []);
        [$scopeLabel, $scopeName] = $this->planScope($plan);
        $rows = '';
        foreach ($scores as $index => $score) {
            $rows .= '<tr><td>' . ($index + 1) . '</td><td>' . $this->h($score['student_name'] ?? '')
                . '</td><td>' . $this->h($score['student_num'] ?? '') . '</td><td>' . $this->h($score['sign_in_score'] ?? '')
                . '</td><td>' . $this->h($score['journal_score'] ?? '') . '</td><td>' . $this->h($score['report_score'] ?? '')
                . '</td><td>' . $this->h($score['enterprise_score'] ?? '') . '</td><td>' . $this->h($score['final_score'] ?? '')
                . '</td><td>' . $this->h($score['comment'] ?? '') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9">暂无成绩数据</td></tr>';
        }
        $html = '<style>body{font-family:sun-exta;font-size:11pt;color:#111}h1{text-align:center;font-size:18pt}'
            . '.meta{margin:12px 0}.meta span{display:inline-block;margin-right:28px}table{width:100%;border-collapse:collapse}'
            . 'th,td{border:1px solid #222;padding:6px;text-align:center}th{background:#f2f2f2}</style>'
            . '<h1>' . $this->h(CurrentContext::get('school_name') ?: '成都锦城学院') . '实习成绩登记表</h1>'
            . '<div class="meta"><span>' . $this->h($scopeLabel) . '：' . $this->h($scopeName) . '</span><span>课程：' . $this->h($plan['course_name'] ?? '')
            . '</span><span>任务：' . $this->h($task['title'] ?? '') . '</span><span>班级：' . $this->h($class['class_name'] ?? '') . '</span></div>'
            . '<table><thead><tr><th>序号</th><th>姓名</th><th>学号</th><th>签到</th><th>日志</th><th>报告</th><th>企业</th><th>总评</th><th>评语</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
        $tempDir = sys_get_temp_dir() . '/practical_mpdf';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('PDF 临时目录创建失败');
        }
        $path = $this->tempPath('archive_score');
        $pdf = new Mpdf(['mode' => 'zh-CN', 'format' => 'A4-L', 'default_font' => 'sun-exta', 'tempDir' => $tempDir]);
        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->WriteHTML($html);
        $pdf->Output($path, Destination::FILE);

        return ['path' => $path, 'ext' => 'pdf', 'total_rows' => count($scores)];
    }

    private function addArchiveInfoTable(object $section, array $data): void
    {
        $plan = (array) ($data['plan'] ?? []);
        $task = (array) ($data['task'] ?? []);
        $student = (array) ($data['student'] ?? []);
        [$scopeLabel, $scopeName] = $this->planScope($plan);
        $rows = array_filter([
            [$scopeLabel, $scopeName ?: ($student['grade_name'] ?? '')],
            ['学院', $student['dep_name'] ?? $plan['dep_name'] ?? ''],
            ['专业', $student['profession_name'] ?? $plan['profession_name'] ?? ''],
            ['班级', $student['class_name'] ?? ''],
            ['课程', trim(implode(' ', array_filter([$plan['course_code'] ?? '', $plan['course_name'] ?? ''])))],
            ['实习任务', $task['title'] ?? $task['name'] ?? ''],
            ['任务时间', $this->dateRange($task['start_date'] ?? null, $task['end_date'] ?? null)],
            ['实习地点', $task['location'] ?? ''],
            ['指导教师', $task['teacher_name'] ?? ''],
            ['学生', trim(implode(' ', array_filter([$student['student_name'] ?? $student['name'] ?? '', $student['student_num'] ?? ''])))],
        ], static fn (array $row): bool => trim((string) $row[1]) !== '');
        if (!$rows) {
            return;
        }

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'B8C0CC', 'cellMargin' => 100]);
        foreach ($rows as [$label, $value]) {
            $table->addRow();
            $table->addCell(1800, ['bgColor' => 'F3F5F7'])->addText((string) $label, ['bold' => true]);
            $table->addCell(7200)->addText($this->plainText($value));
        }
        $section->addTextBreak();
    }

    private function addArchiveSection(object $section, string $title, mixed $content): void
    {
        $text = $this->plainText($content);
        if ($text === '') {
            return;
        }
        $section->addTitle($title, 2);
        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $section->addText($paragraph === '' ? ' ' : $paragraph, [], ['lineHeight' => 1.5, 'spaceAfter' => 120]);
        }
    }

    private function addFormSections(object $section, array $values, string $prefix = ''): void
    {
        foreach ($values as $key => $value) {
            $label = trim($prefix . $this->formLabel((string) $key));
            if (is_array($value)) {
                $this->addFormSections($section, $value, $label . ' / ');
                continue;
            }
            $this->addArchiveSection($section, $label, $value);
        }
    }

    private function formLabel(string $key): string
    {
        return [
            'purpose' => '目的与要求', 'content' => '主要内容', 'process' => '过程与进度',
            'summary' => '成果与总结', 'gains' => '收获与体会', 'problems' => '问题与改进',
            'suggestions' => '建议', 'company_profile' => '实习单位简介', 'position' => '实习岗位',
            'self_summary' => '自我小结', 'enterprise_comment' => '企业评语', 'school_comment' => '学校评语',
        ][$key] ?? str_replace('_', ' ', $key);
    }

    private function dateRange(mixed $start, mixed $end): string
    {
        $values = array_values(array_filter([(string) ($start ?? ''), (string) ($end ?? '')]));
        return implode(' 至 ', $values);
    }

    private function baseValues(array $detail): array
    {
        $item = (array) ($detail['item'] ?? []);
        $manager = (array) ($detail['manager'] ?? []);
        $profile = (array) ($detail['company_profile'] ?? []);
        $professionNames = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['profession_name'] ?? '')),
            (array) ($detail['professions'] ?? [])
        )));
        $createdAt = strtotime((string) ($item['created_at'] ?? '')) ?: time();

        return [
            'department_name' => (string) ($item['dep_name'] ?? ''),
            'company_name' => (string) ($profile['company_name'] ?? $item['company_name'] ?? ''),
            'base_name' => (string) ($item['name'] ?? ''),
            'manager_name' => (string) ($manager['name'] ?? $item['manager_name'] ?? ''),
            'manager_phone' => (string) ($manager['phone'] ?? $item['manager_phone'] ?? ''),
            'application_date' => date('Y年 n月', $createdAt),
            'address' => (string) ($item['address'] ?? ''),
            'area' => $this->decimal($item['area'] ?? null, '平方米'),
            'profession_names' => implode('、', $professionNames),
            'annual_student_count' => (string) ($item['annual_student_count'] ?? ''),
            'current_student_count' => (string) ($item['current_student_count'] ?? ''),
            'service_courses' => $this->plainText($item['service_courses'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'manager_detail_name' => (string) ($manager['name'] ?? ''),
            'manager_gender' => (string) ($manager['gender'] ?? ''),
            'manager_birth_date' => (string) ($manager['birth_date'] ?? ''),
            'manager_title' => (string) ($manager['title'] ?? ''),
            'manager_education' => (string) ($manager['education'] ?? ''),
            'manager_detail_phone' => (string) ($manager['phone'] ?? ''),
            'manager_duties' => $this->plainText($manager['duties'] ?? ''),
            'profile_company_name' => (string) ($profile['company_name'] ?? $item['company_name'] ?? ''),
            'registered_capital' => (string) ($profile['registered_capital'] ?? ''),
            'main_business' => $this->plainText($profile['main_business'] ?? ''),
            'employee_count' => (string) ($profile['employee_count'] ?? ''),
            'annual_intern_count' => (string) ($profile['annual_intern_count'] ?? ''),
            'senior_title_count' => (string) ($profile['senior_title_count'] ?? ''),
            'construction_plan' => $this->plainText($detail['construction']['content'] ?? ''),
        ];
    }

    private function staffRows(array $detail): array
    {
        $rows = [];
        foreach ([['teachers', '校内教师'], ['mentors', '企业导师']] as [$key, $type]) {
            foreach ((array) ($detail[$key] ?? []) as $person) {
                $rows[] = [
                    'staff_type' => $type,
                    'staff_name' => (string) ($person['name'] ?? ''),
                    'staff_gender' => (string) ($person['gender'] ?? ''),
                    'staff_birth_date' => (string) ($person['birth_date'] ?? ''),
                    'staff_title' => (string) ($person['title'] ?? ''),
                    'staff_education' => (string) ($person['education'] ?? ''),
                    'staff_duties' => $this->plainText($person['duties'] ?? ''),
                    'staff_phone' => (string) ($person['phone'] ?? ''),
                ];
            }
        }

        return $rows ?: [[
            'staff_type' => '', 'staff_name' => '', 'staff_gender' => '', 'staff_birth_date' => '',
            'staff_title' => '', 'staff_education' => '', 'staff_duties' => '', 'staff_phone' => '',
        ]];
    }

    private function existingSiteRows(array $sites): array
    {
        $rows = [];
        foreach ($sites as $index => $site) {
            $rows[] = [
                'site_no' => (string) ($index + 1),
                'site_name' => (string) ($site['site_name'] ?? ''),
                'site_cooperation' => $this->plainText($site['cooperation'] ?? ''),
            ];
        }

        return $rows ?: [['site_no' => '1', 'site_name' => '', 'site_cooperation' => '']];
    }

    private function budgetRows(array $budgets): array
    {
        $rows = [];
        foreach ($budgets as $index => $budget) {
            $rows[] = [
                'budget_no' => (string) ($index + 1),
                'budget_item_name' => (string) ($budget['item_name'] ?? ''),
                'budget_content' => $this->plainText($budget['content'] ?? ''),
                'budget_amount' => $this->money($budget['amount'] ?? null),
                'budget_remark' => $this->plainText($budget['remark'] ?? ''),
            ];
        }

        return $rows ?: [[
            'budget_no' => '1', 'budget_item_name' => '', 'budget_content' => '',
            'budget_amount' => '', 'budget_remark' => '',
        ]];
    }

    private function implementationValues(array $detail, array $sheet, array $schedules, array $expenses): array
    {
        $task = (array) ($detail['task'] ?? []);
        [$scopeLabel, $scopeName] = $this->planScope($task);
        $totalAmount = round(array_sum(array_map(
            static fn (array $row): float => (float) ($row['amount'] ?? 0),
            $expenses
        )), 2);
        $attachmentNames = InternshipRecord::fileNamesByIds((array) ($sheet['attachment_ids'] ?? []));

        return [
            '{{school_name}}' => $this->h(CurrentContext::get('school_name') ?: '成都锦城学院'),
            '{{approval_no}}' => $this->h($sheet['approval_no'] ?? ''),
            '{{applicant_name}}' => $this->h($sheet['applicant_name'] ?? ''),
            '{{applicant_department}}' => $this->h($sheet['applicant_department'] ?? ''),
            '{{submitted_at}}' => $this->h($this->dateTime($sheet['submitted_at'] ?? null)),
            '{{status_text}}' => $this->h($this->statusText((string) ($sheet['status'] ?? ''))),
            '{{department_name}}' => $this->h($task['dep_name'] ?? $sheet['applicant_department'] ?? ''),
            '{{scope_label}}' => $this->h($scopeLabel),
            '{{scope_name}}' => $this->h($scopeName ?: ($sheet['grade_name'] ?? '')),
            '{{grade_name}}' => $this->h($scopeName ?: ($sheet['grade_name'] ?? '')),
            '{{course_name}}' => $this->h($sheet['course_name'] ?? $task['course_name'] ?? ''),
            '{{course_type}}' => $this->h($sheet['course_type'] ?? ''),
            '{{detail_content}}' => $this->htmlText($sheet['detail_content'] ?? ''),
            '{{credit}}' => $this->h($this->decimal($sheet['credit'] ?? $task['credit'] ?? null)),
            '{{practice_type}}' => $this->h($this->practiceTypeText((string) ($sheet['practice_type'] ?? $task['type'] ?? ''))),
            '{{internship_mode}}' => $this->h($this->internshipModeText((string) ($sheet['internship_mode'] ?? ''))),
            '{{organize_mode}}' => $this->h($this->organizeModeText((string) ($sheet['organize_mode'] ?? $task['organize_mode'] ?? ''))),
            '{{schedule_rows}}' => $this->scheduleRowsHtml($schedules),
            '{{total_people}}' => $this->h((string) array_sum(array_map(static fn (array $row): int => (int) ($row['people_count'] ?? 0), $schedules))),
            '{{expense_rows}}' => $this->expenseRowsHtml($expenses),
            '{{total_amount}}' => $this->h($this->money($totalAmount)),
            '{{total_amount_upper}}' => $this->h($this->moneyUpper($totalAmount)),
            '{{attachments}}' => $this->h($attachmentNames ? implode('、', $attachmentNames) : '无'),
            '{{remark}}' => $this->htmlText($sheet['remark'] ?? ''),
        ];
    }

    /** 返回计划归属字段名称和值 */
    private function planScope(array $plan): array
    {
        if (($plan['scope_type'] ?? '') === 'cohort') {
            return ['毕业届次', (string) ($plan['cohort_name'] ?? '')];
        }

        return ['年级', (string) ($plan['grade_name'] ?? '')];
    }

    private function scheduleRowsHtml(array $rows): string
    {
        if (!$rows) {
            return '<tr><td class="center">1</td><td colspan="8"></td></tr>';
        }

        $html = '';
        foreach ($rows as $index => $row) {
            $html .= '<tr>'
                . '<td class="center">' . ($index + 1) . '</td>'
                . '<td>' . $this->h($row['profession_name'] ?? '') . '</td>'
                . '<td>' . $this->h($row['grade_name'] ?? '') . '</td>'
                . '<td class="center">' . $this->h($row['people_count'] ?? 0) . '</td>'
                . '<td>' . $this->h($row['week_text'] ?? '') . '</td>'
                . '<td>' . $this->h($row['weekday_text'] ?? '') . '</td>'
                . '<td>' . $this->h($row['location'] ?? '') . '</td>'
                . '<td>' . $this->h($row['time_text'] ?? '') . '</td>'
                . '<td>' . $this->h($row['teacher_name'] ?? '') . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function expenseRowsHtml(array $rows): string
    {
        if (!$rows) {
            return '<tr><td class="center">1</td><td colspan="5"></td></tr>';
        }

        $html = '';
        foreach ($rows as $index => $row) {
            $amount = $row['amount'] ?? null;
            $html .= '<tr>'
                . '<td class="center">' . ($index + 1) . '</td>'
                . '<td>' . $this->h($row['item_name'] ?? '') . '</td>'
                . '<td>' . $this->htmlText($row['content'] ?? '') . '</td>'
                . '<td class="amount">' . $this->h($this->money($amount)) . '</td>'
                . '<td>' . $this->h($amount === null || $amount === '' ? '' : $this->moneyUpper((float) $amount)) . '</td>'
                . '<td>' . $this->htmlText($row['remark'] ?? '') . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function moneyUpper(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $integer = (int) floor($amount);
        $fraction = (int) round(($amount - $integer) * 100);
        if ($fraction >= 100) {
            $integer++;
            $fraction -= 100;
        }
        $jiao = intdiv($fraction, 10);
        $fen = $fraction % 10;
        $text = $this->integerUpper($integer) . '元';
        if ($jiao === 0 && $fen === 0) {
            return $text . '整';
        }
        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        if ($jiao > 0) {
            $text .= $digits[$jiao] . '角';
        } elseif ($fen > 0) {
            $text .= '零';
        }
        if ($fen > 0) {
            $text .= $digits[$fen] . '分';
        }

        return $text;
    }

    private function integerUpper(int $number): string
    {
        if ($number === 0) {
            return '零';
        }
        $groups = [];
        while ($number > 0) {
            $groups[] = $number % 10000;
            $number = intdiv($number, 10000);
        }
        $groupUnits = ['', '万', '亿', '兆'];
        $result = '';
        $zero = false;
        for ($index = count($groups) - 1; $index >= 0; $index--) {
            $section = $groups[$index];
            if ($section === 0) {
                $zero = $result !== '';
                continue;
            }
            if ($result !== '' && ($zero || $section < 1000)) {
                $result .= '零';
            }
            $result .= $this->sectionUpper($section) . ($groupUnits[$index] ?? '');
            $zero = false;
        }

        return $result;
    }

    private function sectionUpper(int $number): string
    {
        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $units = ['', '拾', '佰', '仟'];
        $result = '';
        $zero = false;
        for ($position = 0; $number > 0; $position++) {
            $digit = $number % 10;
            if ($digit === 0) {
                $zero = $result !== '';
            } else {
                if ($zero) {
                    $result = '零' . $result;
                    $zero = false;
                }
                $result = $digits[$digit] . $units[$position] . $result;
            }
            $number = intdiv($number, 10);
        }

        return $result;
    }

    private function practiceTypeText(string $value): string
    {
        return [
            'cognition_internal' => '认知实习（校内）',
            'cognition_external' => '认知实习（校外）',
            'major_internal' => '专业实习（校内）',
            'major_external' => '专业实习（校外）',
            'production' => '生产实习',
            'graduation' => '毕业实习',
        ][$value] ?? $value;
    }

    private function internshipModeText(string $value): string
    {
        return ['onsite' => '现场实习', 'online' => '线上实习', 'hybrid' => '线上线下结合'][$value] ?? $value;
    }

    private function organizeModeText(string $value): string
    {
        return ['centralized' => '集中实习', 'distributed' => '分散实习', 'autonomous' => '自主实习'][$value] ?? $value;
    }

    private function statusText(string $value): string
    {
        return [
            'draft' => '草稿', 'wait' => '待审核', 'accept' => '已通过',
            'modify' => '需修改', 'confirmed' => '已确认',
        ][$value] ?? $value;
    }

    private function dateTime(mixed $value): string
    {
        $timestamp = strtotime((string) ($value ?? ''));
        return $timestamp ? date('Y/m/d H:i', $timestamp) : '';
    }

    private function decimal(mixed $value, string $suffix = ''): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        $text = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        return $text . $suffix;
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '';
    }

    private function plainText(mixed $value): string
    {
        $text = (string) ($value ?? '');
        $text = preg_replace('/<\s*br\s*\/?>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|li|h[1-6])>/iu', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function htmlText(mixed $value): string
    {
        return nl2br($this->h($this->plainText($value)), false);
    }

    private function h(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function tempPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix . '_');
        if ($path === false) {
            throw new RuntimeException('无法创建导出临时文件');
        }

        return $path;
    }
}
