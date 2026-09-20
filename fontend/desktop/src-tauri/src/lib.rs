mod connection;
mod downloads;
mod external;
mod gateway;
mod updater;
mod windows;

use connection::Settings;
use std::sync::{
    atomic::{AtomicBool, Ordering},
    Mutex,
};
use tauri::{
    menu::{AboutMetadata, Menu, MenuItem, PredefinedMenuItem, Submenu},
    AppHandle, Manager, State, WebviewWindow,
};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use tauri_plugin_opener::OpenerExt;

#[derive(Default)]
struct AppState {
    connecting: AtomicBool,
    gateway: Mutex<Option<gateway::Gateway>>,
    zoom: Mutex<f64>,
}

struct ConnectingGuard<'a>(&'a AtomicBool);

impl Drop for ConnectingGuard<'_> {
    /// 释放重复连接保护。
    fn drop(&mut self) {
        self.0.store(false, Ordering::Release);
    }
}

/// 只允许安装包内的连接页调用原生命令。
fn require_connection(window: &WebviewWindow) -> Result<(), String> {
    if window.label() != "connection" {
        return Err("该窗口不能修改客户端连接".into());
    }
    let url = window.url().map_err(|_| "无法确认客户端页面来源")?;
    let local = url.scheme() == "tauri" && url.host_str() == Some("localhost")
        || matches!(url.scheme(), "http" | "https") && url.host_str() == Some("tauri.localhost");
    if !local {
        return Err("仅本地连接页可以使用该功能".into());
    }
    Ok(())
}

/// 读取用户保存的学校连接列表。
#[tauri::command]
fn read_connection_settings(app: AppHandle, window: WebviewWindow) -> Result<Settings, String> {
    require_connection(&window)?;
    connection::load(&app)
}

/// 读取当前学校账号对应的系统密码。
#[tauri::command]
fn read_saved_connection_password(
    app: AppHandle,
    window: WebviewWindow,
    origin: String,
    username: String,
) -> Result<Option<String>, String> {
    require_connection(&window)?;
    let _ = connection::load(&app)?;
    connection::load_password(&origin, &username)
}

/// 建立所选学校的原生会话，并打开安装包内的 PC 界面。
#[tauri::command]
async fn connect_school(
    app: AppHandle,
    window: WebviewWindow,
    state: State<'_, AppState>,
    domain: String,
    username: String,
    password: String,
    remember_password: bool,
    auto_login: bool,
) -> Result<Settings, String> {
    require_connection(&window)?;
    if state.connecting.swap(true, Ordering::AcqRel) {
        return Err("正在连接，请稍候".into());
    }
    let _guard = ConnectingGuard(&state.connecting);
    let origin = connection::school_origin(&domain)?;
    let mut settings = connection::load(&app)?;
    if let Some(school) = app.get_webview_window("school") {
        let same_school = state
            .gateway
            .lock()
            .map_err(|_| "连接状态不可用")?
            .as_ref()
            .is_some_and(|gateway| gateway.remote.origin() == origin.origin());
        if same_school {
            let _ = school.unminimize();
            let _ = school.set_focus();
            let _ = window.hide();
            return Ok(settings);
        }
        let proceed = app
            .dialog()
            .message("切换学校将关闭当前学校的所有窗口，并结束本次登录。请先保存正在填写的内容。")
            .title("切换学校")
            .buttons(MessageDialogButtons::OkCancelCustom(
                "切换学校".into(),
                "取消".into(),
            ))
            .blocking_show();
        if !proceed {
            return Err("已取消切换，当前学校保持打开".into());
        }
    }
    let preferred_port = settings
        .schools
        .iter()
        .find(|school| school.origin == origin.origin().ascii_serialization())
        .map_or(0, |school| school.port);
    let connection = connection::connect(origin, preferred_port).await?;
    let mut profile = connection.school.clone();
    profile.username = username.trim().to_owned();
    profile.auto_login = auto_login;
    let reserved_ports: Vec<u16> = settings
        .schools
        .iter()
        .filter(|school| school.origin != profile.origin)
        .map(|school| school.port)
        .collect();
    let gateway = gateway::start(
        app.clone(),
        connection,
        &reserved_ports,
        if auto_login && !username.trim().is_empty() && !password.is_empty() {
            Some((username.trim().to_owned(), password.clone()))
        } else {
            None
        },
    )
    .await?;
    profile.port = gateway.port;
    settings.last_origin = profile.origin.clone();
    settings
        .schools
        .retain(|school| school.origin != profile.origin);
    settings.schools.insert(0, profile.clone());
    connection::save(&app, &settings)?;
    if remember_password {
        connection::save_password(&profile.origin, &profile.username, &password)?;
    } else {
        connection::delete_password(&profile.origin, &profile.username)?;
    }
    windows::close_school(&app);
    let school = windows::build(
        &app,
        "school",
        gateway.entry.clone(),
        gateway.origin.clone(),
        gateway.remote.clone(),
        None,
    )
    .map_err(|_| "无法打开学校窗口，请重新连接")?;
    let _ = school.set_title(&format!("{} - 实践管理系统", profile.name));
    *state.gateway.lock().map_err(|_| "连接状态不可用")? = Some(gateway);
    *state.zoom.lock().map_err(|_| "窗口状态不可用")? = 1.0;
    window.hide().map_err(|_| "无法隐藏连接设置")?;
    Ok(settings)
}

/// 创建系统菜单，保留原生编辑快捷键和窗口操作。
fn application_menu(app: &AppHandle) -> tauri::Result<Menu<tauri::Wry>> {
    let settings = MenuItem::with_id(app, "connection", "连接设置…", true, Some("CmdOrCtrl+,"))?;
    let about = PredefinedMenuItem::about(
        app,
        Some("关于实践管理系统"),
        Some(AboutMetadata {
            name: Some("实践管理系统".into()),
            version: Some(env!("CARGO_PKG_VERSION").into()),
            ..Default::default()
        }),
    )?;
    let application = Submenu::with_items(
        app,
        "实践管理系统",
        true,
        &[
            &about,
            &settings,
            &MenuItem::with_id(app, "check-update", "检查更新…", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::quit(app, Some("退出"))?,
        ],
    )?;
    let edit = Submenu::with_items(
        app,
        "编辑",
        true,
        &[
            &PredefinedMenuItem::undo(app, Some("撤销"))?,
            &PredefinedMenuItem::redo(app, Some("重做"))?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::cut(app, Some("剪切"))?,
            &PredefinedMenuItem::copy(app, Some("复制"))?,
            &PredefinedMenuItem::paste(app, Some("粘贴"))?,
            &PredefinedMenuItem::select_all(app, Some("全选"))?,
        ],
    )?;
    let view = Submenu::with_items(
        app,
        "窗口",
        true,
        &[
            &MenuItem::with_id(app, "back", "后退", true, Some("CmdOrCtrl+["))?,
            &MenuItem::with_id(app, "forward", "前进", true, Some("CmdOrCtrl+]"))?,
            &MenuItem::with_id(app, "reload", "重新加载", true, Some("CmdOrCtrl+R"))?,
            &MenuItem::with_id(
                app,
                "external-browser",
                "在浏览器打开当前网页",
                true,
                None::<&str>,
            )?,
            &MenuItem::with_id(app, "copy-address", "复制当前网址", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "zoom-in", "放大", true, Some("CmdOrCtrl+="))?,
            &MenuItem::with_id(app, "zoom-out", "缩小", true, Some("CmdOrCtrl+-"))?,
            &MenuItem::with_id(app, "zoom-reset", "实际大小", true, Some("CmdOrCtrl+0"))?,
            &MenuItem::with_id(app, "fullscreen", "切换全屏", true, Some("F11"))?,
            &MenuItem::with_id(app, "downloads", "打开下载文件夹", true, None::<&str>)?,
        ],
    )?;
    Menu::with_items(app, &[&application, &edit, &view])
}

/// 处理原生导航与窗口菜单。
fn handle_menu(app: &AppHandle, id: &str) {
    if id == "check-update" {
        if let Some(window) = app.get_webview_window("school") {
            let _ = window.eval("window.__PRACTICAL_DESKTOP__?.checkUpdate(true)");
        } else {
            app.dialog()
                .message("请先连接学校并登录后检查更新")
                .show(|_| {});
        }
        return;
    }
    if id == "connection" {
        windows::show_connection(app);
        return;
    }
    if id == "downloads" {
        if let Ok(path) = app.path().download_dir() {
            let _ = app.opener().open_path(path.to_string_lossy(), None::<&str>);
        }
        return;
    }
    let window = app
        .webview_windows()
        .into_values()
        .find(|window| window.label() != "connection" && window.is_focused().unwrap_or(false))
        .or_else(|| app.get_webview_window("school"));
    let Some(window) = window else {
        return;
    };
    match id {
        "external-browser" | "copy-address" => external::menu(app, &window, id),
        "back" => {
            let _ = window.eval("history.back()");
        }
        "forward" => {
            let _ = window.eval("history.forward()");
        }
        "reload" => {
            let _ = window.reload();
        }
        "fullscreen" => {
            let _ = window.set_fullscreen(!window.is_fullscreen().unwrap_or(false));
        }
        "zoom-in" | "zoom-out" | "zoom-reset" => {
            let state = app.state::<AppState>();
            if let Ok(mut zoom) = state.zoom.lock() {
                *zoom = match id {
                    "zoom-in" => (*zoom + 0.1).min(2.0),
                    "zoom-out" => (*zoom - 0.1).max(0.6),
                    _ => 1.0,
                };
                let _ = window.set_zoom(*zoom);
            };
        }
        _ => {}
    }
}

/// 初始化原生客户端与本地连接命令。
pub fn run() {
    let mut builder = tauri::Builder::default()
        .manage(AppState::default())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_clipboard_manager::init())
        .plugin(
            tauri_plugin_opener::Builder::new()
                .open_js_links_on_click(false)
                .build(),
        )
        .invoke_handler(tauri::generate_handler![
            read_connection_settings,
            read_saved_connection_password,
            connect_school
        ]);

    #[cfg(target_os = "macos")]
    {
        builder = builder
            .menu(application_menu)
            .on_menu_event(|app, event| handle_menu(app, event.id().as_ref()));
    }

    builder
        .on_window_event(|window, event| {
            if window.label() == "connection" {
                if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                    if window.app_handle().get_webview_window("school").is_some() {
                        api.prevent_close();
                        let _ = window.hide();
                    }
                }
            } else if window.label() == "school" {
                if matches!(event, tauri::WindowEvent::CloseRequested { .. }) {
                    if let Ok(mut gateway) = window.app_handle().state::<AppState>().gateway.lock()
                    {
                        gateway.take();
                    }
                    for (label, preview) in window.app_handle().webview_windows() {
                        if label.starts_with("preview-") {
                            let _ = preview.destroy();
                        }
                    }
                }
                if matches!(event, tauri::WindowEvent::Destroyed) {
                    windows::show_connection(window.app_handle());
                }
            }
        })
        .build(tauri::generate_context!())
        .expect("无法启动实践管理客户端")
        .run(|app, event| {
            #[cfg(target_os = "macos")]
            if let tauri::RunEvent::Reopen { .. } = event {
                if let Some(window) = app.get_webview_window("school") {
                    let _ = window.show();
                    let _ = window.set_focus();
                } else {
                    windows::show_connection(app);
                }
            }
            #[cfg(not(target_os = "macos"))]
            let _ = (app, event);
        });
}
