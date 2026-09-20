use reqwest::{cookie::Jar, redirect::Policy, Client};
use serde::{Deserialize, Serialize};
use std::{fs, io::Write, sync::Arc, time::Duration};
use tauri::{AppHandle, Manager};
use url::Url;

#[derive(Clone, Default, Deserialize, Serialize)]
pub struct Settings {
    #[serde(default)]
    pub last_origin: String,
    #[serde(default)]
    pub schools: Vec<School>,
}

#[derive(Clone, Deserialize, Serialize)]
pub struct School {
    pub origin: String,
    pub name: String,
    #[serde(default)]
    pub port: u16,
    #[serde(default)]
    pub username: String,
    #[serde(default)]
    pub auto_login: bool,
}

pub struct Connection {
    pub school: School,
    pub origin: Url,
    pub client: Client,
}

/// 规范化学校域名，只允许 HTTPS 和本机开发 HTTP。
pub fn school_origin(input: &str) -> Result<Url, String> {
    let input = input.trim();
    if input.is_empty() || input.chars().any(char::is_whitespace) {
        return Err("请输入正确的学校域名".into());
    }
    let value = if input.contains("://") {
        input.to_owned()
    } else {
        format!("https://{input}")
    };
    let mut url = Url::parse(&value).map_err(|_| "学校域名格式不正确")?;
    let loopback = matches!(url.host_str(), Some("localhost" | "127.0.0.1" | "[::1]"));
    if !input.contains("://") && loopback {
        url.set_scheme("http").map_err(|_| "学校地址格式不正确")?;
    }
    if url.scheme() != "https" && !(url.scheme() == "http" && loopback) {
        return Err("学校服务器请使用 HTTPS；HTTP 仅支持 localhost、127.0.0.1 或 [::1]".into());
    }
    if url.host_str().is_none()
        || !url.username().is_empty()
        || url.password().is_some()
        || url.query().is_some()
        || url.fragment().is_some()
        || !matches!(url.path(), "/" | "" | "/pc/" | "/pc/index.html")
    {
        return Err("请填写学校域名和可选端口，不包含账号、参数或其他页面路径".into());
    }
    url.set_path("/");
    Ok(url)
}

/// 读取本机学校列表，不读取或保存登录令牌。
pub fn load(app: &AppHandle) -> Result<Settings, String> {
    let path = app
        .path()
        .app_config_dir()
        .map_err(|_| "无法定位客户端配置目录")?
        .join("schools.json");
    match fs::read(path) {
        Ok(bytes) => serde_json::from_slice(&bytes)
            .map_err(|_| "学校连接配置无法读取，请检查配置文件".into()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(Settings::default()),
        Err(_) => Err("无法读取学校连接配置".into()),
    }
}

/// 原子保存学校连接参数。
pub fn save(app: &AppHandle, settings: &Settings) -> Result<(), String> {
    let directory = app
        .path()
        .app_config_dir()
        .map_err(|_| "无法定位客户端配置目录")?;
    fs::create_dir_all(&directory).map_err(|_| "无法创建客户端配置目录")?;
    let mut file = tempfile::NamedTempFile::new_in(&directory).map_err(|_| "无法写入客户端配置")?;
    let bytes = serde_json::to_vec_pretty(settings).map_err(|_| "无法生成学校连接配置")?;
    file.write_all(&bytes).map_err(|_| "保存学校连接配置失败")?;
    file.as_file()
        .sync_all()
        .map_err(|_| "保存学校连接配置失败")?;
    file.persist(directory.join("schools.json"))
        .map_err(|_| "保存学校连接配置失败")?;
    Ok(())
}

/// 生成系统凭据库使用的稳定键名。
fn credential_key(origin: &str, username: &str) -> String {
    format!("{}:{}", origin.trim().to_ascii_lowercase(), username.trim())
}

/// 保存学校账号密码到系统凭据库。
pub fn save_password(origin: &str, username: &str, password: &str) -> Result<(), String> {
    if username.trim().is_empty() || password.is_empty() {
        return Ok(());
    }
    let entry = keyring::Entry::new(
        "com.2iwm.practical.desktop.school",
        &credential_key(origin, username),
    )
    .map_err(|_| "无法初始化系统凭据库")?;
    entry
        .set_password(password)
        .map_err(|_| "无法保存学校账号密码".to_owned())
}

/// 从系统凭据库读取学校账号密码。
pub fn load_password(origin: &str, username: &str) -> Result<Option<String>, String> {
    if username.trim().is_empty() {
        return Ok(None);
    }
    let entry = keyring::Entry::new(
        "com.2iwm.practical.desktop.school",
        &credential_key(origin, username),
    )
    .map_err(|_| "无法初始化系统凭据库")?;
    match entry.get_password() {
        Ok(password) => Ok(Some(password)),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(_) => Err("无法读取已保存的学校账号密码".into()),
    }
}

/// 删除学校账号密码。
pub fn delete_password(origin: &str, username: &str) -> Result<(), String> {
    if username.trim().is_empty() {
        return Ok(());
    }
    let entry = keyring::Entry::new(
        "com.2iwm.practical.desktop.school",
        &credential_key(origin, username),
    )
    .map_err(|_| "无法初始化系统凭据库")?;
    match entry.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(_) => Err("无法删除已保存的学校账号密码".into()),
    }
}

/// 通过学校公开配置接口确认服务器，并创建独立会话容器。
pub async fn connect(origin: Url, port: u16) -> Result<Connection, String> {
    let client = Client::builder()
        .cookie_provider(Arc::new(Jar::default()))
        .redirect(Policy::none())
        .connect_timeout(Duration::from_secs(10))
        .timeout(Duration::from_secs(300))
        .user_agent(format!(
            "PracticalDesktop/{} ({})",
            env!("CARGO_PKG_VERSION"),
            std::env::consts::OS
        ))
        .build()
        .map_err(|_| "无法初始化学校连接")?;
    let endpoint = origin
        .join("api/config/login-page")
        .map_err(|_| "学校地址格式不正确")?;
    let mut response = client
        .get(endpoint)
        .timeout(Duration::from_secs(15))
        .send()
        .await
        .map_err(|_| "连接失败，请检查学校域名、网络和服务器 HTTPS 证书")?;
    if !response.status().is_success() {
        return Err(format!(
            "学校接口返回 {}，请检查域名或服务器状态",
            response.status().as_u16()
        ));
    }
    let mut bytes = Vec::new();
    while let Some(chunk) = response.chunk().await.map_err(|_| "读取学校信息失败")? {
        if bytes.len() + chunk.len() > 65536 {
            return Err("学校配置响应过大，请检查所填域名".into());
        }
        bytes.extend_from_slice(&chunk);
    }
    let payload: serde_json::Value =
        serde_json::from_slice(&bytes).map_err(|_| "该地址未返回实践管理系统的接口数据")?;
    let name = payload
        .get("data")
        .and_then(|data| data.get("school_name"))
        .and_then(|name| name.as_str());
    if payload.get("code").and_then(|code| code.as_i64()) != Some(0)
        || name.is_none_or(|name| name.trim().is_empty())
    {
        return Err("未找到该域名对应的学校，请联系管理员检查学校域名配置".into());
    }
    Ok(Connection {
        school: School {
            origin: origin.origin().ascii_serialization(),
            name: name.unwrap_or_default().to_owned(),
            port,
            username: String::new(),
            auto_login: false,
        },
        origin,
        client,
    })
}
