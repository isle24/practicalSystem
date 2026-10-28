use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    fs,
    io::Write,
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Mutex,
    },
    time::SystemTime,
};
use tauri::{AppHandle, Manager};

#[derive(Clone, Deserialize, Serialize)]
pub struct Preferences {
    #[serde(default = "enabled_default")]
    pub enabled: bool,
    #[serde(default = "idle_default")]
    pub idle_minutes: u16,
    #[serde(default)]
    pub require_fresh_login: bool,
}
fn enabled_default() -> bool {
    true
}
fn idle_default() -> u16 {
    30
}
impl Default for Preferences {
    fn default() -> Self {
        Self {
            enabled: true,
            idle_minutes: 30,
            require_fresh_login: false,
        }
    }
}

struct Details {
    preferences: Preferences,
    context: Option<Value>,
    snapshot: Option<Value>,
    last_activity: SystemTime,
    hidden_windows: Vec<String>,
    revision: u64,
    pending_lock: bool,
    wechat_session: Option<(String, u64)>,
}

pub struct ClientLock {
    locked: AtomicBool,
    details: Mutex<Details>,
    pub local_password: Arc<crate::local_lock_password::LocalLockPassword>,
}

pub fn load(app: &AppHandle) -> Result<Preferences, String> {
    let path = app
        .path()
        .app_config_dir()
        .map_err(|_| "无法定位客户端配置目录")?
        .join("desktop-lock.json");
    let preferences: Preferences = match fs::read(path) {
        Ok(bytes) => {
            serde_json::from_slice(&bytes).map_err(|_| "本机锁定设置无法读取，请检查配置文件")?
        }
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Preferences::default(),
        Err(_) => return Err("无法读取本机锁定设置".into()),
    };
    if !(1..=480).contains(&preferences.idle_minutes) {
        return Err("本机自动锁定时长无效".into());
    }
    Ok(preferences)
}

fn save(app: &AppHandle, preferences: &Preferences) -> Result<(), String> {
    let directory = app
        .path()
        .app_config_dir()
        .map_err(|_| "无法定位客户端配置目录")?;
    fs::create_dir_all(&directory).map_err(|_| "无法创建客户端配置目录")?;
    let mut file =
        tempfile::NamedTempFile::new_in(&directory).map_err(|_| "无法保存本机锁定设置")?;
    file.write_all(&serde_json::to_vec_pretty(preferences).map_err(|_| "无法生成本机锁定设置")?)
        .map_err(|_| "无法保存本机锁定设置")?;
    file.as_file()
        .sync_all()
        .map_err(|_| "无法保存本机锁定设置")?;
    file.persist(directory.join("desktop-lock.json"))
        .map_err(|_| "无法保存本机锁定设置")?;
    Ok(())
}

impl ClientLock {
    pub fn new(app: &AppHandle) -> Result<Self, String> {
        Ok(Self {
            locked: AtomicBool::new(false),
            local_password: Arc::default(),
            details: Mutex::new(Details {
                preferences: load(app)?,
                context: None,
                snapshot: None,
                last_activity: SystemTime::now(),
                hidden_windows: Vec::new(),
                revision: 0,
                pending_lock: false,
                wechat_session: None,
            }),
        })
    }
    pub fn is_locked(&self) -> bool {
        self.locked.load(Ordering::Acquire)
    }
    pub fn password_context(&self, locked: bool) -> Result<(Value, u64), String> {
        let details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        if self.is_locked() != locked {
            return Err(if locked {
                "客户端当前未锁定"
            } else {
                "客户端已锁定，请先解锁再修改本机密码"
            }
            .into());
        }
        let context = if locked {
            details.snapshot.as_ref()
        } else {
            details.context.as_ref()
        }
        .ok_or("学校登录会话不可用，请重新登录")?;
        if context["account_id"].as_u64().unwrap_or(0) == 0
            || context["school_id"].as_u64().is_none()
            || context["device_jti"]
                .as_str()
                .is_none_or(|jti| jti.is_empty())
        {
            return Err("学校登录会话无效，请重新登录".into());
        }
        Ok((context.clone(), details.revision))
    }
    pub fn status(&self) -> Result<Value, String> {
        let details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        Ok(
            json!({ "locked": self.is_locked(), "revision": details.revision, "enabled": details.preferences.enabled,
            "idle_minutes": details.preferences.idle_minutes, "require_fresh_login": details.preferences.require_fresh_login,
            "context": details.snapshot.as_ref().or(details.context.as_ref()).map(|context| json!({
                "account_id": context["account_id"], "school_id": context["school_id"],
                "school_name": context["school_name"], "user_name": context["user_name"], "login_name": context["login_name"],
            })) }),
        )
    }
    pub fn begin_wechat(&self) -> Result<u64, String> {
        let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        if !self.is_locked() || details.snapshot.is_none() {
            return Err("锁定会话已失效，请退出原会话后重新登录".into());
        }
        details.wechat_session = None;
        Ok(details.revision)
    }
    pub fn remember_wechat(&self, id: &str, revision: u64) -> Result<(), String> {
        if !valid_wechat_id(id) {
            return Err("扫码解锁响应无效，请重新获取二维码".into());
        }
        let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        if !self.is_locked() || details.snapshot.is_none() || details.revision != revision {
            return Err("锁定会话已变更，请重新获取二维码".into());
        }
        details.wechat_session = Some((id.to_owned(), revision));
        Ok(())
    }
    pub fn validate_wechat(&self, id: &str) -> Result<(), String> {
        let details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        if !self.is_locked()
            || details.snapshot.is_none()
            || !details
                .wechat_session
                .as_ref()
                .is_some_and(|(current, revision)| current == id && *revision == details.revision)
        {
            return Err("扫码会话已失效，请重新获取二维码".into());
        }
        Ok(())
    }
    pub fn cancel_wechat(&self, id: &str) -> Result<(), String> {
        if !valid_wechat_id(id) {
            return Err("扫码会话无效".into());
        }
        let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        if details
            .wechat_session
            .as_ref()
            .is_some_and(|(current, _)| current == id)
        {
            details.wechat_session = None;
        }
        Ok(())
    }
    pub fn settings(&self, app: &AppHandle, input: &Value) -> Result<Value, String> {
        if self.is_locked() {
            return Err("客户端已锁定，请先解锁再修改设置".into());
        }
        let enabled = input["enabled"].as_bool().ok_or("请设置是否启用自动锁定")?;
        let minutes = input["idle_minutes"]
            .as_u64()
            .filter(|minutes| (1..=480).contains(minutes))
            .ok_or("自动锁定时长须为 1 至 480 分钟")?;
        {
            let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
            if self.is_locked() {
                return Err("客户端已锁定，请先解锁再修改设置".into());
            }
            let mut next = details.preferences.clone();
            next.enabled = enabled;
            next.idle_minutes = minutes as u16;
            save(app, &next)?;
            details.preferences = next;
            details.revision += 1;
        }
        self.status()
    }
    pub fn activity(&self) {
        if !self.is_locked() {
            if let Ok(mut details) = self.details.lock() {
                let expired = details.preferences.enabled
                    && details.context.is_some()
                    && details
                        .last_activity
                        .elapsed()
                        .unwrap_or_default()
                        .as_secs()
                        >= u64::from(details.preferences.idle_minutes) * 60;
                if !expired {
                    details.last_activity = SystemTime::now();
                }
            }
        }
    }
    pub fn due(&self) -> bool {
        if self.is_locked() {
            return false;
        }
        self.details.lock().is_ok_and(|details| {
            details.preferences.enabled
                && details.context.is_some()
                && details
                    .last_activity
                    .elapsed()
                    .unwrap_or_default()
                    .as_secs()
                    >= u64::from(details.preferences.idle_minutes) * 60
        })
    }
    pub fn clear_context(&self) {
        crate::system_credentials::clear();
        if let Ok(mut details) = self.details.lock() {
            if details.context.is_some() || details.snapshot.is_some() {
                details.revision += 1;
            }
            details.context = None;
            details.snapshot = None;
            details.wechat_session = None;
            details.last_activity = SystemTime::now();
        }
    }
    pub fn remember_context(&self, context: &Value) {
        if context["account_id"].as_u64().unwrap_or(0) == 0 {
            self.clear_context();
            return;
        }
        if let Ok(mut details) = self.details.lock() {
            if self.is_locked() {
                return;
            }
            if details.context.is_none() {
                details.last_activity = SystemTime::now();
            }
            details.context = Some(context.clone());
        }
    }
    pub fn authenticated(
        &self,
        app: &AppHandle,
        context: &Value,
        fresh: bool,
    ) -> Result<(), String> {
        let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        details.wechat_session = None;
        if self.is_locked() {
            if details.pending_lock {
                details.context = Some(context.clone());
                return Ok(());
            }
            return Err("客户端已锁定，请先退出原会话".into());
        }
        if fresh && details.preferences.require_fresh_login {
            let mut next = details.preferences.clone();
            next.require_fresh_login = false;
            save(app, &next)?;
            details.preferences = next;
        }
        details.revision += 1;
        details.context = Some(context.clone());
        details.snapshot = None;
        details.last_activity = SystemTime::now();
        Ok(())
    }
    pub fn begin(&self, app: &AppHandle, owner: &str) -> Result<bool, String> {
        let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        if self.is_locked() {
            return Ok(false);
        }
        details.preferences.require_fresh_login = true;
        let persisted = save(app, &details.preferences);
        details.revision += 1;
        details.snapshot = None;
        details.pending_lock = true;
        details.wechat_session = None;
        self.locked.store(true, Ordering::Release);
        crate::system_credentials::clear();
        details.hidden_windows = crate::windows::hide_for_lock(app, owner);
        drop(details);
        crate::windows::notify_lock(app);
        persisted?;
        Ok(true)
    }
    pub fn freeze(&self, app: &AppHandle, context: &Value) -> Result<(), String> {
        let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        details.pending_lock = false;
        details.revision += 1;
        details.wechat_session = None;
        let valid = context["account_id"].as_u64().unwrap_or(0) > 0
            && !context["school_id"].is_null()
            && context["device_jti"]
                .as_str()
                .is_some_and(|jti| !jti.is_empty());
        if valid {
            details.context = Some(context.clone());
            details.snapshot = Some(context.clone());
        } else {
            details.context = None;
            details.snapshot = None;
        }
        drop(details);
        crate::windows::notify_lock(app);
        if !valid {
            return Err("锁定会话已失效，请退出原会话后重新登录".into());
        }
        Ok(())
    }
    pub fn restore_ordinary(&self, app: &AppHandle) {
        if let Ok(details) = self.details.lock() {
            for label in &details.hidden_windows {
                if label.starts_with("external-") {
                    if let Some(window) = app.get_webview_window(label) {
                        let _ = window.show();
                    }
                }
            }
        }
    }
    pub fn unlock(&self, app: &AppHandle, owner: &str, result: &Value) -> Result<Value, String> {
        let mut details = self.details.lock().map_err(|_| "本机锁定状态不可用")?;
        if !self.is_locked() {
            return Err("客户端当前未锁定".into());
        }
        let snapshot = details
            .snapshot
            .as_ref()
            .ok_or("锁定会话已失效，请退出原会话后重新登录")?;
        let context = &result["context"];
        if !self.is_locked()
            || result["verified"].as_bool() != Some(true)
            || context["account_id"].as_u64().unwrap_or(0) == 0
            || context["school_id"].is_null()
            || context["device_jti"]
                .as_str()
                .is_none_or(|jti| jti.is_empty())
            || ["account_id", "school_id", "device_jti"]
                .iter()
                .any(|key| snapshot[*key] != context[*key])
        {
            return Err("解锁身份与锁定账号不一致，请退出原会话后重新登录".into());
        }
        let mut next = details.preferences.clone();
        next.require_fresh_login = false;
        save(app, &next)?;
        details.preferences = next;
        details.revision += 1;
        details.context = Some(context.clone());
        details.snapshot = None;
        details.pending_lock = false;
        details.wechat_session = None;
        details.last_activity = SystemTime::now();
        let hidden = std::mem::take(&mut details.hidden_windows);
        self.locked.store(false, Ordering::Release);
        drop(details);
        crate::windows::restore_after_lock(app, owner, &hidden);
        crate::windows::notify_lock(app);
        self.status()
    }
}

fn valid_wechat_id(id: &str) -> bool {
    id.len() == 64
        && id
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
}
