# Task 6 通用审批内核实现记录

## 文件

- `server/app/model/channel/WorkflowRecord.php`
- `server/app/model/channel/WorkflowSchema.php`
- `server/app/server/workflow/WorkflowEntityAdapter.php`
- `server/app/server/workflow/WorkflowDefinitionService.php`
- `server/app/server/workflow/WorkflowService.php`
- `server/app/server/workflow/WorkflowNotificationService.php`
- `server/app/controller/Api/WorkflowController.php`
- `server/database/migrations/20260929_workflow.php`

## 主执行者接线

`server/config/workflow.php` 由主执行者添加。配置只接受代码中注册的适配器；空配置、未知实体均返回 422。

```php
return [
    'adapters' => [
        'base_expense' => \app\server\workflow\adapters\ExpenseAdapter::class,
    ],
    'signature_snapshot' => \app\server\signature\SignatureService::class,
];
```

上面的类名仅为接线示例，请替换为实际适配器及签名服务名称。请求不得指定类名或表名。entity_type 仅允许小写字母开头的小写字母、数字、下划线，最多80字符。

### 适配器事务契约

```php
interface WorkflowEntityAdapter
{
    public function load(int $entityId, bool $forUpdate = false): array;
    public function authorize(string $operation, array $entity): void;
    public function snapshot(array $entity): array;
    public function transition(array $entity, string $status, array $context): void;
}
```

- `load`：在当前学校库读取主体；不存在抛404，`forUpdate=true` 必须使用 `lockForUpdate()`。至少返回 `id/status/dep_id`；不要在 load 中仅按申请人过滤，否则当前审批人无法处理。
- `authorize`：操作名为 `view/start/review/cancel`。检查业务权限、数据范围、状态及实体版本；`start` 必须验证当前账号有权以申请人身份提交。申请人/审批人关系由内核补充验证。
- `snapshot`：返回该轮完整不可变业务资料数组，包括业务版本及导出所需字段、关联文件引用。随后编辑实体不得改变已有实例快照。
- `transition`：与内核同一学校数据库事务；不能发送外部消息、执行DDL或单独提交事务。`status` 为 wait/accept/modify/cancelled，兼容实体取消状态由适配器映射。会签尚未完成时收到 wait，不能将单人 accept 动作直接解释为整轮通过。
- `context`：`instance_id/round/action/actor_id`，审批时另有 `opinion/signature`，取消时另有 `reason`。必要业务回调必须在此事务执行并使用最终 status。
- 已运行旧流程由兼容适配器继续原流程；本内核不推断或迁移历史审批。
- 计划删除可在同一学校数据库外层事务内调用 cancel，再删除业务主体；所有入口统一先主体行、再实例行的加锁顺序。

签名服务 `snapshotForApproval(int $signatureId): array` 必须验证当前账号拥有签名、学校归属、文件有效性，返回当次文件ID/版本/摘要/签署人/时间。内核将完整数组写入不可变 history.signature_json。节点要求签名但未提供或服务未配置时返回422，不补写历史签名。

## 控制器路由与载荷

控制器：`app\controller\Api\WorkflowController`。以下路径建议放入现有学校认证路由组，用显式 `Route::get/post` 注册。未修改共享路由文件。

响应沿用 `{code:0,message:'ok',data:...}`；失败为401/403/404/409/422，未预期异常500写服务端日志。

| 方法和建议路径 | 控制器方法 | 输入 | data |
| --- | --- | --- | --- |
| GET `/api/workflow/options` | options | 无 | `{entity_types:string[],accounts:[{id,login_name,name}],roles:[{id,name,role_type}],departments:[{dep_id,dep_name}],channels:['internal','wechat','sms']}` |
| GET `/api/workflow/definitions` | definitions | 无 | `{items:[{id,entity_type,dep_id,name,current_version_id,created_by,created_at,updated_at,versions:[{id,definition_id,version,name,created_by,created_at,updated_at}]}]}` |
| GET `/api/workflow/definition` | definition | version_id | `{version:{id,definition_id,version,name,...},nodes:Node[]}` |
| POST `/api/workflow/save` | save | entity_type,dep_id,name,nodes | `{definition_id,version_id,version,nodes}`；每次保存创建不可变新版本，不自动发布 |
| POST `/api/workflow/publish` | publish | version_id | `{definition_id,version_id,nodes,validated_department_ids:int[]}` |
| GET `/api/workflow/preview` | preview | entity_type,entity_id | `{version_id,nodes:ResolvedNode[]}`，按实体学院解析，只读，申请人无权改链 |
| POST `/api/workflow/start` | start | entity_type,entity_id,request_id | WorkflowResult |
| POST `/api/workflow/review` | review | instance_id,action,opinion,signature_id?,revision | WorkflowResult |
| POST `/api/workflow/cancel` | cancel | entity_type,entity_id,reason | `[]` |
| GET `/api/workflow/history` | history | entity_type,entity_id | `{items:Instance[]}`，轮次倒序 |
| GET `/api/workflow/inbox` | inbox | page=1,page_size=20,kind=review或cc | `{items:InboxItem[],pagination:{page,page_size,total}}` |

options/definitions/definition/save/publish 仅学校管理员或超级管理员可调用。学院流程作用域由管理员维护的 dep_id 表示，0表示学校默认。学院存在独立定义但未发布时阻止提交，不悄然使用默认审批链。

```json
{
  "name": "教务处审批",
  "kind": "review",
  "mode": "all",
  "signature_required": false,
  "selector": {
    "type": "accounts",
    "account_ids": [123],
    "role_id": 0,
    "department": "entity",
    "dep_id": 0
  },
  "notification": {
    "enabled": true,
    "channels": ["internal"],
    "template": "workflow_pending"
  }
}
```

- Node.kind：review/cc；mode：any/all；至少一个review节点，最多100节点，串行排列。
- selector.type=accounts 按账号显式解析；全部指定账号必须有效。role 则要求 role_id，department 为 entity（实体学院）、fixed（指定dep_id）、school（学校范围）。role 账号的 user_role、role、sys_organization 必须有效。指定账号不额外套角色学院筛选。
- ResolvedNode 在 Node 基础上添加 `candidates:[{account_id,user_id,name}]`。候选人在提交时全部解析并冻结。role/entity 学校默认流程发布前逐学院校验；没有有效学院或者某学院缺审批人即阻止发布。
- 保存允许暂时缺审批人以便管理员配置；发布和提交均禁止缺人。未预置真实人员或自动管理员审批人。
- 通知关闭时仍生成review待办或cc任务记录；cc非阻塞。kind=cc 的任务status=notified表示流程已生成抄送记录，不代表渠道已投递；实际投递状态查outbox。
- `request_id`：8到120字符，仅字母/数字/下划线/点/冒号/短横线。每次新提交生成新值，同一提交重试复用；不同轮次不能复用旧值。
- `action`：accept/modify；modify必须填写意见，意见最多2000字符。revision为字符串（由响应读取并原样传回）。同账号同实例同revision重复相同请求返回原响应；改动作/意见/签名返回409。

WorkflowResult：

```json
{"instance_id":12,"entity_type":"base_expense","entity_id":34,"round":2,"status":"wait","active_position":1,"revision":"1"}
```

Instance 包含 `id/entity_type/entity_id/round/definition_version_id/applicant_id/status/active_position/revision/snapshot_json/nodes_json/finished_at/created_at/updated_at/tasks/history`；revision为字符串，snapshot_json为业务资料数组，nodes_json为ResolvedNode数组，request_key不返回。

Task 包含 `id/instance_id/position/account_id/status/kind/handled_at/created_at/updated_at`。status为pending/accept/modify/cancelled/notified。

History 包含 `id/instance_id/position/actor_id/action/from_status/to_status/opinion/signature_json/idempotency_key/request_hash/result_json/created_at/updated_at`。action为start/accept/modify/cancel。result_json为WorkflowResult。position=0表示提交。

InboxItem 包含 `task_id/kind/position/task_status/instance_id/entity_type/entity_id/round/status/revision/snapshot_json/nodes_json`。review只返回当前节点有效待办；cc返回当前账号已创建抄送记录。需要展示实体详情时用entity_type/entity_id定位。

history允许业务适配器授权的查看者，或该实体已创建任务的参与人查看；后续尚未激活节点候选人不自动取得查看权。

## 通知消费者接线

```php
$service = new WorkflowNotificationService();
$items = $service->claim(50);
$service->markSent($id, $claimToken);
$service->markSkipped($id, $claimToken, $reason);
$service->markFailed($id, $claimToken, $reason, $retry);
```

- 消费前按学校数据库编号 bootstrap 上下文。不需要用户会话，不使用默认学校或跨学校扫描。
- claim使用数据库行锁、SKIP LOCKED、5分钟租约；返回 `{id,instance_id,position,recipient_id,channel,template,payload_json,dedupe_key,status,attempts,available_at,locked_until,claim_token,last_error,sent_at,created_at,updated_at}`。
- payload_json 包含 `event=node_activated/entity_type/entity_id/instance_id/round/task_id/node_name/kind/snapshot`。
- recipients直接来自已创建当前节点任务，不搜索全体管理员。
- 消费者计算节点渠道、学校启用渠道、模板配置、个人偏好的交集；企业微信继续绑定及时间窗校验；短信配置不全标记skipped并明确原因，不标记sent。
- 消费者必须将 dedupe_key 传给支持幂等的渠道或复用消息去重记录；租约和去重键不能保证不支持幂等的第三方接口恰好一次，尤其远端成功、本地确认前进程退出时。
- 失效review待办会在claim时标记skipped；消费者发送前仍应核对任务有效性以缩小取消并发窗口。
- markSent仅在真实成功后调用；禁用/无配置/无绑定/偏好关闭用markSkipped；暂时故障用markFailed(...,true)。指数退避最大1小时，最多10次，永久失败可用false。
- 本子任务没有消费者、实际消息发送或外部网关调用。

## 数据结构与状态验证

七张学校表：workflow_definition/version/node/instance/task/history/outbox。流程版本、节点配置、实例nodes_json与snapshot_json、history均只追加。实例revision、状态及任务状态允许推进。未增加请求驱动的任意SQL、类名、表名或脚本表达式。

- start/review/cancel共用学校+实体Redis严格锁，并在同一学校DB事务内先锁实体、再锁实例。Redis租约过期时数据库行锁继续保护状态；实体+轮次、操作幂等键、任务候选、outbox去重键有唯一约束。
- 或签通过取消同节点其余待办；会签全部通过才推进；任一退回结束本轮并取消待办；退回后新request_id创建新轮次，旧快照和记录保留。
- review校验实例revision、实例wait、主体wait、活动节点、当前账号pending任务及账号/用户有效性，然后由适配器补充权限与实体可写条件。
- 流程推进、适配器状态写入、操作记录、outbox同事务提交；事务内不执行DDL或网络投递。
- cc按序生成任务及outbox后继续推进，不阻塞；通知关闭不影响记录或任务生成。

增量迁移：在server目录执行 `php database/migrations/20260929_workflow.php 学校数据库编号`，支持多个编号。所有建表使用CREATE TABLE IF NOT EXISTS，可重复执行，不删除旧数据。主执行者将 `WorkflowSchema::creationStatements()` 接入新库初始化及基线；本子任务未改共享init.php、基线、路由或Git索引。

## 已执行验证与边界

8个新增PHP文件均通过本机php -l。逐分支核对缺少适配器、空候选人、disabled角色/账号、无学院角色解析、过期revision、或签/会签/退回/取消、重复操作、消息租约及重试状态；未执行数据库迁移和外部发送。当前隔离目录无server/vendor/autoload.php，未进行框架加载或数据库运行验证。
