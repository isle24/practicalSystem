ALTER TABLE `favorite_link`
  ADD COLUMN `scope` VARCHAR(20) NOT NULL DEFAULT 'personal',
  ADD COLUMN `open_mode` VARCHAR(20) NOT NULL DEFAULT 'client',
  ADD COLUMN `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  ADD COLUMN `revision` INT UNSIGNED NOT NULL DEFAULT 1,
  ADD INDEX `idx_scope_status` (`scope`, `status`, `deleted_at`);

CREATE TABLE IF NOT EXISTS `user_note` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(180) NOT NULL DEFAULT '',
  `content_md` MEDIUMTEXT NOT NULL,
  `revision` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_uuid` (`account_id`, `uuid`),
  KEY `idx_account_updated` (`account_id`, `deleted_at`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_release` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product` VARCHAR(20) NOT NULL,
  `version` VARCHAR(40) NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `notes_html` MEDIUMTEXT NOT NULL,
  `published_title` VARCHAR(180) DEFAULT NULL,
  `published_notes_html` MEDIUMTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `revision` INT UNSIGNED NOT NULL DEFAULT 1,
  `source_id` VARCHAR(100) DEFAULT NULL,
  `source_hash` CHAR(64) DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `published_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `published_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_version` (`product`, `version`),
  KEY `idx_published` (`status`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_release_sql` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `release_id` BIGINT UNSIGNED NOT NULL,
  `file_name` VARCHAR(180) NOT NULL,
  `target_scope` VARCHAR(20) NOT NULL DEFAULT 'school',
  `sql_content` MEDIUMTEXT NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `executed_by` BIGINT UNSIGNED DEFAULT NULL,
  `executed_at` DATETIME DEFAULT NULL,
  `execution_note` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_release_file` (`release_id`, `file_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `desktop_release_asset` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `release_id` BIGINT UNSIGNED NOT NULL,
  `source_asset_id` BIGINT UNSIGNED NOT NULL,
  `file_name` VARCHAR(240) NOT NULL,
  `platform` VARCHAR(50) NOT NULL,
  `kind` VARCHAR(20) NOT NULL,
  `size` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `signature` TEXT,
  `file_id` BIGINT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `error_message` VARCHAR(500) DEFAULT NULL,
  `claim_token` CHAR(32) DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_release_asset` (`release_id`, `source_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
