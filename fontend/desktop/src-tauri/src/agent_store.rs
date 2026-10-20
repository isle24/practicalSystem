use aes_gcm::aead::rand_core::RngCore;
use aes_gcm::{
    aead::{Aead, KeyInit, OsRng, Payload},
    Aes256Gcm, Nonce,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use fs2::FileExt;
use serde_json::{json, Value};
use std::{fs, io::Write, path::PathBuf};
use tauri::{AppHandle, Manager};
use zeroize::Zeroizing;

const SERVICE: &str = "com.2iwm.practical.desktop.agent.memory";

pub fn acquire(app: &AppHandle, scope: &Value) -> Result<fs::File, String> {
    let key = scope_key(scope)?;
    let path = file(app, &key)?.with_extension("lock");
    fs::create_dir_all(path.parent().ok_or("本机记忆目录无效")?)
        .map_err(|_| "无法创建本机记忆目录")?;
    let file = fs::OpenOptions::new()
        .read(true)
        .write(true)
        .create(true)
        .truncate(false)
        .open(path)
        .map_err(|_| "无法锁定本机记忆")?;
    file.try_lock_exclusive()
        .map_err(|_| "另一个客户端正在使用此账号的 Agent 记忆，请结束后重试")?;
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

pub fn read_locked(app: &AppHandle, scope: &Value) -> Result<Value, String> {
    let key = scope_key(scope)?;
    let legacy = file(app, &key)?;
    let path = legacy.with_extension("workspace.bin");
    let encoded = match fs::read(&path) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => fs::read(&legacy),
        result => result,
    };
    let data = match encoded {
        Ok(data) if data.len() <= 4 * 1024 * 1024 => data,
        Ok(_) => return Err("本机记忆超过容量限制，请清除后重新开始".into()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {
            return Ok(json!({"scope":scope,"messages":[],"history":[]}))
        }
        Err(_) => return Err("无法读取本机加密记忆".into()),
    };
    if data.len() < 29 || data[0] != 1 {
        return Err("本机记忆格式无效".into());
    }
    let key_bytes = secret(&key, false)?;
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
    if value["scope"] != *scope {
        return Err("本机记忆不属于当前学校账号".into());
    }
    Ok(value)
}

pub fn write(app: &AppHandle, scope: &Value, value: &Value) -> Result<(), String> {
    let key = scope_key(scope)?;
    let legacy = file(app, &key)?;
    let path = legacy.with_extension("workspace.bin");
    let exists = path.exists() || legacy.exists();
    let key_bytes = secret(&key, !exists)?;
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
