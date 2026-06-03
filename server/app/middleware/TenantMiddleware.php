<?php

namespace app\middleware;

use app\server\tenant\TenantConnectionManager;
use app\server\tenant\TenantResolver;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class TenantMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $domain = $request->host(true) ?: '';
            $tenant = (new TenantResolver())->resolveByDomainOrDefault($domain);
            if (!$tenant) {
                return json(['code' => 40400, 'message' => '租户不存在或未启用', 'data' => null])->withStatus(404);
            }

            (new TenantConnectionManager())->ensureConnection((int) $tenant['database_id'], $tenant);

            return $handler($request);
        } catch (Throwable $exception) {
            return json(['code' => 50000, 'message' => $exception->getMessage(), 'data' => null])->withStatus(500);
        }
    }
}
