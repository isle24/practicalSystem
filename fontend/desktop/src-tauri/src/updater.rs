use std::{collections::BTreeMap, sync::Mutex};
use tauri::{AppHandle, Manager, WebviewWindow};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use tauri_plugin_updater::UpdaterExt;
use tokio_util::sync::CancellationToken;
use url::Url;

static OFFERED: tokio::sync::Mutex<BTreeMap<String, String>> =
    tokio::sync::Mutex::const_new(BTreeMap::new());
static CONNECTION_SCHOOL: Mutex<Option<(Url, CancellationToken)>> = Mutex::new(None);

#[tauri::command]
pub fn select_connection_update_school(
    window: WebviewWindow,
    domain: Option<String>,
) -> Result<Option<String>, String> {
    crate::require_connection(&window)?;
    let origin = domain
        .as_deref()
        .map(crate::connection::school_origin)
        .transpose()?;
    let mut selected = CONNECTION_SCHOOL.lock().map_err(|_| "更新状态不可用")?;
    if selected.as_ref().map(|(school, _)| school) != origin.as_ref() {
        if let Some((_, cancel)) = selected.take() {
            cancel.cancel();
        }
        *selected = origin.map(|school| (school, CancellationToken::new()));
    }
    Ok(selected.as_ref().map(|(school, _)| school.to_string()))
}

pub fn cancel_connection(origin: Option<&Url>) {
    if let Ok(mut selected) = CONNECTION_SCHOOL.lock() {
        if let Some((_, cancel)) =
            selected.take_if(|(school, _)| origin.is_none_or(|origin| school == origin))
        {
            cancel.cancel();
        }
    }
}

#[tauri::command]
pub async fn check_connection_update(
    app: AppHandle,
    window: WebviewWindow,
    origin: String,
    interactive: bool,
) -> Result<bool, String> {
    crate::require_connection(&window)?;
    if crate::current_school_locked(&app) {
        return Err("客户端已锁定，请解锁后检查更新".into());
    }
    let selected = CONNECTION_SCHOOL
        .lock()
        .map_err(|_| "更新状态不可用")?
        .clone();
    if let Some((school, cancel)) = selected.filter(|(school, _)| school.as_str() == origin) {
        return Ok(check(
            &app,
            crate::connection::school_client()?,
            school,
            cancel,
            interactive,
        )
        .await);
    }
    Ok(false)
}

/// 学校窗口发起检查后可继续使用。
pub fn start(
    app: AppHandle,
    client: reqwest::Client,
    origin: Url,
    cancel: CancellationToken,
    interactive: bool,
) {
    tauri::async_runtime::spawn(async move {
        check(&app, client, origin, cancel, interactive).await;
    });
}

async fn check(
    app: &AppHandle,
    client: reqwest::Client,
    origin: Url,
    cancel: CancellationToken,
    interactive: bool,
) -> bool {
    let mut offered = if interactive {
        let Ok(guard) = OFFERED.try_lock() else {
            return false;
        };
        guard
    } else {
        tokio::select! { _ = cancel.cancelled() => return false, guard = OFFERED.lock() => guard }
    };
    if cancel.is_cancelled() {
        return false;
    }
    let mut report_errors = interactive;
    if let Err(message) = run(
        app,
        client,
        &origin,
        &cancel,
        &mut offered,
        &mut report_errors,
    )
    .await
    {
        if report_errors && !cancel.is_cancelled() {
            if let Some(window) = update_window(app, &origin) {
                progress(app, &origin, "error", 0, None);
                app.dialog()
                    .message(message)
                    .title("客户端更新")
                    .parent(&window)
                    .show(|_| {});
            }
        }
        return false;
    }
    !cancel.is_cancelled()
}

/// 签名验证、再次确认发布状态，然后覆盖安装。
async fn run(
    app: &AppHandle,
    client: reqwest::Client,
    origin: &Url,
    cancel: &CancellationToken,
    offered: &mut BTreeMap<String, String>,
    report_errors: &mut bool,
) -> Result<(), String> {
    let interactive = *report_errors;
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
    let fetch = || async {
        client
            .get(endpoint.clone())
            .timeout(std::time::Duration::from_secs(20))
            .send()
            .await
            .map_err(|_| "无法连接学校更新服务")?
            .error_for_status()
            .map_err(|_| "无法检查学校版本，请联系管理员确认客户端更新服务")?
            .json::<serde_json::Value>()
            .await
            .map_err(|_| "更新服务响应无效")
    };
    let data =
        tokio::select! { _ = cancel.cancelled() => return Ok(()), result = fetch() => result? };
    if cancel.is_cancelled() {
        return Ok(());
    }
    if data["code"] != 0 {
        return Err("学校更新服务暂不可用".into());
    }
    if data["data"]["update"].is_null() {
        if interactive {
            if let Some(window) = update_window(app, origin) {
                app.dialog()
                    .message("当前已是学校发布的最新版本")
                    .title("检查更新")
                    .parent(&window)
                    .show(|_| {});
            }
        }
        return Ok(());
    }
    let version = data["data"]["update"]["version"]
        .as_str()
        .ok_or("版本信息不完整")?;
    let Some(window) = update_window(app, origin) else {
        return Ok(());
    };
    if !interactive
        && offered
            .get(origin.as_str())
            .is_some_and(|last| last == version)
    {
        return Ok(());
    }
    offered.insert(origin.to_string(), version.to_owned());
    let notes = data["data"]["update"]["notes"].as_str().unwrap_or("");
    let mb = data["data"]["update"]["size"].as_u64().unwrap_or(0) as f64 / 1048576.0;
    if !ask(
        window,
        format!(
            "{} 已发布版本 {version}（{mb:.1} MB）\n\n{}\n\n下载更新并在校验后确认安装？",
            origin.origin().ascii_serialization(),
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
    *report_errors = true;
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
        origin,
        "downloading",
        0,
        Some(data["data"]["update"]["size"].as_u64().unwrap_or(0)),
    );
    let bytes = tokio::select! {
        _ = cancel.cancelled() => return Ok(()),
        result = update.download(|chunk, total| {
            downloaded += chunk as u64;
            if last_progress.elapsed().as_millis() > 200 { progress(app, origin, "downloading", downloaded, total); last_progress = std::time::Instant::now(); }
        }, || {}) => result.map_err(|_| "下载或签名校验失败。请重新检查更新。")?
    };
    if cancel.is_cancelled() {
        return Ok(());
    }
    let Some(window) = update_window(app, origin) else {
        return Ok(());
    };
    progress(
        app,
        origin,
        "ready",
        bytes.len() as u64,
        Some(bytes.len() as u64),
    );
    if !ask(
        window,
        "升级包校验完成。请保存所有笔记和表单。继续将退出客户端并覆盖安装，学校连接设置会保留。"
            .into(),
        "保存完毕，安装",
        "稍后安装",
    )
    .await
        || cancel.is_cancelled()
    {
        if !cancel.is_cancelled() {
            progress(app, origin, "cancelled", 0, None);
        }
        return Ok(());
    }
    let final_data = tokio::select! { _ = cancel.cancelled() => return Ok(()), result = fetch() => result.map_err(|_| "无法复核学校发布状态")? };
    if cancel.is_cancelled() {
        return Ok(());
    }
    if final_data["code"] != 0 || final_data["data"]["update"]["version"].as_str() != Some(version)
    {
        return Err("学校版本已变化或连接已切换，请重新检查更新".into());
    }
    progress(app, origin, "installing", 0, None);
    update
        .install(&bytes)
        .map_err(|_| "安装失败，请确认应用位于可写目录；macOS 请先移出 DMG 后再更新")?;
    app.restart();
}

/// 在独立阻塞任务中等待原生确认。
async fn ask(window: WebviewWindow, message: String, yes: &str, no: &str) -> bool {
    let buttons = MessageDialogButtons::OkCancelCustom(yes.into(), no.into());
    tauri::async_runtime::spawn_blocking(move || {
        window
            .dialog()
            .message(message)
            .title("客户端更新")
            .buttons(buttons)
            .parent(&window)
            .blocking_show()
    })
    .await
    .unwrap_or(false)
}

fn update_window(app: &AppHandle, origin: &Url) -> Option<WebviewWindow> {
    let connected = app
        .state::<crate::AppState>()
        .gateway
        .lock()
        .ok()
        .is_some_and(|gateway| {
            gateway
                .as_ref()
                .is_some_and(|gateway| &gateway.remote == origin)
        });
    if connected {
        app.get_webview_window("school")
    } else if CONNECTION_SCHOOL.lock().is_ok_and(|selected| {
        selected
            .as_ref()
            .is_some_and(|(school, _)| school == origin)
    }) {
        app.get_webview_window("connection")
    } else {
        None
    }
}

fn progress(app: &AppHandle, origin: &Url, phase: &str, bytes: u64, total: Option<u64>) {
    if let Some(window) = update_window(app, origin) {
        let detail = serde_json::json!({"phase":phase,"bytes":bytes,"total":total});
        let _ = window.eval(format!(
            "window.dispatchEvent(new CustomEvent('desktop-update-progress',{{detail:{detail}}}))"
        ));
    }
}
