<?php

namespace app\middleware;

use app\server\rbac\DataScopeService;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class DataScopeMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $segments = explode('/', trim($request->path(), '/'));
            $business = $segments[1] ?? $segments[0] ?? 'default';
            (new DataScopeService())->filter($business);

            return $handler($request);
        } catch (Throwable $exception) {
            return json(['code' => 50000, 'message' => $exception->getMessage(), 'data' => null])->withStatus(500);
        }
    }
}
