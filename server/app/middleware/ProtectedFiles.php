<?php

namespace app\middleware;

use app\model\channel\FileAccessRecord;
use app\model\channel\FileRecord;
use app\server\CurrentContext;
use app\server\wechat\WechatBindingService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 对静态文件地址应用学校和业务读取权限。 */
class ProtectedFiles implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $path = rawurldecode($request->path());
        $root = realpath(public_path() . '/files');
        $resolved = realpath(public_path() . '/' . ltrim($path, '/'));
        if ($root && $resolved && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            $path = '/files/' . substr($resolved, strlen($root) + 1);
        }
        if (!str_starts_with($path, '/files/')) {
            return $handler($request);
        }
        return (new SchoolMiddleware())->process($request, fn ($request): Response =>
            (new AuthMiddleware())->process($request, function ($request) use ($path, $handler): Response {
                foreach (FileRecord::idsByUrl($path) as $id) {
                    $file = FileRecord::detailById((int) $id);
                    $binding = (array) CurrentContext::get('wechat_binding', []);
                    if ($file && !FileAccessRecord::publicImage($file) && (new WechatBindingService())->isBlocked($binding)) {
                        return json(['code' => 40310, 'message' => '请先完成企业微信绑定及身份确认', 'data' => null])
                            ->withStatus(403)->withHeader('Cache-Control', 'no-store');
                    }
                    if ($file && (FileAccessRecord::publicImage($file) || FileAccessRecord::readable($file))) {
                        return $handler($request)->withHeaders([
                            'Cache-Control' => FileAccessRecord::publicImage($file) ? 'public, max-age=3600' : 'private, no-store',
                            'X-Content-Type-Options' => 'nosniff',
                        ]);
                    }
                }
                $status = CurrentContext::accountId() ? 403 : 401;
                return json(['code' => $status * 100, 'message' => $status === 401 ? '请先登录' : '无文件访问权限', 'data' => null])
                    ->withStatus($status)->withHeader('Cache-Control', 'no-store');
            })
        );
    }
}
