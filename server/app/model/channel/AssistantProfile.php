<?php

namespace app\model\channel;

/** 当前学校内按账号隔离的个人助手配置。 */
class AssistantProfile extends TableRecord
{
    /** 读取个人配置；密文只供服务端解密。 */
    public static function forAccount(int $accountId): ?array
    {
        return self::queryTable('assistant_profile')->where('account_id', $accountId)->first()?->toArray();
    }

    /** 保存本人配置。 */
    public static function saveForAccount(int $accountId, array $values): void
    {
        self::queryTable('assistant_profile')->updateOrInsert(['account_id' => $accountId], [
            'enabled' => $values['enabled'], 'endpoint' => $values['endpoint'], 'model' => $values['model'],
            'api_key_cipher' => $values['api_key_cipher'], 'updated_at' => date('Y-m-d H:i:s'),
            'revision' => bin2hex(random_bytes(16)),
        ]);
    }

    /** 幂等应用个人配置及会话来源结构升级。 */
    public static function install(): void
    {
        $schema = self::connection()->getSchemaBuilder();
        foreach (self::schemaStatements() as $statement) {
            if (preg_match('/^ALTER TABLE `(\w+)` ADD COLUMN `(\w+)`/', $statement, $match)
                && $schema->hasColumn($match[1], $match[2])) continue;
            self::connection()->unprepared($statement);
        }
        if (!$schema->hasColumn('assistant_profile', 'revision')) {
            self::connection()->statement("ALTER TABLE `assistant_profile` ADD COLUMN `revision` CHAR(32) NOT NULL DEFAULT ''");
        }
    }

    public static function schemaStatements(): array
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/updates/20260920-assistant-personal.sql');
        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }
}
