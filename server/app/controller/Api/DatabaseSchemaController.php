<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\database\DatabaseSchemaService;
use support\Request;
use support\Response;
use Throwable;

class DatabaseSchemaController
{
    use Responds;

    public function options(Request $request): Response
    {
        return $this->respond(fn () => (new DatabaseSchemaService())->options());
    }

    #[OperationLog('检查数据库结构')]
    public function check(Request $request): Response
    {
        return $this->respond(fn () => (new DatabaseSchemaService())->check((string) $request->input('target', 'school')));
    }

    private function respond(callable $callback): Response
    {
        try {
            return $this->ok($callback())->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            $status = in_array($exception->getCode(), [403, 409], true) ? $exception->getCode() : 500;
            $message = $status === 500 ? '结构检查失败，请确认数据库可连接且具有读取结构的权限' : $exception->getMessage();
            return $this->fail($status * 100, $message, $status)->withHeader('Cache-Control', 'private, no-store');
        }
    }
}
