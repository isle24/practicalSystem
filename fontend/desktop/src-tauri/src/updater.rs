use std::sync::{
    atomic::{AtomicBool, Ordering},
    Mutex,
};
use tauri::{AppHandle, Manager};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use tauri_plugin_updater::UpdaterExt;
use tokio_util::sync::CancellationToken;
use url::Url;

static RUNNING: AtomicBool = AtomicBool::new(false);
static OFFERED: Mutex<Option<String>> = Mutex::new(None);
struct Guard;
impl Drop for Guard {
    fn drop(&mut self) {
        RUNNING.store(false, Ordering::Release);
    }
}

/// 学校会话内单次检查，禁止并行下载安装。
pub fn start(
    app: AppHandle,
    client: reqwest::Client,
    origin: Url,
    cancel: CancellationToken,
    interactive: bool,
) {
    if RUNNING.swap(true, Ordering::AcqRel) {
        return;
    }
    tauri::async_runtime::spawn(async move {
        let _guard = Guard;
        if let Err(message) = run(&app, client, origin, cancel.clone(), interactive).await {
            if !cancel.is_cancelled() {
                progress(&app, "error", 0, None);
                if interactive {
                    app.dialog()
                        .message(message)
                        .title("客户端更新")
                        .show(|_| {});
                }
            }
        }
    });
}

/// 签名验证、再次确认发布状态，然后覆盖安装。
async fn run(
    app: &AppHandle,
    client: reqwest::Client,
    origin: Url,
    cancel: CancellationToken,
    interactive: bool,
) -> Result<(), String> {
    let platform = if cfg!(target_os = "macos") {
        "darwin-universal"
    } else if cfg!(target_os = "windows") {
        "windows-x86_64"
    } else {
        "linux-x86_64"
    };
    let endpoint = origin
        .join(&format!(
            "api/release/check?platform={platform}&current={}",
            env!("CARGO_PKG_VERSION")
        ))
        .map_err(|_| "学校地址无效")?;
    let fetch = async {
        client
            .get(endpoint.clone())
            .timeout(std::time::Duration::from_secs(20))
            .send()
            .await
            .map_err(|_| "无法连接学校更新服务")?
            .error_for_status()
            .map_err(|_| "请先登录学校，或联系管理员启用版本服务")?
            .json::<serde_json::Value>()
            .await
            .map_err(|_| "更新服务响应无效")
    };
    let data =
        tokio::select! { _ = cancel.cancelled() => return Ok(()), result = fetch => result? };
    if data["code"] != 0 {
        return Err("学校更新服务暂不可用".into());
    }
    if data["data"]["update"].is_null() {
        if interactive {
            app.dialog()
                .message("当前已是学校发布的最新版本")
                .title("检查更新")
                .show(|_| {});
        }
        return Ok(());
    }
    let version = data["data"]["update"]["version"]
        .as_str()
        .ok_or("版本信息不完整")?;
    let offered = format!("{}:{version}", origin.origin().ascii_serialization());
    if !interactive
        && OFFERED.lock().map_err(|_| "更新状态不可用")?.as_deref() == Some(offered.as_str())
    {
        return Ok(());
    }
    *OFFERED.lock().map_err(|_| "更新状态不可用")? = Some(offered);
    let notes = data["data"]["update"]["notes"].as_str().unwrap_or("");
    let mb = data["data"]["update"]["size"].as_u64().unwrap_or(0) as f64 / 1048576.0;
    if !ask(
        app.clone(),
        format!(
            "发现版本 {version}（{mb:.1} MB）\n\n{}\n\n下载更新？",
            notes.chars().take(1000).collect::<String>()
        ),
        "下载更新",
        "稍后",
    )
    .await
        || cancel.is_cancelled()
    {
        return Ok(());
    }
    if origin.scheme() != "https" {
        return Err(
            "在线安装需要 HTTPS 学校地址。本机 HTTP 可检查版本，请使用安装包手动覆盖安装。".into(),
        );
    }
    #[cfg(target_os = "linux")]
    if std::env::var_os("APPIMAGE").is_none() {
        return Err(
            "当前为系统包安装，请从学校更新说明下载新版 deb，使用系统软件安装器覆盖安装。".into(),
        );
    }
    let manifest = origin
        .join(
            data["data"]["update"]["manifest_path"]
                .as_str()
                .ok_or("缺少升级清单")?,
        )
        .map_err(|_| "升级清单地址无效")?;
    if manifest.origin() != origin.origin() {
        return Err("升级清单必须来自当前学校".into());
    }
    let updater = app
        .updater_builder()
        .endpoints(vec![manifest])
        .map_err(|_| "升级地址无效")?
        .build()
        .map_err(|_| "更新组件初始化失败")?;
    let update = tokio::select! { _ = cancel.cancelled() => return Ok(()), result = updater.check() => result.map_err(|_| "学校升级包未就绪，请重新检查")? };
    let Some(update) = update else {
        return Ok(());
    };
    if update.version != version || update.download_url.origin() != origin.origin() {
        return Err("升级包与当前学校版本不一致".into());
    }
    let mut downloaded = 0u64;
    let mut last_progress = std::time::Instant::now();
    progress(
        app,
        "downloading",
        0,
        Some(data["data"]["update"]["size"].as_u64().unwrap_or(0)),
    );
    let bytes = tokio::select! {
        _ = cancel.cancelled() => { progress(app, "cancelled", 0, None); return Ok(()); },
        result = update.download(|chunk, total| {
            downloaded += chunk as u64;
            if last_progress.elapsed().as_millis() > 200 { progress(app, "downloading", downloaded, total); last_progress = std::time::Instant::now(); }
        }, || {}) => result.map_err(|_| { progress(app, "error", 0, None); "下载或签名校验失败，当前客户端未被修改。请重新检查更新。" })?
    };
    progress(app, "ready", bytes.len() as u64, Some(bytes.len() as u64));
    if !ask(
        app.clone(),
        "升级包校验完成。请保存所有笔记和表单。继续将退出客户端并覆盖安装，学校连接设置会保留。"
            .into(),
        "保存完毕，安装",
        "稍后安装",
    )
    .await
        || cancel.is_cancelled()
    {
        progress(app, "cancelled", 0, None);
        return Ok(());
    }
    let final_data = client
        .get(endpoint)
        .timeout(std::time::Duration::from_secs(20))
        .send()
        .await
        .map_err(|_| "无法复核学校发布状态")?
        .json::<serde_json::Value>()
        .await
        .map_err(|_| "无法复核学校版本")?;
    if cancel.is_cancelled() || final_data["data"]["update"]["version"].as_str() != Some(version) {
        progress(app, "cancelled", 0, None);
        return Err("学校版本已变化或连接已切换，请重新检查更新".into());
    }
    progress(app, "installing", 0, None);
    update
        .install(&bytes)
        .map_err(|_| "安装失败，请确认应用位于可写目录；macOS 请先移出 DMG 后再更新")?;
    app.restart();
}

/// 在独立阻塞任务中等待原生确认。
async fn ask(app: AppHandle, message: String, yes: &str, no: &str) -> bool {
    let buttons = MessageDialogButtons::OkCancelCustom(yes.into(), no.into());
    tauri::async_runtime::spawn_blocking(move || {
        app.dialog()
            .message(message)
            .title("客户端更新")
            .buttons(buttons)
            .blocking_show()
    })
    .await
    .unwrap_or(false)
}

/// 只向可信学校窗口发送无敏感信息的下载进度。
fn progress(app: &AppHandle, phase: &str, bytes: u64, total: Option<u64>) {
    if let Some(window) = app.get_webview_window("school") {
        let detail = serde_json::json!({"phase":phase,"bytes":bytes,"total":total});
        let _ = window.eval(format!(
            "window.dispatchEvent(new CustomEvent('desktop-update-progress',{{detail:{detail}}}))"
        ));
    }
}
