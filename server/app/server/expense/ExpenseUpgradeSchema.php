<?php

namespace app\server\expense;

use PDO;

final class ExpenseUpgradeSchema
{
    public static function syncMenus(PDO $pdo): void
    {
        $menus = [
            [620, 0, '经费管理', 'expense:view', null, 'both', 'directory', 34, 'ClipboardList'],
            [6201, 620, '基地建设费用申请', 'expense:view', '/expense', 'both', 'list', 10, 'ClipboardList'],
            [62011, 6201, '新建与编辑', 'expense:manage', null, 'both', 'button', 11, null],
            [62012, 6201, '审批', 'expense:approve', null, 'both', 'button', 12, null],
            [62013, 6201, '导出', 'expense:export', null, 'both', 'button', 13, null],
        ];
        $stmt = $pdo->prepare("INSERT INTO `menu` (`id`,`parent_id`,`name`,`code`,`path`,`platform`,`type`,`sort`,`icon`,`visible`,`status`) VALUES (?,?,?,?,?,?,?,?,?,'true','enabled') ON DUPLICATE KEY UPDATE `parent_id`=VALUES(`parent_id`),`name`=VALUES(`name`),`code`=VALUES(`code`),`path`=VALUES(`path`),`platform`=VALUES(`platform`),`type`=VALUES(`type`),`sort`=VALUES(`sort`),`icon`=VALUES(`icon`),`visible`='true',`status`='enabled',`deleted_at`=NULL");
        foreach ($menus as $menu) $stmt->execute($menu);

        $menuIds = [620, 6201, 62011, 62012, 62013];
        $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
        $revoke = $pdo->prepare("UPDATE `role_menu` SET `deleted_at`=NOW(),`updated_at`=NOW() WHERE `menu_id` IN ({$placeholders}) AND `deleted_at` IS NULL");
        $revoke->execute($menuIds);

        $roleMenu = $pdo->prepare("INSERT INTO `role_menu` (`role_id`,`menu_id`) VALUES (?,?) ON DUPLICATE KEY UPDATE `deleted_at`=NULL,`updated_at`=NOW()");
        $roleIds = $pdo->query("SELECT `id` FROM `role` WHERE `role_type` IN ('super_admin','school_admin','college_admin','profession_admin') AND `status`='enabled' AND `deleted_at` IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($roleIds as $roleId) {
            foreach ([620, 6201, 62011, 62013] as $menuId) $roleMenu->execute([(int) $roleId, $menuId]);
        }
    }
}
