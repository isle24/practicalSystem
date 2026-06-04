<?php

namespace app\controller\Api;

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

    public function summary(Request $request): Response
    {
        try {
            return $this->ok((new MessageService())->summary((int) CurrentContext::accountId()));
        } catch (Throwable $exception) {
            return $this->fail($this->statusCode($exception), $exception->getMessage(), $this->httpStatus($exception));
        }
    }

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
