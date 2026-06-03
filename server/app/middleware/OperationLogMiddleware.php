<?php

namespace app\middleware;

use app\model\channel\TableRecord;
use app\server\CurrentContext;
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
        'aes_key',
        'encoding_aes_key',
    ];

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

    private function record(Request $request, ?Response $response, float $startedAt, ?Throwable $exception = null): void
    {
        if (!$this->shouldRecord($request)) {
            return;
        }

        try {
            $path = '/' . trim($request->path(), '/');
            $method = strtoupper($request->method());
            $statusCode = $exception ? 500 : $this->statusCode($response);
            TableRecord::writeOperationLog([
                'account_id' => CurrentContext::accountId() ?: null,
                'action' => mb_substr($method . ' ' . $path, 0, 120),
                'ip' => mb_substr((string) $request->getRealIp(), 0, 80),
                'payload' => [
                    'method' => $method,
                    'path' => $path,
                    'status_code' => $statusCode,
                    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                    'query' => $this->mask($this->requestQuery($request)),
                    'input' => $this->mask($this->requestInput($request)),
                    'user_agent' => mb_substr((string) $request->header('user-agent', ''), 0, 300),
                    'error' => $exception ? mb_substr($exception->getMessage(), 0, 500) : null,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    private function shouldRecord(Request $request): bool
    {
        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $path = '/' . trim($request->path(), '/');
        return str_starts_with($path, '/api/');
    }

    private function statusCode(?Response $response): int
    {
        if ($response && method_exists($response, 'getStatusCode')) {
            return (int) $response->getStatusCode();
        }

        return 200;
    }

    private function requestQuery(Request $request): array
    {
        return method_exists($request, 'get') ? (array) $request->get() : [];
    }

    private function requestInput(Request $request): array
    {
        if (!method_exists($request, 'all')) {
            return [];
        }

        return (array) ($request->all() ?: []);
    }

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
