<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\config\ConfigService;
use support\Request;
use support\Response;
use Throwable;

class ConfigController
{
    use Responds;

    public function items(Request $request): Response
    {
        try {
            $group = (string) $request->input('group', 'system');
            return $this->ok([
                'group' => $group,
                'items' => (new ConfigService())->list($group),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function save(Request $request): Response
    {
        try {
            $group = (string) $request->input('group');
            $key = (string) $request->input('key');
            $value = $request->input('value');
            $description = (string) $request->input('description', '');

            return $this->ok((new ConfigService())->set($group, $key, $value, $description));
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }
}
