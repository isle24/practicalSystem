use aes_gcm::aead::rand_core::RngCore;
use aes_gcm::{
    aead::{Aead, KeyInit, OsRng, Payload},
    Aes256Gcm, Nonce,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use fs2::FileExt;
use serde_json::{json, Value};
use std::{
    collections::HashSet,
    fs,
    io::{Read, Write},
    path::{Path, PathBuf},
};
use tauri::{AppHandle, Manager};
use zeroize::Zeroizing;

const SERVICE: &str = "com.2iwm.practical.desktop.agent.memory";
const WORKSPACE_KEY: &str = "local-workspace-v1";
const IMPORTED_SCOPES: &str = "__imported_scope_keys";
const SECTIONS: [&str; 6] = [
    "conversations",
    "memories",
    "plugins",
    "tasks",
    "reminders",
    "events",
];

pub fn acquire(app: &AppHandle, scope: &Value) -> Result<fs::File, String> {
    let key = scope_key(scope)?;
    let scoped = scope.get("storage").is_some();
    let path = file(app, if scoped { &key } else { WORKSPACE_KEY })?.with_extension("lock");
    checked_directory(&path)?;
    fs::create_dir_all(path.parent().ok_or("本机记忆目录无效")?)
        .map_err(|_| "无法创建本机记忆目录")?;
    let _ = checked_file(&path)?;
    let file = fs::OpenOptions::new()
        .read(true)
        .write(true)
        .create(true)
        .truncate(false)
        .open(path)
        .map_err(|_| "无法锁定本机记忆")?;
    file.try_lock_exclusive().map_err(|_| {
        if scoped {
            "另一个客户端正在使用此账号的 Agent 记忆，请结束后重试"
        } else {
            "另一个客户端正在使用本机 Agent 工作区，请结束后重试"
        }
    })?;
    Ok(file)
}

pub fn scope_key(scope: &Value) -> Result<String, String> {
    if scope["account_id"].as_u64().unwrap_or(0) == 0
        || scope["school_id"].as_u64().unwrap_or(0) == 0
        || scope["origin"].as_str().unwrap_or("").is_empty()
    {
        return Err("Agent 学校账号范围无效".into());
    }
    Ok(crate::school_api::digest(&scope.to_string()))
}

fn file(app: &AppHandle, key: &str) -> Result<PathBuf, String> {
    Ok(app
        .path()
        .app_data_dir()
        .map_err(|_| "无法定位本机记忆目录")?
        .join("agent")
        .join(format!("{key}.bin")))
}

fn checked_directory(path: &Path) -> Result<(), String> {
    let parent = path.parent().ok_or("本机记忆目录无效")?;
    match fs::symlink_metadata(parent) {
        Ok(metadata) if metadata.is_dir() && !metadata.file_type().is_symlink() => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        _ => Err("本机记忆目录必须是普通目录".into()),
    }
}

fn checked_file(path: &Path) -> Result<Option<fs::Metadata>, String> {
    checked_directory(path)?;
    match fs::symlink_metadata(path) {
        Ok(metadata) if metadata.is_file() && !metadata.file_type().is_symlink() => {
            Ok(Some(metadata))
        }
        Ok(_) => Err("本机记忆必须是普通文件".into()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err("无法读取本机加密记忆".into()),
    }
}

fn secret(key: &str, create: bool) -> Result<Zeroizing<Vec<u8>>, String> {
    let entry =
        keyring::Entry::new(SERVICE, key).map_err(|_| "系统凭据库不可用，无法加密本机记忆")?;
    let value = match entry.get_password() {
        Ok(value) => Zeroizing::new(value),
        Err(keyring::Error::NoEntry) if create => {
            let mut bytes = Zeroizing::new([0u8; 32]);
            OsRng.fill_bytes(bytes.as_mut());
            let encoded = Zeroizing::new(STANDARD.encode(bytes.as_ref()));
            entry
                .set_password(&encoded)
                .map_err(|_| "系统凭据库无法保存 Agent 加密密钥")?;
            encoded
        }
        Err(_) => return Err("本机记忆密钥不可用，请解锁系统凭据库；未启用明文降级".into()),
    };
    let bytes = Zeroizing::new(
        STANDARD
            .decode(value.as_bytes())
            .map_err(|_| "本机记忆密钥格式无效")?,
    );
    if bytes.len() != 32 {
        return Err("本机记忆密钥无效".into());
    }
    Ok(bytes)
}

fn read_encrypted(path: &Path, key: &str, scope: &Value) -> Result<Option<Value>, String> {
    let Some(metadata) = checked_file(path)? else {
        return Ok(None);
    };
    if metadata.len() > 4 * 1024 * 1024 {
        return Err("本机记忆超过容量限制，请清除后重新开始".into());
    }
    let mut data = Vec::new();
    fs::File::open(path)
        .map_err(|_| "无法读取本机加密记忆")?
        .take(4 * 1024 * 1024 + 1)
        .read_to_end(&mut data)
        .map_err(|_| "无法读取本机加密记忆")?;
    if data.len() > 4 * 1024 * 1024 {
        return Err("本机记忆超过容量限制，请清除后重新开始".into());
    }
    if data.len() < 29 || data[0] != 1 {
        return Err("本机记忆格式无效".into());
    }
    let key_bytes = secret(key, false)?;
    let cipher = Aes256Gcm::new_from_slice(&key_bytes).map_err(|_| "本机记忆密钥无效")?;
    let clear = Zeroizing::new(
        cipher
            .decrypt(
                Nonce::from_slice(&data[1..13]),
                Payload {
                    msg: &data[13..],
                    aad: key.as_bytes(),
                },
            )
            .map_err(|_| "本机记忆解密失败，请检查系统凭据库")?,
    );
    let value: Value = serde_json::from_slice(&clear).map_err(|_| "本机记忆内容无效")?;
    if !value.is_object() || value["scope"] != *scope {
        return Err("本机记忆不属于当前学校账号".into());
    }
    Ok(Some(value))
}

fn read_account(app: &AppHandle, scope: &Value, key: &str) -> Result<Option<Value>, String> {
    let legacy = file(app, key)?;
    match read_encrypted(&legacy.with_extension("workspace.bin"), key, scope)? {
        Some(value) => Ok(Some(value)),
        None => read_encrypted(&legacy, key, scope),
    }
}

fn local_scope() -> Value {
    json!({"storage":"local-workspace:v1"})
}

fn workspace_content(value: &Value, legacy: bool) -> Result<(), String> {
    if !value.is_object() {
        return Err("本机工作区内容无效".into());
    }
    if legacy && value.get("version").is_none() {
        if !value["messages"].is_array() || !value["history"].is_array() {
            return Err("旧版本机会话内容无效".into());
        }
        return Ok(());
    }
    if value["version"] != 2 {
        return Err("本机工作区版本无效".into());
    }
    for section in SECTIONS {
        let items = value[section].as_array().ok_or("本机工作区分区无效")?;
        let mut ids = HashSet::new();
        for item in items {
            let id = item["id"].as_str().ok_or("本机工作区项目 ID 无效")?;
            if !item.is_object()
                || (uuid::Uuid::parse_str(id).is_err()
                    && !(section == "plugins" && id == "builtin-practical-system"))
                || !ids.insert(id)
            {
                return Err("本机工作区项目 ID 无效或重复".into());
            }
            if section == "conversations"
                && (!item["messages"].is_array() || !item["history"].is_array())
            {
                return Err("本机会话内容无效".into());
            }
        }
    }
    if let Some(sources) = value.get(IMPORTED_SCOPES) {
        let mut keys = HashSet::new();
        for key in sources.as_array().ok_or("本机工作区迁移记录无效")? {
            let key = key.as_str().ok_or("本机工作区迁移记录无效")?;
            if key.len() != 64
                || !key.bytes().all(|byte| byte.is_ascii_hexdigit())
                || !keys.insert(key)
            {
                return Err("本机工作区迁移记录无效".into());
            }
        }
    }
    Ok(())
}

fn workspace_envelope(value: &Value) -> Value {
    let mut envelope = json!({"version":2,"scope":local_scope()});
    for field in SECTIONS
        .into_iter()
        .chain(["active_conversation_id", IMPORTED_SCOPES])
    {
        if let Some(value) = value.get(field) {
            envelope[field] = value.clone();
        }
    }
    envelope
}

fn import_account(
    app: &AppHandle,
    scope: &Value,
    key: &str,
    shared: &Value,
) -> Result<Value, String> {
    let mut candidate = shared.clone();
    if let Some(legacy) = read_account(app, scope, key)? {
        workspace_content(&legacy, true)?;
        let legacy = crate::agent_workspace::normalize(scope, legacy);
        let has_active = candidate["conversations"]
            .as_array()
            .unwrap()
            .iter()
            .any(|item| item["id"] == candidate["active_conversation_id"]);
        for section in SECTIONS {
            let items = candidate[section].as_array_mut().unwrap();
            let mut ids = items
                .iter()
                .map(|item| item["id"].as_str().unwrap().to_owned())
                .collect::<HashSet<_>>();
            for item in legacy[section].as_array().unwrap() {
                if ids.insert(item["id"].as_str().unwrap().to_owned()) {
                    items.push(item.clone());
                }
            }
        }
        candidate["events"]
            .as_array_mut()
            .unwrap()
            .sort_by_key(|item| item["created_at"].as_i64().unwrap_or(0));
        if !has_active {
            candidate["active_conversation_id"] = legacy["active_conversation_id"].clone();
        }
    }
    candidate[IMPORTED_SCOPES]
        .as_array_mut()
        .ok_or("本机工作区迁移记录无效")?
        .push(json!(key));
    let bytes =
        serde_json::to_vec(&workspace_envelope(&candidate)).map_err(|_| "本机工作区内容无效")?;
    if bytes.len() > 3 * 1024 * 1024 {
        return Err("合并旧版本机数据后超过 3 MiB，请先释放本机工作区容量".into());
    }
    Ok(candidate)
}

pub fn read_locked(app: &AppHandle, scope: &Value) -> Result<Value, String> {
    let key = scope_key(scope)?;
    if scope.get("storage").is_some() {
        return Ok(read_account(app, scope, &key)?
            .unwrap_or_else(|| json!({"scope":scope,"messages":[],"history":[]})));
    }
    let path = file(app, WORKSPACE_KEY)?.with_extension("workspace.bin");
    let stored = read_encrypted(&path, WORKSPACE_KEY, &local_scope())?;
    let mut dirty = stored.is_none();
    let mut value = stored.unwrap_or_else(|| {
        let mut value = json!({"version":2,"scope":local_scope()});
        for section in SECTIONS {
            value[section] = json!([]);
        }
        value
    });
    workspace_content(&value, false)?;
    value["scope"] = scope.clone();
    if value.get(IMPORTED_SCOPES).is_none() {
        value[IMPORTED_SCOPES] = json!([]);
        dirty = true;
    }
    if !value[IMPORTED_SCOPES]
        .as_array()
        .unwrap()
        .iter()
        .any(|source| source.as_str() == Some(key.as_str()))
    {
        match import_account(app, scope, &key, &value) {
            Ok(imported) => {
                value = imported;
                dirty = true;
            }
            Err(message) => {
                value["migration_notice"] = json!(format!(
                    "当前账号旧版本机数据尚未导入：{message}。原加密文件和凭据条目未作修改，本机工作区仍可使用，后续访问将重试。"
                ));
            }
        }
    }
    if dirty {
        value["__storage_dirty"] = json!(true);
    }
    Ok(value)
}

fn write_encrypted(path: &Path, key: &str, value: &Value, exists: bool) -> Result<(), String> {
    let key_bytes = secret(key, !exists)?;
    let clear = Zeroizing::new(serde_json::to_vec(value).map_err(|_| "无法保存本机记忆")?);
    if clear.len() > 3 * 1024 * 1024 {
        return Err("本机工作区已满，请删除不需要的会话或插件资源".into());
    }
    let cipher = Aes256Gcm::new_from_slice(&key_bytes).map_err(|_| "本机记忆密钥无效")?;
    let mut nonce = [0u8; 12];
    OsRng.fill_bytes(&mut nonce);
    let encrypted = cipher
        .encrypt(
            Nonce::from_slice(&nonce),
            Payload {
                msg: &clear,
                aad: key.as_bytes(),
            },
        )
        .map_err(|_| "本机记忆加密失败")?;
    let directory = path.parent().ok_or("本机记忆目录无效")?;
    fs::create_dir_all(directory).map_err(|_| "无法创建本机记忆目录")?;
    let mut temporary =
        tempfile::NamedTempFile::new_in(directory).map_err(|_| "无法写入本机记忆")?;
    temporary
        .write_all(&[1])
        .and_then(|_| temporary.write_all(&nonce))
        .and_then(|_| temporary.write_all(&encrypted))
        .and_then(|_| temporary.as_file().sync_all())
        .map_err(|_| "无法保存本机记忆")?;
    temporary.persist(path).map_err(|_| "无法替换本机记忆")?;
    Ok(())
}

pub fn write(app: &AppHandle, scope: &Value, value: &Value) -> Result<(), String> {
    let key = scope_key(scope)?;
    if scope.get("storage").is_some() {
        let legacy = file(app, &key)?;
        let path = legacy.with_extension("workspace.bin");
        let exists = checked_file(&path)?.is_some() || checked_file(&legacy)?.is_some();
        return write_encrypted(&path, &key, value, exists);
    }
    workspace_content(value, false)?;
    if value["scope"] != *scope {
        return Err("本机工作区当前会话范围无效".into());
    }
    let path = file(app, WORKSPACE_KEY)?.with_extension("workspace.bin");
    let stored = read_encrypted(&path, WORKSPACE_KEY, &local_scope())?;
    if let Some(stored) = &stored {
        workspace_content(stored, false)?;
    }
    write_encrypted(
        &path,
        WORKSPACE_KEY,
        &workspace_envelope(value),
        stored.is_some(),
    )
}
