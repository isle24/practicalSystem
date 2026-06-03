用户体系：users → account → user_role → role，角色数据范围由 sys_organization 存储，students 和 teacher_list 挂 users 下
- 02-用户服务设计.md

基础档案：departments → professions → profession_directions / classes，grades，companies → base → base_profession_direction
- 13-基础档案设计.md

师生配对：student_join_teacher（申请阶段）→ pair（确认阶段），三模块统一
- pair 含 student_id、teacher_id、dep_id、second_teacher_id、type、status(active/removed)
- grade_teacher_guide 控制教师容量
- 08-实习管理模块设计.md

签到日志：sign_in / journal 多态表，entity_type + entity_id 支持三模块复用
- 08-实习管理模块设计.md

权限：RBAC 三层，数据过滤由独立数据库边界保证，库内按 college_id / pair 关系过滤
- 01-权限系统设计.md

消息：渠道驱动架构 channels JSON 数组，internal/wechat/sms/edu 驱动
- 03-消息服务设计.md

配置：三级覆盖（个人>学院>系统默认），Redis 三级缓存（L1 内存→L2 Redis→L3 DB）
- 07-配置模块设计.md

文件：public/files/{block}/{school_code}/{category}/{yyyymmdd}/{uuid}.{ext}
- is_temporary 标记临时文件自动清理
- 06-文件管理服务设计.md

完整表清单与 ER 关系：89 张表，见 14-数据库设计汇总.md
