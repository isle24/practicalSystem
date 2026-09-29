# Task 8 通知投递文件与接线

## 接线

- 学校认证路由组显式 GET `/api/workflow-message/settings` → `MessageChannelSettingsController::settings`；POST 同路径 → `save`。要求学校上下文、学校/超级管理员角色、`config:manage` 权限。
- `MessageChannelSettings.vue` 无 props，使用现有 request 客户端；放在学校配置的消息渠道页面。个人 PC/H5 页面增加 `notify.sms` 开关，默认关闭；ProfileController 已支持读写该字段。
- `process.php` 增加 `workflow_message_relay`，handler 为 `app\process\WorkflowMessageRelay::class`，count 为 1。复用 SchoolConnectionManager、学校枚举和定时 relay 模式；已有 WechatMessageRelay/RedisQueue 消费者保留。
- ConfigController 通用保存入口须阻止 `workflow_message` 配置组，要求走专用接口；主执行者负责接线。
- 消息模板编辑渠道需可选择 sms，并允许取消 internal。MessageService 已接受三种模板渠道；新 workflow_pending 默认仅 internal，管理员需明确开启模板中的企业微信/短信。

## 数据库升级

1. 原消息、个人通知、绑定手机号相关结构升级完成。
2. 执行 `20260929_workflow.php`。
3. 执行 `php database/migrations/20260929_workflow_delivery.php 学校数据库编号`。
4. 启动消息 relay。

WorkflowSchema 新建 outbox 已包含 nullable `message_id`。WorkflowDeliverySchema 对已有表补列，可重复执行，同时补默认消息模板。outbox 状态为 VARCHAR，新增 queued 无需改字段类型。默认模板 seed 不恢复被用户关闭或删除的 workflow_pending。未运行任何数据库升级。

## 投递行为

- 节点 outbox 仅由节点 enabled/channels 决定；投递前求学校、模板、个人偏好交集。站内偏好对应 system，短信默认关闭；企业微信和短信沿用个人允许时段。
- 审批任务发送前重新检查 pending、实例 wait 和 active_position；抄送检查任务 notified 且实例未取消。企业微信消费者再次复查学校/模板/个人偏好、任务活跃、UserWechat 有效绑定及 CorpId/失效时间。
- 每个 outbox 使用持久化 message_id。锁住 outbox、创建 message/渠道日志、保存 message_id 在同一事务完成。internal 只创建一条站内 target；wechat/sms 使用 exact_channels 不额外生成站内消息。原有调用默认仍生成 internal。
- internal 写入即成功；wechat 只标 queued，原企业微信队列真实 sent/failed/skipped 后 relay 同步 outbox；短信收到明确成功协议后才标 sent。已保存 sent 日志可恢复本地确认，失败指数退避，最多十次。
- 发送在数据库事务提交后进行。持久化重试不会重复创建消息与渠道任务；短信网关幂等键包含学校数据库编号与 outbox dedupe_key。企业微信远端成功但本地确认前退出仍可能重复，现有企业微信接口不提供本系统幂等键协议。
- 未启用、缺配置、无有效绑定、手机号未验证、个人关闭均保留 skipped 原因。短信失败日志不含完整号码、凭据或远端响应正文。

## 业务短信网关协议

独立 SmsMessageDriver，不调用验证码网关。设置组 workflow_message 保存 channels/sms_url/sms_sender/sms_token；sms_token 使用 SecretCipher（依赖现有 TEACHER_SYNC_ENCRYPTION_KEY）加密，查询仅返回 sms_token_configured，不返回明文。留空保留凭据，clear_sms_token 明确清除；缺配置不允许启用短信。

地址仅允许 HTTPS、公网域名、443 端口，无用户信息、查询参数和片段。实际发送解析公网 IPv4 并通过 CURLOPT_RESOLVE 固定本次连接地址，拒绝私网/保留地址，不跟随重定向，不使用环境代理。

POST JSON：`{mobile,content,sender,idempotency_key}`，Header 为 `Authorization: Bearer ...` 和 `Idempotency-Key`。网关必须按幂等键返回同一结果，重复请求不得再次下发。成功须 HTTP 2xx 且 JSON `{success:true,message_id:"非空字符串",idempotency_key:"原值"}`；其余均不标 sent。连接超时5秒、总超时20秒。

## 已执行验证

14个新增或修改 PHP 文件通过 php -l。MessageChannelSettings.vue 通过 Vue compiler-sfc 脚本与模板编译。未启动 relay、发送实际消息或修改生产数据库。PC/H5整体构建由主执行者在完成共享接线后统一执行。
