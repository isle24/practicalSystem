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

### Task 7: 个人电子签名

Files：SignatureController/Service/Record/Schema；shared SignatureCanvas.vue、PC SignatureSettings.vue、H5 SignaturePage.vue；导出公共 SignatureRenderer。

接口：GET /signature/current；POST /signature/save（PNG上传）、POST /signature/session、GET /signature/session-status；Service `snapshotForCurrentUser(?int $signatureId): array` 返回 file_id、version、sha256、user_id、signed_at。

- [ ] 短时一次性二维码、手机同一用户验证、透明PNG、空白拒绝、裁切空白。
- [ ] 私有签名版本与文件引用；修改不覆盖历史；审批仅本人签名。
- [ ] 导出读取审批快照，Word/HTML/PDF按比例嵌入；不回填无签名历史。
- [ ] PC/H5接入与PHP/构建验证，明确个人与安全承诺书签字文件的差别。
