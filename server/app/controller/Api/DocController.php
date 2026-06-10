<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\doc\DocService;
use support\Request;
use support\Response;
use Throwable;

class DocController
{
    use Responds;

    /**
     * 查询文档分类
     */
    #[OperationLog('查询文档分类')]
    public function categories(Request $request): Response
    {
        return $this->handle(fn (): array => (new DocService())->categories());
    }

    /**
     * 查询文档列表
     */
    #[OperationLog('查询文档列表')]
    public function list(Request $request): Response
    {
        return $this->handle(fn (): array => (new DocService())->list([
            'page' => $this->intInput($request, 'page') ?: 1,
            'page_size' => $this->intInput($request, 'page_size') ?: 20,
            'category_id' => $this->intInput($request, 'category_id'),
            'status' => $request->input('status', 'all'),
            'keyword' => $request->input('keyword', ''),
        ]));
    }

    /**
     * 查看文档详情
     */
    #[OperationLog('查看文档详情')]
    public function detail(Request $request): Response
    {
        return $this->handle(fn (): array => (new DocService())->detail($this->intInput($request, 'id')));
    }

    /**
     * 查看文档历史
     */
    #[OperationLog('查看文档历史')]
    public function history(Request $request): Response
    {
        return $this->handle(fn (): array => (new DocService())->history($this->intInput($request, 'article_id') ?: $this->intInput($request, 'id')));
    }

    /**
     * 保存文档分类
     */
    #[OperationLog('保存文档分类')]
    public function saveCategory(Request $request): Response
    {
        return $this->handle(fn (): array => (new DocService())->saveCategory((array) $request->all()), '已保存');
    }

    /**
     * 保存文档
     */
    #[OperationLog('保存文档')]
    public function save(Request $request): Response
    {
        return $this->handle(fn (): array => (new DocService())->saveArticle((array) $request->all()), '已保存');
    }

    /**
     * 删除文档
     */
    #[OperationLog('删除文档')]
    public function delete(Request $request): Response
    {
        return $this->handle(fn (): array => (new DocService())->deleteArticle($this->intInput($request, 'id')), '已删除');
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
