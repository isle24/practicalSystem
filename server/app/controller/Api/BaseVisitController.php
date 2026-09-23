<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\internship\BaseVisitService;
use support\Request;
use support\Response;
use Throwable;

class BaseVisitController
{
    use Responds;

    public function options(Request $request): Response
    {
        return $this->respond(fn () => (new BaseVisitService())->options($request->all()));
    }

    #[OperationLog('查询基地巡查安排')]
    public function list(Request $request): Response
    {
        return $this->respond(fn () => (new BaseVisitService())->page($request->all()));
    }

    #[OperationLog('查看基地走访记录')]
    public function detail(Request $request): Response
    {
        return $this->respond(fn () => (new BaseVisitService())->detail((int) $request->input('id', 0)));
    }

    #[OperationLog('安排基地巡查')]
    public function assign(Request $request): Response
    {
        return $this->respond(fn () => (new BaseVisitService())->assign($request->all()));
    }

    #[OperationLog('填写基地走访时间')]
    public function schedule(Request $request): Response
    {
        return $this->respond(fn () => (new BaseVisitService())->schedule($request->all()));
    }

    #[OperationLog('保存基地走访记录')]
    public function record(Request $request): Response
    {
        return $this->respond(fn () => (new BaseVisitService())->saveRecord($request->all()));
    }

    #[OperationLog('取消基地巡查安排')]
    public function cancel(Request $request): Response
    {
        return $this->respond(fn () => (new BaseVisitService())->cancel($request->all()));
    }

    private function respond(callable $callback): Response
    {
        try {
            return $this->ok($callback())->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            $status = in_array($exception->getCode(), [400, 403, 404, 409], true) ? $exception->getCode() : 500;
            return $this->fail($status * 100, $status === 500 ? '基地巡查服务异常，请确认数据库结构已更新' : $exception->getMessage(), $status)
                ->withHeader('Cache-Control', 'private, no-store');
        }
    }
}
