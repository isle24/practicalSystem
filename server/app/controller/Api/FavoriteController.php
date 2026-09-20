<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\favorite\FavoriteService;
use app\server\file\FileService;
use support\Request;
use support\Response;
use Throwable;

class FavoriteController
{
    use Responds;

    /** 查询个人和学校共享收藏。 */
    #[OperationLog('查询收藏夹')]
    public function list(Request $request): Response
    {
        return $this->respond(fn () => (new FavoriteService())->page($request->all()));
    }

    /** 打开收藏前重新检查可见性及参数。 */
    #[OperationLog('读取收藏链接')]
    public function detail(Request $request): Response
    {
        return $this->respond(fn () => (new FavoriteService())->detail((int) $request->input('id', 0)));
    }

    /** 保存收藏及共享设置。 */
    #[OperationLog('保存收藏夹')]
    public function save(Request $request): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        return $this->respond(fn () => ['item' => (new FavoriteService())->save($request->all())]);
    }

    /** 取消收藏及其桌面可见性。 */
    #[OperationLog('删除收藏夹')]
    public function delete(Request $request): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        return $this->respond(function () use ($request): array {
            (new FavoriteService())->delete($request->all());
            return [];
        });
    }

    /** 上传当前账号的收藏图标。 */
    #[OperationLog('上传收藏图标')]
    public function uploadIcon(Request $request): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        return $this->respond(function () use ($request): array {
            $result = (new FileService())->upload($request, [
                'category' => 'favorite', 'is_temporary' => false, 'require_md5' => false,
                'max_size' => 2 * 1024 * 1024,
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'ico'],
            ]);
            return ['file_id' => $result['file_id'], 'url' => $result['url']];
        });
    }

    /** 保留权限和并发冲突状态码。 */
    private function respond(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $error) {
            $status = in_array($error->getCode(), [400, 403, 404, 409], true) ? $error->getCode() : 500;
            return $this->fail($status * 100, $status === 500 ? '收藏服务不可用，请联系管理员确认数据库结构' : $error->getMessage(), $status);
        }
    }
}
