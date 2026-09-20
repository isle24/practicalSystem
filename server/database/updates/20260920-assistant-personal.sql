CREATE TABLE IF NOT EXISTS `assistant_profile` (
  `account_id` BIGINT UNSIGNED NOT NULL,
  `enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `endpoint` VARCHAR(500) NOT NULL DEFAULT '',
  `model` VARCHAR(120) NOT NULL DEFAULT '',
  `api_key_cipher` TEXT NOT NULL,
  `revision` CHAR(32) NOT NULL DEFAULT '',
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `assistant_thread` ADD COLUMN `provider_mode` VARCHAR(16) NOT NULL DEFAULT 'school';
ALTER TABLE `assistant_turn` ADD COLUMN `provider_mode` VARCHAR(16) NOT NULL DEFAULT 'school';
ALTER TABLE `assistant_turn` ADD COLUMN `provider_revision` CHAR(64) NOT NULL DEFAULT '';
