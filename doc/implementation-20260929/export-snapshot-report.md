# 经费审批导出快照修复

`workflow_instance.snapshot_json` 在经费提交时由 `ExpenseAdapter::snapshot()` 保存完整经费主体，包含明细 `items`、`attachment_ids` 和附件展示数据 `attachments`。`workflow_history.signature_json` 保存该轮审批签名快照。

`ExpenseDocumentExporter` 现先查找 `base_expense` 的最高轮次流程实例。有实例时，导出主体、明细、附件名称均来自该轮 `snapshot_json`，审批记录与签名只读取该实例对应的历史；快照无效时明确报错，不回退读取已变更的实时主体。没有任何流程实例时，沿用 `ExpenseRecord::find()` 的当前保存字段生成历史导出，审批历史为空，不补造明细或签名。

验证：`php -l server/app/server/export/ExpenseDocumentExporter.php` 通过；`git diff --check` 通过。未运行测试、数据库迁移或生产库操作。
