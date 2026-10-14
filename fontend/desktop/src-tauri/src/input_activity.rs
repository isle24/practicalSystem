use tauri::{AppHandle, Manager, WebviewWindow};

#[cfg(target_os = "macos")]
fn recent_input() -> bool {
    use objc2_core_graphics::{CGEventSource, CGEventSourceStateID, CGEventType};
    let seconds = CGEventSource::seconds_since_last_event_type(
        CGEventSourceStateID::CombinedSessionState,
        CGEventType(u32::MAX),
    );
    seconds.is_finite() && (0.0..=2.0).contains(&seconds)
}

#[cfg(target_os = "windows")]
fn recent_input() -> bool {
    use windows_sys::Win32::{
        System::SystemInformation::GetTickCount64,
        UI::Input::KeyboardAndMouse::{GetLastInputInfo, LASTINPUTINFO},
    };
    let mut input = LASTINPUTINFO {
        cbSize: std::mem::size_of::<LASTINPUTINFO>() as u32,
        dwTime: 0,
    };
    unsafe {
        GetLastInputInfo(&mut input) != 0
            && (GetTickCount64() as u32).wrapping_sub(input.dwTime) <= 2000
    }
}

pub fn poll(app: &AppHandle, owner: &str, lock: &crate::client_lock::ClientLock) {
    #[cfg(any(target_os = "macos", target_os = "windows"))]
    if !lock.is_locked() && recent_input() {
        if app.webview_windows().values().any(|window| {
            (window.label() == "school"
                || crate::windows::belongs_to_school(window.label(), owner)
                || window.label().starts_with("external-"))
                && window.is_visible().unwrap_or(false)
                && window.is_focused().unwrap_or(false)
        }) {
            lock.activity();
        }
    }
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    let _ = (app, owner, lock);
}

pub fn install(window: &WebviewWindow) {
    #[cfg(target_os = "linux")]
    {
        use gtk::prelude::*;
        let app = window.app_handle().clone();
        let label = window.label().to_owned();
        let _ = window.with_webview(move |webview| {
            webview.inner().connect_event(move |_, event| {
                use gtk::gdk::EventType;
                if matches!(
                    event.event_type(),
                    EventType::KeyPress
                        | EventType::KeyRelease
                        | EventType::ButtonPress
                        | EventType::ButtonRelease
                        | EventType::MotionNotify
                        | EventType::Scroll
                        | EventType::TouchBegin
                        | EventType::TouchUpdate
                ) {
                    if let Some(window) = app.get_webview_window(&label) {
                        if window.is_visible().unwrap_or(false)
                            && window.is_focused().unwrap_or(false)
                        {
                            let state = app.state::<crate::AppState>();
                            if let Ok(active) = state.gateway.lock() {
                                if let Some(gateway) = active.as_ref() {
                                    if label == "school"
                                        || crate::windows::belongs_to_school(&label, &gateway.owner)
                                        || label.starts_with("external-")
                                    {
                                        gateway.lock.activity();
                                    }
                                }
                            };
                        }
                    }
                }
                gtk::glib::Propagation::Proceed
            });
        });
    }
    #[cfg(not(target_os = "linux"))]
    let _ = window;
}
