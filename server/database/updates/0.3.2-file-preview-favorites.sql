SET @favorite_config_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'favorite_link' AND COLUMN_NAME = 'request_config');
SET @favorite_config_sql = IF(@favorite_config_exists = 0, 'ALTER TABLE favorite_link ADD COLUMN request_config JSON NULL COMMENT ''Non-sensitive external request parameters''', 'SELECT 1');
PREPARE favorite_config_statement FROM @favorite_config_sql;
EXECUTE favorite_config_statement;
DEALLOCATE PREPARE favorite_config_statement;
