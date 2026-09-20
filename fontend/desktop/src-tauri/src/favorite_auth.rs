use crate::{connection::Connection, external, school_api};
use reqwest::{
    header::{HeaderMap, HeaderName, HeaderValue},
    redirect::Policy,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tauri::{webview::Cookie, AppHandle};
use url::Url;

#[derive(Clone, Default, Deserialize, Serialize)]
pub struct Pair {
    pub key: String,
    pub value: String,
}

#[derive(Default, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Profile {
    #[serde(default)]
    pub headers: Vec<Pair>,
    #[serde(default)]
    pub cookies: Vec<Pair>,
    #[serde(default)]
    pub form: Vec<Pair>,
}

/// 限定个人认证参数大小与可修改请求头。
fn validate(profile: &Profile) -> Result<(), String> {
    for rows in [&profile.headers, &profile.cookies, &profile.form] {
        if rows.len() > 20 {
            return Err("每组最多 20 个参数".into());
        }
        for pair in rows {
            if pair.key.is_empty()
                || pair.key.len() > 80
                || pair.value.len() > 4000
                || !pair
                    .key
                    .bytes()
                    .all(|c| c.is_ascii_alphanumeric() || b"_- .".contains(&c) && c != b' ')
                || pair.value.contains(['\r', '\n', '\0'])
            {
                return Err("参数名或值无效".into());
            }
        }
    }
    for pair in &profile.headers {
        let key = pair.key.to_ascii_lowercase();
        if matches!(
            key.as_str(),
            "host"
                | "cookie"
                | "origin"
                | "referer"
                | "content-length"
                | "connection"
                | "transfer-encoding"
                | "upgrade"
                | "te"
                | "trailer"
                | "content-type"
        ) || key.starts_with("sec-")
            || key.starts_with("proxy-")
            || key.starts_with("x-forwarded-")
        {
            return Err("此请求头不可自定义，请使用专用参数组".into());
        }
        HeaderName::from_bytes(key.as_bytes()).map_err(|_| "请求头名称无效")?;
        HeaderValue::from_str(&pair.value).map_err(|_| "请求头值无效")?;
    }
    for pair in &profile.cookies {
        if pair.value.contains(';') {
            return Err("Cookie 值不能包含分号".into());
        }
    }
    Ok(())
}

/// 以学校、账号、收藏和完整目标地址定位系统凭据。
async fn context(connection: &Connection, id: u64) -> Result<(Value, keyring::Entry), String> {
    let account = school_api::account(connection).await?;
    let favorite = school_api::read(connection, &format!("api/favorite/detail?id={id}")).await?;
    let address = favorite["url"].as_str().ok_or("收藏地址不存在")?;
    let url = Url::parse(address).map_err(|_| "收藏地址无效")?;
    if url.scheme() != "https" || !external::allowed(&url) {
        return Err("本机认证只支持 HTTPS 外部站点".into());
    }
    let key = school_api::digest(&format!("{}:{account}:{id}:{address}", connection.origin));
    let entry = keyring::Entry::new("com.2iwm.practical.desktop.favorite", &key)
        .map_err(|_| "系统凭据库不可用")?;
    Ok((favorite, entry))
}

/// 仅返回是否保存，不把原凭据返回网页。
pub async fn configure(connection: &Connection, input: &Value) -> Result<Value, String> {
    let (_, entry) = context(connection, input["id"].as_u64().unwrap_or(0)).await?;
    match input["action"].as_str().unwrap_or("") {
        "status" => match entry.get_password() {
            Ok(_) => Ok(json!({"stored":true})),
            Err(keyring::Error::NoEntry) => Ok(json!({"stored":false})),
            Err(_) => Err("无法读取系统凭据库".into()),
        },
        "save" => {
            let profile: Profile =
                serde_json::from_value(input["profile"].clone()).map_err(|_| "认证配置格式无效")?;
            validate(&profile)?;
            let value = serde_json::to_string(&profile).map_err(|_| "认证配置格式无效")?;
            entry
                .set_password(&value)
                .map_err(|_| "无法保存到系统凭据库")?;
            Ok(json!({"stored":true}))
        }
        "clear" => {
            match entry.delete_credential() {
                Ok(()) | Err(keyring::Error::NoEntry) => (),
                Err(_) => return Err("无法清除系统凭据".into()),
            };
            Ok(json!({"stored":false}))
        }
        _ => Err("操作无效".into()),
    }
}

/// 使用独立网络会话完成一次同源认证交换。
pub async fn open(
    app: &AppHandle,
    connection: &Connection,
    id: u64,
    mode: &str,
) -> Result<(), String> {
    let favorite = school_api::read(connection, &format!("api/favorite/detail?id={id}")).await?;
    let mut url = Url::parse(favorite["url"].as_str().unwrap_or("")).map_err(|_| "收藏地址无效")?;
    let profile = if url.scheme() == "https" {
        let (_, entry) = context(connection, id).await?;
        match entry.get_password() {
            Ok(value) => {
                serde_json::from_str::<Profile>(&value).map_err(|_| "本机认证配置已损坏")?
            }
            Err(keyring::Error::NoEntry) => Profile::default(),
            Err(_) => return Err("无法读取系统凭据库".into()),
        }
    } else {
        Profile::default()
    };
    validate(&profile)?;
    let config = &favorite["request_config"];
    let query: Vec<Pair> = serde_json::from_value(config["query"].clone()).unwrap_or_default();
    for pair in query {
        url.query_pairs_mut().append_pair(&pair.key, &pair.value);
    }
    let mut form: Vec<Pair> = serde_json::from_value(config["form"].clone()).unwrap_or_default();
    for pair in &profile.form {
        form.retain(|item| item.key != pair.key);
        form.push(pair.clone());
    }
    let post = config["method"].as_str() == Some("POST") || !form.is_empty();
    let configured = post || !profile.headers.is_empty() || !profile.cookies.is_empty();
    if !configured {
        return external::open(app, url.as_str(), mode);
    }
    if mode != "client" {
        return Err("POST/Header/Cookie 认证需在客户端窗口打开；外部浏览器不支持注入".into());
    }
    if url.scheme() != "https" || !external::allowed(&url) {
        return Err("认证只支持 HTTPS 外部站点".into());
    }
    let host = url.host_str().ok_or("目标域名无效")?.to_owned();
    let mut cookies: Vec<Cookie<'static>> = profile
        .cookies
        .iter()
        .map(|pair| {
            Cookie::build((pair.key.clone(), pair.value.clone()))
                .domain(host.clone())
                .path("/")
                .secure(true)
                .http_only(true)
                .build()
        })
        .collect();
    if post || !profile.headers.is_empty() {
        let client = reqwest::Client::builder()
            .redirect(Policy::none())
            .timeout(std::time::Duration::from_secs(20))
            .build()
            .map_err(|_| "无法初始化认证请求")?;
        let mut headers = HeaderMap::new();
        for pair in &profile.headers {
            headers.insert(
                HeaderName::from_bytes(pair.key.as_bytes()).map_err(|_| "请求头无效")?,
                HeaderValue::from_str(&pair.value).map_err(|_| "请求头无效")?,
            );
        }
        if !profile.cookies.is_empty() {
            headers.insert(
                reqwest::header::COOKIE,
                HeaderValue::from_str(
                    &profile
                        .cookies
                        .iter()
                        .map(|p| format!("{}={}", p.key, p.value))
                        .collect::<Vec<_>>()
                        .join("; "),
                )
                .map_err(|_| "Cookie 无效")?,
            );
        }
        let request = if post {
            client
                .post(url.clone())
                .form(&form.iter().map(|p| (&p.key, &p.value)).collect::<Vec<_>>())
        } else {
            client.get(url.clone())
        };
        let response = request
            .headers(headers)
            .send()
            .await
            .map_err(|_| "认证连接失败，请检查网络和目标站点")?;
        if !matches!(response.status().as_u16(), 302 | 303) {
            return Err("认证入口需返回 302/303 同源跳转；请确认目标系统的单点登录协议".into());
        }
        let next = response
            .headers()
            .get(reqwest::header::LOCATION)
            .and_then(|v| v.to_str().ok())
            .and_then(|v| url.join(v).ok())
            .ok_or("认证未返回有效跳转")?;
        if next.origin() != url.origin() || !external::allowed(&next) {
            return Err("认证跳转到其他站点，已停止以保护凭据".into());
        }
        for value in response.headers().get_all(reqwest::header::SET_COOKIE) {
            let mut cookie = Cookie::parse(
                value
                    .to_str()
                    .map_err(|_| "认证 Cookie 格式无效")?
                    .to_owned(),
            )
            .map_err(|_| "认证 Cookie 格式无效")?;
            if cookie
                .domain()
                .is_some_and(|domain| domain.trim_start_matches('.') != host)
            {
                return Err("目标站点设置了跨域 Cookie，已停止".into());
            }
            cookie.set_domain(host.clone());
            cookie.set_secure(true);
            if cookie.path().is_none() {
                cookie.set_path("/");
            }
            cookies.retain(|old| old.name() != cookie.name());
            cookies.push(cookie.into_owned());
        }
        url = next;
    }
    external::open_authenticated(app, url, cookies)
}
