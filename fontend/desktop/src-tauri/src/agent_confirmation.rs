use serde_json::{json, Value};
use std::sync::Mutex;
use tauri::WebviewWindow;
use tokio::sync::oneshot;
use tokio_util::sync::CancellationToken;

pub(crate) struct View {
    window: WebviewWindow,
    pub closed: CancellationToken,
    pending: Mutex<Option<Pending>>,
}

struct Pending {
    prompt: Value,
    cancel: CancellationToken,
    answer: Option<oneshot::Sender<bool>>,
}

struct Waiting<'a> {
    view: &'a View,
    id: String,
}

fn credential_field(name: &str) -> bool {
    let key = name.to_ascii_lowercase().replace(['-', '_', ' '], "");
    [
        "password",
        "passwd",
        "passphrase",
        "token",
        "secret",
        "apikey",
        "privatekey",
        "authorization",
        "cookie",
        "密码",
        "密钥",
        "令牌",
    ]
    .iter()
    .any(|suffix| key.ends_with(suffix))
}

fn mask_credentials(value: &mut Value) {
    match value {
        Value::Object(fields) => {
            for (key, value) in fields {
                if credential_field(key) {
                    *value = json!("[已隐藏]");
                } else {
                    mask_credentials(value);
                }
            }
        }
        Value::Array(items) => {
            let mut hide_next = false;
            for item in items {
                if hide_next {
                    *item = json!("[已隐藏]");
                    hide_next = false;
                } else {
                    hide_next = item
                        .as_str()
                        .is_some_and(|arg| arg.starts_with('-') && credential_field(arg));
                    mask_credentials(item);
                }
            }
        }
        Value::String(text) => {
            if let Some((name, _)) = text.split_once(['=', ':']) {
                if credential_field(name) {
                    *text = format!("{name}=[已隐藏]");
                    return;
                }
            }
            if let Some((scheme, _)) = text.split_once(' ') {
                if scheme.eq_ignore_ascii_case("bearer") || scheme.eq_ignore_ascii_case("basic") {
                    *text = format!("{scheme} [已隐藏]");
                    return;
                }
            }
            let (option, address) = text
                .split_once('=')
                .filter(|(key, _)| key.starts_with('-'))
                .map_or(("", text.as_str()), |(key, address)| (key, address));
            if address.starts_with("http://") || address.starts_with("https://") {
                if let Ok(mut url) = url::Url::parse(address) {
                    let userinfo = !url.username().is_empty() || url.password().is_some();
                    let sensitive_query = url.query_pairs().any(|(key, _)| credential_field(&key));
                    if userinfo || sensitive_query {
                        if userinfo {
                            let _ = url.set_username("REDACTED");
                            let _ = url.set_password(None);
                        }
                        if sensitive_query {
                            let pairs: Vec<_> = url
                                .query_pairs()
                                .map(|(key, value)| {
                                    let visible = if credential_field(&key) {
                                        "REDACTED".into()
                                    } else {
                                        value.into_owned()
                                    };
                                    (key.into_owned(), visible)
                                })
                                .collect();
                            url.query_pairs_mut().clear().extend_pairs(pairs);
                        }
                        *text = if option.is_empty() {
                            url.to_string()
                        } else {
                            format!("{option}={url}")
                        };
                    }
                }
            }
        }
        _ => {}
    }
}

pub(crate) fn preview(value: &Value) -> String {
    let mut visible = value.clone();
    mask_credentials(&mut visible);
    format!("{visible:#}")
}

impl Drop for Waiting<'_> {
    fn drop(&mut self) {
        if let Ok(mut slot) = self.view.pending.lock() {
            if slot
                .as_ref()
                .is_some_and(|item| item.prompt["id"] == self.id)
            {
                slot.take();
            }
        }
        let _ = self.view.notify();
    }
}

impl View {
    pub fn new(window: WebviewWindow) -> Self {
        Self {
            window,
            closed: CancellationToken::new(),
            pending: Mutex::new(None),
        }
    }

    fn notify(&self) -> tauri::Result<()> {
        self.window
            .eval("window.dispatchEvent(new Event('practical-agent-confirmation'))")
    }

    pub fn pending(&self, scope: &Value) -> Result<Value, String> {
        let slot = self.pending.lock().map_err(|_| "确认状态不可用")?;
        let prompt = slot.as_ref().filter(|item| {
            item.prompt["scope"] == *scope && !item.cancel.is_cancelled() && item.answer.is_some()
        });
        Ok(json!({"confirmation":prompt.map(|item| &item.prompt)}))
    }

    pub fn decide(&self, scope: &Value, id: &str, allowed: bool) -> Result<(), String> {
        let mut slot = self.pending.lock().map_err(|_| "确认状态不可用")?;
        let item = slot
            .as_mut()
            .filter(|item| {
                item.prompt["scope"] == *scope
                    && item.prompt["id"] == id
                    && !item.cancel.is_cancelled()
            })
            .ok_or("该操作已结束，请查看当前请求")?;
        item.answer
            .take()
            .ok_or("该操作已处理")?
            .send(allowed)
            .map_err(|_| "该操作已结束".into())
    }

    pub async fn ask(
        &self,
        scope: &Value,
        run_id: Option<&str>,
        title: &str,
        description: String,
        cancel: &CancellationToken,
    ) -> Result<bool, String> {
        if self.closed.is_cancelled() || cancel.is_cancelled() {
            return Err("确认请求已取消".into());
        }
        let id = uuid::Uuid::new_v4().to_string();
        let (answer, receive) = oneshot::channel();
        {
            let mut slot = self.pending.lock().map_err(|_| "确认状态不可用")?;
            if slot.is_some() {
                return Err("请先处理当前待确认操作".into());
            }
            *slot = Some(Pending {
                prompt: json!({"id":id,"scope":scope,"run_id":run_id,"title":title,"description":description}),
                cancel: cancel.clone(),
                answer: Some(answer),
            });
        }
        let _waiting = Waiting { view: self, id };
        self.notify().map_err(|_| "Agent 窗口已关闭")?;
        tokio::select! {
            biased;
            _ = self.closed.cancelled() => Err("Agent 窗口已关闭或重新加载".into()),
            _ = cancel.cancelled() => Err("确认请求已取消".into()),
            answer = receive => answer.map_err(|_| "确认请求已结束".into()),
        }
    }
}
