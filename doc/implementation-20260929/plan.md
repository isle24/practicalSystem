# 学校外观、个人素材与通用审批实施计划

> 执行：使用 subagent-driven-development 的实现/复核流程；互不共享文件的任务按 dispatching-parallel-agents 并行执行。共享入口由主执行者顺序整合。

**目标：** 完成已批准设计中的全部需求，最终统一发布客户端。

**架构：** Webman 路由到 Controller、Service、Model；复用学校连接、文件服务、现有消息队列和导出队列。新前端交互放独立组件，App.vue 仅做接入。

**技术：** PHP 8.4、Webman、Illuminate Query Builder、Redis、Vue 3、Element Plus、Vant、Tauri。

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

### Task 1: 学校外观与登录样式

Files：新增 SchoolSettingsPanel.vue、SchoolAppearanceService.php；修改 ConfigController.php、FileRecord.php 中公开素材校验、PC App.vue/styles.css。

接口：GET /config/login-page 增加 school_logo_url；GET /school-appearance/settings，POST /school-appearance/upload-logo 和 /upload-background。学校写接口限 super_admin/school_admin + config:manage，读取公开学校外观不泄露其他配置。

- [ ] 将配置读写和素材上传置于 Service，Controller 仅收参和响应。
- [ ] Logo 推荐 256×256，PNG/JPEG/WebP、2 MB，实际图像校验；独立 category=school_logo，public 展示权限纳入既有文件机制。
- [ ] SchoolSettingsPanel 以独立卡片维护 Logo 和已有学校登录背景；默认壁纸由 Task 5 接入。
- [ ] 登录 Logo 使用 contain；默认建筑图标；登录蓝色按钮用 login-submit-button 类；下载按钮透明/浅色 Hover。
- [ ] App.vue 新学校设置菜单，移除个人设置中的学校背景，保留旧配置值。
- [ ] 执行 php -l 和 PC 生产构建，实际检查界面。

### Task 2: 窗口与弹窗全屏

Files：DesktopWindow.vue、OperationDialog.vue、styles.css、desktop-appearance.css；新增 composables/useMaximizedWindows.js；原有 el-dialog 详情通过相同状态注册接入。

接口：`useMaximizedWindow()` 返回 register/unregister，公共 `hasMaximizedWindow` ref。使用实例级 Set；每实例 watch visible/maximized，并在卸载清理。

- [ ] DesktopWindow 最大化填满桌面 workspace；顶层响应登记将桌面网格改为单行、隐藏 Dock。
- [ ] OperationDialog 最大化遮罩 padding=0，section 100vw/100dvh，无圆角。
- [ ] 还原、关闭、最小化、logout 和卸载清理；嵌套窗口不提前恢复 Dock；保留恢复坐标。
- [ ] 审核原有详情弹窗适配，保持浮层和 select 不被遮挡。
- [ ] PC 构建并在 Windows/Mac 风格检查最大化、还原、关闭。

### Task 3: 基地立项、菜单资料与实习计划软删除

阅读批准设计第 3 节“基地菜单与立项字段”和第 6 节。实现全部后端及 InternshipBaseForm 独立组件；App.vue 接入要求写报告由主执行者完成。

Files：InternshipController.php、InternshipService.php、InternshipRecord.php、InternshipBaseForm.vue；新增 InternshipUpgradeSchema.php、独立 migration，独立 PC API 方法可修改 api/system.js 但在报告列清楚新增导出。

接口：`GET /internship/plan-delete-impact?id=...` 返回 `{id,course_name,arrangements:[{id,title}],arrangement_count,revision}`；`POST /internship/remove-plan` 接受 `{id,include_arrangements,revision}`；返回 `{id,deleted,arrangement_ids}`。两接口使用同一已有计划管理权限/数据范围。

- [ ] base 增加 is_project_approved TINYINT(1) 默认0；按最新历史申报严格映射是/已立项/1/true，保留历史文本。
- [ ] 列表/详情输出布尔值，筛选处理false/0，保存未传不覆盖，新建默认否；表单 radio true/false。
- [ ] 删除影响使用 arrangement.plan_id，无跨学校或无权限数据；revision 反映活动任务集合和计划变更。
- [ ] 在现有 WorkflowLock 和事务中锁计划、复核任务集合、确认cascade；只软删除计划/任务，保留文件、过程材料和历史审批。
- [ ] 所有关联活动任务查询和可写路径排除已删计划/任务；相关待办取消（如工作流尚未合入，报告清楚接口供主执行者衔接）。
- [ ] 提供菜单种子和存量菜单更新代码的独立 Schema 方法，基地申报首项，基地汇总统计、审批表、基地巡查；不直接编辑 init.php。
- [ ] php -l、相关前端构建和 diff --check；报告必要 App.vue 列、过滤、默认面板和删除按钮接入点。

### Task 4: 企业微信 PC 扫码绑定

阅读批准设计第 4 节。Files：WechatBindingService/Controller、UserWechat、AuthMiddleware、fontend/shared/useWechatBinding.js、WechatBindingGate.vue；新增 Redis 会话 Service、共享 QRCode.vue、H5 WechatScanBinding.vue。不直接修改 PC/H5 App.vue、route.php 或 package.json，由主执行者接入。

接口：已登录 POST /wechat/scan-session、GET /wechat/scan-status?session_id=...、POST /wechat/scan-cancel；公开扫码 GET /wechat/scan/start?ticket=...、回调 /wechat/scan/callback；手机 POST /wechat/scan/confirm 使用 OAuth 得到的手机身份 cookie 和 CSRF nonce。

- [ ] 复用现有 OAuth SDK/身份验证、CorpId 和 UserWechat 唯一性；新票据绑定学校、PC账号/用户/设备，TTL 300秒、Redis一次性消费。
- [ ] 扫码 URL 用学校 HTTPS 地址（客户端 serverOrigin），二维码不能包含JWT、身份信息；父App只传学校公开来源。
- [ ] 手机授权后展示待绑定账号和企微身份，点击确认；需要验证身份对应关系，不能仅拿到二维码就绑定任意账号。
- [ ] 轮询只返回创建者自己的会话；成功、过期、取消、账号切换清理定时器；状态完成刷新上下文。
- [ ] 40310拦截保持学校策略；允许未绑定会话调用新增绑定接口；不能 blanket 放行业务API。
- [ ] 管理员列表与服务端拦截使用一致 CorpId/过期判断。
- [ ] 提供路由及 App 接入段到报告；php -l、PC/H5 构建可在依赖由主执行者补齐后运行。

### Task 5: 壁纸库

Files：新增 WallpaperController/Service/Record/Schema、WallpaperLibrary.vue、独立 migration；ProfileController 改为调用壁纸服务保存选择，ProtectedFiles 增加私有/共享壁纸权限。

接口：GET /wallpaper/list?scope=mine|shared、POST /wallpaper/upload、POST /wallpaper/share {id,shared}、POST /wallpaper/apply {id或mode:school}、学校外观 Service 的默认壁纸读写。

- [ ] 校内隔离、上传默认私有、每张独立分享开关、分页、一键应用，推荐1920×1080、8MB。
- [ ] owner/user 与 file_id引用验证；私有文件不匿名公开，撤回后其他用户刷新回学校默认。
- [ ] 学校默认素材保留独立文件引用，只有学校管理员配置；个人选择优先，school模式随学校默认变更。
- [ ] 兼容现有 wallpaper_url；当前已用壁纸迁入本人列表，更早混用 profile 分类不自动公开。
- [ ] PHP/PC构建、手动核对两用户范围和默认选择。

### Task 6: 通用审批内核

Files：server/app/server/workflow/WorkflowService.php、WorkflowDefinitionService.php、WorkflowEntityAdapter.php、WorkflowNotificationService.php；model/channel/WorkflowRecord.php、WorkflowSchema.php；WorkflowController.php；独立迁移。

接口：`start(string $entityType,int $entityId,array $context): array`、`review(int $instanceId,string $action,string $opinion,?int $signatureId,string $revision): array`、`cancel(string $entityType,int $entityId,string $reason): void`、`history(string $entityType,int $entityId): array`。实体从受控适配器注册表解析，不能任意表名。

- [ ] 版本化流程定义及节点、实例、任务、不可变操作记录、通知outbox；学校连接和文件引用复用现有模式。
- [ ] 任意个串行节点；role+部门或指定账号；或签/会签，退回modify再提交新轮次，抄送不阻塞审批。
- [ ] 同事务状态推进/记录/outbox；版本和当前任务校验，Redis锁+DB行锁；重复请求幂等。
- [ ] 申请人不可改审批人；提交时解析全部节点，不足审批人报422；保留候选人和配置快照。
- [ ] 每节点消息enable/channels/template，允许internal/wechat/sms；recipient来自当前任务。
- [ ] PHP语法、重点状态分支代码审查；向主执行者报告消费者接口，签名Task7通过snapshot API注入。

### Task 7: 个人电子签名

Files：SignatureController/Service/Record/Schema；shared SignatureCanvas.vue、PC SignatureSettings.vue、H5 SignaturePage.vue；导出公共 SignatureRenderer。

接口：GET /signature/current；POST /signature/save（PNG上传）、POST /signature/session、GET /signature/session-status；Service `snapshotForCurrentUser(?int $signatureId): array` 返回 file_id、version、sha256、user_id、signed_at。

- [ ] 短时一次性二维码、手机同一用户验证、透明PNG、空白拒绝、裁切空白。
- [ ] 私有签名版本与文件引用；修改不覆盖历史；审批仅本人签名。
- [ ] 导出读取审批快照，Word/HTML/PDF按比例嵌入；不回填无签名历史。
- [ ] PC/H5接入与PHP/构建验证，明确个人与安全承诺书签字文件的差别。

### Task 8: 审批配置与消息通道、旧流程适配

Files：WorkflowSettingsPanel.vue、WorkflowHistory.vue、MessageService、独立SmsMessageDriver、现有审批服务适配器及Process。

- [ ] 管理员按学院/模块编辑顺序节点、账号/角色、any/all、需要签名、消息开关、模板、渠道；发布前解析和校验。
- [ ] internal/wechat复用现有队列；新增业务短信网关配置与驱动，按学校/模板/个人允许渠道求交集，缺配置不可发送。
- [ ] outbox投递与重试只处理已提交状态，稳定去重键防重复。
- [ ] 实习、实验实训、社会实践审批入口经兼容适配器调用原业务逻辑，新提交沿用原审批规则；不创建通用流程实例/任务，不迁移进行中的旧流程或补发旧消息。基地申报与经费申请使用通用审批服务。
- [ ] 计划软删除取消活动任务，公共导出读取签名快照。
- [ ] PHP语法、PC/H5构建，审查权限和消息幂等。

### Task 9: 经费管理

Files：ExpenseController/Service/Record/Schema、ExpensePanel.vue、ExpenseForm.vue、H5 ExpenseApproval.vue、ExpenseDocumentExporter。

- [ ] 扩展base_expense并新增明细表；单位/学期/基地/类型/类别/多明细/附件/备注/合计；金额精确到分，后台重新汇总。
- [ ] 保存草稿/提交/退回修改/审批详情/我的待办；所有权限从学校和数据范围检查。
- [ ] 默认直接上级→教务处→教务处领导→抄送，可配置额外学院节点；未配置实际审批人禁止提交。
- [ ] Word/PDF模板复用导出队列，写审批意见、时间、签名快照，长内容可分页；旧费用不伪造明细。
- [ ] PC独立模块和H5审批接入，菜单和权限升级；PHP/PC/H5/导出实际排版验证。

### Task 10: 汇总验证与统一发版

- [ ] 汇总所有Schema到init、增量SQL和schema-baseline生成源，核对已有库升级可重入。
- [ ] 检查全部设计要求、API访问范围、PC和H5交互、客户端刷新绑定与公共URL。
- [ ] php -l覆盖改动文件；npm run build覆盖PC/H5/desktop；cargo check --locked；git diff --check。
- [ ] 全分支独立代码审阅并解决问题后提交。
- [ ] 核对发布凭据和最新版本，更新所有版本文件及说明；仅同步客户端允许目录到GitHub。
- [ ] 构建三平台并核对资产/签名/哈希后统一发版；学校服务端实际升级状态独立记录，不虚报部署。
