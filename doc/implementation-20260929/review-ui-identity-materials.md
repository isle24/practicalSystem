# Task 1、2、4、5、7 独立审查

审查依据：批准设计第 3、4、5、8 节、`review-ui-identity-materials.diff`、Task 4/5/7 交接记录及当前工作区调用上下文。仅读取代码，未执行接口、数据库写入或整包构建。App.vue、路由、初始化及模板接线仍由主执行者整合。

## P1：重复上传同内容素材会删除正在使用的物理文件

- 位置：`server/app/server/file/FileService.php:700`、`:761`，路径生成位置 `:802`、`:809`；新调用入口 `WallpaperService::upload()`、`SchoolAppearanceService::upload()` / `uploadWallpaper()`。
- 触发：同一学校、同一天、同分类及扩展名，再次上传相同壁纸、Logo 或登录背景；也包括另一用户上传同内容壁纸。新入口关闭了前端摘要要求并直接进入 upload，因此此路径可直接触发。
- 证据：`uploadToLocalStorage()` 以内容 SHA1 生成固定目标路径，先 `move()` 覆盖原物理文件；`persistUploadedFile()` 在相同 storage_scope 命中已有有效 blob 后设 `$removeSavedFile = true`，事务完成后无条件删除 `$savedPath`。此路径就是已有 blob 的 path，导致新旧 file 行引用同一个已删除文件。上传仍返回成功，桌面、登录图及共享该 blob 的其他引用随即失效。
- 影响：文件丢失及全部复用引用失效。底层固定路径行为来自原文件服务，但新素材入口复用后仍使本次“重复上传/反复选择”和学校素材功能不可可靠使用。
- 修复：每次上传先使用独立随机物理文件名，摘要仅用于 blob 去重，命中旧 blob 后只删除本次新文件。不能仅修成功清理分支，因为摘要不符等异常处理也会删除相同 `$savedPath`；应从上传落盘阶段避免覆盖任何现存文件。

## Spec compliance

- **未通过**：P1 阻断壁纸及学校素材可靠保存。
- 学校外观写入口检查超级/学校管理员角色和 `config:manage`；公开设置仅返回展示字段。Logo 上传类型/实际图像/体积受既有文件服务校验，当前登录和设置预览使用 contain。
- 全屏注册使用实例 Symbol + reactive Set；公共弹窗按可见性注册，卸载注销。当前 DesktopWindow 由 `visibleWindows` 渲染，最小化卸载，因此可清除注册；App 已接隐藏 Dock 及单行布局。多实例不会因一个关闭而全部解除最大化。
- 企微会话使用学校隔离 Redis key、300 秒截止时间、PC 账号/用户/设备归属、一次性 ticket/state/identity、浏览器绑定及 nonce；手机确认额外验证目标账号密码和当前 CorpId，不能从请求改变目标账号。确认和取消共享会话锁，切换账号和退出服务端先取消，取消失败时退出返回失败。
- 壁纸访问在管理员快捷放行前按本人/共享/学校默认引用鉴权，匿名不能读取；分享撤回后的 selection 回落学校默认。新壁纸及签名 scope 与 general 分离，头像同内容不再被壁纸权限分支连带阻断。
- 签名保存重新解码 PNG，限制尺寸/像素/体积、排除白色透明像素及不足笔迹，裁切后保存透明图。用户行锁串行版本及会话消费；旧行不覆盖，专用分类拒绝通用上传/引用/删除。快照包含学校、版本、摘要、用户、签署时间，渲染核对物理摘要，空历史快照不回填当前签名。
- 已核对审查期间主执行者补入的 `FileAccessRecord::readable()` 本人签名分支及 `entityReadable()` 拒绝通用签名关系读取，均位于管理员快捷放行之前；先前接线缺口已关闭，不作为遗留问题。

## Code quality

- **需修改 P1 后再评估合入**。未发现其他有充分代码依据的阻断问题。
- 复用了框架 Controller/Service/Model、学校连接、文件引用与现有企业微信 SDK；签名 renderer 没有开放任意快照 HTTP 读取入口。
- 对 `FileService` 的 storage_scope 查询、持久化唯一键及删除保护已读取实际底层调用，并非仅按接口报告判断。

## Cannot verify

- App.vue、路由、初始化、审批表单及所有导出模板最终接线尚未终态；本审查不对全部入口可达及全部签名位置完成作结论。当前 workflow.signature_snapshot 配置和 WorkflowService 对 snapshotForApproval 的调用已存在。
- 当前读取不能替代学校数据库迁移、真实企业微信 OAuth、真实身份扫码以及 PC/H5 实際画布与窗口操作。实现者已有语法/SFC/构建记录，此轮未重复整包构建。

## P1 修复核对

- `FileService::uploadToLocalStorage()` 改为每次上传生成 UUID 物理文件名；MD5 与 SHA1 仍从落盘文件计算，`persistUploadedFile()` 仍按 `storage_scope` 和 MD5 去重。旧 blob 与本次文件的路径分离，命中去重或完整性校验失败时只清理本次文件。
- `storeGeneratedFile()` 原已使用 UUID 作为目标文件名，成功去重及异常处理清理的也是本次副本；未发现同类固定路径覆盖风险。
- 验证：`php -l server/app/server/file/FileService.php` 通过；已检查差异和成功、异常清理分支及磁盘路径构成。未执行上传或数据库操作。
