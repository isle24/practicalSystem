# Task 3: 基地立项、菜单资料与实习计划软删除

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

