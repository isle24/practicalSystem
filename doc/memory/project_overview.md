项目：实践管理系统（实习/实训/实验教学管理平台）。用户是系统设计者，需要协助审查和完善设计文档。

当前状态：设计阶段完成，20 份文档在 doc/ 目录下，尚未编码。

核心架构决策：
- PHP 8.4 + Webman + illuminate/database (Laravel Eloquent)，严格 MVC
- 独立数据库学校：每学校一库，库名由 databases.database_db 指定（命名建议 practical_{school_code}），主库 practical_master 存 schools/databases/authorizations 绑定关系
- 域名路由 → SchoolMiddleware 解析学校 → SchoolConnectionManager 注册动态连接名 → BaseModel 使用 school_connection
- 前端双端独立：fontend/h5 (Vant 4) + fontend/pc (Element Plus)，编译输出到 server/public/h5 和 server/public/pc
- 文件存储 public/files/{block}/{school_code}/{category}/{yyyymmdd}/{uuid}.{ext}，block 支持多盘扩容
- 默认 URL 直接访问，?n= 参数改名下载，X-Accel-Redirect API 备选
- UUID 混淆层：对外 UUID，内部 ID，Redis 双向缓存 school:{database_id}:uuid:*
- 配置三级覆盖：个人 > 学院 > 系统默认
- 工作流主状态优先使用 draft / wait / accept / modify，特殊状态按表定义
- 学校业务主表默认含 created_at / updated_at / deleted_at，主库元数据、日志分表、归档表和纯关联表按表定义处理
- API 对外使用 UUID，内部使用自增 ID，通过 UuidService 双向转换
- 消息 channel 驱动架构：internal / wechat / sms / edu / dingtalk / email
- 全局幂等：前端 v-prevent-duplicate + 后端 IdempotentMiddleware + X-Request-Token
- 字段命名：dep_id 与 college_id 等价（sys_organization 与业务表用 dep_id，config_item 用 college_id）

设计文档索引：
- 00-总体架构设计：项目结构、技术栈、多学校、UUID 混淆层、Agent 预留
- 01-权限系统设计：RBAC 三层、菜单/按钮/接口权限、数据过滤
- 02-用户服务设计：users/account/students/teacher_list、企业微信绑定、2FA、教务对接
- 03-消息服务设计：渠道驱动架构、message/message_target/message_template、消息中心
- 04-日志服务设计：按月分表 operation_log_YYYYMM
- 05-企业微信接入设计：OAuth、扫码登录、菜单可视化配置、wechat_menu_config
- 06-文件管理服务设计：秒传、多态关联 file_relation、Nginx 直链、block 多盘扩容
- 07-配置模块设计：三级覆盖、三级缓存、config_group/config_item
- 08-实习管理模块设计：申请/审核/签到/日志/报告/成绩（核心复用表定义在此）
- 09-实训管理模块设计：项目/实训室/预约/材料/报告，复用签到日志配对
- 10-实验管理模块设计：课程/项目/实验室/预约/材料/报告，复用签到日志配对
- 11-统计报表设计：多维度统计、导出任务、stat_cache/export_task
- 12-前端架构设计：H5 (Vant 4) + PC (Element Plus)，共享能力
- 13-基础档案设计：departments/grades/professions/classes/companies
- 14-数据库设计汇总：89 张表清单、ER 关系、全局约定、索引策略
- 15-API设计规范：路由命名、请求/响应格式、错误码体系、中间件链
- 16-部署运维设计：Nginx 配置、进程管理、备份策略、监控告警
