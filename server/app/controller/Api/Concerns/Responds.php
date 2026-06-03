<?php

namespace app\controller\Api\Concerns;

use support\Response;

trait Responds
{
    protected function ok(array $data = [], string $message = 'ok'): Response
    {
        return json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ]);
    }

    protected function fail(int $code, string $message, int $status = 400, mixed $data = null): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ])->withStatus($status);
    }
}
