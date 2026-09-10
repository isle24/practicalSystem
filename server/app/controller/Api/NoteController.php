<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\note\NoteService;
use support\Request;
use support\Response;
use Throwable;

class NoteController
{
    use Responds;

    /** 查询个人笔记。 */
    #[OperationLog('查询个人笔记')]
    public function list(Request $request): Response
    {
        return $this->respond(fn () => (new NoteService())->page($request->all()));
    }

    /** 查看个人笔记内容。 */
    #[OperationLog('查看个人笔记')]
    public function detail(Request $request): Response
    {
        return $this->respond(fn () => ['item' => (new NoteService())->detail((int) $request->input('id'))]);
    }

    /** 保存个人 Markdown 笔记。 */
    #[OperationLog('保存个人笔记')]
    public function save(Request $request): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        return $this->respond(fn () => ['item' => (new NoteService())->save($request->all())]);
    }

    /** 将笔记移入个人回收站。 */
    #[OperationLog('删除个人笔记')]
    public function delete(Request $request): Response
    {
        return $this->change($request, 'delete');
    }

    /** 恢复个人笔记。 */
    #[OperationLog('恢复个人笔记')]
    public function restore(Request $request): Response
    {
        return $this->change($request, 'restore');
    }

    /** 永久删除个人回收站笔记。 */
    #[OperationLog('永久删除个人笔记')]
    public function purge(Request $request): Response
    {
        return $this->change($request, 'purge');
    }

    /** 统一提交笔记状态操作。 */
    private function change(Request $request, string $action): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        return $this->respond(function () use ($request, $action): array {
            (new NoteService())->change($request->all(), $action);
            return [];
        });
    }

    /** 输出业务错误并保留状态码。 */
    private function respond(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $error) {
            $status = in_array($error->getCode(), [400, 403, 404, 409], true) ? $error->getCode() : 500;
            return $this->fail($status * 100, $status === 500 ? '笔记服务暂不可用，请联系管理员确认数据库已更新' : $error->getMessage(), $status);
        }
    }
}
