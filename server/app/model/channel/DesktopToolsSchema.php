<?php

namespace app\model\channel;

/** 桌面工具的可重复执行结构升级。 */
class DesktopToolsSchema extends TableRecord
{
    /** 仅补充缺失字段和表，不删除历史数据。 */
    public static function apply(): void
    {
        $schema = self::connection()->getSchemaBuilder();
        foreach (self::columnDefinitions() as $column => $statement) {
            if ($schema->hasColumn('favorite_link', $column)) continue;
            self::connection()->statement($statement);
        }
        if (!$schema->hasIndex('favorite_link', 'idx_scope_status')) {
            self::connection()->statement('ALTER TABLE `favorite_link` ADD INDEX `idx_scope_status` (`scope`, `status`, `deleted_at`)');
        }
        foreach (self::creationStatements() as $statement) self::connection()->unprepared($statement);
    }

    public static function columnDefinitions(): array
    {
        return [
            'scope' => "ALTER TABLE `favorite_link` ADD COLUMN `scope` VARCHAR(20) NOT NULL DEFAULT 'personal'",
            'open_mode' => "ALTER TABLE `favorite_link` ADD COLUMN `open_mode` VARCHAR(20) NOT NULL DEFAULT 'client'",
            'updated_by' => 'ALTER TABLE `favorite_link` ADD COLUMN `updated_by` BIGINT UNSIGNED DEFAULT NULL',
            'revision' => 'ALTER TABLE `favorite_link` ADD COLUMN `revision` INT UNSIGNED NOT NULL DEFAULT 1',
            'request_config' => 'ALTER TABLE `favorite_link` ADD COLUMN `request_config` JSON DEFAULT NULL',
        ];
    }

    /** 读取随代码审核的固定建表语句。 */
    public static function creationStatements(): array
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/updates/0.2.0-school-tools.sql');
        return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($sql) => str_starts_with($sql, 'CREATE TABLE')));
    }
}
