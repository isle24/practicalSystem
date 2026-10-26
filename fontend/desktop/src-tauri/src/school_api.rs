use crate::connection::Connection;
use serde_json::Value;
use sha2::{Digest, Sha256};

/// 从当前学校会话读取小型鉴权响应。
pub async fn read(connection: &Connection, path: &str) -> Result<Value, String> {
    if connection.cancel.is_cancelled() {
        return Err("学校连接已关闭，请重新连接".into());
    }
    let target = connection
        .origin
        .join(path)
        .map_err(|_| "学校接口地址无效")?;
    let context = target.path() == "/api/auth/context";
    if context {
        if let Some(value) = &connection.operation_context {
            return Ok(value.clone());
        }
    }
    let _session = if context {
        Some(connection.session_lock.write().await)
    } else {
        None
    };
    read_target(connection, target).await
}

/// 调用方已持有学校会话写锁。
pub async fn read_context_guarded(
    connection: &Connection,
    session: &tokio::sync::RwLockWriteGuard<'_, ()>,
) -> Result<Value, String> {
    read_context_guarded_checked(connection, session)
        .await?
        .ok_or_else(|| "学校登录会话已失效，请重新登录".into())
}

pub async fn read_context_guarded_checked(
    connection: &Connection,
    _session: &tokio::sync::RwLockWriteGuard<'_, ()>,
) -> Result<Option<Value>, String> {
    let target = connection
        .origin
        .join("api/auth/context")
        .map_err(|_| "学校接口地址无效")?;
    let response = get(connection, target).await?;
    let status = response.status();
    if status == reqwest::StatusCode::UNAUTHORIZED {
        return Ok(None);
    }
    let value = read_json(response, 1024 * 1024).await?;
    if value["code"].as_i64() == Some(40100) {
        return Ok(None);
    }
    if !status.is_success() || value["code"].as_i64() != Some(0) {
        return Err(value["message"]
            .as_str()
            .unwrap_or("学校身份读取失败")
            .to_owned());
    }
    if value["data"]["account_id"].as_u64().unwrap_or(0) == 0 {
        return Ok(None);
    }
    Ok(Some(value["data"].clone()))
}

async fn read_target(connection: &Connection, target: url::Url) -> Result<Value, String> {
    decode(get(connection, target).await?, 1024 * 1024).await
}

async fn get(connection: &Connection, target: url::Url) -> Result<reqwest::Response, String> {
    if connection.cancel.is_cancelled() {
        return Err("学校连接已关闭，请重新连接".into());
    }
    connection
        .client
        .get(target)
        .timeout(std::time::Duration::from_secs(15))
        .send()
        .await
        .map_err(|_| "学校接口连接失败，请检查网络".into())
}

/// 通过学校 HTTPS 会话发送凭据库操作。
pub async fn post(connection: &Connection, path: &str, input: &Value) -> Result<Value, String> {
    if connection.origin.scheme() != "https" {
        return Err("云同步密码需要学校 HTTPS 连接".into());
    }
    auth_post(connection, path, input).await
}

pub async fn auth_post(
    connection: &Connection,
    path: &str,
    input: &Value,
) -> Result<Value, String> {
    if connection.cancel.is_cancelled() {
        return Err("学校连接已关闭，请重新连接".into());
    }
    let target = connection
        .origin
        .join(path)
        .map_err(|_| "学校接口地址无效")?;
    if target.origin() != connection.origin.origin() {
        return Err("学校接口来源无效".into());
    }
    let response = connection
        .client
        .post(target)
        .header(
            reqwest::header::ORIGIN,
            connection.origin.origin().ascii_serialization(),
        )
        .timeout(std::time::Duration::from_secs(45))
        .json(input)
        .send()
        .await
        .map_err(|_| "学校接口连接失败，请检查网络")?;
    decode(response, 16 * 1024 * 1024).await
}

/// 授权写入必须携带学校签发的 CSRF 值和单次请求标识。
pub async fn sso_post(connection: &Connection, path: &str, input: &Value) -> Result<Value, String> {
    protected_post(connection, path, input, "api/sso/security").await
}

/// Agent 使用独立 CSRF 来源，不依赖单点登录配置。
pub async fn client_agent_post(
    connection: &Connection,
    path: &str,
    input: &Value,
) -> Result<Value, String> {
    protected_post(connection, path, input, "api/client-agent/bootstrap").await
}

pub async fn cli_operation_post(
    connection: &Connection,
    path: &str,
    input: &Value,
) -> Result<Value, String> {
    if !matches!(path, "api/cli/operations/approve" | "api/cli/operations/deny") {
        return Err("业务确认接口无效".into());
    }
    protected_post(connection, path, input, "api/cli/operations/security").await
}

async fn protected_post(
    connection: &Connection,
    path: &str,
    input: &Value,
    security_path: &str,
) -> Result<Value, String> {
    let security = read(connection, security_path).await?;
    let csrf = security["csrf_token"]
        .as_str()
        .ok_or("授权操作校验不可用")?;
    let target = connection
        .origin
        .join(path)
        .map_err(|_| "学校接口地址无效")?;
    if target.origin() != connection.origin.origin() || connection.origin.scheme() != "https" {
        return Err("授权请求需要同源 HTTPS 学校连接".into());
    }
    let response = connection
        .client
        .post(target)
        .header(
            reqwest::header::ORIGIN,
            connection.origin.origin().ascii_serialization(),
        )
        .header("x-csrf-token", csrf)
        .header("x-request-token", uuid::Uuid::new_v4().to_string())
        .timeout(std::time::Duration::from_secs(45))
        .json(input)
        .send()
        .await
        .map_err(|_| "学校授权接口连接失败")?;
    decode(response, 1024 * 1024).await
}

async fn decode(response: reqwest::Response, limit: usize) -> Result<Value, String> {
    let status = response.status();
    let value = read_json(response, limit).await?;
    if !status.is_success() || value["code"].as_i64() != Some(0) {
        return Err(value["message"]
            .as_str()
            .unwrap_or("学校权限校验失败")
            .to_owned());
    }
    Ok(value["data"].clone())
}

async fn read_json(mut response: reqwest::Response, limit: usize) -> Result<Value, String> {
    let mut bytes = Vec::new();
    while let Some(chunk) = response.chunk().await.map_err(|_| "读取学校信息失败")? {
        if bytes.len() + chunk.len() > limit {
            return Err("学校响应过大".into());
        }
        bytes.extend_from_slice(&chunk);
    }
    serde_json::from_slice(&bytes).map_err(|_| "学校接口格式不正确".into())
}

/// 获取服务器确认的当前账号，禁止使用前端自报账号。
pub async fn account(connection: &Connection) -> Result<u64, String> {
    let context = read(connection, "api/auth/context").await?;
    let id = context["account_id"].as_u64().unwrap_or(0);
    if id == 0 {
        return Err("请先登录学校账号".into());
    }
    Ok(id)
}

/// 生成本机资源命名键，不在文件名暴露学校和账号。
pub fn digest(value: &str) -> String {
    digest_bytes(value.as_bytes())
}

pub fn digest_bytes(value: &[u8]) -> String {
    format!("{:x}", Sha256::digest(value))
}
