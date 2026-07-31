<?php

namespace app\server\teacher;

use app\model\channel\TeacherSyncRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\config\ConfigService;
use app\server\security\SecretCipher;
use DateTimeImmutable;
use GuzzleHttp\Client;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use support\Redis;
use support\Request;

class TeacherSyncService
{
    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const SCHOOL_ROLE_TYPES = ['super_admin', 'school_admin'];
    private const MAX_BATCH_SIZE = 500;
    private const SIGNATURE_WINDOW = 300;
    private const MAX_PULL_PAGES = 40;
    private const PULL_LOCK_TTL = 1800;

    /** 注入配置、加密和 HTTP 客户端 */
    public function __construct(
        private ?ConfigService $configService = null,
        private ?SecretCipher $cipher = null,
        private ?Client $httpClient = null
    ) {
        $this->configService ??= new ConfigService();
        $this->cipher ??= new SecretCipher();
        $this->httpClient ??= new Client();
    }

    /** 验证开放接口签名并接收教师增量数据 */
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

        $secret = $this->cipher->decrypt((string) ($application['app_secret'] ?? ''));
        $signingText = $appId . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $rawBody);
        $expected = hash_hmac('sha256', $signingText, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('同步接口签名无效', 401);
        }

        $nonceKey = 'teacher_sync:nonce:'
            . (CurrentContext::schoolDatabaseId() ?: CurrentContext::schoolConnection())
            . ':' . hash('sha256', $appId . "\n" . $nonce);
        if (!Redis::set($nonceKey, '1', 'EX', self::SIGNATURE_WINDOW, 'NX')) {
            throw new RuntimeException('同步请求 nonce 已使用', 409);
        }

        $result = $this->processPayload($payload, 'push');
        TeacherSyncRecord::touchApplication((int) $application['id'], $this->now());
        return $result;
    }

    /** 从已配置教务接口按游标主动拉取教师数据 */
    public function pull(Request $request): array
    {
        $this->requireSchoolRole();
        $schoolIdentifier = CurrentContext::schoolDatabaseId()
            ?: substr(hash('sha256', CurrentContext::schoolConnection()), 0, 16);
        $lockKey = 'workflow_lock:' . $schoolIdentifier . ':teacher_sync:pull';

        try {
            return (new WorkflowLock())->run(
                $lockKey,
                fn (): array => $this->performPull(),
                self::PULL_LOCK_TTL,
                true
            );
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 409 && $exception->getMessage() === '数据已变更，请刷新后重试') {
                throw new RuntimeException('校内教师同步正在进行，请稍后重试', 409);
            }
            throw $exception;
        }
    }

    /** 执行完整游标拉取并在成功后推进同步水位 */
    private function performPull(): array
    {
        $pullStartedAt = $this->now();
        $url = trim((string) ($this->configService->get('teacher_sync.pull_url') ?? ''));
        $appId = trim((string) ($this->configService->get('teacher_sync.pull_app_id') ?? ''));
        $secret = (string) ($this->configService->get('teacher_sync.pull_app_secret') ?? '');
        $lastSyncedAt = trim((string) ($this->configService->get('teacher_sync.last_synced_at') ?? ''));
        if ($url === '') {
            throw new InvalidArgumentException('未配置教师同步拉取地址');
        }
        if ($appId === '' || $secret === '') {
            throw new InvalidArgumentException('未配置教师同步拉取应用编号或密钥');
        }
        $this->assertPullUrl($url);

        $endpoint = str_ends_with(rtrim($url, '/'), '/open-api/teachers')
            ? rtrim($url, '/')
            : rtrim($url, '/') . '/open-api/teachers';
        $cursor = '';
        $seenCursors = ['' => true];
        $aggregate = $this->emptyResult('pull-' . date('YmdHis'));
        $aggregate['pages'] = 0;
        $maxSourceUpdatedAt = $lastSyncedAt;

        do {
            if ($aggregate['pages'] >= self::MAX_PULL_PAGES) {
                throw new RuntimeException('教师同步拉取页数超过限制');
            }
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $signingText = $appId . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', '');
            $query = ['cursor' => $cursor, 'limit' => self::MAX_BATCH_SIZE];
            if ($lastSyncedAt !== '') {
                $query['updated_after'] = $lastSyncedAt;
            }

            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-App-Id' => $appId,
                    'X-Timestamp' => $timestamp,
                    'X-Nonce' => $nonce,
                    'X-Signature' => hash_hmac('sha256', $signingText, $secret),
                ],
                'query' => $query,
                'connect_timeout' => 5,
                'timeout' => 30,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException('教务教师接口返回 HTTP ' . $response->getStatusCode());
            }
            if (!is_array($body) || (int) ($body['code'] ?? -1) !== 0 || !is_array($body['data'] ?? null)) {
                $message = is_array($body) && is_string($body['message'] ?? null)
                    ? $body['message']
                    : '教务教师接口响应格式无效';
                throw new RuntimeException($message);
            }

            $data = $body['data'];
            if (!array_key_exists('items', $data) || !is_array($data['items']) || !array_is_list($data['items'])) {
                throw new RuntimeException('教务教师接口 data.items 必须为 JSON 数组');
            }
            if (!array_key_exists('has_more', $data) || !is_bool($data['has_more'])) {
                throw new RuntimeException('教务教师接口 data.has_more 必须为 JSON bool');
            }
            if (!array_key_exists('next_cursor', $data) || !is_string($data['next_cursor'])) {
                throw new RuntimeException('教务教师接口 data.next_cursor 必须为字符串');
            }
            $items = $data['items'];
            if (count($items) > self::MAX_BATCH_SIZE) {
                throw new RuntimeException('教务教师接口单页超过 500 条');
            }
            $requestId = sprintf('pull-%s-%04d-%s', date('YmdHis'), $aggregate['pages'] + 1, substr(bin2hex(random_bytes(5)), 0, 10));
            $pageResult = $this->processPayload([
                'request_id' => $requestId,
                'teachers' => $items,
            ], 'pull');
            $this->mergeResult($aggregate, $pageResult, $aggregate['pages'] + 1);
            foreach ($items as $item) {
                $sourceTime = is_array($item) && is_string($item['source_updated_at'] ?? null)
                    ? trim($item['source_updated_at'])
                    : '';
                if ($sourceTime !== '' && ($maxSourceUpdatedAt === '' || strcmp($sourceTime, $maxSourceUpdatedAt) > 0)) {
                    $maxSourceUpdatedAt = $sourceTime;
                }
            }

            $aggregate['pages']++;
            $nextCursor = $data['next_cursor'];
            $hasMore = $data['has_more'];
            if ($hasMore && $nextCursor === '') {
                throw new RuntimeException('教务教师接口缺少下一页游标');
            }
            if ($hasMore && isset($seenCursors[$nextCursor])) {
                throw new RuntimeException('教务教师接口返回循环游标');
            }
            if ($hasMore) {
                $seenCursors[$nextCursor] = true;
            }
            $cursor = $nextCursor;
        } while ($hasMore);

        if ($aggregate['failed'] === 0) {
            $watermark = $aggregate['received'] > 0 && $maxSourceUpdatedAt !== ''
                ? $maxSourceUpdatedAt
                : $pullStartedAt;
            if (strcmp($watermark, $pullStartedAt) > 0) {
                $watermark = $pullStartedAt;
            }
            if ($lastSyncedAt !== '' && strcmp($watermark, $lastSyncedAt) < 0) {
                $watermark = $lastSyncedAt;
            }
            $this->configService->set('teacher_sync', 'last_synced_at', $watermark, '教师主动拉取完成时间');
            $aggregate['last_synced_at'] = $watermark;
        } else {
            $aggregate['last_synced_at'] = $lastSyncedAt;
            $aggregate['warning'] = '存在失败数据，未推进增量同步时间';
        }
        $aggregate['next_cursor'] = $cursor;

        return $aggregate;
    }

    /** 按当前管理员组织范围查询教师档案 */
    public function teachers(Request $request): array
    {
        $this->requireAdminRole();
        return TeacherSyncRecord::teacherPage($this->scope(), [
            'page' => $request->input('page', 1),
            'page_size' => $request->input('page_size', 20),
            'keyword' => $request->input('keyword', ''),
            'dep_id' => $request->input('dep_id'),
            'profession_id' => $request->input('profession_id'),
        ]);
    }

    /** 查询主动拉取配置的脱敏信息 */
    public function config(): array
    {
        $this->requireSchoolRole();
        $secret = (string) ($this->configService->get('teacher_sync.pull_app_secret') ?? '');
        return [
            'pull_url' => $this->configService->get('teacher_sync.pull_url') ?? '',
            'pull_app_id' => $this->configService->get('teacher_sync.pull_app_id') ?? '',
            'pull_app_secret' => SecretCipher::mask($secret),
            'secret_configured' => $secret !== '',
            'last_synced_at' => $this->configService->get('teacher_sync.last_synced_at') ?? '',
        ];
    }

    /** 保存主动拉取地址和应用凭据 */
    public function saveConfig(Request $request): array
    {
        $this->requireSchoolRole();
        $url = $this->optionalInputString($request, 'pull_url');
        $appId = $this->optionalInputString($request, 'pull_app_id');
        $secret = $this->optionalInputString($request, 'pull_app_secret');
        if ($url !== '') {
            $this->assertPullUrl($url);
        }
        if (mb_strlen($url) > 500 || mb_strlen($appId) > 120 || mb_strlen($secret) > 255) {
            throw new InvalidArgumentException('教师同步配置字段长度超限');
        }

        return TeacherSyncRecord::transaction(function () use ($url, $appId, $secret): array {
            $this->configService->set('teacher_sync', 'pull_url', $url, '教务教师接口基础地址');
            $this->configService->set('teacher_sync', 'pull_app_id', $appId, '教务教师接口应用编号');
            if ($secret !== '' && $secret !== '******') {
                $this->configService->set('teacher_sync', 'pull_app_secret', $secret, '教务教师接口应用密钥');
            }

            return $this->config();
        });
    }

    /** 创建或轮换开放推送应用凭据 */
    public function saveApplication(Request $request): array
    {
        $this->requireSchoolRole();
        $appIdInput = $request->input('app_id');
        if (!is_string($appIdInput)) {
            throw new InvalidArgumentException('app_id 必须为字符串');
        }
        $appId = trim($appIdInput);
        if ($appId === '' || mb_strlen($appId) > 120 || !preg_match('/^[A-Za-z0-9._-]+$/', $appId)) {
            throw new InvalidArgumentException('app_id 格式无效');
        }

        $existing = TeacherSyncRecord::applicationForManagement($appId);
        $statusInput = $request->input('status');
        if ($statusInput === null) {
            $status = (string) ($existing['status'] ?? 'enabled');
        } elseif (is_string($statusInput)) {
            $status = trim($statusInput);
        } else {
            throw new InvalidArgumentException('status 必须为字符串');
        }
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new InvalidArgumentException('status 仅支持 enabled 或 disabled');
        }

        $secretInput = $request->input('app_secret');
        if ($secretInput === null) {
            $secret = '';
        } elseif (is_string($secretInput)) {
            $secret = trim($secretInput);
        } else {
            throw new InvalidArgumentException('app_secret 必须为字符串');
        }
        if ($secret === '******') {
            $secret = '';
        }
        if ($secret !== '' && mb_strlen($secret) > 255) {
            throw new InvalidArgumentException('app_secret 长度不能超过 255');
        }

        $allowedIpsInput = $request->input('allowed_ips');
        if ($allowedIpsInput === null) {
            $allowedIps = is_array($existing['allowed_ips'] ?? null) ? $existing['allowed_ips'] : [];
        } elseif (is_array($allowedIpsInput) && array_is_list($allowedIpsInput)) {
            $allowedIps = $allowedIpsInput;
        } else {
            throw new InvalidArgumentException('allowed_ips 必须为 JSON 数组');
        }
        $allowedIps = array_values(array_unique(array_filter(array_map(static function (mixed $ip): string {
            if (!is_string($ip)) {
                throw new InvalidArgumentException('allowed_ips 中的 IP 必须为字符串');
            }
            $value = trim($ip);
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('allowed_ips 包含无效 IP');
            }
            return $value;
        }, $allowedIps))));

        $application = TeacherSyncRecord::saveApplication(
            $appId,
            $secret === '' ? '' : $this->cipher->encrypt($secret),
            $allowedIps,
            $status,
            $this->now()
        );

        return [
            'app_id' => $application['app_id'] ?? $appId,
            'status' => $application['status'] ?? $status,
            'app_secret' => SecretCipher::mask((string) ($application['app_secret'] ?? '')),
            'secret_configured' => (string) ($application['app_secret'] ?? '') !== '',
            'allowed_ips' => $application['allowed_ips'] ?? $allowedIps,
            'last_used_at' => $application['last_used_at'] ?? null,
            'secret_updated_at' => $application['secret_updated_at'] ?? null,
            'updated_at' => $application['updated_at'] ?? null,
        ];
    }

    /** 校验批次并复用统一教师写入流程 */
    private function processPayload(array $payload, string $source): array
    {
        if (!array_key_exists('request_id', $payload) || !is_string($payload['request_id'])) {
            throw new InvalidArgumentException('request_id 必须为字符串');
        }
        $requestId = trim($payload['request_id']);
        if ($requestId === '' || mb_strlen($requestId) > 120 || !preg_match('/^[A-Za-z0-9._:-]+$/', $requestId)) {
            throw new InvalidArgumentException('request_id 格式无效');
        }
        $teachers = $payload['teachers'] ?? null;
        if (!is_array($teachers) || !array_is_list($teachers)) {
            throw new InvalidArgumentException('teachers 必须为 JSON 数组');
        }
        if (count($teachers) > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('单次最多同步 500 名教师');
        }

        $existing = TeacherSyncRecord::batchByRequestId($requestId);
        if ($existing) {
            return $this->existingBatchResult($existing);
        }

        return TeacherSyncRecord::transaction(function () use ($requestId, $source, $teachers): array {
            if (!TeacherSyncRecord::createBatch($requestId, $source, count($teachers), $this->now())) {
                $existing = TeacherSyncRecord::batchByRequestId($requestId);
                if ($existing) {
                    return $this->existingBatchResult($existing);
                }
                throw new RuntimeException('同步批次创建失败');
            }

            $result = $this->emptyResult($requestId);
            $result['received'] = count($teachers);
            foreach ($teachers as $index => $teacher) {
                try {
                    $values = $this->normalizeTeacher($teacher, $source);
                    $action = TeacherSyncRecord::upsertTeacher($values, $this->now());
                    $result[$action]++;
                } catch (TeacherSyncItemException $exception) {
                    $this->appendError($result, $index, $teacher, $exception->field, $exception->getMessage());
                } catch (InvalidArgumentException $exception) {
                    $this->appendError($result, $index, $teacher, 'record', $exception->getMessage());
                }
            }

            TeacherSyncRecord::completeBatch($requestId, $result, $this->now());
            return $result;
        });
    }

    /** 规范化单条教师数据并解析组织代码 */
    private function normalizeTeacher(mixed $teacher, string $source): array
    {
        if (!is_array($teacher)) {
            throw new TeacherSyncItemException('record', '教师数据必须为对象');
        }
        $required = [
            'external_id' => 120,
            'teacher_num' => 80,
            'teacher_name' => 80,
            'department_code' => 80,
            'status' => 20,
            'source_updated_at' => 40,
        ];
        $values = [];
        foreach ($required as $field => $maxLength) {
            $values[$field] = $this->itemString($teacher, $field, $maxLength, true);
        }
        if (!in_array($values['status'], ['enabled', 'disabled'], true)) {
            throw new TeacherSyncItemException('status', 'status 仅支持 enabled 或 disabled');
        }
        $this->assertDateTime($values['source_updated_at'], 'source_updated_at');

        $department = TeacherSyncRecord::departmentByCode($values['department_code']);
        if (!$department) {
            throw new TeacherSyncItemException('department_code', '学院代码不存在或未启用');
        }
        $professionCode = $this->itemString($teacher, 'profession_code', 80);
        $professionId = null;
        if ($professionCode !== '') {
            $profession = TeacherSyncRecord::professionByCode($professionCode, (int) $department['dep_id']);
            if (!$profession) {
                throw new TeacherSyncItemException('profession_code', '专业代码不存在、未启用或不属于所选学院');
            }
            $professionId = (int) $profession['profession_id'];
        }

        $birthDate = $this->itemString($teacher, 'birth_date', 20);
        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate) {
                throw new TeacherSyncItemException('birth_date', 'birth_date 格式应为 YYYY-MM-DD');
            }
        }
        $email = $this->itemString($teacher, 'email', 120);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new TeacherSyncItemException('email', 'email 格式无效');
        }

        return [
            'external_id' => $values['external_id'],
            'teacher_num' => $values['teacher_num'],
            'teacher_name' => $values['teacher_name'],
            'dep_id' => (int) $department['dep_id'],
            'profession_id' => $professionId,
            'gender' => $this->nullableItemString($teacher, 'gender', 20),
            'birth_date' => $birthDate === '' ? null : $birthDate,
            'title' => $this->nullableItemString($teacher, 'title', 120),
            'education' => $this->nullableItemString($teacher, 'education', 80),
            'phone' => $this->nullableItemString($teacher, 'phone', 40),
            'email' => $email === '' ? null : $email,
            'employment_type' => $this->nullableItemString($teacher, 'employment_type', 40),
            'sync_source' => $source,
            'source_updated_at' => $values['source_updated_at'],
            'status' => $values['status'],
        ];
    }

    /** 返回已存在批次的幂等结果 */
    private function existingBatchResult(array $batch): array
    {
        if (($batch['status'] ?? '') !== 'completed' || !is_array($batch['result_json'] ?? null)) {
            throw new RuntimeException('相同 request_id 的同步批次正在处理', 409);
        }
        $result = $batch['result_json'];
        $result['idempotent'] = true;
        return $result;
    }

    /** 生成空同步统计 */
    private function emptyResult(string $requestId): array
    {
        return [
            'request_id' => $requestId,
            'received' => 0,
            'created' => 0,
            'updated' => 0,
            'disabled' => 0,
            'failed' => 0,
            'errors' => [],
        ];
    }

    /** 合并主动拉取分页结果 */
    private function mergeResult(array &$aggregate, array $pageResult, int $page): void
    {
        foreach (['received', 'created', 'updated', 'disabled', 'failed'] as $key) {
            $aggregate[$key] += (int) ($pageResult[$key] ?? 0);
        }
        foreach ((array) ($pageResult['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $error['page'] = $page;
                $aggregate['errors'][] = $error;
            }
        }
    }

    /** 追加单条同步错误 */
    private function appendError(array &$result, int $index, mixed $teacher, string $field, string $message): void
    {
        $result['failed']++;
        $result['errors'][] = [
            'index' => $index,
            'teacher_num' => is_array($teacher) && is_string($teacher['teacher_num'] ?? null)
                ? $teacher['teacher_num']
                : '',
            'field' => $field,
            'message' => mb_substr($message, 0, 500),
        ];
    }

    /** 校验开放应用 IP 白名单 */
    private function assertAllowedIp(array $allowedIps, string $requestIp): void
    {
        $allowedIps = array_values(array_filter(array_map('strval', $allowedIps)));
        if ($allowedIps && !in_array($requestIp, $allowedIps, true)) {
            throw new RuntimeException('当前 IP 不允许调用同步接口', 401);
        }
    }

    /** 读取必填请求头 */
    private function requiredHeader(Request $request, string $name, int $maxLength): string
    {
        $value = trim((string) $request->header($name, ''));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new RuntimeException($name . ' 请求头无效', 401);
        }
        return $value;
    }

    /** 读取可空请求字符串字段 */
    private function optionalInputString(Request $request, string $field): string
    {
        $value = $request->input($field);
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' 必须为字符串');
        }

        return trim($value);
    }

    /** 读取并限制教师字符串字段 */
    private function itemString(array $item, string $field, int $maxLength, bool $required = false): string
    {
        if (!array_key_exists($field, $item) || $item[$field] === null) {
            if ($required) {
                throw new TeacherSyncItemException($field, $field . ' 不能为空');
            }
            return '';
        }
        if (!is_string($item[$field])) {
            throw new TeacherSyncItemException($field, $field . ' 必须为字符串');
        }

        $value = trim($item[$field]);
        if ($required && $value === '') {
            throw new TeacherSyncItemException($field, $field . ' 不能为空');
        }
        if (mb_strlen($value) > $maxLength) {
            throw new TeacherSyncItemException($field, $field . ' 长度不能超过 ' . $maxLength);
        }
        return $value;
    }

    /** 校验允许内网访问的教师拉取 URL 边界 */
    private function assertPullUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host'])) {
            throw new InvalidArgumentException('教师同步拉取地址格式无效');
        }
        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw new InvalidArgumentException('教师同步拉取地址禁止包含 userinfo');
        }
        if (array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException('教师同步拉取地址禁止包含 fragment');
        }
    }

    /** 读取可空教师字符串字段 */
    private function nullableItemString(array $item, string $field, int $maxLength): ?string
    {
        $value = $this->itemString($item, $field, $maxLength);
        return $value === '' ? null : $value;
    }

    /** 校验标准日期时间 */
    private function assertDateTime(string $value, string $field): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new TeacherSyncItemException($field, $field . ' 格式应为 YYYY-MM-DD HH:mm:ss');
        }
    }

    /** 返回当前管理员组织范围 */
    private function scope(): array
    {
        $depIds = [];
        $professionIds = [];
        foreach (CurrentContext::organizationScopes() as $scope) {
            if (!empty($scope['dep_id'])) {
                $depIds[] = (int) $scope['dep_id'];
            }
            if (!empty($scope['profession_id'])) {
                $professionIds[] = (int) $scope['profession_id'];
            }
        }

        return [
            'role_type' => CurrentContext::roleType(),
            'dep_ids' => array_values(array_unique($depIds)),
            'profession_ids' => array_values(array_unique($professionIds)),
        ];
    }

    /** 限制教师档案查询为管理员角色 */
    private function requireAdminRole(): void
    {
        if (!CurrentContext::accountId()) {
            throw new RuntimeException('请先登录', 401);
        }
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLE_TYPES, true)) {
            throw new RuntimeException('无操作权限', 403);
        }
    }

    /** 限制同步配置和主动拉取为学校级管理员 */
    private function requireSchoolRole(): void
    {
        if (!CurrentContext::accountId()) {
            throw new RuntimeException('请先登录', 401);
        }
        if (!in_array(CurrentContext::roleType(), self::SCHOOL_ROLE_TYPES, true)) {
            throw new RuntimeException('无操作权限', 403);
        }
    }

    /** 返回当前时间 */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

class TeacherSyncItemException extends InvalidArgumentException
{
    /** 创建带字段信息的单条同步异常 */
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
