<?php

namespace app\server\internship;

use PDO;

class InternshipUpgradeSchema
{
    public static function ensureBaseProjectApproval(PDO $pdo): void
    {
        $column = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base' AND COLUMN_NAME = 'is_project_approved'"
        );
        $column->execute();
        if ((int) $column->fetchColumn() > 0) {
            return;
        }

        $pdo->exec('ALTER TABLE `base` ADD COLUMN `is_project_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `base_type`');
        $pdo->exec(
            "UPDATE `base` AS b
             LEFT JOIN `base_declaration` AS d ON d.id = (
                 SELECT latest.id FROM `base_declaration` AS latest
                 WHERE latest.base_id = b.id AND latest.deleted_at IS NULL
                 ORDER BY latest.declaration_year DESC, latest.id DESC LIMIT 1
             )
             SET b.is_project_approved = CASE
                 WHEN LOWER(TRIM(d.project_status)) IN ('是', '已立项', '1', 'true') THEN 1
                 ELSE 0 END"
        );
    }

    public static function baseMenuSeeds(): array
    {
        return [
            [6102, 610, '基地申报', null, null, 'pc', 'menu', 301, 'FileText'],
            [61021, 6102, '列表', 'internship:view', '/base-management/applications', 'pc', 'list', 3011, 'List'],
            [6101, 610, '基地汇总统计', null, null, 'pc', 'menu', 302, 'Building2'],
            [61011, 6101, '列表', 'internship:view', '/base-management/construction', 'pc', 'list', 3021, 'List'],
            [6103, 610, '审批表', null, null, 'pc', 'menu', 303, 'ClipboardCheck'],
            [61031, 6103, '列表', 'internship:view', '/base-management/usage', 'pc', 'list', 3031, 'List'],
            [6104, 610, '基地巡查', null, null, 'pc', 'menu', 304, 'CalendarCheck'],
            [61041, 6104, '列表', 'internship:view', '/base-management/visits', 'pc', 'list', 3041, 'List'],
        ];
    }

    public static function syncBaseMenus(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO `menu` (`id`, `parent_id`, `name`, `code`, `path`, `platform`, `type`, `sort`, `icon`, `visible`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'true', 'enabled')
             ON DUPLICATE KEY UPDATE `parent_id` = VALUES(`parent_id`), `name` = VALUES(`name`),
                 `code` = VALUES(`code`), `path` = VALUES(`path`), `platform` = VALUES(`platform`),
                 `type` = VALUES(`type`), `sort` = VALUES(`sort`), `icon` = VALUES(`icon`),
                 `visible` = 'true', `status` = 'enabled', `deleted_at` = NULL"
        );
        foreach (self::baseMenuSeeds() as $menu) {
            $stmt->execute($menu);
        }

        $roleStmt = $pdo->prepare(
            "INSERT INTO `role_menu` (`role_id`, `menu_id`)
             SELECT `role_id`, ? FROM `role_menu` WHERE `menu_id` = ? AND `deleted_at` IS NULL
             ON DUPLICATE KEY UPDATE `deleted_at` = NULL, `updated_at` = NOW()"
        );
        foreach ([6104 => 6102, 61041 => 61021] as $newId => $sourceId) {
            $roleStmt->execute([$newId, $sourceId]);
        }
    }
}
