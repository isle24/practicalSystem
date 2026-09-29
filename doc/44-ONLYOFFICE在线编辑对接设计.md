# ONLYOFFICE 在线编辑对接设计

## 1. 目标和范围

本方案面向学校内部使用，首期支持：

- PC 浏览器在线编辑 DOCX、XLSX。
- 同一份文件同一时间只允许一个账号编辑。
- 保存后生成新的文件版本，保留历史版本。
- 企业微信和手机浏览器继续使用本系统的预览、下载和上传入口，不嵌入 ONLYOFFICE 移动网页编辑器。
- Webman 继续负责登录、学校库切换、业务权限、文件权限、版本记录和编辑锁。
- ONLYOFFICE Docs 只负责浏览器中的 Office 编辑器和文档转换。

以下内容是部署和开发设计，不包含本次代码实现、数据库迁移或服务器变更。

## 2. 方案结论

推荐先使用 ONLYOFFICE Docs Community Edition 做 PC 端单人编辑验证。

选择理由：

1. DOCX、XLSX 的完整编辑器和保存回调已经具备，当前系统不需要自行实现 Word 编辑器或表格编辑器。
2. 首期不启用多人实时协同，不需要部署 Collaboration Server，也不需要把协同数据模型接入学校业务库。
3. 手机端不加载 ONLYOFFICE 编辑器，可以继续复用 FilePreview.vue、FileService 和 H5 上传流程。
4. 现有系统已经使用 Nginx、Webman 8787 端口和 public/files 文件服务，增加独立文档服务即可。

Community Edition 使用 AGPLv3。学校自建服务器可以先做技术验证，但正式上线前必须根据系统是否闭源、是否向用户通过网络提供服务、是否计划向多所学校部署等情况进行许可证审查。若不能接受 AGPLv3 的源码和网络服务义务，应改为向 ONLYOFFICE 采购 Developer 或 Enterprise 授权。技术上采用“单人编辑”不会自动改变授权要求。

## 3. 总体架构

~~~text
浏览器 PC
  │
  ├─ https://sxxt.cdjcc.edu.cn       Webman、PC 前端、H5、文件权限
  │       ├─ /api/office-editor/*    编辑会话、文件源、保存回调
  │       └─ /files/*                现有受保护文件访问
  │
  └─ https://sxxtoffice.cdjcc.edu.cn
          │                           Nginx 反向代理
          ▼
      127.0.0.1:8089
          │
          ▼
      ONLYOFFICE Docs 容器

ONLYOFFICE 容器 ──内部 HTTP──> Webman
      │                         ├─ 一次性文件源地址
      │                         ├─ 保存回调
      │                         └─ FileService 保存新版本
      │
      └─ 不直接连接学校业务数据库，不直接写 public/files
~~~

### 3.1 域名和端口

| 地址 | 用途 | 是否对公网开放 |
|---|---|---|
| https://sxxt.cdjcc.edu.cn | 现有系统和 Webman | 是 |
| https://sxxtoffice.cdjcc.edu.cn | ONLYOFFICE 编辑器静态资源和编辑连接 | 是，仅开放 443 |
| 127.0.0.1:8787 | Webman HTTP | 否，由 Nginx 转发 |
| 127.0.0.1:8788 | WebSocket 消息服务 | 否，按现有配置使用 |
| 127.0.0.1:8089 | ONLYOFFICE 容器 HTTP | 否，只给 Nginx 和本机使用 |

需要为 sxxtoffice.cdjcc.edu.cn 增加 DNS 解析，指向与 sxxt.cdjcc.edu.cn 相同的服务器。ONLYOFFICE 的 8089 端口不要直接暴露到公网。

### 3.2 是否需要反向代理

需要。推荐使用现有 Nginx 反向代理，原因如下：

- 由 Nginx 统一处理 TLS，ONLYOFFICE 容器只使用本机 HTTP。
- 隐藏 8089，避免文档服务绕过域名、证书和防火墙规则。
- 便于设置长连接、上传大小、超时和访问日志。
- 编辑器与系统使用稳定的 HTTPS 地址，企业微信和浏览器不会访问内网端口。
- 后续切换到独立服务器时，只需要修改 Nginx upstream 和 DNS。

同域名路径反代理论上可以实现，例如 `sxxt.cdjcc.edu.cn/onlyoffice/`，但需要同时验证静态资源的绝对路径、编辑器 WebSocket、回调地址、重定向和 Cookie 路径。ONLYOFFICE 官方部署示例以根路径服务为主，路径反代还会增加 Nginx 重写和升级排查项。因此本系统采用独立子域名 `sxxtoffice.cdjcc.edu.cn`，业务域名仍只承载 Webman、PC/H5 和文件权限。

## 4. 32 GB 服务器评估

32 GB 内存同机部署可行，但内存不是唯一条件，还要确认 CPU、磁盘和并发量。建议使用 amd64/x86_64 服务器，至少 4 个 CPU 核心和 SSD。ONLYOFFICE 官方社区版 Docker 文档给出的基础要求包括双核 2 GHz、至少 4 GB 内存、至少 40 GB 可用磁盘和至少 4 GB Swap；生产环境不应只按最低配置分配。

### 4.1 同机部署建议

在数据库也位于同一服务器的情况下，初始资源规划如下：

| 服务 | 初始内存规划 | 说明 |
|---|---:|---|
| ONLYOFFICE | 8-12 GB | 文档打开、转换和临时文件会产生波动 |
| MySQL | 8-12 GB | 以实际库大小和并发调整 innodb_buffer_pool_size |
| Webman PHP worker | 3-6 GB | 先从 8-12 个 worker 观察，避免沿用过大的默认值 |
| Redis、Nginx、系统 | 2-3 GB | 包含队列、缓存、日志和系统开销 |
| 预留 | 4-6 GB | 防止转换峰值导致 OOM |

如果 MySQL 已在其他服务器，ONLYOFFICE 可以获得更多内存。不要在没有监控的情况下把 32 GB 全部配置给数据库或 ONLYOFFICE。需要监控内存、CPU、磁盘空间、容器重启、转换失败和 Webman 回调延迟。

### 4.2 磁盘规划

ONLYOFFICE 的容器日志、缓存、临时文件和数据库目录应使用独立 Docker volume，例如 /srv/onlyoffice。建议至少预留 100 GB SSD 空间，并与 server/public/files 分开统计和备份：

- server/public/files：本系统原始文件和版本文件。
- /srv/onlyoffice：ONLYOFFICE 工作目录、日志、临时文件和容器数据。
- 版本文件只由 Webman 的 FileService 保存，不能依赖 ONLYOFFICE 容器缓存作为业务备份。

应设置磁盘使用率告警。磁盘空间不足时，ONLYOFFICE 可能无法打开或保存文件，即使 Webman 和数据库仍然正常。

## 5. Debian 13 部署

### 5.1 部署前检查

在服务器确认：

~~~bash
uname -m
docker --version
docker compose version
free -h
df -h
nproc
~~~

uname -m 应为 x86_64。Debian 13 使用 Docker Engine 和 Compose 插件部署即可，不建议在宿主机直接安装 ONLYOFFICE 的复杂依赖。服务器需要允许 Docker 拉取镜像，或提前配置企业镜像仓库。

### 5.2 创建运行目录

~~~bash
sudo install -d -m 0750 /srv/onlyoffice/{data,logs,lib,db,forgotten}
sudo chown -R root:root /srv/onlyoffice
cd /srv/onlyoffice
~~~

目录用途：

| 目录 | 用途 |
|---|---|
| data | ONLYOFFICE 数据目录 |
| logs | 容器日志 |
| lib | 应用缓存和运行数据 |
| db | 容器内数据库数据 |
| forgotten | 临时遗留文件 |

### 5.3 生成 JWT 密钥

ONLYOFFICE 与 Webman 必须使用同一份随机密钥。密钥只放在服务器环境文件和 Webman 的服务端环境变量中，不能写入前端源码、Git 或日志。

~~~bash
openssl rand -hex 32
~~~

创建 /srv/onlyoffice/.env：

~~~dotenv
ONLYOFFICE_VERSION=固定的正式版本号
ONLYOFFICE_JWT_SECRET=替换为上一步生成的随机值
~~~

版本号应固定，不要直接使用 latest。升级前先备份、在测试环境打开真实 DOCX/XLSX 文件，并核对官方变更说明。

### 5.4 Docker Compose

创建 /srv/onlyoffice/compose.yml：

~~~yaml
services:
  documentserver:
    image: onlyoffice/documentserver:${ONLYOFFICE_VERSION}
    container_name: practical-onlyoffice
    restart: unless-stopped
    ports:
      - "127.0.0.1:8089:80"
    environment:
      JWT_ENABLED: "true"
      JWT_SECRET: "${ONLYOFFICE_JWT_SECRET}"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - ./logs:/var/log/onlyoffice
      - ./data:/var/www/onlyoffice/Data
      - ./lib:/var/lib/onlyoffice
      - ./db:/var/lib/postgresql
      - ./forgotten:/var/lib/onlyoffice/documentserver/App_Data/cache/files/forgotten
~~~

具体环境变量以所使用的 ONLYOFFICE 镜像版本文档为准。启动前先检查配置并启动：

~~~bash
cd /srv/onlyoffice
docker compose config
docker compose pull
docker compose up -d
docker compose ps
docker compose logs --tail=100 documentserver
~~~

浏览器访问 https://sxxtoffice.cdjcc.edu.cn 时，能看到 ONLYOFFICE 的服务页面只能说明容器可访问，不代表编辑器已经能读取业务文件。必须继续完成 Webman 文件源和保存回调验证。

### 5.5 反向代理示例

在 Nginx 的 http 级别增加 WebSocket 连接映射：

~~~nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
~~~

增加独立 server：

~~~nginx
upstream onlyoffice_documentserver {
    server 127.0.0.1:8089;
    keepalive 16;
}

server {
    listen 443 ssl http2;
    server_name sxxtoffice.cdjcc.edu.cn;

    ssl_certificate     /etc/nginx/ssl/sxxt.cdjcc.edu.cn.crt;
    ssl_certificate_key /etc/nginx/ssl/sxxt.cdjcc.edu.cn.key;

    client_max_body_size 100m;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;

    location / {
        proxy_pass http://onlyoffice_documentserver;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
    }
}
~~~

然后执行：

~~~bash
sudo nginx -t
sudo systemctl reload nginx
~~~

防火墙只开放现有的 80、443 和必要的管理端口，不开放 8089。Nginx 证书必须覆盖 sxxtoffice.cdjcc.edu.cn。如果使用 CDN 或上游负载均衡，必须确认它支持长连接和大文件上传。

## 6. Webman 对接设计

### 6.1 业务边界

Webman 负责：

- 判断当前账号是否有该文件的编辑权限。
- 判断文件扩展名、文件大小和所属学校。
- 创建、续租、释放编辑锁。
- 生成 ONLYOFFICE 可访问的一次性文件源地址。
- 接收并验证 ONLYOFFICE 保存回调。
- 下载回调中的新文件并通过 FileService 保存为新版本。
- 记录版本、操作人、时间、保存结果和错误信息。
- 为 H5 提供最新版本的预览、下载和上传接口。

ONLYOFFICE 负责：

- PC 浏览器内的 DOCX/XLSX 编辑界面。
- 编辑器内的撤销、重做、格式和公式能力。
- 将编辑结果通过回调交给 Webman。

ONLYOFFICE 不直接访问学校数据库，不直接修改 file_blob、file 或业务表。

### 6.2 建议接口

接口名称可按现有路由规范调整，职责建议保持如下：

| 接口 | 调用方 | 作用 |
|---|---|---|
| POST /api/office-editor/open | PC 前端 | 校验权限、获取文件锁、返回编辑器配置和会话 ID |
| POST /api/office-editor/heartbeat | PC 前端 | 续租编辑锁，更新最后活动时间 |
| POST /api/office-editor/release | PC 前端 | 保存完成后主动释放会话 |
| GET /api/office-editor/source | ONLYOFFICE 服务端 | 使用一次性令牌读取当前版本二进制文件 |
| POST /api/office-editor/callback | ONLYOFFICE 服务端 | 接收状态和保存文件地址 |
| GET /api/office-editor/status | PC/H5 | 查询当前编辑者、版本和锁状态 |
| POST /api/office-editor/force-release | 管理员 | 使异常会话失效并释放锁 |
| POST /api/office-editor/mobile-upload | H5 | 上传修改后的文件并创建新版本 |

source 和 callback 不能只依靠普通用户 Cookie。ONLYOFFICE 是服务端调用方，应使用短期令牌、JWT 和会话 ID 三重校验，并限制令牌用途、文件 ID、版本号和过期时间。

### 6.3 文件源和回调网络路径

推荐让 ONLYOFFICE 容器通过宿主机网关访问 Webman：

~~~text
ONLYOFFICE 容器
  └─ http://host.docker.internal:8787/api/office-editor/source?token=...
  └─ http://host.docker.internal:8787/api/office-editor/callback?session=...
~~~

Compose 中的 extra_hosts 将 host.docker.internal 指向宿主机。这样文档服务不需要从公网绕回 sxxt.cdjcc.edu.cn。如果服务器网络策略不允许容器访问宿主机网关，可以改为使用内部可解析的 HTTPS 地址，但必须先验证：

~~~bash
docker exec practical-onlyoffice curl -I http://host.docker.internal:8787/
~~~

业务文件源接口只返回经过权限和一次性令牌校验的文件流，不能返回永久文件 URL。保存回调中的文件下载地址同样只允许由服务端使用。

### 6.4 大文件传输方式

大文件不需要先从公网下载到浏览器，再上传给 ONLYOFFICE。推荐采用服务端内网直连：

1. PC 浏览器只请求 `sxxtoffice.cdjcc.edu.cn` 的编辑器页面和编辑连接。
2. ONLYOFFICE 容器通过 `host.docker.internal:8787` 请求 Webman 的一次性 `source` 地址。
3. Webman 从现有 `FileService` 读取本机文件或受控存储，并把文件流返回给 ONLYOFFICE 容器。
4. 编辑完成后，ONLYOFFICE 将保存回调中的结果地址提供给 Webman；Webman 在服务器内部下载并写入新版本。
5. 浏览器不会接收原始大文件，也不会承担转换文件的中转流量。

这里的“本机转换”是指 ONLYOFFICE 容器与 Webman 位于同一台服务器或同一内网，文件从本机存储进入文档服务。浏览器仍然需要通过 HTTPS 连接编辑器页面，因此不能理解为完全没有网络通信。若文件存储改为 OSS、S3 或其他远程磁盘，Webman 仍应生成受权限控制的短期地址，不能把永久公网文件 URL 放入编辑器配置。

大文件还需要同步配置以下限制：

- Nginx `client_max_body_size` 覆盖允许的 Office 文件大小。
- Nginx 和 Webman 的读取、发送超时覆盖文件转换时间。
- ONLYOFFICE 临时目录和 `server/public/files` 分别监控磁盘空间。
- 保存回调采用流式下载或临时文件，不能把整个文件读入 PHP 内存。
- 转换失败、超时和重复回调必须写入版本记录，不能直接覆盖原文件。

### 6.5 ONLYOFFICE 编辑配置

Webman 为 PC 前端返回以下信息：

- documentserver_url：https://sxxtoffice.cdjcc.edu.cn
- document.key：文件 ID、版本号和随机值组成的稳定版本键
- document.url：ONLYOFFICE 服务端可访问的一次性文件源地址
- document.fileType：只允许 docx 或 xlsx
- document.title：下载文件名
- editorConfig.callbackUrl：当前编辑会话的回调地址
- editorConfig.mode：编辑模式
- editorConfig.user：当前账号的显示信息和稳定用户 ID
- JWT 配置：由 Webman 服务端签名后返回，不在浏览器端生成密钥

同一文件的新版本必须使用新的 document.key。版本键不能只使用文件 ID，否则 ONLYOFFICE 或浏览器缓存可能继续使用旧文档。

## 7. 单人编辑和版本模型

### 7.1 编辑锁规则

打开编辑器时，Webman 在学校库事务中原子创建编辑会话：

~~~text
file_id + account_id + session_id + base_version + lease_expire_at
~~~

规则：

1. 没有有效锁时才允许创建会话。
2. 已有有效锁时，其他账号只能预览、下载或上传新版本，不能打开编辑器。
3. 同一账号重复打开时复用或拒绝第二个会话，避免一个账号的两个标签页互相覆盖。
4. 前端每 20-30 秒续租一次，服务端以 Redis TTL 和数据库状态共同判断有效性。
5. 关闭页面时主动释放，但不能依赖 beforeunload 作为唯一释放方式。
6. 超过租约且没有续租时，管理员或系统任务可以回收锁。

### 7.2 保存和回调规则

ONLYOFFICE 保存是异步的。Webman 只有在成功下载回调文件、校验文件类型和 MD5、写入新 FileRecord/版本记录后，才将会话标记为已保存。

只处理以下回调：

- status=2：编辑会话结束，文档可以保存。
- status=6：强制保存或用户点击保存触发的保存结果。
- 其他状态写入日志并返回成功，避免文档服务因重复回调重试；具体状态映射按使用版本官方文档核对。

回调处理必须幂等：

- 校验会话 ID、文件 ID、用户 ID、版本键和 JWT。
- 校验回调文件仍对应当前会话。
- 已处理的回调不重复创建版本。
- base_version 不是当前最新版本时拒绝落盘，保留旧文件并记录冲突。
- 保存失败时不释放锁，前端显示失败状态，允许重试或由管理员处理。

### 7.3 数据表建议

建议新增学校库表，实际字段以数据库规范和现有迁移方式为准。

office_edit_session：

- id、file_id、account_id、session_token_hash
- base_version_id、status
- last_heartbeat_at、expires_at
- created_at、released_at、release_reason

office_file_version：

- id、file_id、version_no
- stored_file_id、md5、size
- created_by、source、save_status
- created_at、error_message

原始文件和每个正式版本继续通过 FileService 保存，版本表只保存业务关系。删除业务文件前必须检查版本关联，避免物理文件被错误回收。

## 8. PC、H5 和企业微信流程

### 8.1 PC 编辑

1. 用户打开文件详情，PC 显示“在线编辑”。
2. 前端调用 /api/office-editor/open。
3. Webman 判断权限并获取文件锁。
4. 返回 ONLYOFFICE 配置，前端加载 DocsAPI.DocEditor。
5. 前端定时续租，保存状态从回调或状态接口读取。
6. 用户点击“保存并退出”或关闭编辑器，Webman 在保存成功后释放锁。

### 8.2 企业微信和 H5

企业微信内打开时：

- 不加载 ONLYOFFICE 编辑器。
- DOCX、XLSX 继续使用现有只读预览。
- 提供“下载原文件”和“上传修改后文件”。
- 上传后创建新版本，要求用户填写可选的版本说明。
- 如果文件当前被 PC 用户编辑，H5 上传可以选择禁止，首期建议禁止覆盖当前编辑会话。

这样手机端不涉及商业移动网页编辑器授权，也不会增加 H5 包体和内存压力。

## 9. 安全和运维

- ONLYOFFICE 容器只监听 127.0.0.1:8089。
- ONLYOFFICE JWT 密钥与 Webman 配置一致，单独生成，不复用 JWT 登录密钥、企业微信密钥或数据库密码。
- /api/office-editor/callback 不加入普通免登录白名单；使用文档服务 JWT 和会话令牌进行服务端认证。
- 文件源令牌短期有效、单次使用，并绑定学校、文件、版本和会话。
- 禁止通过回调地址或请求参数传入任意文件路径。
- Nginx 和 ONLYOFFICE 日志不得记录文件源令牌、JWT 和完整下载地址。
- ONLYOFFICE 不能绕过 FileService 直接写 public/files。
- 文档版本和回调日志按学校隔离，管理员可以查询失败原因和当前锁持有者。
- 备份包括学校库、server/public/files、/srv/onlyoffice 配置和版本表；ONLYOFFICE 临时缓存不作为业务备份。
- 对 Docker 镜像固定版本，升级前先在测试环境验证 DOCX/XLSX 的公式、图片、合并单元格、页眉页脚和导出结果。

## 10. 实施顺序

### 阶段一：基础部署

1. 准备 sxxtoffice.cdjcc.edu.cn DNS 和 HTTPS 证书。
2. 在 Debian 13 安装 Docker Engine 和 Compose 插件。
3. 启动 ONLYOFFICE Community Edition，确认本机端口、容器健康和 Nginx 反代。
4. 配置 JWT，确认浏览器可以加载编辑器资源。

### 阶段二：Webman 单文件闭环

1. 增加编辑会话和版本表。
2. 增加文件锁、续租、释放和管理员强制释放。
3. 增加文件源接口和回调接口。
4. PC 端只接入 DOCX、XLSX。
5. 用真实文件验证打开、保存、关闭、重新打开和版本下载。

### 阶段三：H5 文件流转

1. H5 增加最新版本预览、下载和上传入口。
2. 处理 PC 编辑锁期间的手机上传规则。
3. 企业微信内核验文件权限、绑定状态和上传后的版本记录。

### 阶段四：上线运维

1. 配置容器、Nginx、Webman、数据库和磁盘监控。
2. 配置 ONLYOFFICE 镜像升级、回滚和备份流程。
3. 核对 AGPLv3 或商业授权范围，形成书面记录。

## 11. 上线前检查

- sxxtoffice.cdjcc.edu.cn 使用有效 HTTPS 证书。
- 公网无法访问 8089。
- ONLYOFFICE 容器能够访问 Webman 的内部 source 和 callback 地址。
- Webman 能下载回调文件并写入新版本。
- 同一文件第二个账号只能预览，不能拿到编辑配置。
- 旧会话的迟到保存不会覆盖新版本。
- PC 保存后的文件可以在 Microsoft Office 或 WPS 中打开。
- 企业微信手机端能预览、下载并上传修改后的文件。
- Webman、Nginx、ONLYOFFICE、MySQL 和磁盘均有日志与告警。
- 已完成许可证审查，确认 Community Edition 的 AGPLv3 使用方式符合学校部署要求。

## 12. 官方资料

- [ONLYOFFICE Docs Community Edition Docker 安装](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-docker.aspx)
- [Community Edition 授权 FAQ](https://helpcenter.onlyoffice.com/docs/faq/docs-community.aspx)
- [ONLYOFFICE 保存文件流程](https://api.onlyoffice.com/docs/docs-api/get-started/how-it-works/saving-file/)
- [ONLYOFFICE 回调处理](https://api.onlyoffice.com/docs/docs-api/usage-api/callback-handler/)
- [ONLYOFFICE JWT 签名](https://api.onlyoffice.com/docs/docs-api/additional-api/signature/)
- [ONLYOFFICE DocumentServer GitHub](https://github.com/ONLYOFFICE/DocumentServer)
