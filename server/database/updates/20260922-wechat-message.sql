-- 在每个学校业务库执行。

SET @wechat_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_notify_setting' AND COLUMN_NAME = 'quiet_start') = 0, 'ALTER TABLE `user_notify_setting` ADD COLUMN `quiet_start` VARCHAR(5) NOT NULL DEFAULT ''00:00''', 'SELECT 1');
PREPARE wechat_stmt FROM @wechat_ddl;
EXECUTE wechat_stmt;
DEALLOCATE PREPARE wechat_stmt;

SET @wechat_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_notify_setting' AND COLUMN_NAME = 'quiet_end') = 0, 'ALTER TABLE `user_notify_setting` ADD COLUMN `quiet_end` VARCHAR(5) NOT NULL DEFAULT ''24:00''', 'SELECT 1');
PREPARE wechat_stmt FROM @wechat_ddl;
EXECUTE wechat_stmt;
DEALLOCATE PREPARE wechat_stmt;

SET @wechat_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_channel_log' AND COLUMN_NAME = 'attempts') = 0, 'ALTER TABLE `message_channel_log` ADD COLUMN `attempts` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE wechat_stmt FROM @wechat_ddl;
EXECUTE wechat_stmt;
DEALLOCATE PREPARE wechat_stmt;

SET @wechat_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_channel_log' AND COLUMN_NAME = 'available_at') = 0, 'ALTER TABLE `message_channel_log` ADD COLUMN `available_at` DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE wechat_stmt FROM @wechat_ddl;
EXECUTE wechat_stmt;
DEALLOCATE PREPARE wechat_stmt;

SET @wechat_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_channel_log' AND COLUMN_NAME = 'locked_until') = 0, 'ALTER TABLE `message_channel_log` ADD COLUMN `locked_until` DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE wechat_stmt FROM @wechat_ddl;
EXECUTE wechat_stmt;
DEALLOCATE PREPARE wechat_stmt;

SET @wechat_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_channel_log' AND COLUMN_NAME = 'claim_token') = 0, 'ALTER TABLE `message_channel_log` ADD COLUMN `claim_token` VARCHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE wechat_stmt FROM @wechat_ddl;
EXECUTE wechat_stmt;
DEALLOCATE PREPARE wechat_stmt;

SET @wechat_ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_channel_log' AND INDEX_NAME = 'idx_channel_available') = 0, 'ALTER TABLE `message_channel_log` ADD KEY `idx_channel_available` (`channel`, `status`, `available_at`)', 'SELECT 1');
PREPARE wechat_stmt FROM @wechat_ddl;
EXECUTE wechat_stmt;
DEALLOCATE PREPARE wechat_stmt;
