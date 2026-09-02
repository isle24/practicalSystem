<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\internship\InternshipEnterpriseEvaluationService;
use InvalidArgumentException;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/** 提供企业导师免登录短信评价接口。 */
class OpenEnterpriseEvaluationController
{
    use Responds;

    /** 发送企业导师评价短信验证码。 */
    #[OperationLog('发送企业导师评价验证码')]
    public function sendCode(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->sendCode($request));
    }

    /** 校验企业导师评价短信验证码。 */
    #[OperationLog('校验企业导师评价验证码')]
    public function verifyCode(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->verifyCode($request));
    }

    /** 查询企业导师评价上下文。 */
    #[OperationLog('查询企业导师评价上下文')]
    public function context(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->context($request));
    }

    /** 提交企业导师学生评价。 */
    #[OperationLog('提交企业导师学生评价')]
    public function submit(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->submit($request));
    }

    /** 统一处理开放评价接口响应。 */
    private function handle(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $exception) {
            $exceptionCode = (int) $exception->getCode();
            $status = in_array($exceptionCode, [401, 403, 404, 409, 422, 429, 503], true)
                ? $exceptionCode
                : ($exception instanceof InvalidArgumentException ? 422 : 500);
            if (!in_array($status, [401, 403, 404, 409, 422, 429, 503], true)) {
                $status = 500;
            }
            if ($status === 500) {
                Log::error('企业评价开放接口异常: ' . $exception);
                return $this->fail(50000, '企业评价服务异常', 500);
            }
            $code = match ($status) {
                401 => 40100,
                403 => 40300,
                404 => 40400,
                409 => 40900,
                429 => 42900,
                503 => 50300,
                default => 42200,
            };
            return $this->fail($code, $exception->getMessage(), $status);
        }
    }

    /** 获取企业评价服务。 */
    private function service(): InternshipEnterpriseEvaluationService
    {
        return new InternshipEnterpriseEvaluationService();
    }
}
