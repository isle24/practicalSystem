# API 设计规范

## 1. 路由命名规范

### 1.1 基本格式

```
{HTTP方法} /api/{module}/{resource}[/{identifier}][/{sub-resource}]
```

| 元素 | 规范 | 示例 |
|---|---|---|
| module | 业务模块名，小写下划线 | internship, training, lab, user, file, config |
| resource | 资源名，小写下划线，复数 | students, applications, journals |
| identifier | UUID（对外），非自增 ID | /api/internship/students/{uuid} |
| sub-resource | 子资源，复数 | /api/internship/students/{uuid}/journals |

### 1.2 路由分组

```
/api/auth/*           认证相关（登录、登出、OAuth 回调、2FA）
/api/user/*           用户与账号管理（个人信息、角色切换、账号切换）
/api/file/*           文件管理（上传、秒传校验、下载）
/api/config/*         配置管理
/api/internship/*     实习管理
/api/training/*       实训管理
/api/lab/*            实验管理
/api/message/*        消息中心
/api/log/*            日志审计
/api/stat/*           统计报表
/api/export/*         导出任务
/api/edu/*            教务系统对接
/api/admin/*          系统管理（学校、菜单、角色、权限）
```

### 1.3 标准动作

| HTTP 方法 | 路径 | 说明 |
|---|---|---|
| GET | /api/{module}/{resources} | 列表查询（含分页、筛选、排序） |
| GET | /api/{module}/{resources}/{uuid} | 单条详情 |
| POST | /api/{module}/{resources} | 新增 |
| PUT | /api/{module}/{resources}/{uuid} | 完整更新 |
| PATCH | /api/{module}/{resources}/{uuid} | 部分更新 |
| DELETE | /api/{module}/{resources}/{uuid} | 软删除 |
| POST | /api/{module}/{resources}/{uuid}/{action} | 动作（approve, reject, submit 等） |

### 1.4 路由注册示例

```php
// 在 app/ 下按模块建目录，routes 统一注册

// app/internship/routes.php
Route::group('/api/internship', function () {
    Route::get('/students/{uuid}/journals', [JournalController::class, 'index']);
    Route::post('/applications/{uuid}/approve', [ApplicationController::class, 'approve']);
})->middleware([AuthMiddleware::class, PermissionMiddleware::class]);
```

## 2. 请求规范

### 2.1 请求头

```
Content-Type: application/json
Accept: application/json
Cookie: jwt={token}                # JWT 通过 httpOnly Secure SameSite Cookie 自动携带
Authorization: Bearer psk_xxx      # 仅 API Key 调用使用，浏览器 JWT 登录不使用
X-Request-Token: {uuid}            # 业务类 POST/PUT/DELETE 必须携带（幂等）
X-CSRF-Token: {csrf_token}         # 浏览器 Cookie JWT 的业务写接口必须携带
```

JWT 不通过 `Authorization` 头传输，避免前端 JavaScript 接触 token，防止 XSS 窃取。Cookie 属性：`httpOnly=true, Secure=true, SameSite=Lax, Path=/api`。API Key 调用使用 `Authorization: Bearer psk_xxx`，由 AuthMiddleware 按前缀识别。

Cookie 必须使用 host-only，不设置父域 `Domain=.example.com`，避免不同学校子域名共享登录态。CORS 只允许当前学校域名来源，不允许 `*`，并限制允许的请求头为业务所需头。浏览器 Cookie JWT 的 POST/PUT/PATCH/DELETE 请求需同时校验 `X-CSRF-Token`；API Key 调用不使用 Cookie，不做 CSRF 校验。

### 2.2 分页参数

```json
{
  "page": 1,
  "per_page": 20,
  "sort": "-created_at",
  "filters": {
    "status": "wait",
    "date_from": "2026-05-01",
    "date_to": "2026-05-31"
  }
}
```

- `per_page` 最大 100
- `sort` 格式：`{+|-}{field}`，默认 `-created_at`

### 2.3 幂等请求

业务类 POST/PUT/PATCH/DELETE 请求必须携带 `X-Request-Token` 头，值为前端生成的 UUID。服务端 Redis 记录 `idempotent:{database_id}:{token}`，TTL 60 秒。重复 token 返回 409。认证回调、验证码、文件上传分片等特殊接口可在路由配置中豁免。

### 2.4 CSRF token

登录成功后服务端生成 CSRF token，Redis 存储 `csrf:{database_id}:{jti}`，TTL 与 JWT 一致。前端通过同源接口获取 token 后写入请求头 `X-CSRF-Token`。切换账号、切换角色、远程下线、模拟登录重新签发 JWT 时，必须同步刷新 CSRF token。

## 3. 响应规范

### 3.1 成功响应

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [...],
    "total": 200,
    "page": 1,
    "per_page": 20
  }
}
```

单条数据：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "uuid": "a3f2b8c1-...",
    "name": "张三",
    ...
  }
}
```

### 3.2 错误响应

```json
{
  "code": 40101,
  "message": "未登录或登录已过期",
  "data": null
}
```

### 3.3 HTTP 状态码

| 状态码 | 说明 |
|---|---|
| 200 | 成功 |
| 201 | 创建成功 |
| 400 | 参数校验失败 |
| 401 | 未认证（JWT 无效或过期） |
| 403 | 无权限 |
| 404 | 资源不存在 |
| 409 | 幂等冲突（重复提交） |
| 422 | 业务校验失败 |
| 429 | 请求过于频繁（限流） |
| 500 | 服务端错误 |

## 4. 错误码体系

格式：`{http_status_suffix}{2位模块}{2位错误序号}`

### 4.1 通用错误（00）

| code | message |
|---|---|
| 40001 | 参数校验失败 |
| 40100 | 未登录 |
| 40101 | 登录已过期 |
| 40102 | 账号或密码错误 |
| 40103 | 需要双因子验证码 |
| 40104 | 双因子验证码错误 |
| 40300 | 无操作权限 |
| 40301 | 无数据访问权限 |
| 40400 | 资源不存在 |
| 40900 | 请勿重复提交 |
| 42200 | 业务校验失败 |
| 42900 | 请求过于频繁，请稍后重试 |
| 50000 | 服务器内部错误 |

### 4.2 模块错误示例

| code | message | 模块 |
|---|---|---|
| 42201 | 该实习安排不可重复申请 | 实习 |
| 42202 | 超出教师指导学生数上限 | 实习 |
| 42203 | 该时段已被预约 | 实训/实验 |
| 42204 | 超出截止时间，请申请延期 | 实习 |
| 42205 | GPS 不在签到范围内 | 实习 |
| 42206 | 二维码已过期 | 实习/实训/实验 |
| 42207 | 信息不匹配，请联系管理员 | 用户 |
| 42208 | 该角色菜单数已达上限 | 企微 |

## 5. UUID 使用规范

- 所有对外 API 的路径参数和响应中的实体标识均使用 UUID
- 请求进入后由 UUID 中间件转换为内部 ID
- 内部逻辑全程使用自增 ID
- 响应返回前转换为 UUID
- UUID 转换缓存策略见 `00-总体架构设计.md` 第 6 节

## 6. 权限校验流程

```
请求 → SchoolMiddleware（库切换）
     → UuidMiddleware（UUID→ID 转换）
     → webman/limiter（通用限流，config/plugin/webman/limiter/app.php 配置）
     → IdempotentMiddleware（幂等校验）
     → AuthMiddleware（从 httpOnly Cookie 提取 JWT，或从 Authorization 识别 API Key → 写入 Context）
     → CsrfMiddleware（Cookie JWT 写接口校验 X-CSRF-Token，API Key 调用跳过）
     → PermissionMiddleware（接口权限码匹配）
     → DataScopeMiddleware（数据过滤条件注入）
     → Controller → Service → Model
```

## 7. 接口限流

登录限流见 `02-用户服务设计.md` 第 7 节。除登录外，以下接口类别实施独立限流：

| 接口类别 | 限流维度 | 规则 |
|---|---|---|
| 文件上传 | IP + user_id | 60 次/分钟 |
| 导出任务创建 | user_id | 10 次/分钟 |
| 验证码发送 | IP | 5 次/5分钟 |
| 通用 API | IP + user_id | 300 次/分钟 |
| 配置写入 | user_id | 30 次/分钟 |

限流中间件使用 `webman/limiter`（已安装），超限返回 429 + `Retry-After` 头：

```php
// config/plugin/webman/limiter/app.php
return [
    'default' => [
        'prefix' => 'rate_limit',
        'max_attempts' => 300,
        'decay_seconds' => 60,
    ],
    'upload' => [
        'prefix' => 'rate_limit:upload',
        'max_attempts' => 20,
        'decay_seconds' => 60,
    ],
    'export' => [
        'prefix' => 'rate_limit:export',
        'max_attempts' => 10,
        'decay_seconds' => 60,
    ],
];
```

路由中通过中间件参数指定限流规则：

```php
Route::post('/api/file/upload', [...])->middleware(['limiter:upload']);
Route::post('/api/export/create', [...])->middleware(['limiter:export']);
// 无需指定则使用 default 规则
```

登录限流仍使用自定义 Lua 脚本方案（见 `02-用户服务设计.md` 第 7 节），因为需要多维度限流（IP+账号+账号全局），webman/limiter 不支持复合维度。
