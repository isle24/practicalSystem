<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\release\ReleaseService;
use app\server\release\GithubReleaseService;
use app\server\release\ReleaseUploadService;
use support\Request;
use support\Response;
use Throwable;

/** 版本说明与管理员发布入口。 */
class ReleaseController
{
    use Responds;

    /** 查看公开给登录用户的更新说明。 */
    #[OperationLog('查看更新说明')]
    public function list(Request $request): Response { return $this->respond(fn () => (new ReleaseService())->page($request->all())); }

    /** 管理版本并导入部署草稿。 */
    #[OperationLog('管理版本与更新')]
    public function adminList(Request $request): Response { return $this->respond(fn () => (new ReleaseService())->adminPage($request->all())); }

    /** 管理员查看版本内容和升级 SQL。 */
    #[OperationLog('查看版本及升级SQL')]
    public function detail(Request $request): Response { return $this->respond(fn () => (new ReleaseService())->detail((int) $request->input('id'))); }

    /** 保存更新说明草稿。 */
    #[OperationLog('保存更新说明草稿')]
    public function save(Request $request): Response { return $this->mutate($request, fn () => (new ReleaseService())->save($request->all())); }

    /** 发布学校版本。 */
    #[OperationLog('发布学校版本')]
    public function publish(Request $request): Response { return $this->mutate($request, fn () => (new ReleaseService())->publish($request->all())); }

    /** 撤回学校版本。 */
    #[OperationLog('撤回学校版本')]
    public function withdraw(Request $request): Response { return $this->mutate($request, fn () => (new ReleaseService())->publish($request->all(), true)); }

    /** 登记人工执行 SQL 的结果。 */
    #[OperationLog('登记升级SQL执行结果')]
    public function markSql(Request $request): Response { return $this->mutate($request, function () use ($request) { (new ReleaseService())->markSql($request->all()); return []; }); }

    /** 查询 GitHub 正式版本。 */
    #[OperationLog('查询GitHub客户端版本')]
    public function github(Request $request): Response { return $this->respond(fn () => ['items' => (new GithubReleaseService())->releases()]); }

    /** 导入 GitHub 版本草稿。 */
    #[OperationLog('导入客户端版本')]
    public function import(Request $request): Response { return $this->mutate($request, fn () => (new GithubReleaseService())->import((int) $request->input('id'))); }

    /** 重试客户端下载。 */
    #[OperationLog('重试客户端资产下载')]
    public function retry(Request $request): Response { return $this->mutate($request, function () use ($request) { (new ReleaseService())->enqueue((int) $request->input('id')); return []; }); }

    /** 创建安装包上传会话。 */
    #[OperationLog('创建客户端安装包上传')]
    public function beginUpload(Request $request): Response { return $this->mutate($request, fn () => (new ReleaseUploadService())->begin((int) $request->input('asset_id'))); }

    /** 接收安装包分片。 */
    #[OperationLog('上传客户端安装包分片')]
    public function uploadChunk(Request $request): Response { return $this->mutate($request, fn () => (new ReleaseUploadService())->chunk((string) $request->input('upload_id'), (int) $request->input('offset'), $request->file('file'))); }

    /** 校验并完成安装包上传。 */
    #[OperationLog('校验客户端安装包上传')]
    public function finishUpload(Request $request): Response { return $this->mutate($request, fn () => (new ReleaseUploadService())->finish((string) $request->input('upload_id'))); }

    /** 登录客户端获取适合本机的限时更新入口。 */
    #[OperationLog('检查客户端更新')]
    public function check(Request $request): Response { return $this->respond(fn () => ['update' => (new ReleaseService())->check((string) $request->input('platform'), (string) $request->input('current'))]); }

    /** 下载学校已发布客户端的手动安装包。 */
    #[OperationLog('查询客户端安装包')]
    public function packages(Request $request): Response { return $this->respond(fn () => ['items' => (new ReleaseService())->packages((int) $request->input('id'))]); }

    /** 网页版安装包下载入口，不公开草稿或 SQL。 */
    #[OperationLog('查看客户端下载入口')]
    public function downloads(Request $request): Response { return $this->respond(fn () => ['items' => (new ReleaseService())->downloads()])->withHeader('Cache-Control', 'no-store'); }

    /** 官方 updater 使用的限时清单。 */
    public function updateManifest(Request $request): Response
    {
        try {
            $ticket = (string) $request->input('ticket');
            [$release, $asset] = (new ReleaseService())->ticket($ticket);
            return json(['version' => $release['version'], 'notes' => strip_tags($release['published_notes_html']), 'pub_date' => date(DATE_RFC3339, strtotime($release['published_at'])),
                'signature' => $asset['signature'], 'url' => $this->origin($request) . '/api/release/download?ticket=' . $ticket])->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $e) { return $this->fail(40300, '升级凭据已失效，请重新检查更新', 403); }
    }

    /** 根据限时凭据下载已发布安装包。 */
    public function download(Request $request): Response
    {
        try {
            $service = new ReleaseService();
            [, $asset] = $service->ticket((string) $request->input('ticket'));
            return (new Response())->download($service->downloadPath($asset), $asset['file_name'])->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $e) { return $this->fail(40300, '下载凭据失效或安装文件不可用', 403); }
    }

    /** 仅接受提交请求的变更操作。 */
    private function mutate(Request $request, callable $callback): Response
    {
        if ($request->method() !== 'POST') return $this->fail(40500, '请使用 POST 请求', 405);
        return $this->respond($callback);
    }

    /** 统一返回可展示的错误。 */
    private function respond(callable $callback): Response
    {
        try { return $this->ok($callback()); }
        catch (Throwable $e) {
            $status = in_array($e->getCode(), [400, 403, 404, 409, 429, 502, 503], true) ? $e->getCode() : 500;
            return $this->fail($status * 100, $status === 500 ? '版本服务不可用，请确认数据库结构和服务配置' : $e->getMessage(), $status);
        }
    }

    /** 下载地址沿用学校请求域名，生产反向代理需传递 HTTPS 协议。 */
    private function origin(Request $request): string
    {
        $scheme = strtolower((string) $request->header('x-forwarded-proto', '')) === 'https' || $request->header('x-forwarded-ssl') === 'on' ? 'https' : 'http';
        $host = $request->host();
        if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)) $scheme = 'https';
        return $scheme . '://' . $host;
    }
}
