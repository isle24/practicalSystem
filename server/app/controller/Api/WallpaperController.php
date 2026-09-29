<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\wallpaper\WallpaperService;
use support\Request;
use support\Response;
use Throwable;

class WallpaperController
{
    use Responds;

    #[OperationLog('查看壁纸库')]
    public function list(Request $request): Response
    {
        return $this->handle(fn () => (new WallpaperService())->list((string) $request->get('scope', 'mine'), (int) $request->get('page', 1), (int) $request->get('page_size', 20)));
    }

    #[OperationLog('上传壁纸')]
    public function upload(Request $request): Response
    {
        return $this->handle(fn () => (new WallpaperService())->upload($request));
    }

    #[OperationLog('查看可导入历史图片')]
    public function importable(Request $request): Response
    {
        return $this->handle(fn () => (new WallpaperService())->importable((int) $request->get('page', 1), (int) $request->get('page_size', 20)));
    }

    #[OperationLog('导入历史图片为壁纸')]
    public function import(Request $request): Response
    {
        return $this->handle(fn () => (new WallpaperService())->import((int) $request->input('file_id', 0)));
    }

    #[OperationLog('分享壁纸')]
    public function share(Request $request): Response
    {
        return $this->handle(fn () => (new WallpaperService())->share((int) $request->input('id', 0), filter_var($request->input('shared', false), FILTER_VALIDATE_BOOL)));
    }

    #[OperationLog('应用壁纸')]
    public function apply(Request $request): Response
    {
        return $this->handle(fn () => (new WallpaperService())->apply($request->input('id') === null ? null : (int) $request->input('id'), (string) $request->input('mode', 'item')));
    }

    private function handle(callable $action): Response
    {
        try { return $this->ok($action()); }
        catch (Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [401, 403, 409, 422, 429], true) ? (int) $exception->getCode() : 400;
            return $this->fail($status * 100, $exception->getMessage(), $status);
        }
    }
}
