<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\file\FileService;
use support\Request;
use support\Response;
use Throwable;

class FileController
{
    use Responds;

    /**
     * 校验文件秒传
     */
    #[OperationLog('校验文件秒传')]
    public function check(Request $request): Response
    {
        return $this->handle(fn (): array => (new FileService())->check($request));
    }

    /**
     * 上传文件
     */
    #[OperationLog('上传文件')]
    public function upload(Request $request): Response
    {
        return $this->handle(fn (): array => (new FileService())->upload($request));
    }

    /**
     * 查询文件列表
     */
    #[OperationLog('查询文件列表')]
    public function list(Request $request): Response
    {
        return $this->handle(fn (): array => (new FileService())->list($request));
    }

    /**
     * 查看文件详情
     */
    #[OperationLog('查看文件详情')]
    public function info(Request $request): Response
    {
        return $this->handle(fn (): array => (new FileService())->info($this->intInput($request, 'id') ?: $this->intInput($request, 'file_id')));
    }

    /**
     * 查询文件关联
     */
    #[OperationLog('查询文件关联')]
    public function relations(Request $request): Response
    {
        return $this->handle(fn (): array => [
            'items' => (new FileService())->relations(
                (string) $request->input('entity_type', ''),
                $this->intInput($request, 'entity_id'),
                (string) $request->input('tag', '')
            ),
        ]);
    }

    /**
     * 绑定文件关联
     */
    #[OperationLog('绑定文件关联')]
    public function attach(Request $request): Response
    {
        return $this->fail(40300, '请通过对应业务提交或修改附件', 403);
    }

    /**
     * 解绑文件关联
     */
    #[OperationLog('解绑文件关联')]
    public function detach(Request $request): Response
    {
        return $this->fail(40300, '请通过对应业务提交或修改附件', 403);
    }

    /**
     * 删除文件
     */
    #[OperationLog('删除文件')]
    public function delete(Request $request): Response
    {
        return $this->handle(fn (): array => (new FileService())->delete(
            $this->intInput($request, 'id') ?: $this->intInput($request, 'file_id'),
            filter_var($request->input('force', false), FILTER_VALIDATE_BOOL)
        ));
    }

    /**
     * 获取文件下载信息
     */
    #[OperationLog('获取文件下载信息')]
    public function download(Request $request): Response
    {
        return $this->handle(fn (): array => (new FileService())->downloadInfo($this->intInput($request, 'id') ?: $this->intInput($request, 'file_id')));
    }

    private function handle(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode();
            if (!in_array($status, [401, 403, 429], true)) {
                $status = $exception->getMessage() === '请先登录' ? 401 : 400;
            }
            $code = match ($status) {
                401 => 40100,
                403 => 40300,
                429 => 42900,
                default => 40001,
            };

            return $this->fail($code, $exception->getMessage(), $status);
        }
    }

    private function intInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }
}
