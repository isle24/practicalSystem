use tauri::webview::{NewWindowFeatures, NewWindowResponse};
use tauri::{AppHandle, WebviewUrl, WebviewWindow, WebviewWindowBuilder};
use tauri_plugin_clipboard_manager::ClipboardExt;
use tauri_plugin_opener::OpenerExt;
use url::Url;

/// 外部网页不得访问本机客户端通道或特权协议。
fn allowed(url: &Url) -> bool {
    let host = url.host_str().unwrap_or("").to_ascii_lowercase();
    matches!(url.scheme(), "http" | "https")
        && !host.is_empty()
        && url.username().is_empty()
        && url.password().is_none()
        && host != "localhost"
        && !host.ends_with(".localhost")
        && !host.starts_with("127.")
        && host != "[::1]"
        && host != "0.0.0.0"
}

/// 统一打开收藏的客户端窗口或系统浏览器。
pub fn open(app: &AppHandle, address: &str, mode: &str) -> Result<(), String> {
    let url = Url::parse(address).map_err(|_| "网址格式不正确")?;
    if !allowed(&url) {
        return Err("仅允许普通 HTTP/HTTPS 外部网页".into());
    }
    if mode == "browser" {
        return app
            .opener()
            .open_url(url.as_str(), None::<&str>)
            .map_err(|_| "无法打开系统浏览器".into());
    }
    if mode != "client" {
        return Err("打开方式无效".into());
    }
    build(app, url, None)
        .map(|_| ())
        .map_err(|_| "无法打开外部网页窗口".into())
}

/// 独立网页窗口不注入学校上下文，不赋予原生命令权限。
fn build(
    app: &AppHandle,
    url: Url,
    features: Option<NewWindowFeatures>,
) -> tauri::Result<WebviewWindow> {
    let title = format!("{} - 外部网页", url.host_str().unwrap_or("网页"));
    let popup_app = app.clone();
    let mut builder = WebviewWindowBuilder::new(
        app,
        format!("external-{}", uuid::Uuid::new_v4().simple()),
        WebviewUrl::External(url),
    )
    .title(title)
    .inner_size(1180.0, 820.0)
    .min_inner_size(600.0, 420.0)
    .resizable(true)
    .center()
    .on_navigation(allowed)
    .on_page_load(|window, page| {
        let _ = window.set_title(&format!(
            "{} - 外部网页",
            page.url().host_str().unwrap_or("网页")
        ));
    })
    .on_new_window(move |url, features| {
        if !allowed(&url) {
            return NewWindowResponse::Deny;
        }
        match build(&popup_app, url, Some(features)) {
            Ok(window) => NewWindowResponse::Create { window },
            Err(_) => NewWindowResponse::Deny,
        }
    });
    if let Some(features) = features {
        builder = builder.window_features(features);
    }
    builder.build()
}

/// 原生菜单仅读取外部网页的当前地址。
pub fn menu(app: &AppHandle, window: &WebviewWindow, command: &str) {
    if !window.label().starts_with("external-") {
        return;
    }
    let Ok(url) = window.url() else {
        return;
    };
    if !allowed(&url) {
        return;
    }
    if command == "copy-address" {
        let _ = app.clipboard().write_text(url.as_str());
    } else {
        let _ = app.opener().open_url(url.as_str(), None::<&str>);
    }
}
