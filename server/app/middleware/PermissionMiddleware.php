<?php

namespace app\middleware;

use app\server\CurrentContext;
use app\server\rbac\PermissionRuleRepository;
use app\server\rbac\RbacService;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class PermissionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $requiredCode = (new PermissionRuleRepository())->requiredCode($request);
            if (!$requiredCode) {
                return $handler($request);
            }

            $permissions = CurrentContext::permissionCodes();
            if (!$permissions && CurrentContext::roleId()) {
                $permissions = (new RbacService())->permissionCodes(CurrentContext::roleId());
                CurrentContext::set(['permissions' => $permissions]);
            }

            if (!in_array($requiredCode, $permissions, true)) {
                return json(['code' => 40300, 'message' => '无操作权限', 'data' => null])->withStatus(403);
            }

            return $handler($request);
        } catch (Throwable $exception) {
            return json(['code' => 50000, 'message' => $exception->getMessage(), 'data' => null])->withStatus(500);
        }
    }
}
