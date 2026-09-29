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

### Task 5: 壁纸库

Files：新增 WallpaperController/Service/Record/Schema、WallpaperLibrary.vue、独立 migration；ProfileController 改为调用壁纸服务保存选择，ProtectedFiles 增加私有/共享壁纸权限。

接口：GET /wallpaper/list?scope=mine|shared、POST /wallpaper/upload、POST /wallpaper/share {id,shared}、POST /wallpaper/apply {id或mode:school}、学校外观 Service 的默认壁纸读写。

- [ ] 校内隔离、上传默认私有、每张独立分享开关、分页、一键应用，推荐1920×1080、8MB。
- [ ] owner/user 与 file_id引用验证；私有文件不匿名公开，撤回后其他用户刷新回学校默认。
- [ ] 学校默认素材保留独立文件引用，只有学校管理员配置；个人选择优先，school模式随学校默认变更。
- [ ] 兼容现有 wallpaper_url；当前已用壁纸迁入本人列表，更早混用 profile 分类不自动公开。
- [ ] PHP/PC构建、手动核对两用户范围和默认选择。
