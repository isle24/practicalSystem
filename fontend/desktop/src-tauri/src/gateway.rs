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
use tauri_plugin_dialog::DialogExt;
use tokio::{
    net::TcpListener,
    sync::{oneshot, Mutex},
};
use url::Url;

#[derive(RustEmbed)]
#[folder = "../dist/pc/"]
struct PcAssets;

pub struct Gateway {
    pub origin: Url,
    pub remote: Url,
    pub entry: Url,
    pub port: u16,
    pub owner: String,
    shutdown: Option<oneshot::Sender<()>>,
    cancel: tokio_util::sync::CancellationToken,
    login_cancel: Option<(reqwest::Client, Url)>,
}

struct GatewayState {
    app: tauri::AppHandle,
    cancel: tokio_util::sync::CancellationToken,
    connection: Connection,
    local: Url,
    cookie: String,
    token: String,
    owner: String,
    credentials: Mutex<Option<(String, String)>>,
    profile: Mutex<School>,
}

impl Drop for Gateway {
    /// 关闭当前学校本机通道。
    fn drop(&mut self) {
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
    pub fn source_session(&self) -> Result<(reqwest::Client, Url), String> {
        self.login_cancel.clone().ok_or("学校会话不可用".into())
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

/// 启动只监听回环地址的会话通道，PC 页面来自安装包。
pub async fn start(
    app: tauri::AppHandle,
    mut connection: Connection,
    reserved_ports: &[u16],
    credentials: Option<(String, String)>,
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
    let cancel = tokio_util::sync::CancellationToken::new();
    let state = Arc::new(GatewayState {
        app,
        cancel: cancel.clone(),
        profile: Mutex::new(connection.school.clone()),
        connection,
        local: local.clone(),
        cookie: format!("practical_desktop_{port}"),
        token,
        owner: owner.clone(),
        credentials: Mutex::new(credentials),
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
        response
            .headers_mut()
            .insert(header::LOCATION, HeaderValue::from_static("/pc/index.html"));
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
    if path == "/_desktop/bootstrap-login" {
        if request.method() != axum::http::Method::POST
            || headers.get(header::ORIGIN).and_then(|v| v.to_str().ok())
                != Some(state.local.origin().ascii_serialization().as_str())
        {
            return error(StatusCode::FORBIDDEN, "仅学校页面可以调用客户端登录");
        }
        let credentials = state.credentials.lock().await.take();
        let Some((login_name, password)) = credentials else {
            let mut result = Response::new(Body::from("{\"attempted\":false}"));
            result.headers_mut().insert(
                header::CONTENT_TYPE,
                HeaderValue::from_static("application/json"),
            );
            result
                .headers_mut()
                .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
            return result;
        };
        let target = match state.connection.origin.join("api/auth/login") {
            Ok(target) => target,
            Err(_) => return error(StatusCode::BAD_GATEWAY, "学校登录地址无效"),
        };
        let response = match state.connection.client.post(target)
            .timeout(std::time::Duration::from_secs(30))
            .header(header::ORIGIN, state.connection.origin.origin().ascii_serialization())
            .header(header::ACCEPT_ENCODING, "identity")
            .json(&serde_json::json!({"login_name": login_name, "password": password, "client": "WEB"}))
            .send().await {
            Ok(response) => response,
            Err(_) => return error(StatusCode::BAD_GATEWAY, "学校登录接口连接失败"),
        };
        let status = response.status();
        let body = match login_response_body(response).await {
            Ok(body) => body,
            Err(_) => return error(StatusCode::BAD_GATEWAY, "读取学校登录响应失败"),
        };
        if status.is_success() {
            remember_authenticated_account(&state, &body, Some("password"), Some(&login_name))
                .await;
        }
        let mut result = Response::new(Body::from(body));
        *result.status_mut() = status;
        result.headers_mut().insert(
            header::CONTENT_TYPE,
            HeaderValue::from_static("application/json; charset=utf-8"),
        );
        result
            .headers_mut()
            .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
        return result;
    }
    if path == "/_desktop/preview-file" && request.method() == axum::http::Method::GET {
        let id = url::form_urlencoded::parse(request.uri().query().unwrap_or("").as_bytes())
            .find(|(key, _)| key == "id")
            .and_then(|(_, value)| value.parse::<u64>().ok())
            .unwrap_or(0);
        return match crate::preview_cache::serve(&state.app, &state.connection, id, headers).await {
            Ok(response) => response,
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
        let bytes = match axum::body::to_bytes(request.into_body(), 65536).await {
            Ok(bytes) => bytes,
            Err(_) => return error(StatusCode::BAD_REQUEST, "参数过长"),
        };
        let input: serde_json::Value = match serde_json::from_slice(&bytes) {
            Ok(input) => input,
            Err(_) => return error(StatusCode::BAD_REQUEST, "参数无效"),
        };
        if sso {
            return match crate::favorite_auth::open_sso(
                &state.app,
                &state.connection,
                input["favorite_id"].as_u64().unwrap_or(0),
                input["mode"].as_str().unwrap_or("client"),
                &state.owner,
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
                crate::credential_vault::configure(&state.connection, &input).await
            } else if credentials {
                crate::favorite_auth::configure(&state.connection, &input).await
            } else {
                crate::preview_cache::configure(&state.app, &input).await
            };
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
                &state.connection,
                input["favorite_id"].as_u64().unwrap_or(0),
                input["mode"].as_str().unwrap_or("client"),
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
    if path.starts_with("/api/credential-vault/") {
        return error(
            StatusCode::FORBIDDEN,
            "凭据同步只能通过原生安全凭据入口操作",
        );
    }
    if path.starts_with("/api/") || path.starts_with("/files/") {
        return proxy(state, request).await;
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
async fn proxy(state: Arc<GatewayState>, request: Request) -> Response<Body> {
    let (parts, body) = request.into_parts();
    let authentication = parts.method == axum::http::Method::POST
        && matches!(
            parts.uri.path(),
            "/api/auth/login" | "/api/auth/wechat-login/consume" | "/api/auth/switch-account"
        );
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
            && !(authentication && name.as_str() == "accept-encoding")
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
    if authentication {
        upstream = upstream.header(header::ACCEPT_ENCODING, "identity");
    }
    let response = match upstream.send().await {
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
    let body = if authentication && status.is_success() {
        let bytes = match login_response_body(response).await {
            Ok(bytes) => bytes,
            Err(message) => return error(StatusCode::BAD_GATEWAY, &message),
        };
        remember_authenticated_account(&state, &bytes, login_method, None).await;
        Body::from(bytes)
    } else {
        Body::from_stream(response.bytes_stream())
    };
    let mut result = Response::new(body);
    *result.status_mut() = status;
    *result.headers_mut() = outgoing;
    result
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

/// 仅按学校成功认证的账号更新本机连接记录。
async fn remember_authenticated_account(
    state: &GatewayState,
    body: &[u8],
    login_method: Option<&str>,
    bootstrap_username: Option<&str>,
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
    let username = bootstrap_username
        .filter(|username| login_method == Some("password") && !username.trim().is_empty())
        .unwrap_or(server_username);
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
