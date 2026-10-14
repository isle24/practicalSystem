use crate::connection::{self, Connection};
use base64::Engine;
use serde::Serialize;
use serde_json::Value;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};
use tokio::sync::OnceCell;
use tokio_util::sync::CancellationToken;
use url::Url;

#[derive(Clone, Serialize)]
pub struct LoginCaptcha {
    id: String,
    image: String,
    expires_in: u64,
}

#[derive(Clone, Default, Serialize)]
pub struct CaptchaState {
    pub captcha_required: bool,
    pub captcha: Option<LoginCaptcha>,
    pub triggers: Vec<String>,
    pub retry_after: Option<u64>,
}

#[derive(Serialize)]
pub struct LoginError {
    pub message: String,
    #[serde(flatten)]
    pub challenge: CaptchaState,
    #[serde(skip)]
    pub uncertain_session: bool,
}

impl From<String> for LoginError {
    fn from(message: String) -> Self {
        Self {
            message,
            challenge: CaptchaState::default(),
            uncertain_session: false,
        }
    }
}

impl From<&str> for LoginError {
    fn from(message: &str) -> Self {
        message.to_owned().into()
    }
}

impl LoginError {
    fn uncertain(message: &str) -> Self {
        Self {
            uncertain_session: true,
            ..message.into()
        }
    }
}

pub struct PreparedLogin {
    pub scope: String,
    pub username: String,
    origin: Url,
    port: u16,
    created: Instant,
    connection: OnceCell<Connection>,
    pub cancel: CancellationToken,
    cleaned: AtomicBool,
}

impl PreparedLogin {
    pub fn new(scope: String, username: String, origin: Url, port: u16) -> Self {
        Self {
            scope,
            username,
            origin,
            port,
            created: Instant::now(),
            connection: OnceCell::new(),
            cancel: CancellationToken::new(),
            cleaned: AtomicBool::new(false),
        }
    }

    pub fn matches(&self, scope: &str, username: &str, origin: &Url) -> bool {
        self.scope == scope
            && self.username == username
            && self.origin.origin() == origin.origin()
            && !self.cancel.is_cancelled()
            && self.created.elapsed() < Duration::from_secs(600)
    }

    pub async fn connection(&self) -> Result<&Connection, LoginError> {
        if self.cancel.is_cancelled() || self.created.elapsed() >= Duration::from_secs(600) {
            return Err("登录连接已取消或过期，请重新连接".into());
        }
        self.connection
            .get_or_try_init(|| {
                connection::connect_with_cancel(self.origin.clone(), self.port, self.cancel.clone())
            })
            .await
            .map_err(LoginError::from)
    }

    pub async fn create_captcha(&self) -> Result<CaptchaState, LoginError> {
        let connection = self.connection().await?;
        let _session = connection.session_lock.write().await;
        let value = post(
            connection,
            "api/auth/login-captcha",
            &serde_json::json!({"login_name": self.username}),
        )
        .await?;
        let challenge = challenge(&value["data"])?;
        if challenge.captcha.is_none() {
            return Err("学校验证码响应无效，请重新获取".into());
        }
        Ok(challenge)
    }

    pub async fn authenticate(
        &self,
        password: &str,
        captcha_id: Option<&str>,
        captcha_code: Option<&str>,
    ) -> Result<Value, LoginError> {
        let connection = self.connection().await?;
        let session = connection.session_lock.write().await;
        let value = post(
            connection,
            "api/auth/login",
            &serde_json::json!({
                "login_name": self.username, "password": password, "client": "WEB",
                "captcha_id": captcha_id.unwrap_or_default(),
                "captcha_code": captcha_code.unwrap_or_default(),
            }),
        )
        .await?;
        let context = &value["data"]["context"];
        if !authenticated_context(context) {
            return Err(LoginError::uncertain("学校未返回有效登录会话，请重新连接"));
        }
        let confirmed = tokio::select! {
            _ = connection.cancel.cancelled() => return Err(LoginError::uncertain("登录连接已取消，请重新连接")),
            result = crate::school_api::read_context_guarded(connection, &session) => result
                .map_err(|_| LoginError::uncertain("学校登录会话确认失败，请重新连接"))?,
        };
        if !authenticated_context(&confirmed)
            || ["account_id", "school_id", "device_jti", "login_name"]
                .iter()
                .any(|key| context[*key] != confirmed[*key])
        {
            return Err(LoginError::uncertain("学校登录身份发生变化，请重新连接"));
        }
        Ok(confirmed)
    }

    pub async fn cleanup(&self) -> Result<(), String> {
        self.cancel.cancel();
        if self.cleaned.load(Ordering::Acquire) {
            return Ok(());
        }
        if let Some(connection) = self.connection.get() {
            let _session = connection.session_lock.write().await;
            if self.cleaned.load(Ordering::Acquire) {
                return Ok(());
            }
            crate::gateway::logout_source(&connection.client, &connection.origin).await?;
        }
        self.cleaned.store(true, Ordering::Release);
        Ok(())
    }
}

fn authenticated_context(context: &Value) -> bool {
    context["account_id"].as_u64().unwrap_or(0) > 0
        && !context["school_id"].is_null()
        && context["login_name"]
            .as_str()
            .is_some_and(|name| !name.trim().is_empty() && name.len() <= 255)
        && context["device_jti"]
            .as_str()
            .is_some_and(|jti| !jti.is_empty() && jti.len() <= 255)
}

fn challenge(data: &Value) -> Result<CaptchaState, LoginError> {
    let required = data["captcha_required"].as_bool().unwrap_or(false);
    let captcha = if data["captcha"].is_object() {
        let id = data["captcha"]["id"]
            .as_str()
            .filter(|id| {
                !id.is_empty()
                    && id.len() <= 128
                    && id
                        .chars()
                        .all(|ch| ch.is_ascii_alphanumeric() || matches!(ch, '-' | '_'))
            })
            .ok_or("学校验证码标识无效")?;
        let image = data["captcha"]["image"]
            .as_str()
            .filter(|image| image.len() <= 256 * 1024)
            .ok_or("学校验证码图片无效")?;
        let encoded = image
            .strip_prefix("data:image/jpeg;base64,")
            .or_else(|| image.strip_prefix("data:image/png;base64,"))
            .ok_or("学校验证码图片格式无效")?;
        let bytes = base64::engine::general_purpose::STANDARD
            .decode(encoded)
            .map_err(|_| LoginError::from("学校验证码图片无效"))?;
        if !(bytes.starts_with(&[0xff, 0xd8, 0xff]) || bytes.starts_with(b"\x89PNG\r\n\x1a\n")) {
            return Err("学校验证码图片格式无效".into());
        }
        Some(LoginCaptcha {
            id: id.to_owned(),
            image: image.to_owned(),
            expires_in: data["captcha"]["expires_in"]
                .as_u64()
                .filter(|ttl| (1..=600).contains(ttl))
                .ok_or("学校验证码有效期无效")?,
        })
    } else {
        None
    };
    Ok(CaptchaState {
        captcha_required: required,
        captcha,
        triggers: if required {
            vec!["login_failures".into()]
        } else {
            Vec::new()
        },
        retry_after: data["retry_after"].as_u64().filter(|ttl| *ttl <= 86400),
    })
}

async fn post(connection: &Connection, path: &str, input: &Value) -> Result<Value, LoginError> {
    let target = connection
        .origin
        .join(path)
        .map_err(|_| "学校接口地址无效")?;
    let request = connection
        .client
        .post(target)
        .header(
            reqwest::header::ORIGIN,
            connection.origin.origin().ascii_serialization(),
        )
        .header(reqwest::header::ACCEPT_ENCODING, "identity")
        .timeout(Duration::from_secs(30))
        .json(input);
    let mut response = tokio::select! {
        _ = connection.cancel.cancelled() => return Err(LoginError::uncertain("登录连接已取消，请重新连接")),
        response = request.send() => response.map_err(|_| LoginError::uncertain("学校登录接口连接失败，请检查网络后重试"))?,
    };
    let status = response.status();
    let mut bytes = Vec::new();
    loop {
        let chunk = tokio::select! {
            _ = connection.cancel.cancelled() => return Err(LoginError::uncertain("登录连接已取消，请重新连接")),
            chunk = response.chunk() => chunk.map_err(|_| LoginError::uncertain("读取学校登录响应失败，请重试"))?,
        };
        let Some(chunk) = chunk else { break };
        if bytes.len() + chunk.len() > 1024 * 1024 {
            return Err(LoginError::uncertain("学校登录响应过大，请联系管理员"));
        }
        bytes.extend_from_slice(&chunk);
    }
    let value: Value = serde_json::from_slice(&bytes)
        .map_err(|_| LoginError::uncertain("学校登录响应格式无效，请重试"))?;
    if !status.is_success() || value["code"].as_i64() != Some(0) {
        let challenge = challenge(&value["data"])?;
        return Err(LoginError {
            message: value["message"]
                .as_str()
                .unwrap_or("学校登录失败，请重试")
                .chars()
                .filter(|ch| !ch.is_control())
                .take(300)
                .collect(),
            challenge,
            uncertain_session: value["code"].as_i64().is_none()
                || value["code"].as_i64() == Some(0),
        });
    }
    Ok(value)
}
