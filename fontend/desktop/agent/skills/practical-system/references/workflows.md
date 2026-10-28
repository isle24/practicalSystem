# 操作流程

本文件供 CLI Skill 与桌面 Agent 共用。按各自 SKILL 处理认证、任务、能力调用、人工批准和回执，不混用入口。下列 ID 仅作查找提示，可用能力、参数、权限、guards、上传支持以当前账号实时目录为准。菜单位置/页面下一步见 `menu-guides.md`；桌面 Agent 可用只读 `project_guide` 查询对应 key。

## 每次任务的顺序

1. 明确学校、角色、对象、操作及结束条件，区分查询、草稿、提交、审核、发布、删除、导入。审核结论、意见、批量范围和重复行处理由用户决定；缺关键决定先询问。
2. 读取实时能力目录，检查 ID、参数、guards、权限、`confirmation_required`。能力缺失、学校关闭、登录失效或权限不足即停止并报告原错误，不换隐藏入口、学校或账号。
3. 用对应列表/选项按关键词、组织、年级、计划或项目筛选并查看分页。重名展示候选 ID 和区别，由用户定位唯一对象；不猜 ID、不取第一条，不默认批量全部。
4. 读详情的状态、归属、完整内容、关系数组及 `revision`；无详情用列表完整行，字段不足请用户原界面核实。只用返回的真实 ID，区分 UUID、名称、本地路径、计划 ID 与任务 ID。
5. 合并用户修改与完整可写参数。保存通常全量覆盖，非 PATCH；省略可能清空/重置/移除关系。保留未改字段和完整数组，不回传只读或目录外字段，`forbidden_fields` 为 null 也不得传。公开参数无法保全原资料时停止，转原界面。
6. B：执行一次保存，检查成功响应/ID 后回查。草稿不等于提交。失败、冲突、响应不完整则保留内容并核实状态，不自行新建副本或循环重试。
7. C：先执行一次能力预览，核对对象、完整参数、状态、范围、影响及文件/行明细；把同源确认入口交用户以当前学校账号人工批准，工具不得代批。批准后按入口执行一次并读同一操作回执；改参数须取消旧预览重新确认。
8. pending、approved、executing 不算完成；拒绝/取消/过期即停止，不自动替代预览。中断、409、executing、unknown 或结果不明只查原回执，不重发执行。回查 ID、状态、版本、关系及逐行结果，如实报告失败/跳过；不声称整批成功或回滚。预算/期限耗尽即停，用户明确要求继续后再处理。

以下各流程每一步都沿用上述停止条件。C 步骤均包含预览、用户批准、单次执行和回执核对；只在用户要求该项变更时执行。

## 个人笔记

1. `note.list`：关键词定位本人唯一 id；普通笔记 `trash=false`，恢复用 `trash=true`。无匹配停止。
2. `note.detail`：传 id，取 `uuid/title/content_md/revision/deleted_at`；已删除须先恢复，详情不可读停止。
3. `note.save`（B）：新增生成有效 36 位 UUID；修改传原 id/uuid、最新 revision 和完整 title/content_md。省略正文会清空。返回笔记后 `note.detail` 核对新版本；409 先重读解决冲突。
4. `note.delete` / `note.restore`（C）：传最新 id/revision，预览回收站变化；执行后用详情/对应 trash 列表核对 deleted_at 和 revision。`note.purge` 关闭。

## 收藏

1. `favorite.list` → `favorite.detail`：按 scope、标题、URL 定位唯一 id，取范围、revision；可见不等于可删除。
2. `favorite.save`（B）：仅新增，显式 `id=0/scope=personal/auth_mode=none`，填 title/url 和所需 open_mode/request_config/icon_file_id/sort。仅允许外部 HTTP/HTTPS，图标须为可读图片 ID。返回 ID 后 detail 核对；已有收藏修改、共享维护、SSO 新增转原界面。
3. `favorite.delete`（C）：最新 id/revision 预览收藏、共享目标、图标关联影响；执行后 list 核对移除，图片保留。权限/版本不符即停。

## 文件与业务附件

1. `file.list`：关键词/分类/状态定位唯一可见文件 ID；普通账号通常只见本人上传文件。
2. `file.info`：核对 id 对应文件名、用途、可读性；无权限停止。
3. `file.relations`：先定位业务对象，再用允许的 entity_type/entity_id/tag 取附件；不混用计划与材料 ID。
4. `file.download`：已验证 ID 才读下载信息，交用户实际返回内容；不把下载信息说成已下载/审阅。

通用上传、附件关系写入、`file.delete` 未开放。新附件/图标请用户先在原界面上传再核实 ID；Excel 仅走对应专用导入上传。

## 文档及分类

1. `doc.categories` → `doc.list` → `doc.detail`：分类/关键词/状态定位唯一文档，读取 article；普通账号只读对角色可见的已发布文档，不可读即停。
2. `doc.history`：有维护权限时传 article_id 读版本，否则停止。
3. `doc.save`（B）：仅新增，显式 `id=0/status=draft`，填标题、完整正文及分类、版本、可见角色、修改说明。返回 id/article 后 detail 核对；已有文档编辑/发布转原界面。
4. `doc.category.save`（C）：categories 选目标/父分类；新增 id=0，修改保留完整 parent_id/name/code/icon/sort/status。预览原值、新值、父级及代码变化对角色可见性的影响；执行后 categories 核对。循环/冲突即停。
5. `doc.delete`（C）：detail 确认 ID，预览归档并软删除；执行后 list 核对，保留历史和原文件。

## 基础档案

1. `archive.list`：学校级管理员按 type、关键词、filter_flag 定位唯一记录；类型为下表前六项。写入将返回主键 dep_id/grade_id/cohort_id/profession_id/class_id 映射为记录 id，勿混关联 ID。
2. `archive.save`（C）：新增取必填字段；修改保留完整可写行。专业的学院/年级、班级的学院/年级/专业须匹配；预览完整原/新值，返回列表后 archive.list 核对。

| type | 完整字段中须明确的内容 |
| --- | --- |
| department | dep_name、dep_short_name、dep_code、sort、flag |
| grade | grade_name、grade_code、字符串 is_current、sort、flag |
| graduation_cohort | cohort_name、2000 至 2999 的 cohort_year、字符串 is_current、sort、flag |
| internship_category | name、code、scope_type=grade/cohort、sort、status |
| profession | profession_name、profession_short_name、profession_code、dep_id、grade_id、sort、flag |
| class | class_name、class_short_name、class_num、dep_id、grade_id、profession_id、sort、flag |
| company | company_name、credit_code、contact_name、contact_mobile、address、unit_type、enterprise_level、flag |

`is_current` 为字符串 true/false；true 会清除同类型其他当前标记，须核对预览。flag=on/off，类别 status=enabled/disabled。company 可保存/删除但无完整档案查询；internship.options 仅有限企业选项。已有企业无法保全字段时转原界面；新增需完整资料，以实际回执为准。

3. `archive.delete`（C）：传已定位的 type/id，预览软删除/停用；执行后重读同类型列表。被计划使用的类别不能删，失败即停，不删关联记录绕过约束。
4. 批量导入仅用下文 `archive.import` 或 `archive.profession.import`，二者规则不同，不互换。

## 实习：定位计划、任务及对象

1. `internship.options`：按代码/学号/工号和组织关系选择唯一档案、企业、师生、基地、计划或任务 ID；类别 scope_type 决定用 grade_id 或 graduation_cohort_id。选项不代替完整资料。
2. `internship.plans`：类别/年级/届次/组织/关键词定位唯一计划，取完整行、plan_content/status、审核节点和任务摘要后才能变更。
3. `internship.arrangements` → `internship.arrangement.detail`：按 plan_id 等筛选，取唯一任务 ID 和完整安排，后续 arrangement_id 用该 ID；`internship.pairs` 核对学生/教师绑定，无绑定停止申请。

### 计划草稿、提交、审核及删除

1. `internship.plan.save`（B）：仅改 draft/modify；显式 status=draft，填 category_id、对应年级/届次、dep_id/profession_id/course_name；完整保留 source_type（edu_system/manual）、course_code/semester/credit/score_rule/plan_content。返回 id 后 internship.plans 核对；范围不符即停。
2. `internship.plan.submit`（C）：internship.plans 确认已有 draft/modify，仅传 id，保留原资料提交；预览 wait、流程/通知影响，执行后回查。无草稿先保存，不能把完整字段塞入此能力。
3. `internship.plan.review`（C）：列表确认 wait、节点/审核记录；用户给 accept/modify 和意见，传 id，approval_level 仅填已核实节点或由服务计算。退回意见 8 至 800 字。预览后执行并回查进度，单节点通过不等于全流程通过。
4. `internship.plan.delete`（C）：用户明确 include_arrangements；预览关联任务、待取消流程和 revision。有任务而未同意一并删除即停；执行后回查计划/任务列表。版本变化重新核实，不扩大范围。

### 实习方式申请

1. `internship.arrangement.detail` + `internship.pairs` + `internship.applications`：核实任务/有效绑定/学生，取唯一申请的 status/teacher_status/admin_status/type/remark。不得改绑学生/任务；无绑定或已 wait/accept 停止编辑。
2. `internship.application.save`（B）：显式 status=draft、arrangement_id，完整 type（centralized/distributed/autonomous）、remark，修改传 id。学生用本人，非学生给已核实 student_id。返回 id/item 后 applications 回查才可提交。
3. `internship.application.submit`（C）：已有 draft/modify 仅传 id，预览重置审核、补建配对教师申请和通知影响；执行后 applications 回查。可代存不代表有提交权限。
4. `internship.application.review`（C）：applications 确认 wait 和教师/管理员节点；用户选 accept/modify/skipped 及意见（退回 5 至 500 字）。执行后回查两个节点及最终 status；skipped 不自动等于通过。

### 基地与企业导师

1. `internship.bases` → `internship.base.detail`：名称/组织/企业/类型定位唯一基地，读 item、完整关系数组及资料后才更新。
2. `internship.base.save`（B）：base_type=long_term/temporary，必须 status=enabled；长期需名称，临时无名称需地址或负责人供服务生成。准备名称/类型/学院/地址及完整 profession_ids/manager/teachers/mentors/existing_sites/company_profile/budgets；返回详情后 base.detail 回查。
   公开参数缺少实际保存会覆盖的 code、company_id、area、capacity、service_courses、construction 等字段。已有基地含需保留数据或全量字段不明，转原界面，禁止不完整更新。立项/停用/UUID 回查未开放，不传 is_project_approved/uuid。
3. `internship.base.delete`（C）：明确单 id 或完整 ids（最多 100），核对数量/关联任务影响后执行，再 bases 回查；只软删除/停用基地，不级联删任务/申报/附件。
4. `internship.options` → `internship.mentor.save`（B）：选唯一 company_id，传完整 name/phone/position/status；修改需真实旧 id。无独立导师列表/详情，不能凭姓名猜 ID；无法核实完整原资料转原界面。保存仅报告实际返回 ID，不声称完成详情回查。

### 师生配对及归档要求

1. `internship.pairs` + `internship.arrangement.detail`：按真实任务/学生/教师关系定位唯一有效配对 ID；新增/调整配对未开放，转原界面。
2. `internship.pair.delete`（C）：传 id/remove_reason，预览师生、任务和完成状态重算影响；执行后 pairs/安排详情回查，不删学生或任务。
3. `internship.archive.requirements`：明确 practice_type=graduation/internship，取 items/materials 的真实类型和完整当前要求后才修改。
4. `internship.archive.requirements.save`（C）：学校级管理员传 practice_type 和完整 items（material_type/required/sort）。遗漏已有项会变非必需，不能只传改动项；预览原/新要求，执行后 requirements 回查。
5. `internship.archive.materials` → `internship.archive.detail`（plan_id）/`internship.archive.history`（材料 id）：取分层档案/版本；实施资料用 `internship.implementation.detail`（arrangement_id）。材料上传/保存及归档写入未开放，查询不等于已归档。

## 实验与实训

写入明确 `module_type=lab`（实验）或 `training`（实训），回查保持同模块；all 仅用于允许的汇总读取。

1. `practice.overview` → `practice.list`：明确 module_type/entity，用 plan_id/project_id/关键词/状态定位唯一目标，取完整行、归属/状态；无独立详情，正文/结构不足转原界面。
2. `practice.plan-teachers`（plan_id）/`practice.project-students`（project_id）核实绑定；`practice.schedule-week` 用已核实年级/学院/专业/week_start 查课表。无通用选项能力，新建所需 ID 无法核实则请用户原界面提供，不套其他模块权限。

### 开课任务、大纲、教案与反思

1. `practice.list`：entity=plan/syllabus/lessonPlan/reflection，取唯一 draft/modify；保留完整 plan_id、组织/班级/教师/课程、title/content/content_json/remark/source_type。结构化字段须为真实对象。
2. `practice.save`（B）：显式 module_type/entity/status=draft 和完整字段，满足标题/范围规则；返回 id/status 后 list 回查。
3. `practice.submit`（C）：完整“保存并提交”，须 module_type/entity/status=wait/title 及全部资料；修改含 id，保留正文/结构，参数齐全可直接新建提交，也可接 B 草稿。预览内容/流程后执行，list 核对 wait；不能只传 id。
4. `practice.review`（C）：list 确认 wait/实体；用户给 accept/modify 和有效 opinion，再传 module_type/entity/id。原服务检查角色/范围/不得自审，执行后 list 回查；拒绝即停。

### 学生日志与报告

1. `practice.list`（entity=project）→ `practice.project-students` → `practice.journals` / `practice.reports`：取本人绑定 project_id、材料 ID、正文/日期/状态；只改 draft/modify，报告须为项目当前报告，不能更新历史版本。
2. `practice.journal.save` / `practice.report.save`（B）：本人显式 module_type/status=draft/project_id、完整 content/title/date/remark，修改传 id；报告保留 template_id/submitted_at/reflection_summary。省略 status 可能触发提交；返回 id/status 后对应列表回查。
3. `practice.journal.submit` / `practice.report.submit`（C）：完整保存并提交，传 module_type/project_id/status=wait 和完整正文/日期等；报告必填 reflection_summary，保留 template_id，submitted_at 服务生成。执行后对应列表核对 wait，不能仅传材料 ID。
4. `practice.journal.review` / `practice.report.review`（C）：对应列表确认 wait，用户给 accept/modify/opinion，再传 module_type/id；执行后回查，不能自审/代审。

### 成绩、课节与归档

1. `practice.list`（entity=score）/`practice.project-students`/`practice.course-scores`（plan_id）读指定成绩；`practice.review`（C，entity=score）可按用户结论审核已有 wait 后回查。成绩录入/比例配置/提交未开放。
2. `practice.period.save`（C）：完整 name/start_time/end_time/sort/status，结束晚于开始；修改需核实旧 ID，预览原值/关联课表。无独立课节列表/详情，不能猜 ID；缺资料转原界面，成功仅报告真实回执。
3. `practice.archive.check`（plan_id）→ `practice.archive.list` → `practice.archive.detail` / `practice.archive.download`（版本 id）：读条件及缺项，选唯一已有版本读详情/下载信息；归档生成未开放，ready 不等于本次已归档。

项目/课表发布、签到写入、项目师生调整、通过后修改及其他未登记写入均转原界面处理。

## 社会实践

1. `social-practice.overview` / `social-practice.statistics`：读用户范围内总览/统计，总数不等于已读全部明细。
2. `social-practice.list`：明确 resource/plan_id/project_id/关键词/状态，定位唯一对象；资源以目录为准。
3. `social-practice.detail` → `social-practice.timeline`：对允许详情的 plan/project/implementation/declaration/material/patch_sign/score 读完整资料、status、节点/流程；计划取 scopes/requirements/score_rules，材料取 attachments。无详情的资源不构造入口。

### 计划草稿、提交、审核与发布

1. `social-practice.detail`（resource=plan）：取 draft/modify 的完整字段/日期/approval_flow_id/数组。新建须核实年级/学院/流程；无社会实践选项/审批流列表，缺 ID 请用户原界面选择，不猜流程。
2. `social-practice.save`（B）：显式 resource=plan，填 title/grade_id/有效 approval_flow_id 及完整字段、scopes/requirements/score_rules。服务强制 draft，不传 status/submit/published_at/uuid；返回 id/status 后 detail 回查。数组全量替换，禁止只传改动项。
3. `social-practice.submit`（C）：resource=plan/id 仅提交已有 draft/modify；先保存或核实内容，预览计划/流程后执行，detail/timeline 回查 wait/节点。缺资料不自动补业务配置。
4. `social-practice.review`（C）：detail/timeline 确认 wait/节点，用户给 accept/modify/意见；原服务校验节点权限/能否退回，执行后回查。单次审核不保证最终 accept。
5. `social-practice.publish`（C）：用户要求发布才做；detail 确认 accept，预览 phase/发布时间/必修名单同步/通知，执行后 detail/timeline 核对 phase=enrolling、published_at 和回执。审核通过不自动发布。

### 材料草稿与提交

1. `social-practice.detail`（plan/project/declaration）+ `social-practice.list/detail`（material）：核实计划、项目或申报、提交范围/要求、material_type；只改 draft/modify，计划/项目或申报/学生/类型/范围不能改绑，两种归属不并填。
2. `file.info` / `file.relations`：核实附件 ID/可读性；缺新附件请用户原界面上传。保留完整 file_ids，省略会清空关系。
3. `social-practice.material.save`（B）：布尔 submit=false、plan_id/material_type/正确归属/submit_scope、完整 title/content/file_ids，修改带 id。返回 id/status/attachments 后 material 详情核对。
4. 二选一：已有内容仅提交用 `social-practice.submit`（C，resource=material/id）；保存修改并提交用 `social-practice.material.submit`（C，布尔 submit=true、明确 submit_scope、完整正文/附件/归属）。预览截止时间/对象/附件后执行，再 detail 回查 wait；不能连续重复提交。
5. `social-practice.review`（C，resource=material）：detail/timeline 确认 wait，按用户结论/意见执行后回查。超期/归属不符/已审核即停；通过后修改转原界面。

### 实施、申报、补签与成绩

1. `social-practice.list/detail/timeline`：resource=implementation/declaration/patch_sign/score，取唯一现有对象/状态；无草稿即停，其新增/保存未开放，先原界面处理。
2. `social-practice.submit`（C）：指定 draft/modify 的 resource/id，原服务校验资料/关系/权限；预览后执行，detail/timeline 回查，不默认提交全部。
3. `social-practice.review`（C）：确认 wait/节点，用户给 accept/modify/意见；申报须教师已 accepted，成绩需独立权限。预览通过申报可能创建分散项目/师生关系、补签生成或关联签到、成绩确认学分的影响；执行后详情/流程回查，不编造结论。

项目维护、报名/教师选择确认、签到保存、安全承诺及归档写入等未登记动作均在原界面处理。列表中有对象不表示已有对应写入能力。

## 五类 Excel 导入

1. 实时目录确认能力/专用上传支持；核对学校、类型、源文件和用户选行。限 xls/xlsx、10 MiB。模板下载未开放，请用户原界面取得；入口无上传或失败即停，不拿本地路径/任意文件 ID 代替返回值。
2. 执行下表对应能力上传；宿主传 multipart 字段 `file` 和附加参数。保留返回 file/arguments/preview，取得服务器编号才下一步。上传不等于导入或批准，前 100 行不能代表全文件。
3. 以 arguments 补全确认参数，保留文件编号/真实行键，检查错误、重复、组织匹配、依赖新增及覆盖。跳错/更新重复/分类/选行由用户决定，不自动丢行或全选。
4. 同能力 C 预览重读文件、绑定摘要/完整参数/影响；用户核对完整明细并批准后执行一次、读原回执。文件/参数/匹配变化即停，不沿用旧批准。
5. 核对实际 total/created/updated/skipped/failed/error_rows/skipped_errors/classified 和逐行 results，再按下表回查。无读取能力只报告回执；如实列错误/跳过/新增依赖，不自动重导或声称整批回滚。

| 能力 ID | 上传附加参数及确认参数 | 原规则、下一步读取 |
| --- | --- | --- |
| `archive.import` | 上传 type=profession/class；确认用返回 type/file_id，无 rows/skip_errors | 最多 5000 行；名称匹配/新增缺失学院、年级、专业/班级，重复跳过。失败行可能已新增依赖；核对逐行/依赖计数，再 archive.list 核对。 |
| `archive.profession.import` | 上传可带 skip_errors；确认用返回 file_id、用户指定布尔 skip_errors（默认 false） | 只匹配既有有效学院，不建学院/更新重复专业；未同意跳错即停。首100行外仍看完整 C 预览，archive.list（type=profession）回查。 |
| `internship.base.import` | 上传无附加参数；确认用返回 import_file_id、用户指定布尔 skip_errors（默认 false） | 最多1000行；创建/复用企业、基地、专业关系、年份申报、预算、接纳统计，同年申报不覆盖。未同意跳错即停；internship.bases、internship.base.detail 回查和核对计数。 |
| `internship.plan.import` | 上传无附加参数；确认用返回 import_file_id/rows（1至5000行），保留 row_key/完整源字段，用户指定 business_type/business_type_confirmed/duplicate_action | 分类 internship/training/lab/social_practice/ignore；仅 internship 写草稿，其余只记 classified，不代表其他模块已导入。duplicate_action=update 才尝试更新可修改重复项，否则跳过。保留用户选行，internship.plans 核对 ID/草稿/内容。 |
| `social-practice.plan.import` | 上传无附加参数；确认用返回 import_file_id/rows（1至500行），保留 row_number/完整源字段/有效 approval_flow_id | arguments.rows 已过滤错误行，须展示 preview.items/summary 的错误/未纳入行让用户决定，不静默当作全量。按 source_key 新增/更新草稿并替换范围/默认材料要求/评分规则，已提交/发布跳过。流程/组织无法核实转原界面；social-practice.list、social-practice.detail（plan）回查。 |

文件编号不得跨能力复用；本能力仍校验用途、上传者、内容及权限，改名/编号不能修复错误。

## 查证位置与缺口处理

查证目录为 `CliCapabilities.php` 末尾合并 `CliActions::definitions()`，含 Content/Internship/Practice 三组 actions；字段/状态按对应 Service 与导入服务实现核实。

未列动作先查实时目录；缺能力须说明具体读取/选项/上传/写入缺口，交用户原界面处理。不组合业务接口、不编造 API/审批结论、不绕范围或扩大批量。
