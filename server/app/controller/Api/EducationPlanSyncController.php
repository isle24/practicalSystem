<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\internship\EducationPlanSyncService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class EducationPlanSyncController
{
    use Responds;

    /** 查询教务计划同步配置 */
    #[OperationLog('查询教务计划同步配置')]
    public function config(Request $request): Response
    {
        return $this->handle($request, 'GET', fn (): array => $this->service()->config());
    }

    /** 保存教务计划同步配置 */
    #[OperationLog('保存教务计划同步配置')]
    public function saveConfig(Request $request): Response
    {
        return $this->handle($request, 'POST', fn (): array => $this->service()->saveConfig($request));
    }

    /** 主动拉取教务教学计划 */
    #[OperationLog('主动同步教务教学计划')]
    public function pull(Request $request): Response
    {
        return $this->handle($request, 'POST', fn (): array => $this->service()->pull($request));
    }

    /** 查询待接收教学计划 */
    #[OperationLog('查询待接收教学计划')]
    public function inbox(Request $request): Response
    {
        return $this->handle($request, 'GET', fn (): array => $this->service()->inbox($request));
    }

    /** 查看待接收教学计划差异 */
    #[OperationLog('查看待接收教学计划差异')]
    public function detail(Request $request): Response
    {
        return $this->handle($request, 'GET', fn (): array => $this->service()->detail($request));
    }

    /** 确认生成或更新教学计划 */
    #[OperationLog('确认接收教务教学计划')]
    public function confirm(Request $request): Response
    {
        return $this->handle($request, 'POST', fn (): array => $this->service()->confirm($request));
    }

    /** 忽略待接收教学计划 */
    #[OperationLog('忽略教务教学计划')]
    public function ignore(Request $request): Response
    {
        return $this->handle($request, 'POST', fn (): array => $this->service()->ignore($request));
    }

    /** 校验 HTTP 方法并统一转换同步服务响应 */
    private function handle(Request $request, string $allowedMethod, callable $callback): Response
    {
        if (strtoupper($request->method()) !== $allowedMethod) {
            return $this->fail(40500, '请求方法不允许', 405)->withHeader('Allow', $allowedMethod);
        }

        try {
            return $this->ok($callback());
        } catch (Throwable $exception) {
            $status = $exception instanceof InvalidArgumentException ? 400 : (int) $exception->getCode();
            if (!in_array($status, [400, 401, 403, 405, 409, 422], true)) {
                $status = 500;
            }
            $code = match ($status) {
                401 => 40100,
                403 => 40300,
                405 => 40500,
                409 => 40900,
                422 => 42200,
                500 => 50000,
                default => 40001,
            };

            return $this->fail($code, $exception->getMessage(), $status);
        }
    }

    /** 创建教务计划同步服务 */
    private function service(): EducationPlanSyncService
    {
        return new EducationPlanSyncService();
    }
}
