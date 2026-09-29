# Task 4 接入记录

## 文件

- `server/app/server/wechat/WechatScanSessionService.php`：Redis 扫码会话、学校/账号/用户/设备归属、300 秒 TTL、OAuth state、手机 cookie、CSRF nonce、确认及取消锁、一次性凭证消费。
- `server/app/server/wechat/WechatBindingService.php`：抽取现有 SDK 身份解析供两条 OAuth 流程复用；有效绑定使用统一模型判断。
- `server/app/controller/Api/WechatBindingController.php`：增加七个扫码接口及禁止缓存响应。
- `server/app/middleware/AuthMiddleware.php`：只放行指定手机入口及已登录绑定接口，业务 API 的 `40310` 策略保留。
- `server/app/model/channel/UserWechat.php`：统一有效绑定判断及详情字段；拒绝复用失效旧绑定。
- `server/app/model/channel/Account.php`：管理员列表只将学校当前 CorpId 下的有效绑定显示为已绑定。
- `fontend/shared/useWechatBinding.js`：生成二维码、轮询、账号/学校切换清理、退出/卸载取消、成功后刷新页面获取身份权限。
- `fontend/shared/components/QRCode.vue`：复用 npm `qrcode`。
- `fontend/shared/components/WechatBindingGate.vue`：PC/Tauri 扫码界面。
- `fontend/h5/src/components/WechatScanBinding.vue`：独立手机确认页，不要求手机预先登录。

无需新增数据库表或迁移，状态及凭证保存在学校隔离的 Redis key。

## 路由

加入现有 `/api` 路由分组，使用项目现有 `WechatBindingController` 引用方式：

```php
Route::post('/wechat/scan-session', [WechatBindingController::class, 'scanSession']);
Route::get('/wechat/scan-status', [WechatBindingController::class, 'scanStatus']);
Route::post('/wechat/scan-cancel', [WechatBindingController::class, 'scanCancel']);
Route::get('/wechat/scan/start', [WechatBindingController::class, 'scanStart']);
Route::get('/wechat/scan/callback', [WechatBindingController::class, 'scanCallback']);
Route::get('/wechat/scan/context', [WechatBindingController::class, 'scanContext']);
Route::post('/wechat/scan/confirm', [WechatBindingController::class, 'scanConfirm']);
```

- 创建返回 `{session_id, scan_url, expires_in, state}`；二维码地址只有随机票据，不包含 JWT 或个人信息。
- 状态查询参数为 `session_id`，返回 `pending/scanned/confirming/confirmed/expired/cancelled/failed`。
- 取消 POST JSON 为 `{session_id}`。
- 手机 OAuth callback 跳转 `/h5/?wechat_scan=1`，设置 Secure/HttpOnly/SameSite=Lax 身份 cookie。
- 手机 context 返回 `{account_name,user_name,wechat_userid,nonce,expires_in}`。
- 手机 confirm POST JSON 为 `{nonce,password}`；账号固定在会话中，不能从请求改为其他账号。

## App 接入

PC：

```js
const wechatBinding = useWechatBinding({
  context: () => permissionState.context,
  request,
  backendUrl,
  publicOrigin: () => window.__PRACTICAL_DESKTOP__?.serverOrigin || backendUrl('/'),
});
```

H5 普通绑定流程可传 `publicOrigin: () => backendUrl('/')`。Gate 现有属性及事件兼容，不需要新增事件。

H5：

```js
import WechatScanBinding from './components/WechatScanBinding.vue';
const isWechatScan = new URL(window.location.href).searchParams.get('wechat_scan') === '1';
```

在最外层优先渲染 `<WechatScanBinding v-if="isWechatScan" />`，原应用放入 `v-else`。在所有启动入口（onMounted、URL 登录与 context 加载、业务及消息初始化）跳过 `isWechatScan`。扫码页面保留 URL 标志以便刷新后继续显示该页面；不要给其 Gate 或正常登录页遮罩。

PC/H5 的退出、切换账号入口在切换身份前执行：

```js
await wechatBinding.cancelScan();
```

服务端 `AuthService::switchAccount()`、退出接口在现有身份仍可用时执行：

```php
(new \app\server\wechat\WechatScanSessionService())->cancelCurrent();
```

前端取消失败时不阻断退出；服务端应先取消会话再完成身份变更，取消服务无法执行时避免静默保留可确认的票据。已有设备撤销/黑名单同样会使手机确认失效。

`OperationLogMiddleware::SENSITIVE_KEYS` 增加 `ticket/session_id/state/nonce`。密码键已由原系统脱敏，不记录明文。

绑定详情每条记录新增 `corp_id/invalidated_at/expires_at/valid`，UI 应用 `valid` 展示有效性，不以数组非空判断“已绑定”。列表字段 `wechat_bound` 已直接更新。

## 依赖及构建

PC/H5 的 package.json 均增加 npm `qrcode`，并更新锁文件。两个 vite.config.js 的 `resolve.dedupe` 均加入 `qrcode`，使共享目录的导入使用对应应用依赖。

已完成：

- 六个修改/新增 PHP 文件的 `php -l` 均通过。
- `node --check fontend/shared/useWechatBinding.js` 通过。
- 三个 Vue 组件用现有 `@vue/compiler-sfc` 编译通过。
- 本模块修改的 `git diff --check` 通过。

依赖和 App/路由整合后由主执行者运行 PC/H5 构建。未连接真实学校数据库、Redis 或企业微信执行授权。

## 校验边界

确认同时要求 OAuth 成员 UserId、学校 CorpId、手机浏览器 cookie、CSRF nonce、同源 JSON 请求、未过期会话、有效 PC 设备、目标账号状态和目标账号密码。密码采用现有 `password_verify` 算法，校验后在数据库锁内再次核对密码哈希未变化。每个票据 15 分钟最多 5 次确认尝试，每个学校账号 15 分钟最多 10 次，刷新票据不能重置账号计数。

账号与企微身份关联由目标账号密码持有证明确认，不假定学校的 UserId 等于学号、工号或手机号。企微成员已绑定其他用户时沿用数据库唯一约束拒绝覆盖。

有效绑定要求 CorpId 相符、UserId 非空、raw_data 无 invalidated_at，且 expires_at 为空或仍有效；学校变更 CorpId 或旧绑定失效后须管理员先解除再重新绑定。

扫码 URL 和 OAuth 回调必须部署于学校 HTTPS 域名，且该域名已配置企业微信 OAuth 可信域名。Tauri 只把学校公开 origin 传给共享组件，二维码不会使用本地网关地址。
