<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\config\ConfigService;
use app\server\file\FileService;
use support\Request;
use support\Response;
use Throwable;

class ConfigController
{
    use Responds;

    private const SCHOOL_ADMIN_ROLE_TYPES = ['super_admin', 'school_admin'];

    public function items(Request $request): Response
    {
        if (!$this->canManageSchoolConfig()) {
            return $this->fail(40300, '无操作权限', 403);
        }

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

    public function loginPage(Request $request): Response
    {
        try {
            return $this->ok($this->loginPageData());
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    public function uploadLoginBackground(Request $request): Response
    {
        if (!$this->canManageSchoolConfig()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $result = (new FileService())->upload($request, [
                'category' => 'login_background',
                'is_temporary' => false,
                'require_md5' => false,
                'max_size' => 8 * 1024 * 1024,
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            ]);

            (new ConfigService())->set(
                'system',
                'login_background_url',
                $result['url'],
                'PC 登录页学校背景图'
            );

            return $this->ok($this->loginPageData(), '已上传');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    public function save(Request $request): Response
    {
        if (!$this->canManageSchoolConfig()) {
            return $this->fail(40300, '无操作权限', 403);
        }

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

    private function loginPageData(): array
    {
        $backgroundUrl = '';
        if (CurrentContext::get('school_connection')) {
            $backgroundUrl = (string) ((new ConfigService())->get('system.login_background_url') ?? '');
        }

        return [
            'school_code' => CurrentContext::schoolCode() ?: '2184',
            'school_name' => CurrentContext::get('school_name') ?: '成都锦城学院',
            'login_background_url' => $backgroundUrl,
        ];
    }

    private function canManageSchoolConfig(): bool
    {
        return in_array(CurrentContext::roleType(), self::SCHOOL_ADMIN_ROLE_TYPES, true);
    }
}
