<?php

namespace app\server\plugin;

use app\model\channel\PluginRecord;
use app\server\CurrentContext;
use RuntimeException;

/** 受控 Web 插件目录服务。 */
class PluginService
{
    /** 判断当前账号是否可以维护插件目录。 */
    public function canManage(): bool
    {
        return in_array(CurrentContext::roleType(), ['super_admin', 'school_admin'], true)
            || in_array('plugin:manage', CurrentContext::permissionCodes(), true);
    }

    /** 查询当前学校启用的插件。 */
    public function page(array $input): array
    {
        if (!CurrentContext::accountId()) throw new RuntimeException('请先登录', 401);
        return PluginRecord::page($input) + ['can_manage' => $this->canManage()];
    }

    /** 保存管理员配置的受控 Web 入口。 */
    public function save(array $input): array
    {
        if (!$this->canManage()) throw new RuntimeException('无插件维护权限', 403);
        $code = trim((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $entry = $this->assertEntry((string) ($input['entry_url'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{2,80}$/', $code) || $name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('插件编码或名称不正确', 400);
        }
        $parts = parse_url($entry);
        $entryHost = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $domains = array_values(array_unique(array_map(
            static fn ($domain) => strtolower(rtrim(trim((string) $domain), '.')),
            array_filter((array) ($input['allowed_domains'] ?? [$entryHost]), static fn ($domain) => trim((string) $domain) !== '')
        )));
        if (!$domains || count($domains) > 20 || array_filter($domains, static fn ($domain) => !preg_match('/^[A-Za-z0-9.-]+$/', $domain))) {
            throw new RuntimeException('允许域名配置不正确', 400);
        }
        if ($entryHost === '' || !in_array($entryHost, $domains, true)) {
            throw new RuntimeException('插件入口域名必须包含在允许域名中', 400);
        }
        $id = PluginRecord::savePlugin([
            'id' => (int) ($input['id'] ?? 0), 'code' => $code, 'name' => $name,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 500),
            'version' => mb_substr(trim((string) ($input['version'] ?? '1.0.0')) ?: '1.0.0', 0, 40),
            'icon_url' => $this->nullableString($input['icon_url'] ?? null, 500), 'entry_url' => $entry,
            'open_mode' => in_array(($input['open_mode'] ?? 'browser'), ['browser', 'client'], true) ? $input['open_mode'] : 'browser',
            'allowed_domains' => json_encode($domains, JSON_UNESCAPED_UNICODE),
            'permission_description' => mb_substr(trim((string) ($input['permission_description'] ?? '')), 0, 500),
            'sort' => (int) ($input['sort'] ?? 100), 'status' => ($input['status'] ?? 'enabled') === 'disabled' ? 'disabled' : 'enabled',
        ]);
        return ['item' => PluginRecord::detail($id)];
    }

    /** 软删除插件目录入口。 */
    public function delete(int $id): void
    {
        if (!$this->canManage()) throw new RuntimeException('无插件维护权限', 403);
        if ($id <= 0 || PluginRecord::deletePlugin($id) <= 0) throw new RuntimeException('插件不存在', 404);
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    /** 校验插件入口只能是 HTTPS Web 地址。 */
    public function assertEntry(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('插件入口必须是 HTTPS 地址，且不能携带账号密码', 400);
        }
        return trim($url);
    }
}
