use crate::connection::{self, Connection, School};
use axum::{
    body::Body,
    extract::{Request, State},
    http::{header, HeaderMap, HeaderValue, Response, StatusCode},
    routing::any,
    Router,
};
use rust_embed::RustEmbed;
use std::sync::Arc;
use tauri::Manager;
use tauri_plugin_dialog::DialogExt;
use tokio::{
    net::TcpListener,
    sync::{oneshot, Mutex, RwLock, RwLockReadGuard},
};
use url::Url;

#[derive(RustEmbed)]
#[folder = "../dist/pc/"]
struct PcAssets;

#[derive(RustEmbed)]
#[folder = "../dist/shell/"]
struct AgentAssets;

pub struct Gateway {
    pub origin: Url,
    pub remote: Url,
    pub entry: Url,
    pub port: u16,
    pub owner: String,
    shutdown: Option<oneshot::Sender<()>>,
    cancel: tokio_util::sync::CancellationToken,
    login_cancel: Option<(reqwest::Client, Url)>,
    session_lock: Arc<RwLock<()>>,
    pub lock: Arc<crate::client_lock::ClientLock>,
    authorization_cancel: Arc<Mutex<tokio_util::sync::CancellationToken>>,
}

pub struct SourceSession {
    client: reqwest::Client,
    origin: Url,
    session_lock: Arc<RwLock<()>>,
    cancel: tokio_util::sync::CancellationToken,
    lock: Arc<crate::client_lock::ClientLock>,
}

impl SourceSession {
    pub async fn logout_for_reconnect(self) -> Result<(), String> {
        let _session = self.session_lock.write().await;
        if self.cancel.is_cancelled() || self.lock.is_locked() {
            return Err("客户端已锁定，请在锁屏退出原会话后重新连接".into());
        }
        match logout_source(&self.client, &self.origin).await {
            Ok(()) => Ok(()),
            Err(message) => {
                if !self.cancel.is_cancelled()
                    && !self.lock.is_locked()
                    && source_confirmed_guest(&self.client, &self.origin).await
                    && !self.cancel.is_cancelled()
                    && !self.lock.is_locked()
                {
                    Ok(())
                } else {
                    Err(message)
                }
            }
        }
    }
    pub async fn logout(self) -> Result<(), String> {
        let _session = self.session_lock.write().await;
        if self.cancel.is_cancelled() {
            return Err("学校连接已关闭".into());
        }
        logout_source(&self.client, &self.origin).await
    }
}

struct GatewayState {
    app: tauri::AppHandle,
    cancel: tokio_util::sync::CancellationToken,
    connection: Connection,
    local: Url,
    cookie: String,
    token: String,
    owner: String,
    authorization_cancel: Arc<Mutex<tokio_util::sync::CancellationToken>>,
    profile: Mutex<School>,
    lock: Arc<crate::client_lock::ClientLock>,
    recovery: bool,
}

impl Drop for Gateway {
    /// 关闭当前学校本机通道。
    fn drop(&mut self) {
        crate::client_agent::cancel_owner(&self.owner);
        if let Some((client, origin)) = self.login_cancel.take() {
            tauri::async_runtime::spawn(async move {
                if let Ok(url) = origin.join("api/auth/wechat-login/cancel") {
                    let _ = client
                        .post(url)
                        .header(header::ORIGIN, origin.origin().ascii_serialization())
                        .timeout(std::time::Duration::from_secs(5))
                        .json(&serde_json::json!({}))
                        .send()
                        .await;
                }
            });
        }
        self.cancel.cancel();
        if let Some(shutdown) = self.shutdown.take() {
            let _ = shutdown.send(());
        }
    }
}

impl Gateway {
    pub fn download_cancellation(&self) -> Option<tokio_util::sync::CancellationToken> {
        self.authorization_cancel
            .try_lock()
            .ok()
            .map(|cancel| cancel.clone())
            .filter(|cancel| !cancel.is_cancelled())
    }
    pub fn source_session(&self) -> Result<SourceSession, String> {
        let (client, origin) = self.login_cancel.clone().ok_or("学校会话不可用")?;
        Ok(SourceSession {
            client,
            origin,
            session_lock: self.session_lock.clone(),
            cancel: self.cancel.clone(),
            lock: self.lock.clone(),
        })
    }
}

/// 等待学校确认注销，失败时保留原客户端连接。
pub async fn logout_source(client: &reqwest::Client, origin: &Url) -> Result<(), String> {
    let target = origin
        .join("api/auth/logout")
        .map_err(|_| "学校退出地址无效")?;
    if target.origin() != origin.origin() {
        return Err("学校退出来源无效".into());
    }
    let response = client
        .post(target)
        .header(header::ORIGIN, origin.origin().ascii_serialization())
        .timeout(std::time::Duration::from_secs(15))
        .json(&serde_json::json!({}))
        .send()
        .await
        .map_err(|_| "无法连接原学校退出接口")?;
    let status = response.status();
    let body = response
        .bytes()
        .await
        .map_err(|_| "无法读取原学校退出响应")?;
    if body.len() > 32 * 1024 {
        return Err("原学校退出响应无效".into());
    }
    let value: serde_json::Value =
        serde_json::from_slice(&body).map_err(|_| "原学校退出响应无效")?;
    if !status.is_success() || value["code"].as_i64() != Some(0) {
        return Err(value["message"]
            .as_str()
            .unwrap_or("原学校退出未完成")
            .to_owned());
    }
    Ok(())
}

async fn source_confirmed_guest(client: &reqwest::Client, origin: &Url) -> bool {
    let Ok(target) = origin.join("api/auth/context") else {
        return false;
    };
    if target.origin() != origin.origin() {
        return false;
    }
    let Ok(mut response) = client
        .get(target)
        .timeout(std::time::Duration::from_secs(15))
        .send()
        .await
    else {
        return false;
    };
    if !response.status().is_success() {
        return false;
    }
    let mut bytes = Vec::new();
    loop {
        let Ok(chunk) = response.chunk().await else {
            return false;
        };
        let Some(chunk) = chunk else { break };
        if bytes.len() + chunk.len() > 65536 {
            return false;
        }
        bytes.extend_from_slice(&chunk);
    }
    let Ok(value) = serde_json::from_slice::<serde_json::Value>(&bytes) else {
        return false;
    };
    if value["code"].as_i64() != Some(0) {
        return false;
    }
    let Some(context) = value["data"].as_object() else {
        return false;
    };
    if !context
        .get("auth_error")
        .is_some_and(|error| error.is_null() || error.as_str() == Some(""))
    {
        return false;
    }
    context
        .get("account_id")
        .is_some_and(|id| id.is_null() || id.as_i64() == Some(0))
}

/// 启动只监听回环地址的会话通道，PC 页面来自安装包。
pub async fn start(
    app: tauri::AppHandle,
    mut connection: Connection,
    reserved_ports: &[u16],
    verified_context: Option<&serde_json::Value>,
    recovery: bool,
) -> Result<Gateway, String> {
    let listener = bind_school_port(connection.school.port, reserved_ports).await?;
    let port = listener
        .local_addr()
        .map_err(|_| "无法读取本机连接端口")?
        .port();
    connection.school.port = port;
    let local =
        Url::parse(&format!("http://127.0.0.1:{port}/")).map_err(|_| "无法创建本机连接地址")?;
    let token = uuid::Uuid::new_v4().simple().to_string();
    let owner = uuid::Uuid::new_v4().simple().to_string();
    let entry = local
        .join(&format!("_desktop/connect/{token}"))
        .map_err(|_| "无法创建学校入口")?;
    let remote = connection.origin.clone();
    let login_cancel = Some((connection.client.clone(), remote.clone()));
    let cancel = connection.cancel.clone();
    let session_lock = connection.session_lock.clone();
    let lock = Arc::new(crate::client_lock::ClientLock::new(&app)?);
    if let Some(context) = verified_context {
        lock.remember_context(context);
    }
    let authorization_cancel = Arc::new(Mutex::new(cancel.child_token()));
    let state = Arc::new(GatewayState {
        app,
        cancel: cancel.clone(),
        profile: Mutex::new(connection.school.clone()),
        connection,
        local: local.clone(),
        cookie: format!("practical_desktop_{port}"),
        token,
        owner: owner.clone(),
        authorization_cancel: authorization_cancel.clone(),
        lock: lock.clone(),
        recovery,
    });
    let timer_state = state.clone();
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::select! {
                _ = timer_state.cancel.cancelled() => break,
                _ = tokio::time::sleep(std::time::Duration::from_secs(1)) => {}
            }
            crate::input_activity::poll(&timer_state.app, &timer_state.owner, &timer_state.lock);
            if timer_state.lock.due()
                && !timer_state
                    .app
                    .state::<crate::AppState>()
                    .connecting
                    .load(std::sync::atomic::Ordering::Acquire)
            {
                if let Err(message) = lock_session(&timer_state).await {
                    timer_state
                        .app
                        .dialog()
                        .message(message)
                        .title("客户端锁定")
                        .show(|_| {});
                }
            }
        }
    });
    let scheduler_state = state.clone();
    tauri::async_runtime::spawn(async move {
        let mut previous = std::time::Instant::now();
        let mut paused = true;
        loop {
            tokio::select! {_=scheduler_state.cancel.cancelled()=>break,_=tokio::time::sleep(std::time::Duration::from_secs(5))=>{}}
            let elapsed = previous.elapsed();
            previous = std::time::Instant::now();
            if scheduler_state.lock.is_locked() {
                paused = true;
                continue;
            }
            let Ok((context, _)) = scheduler_state.lock.password_context(false) else {
                paused = true;
                continue;
            };
            let cancel = scheduler_state.authorization_cancel.lock().await.clone();
            let mut connection = scheduler_state.connection.clone();
            connection.operation_context = Some(context);
            let _session = connection.session_lock.read().await;
            if scheduler_state.lock.is_locked() || cancel.is_cancelled() {
                paused = true;
                continue;
            }
            let interrupted = paused || elapsed > std::time::Duration::from_secs(20);
            paused = false;
            let _ = crate::client_agent::scheduler_tick(
                &scheduler_state.app,
                &connection,
                &scheduler_state.owner,
                cancel,
                interrupted,
            )
            .await;
        }
    });
    let router = Router::new().fallback(any(handle)).with_state(state);
    let (shutdown, receive) = oneshot::channel();
    tauri::async_runtime::spawn(async move {
        let _ = axum::serve(listener, router)
            .with_graceful_shutdown(async {
                let _ = receive.await;
            })
            .await;
    });
    Ok(Gateway {
        origin: local,
        remote,
        entry,
        port,
        owner,
        login_cancel,
        cancel,
        shutdown: Some(shutdown),
        session_lock,
        lock,
        authorization_cancel,
    })
}

/// 保留学校独立的网页存储来源，避开其他学校的历史端口。
async fn bind_school_port(preferred: u16, reserved: &[u16]) -> Result<TcpListener, String> {
    if preferred != 0 && !reserved.contains(&preferred) {
        if let Ok(listener) = TcpListener::bind((std::net::Ipv4Addr::LOCALHOST, preferred)).await {
            return Ok(listener);
        }
    }
    for _ in 0..32 {
        let listener = TcpListener::bind((std::net::Ipv4Addr::LOCALHOST, 0))
            .await
            .map_err(|_| "无法建立本机连接通道")?;
        if !reserved.contains(
            &listener
                .local_addr()
                .map_err(|_| "无法读取本机连接端口")?
                .port(),
        ) {
            return Ok(listener);
        }
    }
    Err("无法分配学校独立连接端口，请稍后重试".into())
}

/// 验证本机入口、会话 Cookie 和来源后分发请求。
async fn handle(State(state): State<Arc<GatewayState>>, request: Request) -> Response<Body> {
    let headers = request.headers();
    let host = format!("127.0.0.1:{}", state.local.port().unwrap_or_default());
    if headers
        .get(header::HOST)
        .and_then(|value| value.to_str().ok())
        != Some(host.as_str())
    {
        return error(StatusCode::FORBIDDEN, "不允许访问该本机地址");
    }
    if headers.get(header::ORIGIN).is_some_and(|origin| {
        origin.as_bytes() != state.local.origin().ascii_serialization().as_bytes()
    }) || headers
        .get("sec-fetch-site")
        .is_some_and(|site| site.as_bytes() == b"cross-site")
    {
        return error(StatusCode::FORBIDDEN, "不允许跨站访问客户端通道");
    }
    let path = request.uri().path();
    if request.method() == axum::http::Method::GET
        && path == format!("/_desktop/connect/{}", state.token)
    {
        let mut response = Response::new(Body::empty());
        *response.status_mut() = StatusCode::SEE_OTHER;
        response.headers_mut().insert(
            header::LOCATION,
            HeaderValue::from_static(if state.recovery {
                "/pc/index.html#password-recovery"
            } else {
                "/pc/index.html"
            }),
        );
        if let Ok(cookie) = HeaderValue::from_str(&format!(
            "{}={}; Path=/; HttpOnly; SameSite=Strict",
            state.cookie, state.token
        )) {
            response.headers_mut().insert(header::SET_COOKIE, cookie);
        }
        response
            .headers_mut()
            .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
        response
            .headers_mut()
            .insert("referrer-policy", HeaderValue::from_static("no-referrer"));
        return response;
    }
    let expected = format!("{}={}", state.cookie, state.token);
    let authenticated = headers
        .get(header::COOKIE)
        .and_then(|cookie| cookie.to_str().ok())
        .is_some_and(|cookies| cookies.split(';').any(|cookie| cookie.trim() == expected));
    if !authenticated {
        return error(StatusCode::UNAUTHORIZED, "请从客户端连接学校");
    }
    if state.lock.due() {
        let _ = lock_session(&state).await;
    }
    if path == "/_desktop/lock" {
        return lock_request(state, request).await;
    }
    if state.lock.is_locked()
        && (path.starts_with("/_desktop/")
            || path.starts_with("/api/")
            || path.starts_with("/files/"))
        && !locked_request_allowed(path)
    {
        return error(StatusCode::LOCKED, "客户端已锁定，请先验证当前账号");
    }
    let authorization_cancel = state.authorization_cancel.lock().await.clone();
    if path == "/_desktop/preview-file" && request.method() == axum::http::Method::GET {
        let (connection, _session) = match account_operation(&state, &authorization_cancel).await {
            Ok(operation) => operation,
            Err(message) => return error(StatusCode::UNAUTHORIZED, &message),
        };
        let id = url::form_urlencoded::parse(request.uri().query().unwrap_or("").as_bytes())
            .find(|(key, _)| key == "id")
            .and_then(|(_, value)| value.parse::<u64>().ok())
            .unwrap_or(0);
        let result = tokio::select! {
            _ = authorization_cancel.cancelled() => return error(StatusCode::LOCKED, "客户端已锁定或账号已变更"),
            result = crate::preview_cache::serve(&state.app, &connection, id, headers, authorization_cancel.clone()) => result,
        };
        if state.lock.is_locked() || authorization_cancel.is_cancelled() {
            return error(StatusCode::LOCKED, "客户端已锁定或账号已变更");
        }
        return match result {
            Ok(response) => response,
            Err(message) => error(StatusCode::BAD_REQUEST, &message),
        };
    }
    if path == "/_desktop/agent.html" || path.starts_with("/_desktop/assets/") {
        if request.method() != axum::http::Method::GET {
            return error(StatusCode::METHOD_NOT_ALLOWED, "请使用 GET");
        }
        let key = path.trim_start_matches("/_desktop/");
        return match AgentAssets::get(key) {
            Some(asset) => {
                let mime = mime_guess::from_path(key).first_or_octet_stream();
                let mut response = Response::new(Body::from(asset.data.into_owned()));
                if let Ok(value) = HeaderValue::from_str(mime.as_ref()) {
                    response.headers_mut().insert(header::CONTENT_TYPE, value);
                }
                response
                    .headers_mut()
                    .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
                response.headers_mut().insert("content-security-policy", HeaderValue::from_static("default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'"));
                response
            }
            None => error(
                StatusCode::NOT_FOUND,
                "Agent 资源不存在，请重新安装完整客户端",
            ),
        };
    }
    if path == "/_desktop/agent" {
        if request.method() != axum::http::Method::POST
            || headers.get(header::ORIGIN).and_then(|v| v.to_str().ok())
                != Some(state.local.origin().ascii_serialization().as_str())
        {
            return error(StatusCode::FORBIDDEN, "仅学校页面可以调用客户端 Agent");
        }
        let bytes = match axum::body::to_bytes(request.into_body(), 2 * 1024 * 1024).await {
            Ok(bytes) => bytes,
            Err(_) => return error(StatusCode::BAD_REQUEST, "Agent 参数过长"),
        };
        let mut input: serde_json::Value = match serde_json::from_slice(&bytes) {
            Ok(value) => value,
            Err(_) => return error(StatusCode::BAD_REQUEST, "Agent 参数无效"),
        };
        if matches!(input["action"].as_str(), Some("poll" | "cancel")) {
            let (context, _) = match state.lock.password_context(false) {
                Ok(value) => value,
                Err(message) => return error(StatusCode::UNAUTHORIZED, &message),
            };
            let mut connection = state.connection.clone();
            connection.operation_context = Some(context);
            if let Err(message) = crate::client_agent::require_real_identity(&connection) {
                return error(StatusCode::FORBIDDEN, &message);
            }
            let result = crate::client_agent::action(
                &state.app,
                &connection,
                &state.owner,
                authorization_cancel.clone(),
                &input,
            )
            .await;
            if state.lock.is_locked()
                || state.cancel.is_cancelled()
                || authorization_cancel.is_cancelled()
            {
                return error(StatusCode::LOCKED, "客户端已锁定或账号已变更");
            }
            return match result {
                Ok(value) => local_json(value),
                Err(message) => error(StatusCode::BAD_REQUEST, &message),
            };
        }
        let operation = if matches!(
            input["action"].as_str(),
            Some(
                "bootstrap"
                    | "save"
                    | "delete-profile"
                    | "models"
                    | "probe"
                    | "workspace"
                    | "workspace-edit"
                    | "import-skill"
                    | "probe-mcp"
                    | "clear"
            )
        ) {
            local_account_operation(&state, &authorization_cancel).await
        } else {
            account_operation(&state, &authorization_cancel).await
        };
        let (connection, _session) = match operation {
            Ok(value) => value,
            Err(message) => return error(StatusCode::UNAUTHORIZED, &message),
        };
        if let Err(message) = crate::client_agent::require_real_identity(&connection) {
            return error(StatusCode::FORBIDDEN, &message);
        }
        let result = if input["action"] == "open" {
            crate::client_agent::open(
                &state.app,
                &state.local,
                &state.connection.origin,
                &state.owner,
            )
        } else {
            tokio::select! {biased;
                _=authorization_cancel.cancelled()=>Err("客户端已锁定或账号已变更".into()),
                result=crate::client_agent::action(&state.app,&connection,&state.owner,authorization_cancel.clone(),&input)=>result,
            }
        };
        if let Some(serde_json::Value::String(key)) = input.get_mut("api_key") {
            use zeroize::Zeroize;
            key.zeroize();
        }
        if state.lock.is_locked()
            || state.cancel.is_cancelled()
            || authorization_cancel.is_cancelled()
        {
            return error(StatusCode::LOCKED, "客户端已锁定或账号已变更");
        }
        return match result {
            Ok(value) => local_json(value),
            Err(message) => error(StatusCode::BAD_REQUEST, &message),
        };
    }
    if path == "/_desktop/office-preview" {
        if request.method() != axum::http::Method::POST
            || headers.get(header::ORIGIN).and_then(|v| v.to_str().ok())
                != Some(state.local.origin().ascii_serialization().as_str())
        {
            return error(StatusCode::FORBIDDEN, "仅学校页面可以调用本机 Office");
        }
        let bytes = match axum::body::to_bytes(request.into_body(), 4096).await {
            Ok(bytes) => bytes,
            Err(_) => return error(StatusCode::BAD_REQUEST, "参数过长"),
        };
        let input: serde_json::Value = match serde_json::from_slice(&bytes) {
            Ok(input) => input,
            Err(_) => return error(StatusCode::BAD_REQUEST, "参数无效"),
        };
        let action = input["action"].as_str().unwrap_or("");
        let allowed: &[&str] = match action {
            "status" => &["action"],
            "save" => &["action", "mode", "engine"],
            "open" => &["action", "file_id", "request_id"],
            "convert" => &["action", "file_id", "request_id", "cancel"],
            _ => return error(StatusCode::BAD_REQUEST, "本机 Office 操作无效"),
        };
        if input
            .as_object()
            .is_none_or(|object| object.keys().any(|key| !allowed.contains(&key.as_str())))
        {
            return error(StatusCode::BAD_REQUEST, "本机 Office 参数无效");
        }
        if action == "convert" && input["cancel"].as_bool() == Some(true) {
            return match crate::office_preview::cancel(&state.owner, &input) {
                Ok(data) => local_json(data),
                Err(message) => error(StatusCode::BAD_REQUEST, &message),
            };
        }
        let generation = match crate::office_preview::scope(&state.owner) {
            Ok(generation) => generation,
            Err(message) => return error(StatusCode::BAD_REQUEST, &message),
        };
        let operation = tokio::select! {
            _ = generation.cancel.cancelled() => return error(StatusCode::LOCKED, "学校会话正在变更，本机预览已取消"),
            _ = authorization_cancel.cancelled() => return error(StatusCode::LOCKED, "客户端已锁定或账号已变更"),
            result = tokio::time::timeout(std::time::Duration::from_secs(30), account_operation(&state, &authorization_cancel)) => result,
        };
        let (connection, _session) = match operation {
            Ok(Ok(operation)) => operation,
            Ok(Err(message)) => return error(StatusCode::UNAUTHORIZED, &message),
            Err(_) => return error(StatusCode::GATEWAY_TIMEOUT, "学校会话校验超时，请稍后重试"),
        };
        if let Err(message) =
            crate::office_preview::scope_current(&state.owner, &generation.revision)
        {
            return error(StatusCode::LOCKED, &message);
        }
        let result = if matches!(action, "status" | "save") {
            crate::office_preview::configure(&state.app, &input)
        } else {
            crate::office_preview::perform(
                &state.app,
                &connection,
                &state.owner,
                &generation.revision,
                &input,
                &authorization_cancel,
            )
            .await
        };
        if state.lock.is_locked()
            || authorization_cancel.is_cancelled()
            || state.cancel.is_cancelled()
        {
            return error(StatusCode::LOCKED, "客户端已锁定或账号已变更");
        }
        return match result {
            Ok(data) => local_json(data),
            Err(message) => error(StatusCode::BAD_REQUEST, &message),
        };
    }
    if matches!(
        path,
        "/_desktop/external"
            | "/_desktop/sso"
            | "/_desktop/update"
            | "/_desktop/favorite-credentials"
            | "/_desktop/preview-cache"
            | "/_desktop/credential-vault"
            | "/_desktop/window"
    ) {
        if request.method() != axum::http::Method::POST
            || headers.get(header::ORIGIN).and_then(|v| v.to_str().ok())
                != Some(state.local.origin().ascii_serialization().as_str())
        {
            return error(StatusCode::FORBIDDEN, "仅学校页面可以调用客户端功能");
        }
        let update = path.ends_with("/update");
        let sso = path.ends_with("/sso");
        let credentials = path.ends_with("/favorite-credentials");
        let cache = path.ends_with("/preview-cache");
        let vault = path.ends_with("/credential-vault");
        let controls = path.ends_with("/window");
        let bytes = match axum::body::to_bytes(request.into_body(), 2 * 1024 * 1024).await {
            Ok(bytes) => bytes,
            Err(_) => return error(StatusCode::BAD_REQUEST, "参数过长"),
        };
        let input: serde_json::Value = match serde_json::from_slice(&bytes) {
            Ok(input) => input,
            Err(_) => return error(StatusCode::BAD_REQUEST, "参数无效"),
        };
        let account_bound = sso
            || credentials
            || vault
            || !(update || controls || cache) && input["favorite_id"].as_u64().unwrap_or(0) > 0;
        let plain_session = if account_bound || controls {
            None
        } else {
            Some(state.connection.session_lock.read().await)
        };
        if state.cancel.is_cancelled()
            || authorization_cancel.is_cancelled()
            || state.lock.is_locked() && !controls
        {
            return error(StatusCode::LOCKED, "客户端已锁定或账号已变更，请重新操作");
        }
        let _plain_session = plain_session;
        let operation = if account_bound {
            match account_operation(&state, &authorization_cancel).await {
                Ok(operation) => Some(operation),
                Err(message) => return error(StatusCode::UNAUTHORIZED, &message),
            }
        } else {
            None
        };
        let connection = operation
            .as_ref()
            .map_or(&state.connection, |(connection, _)| connection);
        if sso {
            return match crate::favorite_auth::open_sso(
                &state.app,
                connection,
                input["favorite_id"].as_u64().unwrap_or(0),
                input["mode"].as_str().unwrap_or("client"),
                &state.owner,
                &authorization_cancel,
            )
            .await
            {
                Ok(()) => {
                    let mut response = Response::new(Body::from("{}"));
                    response.headers_mut().insert(
                        header::CONTENT_TYPE,
                        HeaderValue::from_static("application/json"),
                    );
                    response
                        .headers_mut()
                        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
                    response
                }
                Err(message) => error(StatusCode::BAD_REQUEST, &message),
            };
        }
        if credentials || cache || vault || controls {
            let result = if controls {
                crate::windows::control(&state.app, input["action"].as_str().unwrap_or(""))
            } else if vault {
                crate::credential_vault::configure(connection, &input).await
            } else if credentials {
                crate::favorite_auth::configure(connection, &input).await
            } else {
                crate::preview_cache::configure(&state.app, &input).await
            };
            if !controls && (state.lock.is_locked() || authorization_cancel.is_cancelled()) {
                return error(StatusCode::LOCKED, "客户端已锁定或账号已变更，请重新操作");
            }
            return match result {
                Ok(data) => {
                    let mut response = Response::new(Body::from(data.to_string()));
                    response.headers_mut().insert(
                        header::CONTENT_TYPE,
                        HeaderValue::from_static("application/json"),
                    );
                    response
                        .headers_mut()
                        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
                    response
                }
                Err(message) => error(StatusCode::BAD_REQUEST, &message),
            };
        }
        if update {
            crate::updater::start(
                state.app.clone(),
                state.connection.client.clone(),
                state.connection.origin.clone(),
                state.cancel.clone(),
                input["interactive"].as_bool().unwrap_or(true),
            );
        } else if input["favorite_id"].as_u64().unwrap_or(0) > 0 {
            if let Err(message) = crate::favorite_auth::open(
                &state.app,
                connection,
                input["favorite_id"].as_u64().unwrap_or(0),
                input["mode"].as_str().unwrap_or("client"),
                &state.owner,
                &authorization_cancel,
            )
            .await
            {
                return error(StatusCode::BAD_REQUEST, &message);
            }
        } else if let Err(message) = crate::external::open(
            &state.app,
            input["url"].as_str().unwrap_or(""),
            input["mode"].as_str().unwrap_or("client"),
        ) {
            return error(StatusCode::BAD_REQUEST, &message);
        }
        let mut response = Response::new(Body::from("{\"ok\":true}"));
        response.headers_mut().insert(
            header::CONTENT_TYPE,
            HeaderValue::from_static("application/json"),
        );
        return response;
    }
    if path.starts_with("/api/credential-vault/") || path.starts_with("/api/client-agent/") {
        return error(
            StatusCode::FORBIDDEN,
            "凭据同步只能通过原生安全凭据入口操作",
        );
    }
    if matches!(
        path,
        "/api/assistant/ask" | "/api/assistant/threads" | "/api/assistant/turns"
    ) {
        return error(StatusCode::FORBIDDEN, "请通过客户端 Agent 使用本机会话");
    }
    if path.starts_with("/api/") || path.starts_with("/files/") {
        return proxy(state, request, authorization_cancel).await;
    }
    if request.method() != axum::http::Method::GET && request.method() != axum::http::Method::HEAD {
        return error(StatusCode::METHOD_NOT_ALLOWED, "不支持的请求方式");
    }
    let asset_path = match path {
        "/" | "/pc" | "/pc/" => "index.html",
        _ => path.strip_prefix("/pc/").unwrap_or(""),
    };
    if let Some(asset) = PcAssets::get(asset_path) {
        let mut response = Response::new(if request.method() == axum::http::Method::HEAD {
            Body::empty()
        } else {
            Body::from(asset.data.into_owned())
        });
        let mime = mime_guess::from_path(asset_path).first_or_octet_stream();
        if let Ok(value) = HeaderValue::from_str(mime.as_ref()) {
            response.headers_mut().insert(header::CONTENT_TYPE, value);
        }
        response
            .headers_mut()
            .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
        response
            .headers_mut()
            .insert("referrer-policy", HeaderValue::from_static("same-origin"));
        response.headers_mut().insert(
            "x-content-type-options",
            HeaderValue::from_static("nosniff"),
        );
        return response;
    }
    error(StatusCode::NOT_FOUND, "客户端资源不存在，请更新客户端")
}

/// 转发学校接口和文件流，令牌只保存在 Rust 会话容器中。
async fn proxy(
    state: Arc<GatewayState>,
    request: Request,
    authorization_cancel: tokio_util::sync::CancellationToken,
) -> Response<Body> {
    let (parts, body) = request.into_parts();
    let authentication = parts.method == axum::http::Method::POST
        && matches!(
            parts.uri.path(),
            "/api/auth/login"
                | "/api/auth/register"
                | "/api/auth/passkey-login"
                | "/api/auth/wechat-login/consume"
                | "/api/auth/switch-account"
        );
    let session_change = authentication
        || parts.method == axum::http::Method::POST
            && matches!(
                parts.uri.path(),
                "/api/auth/logout" | "/api/auth/password-recovery/reset"
            );
    let serialized =
        session_change || matches!(parts.uri.path(), "/api/auth/refresh" | "/api/auth/context");
    if session_change {
        crate::office_preview::cancel_owner(&state.owner);
        crate::client_agent::cancel_owner(&state.owner);
    }
    let (_reader, _writer) = if serialized {
        (None, Some(state.connection.session_lock.write().await))
    } else {
        (Some(state.connection.session_lock.read().await), None)
    };
    if state.cancel.is_cancelled() || authorization_cancel.is_cancelled() {
        return error(StatusCode::GONE, "学校账号已变更，请重新操作");
    }
    if state.lock.is_locked() && !locked_request_allowed(parts.uri.path()) {
        return error(StatusCode::LOCKED, "客户端已锁定，请先验证当前账号");
    }
    let login_method = match parts.uri.path() {
        "/api/auth/login" => Some("password"),
        "/api/auth/wechat-login/consume" => Some("wechat"),
        _ => None,
    };
    let mut target = state.connection.origin.clone();
    target.set_path(parts.uri.path());
    target.set_query(parts.uri.query());
    let mut upstream = state.connection.client.request(parts.method, target);
    for (name, value) in &parts.headers {
        if !blocked_header(name.as_str(), &parts.headers)
            && !(serialized && name.as_str() == "accept-encoding")
            && !matches!(
                name.as_str(),
                "host"
                    | "cookie"
                    | "user-agent"
                    | "origin"
                    | "referer"
                    | "forwarded"
                    | "x-forwarded-for"
                    | "x-forwarded-host"
                    | "x-forwarded-proto"
            )
        {
            upstream = upstream.header(name, value);
        }
    }
    upstream = upstream
        .header(
            header::ORIGIN,
            state.connection.origin.origin().ascii_serialization(),
        )
        .header(
            header::REFERER,
            format!("{}pc/index.html", state.connection.origin),
        )
        .body(reqwest::Body::wrap_stream(body.into_data_stream()));
    if serialized {
        upstream = upstream.header(header::ACCEPT_ENCODING, "identity");
    }
    let response = match tokio::select! {
        _ = authorization_cancel.cancelled() => return error(StatusCode::LOCKED, "客户端已锁定或账号已变更"),
        response = upstream.send() => response,
    } {
        Ok(response) => response,
        Err(error_value) => {
            return error(
                if error_value.is_timeout() {
                    StatusCode::GATEWAY_TIMEOUT
                } else {
                    StatusCode::BAD_GATEWAY
                },
                "学校服务器连接中断，请检查网络后重试",
            )
        }
    };
    let status = response.status();
    let cookie_changed = session_change && authentication_cookie_changed(response.headers());
    if cookie_changed {
        invalidate_authorization_context(&state).await;
    }
    let mut outgoing = HeaderMap::new();
    for (name, value) in response.headers() {
        if !blocked_header(name.as_str(), response.headers())
            && !matches!(
                name.as_str(),
                "set-cookie"
                    | "location"
                    | "access-control-allow-origin"
                    | "access-control-allow-credentials"
            )
        {
            outgoing.append(name, value.clone());
        }
    }
    if let Some(location) = response.headers().get(header::LOCATION) {
        let mapped = location
            .to_str()
            .ok()
            .and_then(|location| state.connection.origin.join(location).ok())
            .filter(|url| url.origin() == state.connection.origin.origin())
            .and_then(|url| local_url(&state.local, &url).ok());
        let Some(location) = mapped.and_then(|url| HeaderValue::from_str(url.as_str()).ok()) else {
            return error(
                StatusCode::BAD_GATEWAY,
                "学校接口跳转到其他站点，请检查学校域名配置",
            );
        };
        outgoing.insert(header::LOCATION, location);
    }
    outgoing.insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    let body = if serialized {
        let bytes = match tokio::select! {
            _ = authorization_cancel.cancelled(), if !session_change => return error(StatusCode::LOCKED, "客户端已锁定或账号已变更"),
            bytes = login_response_body(response) => bytes,
        } {
            Ok(bytes) => bytes,
            Err(message) => return error(StatusCode::BAD_GATEWAY, &message),
        };
        if !session_change && !state.cancel.is_cancelled() && !authorization_cancel.is_cancelled() {
            if status == StatusCode::UNAUTHORIZED {
                state.lock.clear_context();
            }
        }
        if !session_change
            && status.is_success()
            && !state.cancel.is_cancelled()
            && !authorization_cancel.is_cancelled()
        {
            if let Ok(value) = serde_json::from_slice::<serde_json::Value>(&bytes) {
                if value["code"].as_i64() == Some(40100) {
                    state.lock.clear_context();
                }
                if value["code"].as_i64() == Some(0) {
                    let context = if value["data"]["context"].is_object() {
                        &value["data"]["context"]
                    } else {
                        &value["data"]
                    };
                    state.lock.remember_context(context);
                }
            }
        }
        if session_change && status.is_success() {
            if !cookie_changed {
                renew_authorization_context(&state, &bytes, authentication).await;
            }
            if authentication {
                remember_authenticated_account(&state, &bytes, login_method).await;
            }
        }
        Body::from(bytes)
    } else {
        cancellable_body(response, authorization_cancel)
    };
    let mut result = Response::new(body);
    *result.status_mut() = status;
    *result.headers_mut() = outgoing;
    result
}

/// 同一次原生操作使用锁内确认的账号上下文。
async fn local_account_operation<'a>(
    state: &'a GatewayState,
    authorization_cancel: &tokio_util::sync::CancellationToken,
) -> Result<(Connection, RwLockReadGuard<'a, ()>), String> {
    let session = state.connection.session_lock.read().await;
    if state.cancel.is_cancelled() || authorization_cancel.is_cancelled() || state.lock.is_locked()
    {
        return Err("客户端已锁定或学校账号已变更".into());
    }
    let (context, _) = state.lock.password_context(false)?;
    let mut connection = state.connection.clone();
    connection.operation_context = Some(context);
    Ok((connection, session))
}

async fn account_operation<'a>(
    state: &'a GatewayState,
    authorization_cancel: &tokio_util::sync::CancellationToken,
) -> Result<(Connection, RwLockReadGuard<'a, ()>), String> {
    let session = state.connection.session_lock.write().await;
    if state.cancel.is_cancelled() || authorization_cancel.is_cancelled() {
        return Err("学校账号已变更，请重新操作".into());
    }
    if state.lock.is_locked() {
        return Err("客户端已锁定，请先验证当前账号".into());
    }
    let context = crate::school_api::read_context_guarded(&state.connection, &session).await?;
    if state.lock.is_locked() || state.cancel.is_cancelled() || authorization_cancel.is_cancelled()
    {
        return Err("学校账号已变更，请重新操作".into());
    }
    if context["account_id"].as_u64().unwrap_or(0) == 0 {
        state.lock.clear_context();
        return Err("请先登录有效学校账号".into());
    }
    state.lock.remember_context(&context);
    let mut connection = state.connection.clone();
    connection.operation_context = Some(context);
    Ok((
        connection,
        tokio::sync::RwLockWriteGuard::downgrade(session),
    ))
}

async fn login_response_body(mut response: reqwest::Response) -> Result<Vec<u8>, String> {
    let mut bytes = Vec::new();
    while let Some(chunk) = response.chunk().await.map_err(|_| "读取学校登录响应失败")? {
        if bytes.len() + chunk.len() > 1024 * 1024 {
            return Err("学校登录响应过大，请联系管理员".into());
        }
        bytes.extend_from_slice(&chunk);
    }
    Ok(bytes)
}

/// 认证成功后结束原账号的授权窗口和未完成请求。
async fn renew_authorization_context(state: &GatewayState, body: &[u8], authenticated: bool) {
    let Ok(value) = serde_json::from_slice::<serde_json::Value>(body) else {
        return;
    };
    if value["code"].as_i64() != Some(0)
        || authenticated && value["data"]["context"]["account_id"].as_u64().unwrap_or(0) == 0
    {
        return;
    }
    invalidate_authorization_context(state).await;
}

/// Cookie 身份边界已变更时立即结束旧窗口和请求代次。
async fn invalidate_authorization_context(state: &GatewayState) {
    crate::client_agent::cancel_owner(&state.owner);
    {
        let mut cancel = state.authorization_cancel.lock().await;
        cancel.cancel();
        *cancel = state.cancel.child_token();
    }
    state.lock.clear_context();
    crate::windows::reset_account_windows(&state.app, &state.owner);
}

/// 仅按学校成功认证的账号更新本机连接记录。
async fn remember_authenticated_account(
    state: &GatewayState,
    body: &[u8],
    login_method: Option<&str>,
) {
    let Ok(value) = serde_json::from_slice::<serde_json::Value>(body) else {
        return;
    };
    if value["code"].as_i64() != Some(0)
        || value["data"]["context"]["account_id"].as_u64().unwrap_or(0) == 0
    {
        return;
    }
    let Some(server_username) = value["data"]["context"]["login_name"]
        .as_str()
        .filter(|username| !username.trim().is_empty())
    else {
        return;
    };
    if let Err(message) = state.lock.authenticated(
        &state.app,
        &value["data"]["context"],
        matches!(login_method, Some("password" | "wechat")),
    ) {
        state
            .app
            .dialog()
            .message(message)
            .title("本机锁定设置")
            .show(|_| {});
    }
    let username = server_username;
    let mut profile = state.profile.lock().await;
    if state.cancel.is_cancelled() {
        return;
    }
    let mut updated = profile.clone();
    updated.username = username.to_owned();
    if let Some(method) = login_method {
        updated.login_method = method.to_owned();
    }
    updated.auto_login = updated.login_method == "password"
        && updated.username == profile.username
        && updated.auto_login;
    let result = (|| {
        let mut settings = connection::load(&state.app)?;
        settings.last_origin = updated.origin.clone();
        settings.last_username = updated.username.clone();
        settings.schools.retain(|school| {
            school.origin != updated.origin
                || (school.username != updated.username
                    && !(profile.username.is_empty() && school.username.is_empty()))
        });
        settings.schools.insert(0, updated.clone());
        connection::save(&state.app, &settings)
    })();
    match result {
        Ok(()) => *profile = updated,
        Err(message) => state
            .app
            .dialog()
            .message(format!("已登录，但学校连接记录未保存：{message}"))
            .title("保存学校连接")
            .show(|_| {}),
    }
}

fn authentication_cookie_changed(headers: &reqwest::header::HeaderMap) -> bool {
    headers
        .get_all(reqwest::header::SET_COOKIE)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .any(|value| {
            value
                .split_once('=')
                .is_some_and(|(name, _)| matches!(name.trim(), "jwt" | "refresh_jwt"))
        })
}

/// 过滤逐跳传输头及 Connection 声明的头。
fn blocked_header(name: &str, headers: &HeaderMap) -> bool {
    matches!(
        name,
        "connection"
            | "keep-alive"
            | "proxy-authenticate"
            | "proxy-authorization"
            | "te"
            | "trailer"
            | "transfer-encoding"
            | "upgrade"
    ) || headers
        .get(header::CONNECTION)
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| {
            value
                .split(',')
                .any(|item| item.trim().eq_ignore_ascii_case(name))
        })
}

/// 保留学校相对路径，将站内地址映射为本机会话地址。
pub fn local_url(local: &Url, remote: &Url) -> Result<Url, String> {
    let mut url = local.clone();
    url.set_path(remote.path());
    url.set_query(remote.query());
    url.set_fragment(remote.fragment());
    Ok(url)
}

/// 返回与网页接口一致的错误格式。
fn local_json(data: serde_json::Value) -> Response<Body> {
    let mut response = Response::new(Body::from(data.to_string()));
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_static("application/json"),
    );
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response
}

fn error(status: StatusCode, message: &str) -> Response<Body> {
    let mut response = Response::new(Body::from(
        serde_json::json!({"code": status.as_u16() as u32 * 100, "message": message, "data": null})
            .to_string(),
    ));
    *response.status_mut() = status;
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_static("application/json; charset=utf-8"),
    );
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response
}

fn locked_request_allowed(path: &str) -> bool {
    matches!(
        path,
        "/_desktop/window" | "/api/auth/context" | "/api/auth/refresh" | "/api/auth/logout"
    )
}

async fn lock_session(state: &GatewayState) -> Result<serde_json::Value, String> {
    let app_state = state.app.state::<crate::AppState>();
    if app_state
        .connecting
        .swap(true, std::sync::atomic::Ordering::AcqRel)
    {
        return Err("正在处理学校连接，请稍后锁定".into());
    }
    let _guard = crate::ConnectingGuard(&app_state.connecting);
    if !crate::school_owner_current(&state.app, &state.owner) {
        return Err("学校连接已变更，请重新操作".into());
    }
    if state.cancel.is_cancelled() {
        return Err("学校连接已关闭".into());
    }
    if state.lock.is_locked() {
        return state.lock.status();
    }
    let persisted = state.lock.begin(&state.app, &state.owner);
    if !state.lock.is_locked() {
        return persisted.and_then(|_| state.lock.status());
    }
    crate::client_agent::cancel_owner(&state.owner);
    {
        let mut cancel = state.authorization_cancel.lock().await;
        cancel.cancel();
        *cancel = state.cancel.child_token();
    }
    let session = state.connection.session_lock.write().await;
    if state.cancel.is_cancelled() {
        return Err("学校连接已关闭".into());
    }
    let context = crate::school_api::read_context_guarded(&state.connection, &session).await;
    let context = match context {
        Ok(context)
            if context["account_id"].as_u64().unwrap_or(0) > 0
                && context["device_jti"]
                    .as_str()
                    .is_none_or(|jti| jti.is_empty()) =>
        {
            crate::school_api::read_context_guarded(&state.connection, &session).await
        }
        result => result,
    };
    match context {
        Ok(context) => state.lock.freeze(&state.app, &context)?,
        Err(message) => {
            state.lock.freeze(&state.app, &serde_json::json!({})).ok();
            return Err(format!("{message}；请退出原会话后重新登录"));
        }
    }
    persisted?;
    state.lock.status()
}

async fn lock_request(state: Arc<GatewayState>, request: Request) -> Response<Body> {
    if request.method() != axum::http::Method::POST
        || request
            .headers()
            .get(header::ORIGIN)
            .and_then(|v| v.to_str().ok())
            != Some(state.local.origin().ascii_serialization().as_str())
    {
        return error(StatusCode::FORBIDDEN, "仅学校页面可以调用本机锁定");
    }
    let bytes = match axum::body::to_bytes(request.into_body(), 2 * 1024 * 1024).await {
        Ok(bytes) => bytes,
        Err(_) => return error(StatusCode::BAD_REQUEST, "参数过长"),
    };
    let input: serde_json::Value = match serde_json::from_slice(&bytes) {
        Ok(input) => input,
        Err(_) => return error(StatusCode::BAD_REQUEST, "参数无效"),
    };
    let result = lock_action(&state, &input).await;
    match result {
        Ok(data) => {
            let mut response = Response::new(Body::from(data.to_string()));
            response.headers_mut().insert(
                header::CONTENT_TYPE,
                HeaderValue::from_static("application/json"),
            );
            response
                .headers_mut()
                .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
            response
        }
        Err(message) => {
            let message = if state.lock.is_locked()
                && (message.contains("过期")
                    || message.contains("禁用")
                    || message.contains("停用")
                    || message.contains("请先登录")
                    || message.contains("登录状态"))
            {
                format!("{message}；请退出原会话后重新登录")
            } else {
                message
            };
            error(StatusCode::BAD_REQUEST, &message)
        }
    }
}

async fn lock_action(
    state: &GatewayState,
    input: &serde_json::Value,
) -> Result<serde_json::Value, String> {
    let action = input["action"].as_str().unwrap_or("");
    if state.cancel.is_cancelled() {
        return Err("学校连接已关闭，请重新连接".into());
    }
    match action {
        "status" => return state.lock.status(),
        "manual" => return lock_session(state).await,
        "activity" => {
            state.lock.activity();
            return state.lock.status();
        }
        _ => {}
    }
    if action == "exit-session" {
        let app_state = state.app.state::<crate::AppState>();
        if app_state
            .connecting
            .swap(true, std::sync::atomic::Ordering::AcqRel)
        {
            return Err("正在处理学校连接，请稍后重试".into());
        }
        let _guard = crate::ConnectingGuard(&app_state.connecting);
        let _session = state.connection.session_lock.write().await;
        logout_source(&state.connection.client, &state.connection.origin).await?;
        invalidate_authorization_context(state).await;
        let mut active = app_state.gateway.lock().map_err(|_| "连接状态不可用")?;
        if active
            .as_ref()
            .is_some_and(|gateway| gateway.owner == state.owner)
        {
            active.take();
        }
        drop(active);
        state.lock.restore_ordinary(&state.app);
        crate::windows::close_school(&state.app, &state.owner);
        crate::windows::show_connection(&state.app);
        return Ok(serde_json::json!({"exited": true}));
    }
    if matches!(
        action,
        "local-password-status"
            | "local-password-set"
            | "local-password-clear"
            | "local-password-unlock"
    ) {
        return local_password_action(state, input).await;
    }
    let _session = state.connection.session_lock.write().await;
    if state.cancel.is_cancelled() {
        return Err("学校连接已关闭，请重新连接".into());
    }
    if action == "settings" {
        return state.lock.settings(&state.app, input);
    }
    if action == "wechat-cancel" {
        let id = input["session_id"].as_str().ok_or("扫码会话无效")?;
        state.lock.cancel_wechat(id)?;
        let data = crate::school_api::auth_post(
            &state.connection,
            "api/auth/desktop-lock/wechat/cancel",
            &serde_json::json!({"session_id": id}),
        )
        .await?;
        if state.cancel.is_cancelled() {
            return Err("学校连接已关闭，请重新连接".into());
        }
        return Ok(data);
    }
    if !state.lock.is_locked() {
        return Err("客户端当前未锁定".into());
    }
    let data = match action {
        "password-unlock" => {
            crate::school_api::auth_post(
                &state.connection,
                "api/auth/desktop-lock/password-unlock",
                &serde_json::json!({"password": input["password"]}),
            )
            .await?
        }
        "wechat-create" => {
            let revision = state.lock.begin_wechat()?;
            let data = crate::school_api::auth_post(
                &state.connection,
                "api/auth/desktop-lock/wechat/create",
                &serde_json::json!({}),
            )
            .await?;
            if state.cancel.is_cancelled() {
                return Err("学校连接已关闭，请重新连接".into());
            }
            let id = data["session_id"].as_str().ok_or("扫码解锁响应无效")?;
            state.lock.remember_wechat(id, revision)?;
            data
        }
        "wechat-status" => {
            let id = input["session_id"].as_str().ok_or("扫码会话无效")?;
            state.lock.validate_wechat(id)?;
            let mut endpoint = state
                .connection
                .origin
                .join("api/auth/desktop-lock/wechat/status")
                .map_err(|_| "扫码接口地址无效")?;
            endpoint.query_pairs_mut().append_pair("session_id", id);
            crate::school_api::read(&state.connection, endpoint.as_str()).await?
        }
        "wechat-consume" => {
            let id = input["session_id"].as_str().ok_or("扫码会话无效")?;
            state.lock.validate_wechat(id)?;
            crate::school_api::auth_post(
                &state.connection,
                "api/auth/desktop-lock/wechat/consume",
                &serde_json::json!({"session_id": id}),
            )
            .await?
        }
        "recovery-request" => {
            let path = input["path"].as_str().unwrap_or("");
            let method = input["method"].as_str().unwrap_or("GET");
            let payload = input
                .get("payload")
                .cloned()
                .unwrap_or(serde_json::json!({}));
            match (path, method) {
                ("/auth/password-recovery/wechat/status", "GET") => {
                    let mut endpoint = state
                        .connection
                        .origin
                        .join("api/auth/password-recovery/wechat/status")
                        .map_err(|_| "找回接口地址无效")?;
                    endpoint.query_pairs_mut().append_pair(
                        "session_id",
                        payload["session_id"].as_str().ok_or("扫码会话无效")?,
                    );
                    crate::school_api::read(&state.connection, endpoint.as_str()).await?
                }
                (
                    "/auth/password-recovery/wechat/create"
                    | "/auth/password-recovery/wechat/cancel"
                    | "/auth/password-recovery/reset",
                    "POST",
                ) => {
                    crate::school_api::auth_post(&state.connection, &format!("api{path}"), &payload)
                        .await?
                }
                _ => return Err("不允许的密码找回操作".into()),
            }
        }
        _ => return Err("本机锁定操作无效".into()),
    };
    if state.cancel.is_cancelled() {
        return Err("学校连接已关闭，请重新连接".into());
    }
    if action == "recovery-request"
        && input["path"].as_str() == Some("/auth/password-recovery/reset")
    {
        {
            let mut cancel = state.authorization_cancel.lock().await;
            cancel.cancel();
            *cancel = state.cancel.child_token();
        }
        state.lock.clear_context();
        crate::windows::close_account_windows(&state.app, &state.owner);
        crate::windows::notify_lock(&state.app);
    }
    if matches!(action, "password-unlock" | "wechat-consume") {
        return state.lock.unlock(&state.app, &state.owner, &data);
    }
    Ok(data)
}

async fn local_password_action(
    state: &GatewayState,
    input: &serde_json::Value,
) -> Result<serde_json::Value, String> {
    let action = input["action"].as_str().unwrap_or("").to_owned();
    let allowed: &[&str] = if action == "local-password-set" {
        &["action", "password", "confirmation"]
    } else if action == "local-password-unlock" {
        &["action", "password"]
    } else {
        &["action"]
    };
    if input
        .as_object()
        .is_none_or(|object| object.keys().any(|key| !allowed.contains(&key.as_str())))
    {
        return Err("本机密码参数无效".into());
    }
    let lease = crate::local_lock_password::reserve()?;
    let unlocking = action == "local-password-unlock";
    let changing = matches!(
        action.as_str(),
        "local-password-set" | "local-password-clear"
    );
    let (expected, revision) = {
        let session = state.connection.session_lock.write().await;
        if state.cancel.is_cancelled() {
            return Err("学校连接已关闭，请重新连接".into());
        }
        let (expected, revision) = state.lock.password_context(unlocking)?;
        if !unlocking {
            let context =
                crate::school_api::read_context_guarded(&state.connection, &session).await?;
            if ["account_id", "school_id", "device_jti"]
                .iter()
                .any(|key| expected[*key] != context[*key])
            {
                return Err("学校账号已变更，请重新登录后设置本机密码".into());
            }
            let (_, current_revision) = state.lock.password_context(false)?;
            if revision != current_revision || state.cancel.is_cancelled() {
                return Err("学校会话已变更，请重新操作本机密码".into());
            }
        }
        (expected, revision)
    };
    let key = crate::local_lock_password::scope(&state.connection.origin, &expected)?;
    let password = zeroize::Zeroizing::new(input["password"].as_str().unwrap_or("").to_owned());
    if action == "local-password-set" && input["confirmation"].as_str() != Some(password.as_str()) {
        return Err("两次输入的本机锁屏密码不一致".into());
    }
    let store = state.lock.local_password.clone();
    let operation = tauri::async_runtime::spawn_blocking(move || {
        let result = match action.as_str() {
            "local-password-status" => store.exists(&key),
            "local-password-set" => store.set(&key, &password),
            "local-password-clear" => store.clear(&key),
            "local-password-unlock" => store.verify(&key, &password),
            _ => Err("本机密码操作无效".into()),
        };
        (result, lease)
    });
    let (result, _lease) = tokio::select! {
        _ = state.cancel.cancelled() => return Err("学校连接已关闭，本机密码操作可能仍在系统处理中".into()),
        result = tokio::time::timeout(std::time::Duration::from_secs(15), operation) => result
            .map_err(|_| "系统凭据库响应超时，本机密码操作可能仍在处理中。请完成或取消系统授权后刷新状态；可使用账号密码或企业微信解锁")?
            .map_err(|_| "本机密码操作未完成，请使用其他解锁方式")?,
    };
    let configured = result?;
    let session = state.connection.session_lock.write().await;
    if state.cancel.is_cancelled() || !crate::school_owner_current(&state.app, &state.owner) {
        return Err("学校连接已变更，请重新操作".into());
    }
    let (current, current_revision) =
        state.lock.password_context(unlocking).map_err(|message| {
            if changing {
                format!("{message}；本机密码操作可能已完成，请解锁后刷新状态确认")
            } else {
                message
            }
        })?;
    if revision != current_revision
        || ["account_id", "school_id", "device_jti"]
            .iter()
            .any(|key| expected[*key] != current[*key])
    {
        return Err(if changing {
            "锁定状态或学校账号已变更；本机密码操作可能已完成，请解锁后刷新状态确认"
        } else {
            "锁定状态或学校账号已变更，请重新操作"
        }
        .into());
    }
    if unlocking {
        let context = crate::school_api::read_context_guarded(&state.connection, &session).await?;
        if state.cancel.is_cancelled() {
            return Err("学校连接已关闭，请重新连接".into());
        }
        return state.lock.unlock(
            &state.app,
            &state.owner,
            &serde_json::json!({"verified": configured, "context": context}),
        );
    }
    let mut status = state.lock.status()?;
    status["local_password_set"] = configured.into();
    Ok(status)
}

pub(crate) fn cancellable_body(
    mut response: reqwest::Response,
    cancel: tokio_util::sync::CancellationToken,
) -> Body {
    use tokio::io::AsyncWriteExt;
    let (mut writer, reader) = tokio::io::duplex(65536);
    tauri::async_runtime::spawn(async move {
        loop {
            let chunk = tokio::select! {
                _ = cancel.cancelled() => break,
                chunk = response.chunk() => chunk,
            };
            let Ok(Some(chunk)) = chunk else {
                break;
            };
            let write = tokio::select! {
                _ = cancel.cancelled() => break,
                result = writer.write_all(&chunk) => result,
            };
            if write.is_err() {
                break;
            }
        }
    });
    Body::from_stream(tokio_util::io::ReaderStream::new(reader))
}

pub(crate) fn cancellable_reader<R>(
    mut input: R,
    cancel: tokio_util::sync::CancellationToken,
) -> Body
where
    R: tokio::io::AsyncRead + Unpin + Send + 'static,
{
    let (mut writer, reader) = tokio::io::duplex(65536);
    tauri::async_runtime::spawn(async move {
        tokio::select! {
            _ = cancel.cancelled() => {},
            _ = tokio::io::copy(&mut input, &mut writer) => {},
        }
    });
    Body::from_stream(tokio_util::io::ReaderStream::new(reader))
}

#[cfg(test)]
mod logout_tests {
    use super::logout_source;
    use axum::{
        http::{header, HeaderMap, StatusCode},
        routing::post,
        Router,
    };
    use reqwest::{cookie::Jar, Client};
    use std::sync::Arc;
    use url::Url;

    #[tokio::test]
    async fn waits_for_authenticated_source_logout_and_rejects_failure() {
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let origin = Url::parse(&format!("http://{}/", listener.local_addr().unwrap())).unwrap();
        let expected_origin = origin.origin().ascii_serialization();
        let router = Router::new().route(
            "/api/auth/logout",
            post(move |headers: HeaderMap| {
                let expected_origin = expected_origin.clone();
                async move {
                    let has_cookie = headers
                        .get(header::COOKIE)
                        .and_then(|value| value.to_str().ok())
                        .is_some_and(|value| value.contains("refresh_jwt=school-a"));
                    let same_origin = headers
                        .get(header::ORIGIN)
                        .and_then(|value| value.to_str().ok())
                        == Some(expected_origin.as_str());
                    if has_cookie && same_origin {
                        (StatusCode::OK, r#"{"code":0,"message":"ok","data":null}"#)
                    } else {
                        (
                            StatusCode::FORBIDDEN,
                            r#"{"code":40300,"message":"forbidden","data":null}"#,
                        )
                    }
                }
            }),
        );
        let server = tokio::spawn(async move {
            axum::serve(listener, router).await.unwrap();
        });
        let jar = Arc::new(Jar::default());
        jar.add_cookie_str("refresh_jwt=school-a; Path=/", &origin);
        let client = Client::builder().cookie_provider(jar).build().unwrap();
        assert!(logout_source(&client, &origin).await.is_ok());
        assert!(logout_source(&Client::new(), &origin).await.is_err());
        server.abort();
        assert!(logout_source(&client, &origin).await.is_err());
    }
}
