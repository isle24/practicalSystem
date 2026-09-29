<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\message\MessageChannelSettingsService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class MessageChannelSettingsController
{
    use Responds;

    public function settings(Request $request): Response
    {
        return $this->handle(fn () => (new MessageChannelSettingsService())->settings());
    }

    public function save(Request $request): Response
    {
        return $this->handle(fn () => (new MessageChannelSettingsService())->save($request->post()));
    }

    private function handle(callable $action): Response
    {
        if (!CurrentContext::schoolDatabaseId() || !in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true) || !in_array('config:manage', CurrentContext::permissionCodes(), true)) return $this->fail(40300, '无操作权限', 403);
        try { return $this->ok($action()); }
        catch (InvalidArgumentException $exception) { return $this->fail(42200, $exception->getMessage(), 422); }
        catch (Throwable $exception) { return $this->fail(50000, '通知渠道配置暂不可用', 500); }
    }
}
