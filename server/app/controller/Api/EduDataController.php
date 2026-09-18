<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\edu\EduImportService;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use support\Response;
use Throwable;

class EduDataController
{
    use Responds;

    #[OperationLog('下载教务导入模板')]
    public function template(Request $request): Response
    {
        try {
            $file = $this->service()->template((string) $request->input('type', ''));
            return (new Response())->download($file['path'], $file['download_name'])->withHeader('Cache-Control', 'private, max-age=3600');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    #[OperationLog('上传教务数据文件')]
    public function upload(Request $request): Response
    {
        return $this->guardPost($request, fn (): array => $this->service()->upload($request, (string) $request->input('type', '')), '导入任务已排队');
    }

    #[OperationLog('查询教务导入批次')]
    public function batches(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->batches($this->filters($request)));
    }

    #[OperationLog('查看教务导入批次详情')]
    public function batchDetail(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->detail($this->int($request, 'id')));
    }

    #[OperationLog('发布教务导入批次')]
    public function publish(Request $request): Response
    {
        return $this->guardPost($request, fn (): array => $this->service()->publish($this->int($request, 'id'), $this->accountId()), '批次已发布');
    }

    #[OperationLog('取消教务导入批次')]
    public function cancel(Request $request): Response
    {
        return $this->guardPost($request, fn (): array => $this->service()->cancel($this->int($request, 'id'), $this->accountId()), '批次已取消');
    }

    #[OperationLog('查询教务学生源数据')]
    public function students(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->page('student', $this->filters($request)));
    }

    #[OperationLog('查询教务开课计划源数据')]
    public function teachingPlans(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->page('teaching_plan', $this->filters($request)));
    }

    #[OperationLog('查询教务开课情况源数据')]
    public function courseOfferings(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->page('course_offering', $this->filters($request)));
    }

    #[OperationLog('查询教务导入问题')]
    public function issues(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->issues($this->int($request, 'batch_id'), $this->filters($request)));
    }

    #[OperationLog('查询教务导入差异')]
    public function changes(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->changes($this->int($request, 'batch_id'), $this->filters($request)));
    }

    #[OperationLog('处理教务导入问题')]
    public function resolveIssue(Request $request): Response
    {
        return $this->guardPost($request, fn (): array => $this->service()->resolveIssue($this->int($request, 'id'), $this->accountId()), '导入问题已处理');
    }

    #[OperationLog('查询教务业务候选')]
    public function candidates(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->candidates($this->filters($request)));
    }

    #[OperationLog('人工分类教务业务候选')]
    public function classifyCandidate(Request $request): Response
    {
        return $this->guardPost($request, fn (): array => $this->service()->classifyCandidate($this->int($request, 'id'), (string) $request->input('business_type', ''), $this->accountId()), '候选项已分类');
    }

    #[OperationLog('确认教务业务候选')]
    public function confirmCandidates(Request $request): Response
    {
        $ids = $request->input('ids', []);
        return $this->guardPost($request, fn (): array => $this->service()->confirmCandidates(is_array($ids) ? $ids : [], $this->accountId()), '业务草稿已生成');
    }

    private function service(): EduImportService
    {
        return new EduImportService();
    }

    private function handle(callable $callback, string $message = 'ok'): Response
    {
        try {
            return $this->ok($callback(), $message);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * 写操作仅允许 POST，避免通过 GET 触发导入、发布与取消。
     */
    private function guardPost(Request $request, callable $callback, string $message = 'ok'): Response
    {
        if (strtoupper($request->method()) !== 'POST') {
            return $this->fail(40500, '仅支持 POST 请求', 405);
        }
        return $this->handle($callback, $message);
    }

    private function failure(Throwable $exception): Response
    {
        error_log('edu data api failed: ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine());
        $status = (int) $exception->getCode();
        if (!in_array($status, [401, 403, 405, 409, 422, 429, 503], true)) {
            $status = $exception instanceof InvalidArgumentException ? 400 : 500;
        }
        $code = match ($status) {
            401 => 40100,
            403 => 40300,
            405 => 40500,
            409 => 40900,
            422 => 42200,
            429 => 42900,
            503 => 50300,
            default => 40001,
        };
        $debug = $this->debugDetailAllowed()
            ? ['file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => array_slice($exception->getTrace(), 0, 5)]
            : null;
        // 500 类错误可能包含 SQL 与文件路径，仅返回通用提示，细节保留在日志中
        $message = $status >= 500 && !$debug ? '服务处理失败，请稍后重试或联系管理员' : $exception->getMessage();
        return $this->fail($code, $message, $status, $debug);
    }

    /**
     * 仅本机环境且显式开启调试时返回调用栈，避免向客户端泄露文件路径与内部数据。
     */
    private function debugDetailAllowed(): bool
    {
        if (!(bool) config('app.debug', false)) {
            return false;
        }
        $remote = (string) (\support\Context::get('remote_ip') ?? '');
        return in_array($remote, ['127.0.0.1', '::1'], true) || PHP_SAPI === 'cli';
    }

    private function accountId(): int
    {
        return (int) (\app\server\CurrentContext::accountId() ?: 0);
    }

    private function int(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }

    private function filters(Request $request): array
    {
        $keys = ['page', 'page_size', 'import_type', 'status', 'academic_year', 'semester', 'keyword', 'batch_id', 'source_status', 'mapping_status', 'business_type', 'candidate_status', 'change_type', 'issue_type', 'resolved'];
        $filters = [];
        foreach ($keys as $key) {
            $value = $request->input($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        return $filters;
    }
}
