<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\account\AccountImportService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class AccountImportController
{
    use Responds;

    public function tasks(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->tasks((string) $request->input('type', 'teacher')));
    }

    public function detail(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->detail((int) $request->input('id', 0)));
    }

    public function teacherTemplate(Request $request): Response
    {
        try {
            $file = $this->service()->teacherTemplate();
            return (new Response())->download($file['path'], $file['download_name'])->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    #[OperationLog('预览教师账号导入')]
    public function teacherPreview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->teacherPreview($request));
    }

    #[OperationLog('提交教师账号导入')]
    public function teacherStart(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->teacherStart(
            (int) $request->input('file_id', 0),
            (string) $request->input('request_key', ''),
            (string) $request->input('preview_token', '')
        ));
    }

    public function studentPreview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->studentPreview($this->filters($request)));
    }

    #[OperationLog('提交学生账号开通')]
    public function studentStart(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->studentStart($this->filters($request), (string) $request->input('password', ''), (string) $request->input('request_key', '')));
    }

    #[OperationLog('重试账号导入任务')]
    public function retry(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->retry((int) $request->input('id', 0)));
    }

    private function filters(Request $request): array
    {
        $filters = $request->input('filters', []);
        if (!is_array($filters)) {
            throw new InvalidArgumentException('筛选条件无效');
        }
        return $filters;
    }

    private function service(): AccountImportService
    {
        return new AccountImportService();
    }

    private function handle(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    private function failure(Throwable $exception): Response
    {
        $status = $exception instanceof InvalidArgumentException ? 400 : (int) $exception->getCode();
        if (!in_array($status, [400, 403, 404, 409, 422, 503], true)) {
            $status = 500;
        }
        $message = $status === 500 ? '账号导入处理失败，请检查数据库结构与服务日志' : $exception->getMessage();
        return $this->fail($status * 100, $message, $status);
    }
}
