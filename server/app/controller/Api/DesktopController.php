<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\TableRecord as ChannelTable;
use app\server\CurrentContext;
use support\Request;
use support\Response;
use Throwable;

class DesktopController
{
    use Responds;

    /**
     * 查询桌面快捷方式
     */
    #[OperationLog('查询桌面快捷方式')]
    public function shortcuts(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            return $this->ok([
                'items' => ChannelTable::desktopShortcuts((int) $accountId),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /**
     * 保存桌面快捷方式
     */
    #[OperationLog('保存桌面快捷方式')]
    public function saveShortcuts(Request $request): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $items = $request->input('items', []);
            if (!is_array($items)) {
                $items = [];
            }

            ChannelTable::replaceDesktopShortcuts((int) $accountId, $items, date('Y-m-d H:i:s'));

            return $this->shortcuts($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }
}
