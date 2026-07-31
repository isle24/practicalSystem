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

## 8. 校内指导教师同步

### 8.1 开放推送接口

教务系统向当前域名对应的学校业务库推送教师档案：

```http
POST /api/open-teacher-sync/push
Content-Type: application/json
X-App-Id: {app_id}
X-Timestamp: {unix_timestamp}
X-Nonce: {random_string}
X-Signature: {signature}
```

请求体：

```json
{
  "request_id": "sync-20260730-001",
  "teachers": [
    {
      "external_id": "teacher-10001",
      "teacher_num": "T10001",
      "teacher_name": "张三",
      "department_code": "08",
      "profession_code": "080701",
      "gender": "male",
      "birth_date": "1985-06-12",
      "title": "副教授",
      "education": "硕士",
      "phone": "13800000000",
      "email": "teacher@example.com",
      "employment_type": "full_time",
      "status": "enabled",
      "source_updated_at": "2026-07-30 10:00:00"
    }
  ]
}
```

请求体根节点必须为 JSON object。`request_id` 必须是原生 JSON string，`teachers` 必须是 JSON array；不接受以根数组、数字、布尔或对象形式传递教师列表，也不对错误类型做字符串强转。

必填字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| request_id | string | 当前学校业务库内唯一，最长 120 字符 |
| teachers | JSON array | 单次最多 500 条 |
| external_id | string | 教务系统教师唯一 ID |
| teacher_num | string | 教师工号 |
| teacher_name | string | 教师姓名 |
| department_code | string | 学院代码，只匹配已有启用学院 |
| status | string | `enabled` 或 `disabled` |
| source_updated_at | datetime | 来源更新时间，格式 `YYYY-MM-DD HH:mm:ss` |

可选字段：`profession_code`、`gender`、`birth_date`、`title`、`education`、`phone`、`email`、`employment_type`。字段存在时必须使用表中声明的 JSON 类型；字符串字段不接受数字、布尔、数组或对象。专业代码只在所属学院内匹配，不存在时返回该条错误，不自动创建学院或专业。

### 8.2 签名与防重放

签名原文必须严格按以下顺序拼接，换行符为 `\n`，末尾不增加换行：

```text
app_id + "\n" + timestamp + "\n" + nonce + "\n" + sha256(raw_body)
```

签名值为：

```text
lowercase_hex(HMAC-SHA256(app_secret, signing_text))
```

服务端使用原始请求体计算 SHA-256，不得重新序列化 JSON。`X-Timestamp` 与服务端时间误差不得超过 300 秒。验签成功后，Redis 使用学校、`app_id` 和 `nonce` 生成防重放键，以 `NX EX 300` 写入；相同 nonce 再次调用返回 409。

开放接口不校验 JWT，但仍经过 `SchoolMiddleware` 完成学校识别和业务库切换，并校验开放应用状态、可选 IP 白名单和 HMAC 签名。只有 `status=enabled` 的开放应用可以推送；停用应用仍可由受保护维护接口查询、启用或轮换密钥。`open_sync_app.app_secret` 使用 `TEACHER_SYNC_ENCRYPTION_KEY` 派生的 AES-256-GCM 密钥加密保存，密文前缀为 `enc:v1:`。

### 8.3 幂等与增量规则

- `request_id` 在每个学校业务库的 `teacher_sync_batch` 中唯一。
- 已完成的 `request_id` 再次提交时直接返回原结果，并增加 `idempotent: true`。
- 正在处理的相同 `request_id` 返回 409，不重复写入。
- 教师档案优先按 `external_id` 匹配，其次按 `teacher_num` 匹配。
- 两个编号命中不同教师档案时，该条失败，不自动合并。
- `teacher_list.external_id` 与 `teacher_list.teacher_num` 分别具有唯一索引；升级库创建索引前如检测到重复值，初始化程序明确报错并要求先清理，不自动合并档案。两个字段允许多条 `NULL`。
- 只有明确传入 `status=disabled` 才停用教师；未出现在批次中的教师不受影响。
- 校内指导教师选择后保存 `base_person.teacher_id`，同时保存姓名、性别、职称、学历和电话快照。

成功响应：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "request_id": "sync-20260730-001",
    "received": 100,
    "created": 20,
    "updated": 78,
    "disabled": 2,
    "failed": 0,
    "errors": []
  }
}
```

单条数据错误不会中止整个批次，`errors` 返回数组下标、教师编号、字段和错误信息：

```json
{
  "index": 3,
  "teacher_num": "T10004",
  "field": "profession_code",
  "message": "专业代码不存在、未启用或不属于所选学院"
}
```

接口级错误：

| HTTP | code | 场景 |
|---|---|---|
| 400 | 40001 | JSON、字段、数量或格式无效 |
| 401 | 40100 | 应用无效、时间戳过期、IP 不允许或签名错误 |
| 405 | 40500 | HTTP 请求方法不允许 |
| 409 | 40900 | nonce 重放或相同批次正在处理 |
| 500 | 50000 | 加密密钥缺失、Redis 或数据库异常；开放接口固定返回“教师同步服务异常”，原始异常仅写入服务端日志 |

### 8.4 主动拉取

学校管理员或超级管理员调用：

```http
POST /api/teacher-sync/pull
```

系统读取以下学校配置：

- `teacher_sync.pull_url`
- `teacher_sync.pull_app_id`
- `teacher_sync.pull_app_secret`
- `teacher_sync.last_synced_at`

未配置拉取地址、应用编号或密钥时返回明确的 400 错误。`pull_url`、`pull_app_id`、`pull_app_secret` 在学校业务库事务内原子保存，任一项写入失败时全部回滚；`pull_app_secret` 加密保存，配置查询只返回 `******`。系统请求地址为 `{pull_url}/open-api/teachers`；若配置值已经以 `/open-api/teachers` 结尾则不重复拼接。

拉取地址允许内网或公网的 HTTP/HTTPS 地址，但禁止 URL userinfo 和 fragment。HTTP 客户端禁止重定向，配置地址返回的 3xx 响应不会跳转到其他目标。

```http
GET {pull_url}/open-api/teachers?updated_after={last_synced_at}&cursor={cursor}&limit=500
X-App-Id: {pull_app_id}
X-Timestamp: {unix_timestamp}
X-Nonce: {random_string}
X-Signature: {signature_for_empty_body}
```

教务系统返回：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [],
    "next_cursor": "",
    "has_more": false
  }
}
```

服务端每次主动拉取都从空游标开始，不接受客户端指定起始 `cursor`，再持续拉取至 `has_more=false`。上游必须返回数组类型的 `data.items`、JSON bool 类型的 `data.has_more` 和字符串类型的 `data.next_cursor`；缺失、类型错误、重复或循环游标均视为接口响应错误。系统记录本次拉取全部已见游标，包含起始空游标，可立即识别 `A -> B -> A` 等非相邻循环。

同一学校主动拉取使用 1800 秒严格互斥锁，Redis 服务异常与锁占用分别返回服务异常和 409，避免多个窗口并发同步和推进水位。单页最多 500 条、HTTP 超时 30 秒，最多拉取 40 页即 20000 名教师；最坏网络等待为 1200 秒，为数据库处理和锁释放保留余量。PC 请求超时为 1500000 毫秒（25 分钟）。每页复用开放推送的教师校验和写入方法，只有全部响应及数据处理成功时才推进 `last_synced_at`；存在失败数据或非法响应时保留原增量时间并返回错误或 `warning`。零数据时水位最多推进到本次拉取开始时间，避免把请求结束期间发生的更新遗漏。本系统响应增加 `pages`、`next_cursor` 和 `last_synced_at`，其余统计字段与推送接口一致。

主动拉取成功响应：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "request_id": "pull-20260730103000",
    "received": 42,
    "created": 5,
    "updated": 36,
    "disabled": 1,
    "failed": 0,
    "errors": [],
    "pages": 2,
    "next_cursor": "",
    "last_synced_at": "2026-07-30 10:29:50"
  }
}
```

### 8.5 教师查询和配置维护

```http
GET  /api/teacher-sync/teachers?keyword=&dep_id=&profession_id=&page=1&page_size=20
GET  /api/teacher-sync/config
POST /api/teacher-sync/save-config
POST /api/teacher-sync/save-application
```

`teachers` 允许学校、学院和专业管理员调用。学校级管理员查看全校启用教师；学院管理员按 `sys_organization.dep_id` 限制；专业管理员按 `sys_organization.profession_id` 限制。请求中的筛选条件不能扩大当前账号的数据范围。

`config`、`save-config`、`save-application` 仅允许学校管理员和超级管理员调用。`save-config` 的 `pull_url`、`pull_app_id`、`pull_app_secret` 只接受 JSON string 或 `null`，不对数组、对象、数字或布尔做强制转换。通用 `/api/config/save` 禁止保存 `group=teacher_sync`，教师同步配置必须通过专用事务接口 `/api/teacher-sync/save-config` 维护，`last_synced_at` 仅由同步流程推进。

`save-application` 请求字段为 `app_id`、`app_secret`、`status` 和可选 `allowed_ips` 数组；`status` 仅支持 `enabled` 或 `disabled`，`allowed_ips` 必须为 JSON array 且每个元素必须是 string。状态缺省时保留已有状态，新建应用默认启用。重复保存相同 `app_id` 时可轮换密钥，空密钥或 `******` 表示保留现有密钥。停用应用仍可通过该接口重新启用或轮换，但开放推送会拒绝停用应用。响应返回应用状态、IP 白名单、密钥是否已配置、密钥更新时间和最后使用时间，密钥仅返回 `******`，不得返回明文或密文。

开放应用维护请求：

```json
{
  "app_id": "educational-system",
  "app_secret": "new-secret",
  "status": "disabled",
  "allowed_ips": ["192.168.3.20"]
}
```

开放应用维护成功响应：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "app_id": "educational-system",
    "status": "disabled",
    "app_secret": "******",
    "secret_configured": true,
    "allowed_ips": ["192.168.3.20"],
    "last_used_at": null,
    "secret_updated_at": "2026-07-30 10:30:00",
    "updated_at": "2026-07-30 10:30:00"
  }
}
```

上述接口严格使用表中 HTTP 方法。`push`、`pull`、`save-config`、`save-application` 仅接受 POST，`teachers`、`config` 仅接受 GET；方法不符返回 HTTP 405、业务码 `40500`，并返回对应 `Allow` 响应头。

### 8.6 数据库初始化升级范围

`server/database/init.php` 始终升级模板库，并在默认学校库由 `seedMaster` 登记后，读取主库 `databases` 表中全部 `status=enabled` 的学校业务库逐一升级。每个登记库使用自身的 `database_host`、`database_port`、`database_user`、`database_pwd`、`database_db` 和 `database_charset`，按 `host + port + database` 去重。任一登记库失败时终止初始化并明确返回对应 `database_id` 和 `database_db`，输出内容不得包含数据库密码。
