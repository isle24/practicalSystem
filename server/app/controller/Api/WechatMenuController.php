<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\wechat\WechatMenuService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class WechatMenuController
{
    use Responds;

    #[OperationLog('同步企业微信应用菜单')]
    public function sync(Request $request): Response
    {
        if (!in_array('wechat:proxy:save', CurrentContext::permissionCodes(), true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok((new WechatMenuService())->syncSaved(), '企业微信菜单同步成功');
        } catch (InvalidArgumentException $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            return $this->fail(50200, $exception->getMessage(), 502);
        }
    }
}
