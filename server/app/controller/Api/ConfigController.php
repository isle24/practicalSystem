<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\CurrentContext;
use app\server\config\ConfigService;
use app\server\config\SchoolAppearanceService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class ConfigController
{
    use Responds;

    private const SCHOOL_ADMIN_ROLE_TYPES = ['super_admin', 'school_admin'];

    /**
     * 查询系统配置
     */
    #[OperationLog('查询系统配置')]
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

    /**
     * 查询登录页配置
     */
    #[OperationLog('查询登录页配置')]
    public function loginPage(Request $request): Response
    {
        try {
            return $this->ok($this->loginPageData());
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /**
     * 上传登录背景图
     */
    #[OperationLog('上传登录背景图')]
    public function uploadLoginBackground(Request $request): Response
    {
        if (!$this->canManageSchoolConfig()) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok((new SchoolAppearanceService())->upload($request, 'background'), '已上传');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    /**
     * 保存系统配置
     */
    #[OperationLog('保存系统配置')]
    public function save(Request $request): Response
    {
        if (!$this->canManageSchoolConfig()) {
            return $this->fail(40300, '无操作权限', 403);
        }
        $groupInput = $request->input('group');
        if ($groupInput === 'assistant') return $this->fail(40001, '助手配置请使用 /api/assistant/save-settings', 400);
        if (is_string($groupInput) && trim($groupInput) === 'teacher_sync') {
            return $this->fail(40001, '教师同步配置请使用 /api/teacher-sync/save-config', 400);
        }

        try {
            $group = (string) $request->input('group');
            if ($group === 'workflow_message') return $this->fail(42200, '请通过审批消息通道设置维护此配置', 422);
            $key = (string) $request->input('key');
            $value = $request->input('value');
            $description = (string) $request->input('description', '');

            return $this->ok((new ConfigService())->set($group, $key, $value, $description));
        } catch (InvalidArgumentException $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    private function loginPageData(): array
    {
        return (new SchoolAppearanceService())->publicSettings();
    }

    private function canManageSchoolConfig(): bool
    {
        return in_array(CurrentContext::roleType(), self::SCHOOL_ADMIN_ROLE_TYPES, true);
    }
}
