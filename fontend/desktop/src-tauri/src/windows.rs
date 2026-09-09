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
    features: Option<NewWindowFeatures>,
) -> tauri::Result<WebviewWindow> {
    let navigation_app = app.clone();
    let navigation_label = label.to_owned();
    let navigation_local = local.clone();
    let navigation_remote = remote.clone();
    let popup_app = app.clone();
    let popup_local = local.clone();
    let popup_remote = remote.clone();
    let downloads = Arc::new(Downloads::default());
    let document_state = downloads.clone();
    let context = serde_json::json!({ "serverOrigin": remote.origin().ascii_serialization(), "localOrigin": local.origin().ascii_serialization() });
    let mut builder = WebviewWindowBuilder::new(app, label, WebviewUrl::External(url))
        .title("实践管理系统")
        .inner_size(1360.0, 900.0)
        .min_inner_size(880.0, 580.0)
        .resizable(true)
        .center()
        .initialization_script(format!("Object.defineProperty(window, '__PRACTICAL_DESKTOP__', {{ value: Object.freeze({context}), writable: false }});"))
        .on_navigation(move |url| {
            if url.origin() == navigation_local.origin() || url.as_str() == "about:blank" || url.scheme() == "blob" {
                return true;
            }
            if url.origin() == navigation_remote.origin() {
                if let (Ok(mapped), Some(window)) = (gateway::local_url(&navigation_local, url), navigation_app.get_webview_window(&navigation_label)) {
                    let _ = window.navigate(mapped);
                }
            } else {
                open_external(&navigation_app, url);
            }
            false
        })
        .on_new_window(move |url, features| {
            if url.origin() != popup_local.origin() && url.origin() != popup_remote.origin() && url.as_str() != "about:blank" && url.scheme() != "blob" {
                open_external(&popup_app, &url);
                return NewWindowResponse::Deny;
            }
            let label = format!("preview-{}", uuid::Uuid::new_v4().simple());
            // about:blank 与 window_features 保留 document.write 打印所需的关联窗口。
            let blank = Url::parse("about:blank").expect("valid blank URL");
            match build(&popup_app, &label, blank, popup_local.clone(), popup_remote.clone(), Some(features)) {
                Ok(window) => NewWindowResponse::Create { window },
                Err(_) => {
                    popup_app.dialog().message("无法打开预览窗口，请稍后重试").title("实践管理系统").show(|_| {});
                    NewWindowResponse::Deny
                }
            }
        })
        .on_document_title_changed(|window, title| {
            let title: String = title.chars().take(100).collect();
            if !title.is_empty() { let _ = window.set_title(&title); }
        })
        .on_page_load(move |_, page| {
            if page.event() == PageLoadEvent::Finished && page.url().as_str() != "about:blank" {
                document_state.mark_document_loaded();
            }
        })
        .on_download(move |webview, event| downloads.handle(webview, event));
    if let Some(features) = features {
        builder = builder.window_features(features);
    }
    builder.build()
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

/// 关闭当前学校的业务和预览窗口。
pub fn close_school(app: &AppHandle) {
    for (label, window) in app.webview_windows() {
        if label == "school" || label.starts_with("preview-") {
            let _ = window.destroy();
        }
    }
}
