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

### Task 8: 审批配置与消息通道、旧流程适配

Files：WorkflowSettingsPanel.vue、WorkflowHistory.vue、MessageService、独立SmsMessageDriver、现有审批服务适配器及Process。

- [ ] 管理员按学院/模块编辑顺序节点、账号/角色、any/all、需要签名、消息开关、模板、渠道；发布前解析和校验。
- [ ] internal/wechat复用现有队列；新增业务短信网关配置与驱动，按学校/模板/个人允许渠道求交集，缺配置不可发送。
- [ ] outbox投递与重试只处理已提交状态，稳定去重键防重复。
- [ ] 所有现有审批入口经通用服务；旧进行中实例由兼容适配器保持原规则，不补发旧消息。
- [ ] 计划软删除取消活动任务，公共导出读取签名快照。
- [ ] PHP语法、PC/H5构建，审查权限和消息幂等。
