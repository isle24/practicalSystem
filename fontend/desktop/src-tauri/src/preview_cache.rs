use crate::{connection::Connection, school_api};
use axum::{
    body::Body,
    http::{header, HeaderMap, HeaderValue, Response, StatusCode},
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::Digest;
use std::{
    fs,
    io::Write,
    path::{Path, PathBuf},
    sync::OnceLock,
    time::SystemTime,
};
use tauri::{AppHandle, Manager};
use tokio::{
    io::{AsyncReadExt, AsyncSeekExt, AsyncWriteExt},
    sync::Mutex,
};
use tokio_util::io::ReaderStream;

const GIB: u64 = 1024 * 1024 * 1024;
static LOCK: OnceLock<Mutex<()>> = OnceLock::new();
#[derive(Serialize, Deserialize)]
struct Settings {
    max_bytes: u64,
}
impl Default for Settings {
    fn default() -> Self {
        Self { max_bytes: 8 * GIB }
    }
}

/// 定位应用自有缓存目录。
fn directory(app: &AppHandle) -> Result<PathBuf, String> {
    let root = app
        .path()
        .app_cache_dir()
        .map_err(|_| "无法定位缓存目录")?
        .join("file-previews");
    fs::create_dir_all(&root).map_err(|_| "无法创建缓存目录")?;
    for entry in fs::read_dir(&root).map_err(|_| "无法读取缓存目录")? {
        let entry = entry.map_err(|_| "无法读取缓存目录")?;
        let name = entry.file_name().to_string_lossy().into_owned();
        if name.starts_with("pending-preview-")
            && name.ends_with(".part")
            && entry.file_type().is_ok_and(|kind| kind.is_file())
        {
            fs::remove_file(entry.path()).map_err(|_| "无法清理中断的缓存下载")?;
        }
    }
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&root, fs::Permissions::from_mode(0o700))
            .map_err(|_| "无法保护缓存目录")?;
    }
    Ok(root)
}

fn settings(root: &Path) -> Result<Settings, String> {
    match fs::read(root.join("settings.json")) {
        Ok(bytes) => {
            serde_json::from_slice(&bytes).map_err(|_| "缓存设置损坏，请重新保存上限".into())
        }
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(Settings::default()),
        Err(_) => Err("无法读取缓存设置".into()),
    }
}

/// 仅枚举应用生成的缓存文件，排除其他目录和符号链接。
fn entries(root: &Path) -> Result<Vec<(PathBuf, u64, SystemTime)>, String> {
    let mut result = Vec::new();
    for entry in fs::read_dir(root).map_err(|_| "无法读取缓存目录")? {
        let entry = entry.map_err(|_| "无法读取缓存文件")?;
        if !entry.file_type().map_err(|_| "无法读取缓存属性")?.is_file() {
            continue;
        }
        let name = entry.file_name().to_string_lossy().into_owned();
        if name.len() != 68
            || !name.ends_with(".bin")
            || !name[..64].bytes().all(|c| c.is_ascii_hexdigit())
        {
            continue;
        }
        let meta = entry.metadata().map_err(|_| "无法读取缓存属性")?;
        result.push((
            entry.path(),
            meta.len(),
            meta.modified().unwrap_or(SystemTime::UNIX_EPOCH),
        ));
    }
    result.sort_by_key(|entry| entry.2);
    Ok(result)
}

/// 按最近使用时间回收超限缓存。
fn trim(root: &Path, limit: u64) -> Result<(), String> {
    let files = entries(root)?;
    let mut used: u64 = files.iter().map(|file| file.1).sum();
    for (path, size, _) in files {
        if used <= limit {
            break;
        }
        fs::remove_file(path).map_err(|_| "缓存正在使用，稍后重试清理")?;
        used -= size;
    }
    Ok(())
}

/// 读取、设置容量或清理应用缓存，不涉及服务器文件。
pub async fn configure(app: &AppHandle, input: &Value) -> Result<Value, String> {
    let _guard = LOCK.get_or_init(|| Mutex::new(())).lock().await;
    let root = directory(app)?;
    match input["action"].as_str().unwrap_or("status") {
        "save" => {
            let gib = input["max_gb"]
                .as_u64()
                .ok_or("缓存上限须为 0 至 128 GB 整数")?;
            if gib > 128 {
                return Err("缓存上限须为 0 至 128 GB 整数".into());
            }
            let mut file =
                tempfile::NamedTempFile::new_in(&root).map_err(|_| "无法保存缓存设置")?;
            file.write_all(
                serde_json::to_string(&Settings {
                    max_bytes: gib * GIB,
                })
                .map_err(|_| "设置无效")?
                .as_bytes(),
            )
            .map_err(|_| "无法保存缓存设置")?;
            file.persist(root.join("settings.json"))
                .map_err(|_| "无法保存缓存设置")?;
            trim(&root, gib * GIB)?;
        }
        "clear" => trim(&root, 0)?,
        "status" => (),
        _ => return Err("缓存操作无效".into()),
    }
    let files = entries(&root)?;
    Ok(
        json!({"max_gb":settings(&root)?.max_bytes/GIB,"used_bytes":files.iter().map(|f|f.1).sum::<u64>(),"files":files.len()}),
    )
}

/// 校验学校权限和内容摘要后返回缓存或下载文件。
pub async fn serve(
    app: &AppHandle,
    connection: &Connection,
    id: u64,
    headers: &HeaderMap,
) -> Result<Response<Body>, String> {
    if id == 0 {
        return Err("文件编号无效".into());
    }
    let account = school_api::account(connection).await?;
    let info = school_api::read(connection, &format!("api/file/info?id={id}")).await?;
    let ext = info["blob"]["ext"]
        .as_str()
        .unwrap_or("")
        .to_ascii_lowercase();
    if !matches!(
        ext.as_str(),
        "jpg"
            | "jpeg"
            | "png"
            | "webp"
            | "gif"
            | "bmp"
            | "avif"
            | "mp3"
            | "wav"
            | "ogg"
            | "m4a"
            | "mp4"
            | "webm"
            | "mov"
            | "pdf"
            | "docx"
            | "xlsx"
    ) {
        return Err("此格式不支持本机预览缓存，请下载原文件".into());
    }
    let digest = info["blob"]["sha1"]
        .as_str()
        .filter(|value| !value.is_empty())
        .or(info["blob"]["md5"].as_str())
        .unwrap_or("");
    let size = info["blob"]["size"].as_u64().ok_or("文件大小无效")?;
    let url = connection
        .origin
        .join(info["url"].as_str().ok_or("文件地址不存在")?)
        .map_err(|_| "文件地址无效")?;
    if url.origin() != connection.origin.origin() {
        return Err("外部存储文件请通过原始地址预览".into());
    }
    let guard = LOCK.get_or_init(|| Mutex::new(())).lock().await;
    let root = directory(app)?;
    let limit = settings(&root)?.max_bytes;
    let key = school_api::digest(&format!(
        "{}:{account}:{id}:{digest}:{size}",
        connection.origin
    ));
    let path = root.join(format!("{key}.bin"));
    if limit == 0
        || size > limit
        || !matches!(digest.len(), 32 | 40)
        || !digest.bytes().all(|value| value.is_ascii_hexdigit())
    {
        drop(guard);
        let mut request = connection.client.get(url);
        if let Some(range) = headers.get(header::RANGE) {
            request = request.header(header::RANGE, range);
        }
        let upstream = request.send().await.map_err(|_| "文件读取失败")?;
        if !upstream.status().is_success() {
            return Err("文件读取失败，请重新登录或联系管理员".into());
        }
        let status = upstream.status();
        let upstream_headers = upstream.headers().clone();
        let mut response = Response::new(Body::from_stream(upstream.bytes_stream()));
        *response.status_mut() = status;
        for key in [
            header::CONTENT_TYPE,
            header::CONTENT_LENGTH,
            header::CONTENT_RANGE,
            header::ACCEPT_RANGES,
        ] {
            if let Some(value) = upstream_headers.get(&key) {
                response.headers_mut().insert(key, value.clone());
            }
        }
        response
            .headers_mut()
            .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
        return Ok(response);
    }
    let hit = fs::symlink_metadata(&path)
        .is_ok_and(|meta| meta.file_type().is_file() && meta.len() == size);
    if !hit {
        trim(&root, limit.saturating_sub(size))?;
        let mut upstream = connection
            .client
            .get(url)
            .send()
            .await
            .map_err(|_| "文件下载失败")?;
        if !upstream.status().is_success() {
            return Err("文件下载失败，请检查权限".into());
        }
        let temp = tempfile::Builder::new()
            .prefix("pending-preview-")
            .suffix(".part")
            .tempfile_in(&root)
            .map_err(|_| "无法写入缓存，检查剩余磁盘空间")?;
        let mut output = tokio::fs::File::from_std(temp.reopen().map_err(|_| "无法写入缓存")?);
        let mut written = 0;
        let mut sha1 = sha1::Sha1::new();
        let mut md5 = md5::Md5::new();
        while let Some(chunk) = upstream.chunk().await.map_err(|_| "文件下载中断，请重试")?
        {
            written += chunk.len() as u64;
            sha1.update(&chunk);
            md5.update(&chunk);
            if written > size || written > limit {
                return Err("文件大小已变化，请重新打开预览".into());
            }
            output
                .write_all(&chunk)
                .await
                .map_err(|_| "缓存写入失败，检查磁盘空间")?;
        }
        if written != size {
            return Err("文件未下载完整，请重试".into());
        }
        let actual = if digest.len() == 40 {
            format!("{:x}", sha1.finalize())
        } else {
            format!("{:x}", md5.finalize())
        };
        if actual != digest.to_ascii_lowercase() {
            return Err("文件内容已变化或下载损坏，请重新打开预览".into());
        }
        output.flush().await.map_err(|_| "缓存写入失败")?;
        drop(output);
        temp.persist(&path).map_err(|_| "缓存保存失败")?;
    }
    let file = fs::File::open(&path).map_err(|_| "缓存读取失败")?;
    let _ = file.set_modified(SystemTime::now());
    let mut input = tokio::fs::File::from_std(file);
    let mut start = 0;
    let mut end = size.saturating_sub(1);
    let mut partial = false;
    if let Some(value) = headers.get(header::RANGE).and_then(|v| v.to_str().ok()) {
        let parsed = value
            .strip_prefix("bytes=")
            .and_then(|v| v.split_once('-'))
            .filter(|(_, b)| !b.contains(','))
            .and_then(|(a, b)| {
                if a.is_empty() {
                    b.parse::<u64>()
                        .ok()
                        .filter(|n| *n > 0)
                        .map(|n| (size.saturating_sub(n), end))
                } else {
                    a.parse::<u64>().ok().zip(if b.is_empty() {
                        Some(end)
                    } else {
                        b.parse::<u64>().ok().map(|n| n.min(end))
                    })
                }
            });
        match parsed {
            Some((a, b)) if a <= b && a < size => {
                start = a;
                end = b;
                partial = true;
            }
            _ => {
                let mut response = Response::new(Body::empty());
                *response.status_mut() = StatusCode::RANGE_NOT_SATISFIABLE;
                response.headers_mut().insert(
                    header::CONTENT_RANGE,
                    HeaderValue::from_str(&format!("bytes */{size}"))
                        .map_err(|_| "文件大小无效")?,
                );
                return Ok(response);
            }
        }
    }
    input
        .seek(std::io::SeekFrom::Start(start))
        .await
        .map_err(|_| "缓存读取失败")?;
    let length = if size == 0 { 0 } else { end - start + 1 };
    drop(guard);
    let mut response = Response::new(Body::from_stream(ReaderStream::new(input.take(length))));
    if partial {
        *response.status_mut() = StatusCode::PARTIAL_CONTENT;
        response.headers_mut().insert(
            header::CONTENT_RANGE,
            HeaderValue::from_str(&format!("bytes {start}-{end}/{size}"))
                .map_err(|_| "文件范围无效")?,
        );
    }
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_str(
            info["blob"]["mime_type"]
                .as_str()
                .unwrap_or("application/octet-stream"),
        )
        .unwrap_or(HeaderValue::from_static("application/octet-stream")),
    );
    response.headers_mut().insert(
        header::CONTENT_LENGTH,
        HeaderValue::from_str(&length.to_string()).map_err(|_| "文件大小无效")?,
    );
    response
        .headers_mut()
        .insert(header::ACCEPT_RANGES, HeaderValue::from_static("bytes"));
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response.headers_mut().insert(
        "x-preview-cache",
        HeaderValue::from_static(if hit { "hit" } else { "miss" }),
    );
    response.headers_mut().insert(
        "x-content-type-options",
        HeaderValue::from_static("nosniff"),
    );
    Ok(response)
}
