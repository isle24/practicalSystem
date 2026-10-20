use crate::{downloads::Downloads, gateway};
use std::sync::Arc;
use tauri::{
    webview::{NewWindowFeatures, NewWindowResponse, PageLoadEvent},
    AppHandle, Manager, WebviewUrl, WebviewWindow, WebviewWindowBuilder,
};
use tauri_plugin_dialog::DialogExt;
use tauri_plugin_opener::OpenerExt;
use url::Url;

/// 打开学校主窗口或共享浏览上下文的预览、打印窗口。
pub fn build(
    app: &AppHandle,
    label: &str,
    url: Url,
    local: Url,
    remote: Url,
    owner: &str,
    login_method: &str,
    features: Option<NewWindowFeatures>,
) -> tauri::Result<WebviewWindow> {
    let navigation_app = app.clone();
    let navigation_label = label.to_owned();
    let navigation_local = local.clone();
    let navigation_remote = remote.clone();
    let popup_app = app.clone();
    let popup_local = local.clone();
    let popup_remote = remote.clone();
    let popup_owner = owner.to_owned();
    let popup_login_method = login_method.to_owned();
    let downloads = Arc::new(Downloads::default());
    let document_state = downloads.clone();
    let context = serde_json::json!({
        "serverOrigin": remote.origin().ascii_serialization(),
        "localOrigin": local.origin().ascii_serialization(),
        "windowLabel": label,
        "loginMethod": login_method,
        "platform": std::env::consts::OS,
    });
    let mut builder = WebviewWindowBuilder::new(app, label, WebviewUrl::External(url))
        .title("实践系统")
        .inner_size(1360.0, 900.0)
        .min_inner_size(880.0, 580.0)
        .resizable(true)
        .disable_drag_drop_handler()
        .center()
        .initialization_script(format!(
            r#"(() => {{
            const bridge = async (action, payload, signal) => {{
                const controller = new AbortController();
                const abort = () => controller.abort();
                if (signal?.aborted) controller.abort();
                signal?.addEventListener('abort', abort, {{once:true}});
                const timer = setTimeout(() => controller.abort(), action === 'office-preview' ? 300000 : action === 'credential-vault' ? 180000 : action === 'external' ? 90000 : 60000);
                try {{
                    const response = await fetch('/_desktop/' + action, {{
                        method:'POST', credentials:'same-origin', signal:controller.signal,
                        headers:{{'Content-Type':'application/json'}}, body:JSON.stringify(payload)
                    }});
                    const data = await response.json().catch(() => ({{}}));
                    if (!response.ok) {{ const error = new Error(data.message || '客户端操作失败，请重试'); error.status = response.status; throw error; }}
                    return data;
                }} catch (error) {{
                    if (error.name === 'AbortError') throw new Error('客户端操作超时，请重试');
                    throw error;
                }} finally {{ clearTimeout(timer); signal?.removeEventListener('abort', abort); }}
            }};
            Object.defineProperty(window, '__PRACTICAL_DESKTOP__', {{
                value:Object.freeze({{...{context}, version:'{}',
                    openExternal:(url,mode='client',favorite_id=0)=>bridge('external',{{url,mode,favorite_id}}),
                    openSso:(favorite_id,mode='client')=>bridge('sso',{{favorite_id,mode}}),
                    favoriteCredentials:(id,action,profile)=>bridge('favorite-credentials',{{id,action,profile}}),
                    credentialVault:(action,payload={{}})=>bridge('credential-vault',{{action,...payload}}),
                    agent:(action,payload={{}})=>bridge('agent',{{...payload,action}}),
                    windowControls:(action)=>bridge('window',{{action}}),
                    lock:(action,payload={{}})=>bridge('lock',{{...payload,action}}),
                    previewCache:(action='status',max_gb)=>bridge('preview-cache',{{action,max_gb}}),
                    previewFileUrl:(id)=>'/_desktop/preview-file?id='+encodeURIComponent(id),
                    officePreview:(action='status',payload={{}},signal)=>bridge('office-preview',{{...payload,action}},signal),
                    checkUpdate:(interactive=true)=>bridge('update',{{interactive}})
                }}), writable:false
            }});
            const agentButtons = () => document.querySelectorAll('.message-mode-tabs button').forEach(button => {{
                if (button.textContent.trim() === '问答助手') {{ button.textContent = 'Agent'; button.dataset.clientAgent = 'true'; }}
            }});
            document.addEventListener('click', event => {{
                const button = event.target.closest?.('.message-mode-tabs button');
                if (!button || button.dataset.clientAgent !== 'true' && button.textContent.trim() !== '问答助手') return;
                event.preventDefault(); event.stopImmediatePropagation();
                bridge('agent', {{action:'open'}}).catch(error => alert(error.message));
            }}, true);
            document.addEventListener('DOMContentLoaded', () => {{
                agentButtons(); new MutationObserver(agentButtons).observe(document.body, {{subtree:true,childList:true}});
            }}, {{once:true}});
        }})();"#,
            env!("CARGO_PKG_VERSION")
        ))
        .on_navigation(move |url| {
            if crate::current_school_locked(&navigation_app) && url.origin() != navigation_local.origin() { return false; }
            if url.origin() == navigation_local.origin()
                || url.as_str() == "about:blank"
                || url.scheme() == "blob"
            {
                return true;
            }
            if url.origin() == navigation_remote.origin() {
                if let (Ok(mapped), Some(window)) = (
                    gateway::local_url(&navigation_local, url),
                    navigation_app.get_webview_window(&navigation_label),
                ) {
                    let _ = window.navigate(mapped);
                }
            } else {
                open_external(&navigation_app, url);
            }
            false
        })
        .on_new_window(move |url, features| {
            if crate::current_school_locked(&popup_app) || !crate::school_owner_current(&popup_app, &popup_owner) {
                return NewWindowResponse::Deny;
            }
            if url.origin() != popup_local.origin()
                && url.origin() != popup_remote.origin()
                && url.as_str() != "about:blank"
                && url.scheme() != "blob"
            {
                open_external(&popup_app, &url);
                return NewWindowResponse::Deny;
            }
            let label = school_window_label("preview", &popup_owner, &uuid::Uuid::new_v4().simple().to_string());
            // about:blank 与 window_features 保留 document.write 打印所需的关联窗口。
            let blank = Url::parse("about:blank").expect("valid blank URL");
            match build(
                &popup_app,
                &label,
                blank,
                popup_local.clone(),
                popup_remote.clone(),
                &popup_owner,
                &popup_login_method,
                Some(features),
            ) {
                Ok(window) => NewWindowResponse::Create { window },
                Err(_) => {
                    popup_app
                        .dialog()
                        .message("无法打开预览窗口，请稍后重试")
                        .title("实践系统")
                        .show(|_| {});
                    NewWindowResponse::Deny
                }
            }
        })
        .on_document_title_changed(|window, title| {
            let title: String = title.chars().take(100).collect();
            let title = if title == "实践管理系统 PC" { "实践系统".to_owned() } else { title };
            if !title.is_empty() {
                let _ = window.set_title(&title);
            }
        })
        .on_page_load(move |_, page| {
            if page.event() == PageLoadEvent::Finished && page.url().as_str() != "about:blank" {
                document_state.mark_document_loaded();
            }
        })
        .on_download(move |webview, event| downloads.handle(webview, event));
    if label == "school" {
        builder = builder.decorations(false);
    }
    if let Some(features) = features {
        builder = builder.window_features(features);
    }
    let window = builder.build()?;
    crate::input_activity::install(&window);
    Ok(window)
}

/// 仅把普通网页和邮件链接交给系统应用。
fn open_external(app: &AppHandle, url: &Url) {
    if matches!(url.scheme(), "https" | "http" | "mailto")
        && app.opener().open_url(url.as_str(), None::<&str>).is_err()
    {
        app.dialog()
            .message("无法打开系统浏览器，请检查默认浏览器设置")
            .title("打开链接")
            .show(|_| {});
    }
}

/// 显示本地学校连接设置。
pub fn show_connection(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("connection") {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
    }
}

pub fn school_window_label(kind: &str, owner: &str, suffix: &str) -> String {
    format!("{kind}-{owner}-{suffix}")
}

pub(crate) fn belongs_to_school(label: &str, owner: &str) -> bool {
    ["preview", "sso", "authenticated", "agent"]
        .iter()
        .any(|kind| label.starts_with(&format!("{kind}-{owner}-")))
}

/// 关闭当前连接创建的业务、预览和授权窗口。
pub fn close_school(app: &AppHandle, owner: &str) {
    for (label, window) in app.webview_windows() {
        if label == "school" || belongs_to_school(&label, owner) {
            let _ = window.destroy();
        }
    }
}

pub fn hide_for_lock(app: &AppHandle, owner: &str) -> Vec<String> {
    let mut hidden = Vec::new();
    for (label, window) in app.webview_windows() {
        if (belongs_to_school(&label, owner) || label.starts_with("external-"))
            && window.is_visible().unwrap_or(false)
        {
            let _ = window.hide();
            hidden.push(label);
        }
    }
    hidden
}

pub fn restore_after_lock(app: &AppHandle, owner: &str, labels: &[String]) {
    if !crate::school_owner_current(app, owner) {
        return;
    }
    for label in labels {
        if belongs_to_school(label, owner) || label.starts_with("external-") {
            if let Some(window) = app.get_webview_window(label) {
                let _ = window.show();
            }
        }
    }
}

pub fn notify_lock(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("school") {
        let _ = window.eval("window.dispatchEvent(new Event('practical-desktop-lock-change'))");
    }
}

/// 关闭原账号子窗口。
pub fn close_account_windows(app: &AppHandle, owner: &str) {
    for (label, window) in app.webview_windows() {
        if belongs_to_school(&label, owner) {
            let _ = window.destroy();
        }
    }
}

pub fn reset_account_windows(app: &AppHandle, owner: &str) {
    close_account_windows(app, owner);
    if let Some(window) = app.get_webview_window("school") {
        let _ = window.reload();
    }
}

/// 控制当前学校主窗口。
pub fn control(app: &AppHandle, action: &str) -> Result<serde_json::Value, String> {
    if action == "connection" {
        if crate::current_school_locked(app) {
            return Err("客户端已锁定，请在锁屏退出原会话后重新连接".into());
        }
        let connection = app
            .get_webview_window("connection")
            .ok_or("连接窗口已关闭")?;
        connection
            .eval("window.dispatchEvent(new Event('practical-client-password-login'))")
            .map_err(|_| "无法显示客户端登录")?;
        show_connection(app);
        return Ok(serde_json::json!({"connection": true}));
    }
    let window = app.get_webview_window("school").ok_or("学校窗口已关闭")?;
    let result = match action {
        "status" => Ok(()),
        "drag" => window.start_dragging(),
        "minimize" => window.minimize(),
        "maximize" => {
            if window.is_maximized().map_err(|_| "无法读取窗口状态")? {
                window.unmaximize()
            } else {
                window.maximize()
            }
        }
        "fullscreen" => {
            window.set_fullscreen(!window.is_fullscreen().map_err(|_| "无法读取全屏状态")?)
        }
        "close" => window.close(),
        _ => return Err("窗口操作无效".into()),
    };
    result.map_err(|_| "窗口操作失败")?;
    let maximized = window.is_maximized().map_err(|_| "无法读取窗口状态")?;
    let fullscreen = window.is_fullscreen().map_err(|_| "无法读取全屏状态")?;
    if action != "status" {
        notify_state(app);
    }
    Ok(serde_json::json!({"maximized": maximized, "fullscreen": fullscreen}))
}

pub fn notify_state(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("school") {
        let _ = window.eval("window.dispatchEvent(new Event('practical-native-window-state'))");
    }
}

#[cfg(test)]
mod school_window_tests {
    use super::{belongs_to_school, school_window_label};
    #[test]
    fn closes_only_windows_owned_by_the_active_school() {
        let school_a = "first-session";
        let school_b = "second-session";
        let a_sso = school_window_label("sso", school_a, "one");
        let a_preview = school_window_label("preview", school_a, "two");
        let b_sso = school_window_label("sso", school_b, "three");
        assert!(belongs_to_school(&a_sso, school_a));
        assert!(belongs_to_school(&a_preview, school_a));
        assert!(!belongs_to_school(&b_sso, school_a));
        assert!(!belongs_to_school("external-unrelated", school_a));
    }
}
