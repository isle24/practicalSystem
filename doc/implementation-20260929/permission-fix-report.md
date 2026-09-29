# 经费菜单权限修复

`ExpenseUpgradeSchema::syncMenus()` 原先为所有启用角色分配经费模块、列表和审批按钮菜单，从而把 `expense:view` 与 `expense:approve` 授予教师、学生、企业等角色。菜单权限码由有效的 `role_menu` 关联生成。

现按角色类型收敛经费菜单：仅超级管理员、学校管理员、学院管理员和专业管理员获得模块、列表、新建编辑及导出菜单。学院与专业管理员仍受经费查询、基地可见性和组织范围约束。同步时先软删除经费菜单的全部现存角色关联，再按允许角色重新写入，因此重跑学校初始化或经费迁移也会撤销既有的过宽授权。审批按钮不授予任何角色；`expense:approve` 不再通过角色菜单授权。

审批由 `WorkflowService::review()` 校验审批实例活动节点下，当前账号是否持有状态为 pending 的 review 任务，并校验账号有效性；不依赖 `expense:approve` 菜单权限。审批人可从通用审批待办进入其参与的经费申请详情，不因此得到经费模块列表入口。角色类型按 `server/database/init.php::seedRoles()` 中现有的 `super_admin`、`school_admin`、`college_admin`、`profession_admin`、`teacher`、`student`、`enterprise` 约定处理。

未连接或修改生产数据库。`syncMenus()` 仅在代码中更新；实际数据变更会在学校初始化或执行经费迁移时发生。

验证：

- `php -l server/app/server/expense/ExpenseUpgradeSchema.php`：通过。
- `git diff --check --no-index /dev/null server/app/server/expense/ExpenseUpgradeSchema.php`：通过。
- `git diff --check --no-index /dev/null doc/implementation-20260929/permission-fix-report.md`：通过。

未新增测试文件或测试相关内容。
