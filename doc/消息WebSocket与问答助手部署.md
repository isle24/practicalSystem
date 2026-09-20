# 消息 WebSocket 与问答助手部署

## 升级顺序

1. 先备份学校业务库。在每个学校业务库执行 `server/database/updates/0.3.4-message-assistant.sql`。该文件只新增三个表，不删除历史数据。
2. 在 `server` 目录执行 `php database/migrations/20260920_assistant_personal.php 学校数据库编号`，幂等补充消息、个人助手配置及会话来源。手工升级旧版 0.3.4 可单次执行 `server/database/updates/20260920-assistant-personal.sql`；其中 ALTER 语句不要重复执行。新安装已包含所需字段。
3. 部署 PHP 和前端编译文件后，重启 Webman，使新增进程生效：`php start.php restart -d`。仅 reload 不能保证创建新增进程。
4. 消息 WebSocket 默认监听 `127.0.0.1:8788`，不要向公网开放此端口。HTTP 保持 `8787`。

## NGINX

以下 `location` 放到学校域名现有 HTTPS `server` 块内。保留原来的证书、静态目录和其他配置；已有 `/` 反代不要重复添加。

```nginx
location = /ws/messages {
    proxy_pass http://127.0.0.1:8788;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 90s;
    proxy_send_timeout 90s;
    proxy_buffering off;
}

location = /api/release/upload-chunk {
    client_max_body_size 4m;
    proxy_pass http://127.0.0.1:8787;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 120s;
}

location / {
    proxy_pass http://127.0.0.1:8787;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 120s;
}
```

执行 `nginx -t` 成功后再 `nginx -s reload`。如果前面还有内网穿透或负载均衡，也需支持 WebSocket Upgrade。多个应用节点须共用学校库、Redis 和相同通道配置。

## 消息授权

- 登录后通过 POST `/api/message/realtime-ticket` 获取一次性 60 秒票据。
- 票据通过连接后的授权帧传递，不放入地址栏、URL 或 NGINX 查询日志。
- 按学校库编号、账号分发。连接每 5 分钟重新鉴权，心跳检查设备是否被强制下线。
- 消息与临时通知同事务提交；后台将已提交通知转发到 Redis。推送仅含刷新类型，不含消息正文。
- 重连、回到前台和每分钟补取均复用鉴权 HTTP 接口。网络中断不丢失库中的消息。
- `message_realtime_outbox` 仅为短期投递缓存，投递成功后删除。原有消息、消息接收人、问答记录和审计记录不会因此删除。

## 问答助手

服务端 `.env` 保留密钥加密配置和实时消息监听地址：

```dotenv
TEACHER_SYNC_ENCRYPTION_KEY=替换为服务器保存的高强度随机密钥
MESSAGE_WS_LISTEN=websocket://127.0.0.1:8788
```

已有 `TEACHER_SYNC_ENCRYPTION_KEY` 请保留，不能随意覆盖，否则旧的同步密钥和 AI 密钥无法解密。此项是服务端加密主密钥，不是 AI API Key；示例中文不是可用密钥。助手复用该加密设施，不在客户端保存 API 密钥。原 `ASSISTANT_ALLOWED_HOSTS` 不再读取，可移除，无需手工维护 AI 域名。`MESSAGE_WS_LISTEN` 只控制内部实时消息监听，与 AI 地址无关。

学校管理员或超级管理员进入“消息中心 → 问答助手 → 配置”，填写完整 HTTPS Chat Completions 地址、模型、密钥，再启用。接口暂未提供时保持停用。

每个用户均可在同一入口配置个人服务，按学校、账号隔离。个人密钥加密保存于学校服务器，PC/H5/客户端共用；请求经学校服务器转发。普通用户不能读取或修改学校密钥。留空保留密钥，勾选清除后同时停用。学校服务费用由学校 API 账户承担，个人服务由个人 API 账户承担。

明确选择学校服务或个人服务；切换时新建会话，不自动发送另一服务的历史。任务保存来源和配置指纹，队列消费时按任务所属账号读取配置。配置停用或更改后，旧排队任务失败并提示重新发送，不回退到另一服务。旧版尚未完成且没有指纹的任务同样需要重新发送。历史上下文仅取相同来源、相同配置指纹的轮次。

只发送用户输入及该个人会话最近六轮成功问答。无业务查询工具，无自动审核、修改成绩或 SQL 执行。响应以禁用原始 HTML 的 Markdown 展示，禁止外部图片。每账号每分钟最多十次提问，同一时刻只处理一个问题。请求失败不暴露提供方响应及密钥；可重新填写后发送。

兼容接口须接受 `model`、`messages`、`stream=false`、`max_tokens`，返回 `choices[0].message.content`。必须通过 HTTPS 公网域名的 443 端口访问，目前使用 IPv4。每次实际请求重新校验解析结果并固定连接地址，拒绝内网、保留地址、重定向和代理，单次响应等待上限 45 秒。尚未配置真实接口前，不能认定模型回答已完成端到端联调。

## 安装包与分平台发布

1. 在版本管理中从 GitHub 导入正式版本清单。公开仓库无需 token；私有仓库仍配置只读 `DESKTOP_GITHUB_TOKEN`。清单读取需要访问 GitHub，安装包不必全部由服务器拉取。
2. 管理员可自行下载对应安装包，在“客户端安装包”中点击“本地上传”。浏览器分片上传，服务端按既有清单校验文件大小和 SHA256；修改过的文件不能替换官方包，签名不能跳过。
3. 至少一个平台的所有安装文件校验完成后才允许发布。Linux 等未就绪平台可勾选“暂缓，后台继续下载”，二次确认后发布其他平台。
4. 暂缓平台继续保留上一个已发布且就绪的版本；新平台文件全部校验完成后自动开放，不要求再次发布。后台每五分钟恢复未完成下载，下载失败时不影响已就绪平台。
5. 网页登录页和桌面任务栏提供“下载客户端”，只展示学校已发布且就绪的平台。草稿和升级 SQL 不公开。
6. 分片会话有效期两小时，单片 2 MiB，默认单文件上限 1 GiB。上传期间不能关闭版本弹窗；超过三小时的遗留分片由定时任务物理清理。服务器需预留安装包和临时分片空间。

## 客户端连接页

首次连接输入学校域名，服务端返回学校名称后记入本机连接历史。再次打开显示学校名称及本地备注，点击“切换”选择历史学校与账号，或输入新域名。切换到新学校时清空上一学校的账号密码。记住密码仍使用系统凭据存储，不写入普通配置文件；该界面不改变学校的登录与绑定规则。
