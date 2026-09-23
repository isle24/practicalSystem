<?php

namespace app\server\account;

use app\model\channel\AccountImportRecord;
use app\model\channel\ProfileAccountRecord;
use app\server\CurrentContext;
use app\server\auth\AuthService;
use app\server\file\FileService;
use app\server\teacher\TeacherImportReader;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Throwable;
use Webman\Http\UploadFile;
use Webman\RedisQueue\Redis as RedisQueue;

class AccountImportService
{
    public const QUEUE = 'account_import';
    private const CHUNK_SIZE = 25;

    public function tasks(string $type): array
    {
        $this->assertAccess();
        $this->assertType($type);
        return ['items' => array_map($this->publicTask(...), AccountImportRecord::tasks((int) CurrentContext::accountId(), $type))];
    }

    public function detail(int $id): array
    {
        $this->assertAccess();
        return ['task' => $this->publicTask($this->ownedTask($id))];
    }

    public function teacherTemplate(): array
    {
        $this->assertAccess(false);
        return (new TeacherImportReader())->template();
    }

    public function teacherPreview(Request $request): array
    {
        $this->assertAccess();
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid()
            || !in_array(strtolower($file->getUploadExtension()), ['xls', 'xlsx'], true)) {
            throw new InvalidArgumentException('请上传有效的 xls 或 xlsx 教师文件');
        }
        $preview = $this->scanTeacher($file->getPathname());
        $previewToken = $this->teacherPreviewToken(
            (int) CurrentContext::accountId(),
            hash_file('sha256', $file->getPathname()),
            $preview,
            $preview['row_numbers'],
            $preview['department_ids']
        );
        $stored = (new FileService())->upload($request, [
            'category' => 'teacher_account_import', 'is_temporary' => false, 'require_md5' => false,
            'allowed_extensions' => ['xls', 'xlsx'], 'max_size' => TeacherImportReader::MAX_FILE_SIZE,
        ]);
        unset($preview['row_numbers'], $preview['department_ids']);
        return array_merge(['file_id' => (int) $stored['file_id'], 'preview_token' => $previewToken], $preview);
    }

    public function teacherStart(int $fileId, string $requestKey, string $previewToken): array
    {
        $this->assertAccess();
        if ($existing = $this->existingRequest($requestKey, 'teacher')) {
            return ['task' => $this->publicTask($existing)];
        }
        $path = $this->teacherFile($fileId, (int) CurrentContext::accountId());
        $source = $this->decodePreviewToken($previewToken, $path, (int) CurrentContext::accountId());
        if (!$source['row_numbers']) throw new InvalidArgumentException('教师文件为空，请重新预览');
        return $this->create('teacher', $requestKey, [
            'file_id' => $fileId, 'total_rows' => count($source['row_numbers']), 'source_json' => $this->encode($source),
        ]);
    }

    public function studentPreview(array $filters): array
    {
        $this->assertAccess();
        return AccountImportRecord::studentPreview($this->filters($filters));
    }

    public function studentStart(array $filters, string $password, string $requestKey): array
    {
        $this->assertAccess();
        if ($existing = $this->existingRequest($requestKey, 'student')) {
            return ['task' => $this->publicTask($existing)];
        }
        if (mb_strlen($password) < 6 || strlen($password) > 72 || str_contains($password, "\0") || trim($password) === '') {
            throw new InvalidArgumentException('学生初始密码至少 6 个字符，且不能超过 72 字节');
        }
        $sources = AccountImportRecord::studentSnapshot($this->filters($filters));
        if (!$sources) {
            throw new InvalidArgumentException('当前范围没有有效且已关联学生档案的数据，请先发布教务学生数据');
        }
        return $this->create('student', $requestKey, [
            'total_rows' => count($sources), 'source_json' => $this->encode(['students' => $sources]),
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
    }

    public function retry(int $id): array
    {
        $this->assertAccess();
        $cursor = AccountImportRecord::connection()->transaction(function () use ($id): int {
            $task = $this->ownedTask($id, true);
            $stale = in_array($task['status'], ['queued', 'processing'], true)
                && strtotime((string) $task['updated_at']) < time() - 300;
            if ($task['status'] !== 'failed' && !$stale) {
                throw new RuntimeException('仅失败或超过 5 分钟未推进的任务可以重试', 409);
            }
            AccountImportRecord::updateTask($id, ['status' => 'queued', 'error_message' => null, 'finished_at' => null]);
            return (int) $task['processed_rows'];
        });
        $this->enqueue($id, $cursor);
        return $this->detail($id);
    }

    public function processChunk(int $id): ?int
    {
        $initial = AccountImportRecord::task($id);
        if (!$initial || !in_array($initial['status'], ['queued', 'processing'], true)) {
            return null;
        }
        (new AuthService())->contextForAccount((int) $initial['created_by'], 'web', false);
        $this->assertAccess();
        return AccountImportRecord::connection()->transaction(function () use ($id): ?int {
            $task = AccountImportRecord::task($id, true);
            if (!$task || !in_array($task['status'], ['queued', 'processing'], true)) {
                return null;
            }
            $source = $this->decode($task['source_json']);
            $cursor = (int) $task['processed_rows'];
            $errors = $this->decode($task['errors_json']);
            $batch = $task['type'] === 'teacher'
                ? $this->teacherChunk($task, $source, $cursor)
                : array_slice($source['students'] ?? [], $cursor, self::CHUNK_SIZE);
            if (!$batch && $cursor < (int) $task['total_rows']) {
                throw new RuntimeException('导入任务数据不完整，请重新上传或选择学生范围', 422);
            }
            foreach ($batch as $row) {
                try {
                    $result = AccountImportRecord::connection()->transaction(function () use ($task, $row): array {
                        if ($task['type'] === 'student') {
                            return AccountImportRecord::provisionStudentSource($row, (string) $task['password_hash']);
                        }
                        if (!empty($row['errors'])) {
                            throw new InvalidArgumentException(implode('；', $row['errors']));
                        }
                        return ProfileAccountRecord::provisionTeacher($row['values']);
                    });
                    $field = $result['action'] . '_count';
                    $task[$field] = (int) $task[$field] + 1;
                } catch (InvalidArgumentException $exception) {
                    $task['failed_count'] = (int) $task['failed_count'] + 1;
                    if (count($errors) < 50) {
                        $errors[] = [
                            $task['type'] === 'teacher' ? 'row_number' : 'source_id' => $row['row_number'] ?? $row['id'],
                            'message' => mb_substr($exception->getMessage(), 0, 300),
                        ];
                    }
                }
                $cursor++;
            }
            $finished = $cursor >= (int) $task['total_rows'];
            $values = [
                'processed_rows' => $cursor, 'errors_json' => $this->encode($errors), 'error_message' => null,
                'status' => $finished ? ((int) $task['failed_count'] > 0 ? 'completed_with_errors' : 'completed') : 'processing',
            ];
            foreach (['created', 'linked', 'updated', 'skipped', 'failed'] as $action) {
                $values[$action . '_count'] = (int) $task[$action . '_count'];
            }
            if ($finished) {
                $values['finished_at'] = date('Y-m-d H:i:s');
                $values['password_hash'] = null;
            }
            AccountImportRecord::updateTask($id, $values);
            return $finished ? null : $cursor;
        });
    }

    public function enqueue(int $id, int $cursor): void
    {
        try {
            if (!RedisQueue::send(self::QUEUE, ['database_id' => CurrentContext::schoolDatabaseId(), 'task_id' => $id])) {
                throw new RuntimeException('队列不可用');
            }
        } catch (Throwable $exception) {
            $this->markFailed($id, '任务入队失败，请检查 Redis 队列后重试', $cursor);
            throw new RuntimeException('任务入队失败，已保存任务，可在任务历史重试', 503, $exception);
        }
    }

    public function markFailed(int $id, string $message, int $cursor): void
    {
        AccountImportRecord::connection()->transaction(function () use ($id, $message, $cursor): void {
            $task = AccountImportRecord::task($id, true);
            if ($task && (int) $task['processed_rows'] === $cursor && in_array($task['status'], ['queued', 'processing'], true)) {
                AccountImportRecord::updateTask($id, ['status' => 'failed', 'error_message' => $message]);
            }
        });
    }

    private function create(string $type, string $requestKey, array $values): array
    {
        try {
            $id = AccountImportRecord::createTask(array_merge($values, [
                'request_key' => $requestKey, 'type' => $type, 'created_by' => (int) CurrentContext::accountId(), 'status' => 'queued',
            ]));
        } catch (Throwable $exception) {
            if ($existing = $this->existingRequest($requestKey, $type)) {
                return ['task' => $this->publicTask($existing)];
            }
            throw $exception;
        }
        $this->enqueue($id, 0);
        return $this->detail($id);
    }

    private function scanTeacher(string $path): array
    {
        $reader = new TeacherImportReader();
        $metadata = $reader->metadata($path);
        $departmentMap = ProfileAccountRecord::departmentAliasMap(AccountImportRecord::departments());
        $seen = [];
        $result = ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'items' => [], 'errors' => [], 'row_numbers' => [], 'department_ids' => []];
        for ($start = 2; $start <= $metadata['total_rows'] + 1; $start += 500) {
            foreach ($reader->rows($path, $start, 500) as $row) {
                $number = mb_strtolower($row['values']['teacher_num']);
                if (isset($seen[$number])) {
                    $row['errors'][] = '职工号在文件中重复';
                }
                $seen[$number] = true;
                $department = ProfileAccountRecord::matchDepartment($row['values']['dep_name'], $departmentMap);
                if ($department['status'] !== 'matched') {
                    $row['errors'][] = $department['message'];
                } else {
                    $row['values']['dep_name'] = (string) $department['department']['dep_name'];
                    $result['department_ids'][$row['row_number']] = (int) $department['department']['dep_id'];
                }
                $result['total_rows']++;
                $result[$row['errors'] ? 'invalid_rows' : 'valid_rows']++;
                $result['row_numbers'][] = $row['row_number'];
                if (count($result['items']) < 50) {
                    $result['items'][] = [
                        'values' => array_intersect_key($row['values'], array_flip(['teacher_num', 'teacher_name', 'dep_name'])),
                        'row_number' => $row['row_number'], 'errors' => $row['errors'],
                    ];
                }
                if ($row['errors'] && count($result['errors']) < 50) {
                    $result['errors'][] = ['row_number' => $row['row_number'], 'message' => implode('；', $row['errors'])];
                }
            }
        }
        return $result;
    }

    private function teacherChunk(array $task, array $source, int $cursor): array
    {
        $path = $this->teacherFile((int) $task['file_id'], (int) $task['created_by']);
        if (!hash_equals((string) ($source['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            throw new RuntimeException('教师文件已变化，请重新上传', 422);
        }
        $numbers = array_slice($source['row_numbers'] ?? [], $cursor, self::CHUNK_SIZE);
        if (!$numbers) {
            return [];
        }
        $reader = new TeacherImportReader();
        $rows = [];
        for ($start = (int) min($numbers); $start <= max($numbers); $start += 500) {
            foreach ($reader->rows($path, $start, min(500, max($numbers) - $start + 1)) as $row) {
                if (in_array($row['row_number'], $numbers, true)) {
                    $row['values']['dep_id'] = (int) ($source['department_ids'][$row['row_number']] ?? 0);
                    $rows[] = $row;
                }
            }
        }
        if (count($rows) !== count($numbers)) {
            throw new RuntimeException('教师文件数据行已变化，请重新上传', 422);
        }
        return $rows;
    }

    private function teacherFile(int $fileId, int $accountId): string
    {
        $file = (new FileService())->info($fileId);
        if (($file['category'] ?? '') !== 'teacher_account_import' || (int) ($file['uploader_id'] ?? 0) !== $accountId
            || !in_array(strtolower((string) ($file['blob']['ext'] ?? '')), ['xls', 'xlsx'], true)) {
            throw new RuntimeException('无权使用该教师导入文件', 403);
        }
        $root = realpath(public_path() . '/files');
        $relative = ltrim(str_replace('\\', '/', (string) ($file['blob']['path'] ?? '')), '/');
        $path = realpath(public_path() . '/' . $relative);
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('导入文件已丢失或路径无效，请重新上传', 422);
        }
        return $path;
    }

    private function teacherPreviewToken(int $accountId, string|false $sha256, array $preview, array $rowNumbers, array $departmentIds): string
    {
        if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new RuntimeException('教师文件校验失败');
        }
        $payload = $this->encode([
            'account_id' => $accountId, 'sha256' => $sha256,
            'row_numbers' => array_values($rowNumbers), 'department_ids' => $departmentIds,
            'total_rows' => (int) ($preview['total_rows'] ?? 0),
            'valid_rows' => (int) ($preview['valid_rows'] ?? 0),
            'invalid_rows' => (int) ($preview['invalid_rows'] ?? 0),
            'expires_at' => time() + 1800,
        ]);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $this->previewTokenKey());
    }

    private function decodePreviewToken(string $token, string $path, int $accountId): array
    {
        if (strlen($token) > 2000000 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $matches)
            || !hash_equals(hash_hmac('sha256', $matches[1], $this->previewTokenKey()), $matches[2])) {
            throw new InvalidArgumentException('教师文件预览凭证无效，请重新上传并预览');
        }
        $json = base64_decode(strtr($matches[1], '-_', '+/'), true);
        $source = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($source) || (int) ($source['account_id'] ?? 0) !== $accountId
            || (int) ($source['expires_at'] ?? 0) < time() || !is_array($source['row_numbers'] ?? null)
            || !is_array($source['department_ids'] ?? null) || (int) ($source['invalid_rows'] ?? 1) !== 0
            || (int) ($source['total_rows'] ?? 0) < 1 || (int) ($source['valid_rows'] ?? 0) !== count($source['row_numbers'])) {
            throw new InvalidArgumentException('教师文件预览已过期，请重新上传并预览');
        }
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || !hash_equals((string) ($source['sha256'] ?? ''), $sha256)) {
            throw new InvalidArgumentException('教师文件与预览时不一致，请重新上传并预览');
        }
        return [
            'row_numbers' => array_values(array_map('intval', $source['row_numbers'])),
            'department_ids' => array_map('intval', $source['department_ids']),
            'sha256' => $sha256,
        ];
    }

    private function previewTokenKey(): string
    {
        $config = (array) config('plugin.tinywan.jwt.app.jwt', []);
        $key = (string) ($config['access_secret_key'] ?? '');
        if (strlen($key) < 32) throw new RuntimeException('教师导入预览签名配置无效');
        return $key;
    }

    private function existingRequest(string $key, string $type): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $key)) {
            throw new InvalidArgumentException('request_key 无效，请刷新后重试');
        }
        $task = AccountImportRecord::requestTask((int) CurrentContext::accountId(), $key);
        if ($task && $task['type'] !== $type) {
            throw new RuntimeException('请求标识已被其他导入任务使用', 409);
        }
        return $task;
    }

    private function ownedTask(int $id, bool $lock = false): array
    {
        $task = AccountImportRecord::task($id, $lock);
        if (!$task || (int) $task['created_by'] !== (int) CurrentContext::accountId()) {
            throw new RuntimeException('导入任务不存在或无权查看', 404);
        }
        return $task;
    }

    private function publicTask(array $task): array
    {
        $result = array_intersect_key($task, array_flip([
            'id', 'type', 'status', 'total_rows', 'processed_rows', 'created_count', 'linked_count', 'updated_count',
            'skipped_count', 'failed_count', 'error_message', 'created_at', 'updated_at', 'finished_at',
        ]));
        foreach (['id', 'total_rows', 'processed_rows', 'created_count', 'linked_count', 'updated_count', 'skipped_count', 'failed_count'] as $field) {
            $result[$field] = (int) ($result[$field] ?? 0);
        }
        $result['progress'] = $result['total_rows'] > 0 ? min(100, (int) floor($result['processed_rows'] * 100 / $result['total_rows'])) : 0;
        $result['errors'] = $this->decode($task['errors_json']);
        $result['retryable'] = $task['status'] === 'failed' || (in_array($task['status'], ['queued', 'processing'], true)
            && strtotime((string) $task['updated_at']) < time() - 300);
        return $result;
    }

    private function assertAccess(bool $schema = true): void
    {
        if (!in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true) || !CurrentContext::accountId()
            || !CurrentContext::schoolDatabaseId()) {
            throw new RuntimeException('仅超级管理员、学校管理员可以导入账号', 403);
        }
        if ($schema) {
            AccountImportRecord::assertSchema();
        }
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, ['teacher', 'student'], true)) {
            throw new InvalidArgumentException('导入类型无效');
        }
    }

    private function filters(array $filters): array
    {
        $result = [];
        foreach (['keyword', 'source_status', 'mapping_status'] as $key) {
            if (isset($filters[$key]) && !is_scalar($filters[$key])) {
                throw new InvalidArgumentException('筛选参数无效');
            }
            $result[$key] = mb_substr(trim((string) ($filters[$key] ?? '')), 0, 180);
        }
        $result['batch_id'] = is_numeric($filters['batch_id'] ?? null) ? max(0, (int) $filters['batch_id']) : 0;
        return $result;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function decode(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }
}
