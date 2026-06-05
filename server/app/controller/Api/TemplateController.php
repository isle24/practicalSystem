<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\template\TemplateService;
use support\Request;
use support\Response;
use Throwable;

class TemplateController
{
    use Responds;

    public function categories(Request $request): Response
    {
        return $this->handle(fn (): array => (new TemplateService())->categories());
    }

    public function list(Request $request): Response
    {
        return $this->handle(fn (): array => (new TemplateService())->list([
            'page' => $this->intInput($request, 'page') ?: 1,
            'page_size' => $this->intInput($request, 'page_size') ?: 20,
            'category_id' => $this->intInput($request, 'category_id'),
            'status' => $request->input('status', 'all'),
            'keyword' => $request->input('keyword', ''),
        ]));
    }

    public function upload(Request $request): Response
    {
        return $this->handle(fn (): array => (new TemplateService())->upload($request), '已上传');
    }

    public function saveCategory(Request $request): Response
    {
        return $this->handle(fn (): array => (new TemplateService())->saveCategory((array) $request->all()), '已保存');
    }

    public function save(Request $request): Response
    {
        return $this->handle(fn (): array => (new TemplateService())->saveTemplate((array) $request->all()), '已保存');
    }

    public function delete(Request $request): Response
    {
        return $this->handle(fn (): array => (new TemplateService())->deleteTemplate($this->intInput($request, 'id')), '已删除');
    }

    public function download(Request $request): Response
    {
        return $this->handle(fn (): array => (new TemplateService())->download($this->intInput($request, 'id')));
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
