<?php

namespace app\server\config;

use app\model\channel\ConfigGroup;
use app\model\channel\ConfigItem;
use app\server\CurrentContext;
use app\server\security\SecretCipher;
use InvalidArgumentException;
use RuntimeException;

class ConfigService
{
    /** 读取指定范围的配置值 */
    public function get(string $name, int $collegeId = 0, int $userId = 0): mixed
    {
        [$groupCode, $key] = $this->splitName($name);
        $group = $this->group($groupCode);
        if (!$group) {
            return null;
        }

        $value = ConfigItem::enabledValue((int) $group['id'], $key, $collegeId, $userId);
        if ($this->isEncryptedSecret($groupCode, $key) && is_string($value) && $value !== '') {
            return (new SecretCipher())->decrypt($value);
        }

        return $value;
    }

    /** 查询配置组内的启用配置项 */
    public function list(string $groupCode): array
    {
        $group = $this->group($groupCode);
        if (!$group) {
            return [];
        }

        $items = ConfigItem::enabledList((int) $group['id']);
        foreach ($items as &$item) {
            if ($this->isEncryptedSecret($groupCode, (string) ($item['key'] ?? ''))) {
                $item['value'] = SecretCipher::mask((string) ($item['value'] ?? ''));
            }
        }
        unset($item);

        return $items;
    }

    /** 保存指定范围的配置值 */
    public function set(string $groupCode, string $key, mixed $value, string $description = '', int $collegeId = 0, int $userId = 0): array
    {
        $this->assertSchoolConnection();
        $this->assertKey($groupCode);
        $this->assertKey($key);
        if ($groupCode === 'wechat' && $key === 'allow_unbound_login' && !is_bool($value)) {
            throw new InvalidArgumentException('允许未绑定企业微信登录必须为布尔值');
        }

        $group = ConfigGroup::enabledOrCreate($groupCode, $this->groupName($groupCode));

        $storedValue = $value;
        if ($this->isEncryptedSecret($groupCode, $key)) {
            if ((string) $value === '******') {
                return [
                    'group' => $groupCode,
                    'key' => $key,
                    'value' => '******',
                    'college_id' => $collegeId,
                    'user_id' => $userId,
                ];
            }
            $storedValue = (new SecretCipher())->encrypt((string) $value);
        }

        ConfigItem::saveValue((int) $group->id, $key, $storedValue, $description, $collegeId, $userId);

        return [
            'group' => $groupCode,
            'key' => $key,
            'value' => $this->isEncryptedSecret($groupCode, $key) ? SecretCipher::mask((string) $value) : $value,
            'college_id' => $collegeId,
            'user_id' => $userId,
        ];
    }

    /** 查询启用配置组 */
    private function group(string $groupCode): ?array
    {
        $this->assertSchoolConnection();
        $this->assertKey($groupCode);

        $group = ConfigGroup::enabledByCode($groupCode);

        return $group ? $group->toArray() : null;
    }

    /** 拆分完整配置名 */
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

    /** 返回配置组显示名称 */
    private function groupName(string $groupCode): string
    {
        return [
            'system' => '系统配置',
            'internship' => '实习配置',
            'education_plan_sync' => '教务计划同步配置',
            'training' => '实训配置',
            'lab' => '实验配置',
            'file' => '文件配置',
            'teacher_sync' => '教师同步配置',
            'assistant' => '问答助手配置',
        ][$groupCode] ?? $groupCode;
    }

    /** 判断配置项是否需要加密和脱敏 */
    private function isEncryptedSecret(string $groupCode, string $key): bool
    {
        return (in_array($groupCode, ['teacher_sync', 'education_plan_sync'], true) && $key === 'pull_app_secret')
            || ($groupCode === 'assistant' && $key === 'api_key');
    }

    /** 校验配置键格式 */
    private function assertKey(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException('配置键格式无效');
        }
    }

    /** 校验学校业务库连接上下文 */
    private function assertSchoolConnection(): void
    {
        if (!CurrentContext::get('school_connection')) {
            throw new RuntimeException('学校业务库连接未解析');
        }
    }
}
