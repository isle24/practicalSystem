<?php

namespace app\server\internship;

use app\model\channel\EducationPlanSyncRecord;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use app\server\config\ConfigService;
use GuzzleHttp\Client;
use InvalidArgumentException;
use RuntimeException;
use support\Request;

class EducationPlanSyncService
{
    private const ADMIN_ROLE_TYPES = ['super_admin', 'school_admin', 'college_admin', 'profession_admin'];
    private const SCHOOL_ROLE_TYPES = ['super_admin', 'school_admin'];
    private const MAX_BATCH_SIZE = 500;
    private const MAX_PULL_PAGES = 40;
    private const PULL_LOCK_TTL = 1800;

    /** 注入同步配置和 HTTP 客户端 */
    public function __construct(
        private ?ConfigService $configService = null,
        private ?Client $httpClient = null
    ) {
        $this->configService ??= new ConfigService();
        $this->httpClient ??= new Client();
    }

    /** 查询教务计划同步配置 */
    public function config(): array
    {
        $this->requireSchoolRole();
        $url = trim((string) ($this->configService->get('education_plan_sync.pull_url') ?? ''));
        $appId = trim((string) ($this->configService->get('education_plan_sync.pull_app_id') ?? ''));
        $secret = (string) ($this->configService->get('education_plan_sync.pull_app_secret') ?? '');

        return [
            'pull_url' => $url,
            'pull_app_id' => $appId,
            'pull_app_secret' => $secret === '' ? '' : '******',
            'secret_configured' => $secret !== '',
            'configured' => $url !== '' && $appId !== '' && $secret !== '',
            'last_synced_at' => $this->configService->get('education_plan_sync.last_synced_at') ?? '',
        ];
    }

    /** 保存教务计划主动拉取配置 */
    public function saveConfig(Request $request): array
    {
        $this->requireSchoolRole();
        $url = $this->stringInput($request, 'pull_url', 500);
        $appId = $this->stringInput($request, 'pull_app_id', 120);
        $secret = $this->stringInput($request, 'pull_app_secret', 255);
        if ($url !== '') {
            $this->assertPullUrl($url);
        }

        EducationPlanSyncRecord::transaction(function () use ($url, $appId, $secret): void {
            $this->configService->set('education_plan_sync', 'pull_url', $url, '教务计划接口地址');
            $this->configService->set('education_plan_sync', 'pull_app_id', $appId, '教务计划接口应用编号');
            if ($secret !== '' && $secret !== '******') {
                $this->configService->set('education_plan_sync', 'pull_app_secret', $secret, '教务计划接口应用密钥');
            }
        });

        return $this->config();
    }

    /** 从教务接口主动拉取教学计划 */
    public function pull(Request $request): array
    {
        $this->requireSchoolRole();
        $this->requirePlanPermission();
        $range = $this->pullRange($request);
        $schoolIdentifier = CurrentContext::schoolDatabaseId()
            ?: substr(hash('sha256', CurrentContext::schoolConnection()), 0, 16);
        $lockKey = 'workflow_lock:' . $schoolIdentifier . ':education_plan_sync:pull';

        try {
            return (new WorkflowLock())->run(
                $lockKey,
                fn (): array => $this->performPull($range),
                self::PULL_LOCK_TTL,
                true
            );
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 409 && $exception->getMessage() === '数据已变更，请刷新后重试') {
                throw new RuntimeException('教学计划同步正在进行，请稍后重试', 409);
            }
            throw $exception;
        }
    }

    /** 分页查询待接收教学计划 */
    public function inbox(Request $request): array
    {
        $this->requireAdminRole();
        $this->requirePlanPermission();

        return EducationPlanSyncRecord::inboxPage($this->scope(), [
            'page' => $request->input('page', 1),
            'page_size' => $request->input('page_size', $request->input('per_page', 20)),
            'status' => $request->input('status'),
            'mapping_status' => $request->input('mapping_status'),
            'grade_id' => $request->input('grade_id'),
            'dep_id' => $request->input('dep_id'),
            'profession_id' => $request->input('profession_id'),
            'keyword' => $request->input('keyword', ''),
        ]);
    }

    /** 查询待接收计划和本地差异 */
    public function detail(Request $request): array
    {
        $this->requireAdminRole();
        $this->requirePlanPermission();
        $id = $this->requiredId($request);
        $item = EducationPlanSyncRecord::inboxRow($this->scope(), $id);
        if (!$item) {
            throw new RuntimeException('待接收计划不存在或无权限', 403);
        }

        return [
            'item' => $item,
            'local_plan' => EducationPlanSyncRecord::localPlanBySourceId((string) $item['source_plan_id']),
            'diff' => (array) ($item['diff_payload'] ?? []),
        ];
    }

    /** 确认生成或更新本地计划草稿 */
    public function confirm(Request $request): array
    {
        $this->requireAdminRole();
        $this->requirePlanPermission();
        $ids = $this->requestIds($request);
        $results = [];
        foreach ($ids as $id) {
            $results[] = (new WorkflowLock())->run(
                WorkflowLock::key('internship', 'education_plan_sync_confirm', $id),
                fn (): array => $this->confirmOne($id)
            );
        }

        return [
            'confirmed' => count($results),
            'items' => $results,
        ];
    }

    /** 忽略待接收计划 */
    public function ignore(Request $request): array
    {
        $this->requireAdminRole();
        $this->requirePlanPermission();
        $id = $this->requiredId($request);
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            throw new InvalidArgumentException('请填写忽略原因');
        }
        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('忽略原因最多 500 字');
        }

        return (new WorkflowLock())->run(
            WorkflowLock::key('internship', 'education_plan_sync_ignore', $id),
            function () use ($id, $reason): array {
                return EducationPlanSyncRecord::transaction(function () use ($id, $reason): array {
                    $row = EducationPlanSyncRecord::inboxRow($this->scope(), $id, true);
                    if (!$row) {
                        throw new RuntimeException('待接收计划不存在或无权限', 403);
                    }
                    if (!in_array((string) $row['status'], ['pending', 'change_pending', 'mapping_failed'], true)) {
                        throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
                    }
                    $now = $this->now();
                    EducationPlanSyncRecord::updateInbox($id, [
                        'status' => 'ignored',
                        'ignored_by' => CurrentContext::accountId(),
                        'ignored_at' => $now,
                        'ignore_reason' => $reason,
                        'updated_at' => $now,
                    ]);

                    return ['id' => $id, 'status' => 'ignored'];
                });
            }
        );
    }

    /** 执行完整游标拉取 */
    private function performPull(array $range): array
    {
        $url = trim((string) ($this->configService->get('education_plan_sync.pull_url') ?? ''));
        $appId = trim((string) ($this->configService->get('education_plan_sync.pull_app_id') ?? ''));
        $secret = (string) ($this->configService->get('education_plan_sync.pull_app_secret') ?? '');
        if ($url === '' || $appId === '' || $secret === '') {
            throw new InvalidArgumentException('尚未配置教务系统接口');
        }
        $this->assertPullUrl($url);
        $endpoint = str_ends_with(rtrim($url, '/'), '/open-api/education-plans')
            ? rtrim($url, '/')
            : rtrim($url, '/') . '/open-api/education-plans';
        $lastSyncedAt = trim((string) ($this->configService->get('education_plan_sync.last_synced_at') ?? ''));
        $cursor = '';
        $hasMore = false;
        $seenCursors = ['' => true];
        $batchNo = 'plan-pull-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $result = [
            'batch_no' => $batchNo,
            'pages' => 0,
            'received' => 0,
            'created' => 0,
            'updated' => 0,
            'duplicate' => 0,
            'mapping_failed' => 0,
            'failed' => 0,
            'errors' => [],
        ];
        $maxSourceUpdatedAt = $lastSyncedAt;

        do {
            if ($result['pages'] >= self::MAX_PULL_PAGES) {
                throw new RuntimeException('教务计划接口拉取页数超过限制');
            }
            $query = [
                'cursor' => $cursor,
                'limit' => self::MAX_BATCH_SIZE,
            ];
            if ($range['updated_from'] !== '') {
                $query['updated_from'] = $range['updated_from'];
            } elseif ($lastSyncedAt !== '') {
                $query['updated_after'] = $lastSyncedAt;
            }
            if ($range['updated_to'] !== '') {
                $query['updated_to'] = $range['updated_to'];
            }
            $body = $this->requestPage($endpoint, $query, $appId, $secret);
            $data = $body['data'];
            $items = $data['items'];
            foreach ($items as $offset => $item) {
                $result['received']++;
                try {
                    $saved = EducationPlanSyncRecord::transaction(fn (): array => $this->receiveItem($item, $batchNo));
                    $result[$saved['result']]++;
                    if ($saved['status'] === 'mapping_failed') {
                        $result['mapping_failed']++;
                    }
                    $sourceUpdatedAt = (string) ($saved['source_updated_at'] ?? '');
                    if ($sourceUpdatedAt !== '' && ($maxSourceUpdatedAt === '' || strcmp($sourceUpdatedAt, $maxSourceUpdatedAt) > 0)) {
                        $maxSourceUpdatedAt = $sourceUpdatedAt;
                    }
                } catch (\Throwable $exception) {
                    $result['failed']++;
                    if (count($result['errors']) < 100) {
                        $result['errors'][] = [
                            'page' => $result['pages'] + 1,
                            'row' => $offset + 1,
                            'message' => $exception->getMessage(),
                        ];
                    }
                }
            }

            $result['pages']++;
            $cursor = (string) $data['next_cursor'];
            $hasMore = (bool) $data['has_more'];
            if ($hasMore && $cursor === '') {
                throw new RuntimeException('教务计划接口缺少下一页游标');
            }
            if ($hasMore && isset($seenCursors[$cursor])) {
                throw new RuntimeException('教务计划接口返回循环游标');
            }
            $seenCursors[$cursor] = true;
        } while ($hasMore);

        if ($result['failed'] === 0) {
            $watermark = $maxSourceUpdatedAt !== '' ? $maxSourceUpdatedAt : $this->now();
            $this->configService->set('education_plan_sync', 'last_synced_at', $watermark, '教务计划主动拉取完成时间');
            $result['last_synced_at'] = $watermark;
        } else {
            $result['last_synced_at'] = $lastSyncedAt;
            $result['warning'] = '存在失败数据，未推进增量同步时间';
        }

        return $result;
    }

    /** 请求单页教务计划数据 */
    private function requestPage(string $endpoint, array $query, string $appId, string $secret): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signingText = $appId . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', '');
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
            throw new RuntimeException('教务计划接口返回 HTTP ' . $response->getStatusCode());
        }
        if (!is_array($body) || (int) ($body['code'] ?? -1) !== 0 || !is_array($body['data'] ?? null)) {
            throw new RuntimeException(is_array($body) ? (string) ($body['message'] ?? '教务计划接口响应格式无效') : '教务计划接口响应格式无效');
        }
        $data = $body['data'];
        if (!is_array($data['items'] ?? null) || !array_is_list($data['items'])) {
            throw new RuntimeException('教务计划接口 data.items 必须为 JSON 数组');
        }
        if (count($data['items']) > self::MAX_BATCH_SIZE) {
            throw new RuntimeException('教务计划接口单页超过 500 条');
        }
        if (!is_bool($data['has_more'] ?? null) || !is_string($data['next_cursor'] ?? null)) {
            throw new RuntimeException('教务计划接口分页字段无效');
        }

        return $body;
    }

    /** 校验映射并写入单条暂存计划 */
    private function receiveItem(mixed $item, string $batchNo): array
    {
        if (!is_array($item) || array_is_list($item)) {
            throw new InvalidArgumentException('教学计划数据必须为 JSON object');
        }
        $sourcePlanId = $this->itemString($item, ['source_plan_id', 'plan_id', 'id'], 120, true);
        $sourceVersion = $this->itemString($item, ['source_version', 'version'], 80, true);
        $gradeCode = $this->itemString($item, ['grade_code'], 80, true);
        $depCode = $this->itemString($item, ['dep_code', 'department_code'], 80, true);
        $professionCode = $this->itemString($item, ['profession_code', 'major_code'], 80, true);
        $courseName = $this->itemString($item, ['course_name'], 180, true);
        $grade = EducationPlanSyncRecord::gradeByCode($gradeCode);
        $department = EducationPlanSyncRecord::departmentByCode($depCode);
        $profession = $department ? EducationPlanSyncRecord::professionByCode($professionCode, (int) $department['dep_id']) : null;
        $mappingErrors = [];
        if (!$grade) {
            $mappingErrors[] = '年级代码未匹配';
        }
        if (!$department) {
            $mappingErrors[] = '学院代码未匹配';
        }
        if (!$profession) {
            $mappingErrors[] = '专业代码未匹配或不属于该学院';
        } elseif ($grade && (int) ($profession['grade_id'] ?? 0) > 0 && (int) $profession['grade_id'] !== (int) $grade['grade_id']) {
            $mappingErrors[] = '专业不属于该年级';
        }
        $localPlan = EducationPlanSyncRecord::localPlanBySourceId($sourcePlanId);
        $diff = $localPlan ? $this->planDiff($localPlan, [
            'grade_id' => $grade['grade_id'] ?? null,
            'dep_id' => $department['dep_id'] ?? null,
            'profession_id' => $profession['profession_id'] ?? null,
            'course_code' => $this->itemString($item, ['course_code'], 120),
            'course_name' => $courseName,
            'course_category' => $this->itemString($item, ['course_category'], 80),
            'credit' => $this->itemNumber($item, ['credit']),
            'total_hours' => $this->itemNumber($item, ['total_hours']),
            'internship_hours' => $this->itemNumber($item, ['practice_hours']),
        ]) : [];
        $sourceUpdatedAt = $this->itemDateTime($item, ['source_updated_at', 'updated_at']);
        $status = $mappingErrors ? 'mapping_failed' : ($localPlan ? 'change_pending' : 'pending');
        $now = $this->now();
        $saved = EducationPlanSyncRecord::upsertInbox([
            'name' => $courseName,
            'code' => $this->itemString($item, ['course_code'], 120),
            'source_plan_id' => $sourcePlanId,
            'source_version' => $sourceVersion,
            'source_course_id' => $this->itemString($item, ['source_course_id', 'course_id'], 120),
            'source_status' => $this->itemString($item, ['source_status', 'status'], 40),
            'grade_code' => $gradeCode,
            'grade_name' => $this->itemString($item, ['grade_name'], 120),
            'dep_code' => $depCode,
            'dep_name' => $this->itemString($item, ['dep_name', 'department_name'], 120),
            'profession_code' => $professionCode,
            'profession_name' => $this->itemString($item, ['profession_name', 'major_name'], 180),
            'education_level' => $this->itemString($item, ['education_level'], 80),
            'scheme_name' => $this->itemString($item, ['scheme_name'], 180),
            'scheme_version' => $this->itemString($item, ['scheme_version'], 80),
            'course_code' => $this->itemString($item, ['course_code'], 120),
            'course_name' => $courseName,
            'course_category' => $this->itemString($item, ['course_category'], 80),
            'course_nature' => $this->itemString($item, ['course_nature'], 80),
            'credit' => $this->itemNumber($item, ['credit']),
            'weekly_hours' => $this->itemNumber($item, ['weekly_hours']),
            'total_hours' => $this->itemNumber($item, ['total_hours']),
            'theory_hours' => $this->itemNumber($item, ['theory_hours']),
            'experiment_hours' => $this->itemNumber($item, ['experiment_hours']),
            'practice_hours' => $this->itemNumber($item, ['practice_hours']),
            'computer_hours' => $this->itemNumber($item, ['computer_hours']),
            'other_hours' => $this->itemNumber($item, ['other_hours']),
            'source_period' => $this->itemString($item, ['source_period', 'academic_period', 'semester'], 80),
            'source_updated_at' => $sourceUpdatedAt,
            'mapping_status' => $mappingErrors ? 'failed' : 'matched',
            'mapped_grade_id' => $grade['grade_id'] ?? null,
            'mapped_dep_id' => $department['dep_id'] ?? null,
            'mapped_profession_id' => $profession['profession_id'] ?? null,
            'raw_payload' => $this->json($item),
            'diff_payload' => $this->json($mappingErrors ? [['field' => 'mapping', 'label' => '基础档案映射', 'source' => implode('；', $mappingErrors), 'local' => '']] : $diff),
            'sync_batch_no' => $batchNo,
            'local_plan_id' => $localPlan['id'] ?? null,
            'received_at' => $now,
            'status' => $status,
        ], $now);
        $saved['source_updated_at'] = $sourceUpdatedAt;
        return $saved;
    }

    /** 确认单条暂存计划 */
    private function confirmOne(int $id): array
    {
        return EducationPlanSyncRecord::transaction(function () use ($id): array {
            $row = EducationPlanSyncRecord::inboxRow($this->scope(), $id, true);
            if (!$row) {
                throw new RuntimeException('待接收计划不存在或无权限', 403);
            }
            if (!in_array((string) $row['status'], ['pending', 'change_pending'], true)) {
                throw new InvalidArgumentException('数据已变更，请刷新后重试', 409);
            }
            if ((string) $row['mapping_status'] !== 'matched') {
                throw new InvalidArgumentException('基础档案映射失败，不能生成计划');
            }
            $localPlan = !empty($row['local_plan_id'])
                ? EducationPlanSyncRecord::lockLocalPlan((int) $row['local_plan_id'])
                : EducationPlanSyncRecord::localPlanBySourceId((string) $row['source_plan_id']);
            if ($localPlan && EducationPlanSyncRecord::localPlanHasBusinessData((int) $localPlan['id'])) {
                throw new InvalidArgumentException('本地计划已有任务或实施数据，不能覆盖，请按变更流程处理', 409);
            }
            $now = $this->now();
            $values = $this->localPlanValues($row, $localPlan, $now);
            if ($localPlan) {
                $planId = (int) $localPlan['id'];
                EducationPlanSyncRecord::updateLocalPlan($planId, $values);
                $action = 'updated';
            } else {
                $planId = EducationPlanSyncRecord::createLocalPlan(array_merge($values, [
                    'uuid' => $this->uuid(),
                    'created_at' => $now,
                ]));
                $action = 'created';
            }
            EducationPlanSyncRecord::updateInbox($id, [
                'local_plan_id' => $planId,
                'confirmed_by' => CurrentContext::accountId(),
                'confirmed_at' => $now,
                'status' => 'generated',
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'plan_id' => $planId, 'action' => $action, 'status' => 'generated'];
        });
    }

    /** 生成本地计划写入值 */
    private function localPlanValues(array $row, ?array $localPlan, string $now): array
    {
        return [
            'name' => (string) $row['course_name'],
            'code' => (string) ($row['course_code'] ?: $row['source_plan_id']),
            'source_type' => 'edu_system',
            'source_plan_id' => $row['source_plan_id'],
            'source_version' => $row['source_version'],
            'source_last_synced_at' => $row['source_updated_at'] ?: $now,
            'course_code' => $row['course_code'],
            'course_name' => $row['course_name'],
            'course_category' => $row['course_category'],
            'category_id' => $localPlan['category_id'] ?? null,
            'grade_id' => $row['mapped_grade_id'],
            'graduation_cohort_id' => null,
            'dep_id' => $row['mapped_dep_id'],
            'profession_id' => $row['mapped_profession_id'],
            'semester' => null,
            'credit' => $row['credit'],
            'total_credit' => $localPlan['total_credit'] ?? $row['credit'],
            'internship_credit' => $localPlan['internship_credit'] ?? null,
            'total_hours' => $row['total_hours'] === null ? null : (string) $row['total_hours'],
            'internship_hours' => $row['practice_hours'] === null ? null : (string) $row['practice_hours'],
            'source_teacher' => $localPlan['source_teacher'] ?? null,
            'source_time' => $localPlan['source_time'] ?? null,
            'source_location' => $localPlan['source_location'] ?? null,
            'remark' => $localPlan['remark'] ?? null,
            'source_row' => $this->json($row['raw_payload'] ?? []),
            'business_type' => 'internship',
            'student_count' => 0,
            'score_rule' => $localPlan['score_rule'] ?? 'average',
            'plan_content' => $this->json($localPlan['plan_content'] ?? []),
            'submitter_id' => CurrentContext::accountId(),
            'status' => 'draft',
            'updated_at' => $now,
            'deleted_at' => null,
        ];
    }

    /** 生成来源数据与本地计划差异 */
    private function planDiff(array $local, array $source): array
    {
        $labels = [
            'grade_id' => '年级',
            'dep_id' => '学院',
            'profession_id' => '专业',
            'course_code' => '课程代码',
            'course_name' => '课程名称',
            'course_category' => '课程类别',
            'credit' => '学分',
            'total_hours' => '总学时',
            'internship_hours' => '实践学时',
        ];
        $diff = [];
        foreach ($labels as $field => $label) {
            $localValue = $local[$field] ?? null;
            $sourceValue = $source[$field] ?? null;
            if ((string) $localValue === (string) $sourceValue) {
                continue;
            }
            $diff[] = [
                'field' => $field,
                'label' => $label,
                'local' => $localValue,
                'source' => $sourceValue,
            ];
        }
        return $diff;
    }

    /** 读取并校验拉取时间范围 */
    private function pullRange(Request $request): array
    {
        $from = trim((string) $request->input('updated_from', ''));
        $to = trim((string) $request->input('updated_to', ''));
        foreach (['updated_from' => $from, 'updated_to' => $to] as $field => $value) {
            if ($value !== '' && !$this->validDateTime($value)) {
                throw new InvalidArgumentException($field . ' 格式应为 YYYY-MM-DD HH:mm:ss');
            }
        }
        if ($from !== '' && $to !== '' && strcmp($from, $to) > 0) {
            throw new InvalidArgumentException('同步开始时间不能晚于结束时间');
        }
        return ['updated_from' => $from, 'updated_to' => $to];
    }

    /** 校验拉取地址 */
    private function assertPullUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('教务计划接口地址无效');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('教务计划接口端口无效');
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

    /** 限制为实习计划管理角色 */
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

    /** 校验实习计划权限 */
    private function requirePlanPermission(): void
    {
        if (!in_array('internship:plan', CurrentContext::permissionCodes(), true)) {
            throw new RuntimeException('无操作权限', 403);
        }
    }

    /** 读取确认主键列表 */
    private function requestIds(Request $request): array
    {
        $input = $request->input('ids');
        if (!is_array($input)) {
            $input = [$request->input('id')];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $input), static fn (int $id): bool => $id > 0)));
        if (!$ids) {
            throw new InvalidArgumentException('请选择待接收计划');
        }
        if (count($ids) > 100) {
            throw new InvalidArgumentException('单次最多确认 100 条计划');
        }
        return $ids;
    }

    /** 读取必填主键 */
    private function requiredId(Request $request): int
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('id 无效');
        }
        return $id;
    }

    /** 读取配置字符串 */
    private function stringInput(Request $request, string $field, int $maxLength): string
    {
        $value = $request->input($field, '');
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' 必须为字符串');
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . ' 长度超限');
        }
        return $value;
    }

    /** 读取来源字符串字段 */
    private function itemString(array $item, array $fields, int $maxLength, bool $required = false): string
    {
        $value = '';
        foreach ($fields as $field) {
            if (array_key_exists($field, $item) && $item[$field] !== null) {
                if (!is_scalar($item[$field])) {
                    throw new InvalidArgumentException($field . ' 必须为字符串');
                }
                $value = trim((string) $item[$field]);
                break;
            }
        }
        if ($required && $value === '') {
            throw new InvalidArgumentException($fields[0] . ' 不能为空');
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($fields[0] . ' 长度超限');
        }
        return $value;
    }

    /** 读取来源数值字段 */
    private function itemNumber(array $item, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') {
                continue;
            }
            if (!is_numeric($item[$field])) {
                throw new InvalidArgumentException($field . ' 必须为数值');
            }
            return round((float) $item[$field], 2);
        }
        return null;
    }

    /** 读取来源日期时间字段 */
    private function itemDateTime(array $item, array $fields): ?string
    {
        $value = $this->itemString($item, $fields, 19);
        if ($value === '') {
            return null;
        }
        if (!$this->validDateTime($value)) {
            throw new InvalidArgumentException($fields[0] . ' 格式应为 YYYY-MM-DD HH:mm:ss');
        }
        return $value;
    }

    /** 校验标准日期时间 */
    private function validDateTime(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date && $date->format('Y-m-d H:i:s') === $value;
    }

    /** 编码 JSON 字段 */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** 返回当前时间 */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** 生成 UUID */
    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
