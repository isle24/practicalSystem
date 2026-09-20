CREATE TABLE IF NOT EXISTS `message_realtime_outbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_ids` JSON NOT NULL,
  `topic` VARCHAR(32) NOT NULL DEFAULT 'messages',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `assistant_thread` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(120) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_owner_updated` (`account_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `assistant_turn` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `question` TEXT NOT NULL,
  `answer` MEDIUMTEXT DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `error_message` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_request` (`account_id`, `request_id`),
  KEY `idx_thread_id` (`thread_id`, `id`),
  KEY `idx_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
