pub struct Entry {
    inner: keyring::Entry,
    #[cfg(target_os = "macos")]
    key: (String, String),
}

impl Entry {
    pub fn new(service: &str, user: &str) -> keyring::Result<Self> {
        Ok(Self {
            inner: keyring::Entry::new(service, user)?,
            #[cfg(target_os = "macos")]
            key: (service.to_owned(), user.to_owned()),
        })
    }

    pub fn get_password(&self) -> keyring::Result<String> {
        #[cfg(target_os = "macos")]
        {
            cache::get_password(&self.key, &self.inner)
        }
        #[cfg(not(target_os = "macos"))]
        {
            self.inner.get_password()
        }
    }

    pub fn set_password(&self, password: &str) -> keyring::Result<()> {
        #[cfg(target_os = "macos")]
        {
            cache::set_password(&self.key, &self.inner, password)
        }
        #[cfg(not(target_os = "macos"))]
        {
            self.inner.set_password(password)
        }
    }

    pub fn delete_credential(&self) -> keyring::Result<()> {
        #[cfg(target_os = "macos")]
        {
            cache::delete_credential(&self.key, &self.inner)
        }
        #[cfg(not(target_os = "macos"))]
        {
            self.inner.delete_credential()
        }
    }
}

pub fn clear() {
    #[cfg(target_os = "macos")]
    cache::clear();
}

pub fn retry() {
    #[cfg(target_os = "macos")]
    cache::retry();
}

#[cfg(target_os = "macos")]
mod cache {
    use std::{
        collections::HashMap,
        io,
        sync::{
            atomic::{AtomicU64, Ordering},
            Arc, Mutex, OnceLock,
        },
        time::{Duration, Instant},
    };
    use zeroize::Zeroizing;

    const ENTRY_LIMIT: usize = 1024;
    const VALUE_LIMIT: usize = 64 * 1024;
    const RETRY_DELAY: Duration = Duration::from_secs(30);
    type Key = (String, String);
    type Slots = HashMap<Key, Arc<Mutex<Slot>>>;
    static SLOTS: OnceLock<Mutex<Slots>> = OnceLock::new();
    static EPOCH: AtomicU64 = AtomicU64::new(0);

    #[derive(Default)]
    struct Slot {
        value: Option<Zeroizing<String>>,
        retry_after: Option<Instant>,
    }

    fn slots() -> &'static Mutex<Slots> {
        SLOTS.get_or_init(|| Mutex::new(HashMap::new()))
    }

    fn failure(kind: io::ErrorKind, message: &'static str) -> keyring::Error {
        keyring::Error::PlatformFailure(Box::new(io::Error::new(kind, message)))
    }

    fn slot(key: &Key) -> keyring::Result<(u64, Arc<Mutex<Slot>>)> {
        let mut slots = slots()
            .lock()
            .map_err(|_| failure(io::ErrorKind::Other, "系统凭据缓存状态不可用"))?;
        if let Some(slot) = slots.get(key) {
            return Ok((EPOCH.load(Ordering::Acquire), Arc::clone(slot)));
        }
        if slots.len() >= ENTRY_LIMIT {
            let idle = slots
                .iter()
                .find(|(_, slot)| Arc::strong_count(slot) == 1)
                .map(|(key, _)| key.clone());
            match idle {
                Some(key) => {
                    slots.remove(&key);
                }
                None => return Err(failure(io::ErrorKind::WouldBlock, "系统凭据操作繁忙")),
            }
        }
        let slot = Arc::new(Mutex::new(Slot::default()));
        slots.insert(key.clone(), Arc::clone(&slot));
        Ok((EPOCH.load(Ordering::Acquire), slot))
    }

    fn current(epoch: u64) -> keyring::Result<()> {
        if EPOCH.load(Ordering::Acquire) == epoch {
            Ok(())
        } else {
            Err(failure(
                io::ErrorKind::Interrupted,
                "学校账号或锁定状态已变更，请重新操作系统凭据",
            ))
        }
    }

    pub(super) fn get_password(key: &Key, entry: &keyring::Entry) -> keyring::Result<String> {
        let (epoch, slot) = slot(key)?;
        let mut slot = slot
            .lock()
            .map_err(|_| failure(io::ErrorKind::Other, "系统凭据操作状态不可用"))?;
        current(epoch)?;
        if let Some(value) = &slot.value {
            let value = Zeroizing::new(value.as_str().to_owned());
            current(epoch)?;
            return Ok(value.as_str().to_owned());
        }
        if slot.retry_after.is_some_and(|until| until > Instant::now()) {
            current(epoch)?;
            return Err(failure(
                io::ErrorKind::WouldBlock,
                "系统凭据读取暂不可用，请完成或取消系统授权后稍后重试",
            ));
        }
        slot.retry_after = None;
        let result = entry.get_password().map(Zeroizing::new);
        current(epoch)?;
        match result {
            Ok(value) => {
                if value.len() <= VALUE_LIMIT {
                    slot.value = Some(Zeroizing::new(value.as_str().to_owned()));
                }
                if let Err(error) = current(epoch) {
                    slot.value = None;
                    return Err(error);
                }
                Ok(value.as_str().to_owned())
            }
            Err(keyring::Error::NoEntry) => {
                current(epoch)?;
                Err(keyring::Error::NoEntry)
            }
            Err(error) => {
                slot.retry_after = Some(Instant::now() + RETRY_DELAY);
                current(epoch)?;
                Err(error)
            }
        }
    }

    pub(super) fn set_password(
        key: &Key,
        entry: &keyring::Entry,
        password: &str,
    ) -> keyring::Result<()> {
        let (epoch, slot) = slot(key)?;
        let mut slot = slot
            .lock()
            .map_err(|_| failure(io::ErrorKind::Other, "系统凭据操作状态不可用"))?;
        current(epoch)?;
        if slot
            .value
            .as_ref()
            .is_some_and(|value| value.as_str() == password)
        {
            return current(epoch);
        }
        slot.value = None;
        slot.retry_after = None;
        current(epoch)?;
        let result = entry.set_password(password);
        current(epoch)?;
        if result.is_ok() && password.len() <= VALUE_LIMIT {
            slot.value = Some(Zeroizing::new(password.to_owned()));
        }
        if let Err(error) = current(epoch) {
            slot.value = None;
            return Err(error);
        }
        result
    }

    pub(super) fn delete_credential(key: &Key, entry: &keyring::Entry) -> keyring::Result<()> {
        let (epoch, slot) = slot(key)?;
        let mut slot = slot
            .lock()
            .map_err(|_| failure(io::ErrorKind::Other, "系统凭据操作状态不可用"))?;
        current(epoch)?;
        slot.value = None;
        slot.retry_after = None;
        current(epoch)?;
        let result = entry.delete_credential();
        current(epoch)?;
        result
    }

    pub(super) fn clear() {
        let cache = slots();
        let mut slots = cache.lock().unwrap_or_else(|error| error.into_inner());
        EPOCH.fetch_add(1, Ordering::AcqRel);
        slots.clear();
        cache.clear_poison();
    }

    pub(super) fn retry() {
        let pending = {
            let Ok(slots) = slots().lock() else {
                return;
            };
            slots.values().cloned().collect::<Vec<_>>()
        };
        for slot in pending {
            let Ok(mut state) = slot.try_lock() else {
                continue;
            };
            state.retry_after = None;
        }
    }
}
