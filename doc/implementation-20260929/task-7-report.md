# Task 7 接线记录

## 文件

- `server/app/controller/Api/SignatureController.php`
- `server/app/server/signature/SignatureService.php`
- `server/app/model/channel/SignatureRecord.php`
- `server/app/model/channel/SignatureSchema.php`
- `server/app/server/export/SignatureRenderer.php`
- `server/database/migrations/20260929_signature.php`
- `fontend/shared/components/SignatureCanvas.vue`
- `fontend/pc/src/components/profile/SignatureSettings.vue`
- `fontend/h5/src/pages/SignaturePage.vue`

附带文件存储隔离及历史保护：`FileService.php`、`FileBlob.php`、`FileStorageSchema.php`、`20260929_file_storage.php`。

## 路由

均放入学校认证 API 组，不添加公开认证例外。前缀 `/api`，控制器 `app\controller\Api\SignatureController`。

| 方法 | 路径 | 方法 | 参数及返回 |
| --- | --- | --- | --- |
| GET | `/signature/current` | current | `{signature:{id,file_id,version,url,width,height,created_at}\|null}` |
| POST | `/signature/save` | save | multipart `file` PNG、可选 `session_id`，请求头 `X-Signature-Intent: personal`；返回 `{signature:...,state:'confirmed'}` |
| POST | `/signature/session` | session | 无参数；返回 `{session_id,scan_url,expires_in:300,state:'pending'}` |
| GET | `/signature/session-status` | sessionStatus | `session_id`；PC 校验发起账号和设备；手机传 `mobile=1` 校验同一系统用户；返回 `{state,expires_in,signature_id}` |
| POST | `/signature/session-cancel` | cancelSession | `session_id`；仅发起账号和设备可取消；返回 `{state}` |

响应沿用 `{code:0,message:'ok',data:...}`。错误码/HTTP状态为401/403/409/410/422/429/503，未预期异常500写日志。410表示过期；409表示已消费、取消或文件不可用。签名上传每学校用户每分钟最多20次。

## PC/H5 接入

PC个人设置挂载 `SignatureSettings.vue`，按当前 `account_id` 设置 key；组件自行请求当前签名、生成二维码、轮询、取消及卸载时取消。签名预览 URL 经现有 `backendUrl` 转换，依赖受保护文件访问。

H5在 `isLoggedIn` 成立后优先显示 `SignaturePage.vue`；绑定阻断仍沿用现有外层逻辑。读取当前 URL 查询 `signature_session`，通过 `:session-id` 传入。二维码落地 `/h5/?signature_session=<64位随机令牌>`。登录页面不得清除该参数，登录成功后仍进入签名页。按 `account_id + ':' + sessionId` 设置组件 key，避免切换账号保留旧画布状态。

“我的”增加直接签名入口，传空 `session-id`。`done` 事件关闭签名页，清除查询参数并返回“我的”。二维码签名页错误时不显示可保存画布，不回退为直接保存。

画布支持鼠标、触摸及笔的 pointer 事件，1200×480透明画布，禁用滚动手势，清空重写、本人签名确认、保存。服务端重新解码PNG、去白色和透明像素、拒绝空白/不足笔迹、8px边距裁切，最终存透明PNG。限制上传2MB、最长边2048、最多2097152像素；部署须启用PHP GD PNG支持。

个人签名说明明确其用途与安全承诺书签字文件不同。签名设置不调用承诺书接口。

## 会话和版本

- `signature_session` 保存令牌SHA256，绑定学校库编号、PC用户、PC账号、PC device_jti、5分钟截止时间。
- 手机沿用系统普通登录；二维码持有者不能获得登录身份。保存时重新校验同一user_id、学校、PC账号与用户对应关系、启用设备及JWT黑名单。
- 相同PC设备创建新会话会取消原pending会话；取消、过期、confirmed均不能再次保存。
- 用户行锁串行化签名版本及会话消费，签名行、file_relation及会话confirmed在同一学校数据库事务写入。每个用户version唯一，每次保存新增一行且不修改旧行。
- 在 `AuthController::logout` 原有Wechat取消会话的try内增加 `(new \app\server\signature\SignatureService())->cancelCurrent()`，因为当前退出主要清cookie，不能仅依赖user_device判断退出。
- 每次审核调用 `snapshotForApproval(int $signatureId)`；`snapshotForCurrentUser(?int $signatureId = null)` 可读指定本人版本或本人最新版本。返回 `signature_id,school_id,file_id,version,sha256,user_id,signed_at`，snapshot会验证文件物理摘要。不能读取其他用户签名。
- `workflow.signature_snapshot` 配置为 `\app\server\signature\SignatureService::class`。审批界面须让用户确认采用本人签名，把当前版本id作为signature_id传给workflow/review。

## 文件访问接线

主执行者在 `FileAccessRecord::readable(object $file)` 的上传人/管理员快速放行之前增加：

```php
if ((string) $file->category === 'personal_signature') {
    return SignatureRecord::fileReadable((int) $file->id);
}
```

`SignatureRecord` 与 `FileAccessRecord` 同namespace，无需跨namespace导入。不要将该分类放入publicImage；学校管理员不能凭文件管理权限读取个人签名。`entityReadable` 若支持personal_signature，必须同样只校验当前user_id，并在管理员快速放行前处理；也可保持不开放通用relation读取，由专用current接口展示。

本任务已在FileService拒绝普通upload/check创建personal_signature、普通attach/replaceRelations改个人签名引用、普通业务字段引用签名、删除/强制删除/生成文件回收删除签名。签名只通过专用服务新增，不向通用附件接口开放复用。FileRelation使用entity_type=personal_signature、entity_id=签名版本ID、tag=signature，非临时文件保留历史引用。

## 存储去重隔离

`file_blob.storage_scope VARCHAR(40) NOT NULL DEFAULT 'general'`，唯一键由`uk_md5(md5)`改为`uk_md5_scope(md5,storage_scope)`。保存及秒传按category选scope：wallpaper、personal_signature单独scope，其余general。MD5仍为文件真实摘要；壁纸与头像、签名与普通上传不再复用同一blob或URL。各scope内部仍去重。

新增 `FileStorageSchema::applyToPdo(PDO $pdo)` 用于已有PDO初始化；独立 `apply()` 用于学校连接迁移。检测已有列和索引，一次ALTER完成缺失项，重复执行不删除数据。原blob行默认general，原URL不改、不迁移重分类。

init应在file_blob创建DDL中添加storage_scope并使用新唯一键，去掉后续旧uk_md5补建逻辑，在ensureFileSchema末尾调用applyToPdo。基线同步列和复合唯一键。

历史壁纸代理可调用 `FileService::copyExistingImage(int $fileId, 'wallpaper', int $ownerAccountId): array`，仅接受profile/wallpaper图片源，保留源文件，副本进入wallpaper scope；返回file_id/url等现有存储字段。调用方先授权迁移对象，本方法不提供HTTP路由。

## 导出契约

`app\server\export\SignatureRenderer` 由已经完成业务对象或导出任务授权的服务器代码调用，不提供任意快照HTTP渲染端点。

- `image(?array $snapshot, int $maxWidth = 150, int $maxHeight = 70): ?array` 返回本地path、按比例width/height、signed_at；表格及其他模板可复用。
- `html(...)` 返回内嵌PNG data URI及审核日期，可供HTML和mPDF使用。
- `appendToWord(object $container, ?array $snapshot, ...)` 在PHPWord段落/单元格按比例嵌图并显示日期。
- `appendToSheet(Worksheet $sheet, string $cell, ?array $snapshot, ...)` 指定签名单元格显示日期、下方图片并增加行高。

读取同一学校中的signature_id/file_id/version/user_id及SHA256，核对物理PNG摘要和尺寸；文件缺失或摘要不同明确失败。空快照返回空值，不补当前个人签名。调用方从当次不可变审批history.signature_json读取；原姓名、意见、日期仍由原模板保留。

旧审批没有签名快照时不添加图像。本任务未修改业务导出模板；主执行者将renderer接入基地申报、经费申请、实施表及已经提供审批快照的明确签名位置。不得将当前签名放入旧history。

## 迁移和验证

部署先运行 `php database/migrations/20260929_file_storage.php 学校数据库编号`，再运行 `php database/migrations/20260929_signature.php 学校数据库编号`，两个入口支持多个编号。新校初始化增加SignatureSchema::creationStatements()；FileStorageSchema在file_blob建好后执行。更新应用代码前完成存储scope迁移，避免新代码读取尚不存在的列。

已执行本机PHP8.4语法检查：SignatureController、SignatureService、SignatureRenderer、SignatureRecord、SignatureSchema、FileStorageSchema、FileBlob、FileService及两个迁移文件通过。三个Vue组件通过@vue/compiler-sfc脚本、模板、样式编译。最终App整合后的PC/H5完整构建由主执行者运行。

未运行真实数据库迁移、真实用户扫码、签名保存或审批。当前本地无可用学校数据库连接；事务与权限链运行状态需部署环境再核验。未提交、未推送、未修改Git索引。
