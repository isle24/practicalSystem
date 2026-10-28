mod agent_models;
mod agent_profile_sync;
mod agent_profiles;
mod agent_process;
mod agent_store;
mod agent_workspace;
mod client_agent;
mod client_lock;
mod connection;
mod connection_login;
mod credential_vault;
mod downloads;
mod external;
mod favorite_auth;
mod gateway;
mod input_activity;
mod local_lock_password;
mod office_engine;
mod office_preview;
mod preview_cache;
mod project_guides;
mod school_api;
mod updater;
mod windows;

use connection::Settings;
use connection_login::{CaptchaState, LoginError, PreparedLogin};
use std::sync::{
    atomic::{AtomicBool, AtomicU8, Ordering},
    Arc, Mutex,
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
    exit_gate: ExitGate,
    gateway: Mutex<Option<gateway::Gateway>>,
    pending_login: Mutex<Option<Arc<PreparedLogin>>>,
    pending_cleanup: tokio::sync::Mutex<()>,
    login_operation_scope: Mutex<Option<String>>,
    cancelled_login_scopes: Mutex<Vec<String>>,
    zoom: Mutex<f64>,
    #[cfg(target_os = "macos")]
    active_window: Mutex<Option<String>>,
}

#[derive(Debug, PartialEq, Eq)]
enum ExitDecision {
    Allow,
    Prevent,
    StartRevocation,
}

#[derive(Default)]
struct ExitGate(AtomicU8);

impl ExitGate {
    fn requested(&self) -> bool {
        self.0.load(Ordering::Acquire) != 0
    }

    fn request(&self, active: bool) -> ExitDecision {
        loop {
            match self.0.load(Ordering::Acquire) {
                2 => return ExitDecision::Allow,
                1 => return ExitDecision::Prevent,
                _ if !active => return ExitDecision::Allow,
                _ => {
                    if self
                        .0
                        .compare_exchange(0, 1, Ordering::AcqRel, Ordering::Acquire)
                        .is_ok()
                    {
                        return ExitDecision::StartRevocation;
                    }
                }
            }
        }
    }

    fn complete(&self) {
        self.0.store(2, Ordering::Release);
    }
    fn cancel(&self) {
        self.0.store(0, Ordering::Release);
    }
}

struct ConnectingGuard<'a>(&'a AtomicBool);

struct LoginOperationGuard<'a>(&'a AppState);

impl Drop for LoginOperationGuard<'_> {
    fn drop(&mut self) {
        if let Ok(mut scope) = self.0.login_operation_scope.lock() {
            scope.take();
        }
    }
}

fn cancel_login_scope(state: &AppState, scope: &str) {
    let operation = state
        .login_operation_scope
        .lock()
        .ok()
        .and_then(|scope| scope.clone());
    if let Ok(mut cancelled) = state.cancelled_login_scopes.lock() {
        if !cancelled.iter().any(|item| item == scope) {
            if cancelled.len() >= 64 {
                if let Some(index) = cancelled
                    .iter()
                    .position(|scope| Some(scope.as_str()) != operation.as_deref())
                {
                    cancelled.remove(index);
                }
            }
            cancelled.push(scope.to_owned());
        }
    }
    if let Ok(pending) = state.pending_login.lock() {
        if let Some(pending) = pending.as_ref().filter(|pending| pending.scope == scope) {
            pending.cancel.cancel();
        }
    }
}

fn login_scope_cancelled(state: &AppState, scope: &str) -> bool {
    state.exit_gate.requested()
        || state
            .cancelled_login_scopes
            .lock()
            .map(|cancelled| cancelled.iter().any(|item| item == scope))
            .unwrap_or(true)
}

fn publish_school_gateway(
    state: &AppState,
    gateway: gateway::Gateway,
    pending: Option<&Arc<PreparedLogin>>,
    scope: &str,
) -> Result<(), String> {
    let cancelled = state
        .cancelled_login_scopes
        .lock()
        .map_err(|_| "登录连接状态不可用")?;
    if state.exit_gate.requested() || cancelled.iter().any(|item| item == scope) {
        return Err("登录连接已取消，请重新连接".into());
    }
    let mut prepared = state
        .pending_login
        .lock()
        .map_err(|_| "登录连接状态不可用")?;
    if pending.is_some_and(|pending| {
        pending.cancel.is_cancelled()
            || !prepared
                .as_ref()
                .is_some_and(|active| Arc::ptr_eq(active, pending))
    }) {
        return Err("登录连接已取消，请重新连接".into());
    }
    let mut active = state
        .gateway
        .lock()
        .map_err(|_| "连接状态不可用，请重新连接")?;
    if pending.is_some() {
        prepared.take();
    }
    *active = Some(gateway);
    Ok(())
}

fn reset_cancelled_connection(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("connection") {
        let _ = window.eval("window.dispatchEvent(new Event('practical-client-login-cancelled'))");
    }
}

impl Drop for ConnectingGuard<'_> {
    /// 释放重复连接保护。
    fn drop(&mut self) {
        self.0.store(false, Ordering::Release);
    }
}

async fn revoke_current_school(app: &AppHandle) -> Result<Option<String>, String> {
    revoke_school(app, false).await
}

async fn revoke_school(app: &AppHandle, reconnect: bool) -> Result<Option<String>, String> {
    let previous = {
        let state = app.state::<AppState>();
        let active = state.gateway.lock().map_err(|_| "连接状态不可用")?;
        active
            .as_ref()
            .map(|gateway| {
                gateway
                    .source_session()
                    .map(|session| (gateway.owner.clone(), session))
            })
            .transpose()?
    };
    if let Some((owner, session)) = previous {
        office_preview::cancel_owner(&owner);
        client_agent::cancel_owner(&owner);
        if reconnect {
            session.logout_for_reconnect().await?;
        } else {
            session.logout().await?;
        }
        Ok(Some(owner))
    } else {
        Ok(None)
    }
}

async fn discard_pending_login(app: &AppHandle, scope: Option<&str>) -> Result<(), String> {
    let state = app.state::<AppState>();
    let _cleanup = state.pending_cleanup.lock().await;
    let pending = {
        let pending = state
            .pending_login
            .lock()
            .map_err(|_| "登录连接状态不可用")?;
        pending
            .as_ref()
            .filter(|pending| scope.is_none_or(|scope| pending.scope == scope))
            .cloned()
    };
    if let Some(pending) = pending {
        pending.cleanup().await?;
        let mut active = state
            .pending_login
            .lock()
            .map_err(|_| "登录连接状态不可用")?;
        if active
            .as_ref()
            .is_some_and(|active| Arc::ptr_eq(active, &pending))
        {
            active.take();
        }
    }
    Ok(())
}

async fn prepare_login(
    app: &AppHandle,
    origin: url::Url,
    username: &str,
    scope: &str,
    port: u16,
) -> Result<Arc<PreparedLogin>, LoginError> {
    if scope.is_empty()
        || scope.len() > 64
        || !scope
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '-')
    {
        return Err("登录请求标识无效，请重新打开连接页".into());
    }
    let existing = app
        .state::<AppState>()
        .pending_login
        .lock()
        .map_err(|_| "登录连接状态不可用")?
        .as_ref()
        .filter(|item| item.matches(scope, username, &origin))
        .cloned();
    if let Some(existing) = existing {
        return Ok(existing);
    }
    discard_pending_login(app, None).await?;
    if login_scope_cancelled(&app.state::<AppState>(), scope) {
        return Err("登录连接已取消，请重新连接".into());
    }
    let pending = Arc::new(PreparedLogin::new(
        scope.to_owned(),
        username.to_owned(),
        origin,
        port,
    ));
    *app.state::<AppState>()
        .pending_login
        .lock()
        .map_err(|_| "登录连接状态不可用")? = Some(pending.clone());
    Ok(pending)
}

fn pending_login_current(app: &AppHandle, pending: &Arc<PreparedLogin>) -> bool {
    !pending.cancel.is_cancelled()
        && app
            .state::<AppState>()
            .pending_login
            .lock()
            .is_ok_and(|active| {
                active
                    .as_ref()
                    .is_some_and(|active| Arc::ptr_eq(active, pending))
            })
}

#[tauri::command]
async fn cancel_connection_login(
    app: AppHandle,
    window: WebviewWindow,
    login_scope: String,
) -> Result<(), LoginError> {
    require_connection(&window)?;
    cancel_login_scope(&app.state::<AppState>(), &login_scope);
    discard_pending_login(&app, Some(&login_scope))
        .await
        .map_err(LoginError::from)
}

#[tauri::command]
async fn create_connection_login_captcha(
    app: AppHandle,
    window: WebviewWindow,
    state: State<'_, AppState>,
    domain: String,
    username: String,
    login_scope: String,
) -> Result<CaptchaState, LoginError> {
    require_connection(&window)?;
    if current_school_locked(&app) {
        return Err("客户端已锁定，请在锁屏退出原会话后重新连接".into());
    }
    if username.trim().is_empty() || username.len() > 255 {
        return Err("请输入有效账号后获取验证码".into());
    }
    if state.connecting.swap(true, Ordering::AcqRel) {
        return Err("正在连接，请稍候".into());
    }
    let _guard = ConnectingGuard(&state.connecting);
    if login_scope_cancelled(&state, &login_scope) {
        return Err("登录连接已取消，请重新连接".into());
    }
    *state
        .login_operation_scope
        .lock()
        .map_err(|_| "登录连接状态不可用")? = Some(login_scope.clone());
    let _operation = LoginOperationGuard(&state);
    let origin = connection::school_origin(&domain)?;
    let settings = connection::load(&app)?;
    let port = settings
        .schools
        .iter()
        .find(|school| school.origin == origin.origin().ascii_serialization())
        .map_or(0, |school| school.port);
    let pending = prepare_login(&app, origin, username.trim(), &login_scope, port).await?;
    let result = pending.create_captcha().await;
    if !pending_login_current(&app, &pending) || current_school_locked(&app) {
        let _ = pending.cleanup().await;
        return Err("登录连接已变更，请重新获取验证码".into());
    }
    result
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
fn read_connection_settings(
    app: AppHandle,
    window: WebviewWindow,
) -> Result<serde_json::Value, String> {
    require_connection(&window)?;
    let mut settings =
        serde_json::to_value(connection::load(&app)?).map_err(|_| "连接设置无法读取")?;
    settings["require_fresh_login"] =
        serde_json::json!(client_lock::load(&app)?.require_fresh_login);
    Ok(settings)
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
    if client_lock::load(&app)?.require_fresh_login {
        return Ok(None);
    }
    if current_school_locked(&app) {
        return Ok(None);
    }
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
    login_method: Option<String>,
    remember_password: bool,
    auto_login: bool,
    remark: Option<String>,
    recovery: Option<bool>,
    fresh_password: Option<bool>,
    login_scope: Option<String>,
    captcha_id: Option<String>,
    captcha_code: Option<String>,
) -> Result<Settings, LoginError> {
    let password = zeroize::Zeroizing::new(password);
    require_connection(&window)?;
    if current_school_locked(&app) {
        return Err("客户端已锁定，请在锁屏退出原会话后重新连接".into());
    }
    let recovery = recovery.unwrap_or(false);
    let login_method = if recovery {
        Some("wechat".to_owned())
    } else {
        login_method
    };
    if recovery
        && (!username.trim().is_empty() || !password.is_empty() || remember_password || auto_login)
    {
        return Err("找回密码须通过学校扫码连接，不能携带保存密码".into());
    }
    let login_method = login_method.unwrap_or_else(|| {
        if username.trim().is_empty() && password.is_empty() {
            "wechat".into()
        } else {
            "password".into()
        }
    });
    if !matches!(login_method.as_str(), "password" | "wechat") {
        return Err("请选择有效的登录方式".into());
    }
    if login_method == "password" && (username.trim().is_empty() || password.is_empty()) {
        return Err("请输入账号和密码，或选择企业微信扫码登录".into());
    }
    if username.len() > 255
        || password.len() > 4096
        || captcha_id.as_ref().is_some_and(|id| id.len() > 128)
        || captcha_code.as_ref().is_some_and(|code| code.len() > 64)
    {
        return Err("登录输入过长，请检查账号、密码和验证码".into());
    }
    if login_method == "wechat"
        && (!username.trim().is_empty() || !password.is_empty() || remember_password || auto_login)
    {
        return Err("企业微信扫码登录无需填写账号密码".into());
    }
    if client_lock::load(&app)?.require_fresh_login
        && login_method == "password"
        && fresh_password != Some(true)
    {
        return Err("客户端锁定后需要手工输入密码，或重新进行企业微信扫码登录".into());
    }
    if state.connecting.swap(true, Ordering::AcqRel) {
        return Err("正在连接，请稍候".into());
    }
    let _guard = ConnectingGuard(&state.connecting);
    let login_scope = login_scope.unwrap_or_else(|| uuid::Uuid::new_v4().to_string());
    if login_scope_cancelled(&state, &login_scope) {
        return Err("登录连接已取消，请重新连接".into());
    }
    *state
        .login_operation_scope
        .lock()
        .map_err(|_| "登录连接状态不可用")? = Some(login_scope.clone());
    let _operation = LoginOperationGuard(&state);
    let origin = connection::school_origin(&domain)?;
    let settings = connection::load(&app)?;
    if let Some(school) = app.get_webview_window("school") {
        let same_school = state
            .gateway
            .lock()
            .map_err(|_| "连接状态不可用")?
            .as_ref()
            .is_some_and(|gateway| gateway.remote.origin() == origin.origin());
        if !recovery
            && same_school
            && settings.last_username == username.trim()
            && password.is_empty()
        {
            let _ = school.unminimize();
            let _ = school.set_focus();
            let _ = window.hide();
            return Ok(settings);
        }
        let proceed = app
            .dialog()
            .message("重新连接学校或账号将关闭当前学校的所有窗口，并结束本次登录。请先保存正在填写的内容。")
            .title("重新连接")
            .buttons(MessageDialogButtons::OkCancelCustom(
                "重新连接".into(),
                "取消".into(),
            ))
            .blocking_show();
        if !proceed {
            discard_pending_login(&app, Some(login_scope.as_str())).await?;
            return Err("已取消切换，当前学校保持打开".into());
        }
    }
    let preferred_port = settings
        .schools
        .iter()
        .find(|school| school.origin == origin.origin().ascii_serialization())
        .map_or(0, |school| school.port);
    let pending = if login_method == "password" {
        Some(
            prepare_login(
                &app,
                origin.clone(),
                username.trim(),
                &login_scope,
                preferred_port,
            )
            .await?,
        )
    } else {
        discard_pending_login(&app, None).await?;
        None
    };
    let verified_context = if let Some(pending) = &pending {
        match pending
            .authenticate(&password, captcha_id.as_deref(), captcha_code.as_deref())
            .await
        {
            Ok(context) => Some(context),
            Err(error) => {
                if error.uncertain_session {
                    let _ = discard_pending_login(&app, Some(&pending.scope)).await;
                }
                return Err(error);
            }
        }
    } else {
        None
    };
    let connection = if let Some(pending) = &pending {
        pending.connection().await?.clone()
    } else {
        connection::connect(origin, preferred_port).await?
    };
    let cleanup_connection = connection.clone();
    if login_scope_cancelled(&state, &login_scope) {
        if let Some(pending) = &pending {
            let _ = discard_pending_login(&app, Some(&pending.scope)).await;
        } else {
            cleanup_connection.cancel.cancel();
            let _ = gateway::logout_source(&cleanup_connection.client, &cleanup_connection.origin)
                .await;
        }
        return Err("登录连接已取消，请重新连接".into());
    }
    let result = activate_school(
        &app,
        &window,
        &state,
        connection,
        settings,
        username.trim(),
        &password,
        &login_method,
        remember_password,
        auto_login,
        remark.unwrap_or_default(),
        recovery,
        verified_context.as_ref(),
        pending.as_ref(),
        &login_scope,
    )
    .await;
    match result {
        Ok(settings) => Ok(settings),
        Err(message) => {
            let cleanup = if let Some(pending) = pending {
                let removed = discard_pending_login(&app, Some(&pending.scope)).await;
                if removed.is_err() {
                    removed
                } else {
                    pending.cleanup().await
                }
            } else {
                cleanup_connection.cancel.cancel();
                gateway::logout_source(&cleanup_connection.client, &cleanup_connection.origin).await
            };
            if cleanup.is_err() {
                return Err(format!("{message}；新登录退出暂未完成，请稍后重试").into());
            }
            Err(message.into())
        }
    }
}

#[allow(clippy::too_many_arguments)]
async fn activate_school(
    app: &AppHandle,
    window: &WebviewWindow,
    state: &AppState,
    mut connection: connection::Connection,
    mut settings: Settings,
    username: &str,
    password: &str,
    login_method: &str,
    remember_password: bool,
    auto_login: bool,
    remark: String,
    recovery: bool,
    verified_context: Option<&serde_json::Value>,
    pending: Option<&Arc<PreparedLogin>>,
    login_scope: &str,
) -> Result<Settings, String> {
    if login_scope_cancelled(state, login_scope)
        || current_school_locked(app)
        || pending.is_some_and(|pending| !pending_login_current(app, pending))
    {
        return Err("登录连接已取消或客户端已锁定，请重新连接".into());
    }
    let mut profile = connection.school.clone();
    profile.username = verified_context
        .and_then(|context| context["login_name"].as_str())
        .unwrap_or(username)
        .to_owned();
    profile.login_method = login_method.to_owned();
    profile.auto_login = auto_login && remember_password && !password.is_empty();
    profile.remark = remark.trim().chars().take(60).collect();
    connection.school = profile.clone();
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
        verified_context,
        recovery,
    )
    .await?;
    if login_scope_cancelled(state, login_scope)
        || current_school_locked(app)
        || pending.is_some_and(|pending| !pending_login_current(app, pending))
    {
        return Err("客户端已锁定，请退出原会话后重新连接".into());
    }
    if let Some(owner) = revoke_school(app, true)
        .await
        .map_err(|reason| format!("原学校退出未完成，当前学校连接已保留：{reason}"))?
    {
        state.gateway.lock().map_err(|_| "连接状态不可用")?.take();
        windows::close_school(app, &owner);
    }
    if login_scope_cancelled(state, login_scope) {
        return Err("登录连接已取消，请重新连接".into());
    }
    profile.port = gateway.port;
    settings.last_origin = profile.origin.clone();
    settings.last_username = profile.username.clone();
    settings
        .schools
        .retain(|school| school.origin != profile.origin || school.username != profile.username);
    settings.schools.insert(0, profile.clone());
    let school = windows::build(
        app,
        "school",
        gateway.entry.clone(),
        gateway.origin.clone(),
        gateway.remote.clone(),
        &gateway.owner,
        &profile.login_method,
        None,
    )
    .map_err(|_| "无法打开学校窗口，请重新连接")?;
    if login_scope_cancelled(state, login_scope) {
        windows::close_school(app, &gateway.owner);
        return Err("登录连接已取消，请重新连接".into());
    }
    let _ = school.set_title(&format!("{} - 实践系统", profile.name));
    let owner = gateway.owner.clone();
    if let Err(message) = publish_school_gateway(state, gateway, pending, login_scope) {
        windows::close_school(app, &owner);
        return Err(message);
    }
    if let Ok(mut zoom) = state.zoom.lock() {
        *zoom = 1.0;
    }
    if let Err(message) = connection::save(app, &settings) {
        app.dialog()
            .message(format!("已登录，但学校连接记录未保存：{message}"))
            .title("保存学校连接")
            .show(|_| {});
    }
    if let Some(context) = verified_context {
        if let Ok(active) = state.gateway.lock() {
            if let Some(gateway) = active.as_ref() {
                if let Err(message) = gateway.lock.authenticated(app, context, true) {
                    app.dialog()
                        .message(message)
                        .title("本机锁定设置")
                        .show(|_| {});
                }
            }
        }
        let saved = if remember_password {
            connection::save_password(&profile.origin, &profile.username, password)
        } else {
            connection::delete_password(&profile.origin, &profile.username)
        };
        if let Err(message) = saved {
            app.dialog()
                .message(format!("已登录，但本机密码设置未保存：{message}"))
                .title("保存学校连接")
                .show(|_| {});
        }
    }
    let _ = window.hide();
    Ok(settings)
}

pub(crate) fn current_school_locked(app: &AppHandle) -> bool {
    app.state::<AppState>()
        .gateway
        .lock()
        .map(|active| {
            active
                .as_ref()
                .is_some_and(|gateway| gateway.lock.is_locked())
        })
        .unwrap_or(true)
}

pub(crate) fn school_owner_current(app: &AppHandle, owner: &str) -> bool {
    app.state::<AppState>().gateway.lock().is_ok_and(|active| {
        active
            .as_ref()
            .is_some_and(|gateway| gateway.owner == owner)
    })
}

/// 创建系统菜单，保留原生编辑快捷键和窗口操作。
fn application_menu(app: &AppHandle) -> tauri::Result<Menu<tauri::Wry>> {
    let settings = MenuItem::with_id(app, "connection", "连接设置…", true, Some("CmdOrCtrl+,"))?;
    let about = PredefinedMenuItem::about(
        app,
        Some("关于实践系统"),
        Some(AboutMetadata {
            name: Some("实践系统".into()),
            version: Some(env!("CARGO_PKG_VERSION").into()),
            ..Default::default()
        }),
    )?;
    let application = Submenu::with_items(
        app,
        "实践系统",
        true,
        &[
            &about,
            &settings,
            &MenuItem::with_id(
                app,
                "client-agent",
                "客户端 Agent…",
                true,
                Some("CmdOrCtrl+Shift+A"),
            )?,
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
    if id == "client-agent" {
        if let Some(window) = app.get_webview_window("school") {
            let _ = window
                .eval("window.__PRACTICAL_DESKTOP__?.agent('open').catch(e=>alert(e.message))");
        } else {
            app.dialog()
                .message("请先连接学校并登录后打开 Agent")
                .show(|_| {});
        }
        return;
    }
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
    if current_school_locked(app)
        && !matches!(id, "fullscreen" | "zoom-in" | "zoom-out" | "zoom-reset")
    {
        return;
    }
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
            if window.label() == "school" {
                windows::notify_state(app);
            }
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
            create_connection_login_captcha,
            cancel_connection_login,
            connect_school
        ]);

    #[cfg(target_os = "macos")]
    {
        builder = builder
            .menu(application_menu)
            .on_menu_event(|app, event| handle_menu(app, event.id().as_ref()));
    }

    builder
        .setup(|app| {
            crate::credential_vault::initialize(app.path().app_config_dir()?)
                .map_err(std::io::Error::other)?;
            Ok(())
        })
        .on_window_event(|window, event| {
            if matches!(event, tauri::WindowEvent::Focused(true))
                && window.label() != "school"
                && window.label() != "connection"
                && current_school_locked(window.app_handle())
            {
                let _ = window.hide();
                return;
            }
            #[cfg(target_os = "macos")]
            if matches!(event, tauri::WindowEvent::Focused(true)) {
                if let Ok(mut active) = window.app_handle().state::<AppState>().active_window.lock()
                {
                    *active = Some(window.label().to_owned());
                }
            }
            if window.label() == "connection" {
                if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                    let pending = window
                        .app_handle()
                        .state::<AppState>()
                        .pending_login
                        .lock()
                        .ok()
                        .and_then(|pending| pending.as_ref().cloned());
                    if let Some(pending) = &pending {
                        pending.cancel.cancel();
                    }
                    let state = window.app_handle().state::<AppState>();
                    let scope = state
                        .login_operation_scope
                        .lock()
                        .ok()
                        .and_then(|scope| scope.clone())
                        .or_else(|| pending.as_ref().map(|pending| pending.scope.clone()));
                    if let Some(scope) = &scope {
                        cancel_login_scope(&state, scope);
                    }
                    if window.app_handle().get_webview_window("school").is_some() {
                        api.prevent_close();
                        let _ = window.hide();
                        if let Some(scope) = scope {
                            let app = window.app_handle().clone();
                            tauri::async_runtime::spawn(async move {
                                if discard_pending_login(&app, Some(&scope)).await.is_err() {
                                    app.dialog()
                                        .message("待登录连接退出暂未完成，请稍后重试")
                                        .title("关闭登录连接")
                                        .show(|_| {});
                                }
                            });
                        }
                    } else if pending.is_some() || state.connecting.load(Ordering::Acquire) {
                        api.prevent_close();
                        window.app_handle().exit(0);
                    }
                }
            } else if window.label() == "school" {
                if matches!(
                    event,
                    tauri::WindowEvent::Resized(_)
                        | tauri::WindowEvent::Focused(_)
                        | tauri::WindowEvent::ScaleFactorChanged { .. }
                ) {
                    windows::notify_state(window.app_handle());
                }
                if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                    api.prevent_close();
                    let app = window.app_handle().clone();
                    let state = app.state::<AppState>();
                    if state.connecting.swap(true, Ordering::AcqRel) {
                        return;
                    }
                    tauri::async_runtime::spawn(async move {
                        let state = app.state::<AppState>();
                        let _guard = ConnectingGuard(&state.connecting);
                        let owner = match revoke_current_school(&app).await {
                            Ok(Some(owner)) => owner,
                            Ok(None) => String::new(),
                            Err(reason) => {
                                app.dialog()
                                    .message(format!("原学校退出未完成，已保留当前连接：{reason}"))
                                    .title("无法关闭学校连接")
                                    .show(|_| {});
                                return;
                            }
                        };
                        if let Ok(mut active) = state.gateway.lock() {
                            active.take();
                        };
                        windows::close_school(&app, &owner);
                    });
                }
                if matches!(event, tauri::WindowEvent::Destroyed) {
                    windows::show_connection(window.app_handle());
                }
            }
        })
        .build(tauri::generate_context!())
        .expect("无法启动实践管理客户端")
        .run(|app, event| {
            if let tauri::RunEvent::ExitRequested { api, .. } = &event {
                let state = app.state::<AppState>();
                let active = state
                    .gateway
                    .lock()
                    .map(|gateway| gateway.is_some())
                    .unwrap_or(true)
                    || state
                        .pending_login
                        .lock()
                        .map(|pending| pending.is_some())
                        .unwrap_or(true)
                    || state.connecting.load(Ordering::Acquire);
                match state.exit_gate.request(active) {
                    ExitDecision::Allow => {}
                    ExitDecision::Prevent => api.prevent_exit(),
                    ExitDecision::StartRevocation => {
                        api.prevent_exit();
                        let scope = state
                            .login_operation_scope
                            .lock()
                            .ok()
                            .and_then(|scope| scope.clone());
                        if let Some(scope) = scope {
                            cancel_login_scope(&state, &scope);
                        }
                        if let Ok(pending) = state.pending_login.lock() {
                            if let Some(pending) = pending.as_ref() {
                                pending.cancel.cancel();
                            }
                        }
                        let occupied = state.connecting.swap(true, Ordering::AcqRel);
                        let app = app.clone();
                        tauri::async_runtime::spawn(async move {
                            let state = app.state::<AppState>();
                            if occupied {
                                while state
                                    .connecting
                                    .compare_exchange(
                                        false,
                                        true,
                                        Ordering::AcqRel,
                                        Ordering::Acquire,
                                    )
                                    .is_err()
                                {
                                    tokio::time::sleep(std::time::Duration::from_millis(50)).await;
                                }
                            }
                            let _guard = ConnectingGuard(&state.connecting);
                            if discard_pending_login(&app, None).await.is_err() {
                                state.exit_gate.cancel();
                                reset_cancelled_connection(&app);
                                app.dialog()
                                    .message("待登录连接退出暂未完成，已取消退出，请稍后重试")
                                    .title("无法退出客户端")
                                    .show(|_| {});
                                return;
                            }
                            match revoke_current_school(&app).await {
                                Ok(owner) => {
                                    if let Ok(mut gateway) = state.gateway.lock() {
                                        gateway.take();
                                    }
                                    if let Some(owner) = owner {
                                        windows::close_school(&app, &owner);
                                    }
                                    state.exit_gate.complete();
                                    app.exit(0);
                                }
                                Err(reason) => {
                                    state.exit_gate.cancel();
                                    reset_cancelled_connection(&app);
                                    app.dialog()
                                        .message(format!("原学校退出未完成，已取消退出：{reason}"))
                                        .title("无法退出客户端")
                                        .show(|_| {});
                                }
                            }
                        });
                        return;
                    }
                }
            }
            #[cfg(target_os = "macos")]
            if let tauri::RunEvent::Reopen {
                has_visible_windows: false,
                ..
            } = event
            {
                windows::restore_active(app);
            }
            #[cfg(not(target_os = "macos"))]
            let _ = (app, event);
        });
}

#[cfg(test)]
mod exit_gate_tests {
    use super::{ExitDecision, ExitGate};

    #[test]
    fn repeated_exit_waits_until_source_logout_is_confirmed() {
        let gate = ExitGate::default();
        assert_eq!(gate.request(true), ExitDecision::StartRevocation);
        assert_eq!(gate.request(true), ExitDecision::Prevent);
        assert_eq!(gate.request(false), ExitDecision::Prevent);
        gate.complete();
        assert_eq!(gate.request(false), ExitDecision::Allow);
    }

    #[test]
    fn failed_logout_returns_to_guarded_idle_state() {
        let gate = ExitGate::default();
        assert_eq!(gate.request(true), ExitDecision::StartRevocation);
        gate.cancel();
        assert_eq!(gate.request(true), ExitDecision::StartRevocation);
    }
}
