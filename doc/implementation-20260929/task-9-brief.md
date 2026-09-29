## Global Constraints

- 用户已确认 doc/2026-09-29-功能改造设计.md；不再询问是否继续或重复确认该设计。
- 不生成任何类型的测试相关内容。使用 PHP 语法检查、前端构建、编译及实际界面验证。
- 不改生产数据，不发送真实消息，不替用户签名或审批真实申请。
- 禁止表情符号；新增注释只说明结构或用途；文档放 doc。
- 当前工作区 /Users/isle/.codex/worktrees/school-workflow-upgrade/practicalSystem，原目录不可用于编辑。
- 并行任务不得修改 App.vue、server/config/route.php、server/database/init.php、schema-baseline.json 或 Git 索引；这些由主执行者统一整合。
- 不自行提交或推送。实现报告写入本目录 task-N-report.md；主执行者在复核通过后按模块提交。
- 接口校验学校、权限、归属和状态；新增请求使用显式框架路由。新增数据库定义放各自 Schema 类，提供可重复执行的增量迁移。
- 无必要不新增依赖。需要二维码时统一复用 shared 中 QRCode 组件及 npm qrcode。

### Task 9: 经费管理

Files：ExpenseController/Service/Record/Schema、ExpensePanel.vue、ExpenseForm.vue、H5 ExpenseApproval.vue、ExpenseDocumentExporter。

- [ ] 扩展base_expense并新增明细表；单位/学期/基地/类型/类别/多明细/附件/备注/合计；金额精确到分，后台重新汇总。
- [ ] 保存草稿/提交/退回修改/审批详情/我的待办；所有权限从学校和数据范围检查。
- [ ] 默认直接上级→教务处→教务处领导→抄送，可配置额外学院节点；未配置实际审批人禁止提交。
- [ ] Word/PDF模板复用导出队列，写审批意见、时间、签名快照，长内容可分页；旧费用不伪造明细。
- [ ] PC独立模块和H5审批接入，菜单和权限升级；PHP/PC/H5/导出实际排版验证。
