<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\OperationGuide;
use app\server\CurrentContext;
use support\Request;
use support\Response;
use Throwable;

class GuideController
{
    use Responds;

    /**
     * 获取模块操作说明
     */
    #[OperationLog('获取模块操作说明')]
    public function current(Request $request): Response
    {
        if (!CurrentContext::accountId()) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $module = $this->stringInput($request, 'module', 60);
            if ($module === '') {
                return $this->fail(40001, 'module 不能为空', 400);
            }

            $guide = OperationGuide::enabledByModule($module);
            return $this->ok([
                'guide' => $guide ? $guide->toArray() : null,
            ]);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    /**
     * 查询操作说明列表
     */
    #[OperationLog('查询操作说明列表')]
    public function list(Request $request): Response
    {
        if (!in_array('guide:view', CurrentContext::permissionCodes(), true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            return $this->ok([
                'items' => OperationGuide::enabledItems(),
            ]);
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /**
     * 保存操作说明
     */
    #[OperationLog('保存操作说明')]
    public function save(Request $request): Response
    {
        if (!in_array('guide:save', CurrentContext::permissionCodes(), true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $module = $this->stringInput($request, 'module_key', 60);
            $title = $this->stringInput($request, 'title', 120);
            if ($module === '' || $title === '') {
                return $this->fail(40001, '模块和标题不能为空', 400);
            }

            OperationGuide::saveGuide([
                'module_key' => $module,
                'title' => $title,
                'content' => $this->cleanHtml((string) $request->input('content', '')),
                'sort' => $this->intInput($request, 'sort'),
                'status' => 'enabled',
                'deleted_at' => null,
            ]);

            return $this->list($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    /**
     * 删除操作说明
     */
    #[OperationLog('删除操作说明')]
    public function delete(Request $request): Response
    {
        if (!in_array('guide:delete', CurrentContext::permissionCodes(), true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $id = $this->intInput($request, 'id');
            if ($id <= 0) {
                return $this->fail(40001, 'id 不能为空', 400);
            }

            OperationGuide::softDeleteById($id);
            return $this->list($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        $value = trim((string) $request->input($key, ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function intInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }

    private function cleanHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? '';
        return preg_replace('/javascript\s*:/i', '', $html) ?? '';
    }
}
