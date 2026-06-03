<?php

namespace app\server\config;

use app\model\channel\ConfigGroup;
use app\model\channel\ConfigItem;
use app\server\CurrentContext;
use InvalidArgumentException;
use RuntimeException;

class ConfigService
{
    public function get(string $name, int $collegeId = 0, int $userId = 0): mixed
    {
        [$groupCode, $key] = $this->splitName($name);
        $group = $this->group($groupCode);
        if (!$group) {
            return null;
        }

        $item = ConfigItem::query()
            ->where('group_id', $group['id'])
            ->where('key', $key)
            ->whereIn('college_id', [$collegeId, 0])
            ->whereIn('user_id', [$userId, 0])
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderByDesc('user_id')
            ->orderByDesc('college_id')
            ->first(['value']);

        return $item ? json_decode((string) $item->value, true) : null;
    }

    public function list(string $groupCode): array
    {
        $group = $this->group($groupCode);
        if (!$group) {
            return [];
        }

        return ConfigItem::query()
            ->where('group_id', $group['id'])
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->get(['id', 'key', 'value', 'college_id', 'user_id', 'description', 'sort'])
            ->map(static fn ($item): array => [
                'id' => $item->id,
                'key' => $item->key,
                'value' => json_decode((string) $item->value, true),
                'college_id' => (int) $item->college_id,
                'user_id' => (int) $item->user_id,
                'description' => $item->description,
                'sort' => (int) $item->sort,
            ])
            ->all();
    }

    public function set(string $groupCode, string $key, mixed $value, string $description = '', int $collegeId = 0, int $userId = 0): array
    {
        $this->assertTenant();
        $this->assertKey($groupCode);
        $this->assertKey($key);

        $group = $this->group($groupCode);
        if (!$group) {
            throw new RuntimeException('配置分组不存在');
        }

        ConfigItem::query()->updateOrCreate(
            [
                'group_id' => $group['id'],
                'key' => $key,
                'college_id' => $collegeId,
                'user_id' => $userId,
            ],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'description' => $description,
                'status' => 'enabled',
                'deleted_at' => null,
            ]
        );

        return [
            'group' => $groupCode,
            'key' => $key,
            'value' => $value,
            'college_id' => $collegeId,
            'user_id' => $userId,
        ];
    }

    private function group(string $groupCode): ?array
    {
        $this->assertTenant();
        $this->assertKey($groupCode);

        $group = ConfigGroup::query()
            ->where('code', $groupCode)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'name']);

        return $group ? $group->toArray() : null;
    }

    private function splitName(string $name): array
    {
        $parts = explode('.', $name, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('配置名格式应为 group.key');
        }

        $this->assertKey($parts[0]);
        $this->assertKey($parts[1]);

        return $parts;
    }

    private function assertKey(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException('配置键格式无效');
        }
    }

    private function assertTenant(): void
    {
        if (!CurrentContext::get('tenant_connection')) {
            throw new RuntimeException('租户连接未解析');
        }
    }
}
