<?php

namespace app\server\rbac;

use app\server\CurrentContext;

class DataScopeService
{
    public function __construct(private ?RbacService $rbacService = null)
    {
        $this->rbacService ??= new RbacService();
    }

    public function filter(string $business = 'default', ?int $accountId = null, ?int $roleId = null, ?string $roleType = null): array
    {
        $roleType ??= CurrentContext::roleType();
        $roleId ??= CurrentContext::roleId();
        $accountId ??= CurrentContext::accountId();

        if (!$roleType && $roleId) {
            $role = $this->rbacService->role($roleId);
            $roleType = $role['role_type'] ?? $role['code'] ?? null;
        }

        $scopes = [];
        if (in_array($roleType, ['college_admin', 'profession_admin', 'enterprise'], true)) {
            $scopes = $this->rbacService->organizationScopes($accountId, $roleId);
        }

        $filter = match ($roleType) {
            'super_admin', 'school_admin' => [],
            'college_admin' => ['dep_id' => $this->ids($scopes, 'dep_id')],
            'profession_admin' => ['profession_id' => $this->ids($scopes, 'profession_id')],
            'enterprise' => ['company_id' => $this->ids($scopes, 'company_id')],
            'teacher' => ['teacher_user_id' => CurrentContext::userId()],
            'student' => ['student_user_id' => CurrentContext::userId()],
            default => ['deny_all' => true],
        };

        $dataScope = [
            'business' => $business,
            'role_type' => $roleType,
            'filter' => $filter,
        ];

        CurrentContext::set([
            'organization_scopes' => $scopes,
            'data_scope' => $dataScope,
        ]);

        return $dataScope;
    }

    private function ids(array $scopes, string $field): array
    {
        $ids = [];
        foreach ($scopes as $scope) {
            if (!empty($scope[$field])) {
                $ids[] = (int) $scope[$field];
            }
        }

        return array_values(array_unique($ids));
    }
}
