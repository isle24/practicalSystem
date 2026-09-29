<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\config\SchoolAppearanceService;
use support\Request;
use support\Response;
use Throwable;

class SchoolAppearanceController
{
    use Responds;

    #[OperationLog('查看学校外观设置')]
    public function settings(Request $request): Response
    {
        return $this->handle(fn (): array => (new SchoolAppearanceService())->settings());
    }

    #[OperationLog('上传学校标识')]
    public function uploadLogo(Request $request): Response
    {
        return $this->handle(fn (): array => (new SchoolAppearanceService())->upload($request, 'logo'));
    }

    #[OperationLog('上传学校登录背景')]
    public function uploadBackground(Request $request): Response
    {
        return $this->handle(fn (): array => (new SchoolAppearanceService())->upload($request, 'background'));
    }

    #[OperationLog('上传学校默认壁纸')]
    public function uploadWallpaper(Request $request): Response
    {
        return $this->handle(fn (): array => (new SchoolAppearanceService())->uploadWallpaper($request));
    }

    #[OperationLog('设置学校默认壁纸')]
    public function setWallpaper(Request $request): Response
    {
        return $this->handle(fn (): array => (new SchoolAppearanceService())->selectWallpaper((int) $request->input('id', 0)));
    }

    private function handle(callable $action): Response
    {
        try {
            return $this->ok($action());
        } catch (Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [401, 403, 409, 422, 429], true) ? (int) $exception->getCode() : 400;
            return $this->fail($status * 100, $exception->getMessage(), $status);
        }
    }
}
