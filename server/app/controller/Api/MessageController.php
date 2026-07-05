<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\message\MessageService;
use support\Request;
use support\Response;
use Throwable;

class MessageController
{
    use Responds;

    private const ADMIN_ROLES = ['super_admin', 'school_admin'];
    private const TEMPLATE_ROLES = ['super_admin'];

    /**
     * 查询消息列表
     */
    #[OperationLog('查询消息列表')]
    public function list(Request $request): Response
    {
        try {
            return $this->ok((new MessageService())->inbox((int) CurrentContext::accountId(), [
                'page' => max(1, $this->intInput($request, 'page') ?: 1),
                'page_size' => min(100, max(10, $this->intInput($request, 'page_size') ?: 20)),
                'type' => $request->input('type', 'all'),
                'status' => $request->input('status', 'all'),
                'keyword' => $request->input('keyword', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
            ]));
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 查询消息摘要
     */
    #[OperationLog('查询消息摘要')]
    public function summary(Request $request): Response
    {
        try {
            return $this->ok((new MessageService())->summary((int) CurrentContext::accountId()));
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 标记消息已读
     */
    #[OperationLog('标记消息已读')]
    public function read(Request $request): Response
    {
        try {
            return $this->ok((new MessageService())->markRead(
                (int) CurrentContext::accountId(),
                (array) $request->input('ids', []),
                $this->boolInput($request, 'all')
            ), '已读状态已更新');
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 查询消息发送对象
     */
    #[OperationLog('查询消息发送对象')]
    public function targets(Request $request): Response
    {
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLES, true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok((new MessageService())->targets([
                'keyword' => $request->input('keyword', ''),
                'role_type' => $request->input('role_type', ''),
                'limit' => $this->intInput($request, 'limit') ?: 200,
            ]));
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 查询消息模板
     */
    #[OperationLog('查询消息模板')]
    public function templates(Request $request): Response
    {
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLES, true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $isTemplateManager = in_array(CurrentContext::roleType(), self::TEMPLATE_ROLES, true);
            return $this->ok((new MessageService())->templates([
                'page' => max(1, $this->intInput($request, 'page') ?: 1),
                'page_size' => min(100, max(10, $this->intInput($request, 'page_size') ?: 20)),
                'type' => $request->input('type', 'all'),
                'status' => $isTemplateManager ? $request->input('status', 'all') : 'enabled',
                'keyword' => $request->input('keyword', ''),
            ]));
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 保存消息模板
     */
    #[OperationLog('保存消息模板')]
    public function saveTemplate(Request $request): Response
    {
        if (!in_array(CurrentContext::roleType(), self::TEMPLATE_ROLES, true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok((new MessageService())->saveTemplate((array) $request->all()), '模板已保存');
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 同步默认消息模板
     */
    #[OperationLog('同步默认消息模板')]
    public function syncTemplates(Request $request): Response
    {
        if (!in_array(CurrentContext::roleType(), self::TEMPLATE_ROLES, true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok((new MessageService())->syncDefaultTemplates(), '默认模板已同步');
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 删除消息模板
     */
    #[OperationLog('删除消息模板')]
    public function deleteTemplate(Request $request): Response
    {
        if (!in_array(CurrentContext::roleType(), self::TEMPLATE_ROLES, true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok((new MessageService())->deleteTemplate($this->intInput($request, 'id')), '模板已删除');
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    /**
     * 发送消息
     */
    #[OperationLog('发送消息')]
    public function send(Request $request): Response
    {
        if (!in_array(CurrentContext::roleType(), self::ADMIN_ROLES, true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok((new MessageService())->send(
                (array) $request->all(),
                (int) (CurrentContext::accountId() ?: 0),
                (string) (CurrentContext::get('user_name') ?: CurrentContext::get('login_name') ?: '系统')
            ), '消息已发送');
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

    private function intInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }

    private function boolInput(Request $request, string $key): bool
    {
        $value = $request->input($key, false);
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    private function statusCode(Throwable $exception): int
    {
        return $exception->getMessage() === '请先登录' ? 40100 : 40001;
    }

    private function httpStatus(Throwable $exception): int
    {
        return $exception->getMessage() === '请先登录' ? 401 : 400;
    }
}
