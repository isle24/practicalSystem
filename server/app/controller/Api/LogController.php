<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\TableRecord as ChannelTable;
use app\server\CurrentContext;
use support\Request;
use support\Response;
use Throwable;

class LogController
{
    use Responds;

    #[OperationLog('查询操作日志')]
    public function list(Request $request): Response
    {
        if (!in_array('log:view', CurrentContext::permissionCodes(), true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $page = max(1, $this->intInput($request, 'page') ?: 1);
            $pageSize = min(100, max(10, $this->intInput($request, 'page_size') ?: 20));

            return $this->ok(ChannelTable::operationLogPage(CurrentContext::schoolDatabase() ?: '', [
                'page' => $page,
                'page_size' => $pageSize,
                'keyword' => $request->input('keyword', ''),
                'action' => $request->input('action', ''),
                'ip' => $request->input('ip', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
            ]));
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function intInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }
}
