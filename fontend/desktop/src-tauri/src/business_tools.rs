use crate::{
    connection::Connection,
    school_api::{self, ApiFailure},
};
use serde_json::{json, Value};
use std::{
    collections::HashSet,
    time::{Duration, Instant},
};
use zeroize::Zeroizing;

pub struct Tools {
    pub access: Zeroizing<String>,
    pub refresh: Zeroizing<String>,
    pub expires: Option<Instant>,
    pub task: String,
    pub task_expires: Option<Instant>,
    pub blocked: bool,
    scope: Value,
    pub connection: Value,
    capabilities: Vec<Value>,
    checked: Option<Instant>,
    retry_at: Option<Instant>,
}
impl Default for Tools {
    fn default() -> Self {
        Self {
            access: Zeroizing::new(String::new()),
            refresh: Zeroizing::new(String::new()),
            expires: None,
            task: String::new(),
            task_expires: None,
            blocked: false,
            scope: Value::Null,
            capabilities: vec![],
            connection: json!({"state":"idle","message":"登录后自动连接学校业务工具","capability_count":0,"action":"connect"}),
            checked: None,
            retry_at: None,
        }
    }
}
impl Tools {
    pub fn bind(&mut self, scope: &Value) {
        if self.scope != *scope {
            *self = Self::default();
            self.scope = scope.clone();
        }
    }
    pub fn status(&self) -> Value {
        let mut value = self.connection.clone();
        value["capability_count"] = json!(self.capabilities.len());
        value["retry_after"] = json!(self
            .retry_at
            .map(|at| at.saturating_duration_since(Instant::now()).as_secs())
            .unwrap_or(0));
        value
    }
    pub fn paused(&mut self) {
        self.blocked = true;
        self.connection = json!({"state":"paused","stage":"call","error":"outcome_requires_review","message":"业务调用已暂停，请先核对已发送请求和操作回执，再点击重新连接","action":"review_result","can_manage_cli":self.connection["can_manage_cli"]});
    }
    pub fn apply_policy(&mut self, policy: &Value) {
        if policy.is_null() {
            return;
        }
        let manage = policy["can_manage_cli"] == true;
        self.connection["can_manage_cli"] = json!(manage);
        if self.blocked {
            return;
        }
        if policy["state"] == "error" {
            self.connection = json!({"state":"error","stage":"capabilities","error":policy["error"],"message":policy["message"],"capability_count":0,"action":"retry","can_manage_cli":manage});
        } else if let Some(reason) = policy["reason"].as_str() {
            match reason {
                "school_disabled" | "no_school_capabilities" | "account_no_capabilities" => {
                    self.capabilities.clear();
                    let message = match reason { "school_disabled" => "本校尚未启用 CLI 业务工具，请由学校管理员开放", "no_school_capabilities" => "本校尚未勾选可用业务功能，请由学校管理员配置 CLI 授权", _ => "当前账号的权限与学校已开放功能没有交集，请联系管理员核对角色和 CLI 授权" };
                    self.connection = json!({"state":if reason=="school_disabled" {"disabled"} else {"no_capabilities"},"stage":"capabilities","error":reason,"message":message,"action":"authorize","can_manage_cli":manage});
                }
                "ready"
                    if ["disabled", "no_capabilities", "binding_required"]
                        .contains(&self.connection["state"].as_str().unwrap_or(""))
                        || self.connection["stage"] == "capabilities"
                            && self.connection["error"] == "invalid_policy" =>
                {
                    self.connection = json!({"state":"idle","message":"学校业务授权已更新，正在自动连接","action":"connect","can_manage_cli":manage});
                }
                _ => (),
            }
        }
    }
    pub fn resume(&mut self, fresh_task: bool) {
        if ["authorization_required"].contains(&self.connection["state"].as_str().unwrap_or("")) {
            self.access = Zeroizing::new(String::new());
            self.refresh = Zeroizing::new(String::new());
            self.expires = None;
            self.task.clear();
            self.task_expires = None;
        }
        if fresh_task {
            self.task.clear();
            self.task_expires = None;
        }
        self.blocked = false;
        self.retry_at = None;
        self.checked = None;
        let manage = self.connection["can_manage_cli"].clone();
        self.connection = json!({"state":"idle","message":"正在重新读取学校业务授权","action":"connect","can_manage_cli":manage});
    }
    fn failure(&mut self, stage: &str, failure: ApiFailure) -> String {
        let manage = self.connection["can_manage_cli"].clone();
        let (state, action) = if failure.code == "task_expired" {
            ("task_expired", "new_task")
        } else if failure.code == "task_budget_exhausted" {
            ("task_budget_exhausted", "new_task")
        } else if failure.status == 401
            || ["invalid_token", "invalid_grant", "browser_login_required"]
                .contains(&failure.code.as_str())
        {
            ("authorization_required", "login")
        } else if failure.code == "wechat_binding_required"
            || failure.status == 403 && failure.message.contains("企业微信绑定")
        {
            ("binding_required", "binding")
        } else if ["cli_disabled", "cli_capability_disabled", "no_capabilities"]
            .contains(&failure.code.as_str())
        {
            ("no_capabilities", "authorize")
        } else if failure.status == 429 {
            ("rate_limited", "retry")
        } else {
            ("error", "retry")
        };
        let label = match stage {
            "session" => "账号授权",
            "task" => "准备业务任务",
            _ => "读取业务能力",
        };
        let message = format!("{label}未完成：{}", failure.message);
        self.retry_at = Some(Instant::now() + Duration::from_secs(failure.retry_after.max(5)));
        self.connection = json!({"state":state,"stage":stage,"error":failure.code,"message":message,"http_status":failure.status,"action":action,"can_manage_cli":manage});
        self.checked = Some(Instant::now());
        message
    }
}

async fn api(
    client: &reqwest::Client,
    connection: &Connection,
    state: &Tools,
    method: &str,
    path: &str,
    body: &Value,
) -> Result<Value, ApiFailure> {
    if connection.cancel.is_cancelled() {
        return Err(ApiFailure::new(0, "connection_closed", "学校会话已结束"));
    }
    let target = connection
        .origin
        .join(path)
        .map_err(|_| ApiFailure::new(400, "invalid_origin", "学校业务地址无效"))?;
    if target.origin() != connection.origin.origin() || !target.path().starts_with("/api/cli/") {
        return Err(ApiFailure::new(403, "invalid_origin", "学校业务来源不匹配"));
    }
    let mut request = client
        .request(
            if method == "POST" {
                reqwest::Method::POST
            } else {
                reqwest::Method::GET
            },
            target,
        )
        .header("x-cli-operation-version", "1")
        .timeout(Duration::from_secs(20));
    if path != "api/cli/token/refresh" {
        request = request.bearer_auth(state.access.as_str());
    }
    if method == "POST" {
        request = request.json(body);
    }
    let response = request.send().await.map_err(|_| {
        ApiFailure::new(0, "network_error", "无法连接学校业务服务，请检查网络后重试")
    })?;
    school_api::decode_api(response, 1024 * 1024).await
}
fn token(value: &Value, field: &str, prefix: &str) -> Result<Zeroizing<String>, ApiFailure> {
    let value = value[field]
        .as_str()
        .filter(|v| {
            v.starts_with(prefix)
                && v.len() == prefix.len() + 64
                && v[prefix.len()..].bytes().all(|b| b.is_ascii_hexdigit())
        })
        .ok_or_else(|| ApiFailure::new(0, "invalid_response", "学校授权凭据返回格式不正确"))?;
    Ok(Zeroizing::new(value.into()))
}

pub async fn connect(
    connection: &Connection,
    scope: &Value,
    state: &mut Tools,
    client: &reqwest::Client,
    need_task: bool,
    explicit: bool,
) -> Result<Value, String> {
    state.bind(scope);
    if state.blocked {
        state.paused();
        return Err(state.connection["message"]
            .as_str()
            .unwrap_or("业务调用已暂停")
            .into());
    }
    if !explicit
        && !["idle", "ready", "connecting"]
            .contains(&state.connection["state"].as_str().unwrap_or(""))
    {
        return Err(state.connection["message"]
            .as_str()
            .unwrap_or("请根据业务工具状态处理")
            .into());
    }
    if !need_task
        && !explicit
        && state.connection["state"] == "ready"
        && state
            .checked
            .is_some_and(|at| at.elapsed() < Duration::from_secs(30))
    {
        return Ok(json!(state.capabilities));
    }
    state.connection["state"] = json!("connecting");
    state.connection["stage"] = json!("session");
    let result = async {
        if state.access.is_empty() {
            let value =
                school_api::client_agent_session(connection, &uuid::Uuid::new_v4().to_string())
                    .await
                    .map_err(|e| ("session", e))?;
            if value["account"]["id"] != scope["account_id"]
                || value["school"]["id"] != scope["school_id"]
            {
                return Err((
                    "session",
                    ApiFailure::new(403, "account_mismatch", "授权结果与当前学校账号不匹配"),
                ));
            }
            let access = token(&value, "access_token", "cli_at_").map_err(|e| ("session", e))?;
            let refresh = token(&value, "refresh_token", "cli_rt_").map_err(|e| ("session", e))?;
            let ttl = value["expires_in"]
                .as_u64()
                .filter(|v| *v > 0 && *v <= 86400)
                .ok_or_else(|| {
                    (
                        "session",
                        ApiFailure::new(0, "invalid_response", "学校会话有效期不正确"),
                    )
                })?;
            state.access = access;
            state.refresh = refresh;
            state.expires = Some(Instant::now() + Duration::from_secs(ttl));
        } else if state
            .expires
            .is_none_or(|at| at <= Instant::now() + Duration::from_secs(30))
        {
            let value = api(
                client,
                connection,
                state,
                "POST",
                "api/cli/token/refresh",
                &json!({"refresh_token":state.refresh.as_str()}),
            )
            .await
            .map_err(|e| ("session", e))?;
            let access = token(&value, "access_token", "cli_at_").map_err(|e| ("session", e))?;
            let refresh = token(&value, "refresh_token", "cli_rt_").map_err(|e| ("session", e))?;
            let ttl = value["expires_in"]
                .as_u64()
                .filter(|v| *v > 0 && *v <= 86400)
                .ok_or_else(|| {
                    (
                        "session",
                        ApiFailure::new(0, "invalid_response", "学校续期有效期不正确"),
                    )
                })?;
            state.access = access;
            state.refresh = refresh;
            state.expires = Some(Instant::now() + Duration::from_secs(ttl));
        }
        state.connection["stage"] = json!("capabilities");
        let catalog = api(
            client,
            connection,
            state,
            "GET",
            "api/cli/capabilities",
            &json!({}),
        )
        .await
        .map_err(|e| ("capabilities", e))?;
        let items = catalog["items"]
            .as_array()
            .filter(|items| items.len() <= 1000)
            .ok_or_else(|| {
                (
                    "capabilities",
                    ApiFailure::new(0, "invalid_response", "学校能力目录格式不正确"),
                )
            })?;
        let mut ids = HashSet::new();
        for item in items {
            let id = item["id"].as_str().filter(|id| {
                !id.is_empty()
                    && id.len() <= 160
                    && id
                        .bytes()
                        .all(|b| b.is_ascii_alphanumeric() || b"_.:-".contains(&b))
            });
            if id.is_none()
                || !ids.insert(id.unwrap())
                || item["available"] != true
                || !matches!(item["phase"].as_str(), Some("B" | "C"))
                || !matches!(item["kind"].as_str(), Some("read" | "write"))
                || (item["phase"] == "C" && item["confirmation_required"] != true)
                || !valid_parameters(&item["parameters"])
            {
                return Err((
                    "capabilities",
                    ApiFailure::new(
                        0,
                        "invalid_response",
                        "学校能力目录含不兼容的功能定义，请管理员检查服务版本",
                    ),
                ));
            }
        }
        state.capabilities = items.clone();
        if items.is_empty() {
            return Err((
                "capabilities",
                ApiFailure::new(
                    403,
                    "no_capabilities",
                    "当前学校授权与账号权限没有可用功能，请联系管理员核对 CLI 授权",
                ),
            ));
        }
        if need_task {
            state.connection["stage"] = json!("task");
            if !state.task.is_empty() {
                if state.task_expires.is_none_or(|at| at <= Instant::now()) {
                    return Err((
                        "task",
                        ApiFailure::new(
                            409,
                            "task_expired",
                            "原业务任务已过期，请明确开始新任务后继续",
                        ),
                    ));
                }
                let path = format!("api/cli/tasks/status?task_id={}", state.task);
                let task = api(client, connection, state, "GET", &path, &json!({}))
                    .await
                    .map_err(|e| ("task", e))?;
                if task["task_id"] != state.task || task["remaining_requests"].as_u64().is_none() {
                    return Err((
                        "task",
                        ApiFailure::new(
                            0,
                            "invalid_response",
                            "学校任务状态返回格式不正确，请管理员检查服务版本",
                        ),
                    ));
                }
                if task["remaining_requests"].as_u64() == Some(0) {
                    return Err((
                        "task",
                        ApiFailure::new(
                            409,
                            "task_budget_exhausted",
                            "原业务任务预算已耗尽，请明确开始新任务后继续",
                        ),
                    ));
                }
            } else {
                let task = api(
                    client,
                    connection,
                    state,
                    "POST",
                    "api/cli/tasks/create",
                    &json!({"label":"客户端 Agent"}),
                )
                .await
                .map_err(|e| ("task", e))?;
                let id = task["task_id"]
                    .as_str()
                    .filter(|id| id.len() == 64 && id.bytes().all(|b| b.is_ascii_hexdigit()))
                    .ok_or_else(|| {
                        (
                            "task",
                            ApiFailure::new(0, "invalid_response", "学校业务任务编号格式不正确"),
                        )
                    })?;
                let ttl = task["expires_in"]
                    .as_u64()
                    .filter(|v| *v > 0 && *v <= 86400)
                    .ok_or_else(|| {
                        (
                            "task",
                            ApiFailure::new(0, "invalid_response", "学校业务任务有效期不正确"),
                        )
                    })?;
                state.task = id.into();
                state.task_expires = Some(Instant::now() + Duration::from_secs(ttl));
            }
        }
        Ok(json!(state.capabilities))
    }
    .await;
    match result {
        Ok(items) => {
            let manage = state.connection["can_manage_cli"].clone();
            state.connection = json!({"state":"ready","stage":if need_task {"task"}else{"capabilities"},"message":format!("已自动连接当前学校账号，可用 {} 项业务功能",state.capabilities.len()),"action":"none","can_manage_cli":manage});
            state.checked = Some(Instant::now());
            state.retry_at = None;
            Ok(items)
        }
        Err((stage, error)) => Err(state.failure(stage, error)),
    }
}

pub fn can_manage_cli(connection: &Connection) -> bool {
    let Some(context) = connection.operation_context.as_ref() else {
        return false;
    };
    matches!(
        context["role_type"].as_str(),
        Some("school_admin" | "super_admin")
    ) && context["permissions"]
        .as_array()
        .into_iter()
        .flatten()
        .any(|permission| permission == "config:manage")
}

fn valid_parameters(value: &Value) -> bool {
    let Some(parameters) = value.as_array().filter(|items| items.len() <= 128) else {
        return false;
    };
    let mut names = HashSet::new();
    parameters.iter().all(|parameter| {
        let Some(name) = parameter["name"]
            .as_str()
            .filter(|name| !name.is_empty() && name.len() <= 128)
        else {
            return false;
        };
        let first = name.as_bytes()[0];
        (first.is_ascii_alphabetic() || first == b'_')
            && name
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
            && names.insert(name)
            && matches!(
                parameter["type"].as_str(),
                Some("string" | "integer" | "number" | "boolean" | "array" | "object")
            )
    })
}
