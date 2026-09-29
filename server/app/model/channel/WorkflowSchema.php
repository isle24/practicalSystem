<?php

namespace app\model\channel;

class WorkflowSchema extends TableRecord
{
    public static function apply(): void
    {
        if (self::connection()->getTablePrefix() !== '') throw new \RuntimeException('流程结构升级不支持数据库表前缀');
        if (self::connection()->transactionLevel() > 0) throw new \RuntimeException('流程结构升级不能在事务内执行');
        foreach (self::creationStatements() as $sql) self::connection()->statement($sql);
    }

    public static function creationStatements(): array
    {
        $definitions = [
            'definition' => "`entity_type` VARCHAR(80) NOT NULL, `dep_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, `name` VARCHAR(180) NOT NULL, `current_version_id` BIGINT UNSIGNED DEFAULT NULL, `created_by` BIGINT UNSIGNED NOT NULL, UNIQUE KEY `uk_scope` (`entity_type`,`dep_id`)",
            'version' => "`definition_id` BIGINT UNSIGNED NOT NULL, `version` INT UNSIGNED NOT NULL, `name` VARCHAR(180) NOT NULL, `created_by` BIGINT UNSIGNED NOT NULL, UNIQUE KEY `uk_version` (`definition_id`,`version`)",
            'node' => "`version_id` BIGINT UNSIGNED NOT NULL, `position` INT UNSIGNED NOT NULL, `config_json` JSON NOT NULL, UNIQUE KEY `uk_position` (`version_id`,`position`)",
            'instance' => "`entity_type` VARCHAR(80) NOT NULL, `entity_id` BIGINT UNSIGNED NOT NULL, `round` INT UNSIGNED NOT NULL, `definition_version_id` BIGINT UNSIGNED NOT NULL, `applicant_id` BIGINT UNSIGNED NOT NULL, `status` VARCHAR(20) NOT NULL, `active_position` INT UNSIGNED NOT NULL DEFAULT 0, `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1, `request_key` CHAR(64) NOT NULL, `snapshot_json` JSON NOT NULL, `nodes_json` JSON NOT NULL, `finished_at` DATETIME DEFAULT NULL, UNIQUE KEY `uk_round` (`entity_type`,`entity_id`,`round`), UNIQUE KEY `uk_request` (`request_key`), KEY `idx_status` (`status`,`applicant_id`)",
            'task' => "`instance_id` BIGINT UNSIGNED NOT NULL, `position` INT UNSIGNED NOT NULL, `account_id` BIGINT UNSIGNED NOT NULL, `status` VARCHAR(20) NOT NULL, `kind` VARCHAR(20) NOT NULL, `handled_at` DATETIME DEFAULT NULL, UNIQUE KEY `uk_assignee` (`instance_id`,`position`,`account_id`), KEY `idx_inbox` (`account_id`,`status`,`kind`)",
            'history' => "`instance_id` BIGINT UNSIGNED NOT NULL, `position` INT UNSIGNED NOT NULL DEFAULT 0, `actor_id` BIGINT UNSIGNED NOT NULL, `action` VARCHAR(30) NOT NULL, `from_status` VARCHAR(20) NOT NULL, `to_status` VARCHAR(20) NOT NULL, `opinion` TEXT NOT NULL, `signature_json` JSON DEFAULT NULL, `idempotency_key` CHAR(64) NOT NULL, `request_hash` CHAR(64) NOT NULL, `result_json` JSON NOT NULL, UNIQUE KEY `uk_operation` (`idempotency_key`), KEY `idx_instance` (`instance_id`,`id`)",
            'outbox' => "`message_id` BIGINT UNSIGNED DEFAULT NULL, `instance_id` BIGINT UNSIGNED NOT NULL, `position` INT UNSIGNED NOT NULL, `recipient_id` BIGINT UNSIGNED NOT NULL, `channel` VARCHAR(20) NOT NULL, `template` VARCHAR(120) NOT NULL, `payload_json` JSON NOT NULL, `dedupe_key` CHAR(64) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pending', `attempts` INT UNSIGNED NOT NULL DEFAULT 0, `available_at` DATETIME NOT NULL, `locked_until` DATETIME DEFAULT NULL, `claim_token` CHAR(32) DEFAULT NULL, `last_error` TEXT DEFAULT NULL, `sent_at` DATETIME DEFAULT NULL, UNIQUE KEY `uk_dedupe` (`dedupe_key`), KEY `idx_delivery` (`status`,`available_at`,`locked_until`)",
        ];
        $sql = [];
        foreach ($definitions as $table => $columns) {
            $sql[] = "CREATE TABLE IF NOT EXISTS `workflow_{$table}` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, {$columns}, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }
        return $sql;
    }
}
