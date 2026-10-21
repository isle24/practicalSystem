use serde_json::{json, Value};
use std::{error::Error, io::ErrorKind, sync::OnceLock, time::Duration};
use tokio_util::sync::CancellationToken;
use url::Url;
use zeroize::Zeroize;

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

#[derive(Debug)]
pub struct Failure {
    code: &'static str,
    status: Option<u16>,
}

impl Failure {
    fn new(code: &'static str) -> Self {
        Self { code, status: None }
    }

    pub fn reply(&self) -> Value {
        json!({"type":"rpc_result","error":true,"error_code":self.code,"http_status":self.status})
    }

    fn message(&self) -> String {
        static MESSAGES: OnceLock<Value> = OnceLock::new();
        let messages = MESSAGES.get_or_init(|| {
            serde_json::from_str(include_str!("../../agent/model-errors.json"))
                .expect("embedded model errors")
        });
        let message = messages[self.code].as_str().unwrap_or("模型请求失败");
        match self.status {
            Some(status) => format!("{message}（HTTP {status}）"),
            None => message.to_owned(),
        }
    }
}

fn connection_failure(reason: &reqwest::Error, https: bool) -> Failure {
    if reason.is_timeout() {
        return Failure::new("timeout");
    }
    let mut source: Option<&(dyn Error + 'static)> = Some(reason);
    for _ in 0..16 {
        let Some(error) = source else { break };
        if let Some(error) = error.downcast_ref::<std::io::Error>() {
            match error.kind() {
                ErrorKind::ConnectionRefused => return Failure::new("refused"),
                ErrorKind::PermissionDenied => return Failure::new("denied"),
                ErrorKind::NetworkUnreachable | ErrorKind::HostUnreachable => {
                    return Failure::new("unreachable")
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
            return Failure::new("dns");
        }
        if https && (message.contains("certificate") || message.contains("certvalid")) {
            return Failure::new("tls");
        }
        source = error.source();
    }
    Failure::new(if reason.is_connect() {
        if https {
            "https_connection"
        } else {
            "http_connection"
        }
    } else {
        "connection"
    })
}

fn client(seconds: u64) -> Result<reqwest::Client, Failure> {
    reqwest::Client::builder()
        .no_proxy()
        .redirect(reqwest::redirect::Policy::none())
        .connect_timeout(Duration::from_secs(10))
        .timeout(Duration::from_secs(seconds))
        .build()
        .map_err(|_| Failure::new("configuration"))
}

fn http_failure(status: u16, value: &Value, models: bool) -> Failure {
    let code = match status {
        401 => "authentication",
        403 => "forbidden",
        300..=399 => "redirect",
        408 | 504 => "timeout",
        413 => "payload_too_large",
        429 => "rate_limit",
        500..=599 => "server_error",
        _ => {
            let kind = value["error"]["code"]
                .as_str()
                .or_else(|| value["error"]["type"].as_str())
                .unwrap_or("");
            match (status, kind) {
                (400 | 404 | 422, "model_not_found" | "invalid_model" | "model_not_supported") => {
                    "model_not_found"
                }
                (400 | 422, "context_length_exceeded" | "context_window_exceeded") => {
                    "context_limit"
                }
                (404, _) if models => "models_unavailable",
                (404 | 405, _) => "endpoint_not_found",
                (400 | 422, _) => match value["error"]["param"].as_str().unwrap_or("") {
                    "tools" | "functions" => "tools_rejected",
                    "tool_choice" | "function_call" => "tool_choice_rejected",
                    "max_tokens" | "max_completion_tokens" => "output_tokens_rejected",
                    "messages" => "messages_rejected",
                    _ => "request_invalid",
                },
                _ => "request_invalid",
            }
        }
    };
    Failure {
        code,
        status: Some(status),
    }
}

async fn response_json(
    mut response: reqwest::Response,
    limit: usize,
    models: bool,
) -> Result<Value, Failure> {
    let status = response.status();
    let bound = if status.is_success() {
        limit
    } else {
        64 * 1024
    };
    let mut bytes = Vec::new();
    loop {
        match response.chunk().await {
            Ok(Some(chunk)) if bytes.len() + chunk.len() <= bound => {
                bytes.extend_from_slice(&chunk)
            }
            Ok(None) => break,
            Ok(Some(_)) if status.is_success() => return Err(Failure::new("response_size")),
            Err(_) if status.is_success() => return Err(Failure::new("connection")),
            _ => break,
        }
    }
    if !status.is_success() {
        let value = serde_json::from_slice(&bytes).unwrap_or(Value::Null);
        return Err(http_failure(status.as_u16(), &value, models));
    }
    serde_json::from_slice(&bytes).map_err(|_| Failure {
        code: "response_json",
        status: Some(status.as_u16()),
    })
}

pub fn redact(value: &mut Value, key: &str) -> bool {
    if key.is_empty() {
        return false;
    }
    match value {
        Value::String(text) if text.contains(key) => {
            let redacted = text.replace(key, "[已隐藏]");
            text.zeroize();
            *text = redacted;
            true
        }
        Value::Array(items) => items
            .iter_mut()
            .fold(false, |changed, item| redact(item, key) | changed),
        Value::Object(items) => items
            .values_mut()
            .fold(false, |changed, item| redact(item, key) | changed),
        _ => false,
    }
}

pub fn redact_messages(messages: &mut Value, key: &str) {
    if let Some(items) = messages.as_array_mut() {
        for message in items {
            if let Some(content) = message.get_mut("content") {
                if let Some(parts) = content.as_array_mut() {
                    for part in parts {
                        let field = match part["type"].as_str() {
                            Some("text") => Some("text"),
                            Some("toolCall") => Some("arguments"),
                            _ => None,
                        };
                        if let Some(value) = field.and_then(|field| part.get_mut(field)) {
                            redact(value, key);
                        }
                    }
                } else {
                    redact(content, key);
                }
            }
            if let Some(calls) = message.get_mut("tool_calls").and_then(Value::as_array_mut) {
                for call in calls {
                    if let Some(arguments) = call
                        .get_mut("function")
                        .and_then(|function| function.get_mut("arguments"))
                    {
                        redact(arguments, key);
                    }
                }
            }
        }
    }
}

pub async fn complete(
    config: &Value,
    body: &Value,
    cancel: &CancellationToken,
) -> Result<Value, Failure> {
    let target = endpoint(config["endpoint"].as_str().unwrap_or(""))
        .map_err(|_| Failure::new("configuration"))?;
    let https = target.scheme() == "https";
    let key = config["api_key"].as_str().unwrap_or("");
    let model = config["model"].as_str().unwrap_or("");
    if key.is_empty()
        || key.len() > 2048
        || key.chars().any(char::is_control)
        || model.is_empty()
        || model.len() > 160
        || model.chars().any(char::is_control)
    {
        return Err(Failure::new("configuration"));
    }
    let input = body
        .as_object()
        .ok_or_else(|| Failure::new("request_invalid"))?;
    if input
        .keys()
        .any(|key| !["messages", "tools", "tool_choice", "max_tokens"].contains(&key.as_str()))
        || body["messages"]
            .as_array()
            .is_none_or(|items| items.is_empty() || items.len() > 64)
        || body
            .get("tools")
            .is_some_and(|tools| tools.as_array().is_none_or(|items| items.len() > 32))
        || body
            .get("tool_choice")
            .is_some_and(|choice| !matches!(choice.as_str(), Some("none" | "auto" | "required")))
        || body.get("max_tokens").is_some_and(|tokens| {
            tokens
                .as_u64()
                .is_none_or(|count| !(1..=8192).contains(&count))
        })
    {
        return Err(Failure::new("request_invalid"));
    }
    if serde_json::to_vec(body)
        .map_err(|_| Failure::new("request_invalid"))?
        .len()
        > 512 * 1024
    {
        return Err(Failure::new("payload_too_large"));
    }
    let mut request = body.clone();
    redact_messages(&mut request["messages"], key);
    if let Some(tools) = request.get_mut("tools").and_then(Value::as_array_mut) {
        for tool in tools {
            if let Some(function) = tool.get_mut("function").and_then(Value::as_object_mut) {
                if let Some(description) = function.get_mut("description") {
                    redact(description, key);
                }
                if function
                    .get("name")
                    .and_then(Value::as_str)
                    .is_some_and(|name| name.starts_with("mcp_"))
                {
                    if let Some(parameters) = function.get_mut("parameters") {
                        redact(parameters, key);
                    }
                }
            }
        }
    }
    request["model"] = json!(model);
    request["stream"] = json!(false);
    let operation = async {
        let response = client(180)?
            .post(target)
            .bearer_auth(key)
            .json(&request)
            .send()
            .await
            .map_err(|reason| connection_failure(&reason, https))?;
        let response = response_json(response, 4 * 1024 * 1024, false).await?;
        let choice = response["choices"]
            .as_array()
            .and_then(|items| items.first())
            .ok_or_else(|| Failure::new("response_protocol"))?;
        let message = &choice["message"];
        if !message.is_object() {
            return Err(Failure::new("response_protocol"));
        }
        let mut value = json!({"choices":[{"message":{"role":message["role"],"content":message["content"],"tool_calls":message["tool_calls"]},"finish_reason":choice["finish_reason"]}]});
        redact(&mut value["choices"][0]["message"]["content"], key);
        if let Some(calls) = value["choices"][0]["message"]["tool_calls"].as_array_mut() {
            for call in calls {
                let function = call
                    .get_mut("function")
                    .and_then(Value::as_object_mut)
                    .ok_or_else(|| Failure::new("response_protocol"))?;
                let arguments = function
                    .get_mut("arguments")
                    .filter(|value| value.is_string())
                    .ok_or_else(|| Failure::new("response_protocol"))?;
                redact(arguments, key);
            }
        }
        if serde_json::to_vec(&value)
            .map_err(|_| Failure::new("response_protocol"))?
            .len()
            > 4 * 1024 * 1024 - 1024
        {
            return Err(Failure::new("response_size"));
        }
        Ok(value)
    };
    tokio::select! { biased;
        _ = cancel.cancelled() => Err(Failure::new("cancelled")),
        result = operation => result,
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
    let client = client(25).map_err(|failure| failure.message())?;
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
        let response = request
            .send()
            .await
            .map_err(|reason| connection_failure(&reason, https).message())?;
        let value = response_json(response, 2 * 1024 * 1024, action == "models")
            .await
            .map_err(|failure| failure.message())?;
        if action != "models" {
            if value["choices"]
                .as_array()
                .is_none_or(|items| items.is_empty())
            {
                return Err(Failure::new("response_protocol").message());
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
    tokio::select! { biased;
        _ = cancel.cancelled() => Err("模型连接已取消".into()),
        result = operation => result,
    }
}
