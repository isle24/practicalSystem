CREATE TABLE IF NOT EXISTS `base_visit_plan` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `revision` INT UNSIGNED NOT NULL DEFAULT 1,
    `title` VARCHAR(180) NOT NULL,
    `base_id` BIGINT UNSIGNED NOT NULL,
    `teacher_id` BIGINT UNSIGNED DEFAULT NULL,
    `dep_id` BIGINT UNSIGNED NOT NULL,
    `base_name` VARCHAR(180) NOT NULL,
    `base_address` VARCHAR(500) DEFAULT NULL,
    `base_department` VARCHAR(180) DEFAULT NULL,
    `base_category` VARCHAR(80) DEFAULT NULL,
    `base_location` VARCHAR(40) DEFAULT NULL,
    `base_manager_name` VARCHAR(80) DEFAULT NULL,
    `base_manager_phone` VARCHAR(40) DEFAULT NULL,
    `teacher_name` VARCHAR(120) NOT NULL,
    `teacher_department` VARCHAR(180) DEFAULT NULL,
    `contact_phone` VARCHAR(40) DEFAULT NULL,
    `contact_account_id` BIGINT UNSIGNED DEFAULT NULL,
    `contact_person` VARCHAR(180) DEFAULT NULL,
    `supervisor_id` BIGINT UNSIGNED DEFAULT NULL,
    `participant_ids` JSON DEFAULT NULL,
    `news_url` VARCHAR(500) DEFAULT NULL,
    `visit_date` DATE DEFAULT NULL,
    `visit_period` VARCHAR(10) DEFAULT NULL,
    `start_time` TIME DEFAULT NULL,
    `end_time` TIME DEFAULT NULL,
    `remark` TEXT DEFAULT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'pending_time',
    `scheduled_at` DATETIME DEFAULT NULL,
    `cancel_reason` VARCHAR(1000) DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `updated_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_visit_plan_uuid` (`uuid`),
    KEY `idx_visit_teacher_status_date` (`teacher_id`, `status`, `visit_date`),
    KEY `idx_visit_dep_status_date` (`dep_id`, `status`, `visit_date`),
    KEY `idx_visit_date_period_status` (`visit_date`, `visit_period`, `status`),
    KEY `idx_visit_base` (`base_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `base_visit_record` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `visit_id` BIGINT UNSIGNED NOT NULL,
    `actual_at` DATETIME NOT NULL,
    `participants` VARCHAR(500) DEFAULT NULL,
    `contact_person` VARCHAR(180) DEFAULT NULL,
    `content` MEDIUMTEXT NOT NULL,
    `problems` TEXT DEFAULT NULL,
    `follow_up` TEXT DEFAULT NULL,
    `attachment_ids` JSON DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `updated_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_visit_record_uuid` (`uuid`),
    UNIQUE KEY `uk_visit_record_plan` (`visit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @base_visit_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'base_manager_name') = 0, 'ALTER TABLE `base_visit_plan` ADD COLUMN `base_manager_name` VARCHAR(80) DEFAULT NULL AFTER `base_location`', 'SELECT 1');
PREPARE base_visit_stmt FROM @base_visit_ddl;
EXECUTE base_visit_stmt;
DEALLOCATE PREPARE base_visit_stmt;
SET @base_visit_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'base_manager_phone') = 0, 'ALTER TABLE `base_visit_plan` ADD COLUMN `base_manager_phone` VARCHAR(40) DEFAULT NULL AFTER `base_manager_name`', 'SELECT 1');
PREPARE base_visit_stmt FROM @base_visit_ddl;
EXECUTE base_visit_stmt;
DEALLOCATE PREPARE base_visit_stmt;
SET @base_visit_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'contact_account_id') = 0, 'ALTER TABLE `base_visit_plan` ADD COLUMN `contact_account_id` BIGINT UNSIGNED DEFAULT NULL AFTER `teacher_department`', 'SELECT 1');
PREPARE base_visit_stmt FROM @base_visit_ddl;
EXECUTE base_visit_stmt;
DEALLOCATE PREPARE base_visit_stmt;
SET @base_visit_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'contact_person') = 0, 'ALTER TABLE `base_visit_plan` ADD COLUMN `contact_person` VARCHAR(180) DEFAULT NULL AFTER `contact_account_id`', 'SELECT 1');
PREPARE base_visit_stmt FROM @base_visit_ddl;
EXECUTE base_visit_stmt;
DEALLOCATE PREPARE base_visit_stmt;
SET @base_visit_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'supervisor_id') = 0, 'ALTER TABLE `base_visit_plan` ADD COLUMN `supervisor_id` BIGINT UNSIGNED DEFAULT NULL AFTER `contact_phone`', 'SELECT 1');
PREPARE base_visit_stmt FROM @base_visit_ddl;
EXECUTE base_visit_stmt;
DEALLOCATE PREPARE base_visit_stmt;
SET @base_visit_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'participant_ids') = 0, 'ALTER TABLE `base_visit_plan` ADD COLUMN `participant_ids` JSON DEFAULT NULL AFTER `supervisor_id`', 'SELECT 1');
PREPARE base_visit_stmt FROM @base_visit_ddl;
EXECUTE base_visit_stmt;
DEALLOCATE PREPARE base_visit_stmt;
SET @base_visit_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_visit_plan' AND COLUMN_NAME = 'news_url') = 0, 'ALTER TABLE `base_visit_plan` ADD COLUMN `news_url` VARCHAR(500) DEFAULT NULL AFTER `participant_ids`', 'SELECT 1');
PREPARE base_visit_stmt FROM @base_visit_ddl;
EXECUTE base_visit_stmt;
DEALLOCATE PREPARE base_visit_stmt;


CREATE TABLE IF NOT EXISTS `base_visit_participant` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `visit_id` BIGINT UNSIGNED NOT NULL,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_visit_participant_uuid` (`uuid`),
    UNIQUE KEY `uk_visit_participant_active` (`visit_id`, `account_id`),
    KEY `idx_visit_participant_account` (`account_id`, `deleted_at`, `visit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `base_visit_participant` (`uuid`, `visit_id`, `account_id`, `created_at`, `updated_at`)
SELECT UUID(), p.id, j.account_id, NOW(), NOW()
FROM `base_visit_plan` p
CROSS JOIN JSON_TABLE(COALESCE(p.participant_ids, JSON_ARRAY()), '$[*]' COLUMNS (`account_id` BIGINT PATH '$')) AS j
WHERE p.deleted_at IS NULL
  AND j.account_id > 0
  AND NOT EXISTS (
      SELECT 1 FROM `base_visit_participant` x
      WHERE x.visit_id = p.id AND x.account_id = j.account_id AND x.deleted_at IS NULL
  );


INSERT INTO `base_visit_participant` (`uuid`, `visit_id`, `account_id`, `created_at`, `updated_at`)
SELECT UUID(), p.id, a.id, NOW(), NOW()
FROM `base_visit_plan` p
JOIN `teacher_list` t ON t.teacher_id = p.teacher_id AND t.deleted_at IS NULL
JOIN `account` a ON a.user_id = t.user_id AND a.deleted_at IS NULL
WHERE p.deleted_at IS NULL
  AND p.status IN ('scheduled', 'completed')
  AND t.user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `base_visit_participant` x
      WHERE x.visit_id = p.id AND x.account_id = a.id
  );
