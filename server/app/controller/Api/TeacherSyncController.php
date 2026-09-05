<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\teacher\TeacherSyncService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class TeacherSyncController
{
    use Responds;

    /** 主动拉取校内指导教师 */
    #[OperationLog('主动同步校内指导教师')]
    public function pull(Request $request): Response
    {
        return $this->handle($request, 'POST', fn (): array => $this->service()->pull($request));
    }

    /** 查询校内指导教师档案 */
    #[OperationLog('查询校内指导教师档案')]
    public function teachers(Request $request): Response
    {
        return $this->handle($request, 'GET', fn (): array => $this->service()->teachers($request));
    }

    /** 查询教师同步配置 */
    #[OperationLog('查询教师同步配置')]
    public function config(Request $request): Response
    {
        return $this->handle($request, 'GET', fn (): array => $this->service()->config());
    }

    /** 保存教师主动同步配置 */
    #[OperationLog('保存教师同步配置')]
    public function saveConfig(Request $request): Response
    {
        return $this->handle($request, 'POST', fn (): array => $this->service()->saveConfig($request));
    }

    /** 创建或轮换教师推送应用 */
    #[OperationLog('保存教师同步开放应用')]
    public function saveApplication(Request $request): Response
    {
        return $this->handle($request, 'POST', fn (): array => $this->service()->saveApplication($request));
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

    /** 创建教师同步服务 */
    private function service(): TeacherSyncService
    {
        return new TeacherSyncService();
    }
}
