<?php

namespace app\server\rbac;

use support\Request;

class PermissionRuleRepository
{
    public function requiredCode(Request $request): ?string
    {
        $rules = (array) config('permission.rules', []);
        $method = strtoupper($request->method());
        $path = '/' . trim($request->path(), '/');

        return $rules[$method . ' ' . $path] ?? $rules[$path] ?? null;
    }
}
