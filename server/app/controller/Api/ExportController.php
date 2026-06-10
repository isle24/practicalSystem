<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\export\ExportTaskService;
use support\Request;
use support\Response;
use Throwable;

class ExportController
{
    use Responds;

    /**
     * 查询导出任务列表
     */
    #[OperationLog('查询导出任务列表')]
    public function list(Request $request): Response
    {
        return $this->handle(fn (): array => (new ExportTaskService())->list([
            'page' => $this->intInput($request, 'page') ?: 1,
            'page_size' => $this->intInput($request, 'page_size') ?: 20,
            'status' => $request->input('status', 'all'),
            'type' => $request->input('type', 'all'),
            'keyword' => $request->input('keyword', ''),
            'scope' => $request->input('scope', ''),
            'user_id' => $this->intInput($request, 'user_id'),
        ]));
    }

    /**
     * 查看导出任务详情
     */
    #[OperationLog('查看导出任务详情')]
    public function detail(Request $request): Response
    {
        return $this->handle(fn (): array => (new ExportTaskService())->detail($this->intInput($request, 'id')));
    }

    /**
     * 创建导出任务
     */
    #[OperationLog('创建导出任务')]
    public function create(Request $request): Response
    {
        return $this->handle(fn (): array => (new ExportTaskService())->create((array) $request->all()), '已创建导出任务');
    }

    /**
     * 重试导出任务
     */
    #[OperationLog('重试导出任务')]
    public function retry(Request $request): Response
    {
        return $this->handle(fn (): array => (new ExportTaskService())->retry($this->intInput($request, 'id')), '已重新排队');
    }

    private function handle(callable $callback, string $message = 'ok'): Response
    {
        try {
            return $this->ok($callback(), $message);
        } catch (Throwable $exception) {
            $status = $this->httpStatus($exception);
            return $this->fail($status === 403 ? 40300 : ($status === 401 ? 40100 : 40001), $exception->getMessage(), $status);
        }
    }

    private function intInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }

    private function httpStatus(Throwable $exception): int
    {
        $code = (int) $exception->getCode();
        return in_array($code, [401, 403], true) ? $code : 400;
    }
}
