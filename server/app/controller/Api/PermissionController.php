<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\rbac\DataScopeService;
use app\server\rbac\RbacService;
use support\Request;
use support\Response;
use Throwable;

class PermissionController
{
    use Responds;

    #[OperationLog('获取当前菜单权限')]
    public function menus(Request $request): Response
    {
        try {
            $roleId = $this->roleId($request);
            $platform = (string) $request->input('platform', 'pc');

            $service = new RbacService();

            return $this->ok([
                'menus' => $service->menus($platform, $roleId),
                'permissions' => $service->permissionCodes($roleId),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    #[OperationLog('获取当前权限码')]
    public function codes(Request $request): Response
    {
        try {
            $roleId = $this->roleId($request);

            return $this->ok([
                'permissions' => (new RbacService())->permissionCodes($roleId),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    #[OperationLog('获取当前数据权限范围')]
    public function scope(Request $request): Response
    {
        try {
            $accountId = $this->accountId($request);
            $roleId = $this->roleId($request);

            return $this->ok([
                'organization_scopes' => (new RbacService())->organizationScopes($accountId, $roleId),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    #[OperationLog('获取数据筛选范围')]
    public function filter(Request $request): Response
    {
        try {
            $business = (string) $request->input('business', 'default');
            $accountId = $this->accountId($request);
            $roleId = $this->roleId($request);
            $roleType = CurrentContext::roleType();

            return $this->ok((new DataScopeService())->filter($business, $accountId, $roleId, $roleType));
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    private function intInput(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private function roleId(Request $request): ?int
    {
        $roleId = CurrentContext::roleId();
        if (!$roleId) {
            return null;
        }

        $requestRoleId = $this->intInput($request, 'role_id');
        if ($requestRoleId && $requestRoleId !== $roleId && $this->canInspectOtherRoles()) {
            return $requestRoleId;
        }

        return $roleId;
    }

    private function accountId(Request $request): ?int
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return null;
        }

        $requestAccountId = $this->intInput($request, 'account_id');
        if ($requestAccountId && $requestAccountId !== $accountId && $this->canInspectOtherRoles()) {
            return $requestAccountId;
        }

        return $accountId;
    }

    private function canInspectOtherRoles(): bool
    {
        return in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true);
    }
}
