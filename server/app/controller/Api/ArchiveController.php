<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\archive\ArchiveService;
use support\Request;
use support\Response;
use Throwable;

class ArchiveController
{
    use Responds;

    /** 查询基础档案列表。 */
    #[OperationLog('查询基础档案列表')]
    public function list(Request $request): Response
    {
        return $this->handle(fn (): array => (new ArchiveService())->list($request));
    }

    /** 保存基础档案。 */
    #[OperationLog('保存基础档案')]
    public function save(Request $request): Response
    {
        return $this->handle(fn (): array => (new ArchiveService())->save($request), '已保存');
    }

    /** 删除基础档案。 */
    #[OperationLog('删除基础档案')]
    public function delete(Request $request): Response
    {
        return $this->handle(fn (): array => (new ArchiveService())->delete($request), '已删除');
    }

    /** 导入专业或班级基础档案。 */
    #[OperationLog('导入基础档案Excel')]
    public function importExcel(Request $request): Response
    {
        return $this->handle(fn (): array => (new ArchiveService())->importExcel($request), '导入完成');
    }

    /** 下载专业导入模板。 */
    public function professionTemplate(Request $request): Response
    {
        try {
            $file = (new ArchiveService())->professionTemplate();
            return (new Response())->download($file['path'], $file['download_name'])->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /** 预览专业导入文件。 */
    #[OperationLog('预览专业导入Excel')]
    public function previewProfessionImport(Request $request): Response
    {
        return $this->handle(fn (): array => (new ArchiveService())->previewProfessionImport($request));
    }

    /** 确认专业导入文件。 */
    #[OperationLog('确认专业导入Excel')]
    public function confirmProfessionImport(Request $request): Response
    {
        return $this->handle(fn (): array => (new ArchiveService())->confirmProfessionImport($request), '导入完成');
    }

    /** 统一处理档案接口响应。 */
    private function handle(callable $callback, string $message = 'ok'): Response
    {
        try {
            return $this->ok($callback(), $message);
        } catch (Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [401, 403], true)
                ? (int) $exception->getCode()
                : 400;
            $code = $status === 403 ? 40300 : ($status === 401 ? 40100 : 40001);
            return $this->fail($code, $exception->getMessage(), $status);
        }
    }
}
