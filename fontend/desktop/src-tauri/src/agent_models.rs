use serde_json::{json, Value};
use std::time::Duration;
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

pub async fn inspect(
    config: &Value,
    action: &str,
    cancel: &CancellationToken,
) -> Result<Value, String> {
    let mut target = endpoint(config["endpoint"].as_str().unwrap_or(""))?;
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
        let mut response = request.send().await.map_err(|reason| {
            if reason.is_timeout() {
                "本机连接模型服务超时，请检查地址和网络"
            } else if reason.is_connect() {
                "本机无法连接模型服务，请检查内网连通性和 TLS 证书"
            } else {
                "本机模型请求失败"
            }
            .to_owned()
        })?;
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
