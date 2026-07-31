<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\teacher\TeacherSyncService;
use InvalidArgumentException;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class OpenTeacherSyncController
{
    use Responds;

    /** 接收教务系统教师增量推送 */
    #[OperationLog('接收教务教师增量推送')]
    public function push(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') {
            return $this->fail(40500, '请求方法不允许', 405)->withHeader('Allow', 'POST');
        }

        try {
            return $this->ok((new TeacherSyncService())->push($request));
        } catch (Throwable $exception) {
            $status = $exception instanceof InvalidArgumentException ? 400 : (int) $exception->getCode();
            if (!in_array($status, [400, 401, 405, 409, 422], true)) {
                $status = 500;
            }
            $code = match ($status) {
                401 => 40100,
                405 => 40500,
                409 => 40900,
                422 => 42200,
                500 => 50000,
                default => 40001,
            };
            if ($status === 500) {
                Log::error('开放教师同步服务异常: ' . $exception);
                return $this->fail($code, '教师同步服务异常', $status);
            }

            return $this->fail($code, $exception->getMessage(), $status);
        }
    }
}
