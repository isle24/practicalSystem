use crate::{
    agent_models,
    agent_process::{bounded_line, plugin_configs, probe_mcp, send, spawn_runtime},
    agent_profile_sync, agent_profiles, agent_store, agent_workspace,
    business_tools::{self, Tools},
    connection::Connection,
    school_api,
};
use serde_json::{json, Value};
use std::{
    collections::HashMap,
    sync::{Arc, Mutex, OnceLock},
    time::Duration,
};
use tauri::{AppHandle, Manager};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use tauri_plugin_opener::OpenerExt;
use tokio::io::BufReader;
use tokio_util::sync::CancellationToken;
use zeroize::Zeroize;

const RESULT_LIMIT: usize = 256 * 1024;
const OPERATION_LIMIT: usize = 8 * 1024 * 1024;

#[derive(Default)]
struct OwnerState {
    run: Option<Arc<Run>>,
    tools: Arc<tokio::sync::Mutex<Tools>>,
    scope: Value,
    schedule_recovered: bool,
}

struct Run {
    id: String,
    scope: Value,
    cancel: CancellationToken,
    state: Mutex<Value>,
    options: Value,
}

struct ModelConfig(Value);

impl std::ops::Deref for ModelConfig {
    type Target = Value;
    fn deref(&self) -> &Value {
        &self.0
    }
}

impl std::ops::DerefMut for ModelConfig {
    fn deref_mut(&mut self) -> &mut Value {
        &mut self.0
    }
}

impl Drop for ModelConfig {
    fn drop(&mut self) {
        wipe_key(&mut self.0);
    }
}

fn owners() -> &'static Mutex<HashMap<String, OwnerState>> {
    static OWNERS: OnceLock<Mutex<HashMap<String, OwnerState>>> = OnceLock::new();
    OWNERS.get_or_init(|| Mutex::new(HashMap::new()))
}

pub fn cancel_owner(owner: &str) {
    if let Ok(mut owners) = owners().lock() {
        if let Some(state) = owners.remove(owner) {
            if let Some(run) = state.run {
                run.cancel.cancel();
            }
        }
    }
}

fn update(run: &Run, values: Value) {
    if let (Ok(mut state), Some(values)) = (run.state.lock(), values.as_object()) {
        for (key, value) in values {
            state[key] = value.clone();
        }
    }
}

pub fn open(
    app: &AppHandle,
    local: &url::Url,
    remote: &url::Url,
    owner: &str,
) -> Result<Value, String> {
    let label = crate::windows::school_window_label("agent", owner, "main");
    if let Some(window) = app.get_webview_window(&label) {
        window.show().map_err(|_| "无法显示 Agent 窗口")?;
        window.unminimize().map_err(|_| "无法恢复 Agent 窗口")?;
        window.set_focus().ok();
    } else {
        let url = local
            .join("/_desktop/agent.html")
            .map_err(|_| "Agent 地址无效")?;
        let window = crate::windows::build(
            app,
            &label,
            url,
            local.clone(),
            remote.clone(),
            owner,
            "",
            None,
        )
        .map_err(|_| "无法创建 Agent 窗口")?;
        window.set_title("客户端 Agent - 实践系统").ok();
    }
    Ok(json!({"ok":true}))
}

fn snapshot(data: &Value) -> Result<Value, String> {
    let active = agent_workspace::active(data)?;
    let mut value = json!({"workspace":agent_workspace::view(data),"history":active["history"],"conversation_id":active["id"]});
    if let Some(message) = data["migration_notice"].as_str() {
        value["memory_error"] = json!(message);
    }
    Ok(value)
}

fn running(owner: &str) -> Option<Arc<Run>> {
    owners()
        .lock()
        .ok()?
        .get(owner)?
        .run
        .as_ref()
        .filter(|run| {
            run.state
                .lock()
                .is_ok_and(|state| state["status"] == "running")
        })
        .cloned()
}

async fn bootstrap(app: &AppHandle, connection: &Connection, owner: &str) -> Result<Value, String> {
    let scope = account_scope(connection)?;
    let cloud = tokio::time::timeout(
        Duration::from_secs(6),
        school_api::read(connection, "api/client-agent/bootstrap"),
    )
    .await
    .unwrap_or_else(|_| Err("学校配置读取超时".into()));
    let mut value = match cloud {
        Ok(value) => {
            check_scope(connection, &value["scope"])?;
            value
        }
        Err(message) => {
            json!({"scope":scope,"cloud_error":format!("云端配置暂不可用：{message}。本机模型仍可保存和使用。")})
        }
    };
    value["profiles"] = agent_profiles::Store::open(app, &scope)?.snapshots();
    value["personal"] = json!({});
    value["busy"] = json!(false);
    let active_run = running(owner).filter(|run| run.scope == value["scope"]);
    if let Some(run) = active_run {
        let state = run.state.lock().map_err(|_| "Agent 状态不可用")?.clone();
        for field in ["workspace", "history", "conversation_id"] {
            value[field] = state[field].clone();
        }
        value["busy"] = json!(true);
        value["run_id"] = json!(run.id);
        value["run"] = state;
    } else {
        match agent_store::acquire(app, &value["scope"]).and_then(|_guard| {
            let previous = agent_store::read_locked(app, &value["scope"])?;
            let data = agent_workspace::normalize(&value["scope"], previous.clone());
            let mut visible = snapshot(&data)?;
            if data != previous {
                if let Err(message) = agent_store::write(app, &value["scope"], &data) {
                    visible["memory_error"] = json!(message);
                }
            }
            Ok(visible)
        }) {
            Ok(workspace) => {
                for field in ["workspace", "history", "conversation_id", "memory_error"] {
                    if let Some(item) = workspace.get(field) {
                        value[field] = item.clone();
                    }
                }
            }
            Err(message) => {
                value["history"] = json!([]);
                value["memory_error"] = json!(message);
            }
        }
    }
    let tools = {
        let mut state = owners().lock().map_err(|_| "Agent 状态不可用")?;
        let entry = state.entry(owner.to_owned()).or_default();
        entry.scope = value["scope"].clone();
        entry.tools.clone()
    };
    if value["busy"] == true {
        value["tool_connection"] = value["run"]["tool_connection"].clone();
    } else if let Ok(mut state) = tools.try_lock() {
        state.bind(&scope);
        state.connection["can_manage_cli"] = json!(business_tools::can_manage_cli(connection));
        state.apply_policy(&value["tool_policy"]);
        let enabled = value["workspace"]["plugins"]
            .as_array()
            .into_iter()
            .flatten()
            .any(|plugin| plugin["builtin"] == true && plugin["enabled"] == true);
        if !enabled && !state.blocked {
            state.connection = json!({"state":"disabled","stage":"capabilities","error":"builtin_disabled","message":"本项目内置 Skill 已停用，请在插件中启用","action":"enable_plugin"});
        } else if enabled {
            if !state.blocked && state.connection["error"] == "builtin_disabled" {
                state.resume(false);
            }
            {
                let client = connection.client.clone();
                if tokio::time::timeout(
                    Duration::from_secs(6),
                    business_tools::connect(connection, &scope, &mut state, &client, false, false),
                )
                .await
                .is_err()
                {
                    let stage = state.connection["stage"]
                        .as_str()
                        .unwrap_or("session")
                        .to_owned();
                    let manage = state.connection["can_manage_cli"].clone();
                    state.connection = json!({"state":"error","stage":stage,"error":"auto_connect_timeout","message":"自动连接学校业务工具超时；本机模型可继续使用，请点击重试以完成连接","action":"retry","can_manage_cli":manage});
                }
            }
        }
        value["tool_connection"] = state.status();
    } else {
        value["tool_connection"] = json!({"state":"connecting","message":"正在连接当前账号的业务工具","capability_count":0,"action":"none"});
    }
    Ok(value)
}

fn check_scope(connection: &Connection, scope: &Value) -> Result<(), String> {
    require_account_session(connection)?;
    agent_store::scope_key(scope)?;
    let account = connection
        .operation_context
        .as_ref()
        .and_then(|v| v["account_id"].as_u64())
        .unwrap_or(0);
    if scope["origin"].as_str() != Some(connection.origin.origin().ascii_serialization().as_str())
        || scope["account_id"].as_u64() != Some(account)
        || scope["school_id"].as_u64()
            != connection
                .operation_context
                .as_ref()
                .and_then(|value| value["school_database_id"].as_u64())
        || account == 0
    {
        return Err("Agent 配置与当前学校账号不匹配".into());
    }
    Ok(())
}

pub(crate) fn require_account_session(connection: &Connection) -> Result<(), String> {
    let context = connection
        .operation_context
        .as_ref()
        .ok_or("学校登录会话不可用，请重新登录后打开 Agent")?;
    if context["device_jti"].as_str().is_none_or(str::is_empty)
        || context["account_id"].as_u64().unwrap_or(0) == 0
    {
        return Err("学校登录会话已失效，请重新登录当前账号后打开 Agent".into());
    }
    if context["school_database_id"].as_u64().unwrap_or(0) == 0 {
        return Err("学校会话缺少学校范围，请更新服务端后重新读取登录状态".into());
    }
    Ok(())
}

fn account_scope(connection: &Connection) -> Result<Value, String> {
    let context = connection
        .operation_context
        .as_ref()
        .ok_or("本机学校身份不可用")?;
    let scope = json!({"origin":connection.origin.origin().ascii_serialization(),"school_id":context["school_database_id"],"account_id":context["account_id"]});
    check_scope(connection, &scope)?;
    Ok(scope)
}

pub async fn action(
    app: &AppHandle,
    connection: &Connection,
    owner: &str,
    cancel: CancellationToken,
    input: &Value,
) -> Result<Value, String> {
    if connection.cancel.is_cancelled() || cancel.is_cancelled() {
        return Err("Agent 学校会话已结束".into());
    }
    require_account_session(connection)?;
    let action = input["action"].as_str().unwrap_or("");
    if matches!(action, "poll" | "cancel") {
        let run = owners()
            .lock()
            .map_err(|_| "Agent 状态不可用")?
            .get(owner)
            .and_then(|state| state.run.clone())
            .ok_or("Agent 运行已结束，请重新读取")?;
        let account = connection
            .operation_context
            .as_ref()
            .and_then(|value| value["account_id"].as_u64())
            .unwrap_or(0);
        if run.id != input["run_id"].as_str().unwrap_or("")
            || run.scope["account_id"].as_u64() != Some(account)
        {
            return Err("Agent 运行不属于当前账号".into());
        }
        if action == "cancel" {
            run.cancel.cancel();
        }
        let value = run.state.lock().map_err(|_| "Agent 状态不可用")?.clone();
        return Ok(value);
    }
    if action == "bootstrap" {
        if input["retry_storage"] == true {
            crate::system_credentials::retry();
        }
        return bootstrap(app, connection, owner).await;
    }
    if action == "sync" {
        if running(owner).is_some() {
            return Err("请先停止当前运行，再同步模型配置".into());
        }
        let scope = account_scope(connection)?;
        return agent_profile_sync::sync(app, connection, &scope, input, &cancel).await;
    }
    if matches!(action, "save" | "delete-profile") {
        if running(owner).is_some() {
            return Err("请先停止当前运行，再修改模型配置".into());
        }
        let scope = account_scope(connection)?;
        let mut store = agent_profiles::Store::open(app, &scope)?;
        let id = if action == "save" {
            Some(store.save(app, input)?)
        } else {
            store.delete(app, input)?;
            None
        };
        return Ok(json!({"profiles":store.snapshots(),"saved_profile_id":id}));
    }
    if matches!(action, "reconnect" | "restart-business-task") {
        if running(owner).is_some() {
            return Err("请先停止当前运行再处理业务连接".into());
        }
        let scope = account_scope(connection)?;
        let tools = owners()
            .lock()
            .map_err(|_| "Agent 状态不可用")?
            .entry(owner.to_owned())
            .or_default()
            .tools
            .clone();
        let mut state = tools.lock().await;
        state.bind(&scope);
        state.connection["can_manage_cli"] = json!(business_tools::can_manage_cli(connection));
        let fresh_task = action == "restart-business-task";
        if fresh_task && state.blocked {
            return Err("请先通过回执核对已发送操作，再重新连接业务工具".into());
        }
        if fresh_task
            && !["task_expired", "task_budget_exhausted"]
                .contains(&state.connection["state"].as_str().unwrap_or(""))
        {
            return Err("当前业务任务无需新建，请先读取连接状态".into());
        }
        if fresh_task || state.blocked {
            let message = if fresh_task {
                "原业务任务已过期或预算已用尽。请确认继续当前工作并新建业务任务；这不会重放已提交操作。"
            } else {
                "请先在回执或原业务页面核对已发送操作的结果。确认已核对后继续连接；不会自动重发原操作。"
            };
            confirm(app, "继续业务调用", message.into(), &cancel).await?;
        }
        if !fresh_task
            && ["task_expired", "task_budget_exhausted"]
                .contains(&state.connection["state"].as_str().unwrap_or(""))
        {
            return Ok(json!({"tool_connection":state.status()}));
        }
        state.resume(fresh_task);
        let client = connection.client.clone();
        let result = tokio::time::timeout(
            Duration::from_secs(20),
            business_tools::connect(connection, &scope, &mut state, &client, true, true),
        )
        .await;
        let ok = matches!(result, Ok(Ok(_)));
        if result.is_err() {
            let stage = state.connection["stage"].clone();
            let manage = state.connection["can_manage_cli"].clone();
            state.connection = json!({"state":"error","stage":stage,"error":"connect_timeout","message":"学校业务连接超时，请检查网络或稍后重试；无需提供数据库地址或密码","action":"retry","can_manage_cli":manage});
        }
        return Ok(json!({"ok":ok,"tool_connection":state.status()}));
    }
    if matches!(action, "open-cli-authorization" | "open-binding") {
        let mut url = connection
            .origin
            .join("pc/index.html")
            .map_err(|_| "学校入口无效")?;
        if action == "open-cli-authorization" {
            let config = school_api::read(connection, "api/client-agent/bootstrap").await?;
            let allowed = config["tool_policy"]["can_manage_cli"]
                .as_bool()
                .unwrap_or_else(|| business_tools::can_manage_cli(connection));
            if !allowed {
                return Err("当前账号不能管理本校 CLI 授权，请联系学校管理员开放对应功能".into());
            }
            url.set_fragment(Some("panel=config:cliAuthorization"));
        } else {
            url.set_fragment(Some("module=profile"));
        }
        if cancel.is_cancelled() || connection.cancel.is_cancelled() {
            return Err("学校会话已结束".into());
        }
        app.opener()
            .open_url(url.as_str(), None::<&str>)
            .map_err(|_| "无法打开学校页面，请从客户端进入对应设置")?;
        return Ok(json!({"ok":true}));
    }
    let scope = account_scope(connection)?;
    if matches!(action, "models" | "probe") {
        let supplied_key = input["api_key"].as_str().unwrap_or("");
        let mut model = ModelConfig(if supplied_key.is_empty() {
            let mut saved = agent_profiles::Store::open(app, &scope)?
                .resolve(input["profile_id"].as_str().unwrap_or(""), false)?;
            if let Some(endpoint) = input["endpoint"].as_str().filter(|value| !value.is_empty()) {
                if agent_models::endpoint(endpoint)?
                    != agent_models::endpoint(saved["endpoint"].as_str().unwrap_or(""))?
                {
                    wipe_key(&mut saved);
                    return Err("更换接口地址后请重新填写 API Key".into());
                }
            }
            saved
        } else {
            json!({"endpoint":input["endpoint"],"api_key":supplied_key,"model":input["model"]})
        });
        if let Some(name) = input["model"].as_str().filter(|value| !value.is_empty()) {
            model["model"] = json!(name);
        }
        return agent_models::inspect(&model, action, &cancel).await;
    }
    let config = bootstrap(app, connection, owner).await?;
    if action == "open-operation" {
        let id = input["operation_id"].as_str().ok_or("操作编号无效")?;
        uuid::Uuid::parse_str(id).map_err(|_| "操作编号无效")?;
        let operation = config["workspace"]["conversations"]
            .as_array()
            .into_iter()
            .flatten()
            .flat_map(|item| item["operations"].as_array().into_iter().flatten())
            .find(|item| item["operation_id"] == id)
            .ok_or("本机会话中没有此操作")?;
        if operation["origin"] != scope["origin"]
            || operation["school_id"] != scope["school_id"]
            || operation["account_id"] != scope["account_id"]
        {
            return Err("请切换到此操作所属的学校账号后查阅回执".into());
        }
        let mut url = connection
            .origin
            .join("pc/index.html")
            .map_err(|_| "回执地址无效")?;
        url.query_pairs_mut().append_pair("cli_operation", id);
        if cancel.is_cancelled() || connection.cancel.is_cancelled() {
            return Err("学校会话已结束".into());
        }
        app.opener()
            .open_url(url.as_str(), None::<&str>)
            .map_err(|_| "无法打开回执，请复制地址到浏览器")?;
        return Ok(json!({"ok":true}));
    }
    if action == "workspace" {
        return Ok(
            json!({"workspace":config["workspace"],"history":config["history"],"conversation_id":config["conversation_id"]}),
        );
    }
    if running(owner).is_some() {
        return Err("请先等待或停止当前运行，再修改本机工作区".into());
    }
    if matches!(
        action,
        "workspace-edit" | "import-skill" | "probe-mcp" | "clear"
    ) {
        let imported = if action == "import-skill" {
            let app = app.clone();
            Some(
                tokio::select! {biased;_=cancel.cancelled()=>return Err("Skill 导入已取消".into()),result=tokio::task::spawn_blocking(move||agent_workspace::import_skill(&app))=>result.map_err(|_|"无法选择 Skill 文件")??},
            )
        } else {
            None
        };
        let guard = agent_store::acquire(app, &scope)?;
        let mut data = agent_workspace::normalize(&scope, agent_store::read_locked(app, &scope)?);
        if action == "probe-mcp" {
            let plugin = data["plugins"]
                .as_array()
                .and_then(|items| {
                    items
                        .iter()
                        .find(|item| item["id"] == input["id"] && item["type"] == "mcp")
                })
                .cloned()
                .ok_or("MCP 插件不存在")?;
            drop(guard);
            approve_plugin(app, &plugin, &cancel).await?;
            return probe_mcp(app, &plugin, &cancel).await;
        }
        let edit_input = if let Some(plugin) = imported {
            json!({"section":"plugins","operation":"save","item":plugin})
        } else {
            input.clone()
        };
        if action == "clear" {
            let id = data["active_conversation_id"].clone();
            if let Some(items) = data["conversations"].as_array_mut() {
                if let Some(item) = items.iter_mut().find(|item| item["id"] == id) {
                    item["history"] = json!([]);
                    item["messages"] = json!([]);
                }
            }
        } else {
            let mut candidate = data.clone();
            let result = agent_workspace::edit(&mut candidate, &edit_input)?;
            if edit_input["section"] == "plugins" && edit_input["operation"] == "save" {
                if let Some(plugin) = candidate["plugins"]
                    .as_array()
                    .and_then(|items| items.iter().find(|item| item["id"] == result["id"]))
                    .filter(|item| item["type"] == "mcp" && item["enabled"] == true)
                {
                    approve_plugin(app, plugin, &cancel).await?;
                }
            }
            data = candidate;
            if edit_input["operation"] == "run" && edit_input["section"] == "tasks" {
                let task = result["item"].clone();
                agent_store::write(app, &scope, &data)?;
                drop(guard);
                return start_task(app, connection, owner, cancel, &task).await;
            }
            if edit_input["operation"] == "run" && edit_input["section"] == "reminders" {
                show_reminder(app, &result["item"]);
                agent_workspace::finish(
                    &mut data,
                    "reminder",
                    result["id"].as_str().unwrap_or(""),
                    "completed",
                    now(),
                )?;
            }
        }
        if cancel.is_cancelled() || connection.cancel.is_cancelled() {
            return Err("学校会话已结束，未保存本机配置".into());
        }
        agent_store::write(app, &scope, &data)?;
        return snapshot(&data);
    }
    if action != "start" {
        return Err("Agent 操作无效".into());
    }
    start_run(app, connection, owner, cancel, input, config).await
}

async fn start_run(
    app: &AppHandle,
    connection: &Connection,
    owner: &str,
    cancel: CancellationToken,
    input: &Value,
    config: Value,
) -> Result<Value, String> {
    let scope = config["scope"].clone();
    let mode = input["mode"].as_str().unwrap_or("");
    if !matches!(mode, "personal" | "school") {
        return Err("请选择模型来源".into());
    }
    let profile_id = input["profile_id"].as_str().unwrap_or("");
    let selected = if mode == "personal" {
        config["profiles"]
            .as_array()
            .and_then(|items| {
                if profile_id.is_empty() {
                    items.first()
                } else {
                    items.iter().find(|item| item["id"] == profile_id)
                }
            })
            .unwrap_or(&config["personal"])
    } else {
        &config["school"]
    };
    if selected["enabled"] != true {
        return Err("所选模型尚未启用，请检查配置".into());
    }
    if mode == "personal" && !profile_id.is_empty() && selected["id"] != profile_id {
        return Err("所选个人模型配置不存在".into());
    }
    let model = input["model"]
        .as_str()
        .filter(|name| !name.is_empty())
        .or_else(|| selected["model"].as_str())
        .unwrap_or("");
    if model.is_empty() || model.len() > 160 || model.chars().any(char::is_control) {
        return Err("请选择有效模型".into());
    }
    if mode == "school" && selected["model"] != model {
        return Err("学校统一模型由管理员配置".into());
    }
    let prompt = input["prompt"].as_str().unwrap_or("").trim();
    if prompt.is_empty() || prompt.chars().count() > 12000 {
        return Err("请输入不超过 12000 字的问题".into());
    }
    let guard = agent_store::acquire(app, &scope)?;
    let mut stored = agent_workspace::normalize(&scope, agent_store::read_locked(app, &scope)?);
    if let Some(id) = input["conversation_id"]
        .as_str()
        .filter(|id| !id.is_empty())
    {
        agent_workspace::edit(
            &mut stored,
            &json!({"section":"conversations","operation":"select","id":id}),
        )?;
    }
    let active_id = stored["active_conversation_id"].clone();
    if let Some(item) = stored["conversations"]
        .as_array_mut()
        .and_then(|items| items.iter_mut().find(|item| item["id"] == active_id))
    {
        if item["mode"] != mode
            || item["profile_id"] != profile_id
            || item["model"] != model
            || item["execution_scope"] != scope
        {
            item["revision"] = Value::Null;
            item["messages"] = json!([]);
        }
        item["execution_scope"] = scope.clone();
        item["mode"] = json!(mode);
        item["profile_id"] = json!(profile_id);
        item["model"] = json!(model);
    }
    let conversation_id = stored["active_conversation_id"]
        .as_str()
        .ok_or("会话标识无效")?
        .to_owned();
    let options = json!({"mode":mode,"profile_id":profile_id,"model":model,"conversation_id":conversation_id,"scheduled":input["scheduled"]==true,"task_id":input["task_id"]});
    agent_store::write(app, &scope, &stored)?;
    let initial = snapshot(&stored)?;
    let run = Arc::new(Run {
        id: uuid::Uuid::new_v4().to_string(),
        scope: scope.clone(),
        cancel: cancel.child_token(),
        state: Mutex::new(
            json!({"status":"running","text":"","history":initial["history"],"workspace":initial["workspace"],"conversation_id":conversation_id,"tool_status":"正在准备模型与工具","tool_connection":config["tool_connection"]}),
        ),
        options,
    });
    update(&run, json!({"run_id":run.id}));
    let tools = {
        let mut owners = owners().lock().map_err(|_| "Agent 状态不可用")?;
        let state = owners.entry(owner.to_owned()).or_default();
        if state.run.as_ref().is_some_and(|run| {
            run.state
                .lock()
                .is_ok_and(|state| state["status"] == "running")
        }) {
            return Err("当前账号已有 Agent 正在运行".into());
        }
        state.run = Some(run.clone());
        state.tools.clone()
    };
    let app = app.clone();
    let connection = connection.clone();
    let prompt = prompt.to_owned();
    let mode = mode.to_owned();
    let running = run.clone();
    tauri::async_runtime::spawn(async move {
        let _guard = guard;
        let result = tokio::select! {biased;
            _=running.cancel.cancelled()=>Err("运行已停止；已发送的写请求请先核对结果".to_owned()),
            _=connection.cancel.cancelled()=>Err("学校连接已关闭".to_owned()),
            _=tokio::time::sleep(Duration::from_secs(180))=>Err("运行达到 180 秒上限，已停止；写请求请先核对结果".to_owned()),
            result=execute(&app,&connection,&running,&mode,&prompt,&config,stored,tools.clone())=>result,
        };
        if result.is_err() {
            cancel_pending_operation(&app, &connection, &running, &tools).await;
            let pending = running
                .state
                .lock()
                .is_ok_and(|value| value["business_write_pending"] == true);
            if pending {
                let mut state = tools.lock().await;
                state.paused();
                update(&running, json!({"tool_connection":state.status()}));
            }
        }
        let status = if result.is_ok() {
            "completed"
        } else if running.cancel.is_cancelled() || connection.cancel.is_cancelled() {
            "cancelled"
        } else {
            "failed"
        };
        if let Ok(mut data) = agent_store::read_locked(&app, &running.scope)
            .map(|data| agent_workspace::normalize(&running.scope, data))
        {
            if let Some(id) = running.options["task_id"].as_str() {
                let _ = agent_workspace::finish(&mut data, "task", id, status, now());
            }
            let _ = agent_store::write(&app, &running.scope, &data);
            if let Ok(snapshot) = snapshot(&data) {
                update(&running, snapshot);
            }
        }
        match result {
            Ok(value) => update(
                &running,
                json!({"status":"done","text":value["answer"],"history":value["history"],"tool_status":"运行结束"}),
            ),
            Err(message) => update(
                &running,
                json!({"status":if status=="cancelled"{"cancelled"}else{"error"},"error":message,"tool_status":"已停止"}),
            ),
        };
    });
    Ok(
        json!({"run_id":run.id,"workspace":initial["workspace"],"history":initial["history"],"conversation_id":conversation_id}),
    )
}

fn now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|value| value.as_secs() as i64)
        .unwrap_or(0)
}

async fn confirm(
    app: &AppHandle,
    title: &str,
    description: String,
    cancel: &CancellationToken,
) -> Result<(), String> {
    if cancel.is_cancelled() {
        return Err("操作已取消".into());
    }
    let dialog = app.dialog().message(description).title(title).buttons(
        MessageDialogButtons::OkCancelCustom("允许".into(), "拒绝".into()),
    );
    let accepted = tokio::select! {biased;_=cancel.cancelled()=>return Err("操作已取消".into()),result=tokio::task::spawn_blocking(move||dialog.blocking_show())=>result.map_err(|_|"无法显示原生确认窗口")?};
    if !accepted || cancel.is_cancelled() {
        return Err("用户未批准操作或会话已结束".into());
    }
    Ok(())
}

async fn approve_plugin(
    app: &AppHandle,
    plugin: &Value,
    cancel: &CancellationToken,
) -> Result<(), String> {
    let description = if plugin["transport"] == "stdio" {
        format!("插件：{}\n命令：{}\n参数：{}\n\n该程序将以当前用户的系统权限在本机运行。仅启用你信任的服务。配置的环境变量不会在此显示。",plugin["name"].as_str().unwrap_or("MCP"),plugin["command"].as_str().unwrap_or(""),serde_json::to_string(&plugin["args"]).unwrap_or_default())
    } else {
        format!(
            "插件：{}\n服务：{}\n\n客户端将直接连接此 MCP 服务，工具调用仍需逐次确认。",
            plugin["name"].as_str().unwrap_or("MCP"),
            plugin["url"].as_str().unwrap_or("")
        )
    };
    if description.len() > 16000 {
        return Err("插件配置过长，无法完整确认".into());
    }
    confirm(app, "确认 MCP 服务", description, cancel).await
}

fn show_reminder(app: &AppHandle, item: &Value) {
    app.dialog()
        .message(item["content"].as_str().unwrap_or("本机提醒已到期"))
        .title(item["name"].as_str().unwrap_or("Agent 提醒"))
        .show(|_| {});
}

async fn approve_mcp_call(
    app: &AppHandle,
    run: &Run,
    plugins: &Value,
    params: &Value,
) -> Result<Value, String> {
    if run.options["scheduled"] == true {
        return Err("计划任务不允许调用 MCP 工具".into());
    }
    let plugin = plugins
        .as_array()
        .and_then(|items| {
            items.iter().find(|item| {
                item["id"] == params["plugin_id"]
                    && item["type"] == "mcp"
                    && item["enabled"] == true
            })
        })
        .ok_or("MCP 插件未启用")?;
    let tool = params["tool"]
        .as_str()
        .filter(|value| {
            !value.is_empty() && value.len() <= 128 && !value.chars().any(char::is_control)
        })
        .ok_or("MCP 工具名无效")?;
    let preview = serde_json::to_string_pretty(&params["arguments"]).map_err(|_| "MCP 参数无效")?;
    if preview.len() > 16000 || !params["arguments"].is_object() {
        return Err("MCP 参数过长或格式无效，无法确认".into());
    }
    update(run, json!({"tool_status":format!("等待确认 MCP：{tool}")}));
    if let Err(message)=confirm(app,"确认 MCP 工具调用",format!("插件：{}\n工具：{tool}\n学校：{}\n\n参数：\n{preview}\n\n插件可能读取或修改本机及外部服务数据，请核对后确认。",plugin["name"].as_str().unwrap_or(""),run.scope["origin"].as_str().unwrap_or("")),&run.cancel).await {run.cancel.cancel();return Err(message);}
    Ok(json!({"approved":true}))
}

async fn start_task(
    app: &AppHandle,
    connection: &Connection,
    owner: &str,
    cancel: CancellationToken,
    task: &Value,
) -> Result<Value, String> {
    let scope = owners()
        .lock()
        .map_err(|_| "Agent 状态不可用")?
        .get(owner)
        .map(|state| state.scope.clone())
        .ok_or("Agent 尚未初始化")?;
    let result=async {
        let config=bootstrap(app,connection,owner).await?;
        let guard=agent_store::acquire(app,&scope)?;
        let mut data=agent_workspace::normalize(&scope,agent_store::read_locked(app,&scope)?);
        agent_workspace::edit(&mut data,&json!({"section":"conversations","operation":"save","item":{"title":format!("定时任务：{}",task["name"].as_str().unwrap_or("任务"))}}))?;
        let id=data["active_conversation_id"].clone();
        agent_store::write(app,&scope,&data)?;drop(guard);
        let input=json!({"mode":task["mode"],"profile_id":task["profile_id"],"model":if task["mode"]=="school"{config["school"]["model"].clone()}else{task["model"].clone()},"conversation_id":id,"prompt":task["prompt"],"scheduled":true,"task_id":task["id"]});
        start_run(app,connection,owner,cancel,&input,config).await
    }.await;
    if result.is_err() {
        if let Ok(_guard) = agent_store::acquire(app, &scope) {
            if let Ok(mut data) = agent_store::read_locked(app, &scope)
                .map(|data| agent_workspace::normalize(&scope, data))
            {
                let _ = agent_workspace::finish(
                    &mut data,
                    "task",
                    task["id"].as_str().unwrap_or(""),
                    "failed",
                    now(),
                );
                let _ = agent_store::write(app, &scope, &data);
            }
        }
    }
    result
}

pub async fn scheduler_tick(
    app: &AppHandle,
    connection: &Connection,
    owner: &str,
    cancel: CancellationToken,
    interrupted: bool,
) -> Result<(), String> {
    if running(owner).is_some() {
        return Ok(());
    }
    let context = connection
        .operation_context
        .as_ref()
        .ok_or("本机学校身份不可用")?;
    let scope = json!({"origin":connection.origin.origin().ascii_serialization(),"school_id":context["school_database_id"],"account_id":context["account_id"]});
    check_scope(connection, &scope)?;
    let recovered = {
        let mut owners = owners().lock().map_err(|_| "Agent 状态不可用")?;
        let state = owners.entry(owner.to_owned()).or_default();
        state.scope = scope.clone();
        state.schedule_recovered
    };
    let guard = match agent_store::acquire(app, &scope) {
        Ok(guard) => guard,
        Err(_) => return Ok(()),
    };
    let mut data = agent_workspace::normalize(&scope, agent_store::read_locked(app, &scope)?);
    let before = data.clone();
    let ready = agent_workspace::due(&mut data, now(), interrupted || !recovered);
    let mut task = None;
    for item in ready {
        let id = item["id"].as_str().unwrap_or("");
        if item["kind"] == "reminder" {
            show_reminder(app, &item);
            agent_workspace::finish(&mut data, "reminder", id, "completed", now())?;
        } else if task.is_none() {
            task = Some(item);
        } else {
            agent_workspace::finish(&mut data, "task", id, "missed", now())?;
        }
    }
    if data != before {
        agent_store::write(app, &scope, &data)?;
    }
    drop(guard);
    if let Ok(mut owners) = owners().lock() {
        if let Some(state) = owners.get_mut(owner) {
            state.schedule_recovered = true;
        }
    }
    if let Some(task) = task {
        start_task(app, connection, owner, cancel, &task).await?;
    }
    Ok(())
}

async fn agent_post(
    connection: &Connection,
    run: &Run,
    path: &str,
    body: &Value,
) -> Result<Value, String> {
    let _session = tokio::select! {
        biased;
        _ = run.cancel.cancelled() => return Err("Agent 运行已停止".into()),
        guard = connection.session_lock.read() => guard,
    };
    if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
        return Err("Agent 学校会话已结束".into());
    }
    school_api::client_agent_post(connection, path, body).await
}

fn wipe_key(value: &mut Value) {
    if let Some(Value::String(key)) = value.get_mut("api_key") {
        key.zeroize();
    }
}

async fn decode(mut response: reqwest::Response, limit: usize) -> Result<Value, String> {
    let success = response.status().is_success();
    let mut bytes = Vec::new();
    while let Some(chunk) = response.chunk().await.map_err(|_| "读取学校工具响应失败")? {
        if bytes.len() + chunk.len() > limit {
            return Err("工具返回数据过多，请缩小查询范围".into());
        }
        bytes.extend_from_slice(&chunk);
    }
    let value: Value = serde_json::from_slice(&bytes).map_err(|_| "学校工具未返回 JSON 数据")?;
    if !success || value["code"].as_i64() != Some(0) {
        return Err(value["message"]
            .as_str()
            .unwrap_or("业务权限校验或调用失败")
            .chars()
            .take(400)
            .collect());
    }
    Ok(value["data"].clone())
}

async fn cli(
    client: &reqwest::Client,
    connection: &Connection,
    token: &str,
    method: &str,
    path: &str,
    body: &Value,
    task: &str,
    write: bool,
) -> Result<Value, String> {
    cli_request(
        client,
        connection,
        token,
        method,
        path,
        body,
        task,
        write,
        None,
        RESULT_LIMIT,
    )
    .await
}

async fn cli_request(
    client: &reqwest::Client,
    connection: &Connection,
    token: &str,
    method: &str,
    path: &str,
    body: &Value,
    task: &str,
    write: bool,
    operation_id: Option<&str>,
    limit: usize,
) -> Result<Value, String> {
    let mut url = connection
        .origin
        .join(path)
        .map_err(|_| "工具接口地址无效")?;
    if url.origin() != connection.origin.origin()
        || !url.path().starts_with("/api/")
        || url.query().is_some()
    {
        return Err("工具接口来源无效".into());
    }
    let method = reqwest::Method::from_bytes(method.as_bytes()).map_err(|_| "工具请求方法无效")?;
    if method == reqwest::Method::GET {
        if let Some(args) = body.as_object() {
            let mut query = url.query_pairs_mut();
            for (k, v) in args {
                query.append_pair(
                    k,
                    &v.as_str()
                        .map(str::to_owned)
                        .unwrap_or_else(|| v.to_string()),
                );
            }
        }
    }
    let mut request = client
        .request(method.clone(), url)
        .bearer_auth(token)
        .header("x-cli-operation-version", "1")
        .timeout(Duration::from_secs(45));
    if path.starts_with("api/cli/operations/") && method == reqwest::Method::POST {
        request = request.timeout(Duration::from_secs(
            if path == "api/cli/operations/cancel" {
                5
            } else {
                180
            },
        ));
    }
    if !task.is_empty() {
        request = request.header("x-cli-task", task);
    }
    if write {
        request = request.header(
            "x-idempotency-key",
            operation_id
                .map(str::to_owned)
                .unwrap_or_else(|| uuid::Uuid::new_v4().to_string()),
        );
    }
    if method != reqwest::Method::GET {
        request = request.json(body);
    }
    let response = request.send().await.map_err(|_| {
        if write {
            "写请求连接中断，结果未知，请先查询核对，不要直接重试"
        } else {
            "学校工具连接失败"
        }
    })?;
    decode(response, limit).await
}

async fn operation_request(
    client: &reqwest::Client,
    connection: &Connection,
    session: &Tools,
    method: &str,
    path: &str,
    body: &Value,
    operation_id: &str,
) -> Result<Value, String> {
    let task = if matches!(
        path,
        "api/cli/operations/prepare" | "api/cli/operations/execute"
    ) {
        session.task.as_str()
    } else {
        ""
    };
    cli_request(
        client,
        connection,
        &session.access,
        method,
        path,
        body,
        task,
        method == "POST",
        Some(operation_id),
        OPERATION_LIMIT,
    )
    .await
}

fn remember_operation(app: &AppHandle, run: &Run, operation: Value) -> Result<(), String> {
    update(run, json!({"operation":operation}));
    let saved = (|| {
        let mut data =
            agent_workspace::normalize(&run.scope, agent_store::read_locked(app, &run.scope)?);
        let conversation = data["conversations"]
            .as_array_mut()
            .ok_or("本机会话无效")?
            .iter_mut()
            .find(|item| item["id"] == run.options["conversation_id"])
            .ok_or("本机会话不存在")?;
        let mut operations = conversation["operations"]
            .as_array()
            .cloned()
            .unwrap_or_default();
        operations.retain(|item| item["operation_id"] != operation["operation_id"]);
        operations.push(operation);
        if operations.len() > 50 {
            operations.drain(..operations.len() - 50);
        }
        conversation["operations"] = json!(operations);
        agent_store::write(app, &run.scope, &data)?;
        update(run, snapshot(&data)?);
        Ok::<(), String>(())
    })();
    update(
        run,
        json!({"operation_storage_error":saved.as_ref().err().map(|message| format!("操作查阅记录未保存：{message}。请复制本次回执地址。"))}),
    );
    saved
}

fn remember_operation_state(app: &AppHandle, run: &Run, state: &Value) {
    let Some(mut operation) = run
        .state
        .lock()
        .ok()
        .and_then(|value| value.get("operation").cloned())
    else {
        return;
    };
    operation["state"] = json!(match state.as_str() {
        Some(
            value @ ("pending" | "approved" | "executing" | "completed" | "failed" | "unknown"
            | "denied" | "cancelled" | "expired"),
        ) => value,
        _ => "unknown",
    });
    operation["updated_at"] = json!(now());
    let _ = remember_operation(app, run, operation);
}

async fn cancel_pending_operation(
    app: &AppHandle,
    connection: &Connection,
    run: &Run,
    tools: &Arc<tokio::sync::Mutex<Tools>>,
) {
    let operation_id = run.state.lock().ok().and_then(|state| {
        if state["operation_pending"] == true {
            state["operation_id"].as_str().map(str::to_owned)
        } else {
            None
        }
    });
    let Some(id) = operation_id else {
        return;
    };
    let client = connection.client.clone();
    let mut session = tools.lock().await;
    session.paused();
    update(run, json!({"tool_connection":session.status()}));
    if let Ok(value) = operation_request(
        &client,
        connection,
        &session,
        "POST",
        "api/cli/operations/cancel",
        &json!({"operation_id":id}),
        &id,
    )
    .await
    {
        update(
            run,
            json!({"operation_pending":false,"operation_state":value["state"]}),
        );
        remember_operation_state(app, run, &value["state"]);
    } else {
        remember_operation_state(app, run, &json!("unknown"));
    }
}

fn operation_result(mut value: Value, receipt_url: &str) -> Result<Value, String> {
    value["complete_receipt_url"] = json!(receipt_url);
    value.as_object_mut().map(|object| object.remove("preview"));
    if serde_json::to_vec(&value)
        .map_err(|_| "业务回执格式无效")?
        .len()
        <= RESULT_LIMIT - 1024
    {
        return Ok(value);
    }
    let mut result = json!({"operation_id":value["operation_id"],"capability_id":value["capability_id"],"state":value["state"],"error":value["error"],"truncated":true,"complete_receipt_url":receipt_url,"message":"完整回执超过模型输入上限，请打开完整回执地址查看逐项结果；不得据此宣称所有条目成功。"});
    for key in ["total", "created", "updated", "skipped", "failed"] {
        if value["result"][key].is_number() {
            result[key] = value["result"][key].clone();
        }
    }
    Ok(result)
}

async fn call_confirmed_tool(
    app: &AppHandle,
    connection: &Connection,
    run: &Run,
    client: &reqwest::Client,
    session: &mut Tools,
    cap: &Value,
    params: &Value,
) -> Result<Value, String> {
    let id = uuid::Uuid::new_v4().to_string();
    let mut receipt_url = connection
        .origin
        .join("pc/index.html")
        .map_err(|_| "确认地址无效")?;
    receipt_url
        .query_pairs_mut()
        .append_pair("cli_operation", &id);
    update(
        run,
        json!({"operation_id":id,"operation_pending":true,"operation_url":receipt_url.as_str(),"tool_status":"正在生成服务器操作预览"}),
    );
    remember_operation(
        app,
        run,
        json!({"operation_id":id,"receipt_url":receipt_url.as_str(),"origin":run.scope["origin"],"school_id":run.scope["school_id"],"account_id":run.scope["account_id"],"capability_id":params["capability_id"],"label":cap["label"],"state":"preparing","created_at":now(),"updated_at":now()}),
    )?;
    let prepared = match operation_request(
        client,
        connection,
        session,
        "POST",
        "api/cli/operations/prepare",
        &json!({"capability_id":params["capability_id"],"arguments":params["arguments"]}),
        &id,
    )
    .await
    {
        Ok(value) => value,
        Err(message) => {
            session.paused();
            remember_operation_state(app, run, &json!("unknown"));
            return Ok(
                json!({"operation_id":id,"state":"unknown","complete_receipt_url":receipt_url.as_str(),"message":format!("预览请求未取得确定结果：{message}。请查询此操作，不能换操作编号自动重试。") }),
            );
        }
    };
    if prepared["operation_id"] != id
        || prepared["state"] != "pending"
        || !prepared["preview"].is_object()
        || prepared["preview_hash"]
            .as_str()
            .is_none_or(|hash| hash.len() != 64)
    {
        session.paused();
        remember_operation_state(app, run, &json!("unknown"));
        return Err("服务器操作预览不完整，请查询该操作状态".into());
    }
    remember_operation_state(app, run, &prepared["state"]);
    let preview =
        serde_json::to_string_pretty(&prepared["preview"]).map_err(|_| "操作预览格式无效")?;
    let label = cap["label"].as_str().unwrap_or("业务操作");
    update(run, json!({"tool_status":format!("等待人工确认：{label}")}));
    let decision = if preview.len() <= 16000 {
        let description = format!(
            "学校：{}\n账号 ID：{}\n\n服务器预览：\n{preview}\n\n确认仅授权执行这一操作。",
            run.scope["origin"].as_str().unwrap_or(""),
            run.scope["account_id"]
        );
        match confirm(app, "确认业务操作", description, &run.cancel).await {
            Ok(()) => {
                let _guard = connection.session_lock.read().await;
                if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
                    Err("运行已停止，未批准操作".into())
                } else {
                    school_api::cli_operation_post(
                        connection,
                        "api/cli/operations/approve",
                        &json!({"operation_id":id,"preview_hash":prepared["preview_hash"]}),
                    )
                    .await
                }
            }
            Err(message) => Err(message),
        }
    } else {
        match confirm(app, "打开完整业务预览", format!("操作：{label}\n预览内容较多，请在学校确认页核对完整参数和影响，再人工批准。\n\n{}\n\n客户端将等待这一个操作的确认结果。",receipt_url.as_str()), &run.cancel).await {
            Ok(()) => {
                app.opener().open_url(receipt_url.as_str(), None::<&str>).map_err(|_| "无法打开完整预览，请使用确认地址")?;
                let wait = async {
                    loop {
                        tokio::select! {biased; _=run.cancel.cancelled()=>return Err("运行已取消".to_owned()), _=connection.cancel.cancelled()=>return Err("学校会话已结束".to_owned()), _=tokio::time::sleep(Duration::from_secs(3))=>()};
                        let value = operation_request(client, connection, session, "GET", "api/cli/operations/status", &json!({"operation_id":id}), &id).await?;
                        if value["state"] != "pending" { return Ok(value); }
                    }
                };
                match tokio::time::timeout(Duration::from_secs(120), wait).await { Ok(value) => value, Err(_) => Err("等待人工确认超时，未执行操作".into()) }
            }
            Err(message) => Err(message),
        }
    };
    let decision = match decision {
        Ok(value) => value,
        Err(message) => {
            session.paused();
            let cancelled = operation_request(
                client,
                connection,
                session,
                "POST",
                "api/cli/operations/cancel",
                &json!({"operation_id":id}),
                &id,
            )
            .await;
            update(
                run,
                json!({"operation_pending":cancelled.is_err(),"tool_status":message}),
            );
            remember_operation_state(
                app,
                run,
                &cancelled
                    .as_ref()
                    .map(|value| value["state"].clone())
                    .unwrap_or(json!("unknown")),
            );
            return operation_result(
                cancelled.unwrap_or_else(
                    |_| json!({"operation_id":id,"state":"unknown","message":message}),
                ),
                receipt_url.as_str(),
            );
        }
    };
    remember_operation_state(app, run, &decision["state"]);
    if decision["state"] != "approved" {
        session.paused();
        update(
            run,
            json!({"operation_pending":false,"operation_state":decision["state"]}),
        );
        return operation_result(decision, receipt_url.as_str());
    }
    if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
        return Err("运行已停止，未执行本次操作".into());
    }
    update(
        run,
        json!({"tool_status":format!("正在执行已确认操作：{label}")}),
    );
    remember_operation_state(app, run, &json!("executing"));
    if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
        return Err("运行已停止，未执行本次操作".into());
    }
    let result = match operation_request(
        client,
        connection,
        session,
        "POST",
        "api/cli/operations/execute",
        &json!({"operation_id":id}),
        &id,
    )
    .await
    {
        Ok(value) => value,
        Err(message) => {
            session.paused();
            operation_request(client, connection, session, "GET", "api/cli/operations/status", &json!({"operation_id":id}), &id).await.unwrap_or_else(|_| json!({"operation_id":id,"state":"unknown","message":format!("{message}；请先核对回执，不能重新执行。") }))
        }
    };
    let terminal = matches!(
        result["state"].as_str(),
        Some("completed" | "failed" | "unknown" | "denied" | "cancelled" | "expired")
    );
    if result["state"] != "completed" {
        session.paused();
    }
    update(
        run,
        json!({"operation_pending":!terminal,"operation_state":result["state"]}),
    );
    remember_operation_state(app, run, &result["state"]);
    operation_result(result, receipt_url.as_str())
}

async fn tools(
    connection: &Connection,
    run: &Run,
    state: &mut Tools,
    _device_id: &str,
    client: &reqwest::Client,
) -> Result<Value, String> {
    update(
        run,
        json!({"tool_connection":{"state":"connecting","message":"正在自动读取当前学校账号的业务能力","capability_count":0,"action":"none"}}),
    );
    let _guard = connection.session_lock.read().await;
    if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
        return Err("当前学校会话已结束".into());
    }
    let result = business_tools::connect(connection, &run.scope, state, client, true, false).await;
    update(run, json!({"tool_connection":state.status()}));
    result
}

async fn execute(
    app: &AppHandle,
    connection: &Connection,
    run: &Arc<Run>,
    mode: &str,
    prompt: &str,
    config: &Value,
    mut stored: Value,
    tools_state: Arc<tokio::sync::Mutex<Tools>>,
) -> Result<Value, String> {
    let device_id = uuid::Uuid::new_v4().to_string();
    let conversation = agent_workspace::active(&stored)?.clone();
    let profile_id = run.options["profile_id"].as_str().unwrap_or("");
    let mut model = ModelConfig(if mode == "personal" {
        agent_profiles::Store::open(app, &run.scope)?.resolve(profile_id, true)?
    } else {
        config["school"].clone()
    });
    model["model"] = run.options["model"].clone();
    if mode == "personal" {
        model["endpoint"] =
            json!(agent_models::endpoint(model["endpoint"].as_str().unwrap_or(""))?.as_str());
    }
    let revision = model["revision"].as_str().unwrap_or("").to_owned();
    let actual_profile = model["profile_id"]
        .as_str()
        .unwrap_or(profile_id)
        .to_owned();
    let selected_model = model["model"].as_str().unwrap_or("").to_owned();
    let messages = if conversation["mode"] == mode
        && conversation["execution_scope"] == run.scope
        && conversation["revision"] == revision
        && conversation["profile_id"] == actual_profile
        && conversation["model"] == selected_model
    {
        conversation["messages"].clone()
    } else {
        json!(conversation["history"].as_array().into_iter().flatten().filter_map(|item|{
            let role=item["role"].as_str()?;let text=item["text"].as_str()?;
            match role {"user"=>Some(json!({"role":"user","content":text,"timestamp":now()*1000})),"assistant"=>Some(json!({"role":"assistant","content":[{"type":"text","text":text}],"timestamp":now()*1000})),_=>None}
        }).collect::<Vec<_>>())
    };
    let plugins = if run.options["scheduled"] == true {
        json!([])
    } else {
        plugin_configs(&stored)
    };
    let client = connection.client.clone();
    let mut session = tools_state.lock().await;
    let builtin_enabled = stored["plugins"]
        .as_array()
        .into_iter()
        .flatten()
        .any(|plugin| plugin["builtin"] == true && plugin["enabled"] == true);
    let mut catalog = if !builtin_enabled {
        if !session.blocked {
            session.connection = json!({"state":"disabled","error":"builtin_disabled","message":"本项目内置 Skill 已停用，请在插件中启用","action":"enable_plugin"});
        }
        update(run, json!({"tool_connection":session.status()}));
        json!([])
    } else {
        match tools(connection, run, &mut session, &device_id, &client).await {
            Ok(value) => value,
            Err(message) => {
                update(run, json!({"tool_status":format!("仅模型对话：{message}")}));
                json!([])
            }
        }
    };
    if run.options["scheduled"] == true {
        catalog = json!(catalog
            .as_array()
            .into_iter()
            .flatten()
            .filter(|cap| cap["kind"] == "read")
            .cloned()
            .collect::<Vec<_>>());
    }
    let mut child = spawn_runtime(app)?;
    let mut stdin = child.stdin.take().ok_or("Agent 输入通道不可用")?;
    let stdout = child.stdout.take().ok_or("Agent 输出通道不可用")?;
    let packet = json!({"type":"start","run_id":run.id,"mode":mode,"config":{"model":model["model"]},"prompt":prompt,"messages":messages,"capabilities":catalog,"plugins":plugins,"workspace_context":agent_workspace::context(&stored),"tool_connection":session.status()});
    let sent = send(&mut stdin, &packet).await;
    sent?;
    drop(packet);
    let mut reader = BufReader::new(stdout);
    let mut rpc_count = 0usize;
    loop {
        if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
            return Err("运行已停止；已发送的写请求请先核对结果".into());
        }
        let line = bounded_line(&mut reader)
            .await?
            .ok_or("Agent 进程意外结束，请检查完整安装包")?;
        let mut value: Value = serde_json::from_slice(&line).map_err(|_| "Agent 通道格式无效")?;
        let key = model["api_key"].as_str().unwrap_or("");
        let protected = if mode == "personal" {
            match value["type"].as_str() {
                Some("rpc") if matches!(value["method"].as_str(), Some("tool" | "confirm_mcp")) => {
                    value
                        .get_mut("params")
                        .and_then(|params| params.get_mut("arguments"))
                        .is_some_and(|arguments| agent_models::redact(arguments, key))
                }
                Some("text") => agent_models::redact(&mut value["text"], key),
                Some("done") => {
                    agent_models::redact(&mut value["answer"], key);
                    agent_models::redact_messages(&mut value["messages"], key);
                    false
                }
                Some("error") => agent_models::redact(&mut value["message"], key),
                _ => false,
            }
        } else {
            false
        };
        match value["type"].as_str().unwrap_or("") {
            "ready" => (),
            "tools" => update(
                run,
                json!({"tool_registration":{"school_capability":value["school_capability"],"capability_count":value["capability_count"],"tool_names":value["tool_names"]}}),
            ),
            "tool_dispatch" => update(
                run,
                json!({"tool_dispatch":{"school_capability":value["school_capability"],"tool_count":value["tool_count"]}}),
            ),
            "text" => {
                let text = value["text"].as_str().unwrap_or("");
                if text.len() > 120000 {
                    return Err("模型回答过长".into());
                }
                update(run, json!({"text":text}));
            }
            "rpc" => {
                if value["id"]
                    .as_str()
                    .is_none_or(|id| uuid::Uuid::parse_str(id).is_err())
                {
                    return Err("Agent 请求标识无效".into());
                }
                if protected && matches!(value["method"].as_str(), Some("tool" | "confirm_mcp")) {
                    return Err("工具参数包含受保护凭据，未执行，请移除凭据后重试".into());
                }
                rpc_count += 1;
                if rpc_count > 44 {
                    return Err("Agent 调用次数达到上限".into());
                }
                if value["method"] == "personal_model" && mode == "personal" {
                    update(run, json!({"tool_status":"本机模型推理中"}));
                    let mut reply =
                        match agent_models::complete(&model, &value["params"]["body"], &run.cancel)
                            .await
                        {
                            Ok(result) => json!({"type":"rpc_result","result":result}),
                            Err(failure) => failure.reply(),
                        };
                    reply["id"] = value["id"].clone();
                    send(&mut stdin, &reply).await?;
                    continue;
                }
                let result = match value["method"].as_str().unwrap_or("") {
                    "project_guide" if builtin_enabled => {
                        crate::project_guides::query(&value["params"])
                    }
                    "model" if mode == "school" => {
                        update(run, json!({"tool_status":"学校模型推理中"}));
                        agent_post(connection,run,"api/client-agent/model",&json!({"revision":config["school"]["revision"],"body":value["params"]["body"]})).await
                    }
                    "confirm_mcp" => approve_mcp_call(app, run, &plugins, &value["params"]).await,
                    "tool" => {
                        let outcome = call_tool(
                            app,
                            connection,
                            run,
                            &client,
                            &mut session,
                            &catalog,
                            &value["params"],
                        )
                        .await;
                        update(run, json!({"tool_connection":session.status()}));
                        outcome
                    }
                    _ => Err("Agent 请求了未开放的本机功能".into()),
                };
                let reply = match result {
                    Ok(value) => json!({"type":"rpc_result","result":value}),
                    Err(message) => json!({"type":"rpc_result","error":message}),
                };
                let mut reply = reply;
                reply["id"] = value["id"].clone();
                send(&mut stdin, &reply).await?;
            }
            "done" => {
                if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
                    return Err("Agent 已停止".into());
                }
                let answer = value["answer"].as_str().unwrap_or("");
                if answer.len() > 120000 || !value["messages"].is_array() {
                    return Err("Agent 会话响应无效".into());
                }
                let mut history = conversation["history"]
                    .as_array()
                    .cloned()
                    .unwrap_or_default();
                history.push(json!({"role":"user","text":prompt}));
                history.push(json!({"role":"assistant","text":answer}));
                if history.len() > 60 {
                    history.drain(..history.len() - 60);
                }
                if mode == "personal" {
                    for item in &mut history {
                        agent_models::redact(&mut item["text"], key);
                    }
                }
                stored = agent_workspace::normalize(
                    &run.scope,
                    agent_store::read_locked(app, &run.scope)?,
                );
                agent_workspace::complete(
                    &mut stored,
                    run.options["conversation_id"].as_str().unwrap_or(""),
                    mode,
                    &actual_profile,
                    &selected_model,
                    &revision,
                    value["messages"].clone(),
                    json!(history),
                )?;
                agent_store::write(app, &run.scope, &stored)?;
                let _ = tokio::time::timeout(Duration::from_secs(3), child.wait()).await;
                return Ok(json!({"answer":answer,"history":history}));
            }
            "error" => {
                return Err(value["message"]
                    .as_str()
                    .unwrap_or("Agent 运行失败")
                    .chars()
                    .take(400)
                    .collect())
            }
            _ => return Err("Agent 通道事件未支持".into()),
        }
    }
}

async fn call_tool(
    app: &AppHandle,
    connection: &Connection,
    run: &Run,
    client: &reqwest::Client,
    session: &mut Tools,
    catalog: &Value,
    params: &Value,
) -> Result<Value, String> {
    let id = params["capability_id"].as_str().ok_or("工具标识无效")?;
    let cap = catalog
        .as_array()
        .and_then(|items| items.iter().find(|c| c["id"] == id))
        .ok_or("当前账号未开放该工具")?;
    if !matches!(cap["phase"].as_str(), Some("B" | "C"))
        || cap["available"] != true
        || (cap["phase"] == "C" && cap["confirmation_required"] != true)
    {
        return Err("该工具尚未开放".into());
    }
    let args = params["arguments"].as_object().ok_or("工具参数无效")?;
    let allowed: Vec<&str> = cap["parameters"]
        .as_array()
        .map(|p| p.iter().filter_map(|v| v["name"].as_str()).collect())
        .unwrap_or_default();
    if args.keys().any(|key| !allowed.contains(&key.as_str())) {
        return Err("工具包含未开放的参数".into());
    }
    if session.blocked {
        return Err("业务工具连接已失效，请重新连接".into());
    }
    let write = cap["kind"] == "write";
    if write && run.options["scheduled"] == true {
        return Err("计划任务只允许读取学校业务数据".into());
    }
    if cap["phase"] == "C" {
        return call_confirmed_tool(app, connection, run, client, session, cap, params).await;
    }
    let label = cap["label"].as_str().unwrap_or(id);
    update(
        run,
        json!({"tool_status":format!("{}：{label}",if write {"等待确认"} else {"正在查询"})}),
    );
    if write {
        let preview =
            serde_json::to_string_pretty(&params["arguments"]).map_err(|_| "工具参数无效")?;
        if preview.len() > 16000 {
            return Err("写操作参数过长，无法完整确认，请拆分后处理".into());
        }
        let description=format!("操作：{label}\n学校：{}\n账号 ID：{}\n\n参数：\n{preview}\n\n确认后会立即提交。网络中断时请先查询核对结果。",run.scope["origin"].as_str().unwrap_or(""),run.scope["account_id"]);
        let dialog = app
            .dialog()
            .message(description)
            .title("确认 Agent 写操作")
            .buttons(MessageDialogButtons::OkCancelCustom(
                "确认执行".into(),
                "拒绝".into(),
            ));
        let accepted = tokio::task::spawn_blocking(move || dialog.blocking_show())
            .await
            .map_err(|_| "无法显示确认窗口")?;
        if !accepted {
            run.cancel.cancel();
            return Err("用户拒绝本次写操作，请停止该操作".into());
        }
    }
    if run.cancel.is_cancelled() || connection.cancel.is_cancelled() {
        return Err("运行已停止，未提交本次请求".into());
    }
    update(run, json!({"tool_status":format!("正在执行：{label}")}));
    if write {
        update(run, json!({"business_write_pending":true}));
    }
    let result = cli(
        client,
        connection,
        &session.access,
        cap["method"].as_str().ok_or("工具方法无效")?,
        cap["path"].as_str().ok_or("工具路径无效")?,
        &params["arguments"],
        &session.task,
        write,
    )
    .await;
    update(run, json!({"business_write_pending":false}));
    if result.is_err() {
        if write {
            session.paused();
            run.cancel.cancel();
        } else {
            session.connection = json!({"state":"error","stage":"call","error":"business_read_failed","message":result.as_ref().err(),"action":"retry","can_manage_cli":session.connection["can_manage_cli"]});
        }
        update(run, json!({"tool_connection":session.status()}));
    }
    result
}
