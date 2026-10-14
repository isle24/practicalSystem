use crate::{connection::Connection, office_engine, preview_cache, school_api};
use base64::Engine;
use quick_xml::{events::Event, Reader, XmlVersion};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::Digest;
use std::{
    collections::HashMap,
    fs,
    io::{Cursor, Read, Write},
    path::{Path, PathBuf},
    sync::{Mutex, OnceLock},
    time::{Duration, Instant},
};
use tauri::{AppHandle, Manager};
use tauri_plugin_opener::OpenerExt;
use tokio::sync::Semaphore;
use tokio_util::sync::CancellationToken;

const MAX_BYTES: usize = 30 * 1024 * 1024;
static SETTINGS_LOCK: Mutex<()> = Mutex::new(());
static CONVERSION: Semaphore = Semaphore::const_new(1);
static TASKS: OnceLock<Mutex<Registry>> = OnceLock::new();

#[derive(Default)]
struct Registry {
    tasks: HashMap<String, Task>,
    generations: HashMap<String, Scope>,
}

#[derive(Clone)]
pub struct Scope {
    pub revision: String,
    pub cancel: CancellationToken,
}
impl Scope {
    fn new() -> Self {
        Self {
            revision: uuid::Uuid::new_v4().to_string(),
            cancel: CancellationToken::new(),
        }
    }
}

pub fn scope(owner: &str) -> Result<Scope, String> {
    let mut registry = TASKS
        .get_or_init(|| Mutex::new(Registry::default()))
        .lock()
        .map_err(|_| "本机预览状态不可用")?;
    Ok(registry
        .generations
        .entry(owner.into())
        .or_insert_with(Scope::new)
        .clone())
}

pub fn scope_current(owner: &str, generation: &str) -> Result<(), String> {
    let registry = TASKS
        .get_or_init(|| Mutex::new(Registry::default()))
        .lock()
        .map_err(|_| "本机预览状态不可用")?;
    if registry
        .generations
        .get(owner)
        .is_none_or(|current| current.revision != generation || current.cancel.is_cancelled())
    {
        return Err("学校会话正在变更，本机预览已取消".into());
    }
    Ok(())
}

#[derive(Serialize, Deserialize)]
struct Settings {
    mode: String,
    engine: String,
}
impl Default for Settings {
    fn default() -> Self {
        Self {
            mode: "builtin".into(),
            engine: "auto".into(),
        }
    }
}

fn load(app: &AppHandle) -> Result<Settings, String> {
    let path = app
        .path()
        .app_config_dir()
        .map_err(|_| "无法定位本机 Office 设置")?
        .join("office-preview.json");
    let settings: Settings = match fs::read(path) {
        Ok(bytes) => {
            serde_json::from_slice(&bytes).map_err(|_| "本机 Office 设置损坏，请重新保存")?
        }
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Settings::default(),
        Err(_) => return Err("无法读取本机 Office 设置".into()),
    };
    if !matches!(settings.mode.as_str(), "builtin" | "native" | "pdf")
        || !matches!(settings.engine.as_str(), "auto" | "word" | "libreoffice")
    {
        return Err("本机 Office 设置无效，请重新保存".into());
    }
    Ok(settings)
}

/// 管理本机预览方式和引擎选择。
pub fn configure(app: &AppHandle, input: &Value) -> Result<Value, String> {
    let _guard = SETTINGS_LOCK.lock().map_err(|_| "本机 Office 设置不可用")?;
    match input["action"].as_str().unwrap_or("status") {
        "save" => {
            let mode = input["mode"].as_str().ok_or("请选择 Office 预览方式")?;
            let engine = input["engine"].as_str().ok_or("请选择本机转换引擎")?;
            if !matches!(mode, "builtin" | "native" | "pdf")
                || !matches!(engine, "auto" | "word" | "libreoffice")
            {
                return Err("本机 Office 设置无效".into());
            }
            let directory = app
                .path()
                .app_config_dir()
                .map_err(|_| "无法定位本机 Office 设置")?;
            fs::create_dir_all(&directory).map_err(|_| "无法创建本机配置目录")?;
            let mut file = tempfile::NamedTempFile::new_in(&directory)
                .map_err(|_| "无法保存本机 Office 设置")?;
            file.write_all(
                &serde_json::to_vec(&Settings {
                    mode: mode.into(),
                    engine: engine.into(),
                })
                .map_err(|_| "本机 Office 设置无效")?,
            )
            .map_err(|_| "本机 Office 设置写入失败")?;
            file.as_file()
                .sync_all()
                .map_err(|_| "本机 Office 设置写入失败")?;
            file.persist(directory.join("office-preview.json"))
                .map_err(|_| "本机 Office 设置保存失败")?;
        }
        "status" => (),
        _ => return Err("本机 Office 操作无效".into()),
    }
    let settings = load(app)?;
    Ok(
        json!({"mode": settings.mode, "engine": settings.engine, "engines": office_engine::status(),
        "native_formats": ["docx", "xlsx"], "pdf_formats": ["docx"], "max_mb": 30}),
    )
}

struct Task {
    owner: String,
    file_id: u64,
    cancel: CancellationToken,
    created: Instant,
}
struct TaskGuard(String);
struct DeadlineGuard(tokio::task::JoinHandle<()>);
impl Drop for DeadlineGuard {
    fn drop(&mut self) {
        self.0.abort();
    }
}
impl Drop for TaskGuard {
    fn drop(&mut self) {
        if let Ok(mut registry) = TASKS.get_or_init(|| Mutex::new(Registry::default())).lock() {
            registry.tasks.remove(&self.0);
        }
    }
}

fn request_id(input: &Value) -> Result<String, String> {
    let id = input["request_id"].as_str().ok_or("本机预览请求编号无效")?;
    uuid::Uuid::parse_str(id).map_err(|_| "本机预览请求编号无效")?;
    Ok(id.to_owned())
}

/// 仅取消同一学校连接内已登记的本机操作。
pub fn cancel(owner: &str, input: &Value) -> Result<Value, String> {
    let request_id = request_id(input)?;
    let mut registry = TASKS
        .get_or_init(|| Mutex::new(Registry::default()))
        .lock()
        .map_err(|_| "本机预览状态不可用")?;
    let tasks = &mut registry.tasks;
    tasks.retain(|_, task| task.created.elapsed() < Duration::from_secs(300));
    if let Some(task) = tasks.get(&request_id) {
        if task.owner != owner || task.file_id != input["file_id"].as_u64().unwrap_or(0) {
            return Err("本机预览请求不属于当前连接".into());
        }
        task.cancel.cancel();
    } else {
        if tasks.len() >= 128 {
            return Err("本机预览取消请求过多，请稍后重试".into());
        }
        let cancel = CancellationToken::new();
        cancel.cancel();
        tasks.insert(
            request_id,
            Task {
                owner: owner.into(),
                file_id: input["file_id"].as_u64().unwrap_or(0),
                cancel,
                created: Instant::now(),
            },
        );
    }
    Ok(json!({"cancelled": true}))
}

pub fn cancel_owner(owner: &str) {
    if let Ok(mut registry) = TASKS.get_or_init(|| Mutex::new(Registry::default())).lock() {
        if let Some(scope) = registry.generations.get(owner) {
            scope.cancel.cancel();
        }
        registry.generations.insert(owner.into(), Scope::new());
        for task in registry.tasks.values().filter(|task| task.owner == owner) {
            task.cancel.cancel();
        }
    }
}

struct Source {
    id: u64,
    ext: String,
    size: u64,
    digest: String,
    key: String,
}

async fn source_info(connection: &Connection, id: u64) -> Result<Source, String> {
    if id == 0 {
        return Err("文件编号无效".into());
    }
    let account = school_api::account(connection).await?;
    let info = school_api::read(connection, &format!("api/file/info?id={id}")).await?;
    let size = info["blob"]["size"]
        .as_u64()
        .filter(|size| *size > 0 && *size <= MAX_BYTES as u64)
        .ok_or("本机 Office 预览仅支持不超过 30 MB 的文档")?;
    let ext = info["blob"]["ext"]
        .as_str()
        .unwrap_or("")
        .to_ascii_lowercase();
    if !matches!(ext.as_str(), "docx" | "xlsx") {
        return Err("本机 Office 打开支持 DOCX/XLSX，转 PDF 支持 DOCX；其他格式请下载查看".into());
    }
    let digest = info["blob"]["sha1"]
        .as_str()
        .filter(|value| !value.is_empty())
        .or_else(|| info["blob"]["md5"].as_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    if !matches!(digest.len(), 32 | 40) || !digest.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err("文件缺少有效内容校验值，请联系管理员".into());
    }
    let url = connection
        .origin
        .join(info["url"].as_str().ok_or("文件地址不存在")?)
        .map_err(|_| "文件地址无效")?;
    if url.origin() != connection.origin.origin() {
        return Err("外部存储文档请使用原始预览或下载查看".into());
    }
    let key = school_api::digest(&format!(
        "{}:{account}:{id}:{ext}:{digest}:{size}",
        connection.origin
    ));
    Ok(Source {
        id,
        ext,
        size,
        digest,
        key,
    })
}

async fn scoped_source_info(
    connection: &Connection,
    id: u64,
    cancel: &CancellationToken,
) -> Result<Source, String> {
    tokio::select! {
        _ = cancel.cancelled() => Err("本机预览已取消".into()),
        result = tokio::time::timeout(Duration::from_secs(30), source_info(connection, id)) => result.map_err(|_| "学校文件权限读取超时")?,
    }
}

async fn source_bytes(
    app: &AppHandle,
    connection: &Connection,
    source: &Source,
    cancel: &CancellationToken,
) -> Result<Vec<u8>, String> {
    let read = async {
        let response = preview_cache::serve(
            app,
            connection,
            source.id,
            &axum::http::HeaderMap::new(),
            cancel.clone(),
        )
        .await?;
        axum::body::to_bytes(response.into_body(), MAX_BYTES)
            .await
            .map(|bytes| bytes.to_vec())
            .map_err(|_| "文件读取失败或超过 30 MB".to_owned())
    };
    let bytes = tokio::select! {
        _ = cancel.cancelled() => return Err("本机预览已取消".into()),
        result = tokio::time::timeout(Duration::from_secs(60), read) => result.map_err(|_| "文档下载超时，请重新打开")??,
    };
    let actual = if source.digest.len() == 40 {
        format!("{:x}", sha1::Sha1::digest(&bytes))
    } else {
        format!("{:x}", md5::Md5::digest(&bytes))
    };
    if bytes.len() as u64 != source.size || actual != source.digest {
        return Err("文件已变化或下载损坏，请重新打开".into());
    }
    Ok(bytes)
}

fn work_root(app: &AppHandle) -> Result<PathBuf, String> {
    let root = app
        .path()
        .app_cache_dir()
        .map_err(|_| "无法定位本机文档目录")?
        .join("office-work");
    fs::create_dir_all(&root).map_err(|_| "无法创建本机文档目录")?;
    if !fs::symlink_metadata(&root).is_ok_and(|metadata| metadata.file_type().is_dir()) {
        return Err("本机文档目录无效".into());
    }
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&root, fs::Permissions::from_mode(0o700))
            .map_err(|_| "无法保护本机文档目录")?;
    }
    for entry in fs::read_dir(&root).map_err(|_| "无法读取本机文档目录")? {
        let entry = entry.map_err(|_| "无法读取本机文档目录")?;
        let name = entry.file_name().to_string_lossy().into_owned();
        if name.starts_with("pending-office-")
            && entry.file_type().is_ok_and(|kind| kind.is_dir())
            && entry
                .metadata()
                .ok()
                .and_then(|metadata| metadata.modified().ok())
                .and_then(|time| time.elapsed().ok())
                .is_some_and(|age| age.as_secs() > 86400)
        {
            let _ = fs::remove_dir_all(entry.path());
        }
    }
    Ok(root)
}

fn check_field(value: &str) -> Result<(), String> {
    let uppercase = value.to_ascii_uppercase();
    let command = uppercase.split_whitespace().next().unwrap_or("");
    if !matches!(
        command,
        "PAGE"
            | "NUMPAGES"
            | "SECTION"
            | "SECTIONPAGES"
            | "TOC"
            | "REF"
            | "PAGEREF"
            | "STYLEREF"
            | "HYPERLINK"
            | "DATE"
            | "TIME"
            | "CREATEDATE"
            | "SAVEDATE"
            | "PRINTDATE"
            | "AUTHOR"
            | "TITLE"
            | "SUBJECT"
            | "KEYWORDS"
            | "COMMENTS"
            | "FILENAME"
            | "FILESIZE"
            | "NUMWORDS"
            | "NUMCHARS"
            | "DOCPROPERTY"
            | "SEQ"
            | "LISTNUM"
            | "EQ"
            | "SYMBOL"
            | "IF"
            | "="
            | "FORMTEXT"
            | "FORMCHECKBOX"
            | "FORMDROPDOWN"
            | "MERGEFIELD"
            | "MERGEREC"
            | "MERGESEQ"
            | "QUOTE"
    ) || uppercase.split_whitespace().any(|word| {
        matches!(
            word,
            "DDE"
                | "DDEAUTO"
                | "LINK"
                | "INCLUDETEXT"
                | "INCLUDEPICTURE"
                | "DATABASE"
                | "MACROBUTTON"
                | "PRINT"
        )
    }) {
        return Err("DOCX 含未支持的动态字段，不能自动转换；请手动使用本机 Office 打开".into());
    }
    Ok(())
}

fn check_xml(bytes: &[u8], relationships: bool, content_types: bool) -> Result<(), String> {
    let text = std::str::from_utf8(bytes)
        .map_err(|_| "DOCX XML 编码不支持，请手动使用本机 Office 打开")?;
    if text.contains('\0') {
        return Err("DOCX XML 编码无效，请手动使用本机 Office 打开".into());
    }
    let mut reader = Reader::from_str(text.trim_start_matches('\u{feff}'));
    let mut instruction = false;
    let mut fields: Vec<String> = Vec::new();
    let mut elements: Vec<String> = Vec::new();
    let mut roots = 0usize;
    let mut declaration_seen = false;
    loop {
        let event = reader
            .read_event()
            .map_err(|_| "DOCX 容器格式损坏，请手动使用本机 Office 打开")?;
        let empty = matches!(&event, Event::Empty(_));
        match event {
            Event::Decl(declaration) => {
                if declaration_seen || roots > 0 {
                    return Err("DOCX XML 声明位置无效".into());
                }
                declaration_seen = true;
                if let Some(encoding) = declaration.encoding() {
                    let encoding = encoding.map_err(|_| "DOCX XML 编码声明无效")?;
                    if !encoding.eq_ignore_ascii_case("utf-8")
                        && !encoding.eq_ignore_ascii_case("us-ascii")
                    {
                        return Err("DOCX XML 编码不支持，请手动使用本机 Office 打开".into());
                    }
                }
            }
            Event::DocType(_) => return Err("DOCX 包含外部声明，请手动使用本机 Office 打开".into()),
            Event::Start(element) | Event::Empty(element) => {
                if elements.is_empty() {
                    roots += 1;
                    if roots != 1 {
                        return Err("DOCX XML 根结构无效".into());
                    }
                }
                if !empty {
                    elements.push(element.name().as_ref().to_owned());
                }
                if relationships && element.local_name().as_ref() == "Relationship" {
                    let mut relationship = String::new();
                    let mut external = false;
                    let mut target = String::new();
                    for attribute in element.attributes() {
                        let attribute = attribute.map_err(|_| "DOCX 关系格式损坏")?;
                        let value = attribute
                            .normalized_value(XmlVersion::Implicit1_0)
                            .map_err(|_| "DOCX 关系格式损坏")?
                            .into_owned();
                        match attribute.key.as_ref() {
                            "Type" => relationship = value,
                            "TargetMode" => external = value.eq_ignore_ascii_case("external"),
                            "Target" => target = value,
                            _ => (),
                        }
                    }
                    let kind = relationship
                        .rsplit('/')
                        .next()
                        .unwrap_or("")
                        .to_ascii_lowercase();
                    if matches!(
                        kind.as_str(),
                        "oleobject"
                            | "package"
                            | "attachedtemplate"
                            | "afchunk"
                            | "vbaproject"
                            | "control"
                            | "externallink"
                    ) || external && kind != "hyperlink"
                        || kind != "hyperlink"
                            && (target.contains(':')
                                || target.starts_with('/')
                                || target.contains('\\')
                                || target.split('/').any(|segment| segment == ".."))
                    {
                        return Err("DOCX 含宏、嵌入对象或外部内容，不能自动转换；请手动使用本机 Office 打开".into());
                    }
                }
                if content_types {
                    for attribute in element.attributes() {
                        let attribute = attribute.map_err(|_| "DOCX 内容类型损坏")?;
                        if attribute.key.as_ref() == "ContentType" {
                            let value = attribute
                                .normalized_value(XmlVersion::Implicit1_0)
                                .map_err(|_| "DOCX 内容类型损坏")?
                                .to_ascii_lowercase();
                            if value.contains("macroenabled")
                                || value.contains("vbaproject")
                                || value.contains("oleobject")
                                || value.contains("activex")
                            {
                                return Err(
                                    "DOCX 含主动内容，不能自动转换；请手动使用本机 Office 打开"
                                        .into(),
                                );
                            }
                        }
                    }
                }
                if element.local_name().as_ref() == "instrText" {
                    if fields.is_empty() || instruction {
                        return Err("DOCX 字段结构无效，请手动使用本机 Office 打开".into());
                    }
                    instruction = !empty;
                }
                if element.local_name().as_ref() == "fldChar" {
                    let mut kind = String::new();
                    for attribute in element.attributes() {
                        let attribute = attribute.map_err(|_| "DOCX 字段格式损坏")?;
                        if attribute.key.local_name().as_ref() == "fldCharType" {
                            kind = attribute
                                .normalized_value(XmlVersion::Implicit1_0)
                                .map_err(|_| "DOCX 字段格式损坏")?
                                .into_owned();
                        }
                    }
                    match kind.as_str() {
                        "begin" if !instruction => fields.push(String::new()),
                        "separate" if !instruction => {
                            check_field(fields.last().ok_or("DOCX 字段结构无效")?)?
                        }
                        "end" if !instruction => {
                            check_field(&fields.pop().ok_or("DOCX 字段结构无效")?)?
                        }
                        _ => return Err("DOCX 字段结构无效，请手动使用本机 Office 打开".into()),
                    }
                }
                if element.local_name().as_ref() == "fldSimple" {
                    for attribute in element.attributes() {
                        let attribute = attribute.map_err(|_| "DOCX 字段格式损坏")?;
                        if attribute.key.local_name().as_ref() == "instr" {
                            check_field(
                                &attribute
                                    .normalized_value(XmlVersion::Implicit1_0)
                                    .map_err(|_| "DOCX 字段格式损坏")?,
                            )?;
                        }
                    }
                }
            }
            Event::Text(text) if instruction => fields
                .last_mut()
                .ok_or("DOCX 字段结构无效")?
                .push_str(&text.xml10_content()),
            Event::CData(text) if instruction => fields
                .last_mut()
                .ok_or("DOCX 字段结构无效")?
                .push_str(&text.xml10_content()),
            Event::GeneralRef(reference) if instruction => {
                let value = reference
                    .resolve_char_ref()
                    .map_err(|_| "DOCX 字段格式损坏")?
                    .or_else(|| match reference.xml10_content().as_ref() {
                        "quot" => Some('"'),
                        "apos" => Some('\''),
                        "amp" => Some('&'),
                        "lt" => Some('<'),
                        "gt" => Some('>'),
                        _ => None,
                    })
                    .ok_or("DOCX 字段包含未支持的实体，请手动使用本机 Office 打开")?;
                fields.last_mut().ok_or("DOCX 字段结构无效")?.push(value);
            }
            Event::End(element) => {
                if element.local_name().as_ref() == "instrText" {
                    instruction = false;
                }
                if elements.pop().as_deref() != Some(element.name().as_ref()) {
                    return Err("DOCX XML 元素未正确闭合".into());
                }
            }
            Event::Eof => {
                if !fields.is_empty() || instruction || !elements.is_empty() || roots != 1 {
                    return Err("DOCX 字段未完整闭合，请手动使用本机 Office 打开".into());
                }
                return Ok(());
            }
            Event::Text(text) if elements.is_empty() && !text.xml10_content().trim().is_empty() => {
                return Err("DOCX XML 根外存在内容".into())
            }
            Event::CData(_) | Event::GeneralRef(_) if elements.is_empty() => {
                return Err("DOCX XML 根外存在内容".into())
            }
            _ => (),
        }
    }
}

/// 校验普通 DOCX 容器，拒绝自动执行主动内容。
fn validate_docx(bytes: &[u8]) -> Result<(), String> {
    let mut archive = zip::ZipArchive::new(Cursor::new(bytes))
        .map_err(|_| "文档不是普通 DOCX 或已加密，请手动使用本机 Office 打开")?;
    if archive.len() > 10000 {
        return Err("DOCX 容器条目过多，请下载查看".into());
    }
    let mut total = 0u64;
    let mut document = false;
    let mut content_types = false;
    let mut names = std::collections::HashSet::new();
    for index in 0..archive.len() {
        let mut file = archive
            .by_index(index)
            .map_err(|_| "DOCX 容器损坏或已加密")?;
        let name = file.name().to_ascii_lowercase();
        total = total.saturating_add(file.size());
        if total > 100 * 1024 * 1024 || file.size() > 32 * 1024 * 1024 {
            return Err("DOCX 解压体积过大，请下载查看".into());
        }
        if !names.insert(name.clone()) || file.enclosed_name().is_none() || name.contains('\\') {
            return Err("DOCX 容器路径无效".into());
        }
        if name.contains("vbaproject")
            || name.contains("/embeddings/")
            || name.contains("/activex/")
            || name.starts_with("customui/")
        {
            return Err("DOCX 含宏或嵌入对象，不能自动转换；请手动使用本机 Office 打开".into());
        }
        if name == "word/document.xml" {
            document = true;
        }
        if name == "[content_types].xml" {
            content_types = true;
        }
        if name.ends_with(".xml") || name.ends_with(".rels") {
            let mut xml = Vec::new();
            let declared_size = file.size();
            file.by_ref()
                .take(32 * 1024 * 1024 + 1)
                .read_to_end(&mut xml)
                .map_err(|_| "DOCX 容器读取失败")?;
            if xml.len() as u64 != declared_size || xml.len() > 32 * 1024 * 1024 {
                return Err("DOCX 容器解压体积无效".into());
            }
            check_xml(&xml, name.ends_with(".rels"), name == "[content_types].xml")?;
        }
    }
    if !document || !content_types {
        return Err("文档不是普通 DOCX，请手动使用本机 Office 打开".into());
    }
    Ok(())
}

fn pdf_bytes(path: &Path) -> Result<Vec<u8>, String> {
    if !fs::symlink_metadata(path).is_ok_and(|metadata| {
        metadata.file_type().is_file() && metadata.len() > 8 && metadata.len() <= MAX_BYTES as u64
    }) {
        return Err("转换未生成有效 PDF，或 PDF 超过 30 MB，请使用本机 Office 打开".into());
    }
    let bytes = fs::read(path).map_err(|_| "无法读取转换结果")?;
    if !bytes.starts_with(b"%PDF-")
        || !bytes
            .windows(5)
            .rev()
            .take(2048)
            .any(|value| value == b"%%EOF")
    {
        return Err("转换结果不是完整 PDF，请使用本机 Office 打开".into());
    }
    Ok(bytes)
}

fn active(app: &AppHandle, owner: &str, cancel: &CancellationToken) -> Result<(), String> {
    if cancel.is_cancelled()
        || crate::current_school_locked(app)
        || !crate::school_owner_current(app, owner)
    {
        return Err("客户端已锁定、账号已变更或预览已取消".into());
    }
    Ok(())
}

/// 按服务器文件编号执行本机打开或转换。
pub async fn perform(
    app: &AppHandle,
    connection: &Connection,
    owner: &str,
    generation: &str,
    input: &Value,
    authorization: &CancellationToken,
) -> Result<Value, String> {
    let id = input["file_id"]
        .as_u64()
        .filter(|id| *id > 0)
        .ok_or("文件编号无效")?;
    let request_id = request_id(input)?;
    let action = input["action"].as_str().unwrap_or("");
    if !matches!(action, "open" | "convert") {
        return Err("本机 Office 操作无效".into());
    }
    active(app, owner, authorization)?;
    let cancel = authorization.child_token();
    {
        let mut registry = TASKS
            .get_or_init(|| Mutex::new(Registry::default()))
            .lock()
            .map_err(|_| "本机预览状态不可用")?;
        if registry
            .generations
            .get(owner)
            .is_none_or(|current| current.revision != generation || current.cancel.is_cancelled())
        {
            return Err("学校会话正在变更，本机预览已取消".into());
        }
        let tasks = &mut registry.tasks;
        tasks.retain(|_, task| task.created.elapsed() < Duration::from_secs(300));
        if tasks.contains_key(&request_id) {
            return Err("该本机预览请求已进行或已取消".into());
        }
        tasks.insert(
            request_id.clone(),
            Task {
                owner: owner.into(),
                file_id: id,
                cancel: cancel.clone(),
                created: Instant::now(),
            },
        );
    }
    let _task = TaskGuard(request_id);
    let deadline_cancel = cancel.clone();
    let _deadline = DeadlineGuard(tokio::spawn(async move {
        tokio::time::sleep(Duration::from_secs(210)).await;
        deadline_cancel.cancel();
    }));
    let _permit = if action == "convert" {
        Some(
            CONVERSION
                .try_acquire()
                .map_err(|_| "本机正在转换其他文档，请稍候再打开")?,
        )
    } else {
        None
    };
    let source = scoped_source_info(connection, id, &cancel).await?;
    active(app, owner, &cancel)?;
    if action == "open" {
        let bytes = source_bytes(app, connection, &source, &cancel).await?;
        let directory = tempfile::Builder::new()
            .prefix("pending-office-")
            .tempdir_in(work_root(app)?)
            .map_err(|_| "无法准备本机文档副本")?;
        let path = directory.path().join(format!("preview.{}", source.ext));
        fs::write(&path, &bytes).map_err(|_| "本机文档副本写入失败")?;
        let mut permissions = fs::metadata(&path)
            .map_err(|_| "本机文档副本无效")?
            .permissions();
        permissions.set_readonly(true);
        fs::set_permissions(&path, permissions).map_err(|_| "无法设置文档只读属性")?;
        if scoped_source_info(connection, id, &cancel).await?.key != source.key {
            return Err("文件已变化，请重新打开".into());
        }
        active(app, owner, &cancel)?;
        app.opener()
            .open_path(path.to_string_lossy(), None::<&str>)
            .map_err(|_| "无法使用本机 Office 打开，请检查文档关联程序或下载后手动打开")?;
        let _ = directory.keep();
        return Ok(json!({"opened": true}));
    }
    if source.ext != "docx" {
        return Err("本机转 PDF 仅支持普通 DOCX，其他格式请使用本机 Office 打开".into());
    }
    let settings = load(app)?;
    let engine = office_engine::select(&settings.engine)?;
    let key = school_api::digest(&format!(
        "office-pdf-v1:{}:{}",
        source.key,
        engine.fingerprint()?
    ));
    let mut cached = true;
    let cached_pdf = tokio::select! {
        _ = cancel.cancelled() => return Err("本机预览已取消".into()),
        result = tokio::time::timeout(Duration::from_secs(15), preview_cache::office_read(app, &key)) => result.map_err(|_| "本机缓存读取超时，请稍后重新打开")??,
    };
    let bytes = match cached_pdf {
        Some(bytes) => bytes,
        None => {
            cached = false;
            let bytes = source_bytes(app, connection, &source, &cancel).await?;
            validate_docx(&bytes)?;
            active(app, owner, &cancel)?;
            let directory = tempfile::Builder::new()
                .prefix("pending-office-")
                .tempdir_in(work_root(app)?)
                .map_err(|_| "无法创建本机转换目录")?;
            fs::write(directory.path().join("source.docx"), bytes)
                .map_err(|_| "本机文档写入失败，请检查磁盘空间")?;
            if let Err(failure) = office_engine::convert(&engine, directory.path(), &cancel).await {
                if let Some(mut worker) = failure.pending_worker {
                    let path = directory.keep();
                    let retained_permit = _permit;
                    tauri::async_runtime::spawn(async move {
                        let _permit = retained_permit;
                        let _ = worker.wait().await;
                        let _ = fs::remove_dir_all(path);
                    });
                }
                return Err(failure.message);
            }
            active(app, owner, &cancel)?;
            pdf_bytes(&directory.path().join("source.pdf"))?
        }
    };
    if scoped_source_info(connection, id, &cancel).await?.key != source.key {
        return Err("文件已变化，请重新打开".into());
    }
    active(app, owner, &cancel)?;
    if !cached {
        tokio::select! {
            _ = cancel.cancelled() => return Err("本机预览已取消".into()),
            result = tokio::time::timeout(Duration::from_secs(15), preview_cache::office_store(app, &key, &bytes, &cancel)) => result.map_err(|_| "本机缓存保存超时，请稍后重新打开")??,
        };
    }
    active(app, owner, &cancel)?;
    Ok(
        json!({"pdf_base64": base64::engine::general_purpose::STANDARD.encode(bytes), "engine": engine.name, "cached": cached}),
    )
}
