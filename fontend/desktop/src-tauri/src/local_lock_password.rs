use aes_gcm::aead::{rand_core::RngCore, OsRng};
use argon2::{Algorithm, Argon2, Params, Version};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    sync::Mutex,
    time::{Duration, Instant},
};
use subtle::ConstantTimeEq;
use zeroize::Zeroizing;

const SERVICE: &str = "com.2iwm.practical.desktop.local-lock";
static OPERATION: tokio::sync::Mutex<()> = tokio::sync::Mutex::const_new(());

pub fn reserve() -> Result<tokio::sync::MutexGuard<'static, ()>, String> {
    OPERATION.try_lock().map_err(|_| "上次本机密码操作仍等待系统凭据库，请完成或取消系统授权后重试；可使用账号密码或企业微信解锁".into())
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Verifier {
    version: u8,
    salt: [u8; 16],
    hash: [u8; 32],
}

#[derive(Default)]
struct Attempts {
    key: String,
    failures: u8,
    until: Option<Instant>,
}

#[derive(Default)]
pub struct LocalLockPassword {
    attempts: Mutex<Attempts>,
}

pub fn scope(origin: &url::Url, context: &Value) -> Result<String, String> {
    let account = context["account_id"]
        .as_u64()
        .filter(|id| *id > 0)
        .ok_or("请先登录学校账号")?;
    let school = context["school_id"].as_u64().ok_or("学校身份无效")?;
    Ok(crate::school_api::digest(&format!(
        "{}:{school}:{account}",
        origin.origin().ascii_serialization()
    )))
}

fn entry(key: &str) -> Result<keyring::Entry, String> {
    keyring::Entry::new(SERVICE, key)
        .map_err(|_| "系统凭据库不可用，请使用账号密码或企业微信解锁".into())
}

fn load(key: &str) -> Result<Option<Verifier>, String> {
    match entry(key)?.get_password() {
        Ok(value) => {
            let value = Zeroizing::new(value);
            if value.len() > 1024 {
                return Err("本机锁屏密码配置无效，请解锁后重新设置".into());
            }
            let verifier: Verifier = serde_json::from_str(&value)
                .map_err(|_| "本机锁屏密码配置无效，请解锁后重新设置")?;
            if verifier.version != 1 {
                return Err("本机锁屏密码版本不支持，请使用账号密码或企业微信解锁".into());
            }
            Ok(Some(verifier))
        }
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(_) => Err("无法读取本机锁屏密码，请解锁系统凭据库或使用其他方式解锁".into()),
    }
}

fn derive(password: &str, salt: &[u8; 16]) -> Result<Zeroizing<[u8; 32]>, String> {
    let params = Params::new(19456, 2, 1, Some(32)).map_err(|_| "本机密码校验参数无效")?;
    let mut output = Zeroizing::new([0u8; 32]);
    Argon2::new(Algorithm::Argon2id, Version::V0x13, params)
        .hash_password_into(password.as_bytes(), salt, output.as_mut())
        .map_err(|_| "本机密码校验失败，请使用其他方式解锁")?;
    Ok(output)
}

pub fn validate(password: &str) -> Result<(), String> {
    if !(4..=64).contains(&password.chars().count()) || password.len() > 256 {
        return Err("本机锁屏密码须为 4 至 64 个字符".into());
    }
    Ok(())
}

impl LocalLockPassword {
    pub fn exists(&self, key: &str) -> Result<bool, String> {
        Ok(load(key)?.is_some())
    }

    pub fn set(&self, key: &str, password: &str) -> Result<bool, String> {
        validate(password)?;
        let mut salt = [0u8; 16];
        OsRng
            .try_fill_bytes(&mut salt)
            .map_err(|_| "无法生成本机密码随机盐")?;
        let hash = derive(password, &salt)?;
        let value = Zeroizing::new(
            serde_json::to_string(&Verifier {
                version: 1,
                salt,
                hash: *hash,
            })
            .map_err(|_| "无法生成本机密码校验值")?,
        );
        entry(key)?
            .set_password(&value)
            .map_err(|_| "无法保存本机锁屏密码，请解锁系统凭据库后重试")?;
        self.reset()?;
        Ok(true)
    }

    pub fn clear(&self, key: &str) -> Result<bool, String> {
        match entry(key)?.delete_credential() {
            Ok(()) | Err(keyring::Error::NoEntry) => {}
            Err(_) => return Err("无法清除本机锁屏密码，请解锁系统凭据库后重试".into()),
        }
        self.reset()?;
        Ok(false)
    }

    fn reset(&self) -> Result<(), String> {
        *self.attempts.lock().map_err(|_| "本机密码校验状态不可用")? = Attempts::default();
        Ok(())
    }

    pub fn verify(&self, key: &str, password: &str) -> Result<bool, String> {
        if !(4..=128).contains(&password.chars().count()) || password.len() > 512 {
            return Err("本机锁屏密码长度无效，请输入已设置的密码".into());
        }
        let mut attempts = self.attempts.lock().map_err(|_| "本机密码校验状态不可用")?;
        if attempts.key != key {
            *attempts = Attempts {
                key: key.to_owned(),
                ..Attempts::default()
            };
        }
        if let Some(until) = attempts.until {
            if let Some(remaining) = until.checked_duration_since(Instant::now()) {
                return Err(format!(
                    "本机密码尝试过多，请在 {} 秒后重试，或使用账号密码、企业微信解锁",
                    remaining.as_secs() + 1
                ));
            }
            attempts.failures = 0;
            attempts.until = None;
        }
        let verifier =
            load(key)?.ok_or("当前账号尚未设置本机锁屏密码，请使用账号密码或企业微信解锁")?;
        let actual = derive(password, &verifier.salt)?;
        if bool::from(actual.as_slice().ct_eq(&verifier.hash)) {
            *attempts = Attempts::default();
            return Ok(true);
        }
        attempts.failures += 1;
        if attempts.failures >= 5 {
            attempts.until = Some(Instant::now() + Duration::from_secs(60));
            return Err(
                "本机锁屏密码错误，已暂停本机密码验证 60 秒，可使用账号密码或企业微信解锁".into(),
            );
        }
        Err(format!(
            "本机锁屏密码错误，还可尝试 {} 次",
            5 - attempts.failures
        ))
    }
}
