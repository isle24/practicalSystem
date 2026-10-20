use serde_json::{json, Value};
use std::{process::Stdio, time::Duration};
use tauri::{AppHandle, Manager};
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio_util::sync::CancellationToken;
use zeroize::Zeroizing;

const LINE_LIMIT: usize = 4 * 1024 * 1024;

pub(crate) struct RuntimeChild(Option<tokio::process::Child>);
impl std::ops::Deref for RuntimeChild {
    type Target = tokio::process::Child;
    fn deref(&self) -> &Self::Target {
        self.0.as_ref().unwrap()
    }
}
impl std::ops::DerefMut for RuntimeChild {
    fn deref_mut(&mut self) -> &mut Self::Target {
        self.0.as_mut().unwrap()
    }
}
impl Drop for RuntimeChild {
    fn drop(&mut self) {
        if let Some(mut child) = self.0.take() {
            tauri::async_runtime::spawn(async move {
                if tokio::time::timeout(Duration::from_secs(3), child.wait())
                    .await
                    .is_err()
                {
                    let _ = child.kill().await;
                    let _ = child.wait().await;
                }
            });
        }
    }
}

pub(crate) fn spawn_runtime(app: &AppHandle) -> Result<RuntimeChild, String> {
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
    command
        .spawn()
        .map(|child| RuntimeChild(Some(child)))
        .map_err(|_| "无法启动随包 Agent 运行环境".into())
}

pub(crate) fn plugin_configs(data: &Value) -> Value {
    let plugins = data["plugins"]
        .as_array()
        .into_iter()
        .flatten()
        .filter(|item| item["type"] == "mcp" && item["enabled"] == true)
        .cloned()
        .map(|mut plugin| {
            if plugin["transport"] == "stdio" {
                if !plugin["env"].is_object() {
                    plugin["env"] = json!({});
                }
                #[cfg(target_os = "windows")]
                if let Some(value) = std::env::var_os("SystemRoot") {
                    plugin["env"]["SystemRoot"] = json!(value.to_string_lossy());
                }
                #[cfg(unix)]
                if plugin["env"]["PATH"].is_null() {
                    plugin["env"]["PATH"] = json!("/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin");
                }
            }
            plugin
        })
        .collect::<Vec<_>>();
    json!(plugins)
}

pub(crate) async fn probe_mcp(
    app: &AppHandle,
    plugin: &Value,
    cancel: &CancellationToken,
) -> Result<Value, String> {
    let mut child = spawn_runtime(app)?;
    let mut stdin = child.stdin.take().ok_or("Agent 输入通道不可用")?;
    let mut reader = BufReader::new(child.stdout.take().ok_or("Agent 输出通道不可用")?);
    let mut chosen = plugin.clone();
    chosen["enabled"] = json!(true);
    let configs = plugin_configs(&json!({"plugins":[chosen]}));
    let packet = json!({"type":"probe_mcp","plugin":configs[0]});
    send(&mut stdin, &packet).await?;
    let operation = async {
        loop {
            let line = bounded_line(&mut reader).await?.ok_or("MCP 进程已结束")?;
            let value: Value = serde_json::from_slice(&line).map_err(|_| "MCP 响应格式无效")?;
            match value["type"].as_str().unwrap_or("") {
                "ready" => (),
                "probe" => return Ok(value),
                "error" => return Err("MCP 连接失败，请检查服务地址、凭据和本机环境".to_owned()),
                _ => return Err("MCP 返回了未开放的操作".to_owned()),
            }
        }
    };
    tokio::select! {biased;_=cancel.cancelled()=>Err("MCP 连接已取消".into()),_=tokio::time::sleep(Duration::from_secs(28))=>Err("MCP 连接超时".into()),result=operation=>result}
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

pub(crate) async fn bounded_line(
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

pub(crate) async fn send(
    stdin: &mut tokio::process::ChildStdin,
    value: &Value,
) -> Result<(), String> {
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
