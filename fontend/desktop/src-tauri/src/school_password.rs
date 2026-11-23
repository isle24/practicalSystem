use crate::local_key;
use aes_gcm::{
    aead::{rand_core::RngCore, Aead, OsRng, Payload},
    Aes256Gcm, KeyInit, Nonce,
};
use std::{
    fs,
    io::{ErrorKind, Read, Write},
    os::unix::fs::DirBuilderExt,
    path::PathBuf,
};
use tauri::{AppHandle, Manager};
use tempfile::NamedTempFile;
use zeroize::Zeroizing;

const HEADER_BYTES: usize = 1 + 12;
const RECORD_OVERHEAD: usize = HEADER_BYTES + 16;
const MAX_RECORD_BYTES: usize = RECORD_OVERHEAD + crate::connection::MAX_PASSWORD_BYTES;

pub struct SchoolPassword {
    directory: PathBuf,
    scope: String,
}

impl SchoolPassword {
    pub fn new(app: &AppHandle, origin: &str, username: &str) -> Result<Self, String> {
        let directory = app
            .path()
            .app_config_dir()
            .map_err(|_| "无法定位本机学校密码目录")?
            .join("school-passwords");
        match local_key::check_directory(&directory) {
            Ok(()) => {}
            Err(error) if error.kind() == ErrorKind::NotFound => {}
            _ => return Err("本机学校密码目录须为仅当前用户可访问的普通目录".into()),
        }
        let scope = crate::school_api::digest(&format!("{origin}\0{username}"));
        Ok(Self { directory, scope })
    }

    fn path(&self) -> PathBuf {
        self.directory.join(format!("{}.bin", self.scope))
    }

    // 空记录表示该账号已取消记住，旧版钥匙串不再参与登录。
    pub fn status(&self) -> Result<Option<bool>, String> {
        match fs::symlink_metadata(self.path()) {
            Ok(file) if file.is_file() => Ok(Some(file.len() > 0)),
            Err(error) if error.kind() == ErrorKind::NotFound => Ok(None),
            _ => Err("无法读取本机学校密码状态，请手工输入密码".into()),
        }
    }

    fn key(&self, create: bool) -> Result<Zeroizing<[u8; 32]>, String> {
        local_key::load(&self.directory.join("local.key"), create).map_err(|error| {
            match error.kind() {
                ErrorKind::InvalidData => "本机学校密码密钥无效，请手工输入密码",
                _ => "本机学校密码密钥不可用，请手工输入密码",
            }
            .into()
        })
    }

    pub fn load(&self) -> Result<Zeroizing<String>, String> {
        let file = local_key::open_private_file(&self.path())
            .map_err(|_| "本机学校密码不可读，请手工输入密码")?;
        let mut bytes = Vec::new();
        file.take((MAX_RECORD_BYTES + 1) as u64)
            .read_to_end(&mut bytes)
            .map_err(|_| "无法读取本机学校密码，请手工输入密码")?;
        if bytes.len() <= RECORD_OVERHEAD || bytes.len() > MAX_RECORD_BYTES || bytes[0] != 1 {
            return Err("本机学校密码记录无效，请手工输入密码".into());
        }
        let cipher = Aes256Gcm::new_from_slice(self.key(false)?.as_ref())
            .map_err(|_| "本机学校密码密钥无效")?;
        let clear = cipher
            .decrypt(
                Nonce::from_slice(&bytes[1..HEADER_BYTES]),
                Payload {
                    msg: &bytes[HEADER_BYTES..],
                    aad: self.scope.as_bytes(),
                },
            )
            .map_err(|_| "本机学校密码无法解密，请手工输入密码")?;
        String::from_utf8(clear)
            .map(Zeroizing::new)
            .map_err(|_| "本机学校密码格式无效".into())
    }

    pub fn save(&self, password: Option<&str>) -> Result<(), String> {
        if password.is_none() && self.status()? == Some(false) {
            return Ok(());
        }
        fs::DirBuilder::new()
            .recursive(true)
            .mode(0o700)
            .create(&self.directory)
            .map_err(|_| "无法创建本机学校密码目录")?;
        let mut temporary =
            NamedTempFile::new_in(&self.directory).map_err(|_| "无法写入本机学校密码")?;
        if let Some(password) = password {
            let cipher = Aes256Gcm::new_from_slice(self.key(true)?.as_ref())
                .map_err(|_| "本机学校密码密钥无效")?;
            let mut nonce = [0u8; 12];
            OsRng
                .try_fill_bytes(&mut nonce)
                .map_err(|_| "无法生成学校密码加密随机数")?;
            let ciphertext = cipher
                .encrypt(
                    Nonce::from_slice(&nonce),
                    Payload {
                        msg: password.as_bytes(),
                        aad: self.scope.as_bytes(),
                    },
                )
                .map_err(|_| "本机学校密码加密失败")?;
            temporary
                .write_all(&[1])
                .and_then(|_| temporary.write_all(&nonce))
                .and_then(|_| temporary.write_all(&ciphertext))
                .map_err(|_| "无法写入本机学校密码")?;
        }
        temporary
            .as_file()
            .sync_all()
            .map_err(|_| "无法保存本机学校密码")?;
        temporary
            .persist(self.path())
            .map_err(|_| "无法保存本机学校密码")?;
        Ok(())
    }
}
