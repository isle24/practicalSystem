<?php

namespace app\server\template;

use app\model\channel\TemplateRecord;
use app\server\CurrentContext;
use app\server\file\FileService;
use InvalidArgumentException;
use support\Request;

class TemplateService
{
    private const MANAGE_ROLES = ['super_admin', 'school_admin'];

    public function categories(): array
    {
        $this->requireLogin();

        return [
            'items' => TemplateRecord::categoryRows($this->canManage()),
        ];
    }

    public function list(array $filters): array
    {
        $this->requireLogin();
        $this->requireView();

        return TemplateRecord::templatePage($filters, $this->canManage());
    }

    public function upload(Request $request): array
    {
        $this->requireManage();

        return (new FileService())->upload($request, [
            'category' => 'template',
            'is_temporary' => false,
            'require_md5' => false,
            'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv'],
        ]);
    }

    public function saveCategory(array $payload): array
    {
        $this->requireManage();
        $id = TemplateRecord::saveCategory([
            'id' => $this->intValue($payload['id'] ?? 0),
            'name' => $this->requiredString($payload['name'] ?? '', '分类名称', 120),
            'code' => $this->nullableString($payload['code'] ?? null, 120),
            'description' => $this->nullableString($payload['description'] ?? null, 500),
            'sort' => $this->intValue($payload['sort'] ?? 0),
            'flag' => $this->enum($payload['flag'] ?? 'on', ['on', 'off'], 'on'),
            'status' => $this->enum($payload['status'] ?? 'enabled', ['enabled', 'disabled'], 'enabled'),
            'deleted_at' => null,
        ]);

        return [
            'id' => $id,
            'categories' => $this->categories(),
        ];
    }

    public function saveTemplate(array $payload): array
    {
        $this->requireManage();
        $fileId = $this->nullableInt($payload['file_id'] ?? null);
        if (!$fileId) {
            throw new InvalidArgumentException('请上传模板文件');
        }

        $id = TemplateRecord::saveTemplate([
            'id' => $this->intValue($payload['id'] ?? 0),
            'category_id' => $this->nullableInt($payload['category_id'] ?? null),
            'name' => $this->requiredString($payload['name'] ?? '', '模板名称', 180),
            'description' => $this->nullableString($payload['description'] ?? null, 1000),
            'file_id' => $fileId,
            'version' => $this->nullableString($payload['version'] ?? null, 40) ?: '1.0',
            'flag' => $this->enum($payload['flag'] ?? 'on', ['on', 'off'], 'on'),
            'business_code' => $this->nullableString($payload['business_code'] ?? null, 80),
            'material_type' => $this->nullableString($payload['material_type'] ?? null, 60),
            'scope_type' => $this->enum($payload['scope_type'] ?? '', ['', 'plan', 'arrangement', 'student_task', 'plan_class'], '' ) ?: null,
            'practice_types' => $this->jsonArray($payload['practice_types'] ?? []),
            'status' => $this->enum($payload['status'] ?? 'enabled', ['enabled', 'disabled'], 'enabled'),
            'deleted_at' => null,
        ]);

        return [
            'id' => $id,
            'template' => TemplateRecord::detailById($id, true),
        ];
    }

    public function deleteTemplate(int $id): array
    {
        $this->requireManage();
        if ($id <= 0) {
            throw new InvalidArgumentException('id 无效');
        }

        return [
            'affected' => TemplateRecord::softDeleteTemplate($id, date('Y-m-d H:i:s')),
        ];
    }

    public function download(int $id): array
    {
        $this->requireLogin();
        $this->requireView();
        if ($id <= 0) {
            throw new InvalidArgumentException('id 无效');
        }

        $template = TemplateRecord::detailById($id, $this->canManage());
        if (!$template || empty($template['file_id'])) {
            throw new InvalidArgumentException('模板文件不存在');
        }

        TemplateRecord::incrementDownload($id);
        return array_merge([
            'template' => $template,
        ], (new FileService())->downloadInfo((int) $template['file_id']));
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
        return $this->canManage() || in_array('template:view', CurrentContext::permissionCodes(), true);
    }

    private function canManage(): bool
    {
        return in_array(CurrentContext::roleType(), self::MANAGE_ROLES, true)
            || in_array('template:manage', CurrentContext::permissionCodes(), true);
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
        return $text === '' ? null : mb_substr($text, 0, $maxLength);
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

    private function jsonArray(mixed $value): string
    {
        $items = is_array($value) ? $value : [];
        $items = array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items
        ))));

        return json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
