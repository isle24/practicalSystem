# Task 4: 企业微信 PC 扫码绑定

阅读批准设计第 4 节。Files：WechatBindingService/Controller、UserWechat、AuthMiddleware、fontend/shared/useWechatBinding.js、WechatBindingGate.vue；新增 Redis 会话 Service、共享 QRCode.vue、H5 WechatScanBinding.vue。不直接修改 PC/H5 App.vue、route.php 或 package.json，由主执行者接入。

接口：已登录 POST /wechat/scan-session、GET /wechat/scan-status?session_id=...、POST /wechat/scan-cancel；公开扫码 GET /wechat/scan/start?ticket=...、回调 /wechat/scan/callback；手机 POST /wechat/scan/confirm 使用 OAuth 得到的手机身份 cookie 和 CSRF nonce。

- [ ] 复用现有 OAuth SDK/身份验证、CorpId 和 UserWechat 唯一性；新票据绑定学校、PC账号/用户/设备，TTL 300秒、Redis一次性消费。
- [ ] 扫码 URL 用学校 HTTPS 地址（客户端 serverOrigin），二维码不能包含JWT、身份信息；父App只传学校公开来源。
- [ ] 手机授权后展示待绑定账号和企微身份，点击确认；需要验证身份对应关系，不能仅拿到二维码就绑定任意账号。
- [ ] 轮询只返回创建者自己的会话；成功、过期、取消、账号切换清理定时器；状态完成刷新上下文。
- [ ] 40310拦截保持学校策略；允许未绑定会话调用新增绑定接口；不能 blanket 放行业务API。
- [ ] 管理员列表与服务端拦截使用一致 CorpId/过期判断。
- [ ] 提供路由及 App 接入段到报告；php -l、PC/H5 构建可在依赖由主执行者补齐后运行。


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

