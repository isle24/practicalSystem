<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\PracticeEntityNames;
use app\controller\Api\Concerns\Responds;
use app\server\OperationLogContext;
use app\server\practice\PracticeService;
use support\Request;
use support\Response;
use Throwable;

class LabController
{
    use Responds;
    use PracticeEntityNames;

    /**
     * 查看实验总览
     */
    #[OperationLog('查看实验总览')]
    public function overview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->overview($request));
    }

    /**
     * 获取实验筛选选项
     */
    #[OperationLog('获取实验筛选选项')]
    public function options(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->options($request));
    }

    /**
     * 查询实验列表
     */
    #[OperationLog('查询实验列表')]
    public function list(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->list($request));
    }

    /**
     * 保存实验记录
     */
    #[OperationLog('保存实验记录')]
    public function save(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->save($request));
    }

    /**
     * 审核实验记录
     */
    #[OperationLog('审核实验记录')]
    public function review(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->review($request));
    }

    /**
     * 发起实验通过后修改
     */
    #[OperationLog('发起实验通过后修改')]
    public function requestModification(Request $request): Response
    {
        OperationLogContext::setName('发起实验' . $this->practiceEntityName($request) . '通过后修改');
        return $this->handle(fn (): array => $this->service()->requestModification($request));
    }

    /**
     * 查看实验流程记录
     */
    #[OperationLog('查看实验流程记录')]
    public function timeline(Request $request): Response
    {
        OperationLogContext::setName('查看实验' . $this->practiceEntityName($request) . '流程记录');
        return $this->handle(fn (): array => $this->service()->timeline($request));
    }

    private function handle(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode();
            if (!in_array($status, [401, 403, 404, 409, 422, 429], true)) {
                $status = $exception->getMessage() === '请先登录' ? 401 : 400;
            }
            $code = match ($status) {
                401 => 40100,
                403 => str_contains($exception->getMessage(), '数据') ? 40301 : 40300,
                404 => 40400,
                409 => 40900,
                422 => 42200,
                429 => 42900,
                default => 40001,
            };
            if ((int) $exception->getCode() >= 42201 && (int) $exception->getCode() <= 42299) {
                $code = (int) $exception->getCode();
                $status = 422;
            }

            return $this->fail($code, $exception->getMessage(), $status);
        }
    }

    private function service(): PracticeService
    {
        return new PracticeService('lab');
    }
}
