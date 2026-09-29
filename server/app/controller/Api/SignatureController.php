<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\signature\SignatureService;
use support\Request;
use support\Response;
use Throwable;
use Webman\Http\UploadFile;

class SignatureController
{
    use Responds;

    public function current(Request $request): Response
    {
        return $this->handle(fn () => (new SignatureService())->current());
    }

    public function save(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            if ($request->header('x-signature-intent') !== 'personal') throw new \RuntimeException('请通过个人签名页面保存', 403);
            $file = $request->file('file');
            if (!$file instanceof UploadFile) throw new \RuntimeException('请上传 PNG 签名', 422);
            return (new SignatureService())->save($file, (string) $request->post('session_id', ''));
        });
    }

    public function session(Request $request): Response
    {
        return $this->handle(fn () => (new SignatureService())->createSession());
    }

    public function sessionStatus(Request $request): Response
    {
        return $this->handle(fn () => (new SignatureService())->sessionStatus((string) $request->get('session_id', ''), $request->get('mobile') === '1'));
    }

    public function cancelSession(Request $request): Response
    {
        return $this->handle(fn () => (new SignatureService())->cancelSession((string) $request->post('session_id', '')));
    }

    private function handle(callable $callback): Response
    {
        try { return $this->ok($callback()); }
        catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $status = in_array($code, [401, 403, 404, 409, 410, 422, 429, 503], true) ? $code : ($exception instanceof \InvalidArgumentException ? 422 : 500);
            if ($status === 500) \support\Log::error('Signature request failed', ['exception' => $exception]);
            return $this->fail($status, $status === 500 ? '签名处理失败，请稍后重试' : $exception->getMessage(), $status);
        }
    }
}
