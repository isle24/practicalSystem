# Task 6: 通用审批内核

Files：server/app/server/workflow/WorkflowService.php、WorkflowDefinitionService.php、WorkflowEntityAdapter.php、WorkflowNotificationService.php；model/channel/WorkflowRecord.php、WorkflowSchema.php；WorkflowController.php；独立迁移。

接口：`start(string $entityType,int $entityId,array $context): array`、`review(int $instanceId,string $action,string $opinion,?int $signatureId,string $revision): array`、`cancel(string $entityType,int $entityId,string $reason): void`、`history(string $entityType,int $entityId): array`。实体从受控适配器注册表解析，不能任意表名。

- [ ] 版本化流程定义及节点、实例、任务、不可变操作记录、通知outbox；学校连接和文件引用复用现有模式。
- [ ] 任意个串行节点；role+部门或指定账号；或签/会签，退回modify再提交新轮次，抄送不阻塞审批。
- [ ] 同事务状态推进/记录/outbox；版本和当前任务校验，Redis锁+DB行锁；重复请求幂等。
- [ ] 申请人不可改审批人；提交时解析全部节点，不足审批人报422；保留候选人和配置快照。
- [ ] 每节点消息enable/channels/template，允许internal/wechat/sms；recipient来自当前任务。
- [ ] PHP语法、重点状态分支代码审查；向主执行者报告消费者接口，签名Task7通过snapshot API注入。


## 约束

- 用户已确认 doc/2026-09-29-功能改造设计.md；不再询问是否继续或重复确认该设计。
- 不生成任何类型的测试相关内容。使用 PHP 语法检查、前端构建、编译及实际界面验证。
- 不改生产数据，不发送真实消息，不替用户签名或审批真实申请。
- 禁止表情符号；新增注释只说明结构或用途；文档放 doc。
- 当前工作区 /Users/isle/.codex/worktrees/school-workflow-upgrade/practicalSystem，原目录不可用于编辑。
- 并行任务不得修改 App.vue、server/config/route.php、server/database/init.php、schema-baseline.json 或 Git 索引；这些由主执行者统一整合。
- 不自行提交或推送。实现报告写入本目录 task-N-report.md；主执行者在复核通过后按模块提交。
- 接口校验学校、权限、归属和状态；新增请求使用显式框架路由。新增数据库定义放各自 Schema 类，提供可重复执行的增量迁移。
- 无必要不新增依赖。需要二维码时统一复用 shared 中 QRCode 组件及 npm qrcode。

