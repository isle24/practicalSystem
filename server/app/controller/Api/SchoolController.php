<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\school\SchoolConnectionManager;
use app\server\school\SchoolResolver;
use support\Request;
use support\Response;
use Throwable;

class SchoolController
{
    use Responds;

    #[OperationLog('获取当前学校连接')]
    public function current(Request $request): Response
    {
        return $this->ok([
            'context' => [
                'school_database_id' => CurrentContext::schoolDatabaseId(),
                'school_database' => CurrentContext::schoolDatabase(),
                'school_connection' => CurrentContext::get('school_connection'),
            ],
            'active_connections' => (new SchoolConnectionManager())->activeConnections(),
        ]);
    }

    #[OperationLog('解析学校连接')]
    public function resolve(Request $request): Response
    {
        try {
            $domain = (string) $request->input('domain', $request->host(true) ?: '');
            $school = (new SchoolResolver())->resolveByDomainOrDefault($domain);
            if (!$school) {
                return $this->fail(40400, '学校不存在或未启用', 404);
            }

            return $this->ok([
                'authorization_id' => $school['authorization_id'] ?? null,
                'school_id' => $school['school_id'] ?? null,
                'database_id' => $school['database_id'] ?? null,
                'database_db' => $school['database_db'] ?? null,
                'database_charset' => $school['database_charset'] ?? null,
                'is_default_business_db' => $school['is_default_business_db'] ?? null,
                'config_version' => $school['config_version'] ?? null,
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }
}
