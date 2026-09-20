CREATE TABLE IF NOT EXISTS `plugin_catalog` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `code` VARCHAR(80) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `version` VARCHAR(40) DEFAULT '1.0.0',
  `icon_url` VARCHAR(500) DEFAULT NULL,
  `entry_url` VARCHAR(500) NOT NULL,
  `open_mode` VARCHAR(20) NOT NULL DEFAULT 'browser',
  `allowed_domains` JSON DEFAULT NULL,
  `permission_description` VARCHAR(500) DEFAULT NULL,
  `sort` INT NOT NULL DEFAULT 100,
  `status` VARCHAR(20) NOT NULL DEFAULT 'enabled',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_code` (`code`),
  KEY `idx_plugin_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `plugin_catalog` (`uuid`, `code`, `name`, `description`, `version`, `entry_url`, `open_mode`, `allowed_domains`, `permission_description`, `sort`, `status`)
VALUES ('00000000-0000-0000-0000-000000710001', 'cloud-storage', '网盘入口', '通过官方授权页面访问学校配置的网盘服务。', '1.0.0', 'https://drive.google.com/', 'browser', '["drive.google.com"]', '仅打开官方 Web/OAuth 页面，不保存网盘密码。', 10, 'enabled');
