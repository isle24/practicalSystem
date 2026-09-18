<?php

namespace app\server\edu;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Webman\Http\UploadFile;

class EduTemplateService
{
    public const MAX_FILE_SIZE = 524288000;

    private const DEFINITIONS = [
        'student' => [
            'file_name' => '在校学生导入模板.xlsx',
            'headers' => ['学号', '姓名', '性别', '年级', '学院', '专业代码', '专业名称', '班号', '班级', '学籍状态', '报到注册状态', '是否在校', '有无学籍', '学历层次', '学制', '学信专业代码', '学信专业名称', '学生类别', '培养层次', '出生日期', '入学日期', '证件类型', '证件号', '国籍/地区', '籍贯', '民族', '政治面貌', '出生地', '生源地', '家庭地址', '考生号', '来源省', '学习年限', '学生类型', '辅导员', '入学方式', '入学总分', '考生类别', '毕业中学', '备注', '电子邮箱', '手机号码', '毕业年级', '考生来源'],
            'required' => ['学号', '姓名', '年级', '学院', '专业代码', '专业名称', '班号', '班级'],
            'row_limit' => 500000,
        ],
        'teaching_plan' => [
            'file_name' => '开课计划导入模板.xlsx',
            'headers' => ['学年', '学期', '年级', '课程代码', '课程名称', '课程不落实', '开课部门代码', '开课部门', '是否落实', '学分', '周学时', '总学时', '理论总学时', '实验总学时', '实践总学时', '其他总学时', '学分要求节点', '校区', '人数', '是否预选课程', '预选课程状态', '专业学院名称', '专业代码', '专业名称', '专业方向', '考试形式', '考试方式', '考核方式', '课程类别', '课程性质', '起止周', '大类名称', '主修专业课程', '是否学位课程', '准入课程', '准出课程', '荣誉课程', '专业核心课程标记', '公共基础课程标记', '辅修标记', '辅修学分', '二专业标记', '二学位标记', '备注', '班数'],
            'required' => ['学年', '学期', '年级', '课程代码', '课程名称', '专业代码'],
            'row_limit' => 200000,
        ],
        'course_offering' => [
            'file_name' => '开课情况导入模板.xlsx',
            'headers' => ['学年', '学期', '学分', '课程代码', '开课学院', '课程名称', '专业核心课程', '公共基础课程', '学位课程', '是否需要教材', '教材类型', '校区', '场地类别', '场地二级类别', '任务落实人', '任务落实时间', '教学班', '是否主教学班', '是否合班标记', '主教学班', '教学班组成', '教学班人数', '教学班容量', '选课人数', '教职工信息', '教师部门', '教工号', '教师名称', '职务类别', '职称级别', '任务序号', '课序号', '考试周', '教师性别', '教职工类别', '辅讲教师', '教师出生日期', '职称', '成绩录入教师', '周学时', '起始结束周', '起始周', '结束周', '排课起始结束周', '排课起始周', '排课结束周', '课程结束时间', '学时类型', '任务总学时', '已排课学时', '课程周学时', '课程总学时', '面向对象', '限制对象', '备注', '选课备注', '实践周次', '课程上课学时总学时', '课程实践环节总学时', '课程实践学时总学时', '课程类别', '二级类别', '课程类型', '课程性质', '课程归属', '开课状态', '选课标记', '授课方式', '上课时间', '教学地点', '专业方向', '正常选课人数', '非重修选课人数', '考试形式', '考试方式', '考核方式', '教学模式', '是否排课', '重修跟班人数', '合并数量', '授课对象所属学院', '年级组成', '专业组成', '学信专业组成', '期末考试时间', '期末考试地点', '是否外聘', '是否主讲', '教学班ID', '开课学院代码', '开课类型'],
            'required' => ['学年', '学期', '课程代码', '课程名称', '教学班ID'],
            'row_limit' => 200000,
        ],
    ];

    public function template(string $type): array
    {
        $definition = self::definition($type);
        $directory = runtime_path() . DIRECTORY_SEPARATOR . 'edu-templates';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('教务模板目录创建失败');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $type . '-import-template.xlsx';
        if (!is_file($path)) {
            $this->writeTemplate($path, $definition['headers']);
        }

        return ['path' => $path, 'download_name' => $definition['file_name'], 'ext' => 'xlsx'];
    }

    public function validateUpload(UploadFile $file, string $type): array
    {
        self::definition($type);
        $extension = strtolower((string) $file->getUploadExtension());
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new InvalidArgumentException('仅支持 xls、xlsx 文件');
        }
        if ((int) $file->getSize() <= 0 || (int) $file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('文件大小必须在 1B 至 500MB 之间');
        }
        $path = $file->getPathname();
        if (!is_file($path)) {
            throw new InvalidArgumentException('上传文件不存在');
        }
        (new EduSpreadsheetReader())->assertSafeArchive($path, $extension);
        $header = (new EduSpreadsheetReader())->readHeader($path, $type, $extension);

        return [
            'path' => $path,
            'extension' => $extension,
            'size' => (int) $file->getSize(),
            'headers' => $header['headers'],
            'column_count' => count($header['headers']),
        ];
    }

    public function definitionFor(string $type): array
    {
        return self::definition($type);
    }

    private function writeTemplate(string $path, array $headers): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('sheet1');
        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $cell = $sheet->getCell($column . '1');
            $cell->setValueExplicit($header, DataType::TYPE_STRING);
            $cell->getStyle()->getFont()->setBold(true);
            $cell->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF7');
            $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getColumnDimension($column)->setWidth(min(24, max(12, mb_strwidth($header, 'UTF-8') + 4)));
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('填写说明');
        $guide->fromArray([
            ['项目', '说明'],
            ['工作表', '仅填写 sheet1，系统忽略本页'],
            ['表头', '不得修改名称、顺序或删除字段'],
            ['日期', '使用 YYYY-MM-DD 或教务系统原始日期格式'],
            ['多值字段', '教师使用英文逗号，教学班组成使用英文分号'],
            ['导入', '上传后先校验和预览，确认后才发布'],
        ]);
        $guide->getStyle('A1:B1')->getFont()->setBold(true);
        $guide->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF7');
        $guide->getColumnDimension('A')->setWidth(18);
        $guide->getColumnDimension('B')->setWidth(80);
        $guide->getStyle('B1:B20')->getAlignment()->setWrapText(true);

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private static function definition(string $type): array
    {
        if (!isset(self::DEFINITIONS[$type])) {
            throw new InvalidArgumentException('教务模板类型无效');
        }
        return self::DEFINITIONS[$type];
    }
}
