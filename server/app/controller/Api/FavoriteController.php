<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\model\channel\TableRecord as ChannelTable;
use app\server\CurrentContext;
use app\server\file\FileService;
use support\Request;
use support\Response;
use Throwable;

class FavoriteController
{
    use Responds;

    /**
     * 查询收藏夹
     */
    #[OperationLog('查询收藏夹')]
    public function list(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            return $this->ok(ChannelTable::favoritePage((int) $accountId, [
                'page' => $this->optionalInt($request, 'page') ?? 1,
                'page_size' => $this->optionalInt($request, 'page_size') ?? 20,
                'keyword' => $this->stringInput($request, 'keyword', 120),
            ]));
        } catch (Throwable $exception) {
            return $this->fail(50000, $exception->getMessage(), 500);
        }
    }

    /**
     * 保存收藏夹
     */
    #[OperationLog('保存收藏夹')]
    public function save(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        $userId = CurrentContext::userId();
        if (!$accountId || !$userId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $values = [
                'id' => $this->optionalInt($request, 'id') ?? 0,
                'title' => $this->requiredString($request, 'title', 180),
                'url' => $this->urlInput($request),
                'icon_url' => $this->nullableString($request, 'icon_url', 500),
                'icon_file_id' => $this->optionalInt($request, 'icon_file_id'),
                'sort' => $this->optionalInt($request, 'sort') ?? 0,
                'status' => 'enabled',
            ];

            ChannelTable::saveFavorite((int) $accountId, (int) $userId, $values, date('Y-m-d H:i:s'));

            return $this->list($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    /**
     * 删除收藏夹
     */
    #[OperationLog('删除收藏夹')]
    public function delete(Request $request): Response
    {
        $accountId = CurrentContext::accountId();
        if (!$accountId) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $id = $this->requiredInt($request, 'id');
            ChannelTable::deleteFavorite((int) $accountId, $id, date('Y-m-d H:i:s'));

            return $this->list($request);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    /**
     * 上传收藏图标
     */
    #[OperationLog('上传收藏图标')]
    public function uploadIcon(Request $request): Response
    {
        if (!CurrentContext::accountId()) {
            return $this->fail(40100, '请先登录', 401);
        }

        try {
            $result = (new FileService())->upload($request, [
                'category' => 'favorite',
                'is_temporary' => false,
                'require_md5' => false,
                'max_size' => 2 * 1024 * 1024,
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'ico'],
            ]);

            return $this->ok([
                'file_id' => $result['file_id'],
                'url' => $result['url'],
            ], '已上传');
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function requiredInt(Request $request, string $key): int
    {
        $value = $request->input($key);
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException("{$key} 无效");
        }

        return (int) $value;
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private function requiredString(Request $request, string $key, int $maxLength): string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        if ($value === '') {
            throw new \InvalidArgumentException("{$key} 不能为空");
        }

        return $value;
    }

    private function nullableString(Request $request, string $key, int $maxLength): ?string
    {
        $value = $this->stringInput($request, $key, $maxLength);
        return $value === '' ? null : $value;
    }

    private function stringInput(Request $request, string $key, int $maxLength): string
    {
        $value = trim((string) $request->input($key, ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function urlInput(Request $request): string
    {
        $url = $this->requiredString($request, 'url', 500);
        if (!preg_match('/^https?:\/\//i', $url)) {
            throw new \InvalidArgumentException('收藏地址必须以 http:// 或 https:// 开头');
        }

        return $url;
    }
}
