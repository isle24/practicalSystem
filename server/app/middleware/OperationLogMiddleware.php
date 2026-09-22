<?php

namespace app\middleware;

use app\attribute\OperationLog;
use app\model\channel\TableRecord;
use app\server\CurrentContext;
use app\server\OperationLogContext;
use ReflectionMethod;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class OperationLogMiddleware implements MiddlewareInterface
{
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'token',
        'secret',
        'authorization',
        'cookie',
        'access_token',
        'refresh_token',
        'jwt',
        'sms_code',
        'aes_key',
        'encoding_aes_key',
        'content_md',
        'sql_content',
        'identity_number',
        'identity_last_six',
        'id_card',
        '证件号',
    ];

    /**
     * 执行请求并记录接口操作日志。
     */
    public function process(Request $request, callable $handler): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $handler($request);
        } catch (Throwable $exception) {
            $this->record($request, null, $startedAt, $exception);
            throw $exception;
        }

        $this->record($request, $response, $startedAt);
        return $response;
    }

    /**
     * 写入当前接口请求的操作日志。
     */
    private function record(Request $request, ?Response $response, float $startedAt, ?Throwable $exception = null): void
    {
        if (!$this->shouldRecord($request)) {
            return;
        }

        try {
            $path = '/' . trim($request->path(), '/');
            $method = strtoupper($request->method());
            $operation = $this->operationName($request);
            $statusCode = $exception ? 500 : $this->statusCode($response);
            $responsePayload = $this->responsePayload($response, $exception);
            TableRecord::writeOperationLog([
                'account_id' => CurrentContext::accountId() ?: null,
                'action' => mb_substr($operation ?: $method . ' ' . $path, 0, 120),
                'ip' => mb_substr((string) $request->getRealIp(), 0, 80),
                'payload' => [
                    'operation' => $operation,
                    'method' => $method,
                    'path' => $path,
                    'controller' => is_string($request->controller ?? null) ? $request->controller : null,
                    'action_method' => is_string($request->action ?? null) ? $request->action : null,
                    'status_code' => $statusCode,
                    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                    'query' => $this->auditParameters($path, $this->requestQuery($request)),
                    'input' => $this->auditParameters($path, $this->requestInput($request)),
                    'user_agent' => mb_substr((string) $request->header('user-agent', ''), 0, 300),
                    'response_code' => $responsePayload['code'],
                    'response_message' => $responsePayload['message'],
                    'error' => $exception ? mb_substr($exception->getMessage(), 0, 500) : null,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * 判断当前请求是否需要写操作日志。
     */
    private function shouldRecord(Request $request): bool
    {
        $method = strtoupper($request->method());
        if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $path = '/' . trim($request->path(), '/');
        if ($path === '/api/wechat/callback') {
            return false;
        }
        return str_starts_with($path, '/api/');
    }

    /**
     * 获取接口动作名称，优先读取 OperationLog 属性。
     */
    private function operationName(Request $request): ?string
    {
        $contextName = OperationLogContext::name();
        if ($contextName) {
            return $contextName;
        }

        $controller = is_string($request->controller ?? null) ? $request->controller : '';
        $action = is_string($request->action ?? null) ? $request->action : '';
        if ($controller === '' || $action === '' || !class_exists($controller) || !method_exists($controller, $action)) {
            return null;
        }

        static $cache = [];
        $key = $controller . '::' . $action;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $method = new ReflectionMethod($controller, $action);
            $attributes = $method->getAttributes(OperationLog::class);
            $name = $attributes ? trim($attributes[0]->newInstance()->name) : $this->docCommentName($method);
            return $cache[$key] = ($name === '' ? null : $name);
        } catch (Throwable) {
            return $cache[$key] = null;
        }
    }

    /**
     * 从方法注释读取接口动作名称。
     */
    private function docCommentName(ReflectionMethod $method): string
    {
        $comment = (string) $method->getDocComment();
        if ($comment === '') {
            return '';
        }

        $comment = preg_replace('/^\/\*\*|\*\/$/', '', trim($comment));
        if (!is_string($comment)) {
            return '';
        }

        foreach (preg_split('/\R/', $comment) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*\*\s?/', '', $line));
            if ($line !== '' && !str_starts_with($line, '@')) {
                return mb_substr($line, 0, 120);
            }
        }

        return '';
    }

    /**
     * 获取响应 HTTP 状态码。
     */
    private function statusCode(?Response $response): int
    {
        if ($response && method_exists($response, 'getStatusCode')) {
            return (int) $response->getStatusCode();
        }

        return 200;
    }

    /**
     * 读取响应中的业务状态和提示消息。
     */
    private function responsePayload(?Response $response, ?Throwable $exception): array
    {
        if ($exception) {
            return [
                'code' => 50000,
                'message' => mb_substr($exception->getMessage(), 0, 500),
            ];
        }

        if (!$response || !method_exists($response, 'rawBody')) {
            return [
                'code' => null,
                'message' => null,
            ];
        }

        $body = (string) $response->rawBody();
        if ($body === '' || mb_strlen($body) > 20000) {
            return [
                'code' => null,
                'message' => null,
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'code' => null,
                'message' => null,
            ];
        }

        return [
            'code' => isset($decoded['code']) && is_numeric($decoded['code']) ? (int) $decoded['code'] : null,
            'message' => isset($decoded['message']) ? mb_substr((string) $decoded['message'], 0, 500) : null,
        ];
    }

    /**
     * 读取请求查询参数。
     */
    private function requestQuery(Request $request): array
    {
        return method_exists($request, 'get') ? (array) $request->get() : [];
    }

    /**
     * 读取请求输入参数。
     */
    private function requestInput(Request $request): array
    {
        if (!method_exists($request, 'all')) {
            return [];
        }

        return (array) ($request->all() ?: []);
    }

    /**
     * 脱敏请求参数。
     */
    private function auditParameters(string $path, array $input): mixed
    {
        if (preg_match('~^/api/(note|release|assistant)/~i', $path)) {
            $input = array_intersect_key($input, array_flip(['id', 'revision', 'page', 'page_size', 'product', 'version', 'action', 'sql_id', 'deferred_platforms']));
        }
        return $this->mask($input);
    }

    /** 脱敏日志参数。 */
    private function mask(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '******';
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $itemKey => $itemValue) {
                $result[$itemKey] = $this->mask($itemValue, is_string($itemKey) ? $itemKey : null);
            }
            return $result;
        }

        if (is_object($value)) {
            return ['type' => $value::class];
        }

        if (is_string($value)) {
            return mb_strlen($value) > 1000 ? mb_substr($value, 0, 1000) . '...' : $value;
        }

        return $value;
    }

    /**
     * 判断参数名是否为敏感字段。
     */
    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
