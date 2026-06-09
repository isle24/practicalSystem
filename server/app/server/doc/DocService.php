<?php

namespace app\server\doc;

use app\model\channel\DocRecord;
use app\server\CurrentContext;
use InvalidArgumentException;

class DocService
{
    private const MANAGE_ROLES = ['super_admin', 'school_admin'];
    private const ARTICLE_STATUSES = ['draft', 'published', 'archived'];

    public function categories(): array
    {
        $this->requireLogin();
        $canManage = $this->canManage();
        $roleType = CurrentContext::roleType();

        return [
            'items' => DocRecord::categoryRows($canManage, $roleType),
            'tree' => DocRecord::categoryTree($canManage, $roleType),
        ];
    }

    public function list(array $filters): array
    {
        $this->requireLogin();
        $this->requireView();

        return DocRecord::articlePage($filters, $this->canManage(), CurrentContext::roleType());
    }

    public function detail(int $id): array
    {
        $this->requireLogin();
        $this->requireView();
        if ($id <= 0) {
            throw new InvalidArgumentException('id 无效');
        }

        $article = DocRecord::articleDetail($id, $this->canManage(), true, CurrentContext::roleType());
        if (!$article) {
            throw new InvalidArgumentException('文档不存在或未发布');
        }

        return ['article' => $article];
    }

    public function history(int $articleId): array
    {
        $this->requireManage();
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id 无效');
        }

        return [
            'items' => DocRecord::historyRows($articleId),
        ];
    }

    public function saveCategory(array $payload): array
    {
        $this->requireManage();
        $name = $this->requiredString($payload['name'] ?? '', '分类名称', 120);
        $code = $this->nullableString($payload['code'] ?? null, 120);
        $id = $this->intValue($payload['id'] ?? 0);

        $categoryId = DocRecord::saveCategory([
            'id' => $id,
            'parent_id' => max(0, $this->intValue($payload['parent_id'] ?? 0)),
            'name' => $name,
            'code' => $code ?: null,
            'icon' => $this->nullableString($payload['icon'] ?? null, 80),
            'sort' => $this->intValue($payload['sort'] ?? 0),
            'status' => $this->enum($payload['status'] ?? 'enabled', ['enabled', 'disabled'], 'enabled'),
            'deleted_at' => null,
        ]);

        return [
            'id' => $categoryId,
            'categories' => $this->categories(),
        ];
    }

    public function saveArticle(array $payload): array
    {
        $this->requireManage();
        $status = $this->enum($payload['status'] ?? 'draft', self::ARTICLE_STATUSES, 'draft');
        $now = date('Y-m-d H:i:s');
        $articleId = DocRecord::saveArticle([
            'id' => $this->intValue($payload['id'] ?? 0),
            'category_id' => $this->nullableInt($payload['category_id'] ?? null),
            'title' => $this->requiredString($payload['title'] ?? '', '文档标题', 180),
            'name' => $this->requiredString($payload['title'] ?? '', '文档标题', 180),
            'content' => $this->cleanContent((string) ($payload['content'] ?? '')),
            'version' => $this->nullableString($payload['version'] ?? null, 40) ?: '1.0',
            'visible_roles' => $this->visibleRoles($payload['visible_roles'] ?? null, $this->nullableInt($payload['category_id'] ?? null)),
            'status' => $status,
            'author_id' => CurrentContext::accountId(),
            'published_at' => $status === 'published' ? ($payload['published_at'] ?? $now) : null,
            'deleted_at' => null,
            'updated_at' => $now,
        ], (int) CurrentContext::accountId(), $this->nullableString($payload['change_note'] ?? null, 500) ?: '保存文档');

        return [
            'id' => $articleId,
            'article' => DocRecord::articleDetail($articleId, true, false, CurrentContext::roleType()),
        ];
    }

    public function deleteArticle(int $id): array
    {
        $this->requireManage();
        if ($id <= 0) {
            throw new InvalidArgumentException('id 无效');
        }

        return [
            'affected' => DocRecord::softDeleteArticle($id, date('Y-m-d H:i:s')),
        ];
    }

    private function requireView(): void
    {
        if (!$this->canView()) {
            throw new InvalidArgumentException('无操作权限', 403);
        }
    }

    private function requireManage(): void
    {
        $this->requireLogin();
        if (!$this->canManage()) {
            throw new InvalidArgumentException('无操作权限', 403);
        }
    }

    private function canView(): bool
    {
        return $this->canManage() || in_array('doc:view', CurrentContext::permissionCodes(), true);
    }

    private function canManage(): bool
    {
        return in_array(CurrentContext::roleType(), self::MANAGE_ROLES, true)
            || in_array('doc:manage', CurrentContext::permissionCodes(), true);
    }

    private function requireLogin(): void
    {
        if (!CurrentContext::accountId()) {
            throw new InvalidArgumentException('请先登录', 401);
        }
    }

    private function requiredString(mixed $value, string $label, int $maxLength): string
    {
        $text = $this->nullableString($value, $maxLength) ?? '';
        if ($text === '') {
            throw new InvalidArgumentException("请填写{$label}");
        }

        return $text;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $maxLength);
    }

    private function cleanContent(string $content): string
    {
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $content) ?? '';
        $content = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $content) ?? '';
        return preg_replace('/javascript\s*:/i', '', $content) ?? '';
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function enum(mixed $value, array $values, string $default): string
    {
        $value = (string) $value;
        return in_array($value, $values, true) ? $value : $default;
    }

    private function visibleRoles(mixed $value, ?int $categoryId): array
    {
        $roles = is_array($value) ? $value : explode(',', (string) ($value ?? ''));
        $roles = array_values(array_unique(array_filter(array_map(static fn ($role): string => trim((string) $role), $roles))));
        $allowed = ['all', 'admin', 'super_admin', 'school_admin', 'college_admin', 'profession_admin', 'teacher', 'student', 'enterprise'];
        $roles = array_values(array_filter($roles, static fn (string $role): bool => in_array($role, $allowed, true)));
        return $roles ?: DocRecord::defaultVisibleRolesForCategory($categoryId);
    }
}
