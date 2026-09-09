use std::{
    collections::HashMap,
    path::PathBuf,
    sync::{
        atomic::{AtomicBool, Ordering},
        Mutex,
    },
};
use tauri::{webview::DownloadEvent, Manager, Webview};
use tauri_plugin_dialog::DialogExt;

struct PendingDownload {
    directory: tempfile::TempDir,
    path: PathBuf,
    name: String,
}

#[derive(Default)]
pub struct Downloads {
    pending: Mutex<HashMap<String, PendingDownload>>,
    document_loaded: AtomicBool,
}

impl Downloads {
    /// 标记窗口已呈现可预览的页面。
    pub fn mark_document_loaded(&self) {
        self.document_loaded.store(true, Ordering::Release);
    }

    /// 接收 WebView 下载，完成后由系统保存对话框选择位置。
    pub fn handle(&self, webview: Webview, event: DownloadEvent<'_>) -> bool {
        match event {
            DownloadEvent::Requested { url, destination } => {
                let Ok(mut pending) = self.pending.lock() else {
                    return false;
                };
                if pending.contains_key(url.as_str()) {
                    webview
                        .app_handle()
                        .dialog()
                        .message("该文件正在下载，请稍候")
                        .title("文件下载")
                        .show(|_| {});
                    return false;
                }
                let Ok(directory) = tempfile::Builder::new()
                    .prefix("practical-download-")
                    .tempdir()
                else {
                    webview
                        .app_handle()
                        .dialog()
                        .message("无法创建下载缓存，请检查磁盘可用空间")
                        .title("文件下载")
                        .show(|_| {});
                    return false;
                };
                let name = destination
                    .file_name()
                    .and_then(|name| name.to_str())
                    .unwrap_or("download");
                let name: String = name
                    .chars()
                    .filter(|c| !c.is_control())
                    .map(|c| if "<>:\"/\\|?*".contains(c) { '_' } else { c })
                    .take(180)
                    .collect();
                *destination = directory.path().join("download");
                pending.insert(
                    url.to_string(),
                    PendingDownload {
                        directory,
                        path: destination.clone(),
                        name,
                    },
                );
            }
            DownloadEvent::Finished { url, success, .. } => {
                let downloaded = self
                    .pending
                    .lock()
                    .ok()
                    .and_then(|mut pending| pending.remove(url.as_str()));
                let Some(downloaded) = downloaded else {
                    return true;
                };
                let app = webview.app_handle().clone();
                let empty_preview = (webview.label().starts_with("preview-")
                    && !self.document_loaded.load(Ordering::Acquire))
                .then(|| webview.label().to_owned());
                if !success {
                    app.dialog()
                        .message("文件下载失败，请检查网络后重试")
                        .title("文件下载")
                        .show(|_| {});
                    return true;
                }
                let mut dialog = app
                    .dialog()
                    .file()
                    .set_title("保存文件")
                    .set_file_name(&downloaded.name);
                if let Ok(directory) = app.path().download_dir() {
                    dialog = dialog.set_directory(directory);
                }
                if let Some(parent) = app.get_webview_window("school") {
                    dialog = dialog.set_parent(&parent);
                }
                dialog.save_file(move |destination| {
                    tauri::async_runtime::spawn_blocking(move || {
                        if let Some(label) = empty_preview {
                            if let Some(window) = app.get_webview_window(&label) {
                                let _ = window.destroy();
                            }
                        }
                        let _cache = downloaded.directory;
                        let Some(destination) = destination else {
                            return;
                        };
                        let result =
                            destination
                                .into_path()
                                .map_err(|_| ())
                                .and_then(|destination| {
                                    std::fs::copy(&downloaded.path, destination)
                                        .map(|_| ())
                                        .map_err(|_| ())
                                });
                        if result.is_err() {
                            app.dialog()
                                .message("保存失败，请检查目标目录权限和磁盘可用空间，然后重新下载")
                                .title("保存文件")
                                .show(|_| {});
                        }
                    });
                });
            }
            _ => {}
        }
        true
    }
}
