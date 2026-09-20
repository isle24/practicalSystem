<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\plugin\PluginService;
use support\Request;
use support\Response;
use Throwable;

class PluginController
{
    use Responds;

    /** 查询受控 Web 插件目录。 */
    #[OperationLog('查询插件目录')]
    public function list(Request $request): Response
    {
        try {
            return $this->ok((new PluginService())->page([
                'page' => is_numeric($request->input('page')) ? (int) $request->input('page') : 1,
                'page_size' => is_numeric($request->input('page_size')) ? (int) $request->input('page_size') : 20,
                'keyword' => $request->input('keyword', ''),
            ]));
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode();
            $status = in_array($status, [401, 403], true) ? $status : 400;
            return $this->fail($status === 401 ? 40100 : 40001, $exception->getMessage(), $status);
        }
    }

    /** 保存插件目录配置。 */
    #[OperationLog('保存插件目录')]
    public function save(Request $request): Response
    {
        try {
            return $this->ok((new PluginService())->save((array) $request->all()), '插件已保存');
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode();
            $status = in_array($status, [403, 404], true) ? $status : 400;
            return $this->fail($status === 403 ? 40300 : 40001, $exception->getMessage(), $status);
        }
    }

    /** 删除插件目录配置。 */
    #[OperationLog('删除插件目录')]
    public function delete(Request $request): Response
    {
        try {
            (new PluginService())->delete((int) $request->input('id'));
            return $this->ok([], '插件已删除');
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode();
            $status = in_array($status, [403, 404], true) ? $status : 400;
            return $this->fail($status === 403 ? 40300 : 40001, $exception->getMessage(), $status);
        }
    }
}
