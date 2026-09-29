# Task 3 实施记录

## 修改文件

- `server/app/controller/Api/InternshipController.php`：新增删除影响查询和删除入口。
- `server/app/server/internship/InternshipService.php`：基地立项字段校验与保存、计划删除权限/版本校验、事务和计划锁、任务写入时锁定所属计划。
- `server/app/model/channel/InternshipRecord.php`：基地布尔输出与筛选、删除影响版本、计划和任务软删除、活动任务查询排除已删除计划。
- `server/app/server/internship/InternshipUpgradeSchema.php`：基地字段升级、最新历史申报映射、菜单种子和存量菜单同步。
- `server/database/migrations/20260929_internship_base_project.php`：按学校数据库编号重复执行的增量迁移入口；未执行迁移，未修改学校数据。
- `fontend/pc/src/components/InternshipBaseForm.vue`：是否立项只读显示及是/否单选，新建默认否。
- `fontend/pc/src/api/system.js`：导出 `fetchInternshipPlanDeleteImpact(id)` 和 `removeInternshipPlan(payload)`。

## 接口与行为

- `GET /api/internship/plan-delete-impact?id=...` 对应 `planDeleteImpact`，返回 `id`、`course_name`、`arrangements: [{id,title}]`、`arrangement_count`、`revision`。
- `POST /api/internship/remove-plan` 对应 `removePlan`，请求 `id`、`include_arrangements`、`revision`，返回 `id`、`deleted`、`arrangement_ids`。
- 两接口复用 `internship:plan`、管理员角色与计划学院/专业数据范围；已删除计划再次提交返回 `deleted: false`。预览后计划或任务发生变化返回 409；有任务但未确认级联返回 409。
- 删除在 `WorkflowLock('internship','plan',id)` 和学校库事务中锁计划与任务，统一写入 `deleted_at`；任务写入路径在事务内锁所属计划，避免删除同时新增任务。
- 不删除附件、过程材料、历史审批及签名。工作流取消尚未接入，主执行者在 `InternshipService::removePlan()` 中 `softDeletePlanAndArrangements()` 之后、事务返回之前接取消 hook；实体类型需与工作流适配器确认。
- 当前任务查询的共用范围条件与待办关联查询已排除软删除计划；任务本身的 `deleted_at` 过滤继续生效。

## 数据升级与菜单

- `base.is_project_approved`：`TINYINT(1) NOT NULL DEFAULT 0`。首次加列后，按有效申报的 `declaration_year DESC, id DESC` 取最新一条，仅把 `是`、`已立项`、`1`、`true` 映射为 1；原始 `project_status` 保留。重复迁移不覆盖后来人工编辑的立项值。
- 基地列表和详情输出布尔值；列表筛选接受 `false`/`0`，旧客户端不提交字段时保留原值。
- 菜单顺序/标识：`6102` 基地申报、`6101` 基地汇总统计、`6103` 审批表、`6104` 基地巡查。`61031` 沿用 `/base-management/usage`；`61041` 使用 `/base-management/visits`。新巡查菜单复制现有可见角色的菜单授权。
- 新学校库初始化时，`server/database/init.php` 的 `base` 建表定义需加入立项列，并在原始菜单种子及角色授权之后调用 `InternshipUpgradeSchema::syncBaseMenus($pdo)`。对存量学校运行独立迁移；不要在迁移前通过其他入口预先加列，否则历史映射会跳过。

## 主执行者集成点

- `server/config/route.php` 增加显式 GET `/api/internship/plan-delete-impact` 和 POST `/api/internship/remove-plan` 路由，映射对应 Controller 方法。
- `App.vue` 基地模块首屏为基地申报；基地列表添加“是否立项”列及是/否筛选，筛选请求使用 `is_project_approved: 1/0`，导出标题与菜单文案按设计同步。
- `App.vue` 计划列表删除命令调用 `fetchInternshipPlanDeleteImpact` 显示任务数量和列表；无任务直接确认，有任务提供一并删除/取消；提交预览返回的 `revision`，409 时重新预览。删除成功刷新计划、任务与选项。
- `App.vue` 保留 `/base-management/usage` 路径/标识的兼容映射，并接入巡查菜单路径。工作流未完成任务取消须在同一删除事务内完成；不要调用带额外锁和独立事务的公开取消入口。

## 验证

- 修改涉及的五个 PHP 文件均通过 `php -l`。
- PC `npm run build` 退出码 0，Vite 处理 3516 个模块；构建仅有现有依赖注释与大 chunk 提示。
- `git diff --check` 退出码 0。
- 未运行数据库迁移、未接真实学校库验证。未创建测试内容。
