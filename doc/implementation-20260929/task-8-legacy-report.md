# Task 8 既有审批接入记录

## 修改文件

- `server/app/server/workflow/WorkflowService.php`：受控兼容入口、查询最新实例、外层主体事务提交、计划删除取消。
- `server/app/server/workflow/LegacyWorkflowAdapter.php`：既有审批接口。
- `server/app/server/workflow/adapters/BaseApplicationAdapter.php`：基地申报实体读写、权限、业务快照。
- `server/app/server/internship/InternshipService.php`：新版基地提交和审核分流、列表元数据、历史查询、删除取消、旧审批接口接入。
- `server/app/server/internship/InternshipStudentChangeService.php`：学生资料变更审核接入。
- `server/app/server/practice/PracticeService.php`：实验实训审核和历史接入。
- `server/app/server/socialpractice/SocialPracticeService.php`：社会实践审核、指导教师确认和历史接入。

## 主执行者接线

`server/config/workflow.php` 的 adapters 增加：

```php
'base_application' => \app\server\workflow\adapters\BaseApplicationAdapter::class,
```

签名服务继续使用 `app\server\signature\SignatureService`。兼容注册表固定在 WorkflowService 内，不加入管理员可配置的版本化流程类型。

基地申报列表 `items[].workflow` 和保存响应 `workflow` 为最新实例或 null。实例含 `instance_id/entity_type/entity_id/round/status/active_position/revision`，列表另外含 `can_review/signature_required`。保存草稿无实例时为 null；新提交返回新实例字段。现有旧 wait 没有实例时仍是 null。

`saveBaseFlow(type=application,status=wait)` 不再直接写 wait：学校事务内保存 draft，再由内核锁主体、冻结版本和候选人、写快照并推进到 wait。提交失败整体回滚。可传 `request_id`；旧前端未传时生成新标识。可靠提交重试使用 `/workflow/start` 并复用 request_id；旧保存接口仍保留 wait 状态不可重复编辑的约束。

`reviewBaseFlow` 检测到新版实例时要求请求 `revision`，可传 `signature_id`；内核校验活动节点和待办人。新版UI应使用 `workflow.can_review`、`workflow.signature_required`，把 revision 原样传回。也可直接调用 `/workflow/review`。返回保留 `id/status`，添加 workflow。旧实例继续使用原意见、角色和状态约束。

旧 timeline 输出保留 records/reviews/cycles/items，基地申报额外返回 `workflow: {items: [...]}`，其他实体为 null。新版历史使用 WorkflowHistory 或 `/workflow/history`，不混写旧 recording 模拟新节点记录。

## 固定注册范围

- InternshipService：reviewBaseFlow、reviewArrangement、reviewArrangementChange、reviewApplication、reviewJournal、reviewReport、reviewDelay、reviewPlan、reviewDocument、requestModification、timeline。
- InternshipStudentChangeService：review。
- PracticeService：review、reviewJournal、reviewReport、requestModification、timeline；沿用构造时 training/lab/all 模块上下文。
- SocialPracticeService：review、confirmTeacher、requestModification、timeline。

实体涵盖实习计划五级审核、任务及变更、实习申请、日志、报告、延期、大纲、实施表、教师工作报告、巡查、毕业鉴定、四类基地历史审批、学生资料变更；实验实训计划、大纲、教案、成绩、反思、日志、报告；社会实践计划、项目、实施方案、申报、材料、补签、成绩和指导教师确认。

公开原服务方法先进入 WorkflowService::legacy；通用入口验证学校/账号和服务精确类、固定操作白名单；适配器再用 match 调用改为 private 的原实现。调用者不能提供类名、任意方法名、SQL、表名或回调。没有递归调用公开包装方法。原实现的权限、主体事务、锁、五级/节点规则、禁止自审规则、审核意见、历史和既有通知保持原路径。

审核草稿仅保存意见，不推进流程；导入确认、团队成员本人确认和企业导师评价不是审批节点，继续原接口。全量搜索 server/app/server 的 public review/approve/audit/confirm 入口后确认毕业鉴定由 reviewDocument 分派，不存在独立漏接审核服务。

## 权限与并发核查

- 基地 view/review 沿用现有数据范围与 internship:view/approve；start/cancel 要求 internship:manage、管理员角色且 submitter_id 为当前账号，并核对基地学院可见性。
- 新提交仅 draft/modify；重新提交冻结新轮次资料。snapshot 保存整条申报、整条基地及 updated_at 版本引用。
- 旧 wait 没有 workflow_instance 时使用原审核；主体行锁内再次检测不存在实例，防止并发提交后绕过节点。不会为旧数据创建实例或补发旧通知。
- 新流程不调用旧提交/审核通知，通知仅由内核 outbox 产生。
- 保存使用外层学校事务，先锁主体，startInTransaction 复用事务而不再获取 Redis 锁，避免持有主体锁等待另一个持有 Redis 锁的提交者。
- 计划软删除在原删除事务内调用 cancelDeletedInternshipPlan。该接口必须处于事务中，重新锁定并确认已删除计划；按主体行→关联主体行→实例行顺序处理。
- 取消覆盖 plan/internship_plan、arrangement/internship_arrangement，以及申请、学生资料变更、任务变更、签到、日志、报告、成绩、延期、保险、安全责任书、大纲、实施表、教师工作报告、巡查和毕业鉴定的现有及 internship_ 前缀类型。共享签到/日志/延期表明确限定 entity_type=internship。
- 活动实例写 cancelled，revision 增加，pending任务取消，未完成 outbox 标记 skipped，追加带删除原因的 history；不触发实体回写、不新建事务、不抢第二个 Redis 锁、不发送取消消息。主体/流程任一步失败整体回滚。
- 已结束实例和历史附件不变。旧待审核行保留原历史状态，沿用 Task3 的软删除计划/任务过滤。

## 验证

修改的七个 PHP 文件通过 `php -l`；`git diff --check` 无空白错误。逐项对照数据初始化定义核实关联表与外键、旧入口分派、节点权限、锁顺序、消息路径。未连接学校库、未执行迁移、未调用真实审批或通知。共享配置、路由、前端App、初始化及Git索引未修改。
