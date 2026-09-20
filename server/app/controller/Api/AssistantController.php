<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\AssistantRecord;
use app\server\CurrentContext;
use app\server\message\AssistantService;
use support\Request;
use support\Response;
use Throwable;

/** 个人问答与分级助手配置接口。 */
class AssistantController
{
    use Responds;

    /** 读取个人配置、学校启用状态及管理员可见配置。 */
    #[OperationLog('查看问答助手配置')]
    public function settings(Request $request): Response
    {
        return $this->handle(fn () => (new AssistantService())->settings());
    }

    /** 保存本人配置或经授权的学校配置。 */
    #[OperationLog('配置问答助手')]
    public function saveSettings(Request $request): Response
    {
        return $this->post($request, fn () => (new AssistantService())->saveSettings($request->all()));
    }

    /** 列出个人会话。 */
    #[OperationLog('查询个人问答会话')]
    public function threads(Request $request): Response
    {
        return $this->handle(fn () => AssistantRecord::threads((int) CurrentContext::accountId(), max(1, (int) $request->input('page', 1))));
    }

    /** 查看本人会话轮次。 */
    #[OperationLog('查看个人问答记录')]
    public function turns(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            AssistantRecord::expirePending((int) CurrentContext::accountId());
            return ['items' => AssistantRecord::turns((int) CurrentContext::accountId(), (int) $request->input('thread_id'), (int) $request->input('before', 0))];
        });
    }

    /** 提交个人问题。 */
    #[OperationLog('向问答助手提问')]
    public function ask(Request $request): Response
    {
        return $this->post($request, fn () => (new AssistantService())->ask($request->all()));
    }

    /** 限定写入方法。 */
    private function post(Request $request, callable $callback): Response
    {
        return $request->method() === 'POST' ? $this->handle($callback) : $this->fail(40500, '请使用 POST', 405);
    }

    /** 统一转换可公开错误。 */
    private function handle(callable $callback): Response
    {
        try { return $this->ok($callback())->withHeader('Cache-Control', 'private, no-store'); }
        catch (Throwable $e) {
            $code = in_array((int) $e->getCode(), [400, 403, 404, 409, 429], true) ? (int) $e->getCode() : 500;
            return $this->fail($code * 100, $code === 500 ? '问答服务暂不可用，请联系管理员检查配置与数据库升级' : $e->getMessage(), $code);
        }
    }
}
