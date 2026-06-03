<?php

namespace app\middleware;

use app\server\school\SchoolConnectionManager;
use app\server\school\SchoolResolver;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class SchoolMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $domain = $request->host(true) ?: '';
            $school = (new SchoolResolver())->resolveByDomainOrDefault($domain);
            if (!$school) {
                return json(['code' => 40400, 'message' => '学校不存在或未启用', 'data' => null])->withStatus(404);
            }

            (new SchoolConnectionManager())->ensureConnection((int) $school['database_id'], $school);

            return $handler($request);
        } catch (Throwable $exception) {
            return json(['code' => 50000, 'message' => $exception->getMessage(), 'data' => null])->withStatus(500);
        }
    }
}
