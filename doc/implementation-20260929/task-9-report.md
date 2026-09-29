# Task 9 经费管理实现记录

## 已实现

- `ExpenseSchema` 声明 `base_expense` 的可重复列扩展及 `base_expense_item` 明细表；没有触碰旧记录或伪造旧明细。
- `ExpenseRecord` 支持明细读取、保存、按学校上下文查询，并按申请人和有效组织范围限制列表数据。
- `ExpenseService` 提供分页列表、详情、草稿保存和WorkflowService提交调用。金额采用整数分格式化及累计，拒绝非正数/超过两位小数，不依赖浮点或bc数学扩展；合计由服务端计算。
- `ExpenseAdapter` 实现Task6工作流适配器契约，并映射主体工作流状态。
- `ExpenseController` 提供 options/list/detail/save/submit 控制器方法。
- 新增 PC `ExpensePanel.vue` 与 H5 `ExpenseApproval.vue` 组件骨架，props为 request/backendUrl/accountId（H5另需expenseId）。
- `ExpenseDocumentExporter` 目前仅生成基础HTML，不注册到Word/PDF导出队列。

## 接线说明

主执行者需添加 `/api/expense/options|list|detail|save|submit` 显式路由，在 `workflow.php` 将 `base_expense` 映射至 `ExpenseAdapter`，并将 `ExpenseSchema::creationStatements()` 接入增量迁移/新库初始化。WorkflowService应保持仅接受已发布定义及有效审批候选人的现有行为；未完成人员配置时拒绝提交。ExportTaskService与ExportGenerator目前未注册费用类型，不能以HTML输出标称Word或PDF。菜单、独立权限及PC/H5导航尚待整合。

## 验证与未完成

已对新增PHP文件运行 `php -l`，通过：`ExpenseSchema.php`、`ExpenseRecord.php`、`ExpenseService.php`、`ExpenseAdapter.php`、`ExpenseController.php`、`ExpenseDocumentExporter.php`。未运行数据库迁移、Vue编译或构建、实际导出排版验证。PC组件目前仅为列表骨架，缺少费用申请表单；H5组件目前仅为详情显示骨架，缺少工作流历史和审批操作。未实现学期/基地有效性与附件正式关联授权校验，亦未实现附件名称导出及审批意见、时间、签名快照的Word/PDF排版。因此Task9仍未完成。

补充：已将 `expense_document` 注册到 ExportGenerator/ExportTaskService，使用 PHPWord 生成 DOCX。Schema 使用运行时列存在检查，兼容不支持 `ADD COLUMN IF NOT EXISTS` 的 MySQL。路由与 App 菜单仍由主执行者接入；PC/H5组件尚未接入共享审批历史组件。

## 草稿保存与提交互斥修复

`ExpenseService::save` 对已存在申请使用 `WorkflowLock::key('generic_workflow', 'base_expense', $id)` 严格互斥锁，并在锁内事务中通过 `ExpenseRecord::find($id, true)` 加载主体、校验学校数据范围/申请人/草稿或退回状态，再更新主体、明细与附件关联。新增申请仍在事务中创建。`submit` 同样获取完全相同的实体锁，并在同一学校数据库事务内重新锁行和校验后调用 `WorkflowService::startInTransaction`，避免保存与提交流程重叠。未运行生产数据库或迁移。

验证：`php -l server/app/server/expense/ExpenseService.php` 输出 `No syntax errors detected`；`git diff --check` 无输出且退出码为0。
