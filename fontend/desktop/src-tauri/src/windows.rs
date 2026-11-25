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
            const context = {context};
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
                value:Object.freeze({{...context, version:'{}',
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
            if (context.windowLabel === 'school') {{
                const appearanceKey = 'practical:desktop-ui-style:' + context.serverOrigin;
                const syncAppearance = () => {{
                    try {{
                        const saved = JSON.parse(localStorage.getItem(appearanceKey));
                        if (saved?.account_id) bridge('agent-appearance', {{
                            account_id:Number(saved.account_id), style:saved.style, theme:saved.theme
                        }}).catch(() => {{}});
                    }} catch {{}}
                }};
                const observer = new MutationObserver(changes => {{
                    if (changes.some(change => change.oldValue !== change.target.getAttribute(change.attributeName))) syncAppearance();
                }});
                window.addEventListener('practical-agent-request-appearance', () => {{
                    observer.observe(document.documentElement, {{attributeOldValue:true, attributeFilter:['data-ui-style','data-theme']}});
                    syncAppearance();
                }});
            }}
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
    if label == "school" {
        finish_window_frame(&window)?;
    }
    Ok(window)
}

pub fn request_agent_appearance(app: &AppHandle) {
    if let Some(school) = app.get_webview_window("school") {
        let _ =
            school.eval("window.dispatchEvent(new Event('practical-agent-request-appearance'))");
    }
}

pub fn finish_window_frame(window: &WebviewWindow) -> tauri::Result<()> {
    #[cfg(target_os = "macos")]
    {
        window.with_webview(apply_window_frame)?;
        let resized = window.clone();
        window.on_window_event(move |event| {
            if matches!(event, tauri::WindowEvent::Resized(_)) {
                let _ = resized.with_webview(apply_window_frame);
            }
        });
    }
    #[cfg(not(target_os = "macos"))]
    let _ = window;
    Ok(())
}

#[cfg(target_os = "macos")]
fn apply_window_frame(webview: tauri::webview::PlatformWebview) {
    use objc2::{available, msg_send, rc::Retained, runtime::AnyObject};
    use objc2_app_kit::{NSColor, NSWindow, NSWindowStyleMask};
    use objc2_quartz_core::{kCACornerCurveContinuous, CACornerMask};

    // with_webview 在 AppKit 主线程持有窗口。
    let Some(window) = (unsafe { webview.ns_window().cast::<NSWindow>().as_ref() }) else {
        return;
    };
    if window.isOpaque() {
        window.setOpaque(false);
        window.setBackgroundColor(Some(&NSColor::clearColor()));
    }
    let Some(content) = window.contentView() else {
        return;
    };
    content.setWantsLayer(true);
    let Some(layer) = content.layer() else { return };
    let style = window.styleMask();
    let titled = style.contains(NSWindowStyleMask::Titled);
    let radius = if style.contains(NSWindowStyleMask::FullScreen) {
        0.0
    } else if titled && available!(macos = 27.0) {
        // macOS 27 公开的 NSView.effectiveCornerRadii 尚未收录在 objc2-app-kit 0.3 中。
        let corners: Option<Retained<AnyObject>> =
            unsafe { msg_send![&*content, effectiveCornerRadii] };
        corners.map_or(16.0, |radii| unsafe { msg_send![&*radii, bottomLeft] })
    } else if available!(macos = 26.0) {
        16.0
    } else {
        10.0
    };
    if !layer.masksToBounds() || layer.cornerRadius() != radius {
        let mut corners = CACornerMask::LayerMinXMinYCorner | CACornerMask::LayerMaxXMinYCorner;
        if !titled {
            corners |= CACornerMask::LayerMinXMaxYCorner | CACornerMask::LayerMaxXMaxYCorner;
        }
        layer.setMaskedCorners(corners);
        layer.setCornerCurve(unsafe { kCACornerCurveContinuous });
        layer.setCornerRadius(radius);
        layer.setMasksToBounds(true);
        window.invalidateShadow();
    }
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

/// 恢复应用内上次使用的窗口。
#[cfg(target_os = "macos")]
pub fn restore_active(app: &AppHandle) {
    let school = app.get_webview_window("school");
    let window = if crate::current_school_locked(app) {
        school
    } else {
        let label = app
            .state::<crate::AppState>()
            .active_window
            .lock()
            .ok()
            .and_then(|active| active.clone());
        let owner = app
            .state::<crate::AppState>()
            .gateway
            .lock()
            .ok()
            .and_then(|gateway| gateway.as_ref().map(|gateway| gateway.owner.clone()));
        label
            .filter(|label| {
                label == "school"
                    || label == "connection"
                    || owner.as_ref().is_some_and(|owner| {
                        belongs_to_school(label, owner) || label.starts_with("external-")
                    })
            })
            .and_then(|label| app.get_webview_window(&label))
            .or(school)
    };
    if let Some(window) = window {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
    } else {
        show_connection(app);
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
