use serde_json::{json, Value};
use std::{error::Error, io::ErrorKind, time::Duration};
use tokio_util::sync::CancellationToken;
use url::Url;

pub fn endpoint(value: &str) -> Result<Url, String> {
    if value.len() > 500 || value.chars().any(char::is_control) || value.contains('\\') {
        return Err("模型接口地址无效".into());
    }
    let mut url = Url::parse(value.trim()).map_err(|_| "请输入完整 HTTP 或 HTTPS 模型地址")?;
    if !matches!(url.scheme(), "http" | "https")
        || url.host_str().is_none()
        || !url.username().is_empty()
        || url.password().is_some()
        || url.query().is_some()
        || url.fragment().is_some()
    {
        return Err("模型地址仅支持 HTTP/HTTPS，不能包含账号、查询参数或片段".into());
    }
    let path = url.path().trim_end_matches('/');
    let base = path
        .strip_suffix("/chat/completions")
        .or_else(|| path.strip_suffix("/models"))
        .unwrap_or(path)
        .to_owned();
    url.set_path(&format!("{base}/chat/completions"));
    Ok(url)
}

fn connection_error(reason: &reqwest::Error, https: bool) -> String {
    if reason.is_timeout() {
        return "本机连接模型服务超时，请检查地址、端口和网络".into();
    }
    let mut source: Option<&(dyn Error + 'static)> = Some(reason);
    for _ in 0..16 {
        let Some(error) = source else { break };
        if let Some(error) = error.downcast_ref::<std::io::Error>() {
            match error.kind() {
                ErrorKind::ConnectionRefused => {
                    return "模型服务拒绝连接，请检查端口、服务是否启动及是否允许其他设备访问"
                        .into();
                }
                ErrorKind::PermissionDenied => {
                    return "本机系统拒绝网络访问；macOS 请在系统设置的隐私与安全性、本地网络中允许实践系统访问".into();
                }
                ErrorKind::NetworkUnreachable | ErrorKind::HostUnreachable => {
                    return "本机无法到达模型服务，请检查局域网、VPN、路由及防火墙；macOS 同时检查本地网络权限".into();
                }
                _ => (),
            }
        }
        let message = error.to_string().to_ascii_lowercase();
        if message.contains("dns error")
            || message.contains("failed to lookup address")
            || message.contains("name or service not known")
            || message.contains("nodename nor servname provided")
        {
            return "本机无法解析模型服务主机名，请检查内网 DNS，或使用可访问的内网 IP 地址".into();
        }
        if https && (message.contains("certificate") || message.contains("certvalid")) {
            return "模型服务 HTTPS 证书验证失败，请检查证书主机名、有效期及证书链".into();
        }
        source = error.source();
    }
    if reason.is_connect() {
        if https {
            "本机无法建立 HTTPS 模型连接，请核对协议、端口和网络权限".into()
        } else {
            "本机无法建立 HTTP 模型连接，请核对地址、端口和网络权限；HTTP 连接不涉及 TLS 证书"
                .into()
        }
    } else {
        "本机模型请求失败，请核对服务是否提供 OpenAI 兼容接口".into()
    }
}

pub async fn inspect(
    config: &Value,
    action: &str,
    cancel: &CancellationToken,
) -> Result<Value, String> {
    let mut target = endpoint(config["endpoint"].as_str().unwrap_or(""))?;
    let https = target.scheme() == "https";
    let key = config["api_key"].as_str().unwrap_or("");
    if key.is_empty() || key.len() > 2048 || key.chars().any(char::is_control) {
        return Err("请填写有效的 API Key".into());
    }
    let client = reqwest::Client::builder()
        .no_proxy()
        .redirect(reqwest::redirect::Policy::none())
        .connect_timeout(Duration::from_secs(10))
        .timeout(Duration::from_secs(25))
        .build()
        .map_err(|_| "无法初始化本机模型连接")?;
    let request = if action == "models" {
        let base = target
            .path()
            .strip_suffix("/chat/completions")
            .unwrap_or("")
            .to_owned();
        target.set_path(&format!("{base}/models"));
        client.get(target).bearer_auth(key)
    } else {
        let model = config["model"].as_str().unwrap_or("").trim();
        if model.is_empty() || model.len() > 160 || model.chars().any(char::is_control) {
            return Err("请选择或填写要检查的模型".into());
        }
        client.post(target).bearer_auth(key).json(&json!({"model":model,"messages":[{"role":"user","content":"请回复 OK"}],"max_tokens":16,"stream":false}))
    };
    let operation = async {
        let mut response = request
            .send()
            .await
            .map_err(|reason| connection_error(&reason, https))?;
        let status = response.status();
        if !status.is_success() {
            return Err(match status.as_u16() {
                401 | 403 => "模型服务拒绝认证，请检查 API Key 和访问权限".to_owned(),
                404 if action == "models" => {
                    "服务未提供 /models，请核对基础地址，或手动填写模型名称".to_owned()
                }
                429 => "模型服务限流，请稍后重试".to_owned(),
                300..=399 => "模型服务返回重定向，请直接填写最终接口地址".to_owned(),
                code => format!("模型服务返回 HTTP {code}，请检查接口和模型配置"),
            });
        }
        let mut bytes = Vec::new();
        while let Some(chunk) = response.chunk().await.map_err(|_| "读取模型服务响应失败")?
        {
            if bytes.len() + chunk.len() > 2 * 1024 * 1024 {
                return Err("模型服务响应过大".into());
            }
            bytes.extend_from_slice(&chunk);
        }
        let value: Value = serde_json::from_slice(&bytes).map_err(|_| "模型服务未返回有效 JSON")?;
        if action != "models" {
            if !value["choices"].is_array()
                || value["choices"]
                    .as_array()
                    .is_none_or(|items| items.is_empty())
            {
                return Err("服务响应不符合 Chat Completions 格式".into());
            }
            return Ok(json!({"ok":true,"message":"本机已成功调用所选模型"}));
        }
        let items = value["data"]
            .as_array()
            .ok_or("模型列表需使用 OpenAI 兼容的 data 数组格式")?;
        if items.len() > 2000 {
            return Err("模型列表超过 2000 项，请缩小服务暴露范围".into());
        }
        let mut models = items
            .iter()
            .filter_map(|item| item["id"].as_str())
            .filter(|id| {
                !id.is_empty() && *id != key && id.len() <= 160 && !id.chars().any(char::is_control)
            })
            .map(str::to_owned)
            .collect::<Vec<_>>();
        models.sort();
        models.dedup();
        Ok(
            json!({"models":models.into_iter().map(|id| json!({"id":id})).collect::<Vec<_>>(),"message":"模型列表来自本机直接请求"}),
        )
    };
    tokio::select! {
        biased;
        _ = cancel.cancelled() => Err("模型连接已取消".into()),
        result = operation => result,
    }
}
