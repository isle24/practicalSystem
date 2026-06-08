<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\practice\PracticeService;
use support\Request;
use support\Response;
use Throwable;

class LabController
{
    use Responds;

    public function overview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->overview($request));
    }

    public function options(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->options($request));
    }

    public function list(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->list($request));
    }

    public function save(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->save($request));
    }

    public function review(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->review($request));
    }

    public function requestModification(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->requestModification($request));
    }

    public function timeline(Request $request): Response
    {
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
