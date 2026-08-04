<?php

namespace app\server\socialpractice;

use app\model\channel\SocialPracticeRecord;
use app\model\channel\TeacherSyncRecord;
use app\server\CurrentContext;
use app\server\file\FileService;
use app\server\security\SecretCipher;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use support\Redis;
use support\Request;
use Webman\Http\UploadFile;

class SocialPracticePlanSourceService
{
    private const MAX_ROWS = 500;
    private const MAX_FILE_SIZE = 10485760;
    private const SIGNATURE_WINDOW = 300;
    private const HEADERS = [
        'source_key' => ['来源编号', '计划编号', 'source_key'],
        'course_code' => ['课程代码', '课程编号', 'course_code'],
        'title' => ['计划名称', '课程名称', 'title', 'course_name'],
        'grade_name' => ['年级', '年级名称', 'grade_name'],
        'department_name' => ['学院', '组织学院', '学院名称', 'department_name'],
        'profession_name' => ['专业', '专业名称', 'profession_name'],
        'class_name' => ['班级', '班级名称', 'class_name'],
        'scope_type' => ['适用范围', '范围类型', 'scope_type'],
        'credit' => ['学分', 'credit'],
        'participation_mode' => ['参与方式', 'participation_mode'],
        'teacher_match_mode' => ['教师匹配方式', 'teacher_match_mode'],
        'register_start_at' => ['报名开始', 'register_start_at'],
        'register_end_at' => ['报名截止', 'register_end_at'],
        'practice_start_at' => ['实践开始', 'practice_start_at'],
        'practice_end_at' => ['实践结束', 'practice_end_at'],
        'result_deadline_at' => ['成果截止', 'result_deadline_at'],
        'score_deadline_at' => ['成绩截止', 'score_deadline_at'],
        'description' => ['计划说明', '说明', 'description'],
    ];

    /** 返回社会实践计划 Excel 导入模板。 */
    public function template(): array
    {
        $directory = rtrim(public_path(), DIRECTORY_SEPARATOR) . '/templates/social-practice';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('模板目录创建失败');
        }
        $fileName = 'social-practice-plan-import-template.xlsx';
        $path = $directory . '/' . $fileName;
        if (!is_file($path)) {
            $this->writeTemplate($path);
        }

        return [
            'url' => '/templates/social-practice/' . $fileName,
            'download_name' => '社会实践计划导入模板.xlsx',
            'version' => '2026.08',
        ];
    }

    /** 解析社会实践计划 Excel 并返回导入预览。 */
    public function preview(Request $request, array $scope): array
    {
        $file = $this->excelFile($request);
        $rows = $this->readRows($file->getPathname());
        $items = array_map(fn (array $row): array => $this->previewRow($row, $scope), $rows);
        $fileInfo = (new FileService())->upload($request, [
            'allowed_extensions' => ['xls', 'xlsx'],
            'max_size' => self::MAX_FILE_SIZE,
            'require_md5' => false,
            'category' => 'social_practice_plan_import',
            'is_temporary' => false,
        ]);

        return [
            'file' => $fileInfo,
            'items' => $items,
            'summary' => [
                'source_rows' => count($items),
                'error_rows' => count(array_filter($items, static fn (array $item): bool => !empty($item['errors']))),
                'duplicate_rows' => count(array_filter($items, static fn (array $item): bool => !empty($item['duplicate']))),
            ],
        ];
    }

    /** 将确认后的 Excel 预览行写入社会实践计划草稿。 */
    public function confirm(Request $request, array $scope, int $accountId): array
    {
        $fileId = $this->positiveInt($request->input('import_file_id'));
        $file = (new FileService())->info($fileId);
        if (($file['category'] ?? '') !== 'social_practice_plan_import' || (int) ($file['uploader_id'] ?? 0) !== $accountId) {
            throw new RuntimeException('导入文件不存在或无权使用', 40301);
        }
        $rows = $request->input('rows');
        if (!is_array($rows) || !array_is_list($rows) || !$rows) {
            throw new InvalidArgumentException('没有待确认的计划数据');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('单次最多确认 500 条计划');
        }

        return $this->processRows($rows, 'excel', $accountId, $scope);
    }

    /** 验证开放应用签名并接收教务社会实践计划。 */
    public function push(Request $request): array
    {
        $rawBody = (string) $request->rawBody();
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('请求体必须为有效 JSON');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('请求体根节点必须为 JSON object');
        }

        $appId = $this->requiredHeader($request, 'x-app-id', 120);
        $timestamp = $this->requiredHeader($request, 'x-timestamp', 20);
        $nonce = $this->requiredHeader($request, 'x-nonce', 120);
        $signature = strtolower($this->requiredHeader($request, 'x-signature', 128));
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_WINDOW) {
            throw new RuntimeException('请求时间戳无效或已过期', 401);
        }
        $application = TeacherSyncRecord::applicationByAppId($appId);
        if (!$application) {
            throw new RuntimeException('同步应用不存在或已停用', 401);
        }
        $this->assertAllowedIp((array) ($application['allowed_ips'] ?? []), (string) $request->getRealIp());
        $secret = (new SecretCipher())->decrypt((string) ($application['app_secret'] ?? ''));
        $signingText = $appId . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $rawBody);
        if (!hash_equals(hash_hmac('sha256', $signingText, $secret), $signature)) {
            throw new RuntimeException('同步接口签名无效', 401);
        }

        $nonceKey = 'social_practice_sync:nonce:'
            . (CurrentContext::schoolDatabaseId() ?: CurrentContext::schoolConnection())
            . ':' . hash('sha256', $appId . "\n" . $nonce);
        if (!Redis::set($nonceKey, '1', 'EX', self::SIGNATURE_WINDOW, 'NX')) {
            throw new RuntimeException('同步请求 nonce 已使用', 409);
        }

        $requestId = trim((string) ($payload['request_id'] ?? ''));
        if ($requestId === '' || mb_strlen($requestId) > 120 || !preg_match('/^[A-Za-z0-9._:-]+$/', $requestId)) {
            throw new InvalidArgumentException('request_id 格式无效');
        }
        $plans = $payload['plans'] ?? null;
        if (!is_array($plans) || !array_is_list($plans) || count($plans) > self::MAX_ROWS) {
            throw new InvalidArgumentException('plans 必须为不超过 500 条的 JSON 数组');
        }

        $result = $this->processRows($plans, 'edu_system', null, ['role_type' => 'school_admin']);
        $result['request_id'] = $requestId;
        TeacherSyncRecord::touchApplication((int) $application['id'], $this->now());
        return $result;
    }

    /** 批量校验并写入计划草稿。 */
    private function processRows(array $rows, string $sourceType, ?int $accountId, array $scope): array
    {
        $result = ['total' => count($rows), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];
        foreach ($rows as $index => $row) {
            try {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('计划数据必须为对象');
                }
                $normalized = $this->normalizeRow($row, $sourceType, $accountId, $scope, $index + 1);
                $saved = SocialPracticeRecord::upsertImportedPlan(
                    $normalized['values'],
                    [$normalized['scope']],
                    $this->defaultRequirements(),
                    $this->defaultScoreRules(),
                    $this->now()
                );
                $state = (string) ($saved['result'] ?? 'skipped');
                $result[$state] = (int) ($result[$state] ?? 0) + 1;
                $result['results'][] = array_merge(['row_number' => (int) ($row['row_number'] ?? ($index + 1))], $saved);
                if (in_array($state, ['created', 'updated'], true)) {
                    SocialPracticeRecord::insertActivityRecording(
                        'social_practice_plan',
                        (int) $saved['id'],
                        $accountId,
                        $sourceType === 'excel' ? 'excel_import' : 'edu_sync',
                        $sourceType === 'excel' ? 'Excel 导入计划草稿' : '教务系统同步计划草稿',
                        $this->now()
                    );
                }
            } catch (\Throwable $exception) {
                $result['failed']++;
                $result['results'][] = [
                    'row_number' => (int) (is_array($row) ? ($row['row_number'] ?? ($index + 1)) : ($index + 1)),
                    'result' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }
        return $result;
    }

    /** 规范化导入或同步的单条计划。 */
    private function normalizeRow(array $row, string $sourceType, ?int $accountId, array $scope, int $index): array
    {
        $title = $this->requiredText($row['title'] ?? $row['course_name'] ?? '', '计划名称', 180);
        $gradeName = $this->requiredText($row['grade_name'] ?? '', '年级', 80);
        $departmentName = $this->requiredText($row['department_name'] ?? $row['dep_name'] ?? '', '学院', 180);
        $professionName = $this->text($row['profession_name'] ?? '', 180);
        $className = $this->text($row['class_name'] ?? '', 180);
        $academic = SocialPracticeRecord::academicOptionByNames($gradeName, $departmentName, $professionName, $className);
        if (!$academic) {
            throw new InvalidArgumentException('年级、学院、专业或班级无法匹配');
        }
        $this->assertScopeAccess($scope, (int) $academic['department']['dep_id'], (int) ($academic['profession']['profession_id'] ?? 0));

        $scopeType = $this->text($row['scope_type'] ?? '', 40);
        $scopeType = match ($scopeType) {
            '', '自动' => $className !== '' ? 'class' : ($professionName !== '' ? 'profession' : 'college'),
            '全校', 'school' => 'school',
            '学院', 'college' => 'college',
            '专业', 'profession' => 'profession',
            '班级', 'class' => 'class',
            default => throw new InvalidArgumentException('适用范围仅支持全校、学院、专业或班级'),
        };
        if ($scopeType === 'profession' && !$academic['profession']) {
            throw new InvalidArgumentException('专业范围必须填写专业');
        }
        if ($scopeType === 'class' && !$academic['class']) {
            throw new InvalidArgumentException('班级范围必须填写班级');
        }

        $sourceKey = $this->text($row['source_key'] ?? '', 180);
        if ($sourceKey === '') {
            $sourceKey = ($sourceType === 'excel' ? 'excel:' : 'edu:') . sha1(implode('|', [$gradeName, $departmentName, $professionName, $className, $title]));
        }
        $participationMode = $this->enumValue($row['participation_mode'] ?? 'mandatory', ['mandatory', 'voluntary'], ['必修' => 'mandatory', '自愿' => 'voluntary'], '参与方式');
        $teacherMatchMode = $this->enumValue($row['teacher_match_mode'] ?? 'mixed', ['student_choose', 'admin_assign', 'mixed'], ['学生选择' => 'student_choose', '管理员分配' => 'admin_assign', '混合匹配' => 'mixed'], '教师匹配方式');
        $approvalFlowId = $this->positiveInt($row['approval_flow_id'] ?? 0) ?: SocialPracticeRecord::defaultApprovalFlowId();
        if (!$approvalFlowId) {
            throw new InvalidArgumentException('没有可用的社会实践审批流程');
        }

        $courseCode = $this->text($row['course_code'] ?? '', 120);
        return [
            'values' => [
                'name' => $title,
                'code' => $courseCode !== '' ? $courseCode : $sourceKey,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'title' => $title,
                'description' => $this->text($row['description'] ?? '', 100000) ?: null,
                'grade_id' => (int) $academic['grade']['grade_id'],
                'organizer_dep_id' => (int) $academic['department']['dep_id'],
                'credit' => $this->decimal($row['credit'] ?? 0),
                'participation_mode' => $participationMode,
                'teacher_match_mode' => $teacherMatchMode,
                'teacher_confirm_hours' => 48,
                'max_reselect_count' => 2,
                'default_team_submit_mode' => 'individual',
                'register_start_at' => $this->dateTime($row['register_start_at'] ?? null),
                'register_end_at' => $this->dateTime($row['register_end_at'] ?? null),
                'practice_start_at' => $this->dateTime($row['practice_start_at'] ?? null),
                'practice_end_at' => $this->dateTime($row['practice_end_at'] ?? null),
                'result_deadline_at' => $this->dateTime($row['result_deadline_at'] ?? null),
                'score_deadline_at' => $this->dateTime($row['score_deadline_at'] ?? null),
                'approval_flow_id' => $approvalFlowId,
                'current_node_id' => null,
                'submitter_id' => $accountId,
            ],
            'scope' => [
                'scope_type' => $scopeType,
                'dep_id' => $scopeType === 'school' ? null : (int) $academic['department']['dep_id'],
                'profession_id' => in_array($scopeType, ['profession', 'class'], true) ? (int) ($academic['profession']['profession_id'] ?? 0) : null,
                'class_id' => $scopeType === 'class' ? (int) ($academic['class']['class_id'] ?? 0) : null,
            ],
            'source_key' => $sourceKey,
            'index' => $index,
        ];
    }

    /** 返回导入预览行。 */
    private function previewRow(array $row, array $scope): array
    {
        try {
            $normalized = $this->normalizeRow($row, 'excel', CurrentContext::accountId(), $scope, (int) ($row['row_number'] ?? 0));
            $existing = SocialPracticeRecord::importedPlanBySourceKey('excel', $normalized['source_key']);
            return array_merge($row, $normalized['values'], $normalized['scope'], [
                'source_key' => $normalized['source_key'],
                'errors' => [],
                'duplicate' => $existing ? ['id' => (int) $existing->id, 'status' => (string) $existing->status] : null,
                'can_import' => true,
            ]);
        } catch (\Throwable $exception) {
            return array_merge($row, ['errors' => [$exception->getMessage()], 'duplicate' => null, 'can_import' => false]);
        }
    }

    /** 读取 Excel 中的有效计划行。 */
    private function readRows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        try {
            $sheetRows = $spreadsheet->getActiveSheet()->toArray(null, false, true, true);
            [$mapping, $startRow] = $this->headerMapping($sheetRows);
            $rows = [];
            foreach ($sheetRows as $rowNumber => $sheetRow) {
                if ((int) $rowNumber < $startRow) {
                    continue;
                }
                $row = ['row_number' => (int) $rowNumber];
                foreach ($mapping as $field => $column) {
                    $row[$field] = $sheetRow[$column] ?? null;
                }
                if ($this->rowEmpty($row)) {
                    continue;
                }
                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS) {
                    throw new InvalidArgumentException('单次最多读取 500 条计划');
                }
            }
            if (!$rows) {
                throw new InvalidArgumentException('Excel 中没有可导入的计划');
            }
            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** 定位 Excel 表头并返回字段列映射。 */
    private function headerMapping(array $rows): array
    {
        foreach (array_slice($rows, 0, 15, true) as $rowNumber => $row) {
            $mapping = [];
            foreach ($row as $column => $value) {
                $key = $this->headerKey((string) $value);
                if ($key !== null && !isset($mapping[$key])) {
                    $mapping[$key] = $column;
                }
            }
            if (isset($mapping['title'], $mapping['grade_name'], $mapping['department_name'])) {
                return [$mapping, (int) $rowNumber + 1];
            }
        }
        throw new InvalidArgumentException('未找到计划名称、年级和学院表头');
    }

    /** 将 Excel 表头转换为字段名。 */
    private function headerKey(string $value): ?string
    {
        $value = $this->normalizedText($value);
        foreach (self::HEADERS as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($value === $this->normalizedText($alias)) {
                    return $field;
                }
            }
        }
        return null;
    }

    /** 生成社会实践计划导入模板。 */
    private function writeTemplate(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('社会实践计划');
        $headers = array_map(static fn (array $aliases): string => $aliases[0], self::HEADERS);
        $sheet->fromArray(array_values($headers), null, 'A1');
        $sheet->fromArray([
            'SP-2026-001', 'SP-C001', '2026级社会实践计划', '2026级', '信息工程学院', '软件技术', '', '专业',
            1, '必修', '混合匹配', '2026-07-01 00:00:00', '2026-07-15 23:59:59', '2026-07-20 00:00:00',
            '2026-08-20 23:59:59', '2026-08-25 23:59:59', '2026-08-31 23:59:59', '社会调研与志愿服务',
        ], null, 'A2');
        $sheet->freezePane('A2');
        foreach (range('A', 'R') as $column) {
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['C', 'R'], true) ? 24 : 16);
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /** 返回默认材料要求。 */
    private function defaultRequirements(): array
    {
        return [
            ['practice_mode' => 'all', 'requirement_type' => 'safety_agreement', 'required_flag' => 'true', 'submit_scope' => 'student'],
            ['practice_mode' => 'all', 'requirement_type' => 'insurance', 'required_flag' => 'true', 'submit_scope' => 'student'],
            ['practice_mode' => 'all', 'requirement_type' => 'emergency_plan', 'required_flag' => 'true', 'submit_scope' => 'project'],
            ['practice_mode' => 'all', 'requirement_type' => 'parent_notice', 'required_flag' => 'false', 'submit_scope' => 'student'],
            ['practice_mode' => 'centralized', 'requirement_type' => 'practice_report', 'required_flag' => 'true', 'submit_scope' => 'student'],
            ['practice_mode' => 'centralized', 'requirement_type' => 'practice_photo', 'required_flag' => 'true', 'submit_scope' => 'student'],
            ['practice_mode' => 'distributed', 'requirement_type' => 'practice_proof', 'required_flag' => 'true', 'submit_scope' => 'student'],
            ['practice_mode' => 'distributed', 'requirement_type' => 'social_practice_report', 'required_flag' => 'true', 'submit_scope' => 'student'],
        ];
    }

    /** 返回集中和分散实践默认成绩规则。 */
    private function defaultScoreRules(): array
    {
        return [
            ['practice_mode' => 'centralized', 'item_code' => 'attendance', 'item_name' => '签到', 'weight' => 20, 'max_score' => 100, 'sort' => 10],
            ['practice_mode' => 'centralized', 'item_code' => 'performance', 'item_name' => '实践表现', 'weight' => 30, 'max_score' => 100, 'sort' => 20],
            ['practice_mode' => 'centralized', 'item_code' => 'report', 'item_name' => '实践报告', 'weight' => 50, 'max_score' => 100, 'sort' => 30],
            ['practice_mode' => 'distributed', 'item_code' => 'attitude', 'item_name' => '实践态度', 'weight' => 20, 'max_score' => 100, 'sort' => 10],
            ['practice_mode' => 'distributed', 'item_code' => 'result', 'item_name' => '实践成果', 'weight' => 30, 'max_score' => 100, 'sort' => 20],
            ['practice_mode' => 'distributed', 'item_code' => 'report', 'item_name' => '社会实践报告', 'weight' => 50, 'max_score' => 100, 'sort' => 30],
        ];
    }

    /** 校验导入行是否位于当前组织范围。 */
    private function assertScopeAccess(array $scope, int $depId, int $professionId): void
    {
        $role = (string) ($scope['role_type'] ?? 'school_admin');
        if ($role === 'college_admin' && !in_array($depId, array_map('intval', $scope['dep_ids'] ?? []), true)) {
            throw new RuntimeException('学院不在当前账号管理范围内', 40301);
        }
        if ($role === 'profession_admin' && !in_array($professionId, array_map('intval', $scope['profession_ids'] ?? []), true)) {
            throw new RuntimeException('专业不在当前账号管理范围内', 40301);
        }
    }

    /** 校验开放应用 IP 白名单。 */
    private function assertAllowedIp(array $allowedIps, string $ip): void
    {
        $allowedIps = array_values(array_filter(array_map('strval', $allowedIps)));
        if ($allowedIps && !in_array($ip, $allowedIps, true)) {
            throw new RuntimeException('请求 IP 不在允许范围内', 401);
        }
    }

    /** 返回并校验上传的 Excel。 */
    private function excelFile(Request $request): UploadFile
    {
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()) {
            throw new InvalidArgumentException('上传文件无效');
        }
        if (!in_array(strtolower($file->getUploadExtension()), ['xls', 'xlsx'], true)) {
            throw new InvalidArgumentException('仅支持 xls、xlsx 文件');
        }
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('文件大小不能超过 10MB');
        }
        return $file;
    }

    /** 判断导入行是否为空。 */
    private function rowEmpty(array $row): bool
    {
        foreach (array_keys(self::HEADERS) as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    /** 读取必填文本。 */
    private function requiredText(mixed $value, string $label, int $maxLength): string
    {
        $value = $this->text($value, $maxLength);
        if ($value === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        return $value;
    }

    /** 截取文本。 */
    private function text(mixed $value, int $maxLength): string
    {
        $value = trim((string) ($value ?? ''));
        return mb_substr($value, 0, $maxLength);
    }

    /** 解析枚举文本。 */
    private function enumValue(mixed $value, array $allowed, array $aliases, string $label): string
    {
        $value = trim((string) $value);
        $value = $aliases[$value] ?? $value;
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . '无效');
        }
        return $value;
    }

    /** 解析非负学分。 */
    private function decimal(mixed $value): float
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 99) {
            throw new InvalidArgumentException('学分格式无效');
        }
        return round((float) $value, 2);
    }

    /** 解析可空日期时间。 */
    private function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                throw new InvalidArgumentException('日期格式无效');
            }
        }
        try {
            return (new DateTimeImmutable(trim((string) $value)))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new InvalidArgumentException('日期格式无效');
        }
    }

    /** 返回请求头文本。 */
    private function requiredHeader(Request $request, string $name, int $maxLength): string
    {
        $value = trim((string) $request->header($name, ''));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new RuntimeException($name . ' 请求头无效', 401);
        }
        return $value;
    }

    /** 规范化表头文本。 */
    private function normalizedText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string) preg_replace('/[\s　_\-:：（）()\/]+/u', '', $value);
    }

    /** 返回正整数。 */
    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** 返回当前时间。 */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
