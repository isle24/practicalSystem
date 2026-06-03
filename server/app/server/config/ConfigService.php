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

        return ConfigItem::enabledValue((int) $group['id'], $key, $collegeId, $userId);
    }

    public function list(string $groupCode): array
    {
        $group = $this->group($groupCode);
        if (!$group) {
            return [];
        }

        return ConfigItem::enabledList((int) $group['id']);
    }

    public function set(string $groupCode, string $key, mixed $value, string $description = '', int $collegeId = 0, int $userId = 0): array
    {
        $this->assertSchoolConnection();
        $this->assertKey($groupCode);
        $this->assertKey($key);

        $group = $this->group($groupCode);
        if (!$group) {
            throw new RuntimeException('配置分组不存在');
        }

        ConfigItem::saveValue((int) $group['id'], $key, $value, $description, $collegeId, $userId);

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
        $this->assertSchoolConnection();
        $this->assertKey($groupCode);

        $group = ConfigGroup::enabledByCode($groupCode);

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

    private function assertSchoolConnection(): void
    {
        if (!CurrentContext::get('school_connection')) {
            throw new RuntimeException('学校业务库连接未解析');
        }
    }
}
