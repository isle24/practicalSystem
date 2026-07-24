<?php

namespace app\server\export;

use app\model\channel\InternshipRecord;
use app\server\CurrentContext;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Throwable;

class InternshipDocumentExporter
{
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
            '{{grade_name}}' => $this->h($sheet['grade_name'] ?? $task['grade_name'] ?? ''),
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
