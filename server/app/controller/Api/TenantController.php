<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\tenant\TenantConnectionManager;
use app\server\tenant\TenantResolver;
use support\Request;
use support\Response;
use Throwable;

class TenantController
{
    use Responds;

    public function current(Request $request): Response
    {
        return $this->ok([
            'context' => [
                'tenant_database_id' => CurrentContext::tenantDatabaseId(),
                'tenant_database' => CurrentContext::tenantDatabase(),
                'tenant_connection' => CurrentContext::get('tenant_connection'),
            ],
            'active_connections' => (new TenantConnectionManager())->activeConnections(),
        ]);
    }

    public function resolve(Request $request): Response
    {
        try {
            $domain = (string) $request->input('domain', $request->host(true) ?: '');
            $tenant = (new TenantResolver())->resolveByDomainOrDefault($domain);
            if (!$tenant) {
                return $this->fail(40400, '租户不存在或未启用', 404);
            }

            return $this->ok([
                'authorization_id' => $tenant['authorization_id'] ?? null,
                'school_id' => $tenant['school_id'] ?? null,
                'database_id' => $tenant['database_id'] ?? null,
                'database_db' => $tenant['database_db'] ?? null,
                'database_charset' => $tenant['database_charset'] ?? null,
                'is_default_business_db' => $tenant['is_default_business_db'] ?? null,
                'config_version' => $tenant['config_version'] ?? null,
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }
}
