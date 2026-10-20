use crate::{agent_store, connection::Connection, school_api};
use aes_gcm::aead::rand_core::RngCore;
use aes_gcm::{
    aead::{Aead, KeyInit, OsRng, Payload},
    Aes256Gcm, Nonce,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use serde_json::{json, Value};
use std::{
    collections::HashMap,
    process::Stdio,
    sync::{Arc, Mutex, OnceLock},
    time::{Duration, Instant},
};
use tauri::{AppHandle, Manager};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio_util::sync::CancellationToken;
use zeroize::{Zeroize, Zeroizing};

const LINE_LIMIT: usize = 4 * 1024 * 1024;
const RESULT_LIMIT: usize = 256 * 1024;

#[derive(Default)]
struct OwnerState {
    run: Option<Arc<Run>>,
    tools: Arc<tokio::sync::Mutex<Tools>>,
}

struct Tools {
    access: Zeroizing<String>,
    refresh: Zeroizing<String>,
    expires: Option<Instant>,
    task: String,
    task_expires: Option<Instant>,
    blocked: bool,
}

impl Default for Tools {
    fn default() -> Self {
        Self {
            access: Zeroizing::new(String::new()),
            refresh: Zeroizing::new(String::new()),
            expires: None,
            task: String::new(),
            task_expires: None,
            blocked: true,
        }
    }
}

struct Run {
    id: String,
    scope: Value,
    cancel: CancellationToken,
    state: Mutex<Value>,
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

async fn bootstrap(app: &AppHandle, connection: &Connection, owner: &str) -> Result<Value, String> {
    let mut value = school_api::read(connection, "api/client-agent/bootstrap").await?;
    check_scope(connection, &value["scope"])?;
    value["busy"] = json!(false);
    let mut running_history = None;
    if let Ok(owners) = owners().lock() {
        if let Some(state) = owners.get(owner) {
            if let Some(run) = state.run.as_ref().filter(|run| run.scope == value["scope"]) {
                if let Ok(snapshot) = run.state.lock() {
                    value["run_id"] = json!(run.id);
                    value["busy"] = json!(snapshot["status"] == "running");
                    value["run"] = snapshot.clone();
                    if snapshot["status"] == "running" {
                        running_history = Some(snapshot["history"].clone());
                    }
                }
            }
        }
    }
    if let Some(history) = running_history {
        value["history"] = history;
    } else {
        match agent_store::read(app, &value["scope"]) {
            Ok(stored) => value["history"] = stored["history"].clone(),
            Err(message) => {
                value["history"] = json!([]);
                value["memory_error"] = json!(message);
            }
        }
    }
    Ok(value)
}

fn check_scope(connection: &Connection, scope: &Value) -> Result<(), String> {
    agent_store::scope_key(scope)?;
    let account = connection
        .operation_context
        .as_ref()
        .and_then(|v| v["account_id"].as_u64())
        .unwrap_or(0);
    if scope["origin"].as_str() != Some(connection.origin.origin().ascii_serialization().as_str())
        || scope["account_id"].as_u64() != Some(account)
        || account == 0
    {
        return Err("Agent 配置与当前学校账号不匹配".into());
    }
    Ok(())
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
    let action = input["action"].as_str().unwrap_or("");
    if action == "save" {
        school_api::sso_post(
            connection,
            "api/client-agent/personal-save",
            &json!({
                "enabled":input["enabled"], "endpoint":input["endpoint"],"model":input["model"],
                "api_key":input["api_key"],"clear_key":input["clear_key"]
            }),
        )
        .await?;
        return bootstrap(app, connection, owner).await;
    }
    if action == "bootstrap" {
        return bootstrap(app, connection, owner).await;
    }
    if action == "reconnect" {
        let mut owners = owners().lock().map_err(|_| "Agent 状态不可用")?;
        let state = owners.entry(owner.to_owned()).or_default();
        if state
            .run
            .as_ref()
            .is_some_and(|run| run.state.lock().is_ok_and(|s| s["status"] == "running"))
        {
            return Err("请先停止当前运行".into());
        }
        state.tools = Arc::new(tokio::sync::Mutex::new(Tools {
            blocked: false,
            ..Tools::default()
        }));
        drop(owners);
        return Ok(json!({"ok":true}));
    }
    if matches!(action, "poll" | "cancel") {
        let owners = owners().lock().map_err(|_| "Agent 状态不可用")?;
        let run = owners
            .get(owner)
            .and_then(|s| s.run.clone())
            .ok_or("Agent 运行已结束，请重新读取")?;
        let account = connection
            .operation_context
            .as_ref()
            .and_then(|v| v["account_id"].as_u64())
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
    let config = bootstrap(app, connection, owner).await?;
    let scope = config["scope"].clone();
    if action == "clear" {
        let mut owners = owners().lock().map_err(|_| "Agent 状态不可用")?;
        if let Some(state) = owners.get_mut(owner) {
            if state
                .run
                .as_ref()
                .is_some_and(|run| run.state.lock().is_ok_and(|s| s["status"] == "running"))
            {
                return Err("请先停止当前运行，再清除本机记忆".into());
            }
            state.run = None;
        }
        agent_store::clear(app, &scope)?;
        return Ok(json!({"history":[]}));
    }
    if action != "start" {
        return Err("Agent 操作无效".into());
    }
    let mode = input["mode"].as_str().unwrap_or("");
    if !matches!(mode, "personal" | "school") {
        return Err("请选择模型来源".into());
    }
    if config[mode]["enabled"].as_bool() != Some(true) {
        return Err("所选模型尚未启用，请检查配置".into());
    }
    let prompt = input["prompt"].as_str().unwrap_or("").trim();
    if prompt.is_empty() || prompt.chars().count() > 12000 {
        return Err("请输入不超过 12000 字的问题".into());
    }
    let memory_guard = agent_store::acquire(app, &scope)?;
    let stored = agent_store::read_locked(app, &scope)?;
    agent_store::write(app, &scope, &stored)?;
    let run = Arc::new(Run {
        id: uuid::Uuid::new_v4().to_string(),
        scope: scope.clone(),
        cancel: cancel.child_token(),
        state: Mutex::new(
            json!({"run_id":"","status":"running","text":"","history":stored["history"],"tool_status":"正在准备模型与业务权限"}),
        ),
    });
    update(&run, json!({"run_id":run.id}));
    let tools = {
        let mut owners = owners().lock().map_err(|_| "Agent 状态不可用")?;
        let owner = owners.entry(owner.to_owned()).or_default();
        if owner
            .run
            .as_ref()
            .is_some_and(|run| run.state.lock().is_ok_and(|s| s["status"] == "running"))
        {
            return Err("当前账号已有 Agent 正在运行".into());
        }
        owner.run = Some(run.clone());
        owner.tools.clone()
    };
    let app = app.clone();
    let connection = connection.clone();
    let prompt = prompt.to_owned();
    let mode = mode.to_owned();
    let running = run.clone();
    tauri::async_runtime::spawn(async move {
        let _memory_guard = memory_guard;
        let result = tokio::select! {
            biased;
            _ = running.cancel.cancelled() => Err("运行已停止；已发送的写请求请先核对结果".to_owned()),
            _ = connection.cancel.cancelled() => Err("学校连接已关闭".to_owned()),
            _ = tokio::time::sleep(Duration::from_secs(180)) => Err("运行达到 180 秒上限，已停止；写请求请先核对结果".to_owned()),
            result = execute(&app,&connection,&running,&mode,&prompt,&config,stored,tools) => result,
        };
        match result {
            Ok(value) => update(
                &running,
                json!({"status":"done","text":value["answer"],"history":value["history"],"tool_status":"运行结束"}),
            ),
            Err(message) => {
                let status = if running.cancel.is_cancelled() || connection.cancel.is_cancelled() {
                    "cancelled"
                } else {
                    "error"
                };
                update(
                    &running,
                    json!({"status":status,"error":message,"tool_status":"已停止"}),
                );
            }
        }
    });
    Ok(json!({"run_id":run.id}))
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
    school_api::sso_post(connection, path, body).await
}

async fn personal(connection: &Connection, run: &Run, device_id: &str) -> Result<Value, String> {
    let mut key = Zeroizing::new([0u8; 32]);
    OsRng.fill_bytes(key.as_mut());
    let mut body = json!({"transport_key":STANDARD.encode(key.as_ref()),"device_id":device_id});
    let envelope = agent_post(connection, run, "api/client-agent/personal-download", &body).await;
    if let Some(Value::String(s)) = body.get_mut("transport_key") {
        s.zeroize();
    }
    body = Value::Null;
    drop(body);
    let envelope = envelope?;
    if envelope["algorithm"] != "AES-256-GCM" {
        return Err("个人模型加密信封格式无效".into());
    }
    let nonce = STANDARD
        .decode(envelope["nonce"].as_str().unwrap_or(""))
        .map_err(|_| "个人配置加密信封无效")?;
    let mut encrypted = STANDARD
        .decode(envelope["ciphertext"].as_str().unwrap_or(""))
        .map_err(|_| "个人配置加密信封无效")?;
    let tag = STANDARD
        .decode(envelope["tag"].as_str().unwrap_or(""))
        .map_err(|_| "个人配置加密信封无效")?;
    if nonce.len() != 12 || tag.len() != 16 || encrypted.len() > 32768 {
        return Err("个人配置加密信封无效".into());
    }
    encrypted.extend_from_slice(&tag);
    let cipher = Aes256Gcm::new_from_slice(key.as_ref()).map_err(|_| "个人配置解密失败")?;
    let clear = Zeroizing::new(
        cipher
            .decrypt(
                Nonce::from_slice(&nonce),
                Payload {
                    msg: &encrypted,
                    aad: b"practical-client-agent:v1",
                },
            )
            .map_err(|_| "个人配置解密失败")?,
    );
    let mut value: Value = serde_json::from_slice(&clear).map_err(|_| "个人配置格式无效")?;
    if value["scope"] != run.scope || value["device_id"] != device_id || value["mode"] != "personal"
    {
        wipe_key(&mut value);
        return Err("个人配置不属于当前学校账号或设备".into());
    }
    Ok(value)
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
    let mut request = client.request(method.clone(), url).bearer_auth(token);
    if !task.is_empty() {
        request = request.header("x-cli-task", task);
    }
    if write {
        request = request.header("x-idempotency-key", uuid::Uuid::new_v4().to_string());
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
    decode(response, RESULT_LIMIT).await
}

async fn tools(
    connection: &Connection,
    run: &Run,
    state: &mut Tools,
    device_id: &str,
    client: &reqwest::Client,
) -> Result<Value, String> {
    if state.blocked {
        return Err("业务工具连接已失效，请点击重新连接业务工具".into());
    }
    let result = async {
        if state.access.is_empty() {
            let value = agent_post(
                connection,
                run,
                "api/client-agent/session",
                &json!({"device_id":device_id}),
            )
            .await?;
            state.access = Zeroizing::new(
                value["access_token"]
                    .as_str()
                    .ok_or("CLI 会话格式无效")?
                    .to_owned(),
            );
            state.refresh = Zeroizing::new(
                value["refresh_token"]
                    .as_str()
                    .ok_or("CLI 续期格式无效")?
                    .to_owned(),
            );
            state.expires = Some(
                Instant::now() + Duration::from_secs(value["expires_in"].as_u64().unwrap_or(0)),
            );
        } else if state
            .expires
            .is_none_or(|t| t <= Instant::now() + Duration::from_secs(30))
        {
            let value = cli(
                client,
                connection,
                "",
                "POST",
                "api/cli/token/refresh",
                &json!({"refresh_token":state.refresh.as_str()}),
                "",
                false,
            )
            .await?;
            state.access = Zeroizing::new(
                value["access_token"]
                    .as_str()
                    .ok_or("CLI 续期格式无效")?
                    .to_owned(),
            );
            state.refresh = Zeroizing::new(
                value["refresh_token"]
                    .as_str()
                    .ok_or("CLI 续期格式无效")?
                    .to_owned(),
            );
            state.expires = Some(
                Instant::now() + Duration::from_secs(value["expires_in"].as_u64().unwrap_or(0)),
            );
        }
        let catalog = cli(
            client,
            connection,
            &state.access,
            "GET",
            "api/cli/capabilities",
            &json!({}),
            "",
            false,
        )
        .await?;
        if catalog["items"].as_array().is_some_and(|v| !v.is_empty())
            && state
                .task_expires
                .is_none_or(|t| t <= Instant::now() + Duration::from_secs(180))
        {
            let task = cli(
                client,
                connection,
                &state.access,
                "POST",
                "api/cli/tasks/create",
                &json!({"label":"客户端 Agent"}),
                "",
                false,
            )
            .await?;
            state.task = task["task_id"]
                .as_str()
                .ok_or("CLI 任务格式无效")?
                .to_owned();
            state.task_expires = Some(
                Instant::now() + Duration::from_secs(task["expires_in"].as_u64().unwrap_or(0)),
            );
        }
        Ok(catalog["items"].clone())
    }
    .await;
    if result.is_err() {
        state.blocked = true;
    }
    result
}

fn runtime(app: &AppHandle) -> Result<(std::path::PathBuf, std::path::PathBuf), String> {
    let executable = std::env::current_exe().map_err(|_| "无法定位 Agent 运行环境")?;
    let parent = executable.parent().ok_or("无法定位 Agent 运行环境")?;
    let binary = if cfg!(target_os = "windows") {
        "agent-node.exe"
    } else {
        "agent-node"
    };
    let node = parent.join(binary);
    let script = app
        .path()
        .resource_dir()
        .map_err(|_| "无法定位 Agent 资源")?
        .join("agent-runtime/runtime.mjs");
    if node.is_file() && script.is_file() {
        return Ok((node, script));
    }
    #[cfg(debug_assertions)]
    {
        let target = if cfg!(all(target_os = "macos", target_arch = "aarch64")) {
            "aarch64-apple-darwin"
        } else if cfg!(target_os = "macos") {
            "x86_64-apple-darwin"
        } else if cfg!(target_os = "windows") {
            "x86_64-pc-windows-msvc"
        } else {
            "x86_64-unknown-linux-gnu"
        };
        let suffix = if cfg!(target_os = "windows") {
            ".exe"
        } else {
            ""
        };
        let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"));
        let node = root.join(format!("binaries/agent-node-{target}{suffix}"));
        let script = root.join("agent-runtime/runtime.mjs");
        if node.is_file() && script.is_file() {
            return Ok((node, script));
        }
    }
    Err("安装包缺少 Agent 运行环境，请重新安装完整客户端".into())
}

async fn bounded_line(
    reader: &mut BufReader<tokio::process::ChildStdout>,
) -> Result<Option<Vec<u8>>, String> {
    let mut output = Vec::new();
    loop {
        let bytes = reader.fill_buf().await.map_err(|_| "Agent 通道读取失败")?;
        if bytes.is_empty() {
            return if output.is_empty() {
                Ok(None)
            } else {
                Err("Agent 通道提前结束".into())
            };
        }
        let length = bytes
            .iter()
            .position(|b| *b == b'\n')
            .map(|n| n + 1)
            .unwrap_or(bytes.len());
        if output.len() + length > LINE_LIMIT {
            return Err("Agent 通道内容过大，已停止".into());
        }
        let done = bytes[length - 1] == b'\n';
        output.extend_from_slice(&bytes[..length]);
        reader.consume(length);
        if done {
            return Ok(Some(output));
        }
    }
}

async fn send(stdin: &mut tokio::process::ChildStdin, value: &Value) -> Result<(), String> {
    let mut bytes = Zeroizing::new(serde_json::to_vec(value).map_err(|_| "Agent 通道参数无效")?);
    if bytes.len() > LINE_LIMIT - 1 {
        return Err("会话上下文过多，请清除本机记忆后继续".into());
    }
    bytes.push(b'\n');
    stdin
        .write_all(&bytes)
        .await
        .map_err(|_| "Agent 通道已关闭")?;
    stdin
        .flush()
        .await
        .map_err(|_| "Agent 通道已关闭".to_owned())
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
    let mut model = ModelConfig(if mode == "personal" {
        personal(connection, run, &device_id).await?
    } else {
        config["school"].clone()
    });
    let revision = model["revision"].clone();
    if stored["mode"] != mode || stored["revision"] != model["revision"] {
        stored["messages"] = json!([]);
    }
    let client = reqwest::Client::builder()
        .redirect(reqwest::redirect::Policy::none())
        .no_proxy()
        .timeout(Duration::from_secs(45))
        .build()
        .map_err(|_| "Agent 工具连接无法初始化")?;
    let mut session = tools_state.lock().await;
    let catalog = match tools(connection, run, &mut session, &device_id, &client).await {
        Ok(value) => value,
        Err(message) => {
            update(run, json!({"tool_status":format!("仅模型对话：{message}")}));
            json!([])
        }
    };
    let (node, script) = runtime(app)?;
    let mut command = tokio::process::Command::new(node);
    command
        .arg("--disable-proto=throw")
        .arg(script)
        .env_clear()
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .kill_on_drop(true);
    #[cfg(target_os = "windows")]
    {
        if let Some(value) = std::env::var_os("SystemRoot") {
            command.env("SystemRoot", value);
        }
        command.creation_flags(0x08000000);
    }
    let mut child = command.spawn().map_err(|_| "无法启动随包 Agent 运行环境")?;
    let mut stdin = child.stdin.take().ok_or("Agent 输入通道不可用")?;
    let stdout = child.stdout.take().ok_or("Agent 输出通道不可用")?;
    let mut packet = json!({"type":"start","run_id":run.id,"mode":mode,"config":model.0,"prompt":prompt,"messages":stored["messages"],"capabilities":catalog});
    let sent = send(&mut stdin, &packet).await;
    if let Some(config) = packet.get_mut("config") {
        wipe_key(config);
    }
    wipe_key(&mut model);
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
        let value: Value = serde_json::from_slice(&line).map_err(|_| "Agent 通道格式无效")?;
        match value["type"].as_str().unwrap_or("") {
            "ready" => (),
            "text" => {
                let text = value["text"].as_str().unwrap_or("");
                if text.len() > 120000 {
                    return Err("模型回答过长".into());
                }
                update(run, json!({"text":text}));
            }
            "rpc" => {
                rpc_count += 1;
                if rpc_count > 44 {
                    return Err("Agent 调用次数达到上限".into());
                }
                let result = match value["method"].as_str().unwrap_or("") {
                    "model" if mode == "school" => {
                        update(run, json!({"tool_status":"学校模型推理中"}));
                        agent_post(connection,run,"api/client-agent/model",&json!({"revision":config["school"]["revision"],"body":value["params"]["body"]})).await
                    }
                    "tool" => {
                        call_tool(
                            app,
                            connection,
                            run,
                            &client,
                            &mut session,
                            &catalog,
                            &value["params"],
                        )
                        .await
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
                let mut history = stored["history"].as_array().cloned().unwrap_or_default();
                history.push(json!({"role":"user","text":prompt}));
                history.push(json!({"role":"assistant","text":answer}));
                if history.len() > 60 {
                    history.drain(..history.len() - 60);
                }
                let memory = json!({"scope":run.scope,"mode":mode,"revision":revision,"messages":value["messages"],"history":history});
                agent_store::write(app, &run.scope, &memory)?;
                child.kill().await.ok();
                child.wait().await.ok();
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
    if cap["phase"] != "B" || cap["available"] != true {
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
    if result.is_err() {
        session.blocked = true;
        if write {
            run.cancel.cancel();
        }
    }
    result
}
