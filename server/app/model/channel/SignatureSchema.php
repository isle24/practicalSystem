<?php

namespace app\model\channel;

class SignatureSchema extends TableRecord
{
    public static function apply(): void
    {
        if (self::connection()->getTablePrefix() !== '' || self::connection()->transactionLevel() > 0) throw new \RuntimeException('签名结构升级需要无表前缀且无活动事务');
        foreach (self::creationStatements() as $sql) self::connection()->statement($sql);
    }

    public static function creationStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `personal_signature` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` BIGINT UNSIGNED NOT NULL, `account_id` BIGINT UNSIGNED NOT NULL, `file_id` BIGINT UNSIGNED NOT NULL, `version` INT UNSIGNED NOT NULL, `sha256` CHAR(64) NOT NULL, `width` INT UNSIGNED NOT NULL, `height` INT UNSIGNED NOT NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uk_user_version` (`user_id`,`version`), UNIQUE KEY `uk_file` (`file_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `signature_session` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `token_hash` CHAR(64) NOT NULL, `school_id` BIGINT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `account_id` BIGINT UNSIGNED NOT NULL, `device_jti` VARCHAR(255) NOT NULL, `state` VARCHAR(20) NOT NULL DEFAULT 'pending', `signature_id` BIGINT UNSIGNED DEFAULT NULL, `expires_at` DATETIME NOT NULL, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`token_hash`), KEY `idx_owner` (`account_id`,`state`), KEY `idx_expiry` (`expires_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }
}
