use crate::{
    connection::Connection,
    external,
    favorite_auth::{self, Profile},
    school_api,
};
use aes_gcm::{
    aead::{rand_core::RngCore, Aead, KeyInit, OsRng, Payload},
    Aes256Gcm, Nonce,
};
use base64::{engine::general_purpose::STANDARD, Engine as _};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    fs,
    io::Write,
    path::PathBuf,
    sync::OnceLock,
    time::{SystemTime, UNIX_EPOCH},
};
use url::Url;
use zeroize::Zeroizing;

const SERVICE: &str = "com.2iwm.practical.desktop.vault";
const LEGACY_SERVICE: &str = "com.2iwm.practical.desktop.favorite";
const LIMIT: usize = 1000;
const ACTIVE_LIMIT: usize = 100;
const TRANSPORT_AAD: &[u8] = b"practical-credential-vault:v1";
static DIRECTORY: OnceLock<PathBuf> = OnceLock::new();
static LOCAL_LOCK: tokio::sync::Mutex<()> = tokio::sync::Mutex::const_new(());

#[derive(Clone, Deserialize, Serialize, PartialEq)]
struct Owner {
    school_database_id: u64,
    user_id: u64,
    account_id: u64,
}

struct Scope {
    owner: Owner,
    key: String,
}

#[derive(Clone, Deserialize, Serialize)]
struct Metadata {
    id: String,
    site_origin: String,
    target_hash: String,
    favorite_id: u64,
    label: String,
    modified_at: u64,
    deleted: bool,
    key: String,
    #[serde(default)]
    legacy_keys: Vec<String>,
}

#[derive(Default, Deserialize, Serialize)]
struct Index {
    #[serde(default)]
    sync_enabled: bool,
    #[serde(default)]
    legacy_scanned: bool,
    #[serde(default)]
    records: Vec<Metadata>,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct SyncRecord {
    id: String,
    site_origin: String,
    target_hash: String,
    favorite_id: u64,
    label: String,
    modified_at: u64,
    deleted: bool,
    profile: Option<Profile>,
}

#[derive(Deserialize)]
struct Download {
    owner: Owner,
    records: Vec<SyncRecord>,
}

/// 由客户端启动流程设置非秘密索引目录。
pub fn initialize(directory: PathBuf) -> Result<(), String> {
    let directory = directory.join("credential-vault");
    fs::create_dir_all(&directory).map_err(|_| "无法创建本机凭据索引目录")?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&directory, fs::Permissions::from_mode(0o700))
            .map_err(|_| "无法保护本机凭据索引目录")?;
    }
    if let Some(current) = DIRECTORY.get() {
        return if *current == directory {
            Ok(())
        } else {
            Err("凭据索引目录已初始化".into())
        };
    }
    DIRECTORY
        .set(directory)
        .map_err(|_| "凭据索引目录已初始化".into())
}

async fn scope(connection: &Connection) -> Result<Scope, String> {
    let context = school_api::read(connection, "api/auth/context").await?;
    let user_id = context["user_id"].as_u64().unwrap_or(0);
    let account_id = context["account_id"].as_u64().unwrap_or(0);
    if user_id == 0 || account_id == 0 {
        return Err("请先登录有效学校账号".into());
    }
    let status = school_api::read(connection, "api/credential-vault/status").await?;
    let owner: Owner =
        serde_json::from_value(status["owner"].clone()).map_err(|_| "凭据库身份格式无效")?;
    if owner.school_database_id == 0 || owner.user_id == 0 || owner.account_id == 0 {
        return Err("请先登录有效学校账号".into());
    }
    if owner.user_id != user_id || owner.account_id != account_id {
        return Err("学校账号已切换，请重新读取凭据库".into());
    }
    let key = school_api::digest(&format!(
        "{}:{}:{}:{}",
        connection.origin, owner.school_database_id, owner.user_id, owner.account_id
    ));
    Ok(Scope { owner, key })
}

fn path(scope: &Scope) -> Result<PathBuf, String> {
    Ok(DIRECTORY
        .get()
        .ok_or("本机凭据索引尚未初始化")?
        .join(format!("{}.json", scope.key)))
}

fn load(scope: &Scope) -> Result<Index, String> {
    let path = path(scope)?;
    if !path.exists() {
        return Ok(Index::default());
    }
    if fs::metadata(&path).map_err(|_| "无法读取凭据索引")?.len() > 2 * 1024 * 1024 {
        return Err("本机凭据索引过大，请检查客户端配置".into());
    }
    let index: Index = serde_json::from_slice(&fs::read(path).map_err(|_| "无法读取凭据索引")?)
        .map_err(|_| "本机凭据索引损坏；请先备份配置目录")?;
    if index.records.len() > LIMIT
        || index.records.iter().any(|row| {
            !is_hash(&row.id)
                || !is_hash(&row.key)
                || row.legacy_keys.iter().any(|key| !is_hash(key))
        })
    {
        return Err("本机凭据索引格式无效".into());
    }
    Ok(index)
}

fn save(scope: &Scope, index: &Index) -> Result<(), String> {
    let path = path(scope)?;
    let mut file = tempfile::NamedTempFile::new_in(path.parent().ok_or("凭据索引目录无效")?)
        .map_err(|_| "无法创建凭据索引")?;
    let bytes = serde_json::to_vec(index).map_err(|_| "无法生成凭据索引")?;
    file.write_all(&bytes).map_err(|_| "无法保存凭据索引")?;
    file.as_file().sync_all().map_err(|_| "无法保存凭据索引")?;
    file.persist(path).map_err(|_| "无法替换凭据索引")?;
    Ok(())
}

fn entry(key: &str) -> Result<keyring::Entry, String> {
    keyring::Entry::new(SERVICE, key).map_err(|_| "系统凭据库不可用".into())
}

fn legacy_entry(key: &str) -> Result<keyring::Entry, String> {
    keyring::Entry::new(LEGACY_SERVICE, key).map_err(|_| "系统凭据库不可用".into())
}

fn read_profile(key: &str) -> Result<Option<Profile>, String> {
    match entry(key)?.get_password() {
        Ok(value) => {
            let value = Zeroizing::new(value);
            let profile: Profile =
                serde_json::from_str(&value).map_err(|_| "本机认证配置已损坏")?;
            favorite_auth::validate(&profile)?;
            Ok(Some(profile))
        }
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(_) => Err("无法读取系统凭据库；请解锁系统凭据存储".into()),
    }
}

fn delete_entry(entry: keyring::Entry) -> Result<(), String> {
    match entry.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(_) => Err("无法清除系统凭据；请解锁系统凭据存储".into()),
    }
}

fn erase(row: &Metadata) -> Result<(), String> {
    delete_entry(entry(&row.key)?)?;
    for key in &row.legacy_keys {
        delete_entry(legacy_entry(key)?)?;
    }
    Ok(())
}

fn timestamp(index: &Index) -> u64 {
    let now = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(1, |value| value.as_millis() as u64);
    now.max(
        index
            .records
            .iter()
            .map(|row| row.modified_at)
            .max()
            .unwrap_or(0)
            .saturating_add(1),
    )
}

fn is_hash(value: &str) -> bool {
    value.len() == 64
        && value
            .bytes()
            .all(|c| c.is_ascii_hexdigit() && !c.is_ascii_uppercase())
}

fn metadata(connection: &Connection, scope: &Scope, favorite: &Value) -> Result<Metadata, String> {
    let address = favorite["url"].as_str().ok_or("收藏地址不存在")?;
    let url = Url::parse(address).map_err(|_| "收藏地址无效")?;
    if url.scheme() != "https"
        || !external::allowed(&url)
        || url.origin() == connection.origin.origin()
    {
        return Err("本机认证只支持 HTTPS 外部站点".into());
    }
    let favorite_id = favorite["id"].as_u64().ok_or("收藏编号无效")?;
    let target_hash = school_api::digest(address);
    let id = school_api::digest(&format!("favorite:{favorite_id}:{target_hash}"));
    Ok(Metadata {
        key: school_api::digest(&format!("{}:{id}", scope.key)),
        id,
        site_origin: url.origin().ascii_serialization(),
        target_hash,
        favorite_id,
        label: favorite["title"]
            .as_str()
            .unwrap_or("外部站点")
            .chars()
            .filter(|c| !c.is_control())
            .take(180)
            .collect(),
        modified_at: 0,
        deleted: false,
        legacy_keys: Vec::new(),
    })
}

fn put(
    scope: &Scope,
    index: &mut Index,
    mut row: Metadata,
    profile: &Profile,
) -> Result<(), String> {
    favorite_auth::validate(profile)?;
    let value = Zeroizing::new(serde_json::to_string(profile).map_err(|_| "认证配置格式无效")?);
    if let Some(old) = index.records.iter().find(|old| old.id == row.id) {
        row.legacy_keys = old.legacy_keys.clone();
    }
    if !index.records.iter().any(|old| old.id == row.id) && index.records.len() >= LIMIT {
        return Err("凭据索引最多管理 1000 条历史记录".into());
    }
    if index
        .records
        .iter()
        .filter(|old| !old.deleted && old.id != row.id)
        .count()
        >= ACTIVE_LIMIT
    {
        return Err("当前账号最多保存 100 条有效凭据".into());
    }
    let credential = entry(&row.key)?;
    let previous = match credential.get_password() {
        Ok(secret) => Some(Zeroizing::new(secret)),
        Err(keyring::Error::NoEntry) => None,
        Err(_) => return Err("无法读取系统凭据库；请解锁系统凭据存储".into()),
    };
    credential
        .set_password(&value)
        .map_err(|_| "无法保存到系统凭据库；请解锁系统凭据存储")?;
    let old_records = index.records.clone();
    index.records.retain(|old| old.id != row.id);
    index.records.push(row.clone());
    if let Err(error) = save(scope, index) {
        index.records = old_records;
        if let Some(secret) = previous {
            let _ = credential.set_password(&secret);
        } else {
            let _ = delete_entry(credential);
        }
        return Err(error);
    }
    Ok(())
}

fn migrate_favorite(
    connection: &Connection,
    scope: &Scope,
    index: &mut Index,
    favorite: &Value,
) -> Result<(), String> {
    let mut row = metadata(connection, scope, favorite)?;
    if index.records.iter().any(|old| old.id == row.id) {
        return Ok(());
    }
    let old_key = school_api::digest(&format!(
        "{}:{}:{}:{}",
        connection.origin,
        scope.owner.account_id,
        row.favorite_id,
        favorite["url"].as_str().unwrap_or("")
    ));
    let value = match legacy_entry(&old_key)?.get_password() {
        Ok(value) => Zeroizing::new(value),
        Err(keyring::Error::NoEntry) => return Ok(()),
        Err(_) => return Err("无法读取历史系统凭据".into()),
    };
    let profile: Profile = serde_json::from_str(&value).map_err(|_| "历史认证配置已损坏")?;
    row.modified_at = 1;
    row.legacy_keys.push(old_key);
    put(scope, index, row, &profile)
}

async fn migrate_visible(
    connection: &Connection,
    scope: &Scope,
    index: &mut Index,
) -> Result<(), String> {
    if index.legacy_scanned {
        return Ok(());
    }
    let mut page = 1;
    loop {
        let data = school_api::read(
            connection,
            &format!("api/favorite/list?page={page}&page_size=200"),
        )
        .await?;
        let items = data["items"].as_array().ok_or("收藏列表格式无效")?;
        for favorite in items {
            let address = favorite["url"].as_str().unwrap_or("");
            if let Ok(url) = Url::parse(address) {
                if url.scheme() == "https"
                    && external::allowed(&url)
                    && url.origin() != connection.origin.origin()
                {
                    migrate_favorite(connection, scope, index, favorite)?;
                }
            }
        }
        if items.len() < 200 || page * 200 >= data["pagination"]["total"].as_u64().unwrap_or(0) {
            break;
        }
        page += 1;
    }
    index.legacy_scanned = true;
    save(scope, index)
}

fn listing(index: &Index) -> Value {
    let items: Vec<Value> = index
        .records
        .iter()
        .filter(|row| !row.deleted)
        .map(|row| {
            json!({
                "id": row.id, "site_origin": row.site_origin, "favorite_id": row.favorite_id,
                "label": row.label, "modified_at": row.modified_at,
            })
        })
        .collect();
    json!({"items":items,"count":items.len(),"enabled":index.sync_enabled})
}

async fn cloud_status(connection: &Connection, scope: &Scope) -> Result<Value, String> {
    if connection.origin.scheme() != "https" {
        return Err("云同步密码需要学校 HTTPS 连接".into());
    }
    let status = school_api::read(connection, "api/credential-vault/status").await?;
    let owner: Owner =
        serde_json::from_value(status["owner"].clone()).map_err(|_| "云凭据库身份格式无效")?;
    if owner != scope.owner {
        return Err("学校账号已切换，请重新读取凭据库".into());
    }
    Ok(status)
}

async fn post(
    connection: &Connection,
    scope: &Scope,
    action: &str,
    mut input: Value,
) -> Result<Value, String> {
    input["owner"] = serde_json::to_value(&scope.owner).map_err(|_| "凭据库身份无效")?;
    school_api::post(
        connection,
        &format!("api/credential-vault/{action}"),
        &input,
    )
    .await
}

fn sync_record(row: &Metadata, profile: Option<Profile>) -> SyncRecord {
    SyncRecord {
        id: row.id.clone(),
        site_origin: row.site_origin.clone(),
        target_hash: row.target_hash.clone(),
        favorite_id: row.favorite_id,
        label: row.label.clone(),
        modified_at: row.modified_at,
        deleted: row.deleted,
        profile,
    }
}

fn validate_record(connection: &Connection, row: &SyncRecord) -> Result<(), String> {
    let url = Url::parse(&row.site_origin).map_err(|_| "同步站点来源无效")?;
    if url.scheme() != "https"
        || !external::allowed(&url)
        || url.origin().ascii_serialization() != row.site_origin
        || url.origin() == connection.origin.origin()
        || !is_hash(&row.target_hash)
        || row.favorite_id == 0
        || row.id
            != school_api::digest(&format!("favorite:{}:{}", row.favorite_id, row.target_hash))
        || row.label.chars().count() > 180
        || row.modified_at == 0
    {
        return Err("云凭据元数据无效，已停止同步".into());
    }
    if row.deleted {
        if row.profile.is_some() {
            return Err("删除记录不能携带认证参数".into());
        }
    } else {
        favorite_auth::validate(row.profile.as_ref().ok_or("云认证配置缺失")?)?;
    }
    Ok(())
}

async fn sync(
    connection: &Connection,
    scope: &Scope,
    index: &mut Index,
    import_new: bool,
) -> Result<Value, String> {
    if !index.sync_enabled {
        return Err("请先明确开启本机云同步密码".into());
    }
    let status = cloud_status(connection, scope).await?;
    if status["available"].as_bool() != Some(true) {
        return Err(status["reason"]
            .as_str()
            .unwrap_or("学校凭据库加密不可用")
            .into());
    }
    if status["enabled"].as_bool() != Some(true) {
        return Err("服务器已停止云同步；请重新确认开关".into());
    }
    let records: Vec<SyncRecord> = index
        .records
        .iter()
        .map(|row| {
            let profile = if row.deleted {
                None
            } else {
                Some(
                    read_profile(&row.key)?
                        .ok_or("索引中的本机凭据缺失；请删除该记录或重新保存")?,
                )
            };
            Ok(sync_record(row, profile))
        })
        .collect::<Result<_, String>>()?;
    let upload = post(connection, scope, "upload", json!({"records":records})).await?;
    let mut key = Zeroizing::new([0u8; 32]);
    OsRng
        .try_fill_bytes(&mut *key)
        .map_err(|_| "无法生成安全传输密钥")?;
    let envelope = post(
        connection,
        scope,
        "download",
        json!({"transport_key":STANDARD.encode(&*key)}),
    )
    .await?;
    if envelope["cipher"].as_str() != Some("AES-256-GCM") || envelope["version"].as_u64() != Some(1)
    {
        return Err("云同步传输协议无效".into());
    }
    let nonce = STANDARD
        .decode(envelope["nonce"].as_str().unwrap_or(""))
        .map_err(|_| "同步响应 nonce 无效")?;
    let tag = STANDARD
        .decode(envelope["tag"].as_str().unwrap_or(""))
        .map_err(|_| "同步响应 tag 无效")?;
    let mut ciphertext = STANDARD
        .decode(envelope["ciphertext"].as_str().unwrap_or(""))
        .map_err(|_| "同步响应密文无效")?;
    if nonce.len() != 12 || tag.len() != 16 {
        return Err("同步响应加密元数据无效".into());
    }
    ciphertext.extend_from_slice(&tag);
    let cipher = Aes256Gcm::new_from_slice(&*key).map_err(|_| "无法初始化同步解密")?;
    let plaintext = Zeroizing::new(
        cipher
            .decrypt(
                Nonce::from_slice(&nonce),
                Payload {
                    msg: &ciphertext,
                    aad: TRANSPORT_AAD,
                },
            )
            .map_err(|_| "云凭据响应无法验证，已停止同步")?,
    );
    let download: Download =
        serde_json::from_slice(&plaintext).map_err(|_| "云凭据响应格式无效")?;
    if download.owner != scope.owner || download.records.len() > LIMIT {
        return Err("云凭据所有者或数量无效".into());
    }
    for row in &download.records {
        validate_record(connection, row)?;
    }
    let mut imported = 0;
    let mut removed = 0;
    for remote in download.records {
        let local = index
            .records
            .iter()
            .find(|row| row.id == remote.id)
            .cloned();
        if local.is_none() && !import_new {
            continue;
        }
        if let Some(local) = &local {
            if local.modified_at > remote.modified_at {
                continue;
            }
            if local.modified_at == remote.modified_at
                && local.deleted == remote.deleted
                && (local.deleted || read_profile(&local.key)? == remote.profile)
            {
                continue;
            }
        }
        let row = Metadata {
            key: school_api::digest(&format!("{}:{}", scope.key, remote.id)),
            id: remote.id,
            site_origin: remote.site_origin,
            target_hash: remote.target_hash,
            favorite_id: remote.favorite_id,
            label: remote.label,
            modified_at: remote.modified_at,
            deleted: remote.deleted,
            legacy_keys: local
                .as_ref()
                .map(|row| row.legacy_keys.clone())
                .unwrap_or_default(),
        };
        if row.deleted {
            erase(&row)?;
            index.records.retain(|old| old.id != row.id);
            index.records.push(row);
            save(scope, index)?;
            removed += 1;
        } else {
            put(
                scope,
                index,
                row,
                remote.profile.as_ref().ok_or("云认证配置缺失")?,
            )?;
            imported += 1;
        }
    }
    Ok(
        json!({"uploaded":upload["uploaded"],"imported":imported,"removed":removed,"count":listing(index)["count"],"enabled":true}),
    )
}

async fn sync_after_local(
    connection: &Connection,
    scope: &Scope,
    index: &mut Index,
    action: &str,
) -> Result<(), String> {
    if index.sync_enabled {
        sync(connection, scope, index, false)
            .await
            .map_err(|error| format!("本机已{action}，云同步失败：{error}"))?;
    }
    Ok(())
}

/// 收藏认证只在原生端读取与保存。
pub async fn profile(connection: &Connection, favorite: &Value) -> Result<Option<Profile>, String> {
    let scope = scope(connection).await?;
    let _guard = LOCAL_LOCK.lock().await;
    let mut index = load(&scope)?;
    migrate_favorite(connection, &scope, &mut index, favorite)?;
    let target = metadata(connection, &scope, favorite)?;
    let row = index
        .records
        .iter()
        .find(|row| row.id == target.id && !row.deleted);
    match row {
        Some(row) => read_profile(&row.key),
        None => Ok(None),
    }
}

pub async fn save_profile(
    connection: &Connection,
    favorite: &Value,
    profile: &Profile,
) -> Result<Value, String> {
    let scope = scope(connection).await?;
    let _guard = LOCAL_LOCK.lock().await;
    let mut index = load(&scope)?;
    migrate_favorite(connection, &scope, &mut index, favorite)?;
    let mut row = metadata(connection, &scope, favorite)?;
    row.modified_at = timestamp(&index);
    put(&scope, &mut index, row, profile)?;
    sync_after_local(connection, &scope, &mut index, "保存").await?;
    Ok(json!({"stored":true,"enabled":index.sync_enabled}))
}

pub async fn clear_favorite(connection: &Connection, favorite: &Value) -> Result<Value, String> {
    let scope = scope(connection).await?;
    let _guard = LOCAL_LOCK.lock().await;
    let mut index = load(&scope)?;
    migrate_favorite(connection, &scope, &mut index, favorite)?;
    let target = metadata(connection, &scope, favorite)?;
    remove_local(&scope, &mut index, Some(&target.id))?;
    sync_after_local(connection, &scope, &mut index, "删除").await?;
    Ok(json!({"stored":false,"enabled":index.sync_enabled}))
}

fn remove_local(scope: &Scope, index: &mut Index, id: Option<&str>) -> Result<usize, String> {
    if id.is_some_and(|id| !is_hash(id)) {
        return Err("凭据编号无效".into());
    }
    let modified_at = timestamp(index);
    let mut removed = 0;
    for i in 0..index.records.len() {
        if id.is_some_and(|id| id != index.records[i].id) {
            continue;
        }
        if index.records[i].deleted {
            continue;
        }
        erase(&index.records[i])?;
        index.records[i].deleted = true;
        index.records[i].modified_at = modified_at;
        index.records[i].legacy_keys.clear();
        save(scope, index)?;
        removed += 1;
    }
    Ok(removed)
}

/// 网页只获取凭据元数据、状态与操作数量。
pub async fn configure(connection: &Connection, input: &Value) -> Result<Value, String> {
    let scope = scope(connection).await?;
    let _guard = LOCAL_LOCK.lock().await;
    let mut index = load(&scope)?;
    match input["action"].as_str().unwrap_or("") {
        "list" => Ok(listing(&index)),
        "status" => {
            let migration_error = migrate_visible(connection, &scope, &mut index).await.err();
            let mut result = listing(&index);
            match cloud_status(connection, &scope).await {
                Ok(cloud) => {
                    result["available"] = cloud["available"].clone();
                    result["cloud_enabled"] = cloud["enabled"].clone();
                    result["cloud_count"] = cloud["count"].clone();
                    result["reason"] = cloud["reason"].clone();
                }
                Err(error) => {
                    result["available"] = json!(false);
                    result["reason"] = json!(error);
                }
            }
            if let Some(error) = migration_error {
                result["migration_error"] = json!(error);
            }
            Ok(result)
        }
        "remove" | "clear" => {
            if input["confirm"].as_bool() != Some(true) {
                return Err("请确认清除本机凭据".into());
            }
            let all = input["action"] == "clear";
            let id = if all {
                None
            } else {
                Some(input["id"].as_str().ok_or("请选择凭据")?)
            };
            let removed = remove_local(&scope, &mut index, id)?;
            sync_after_local(connection, &scope, &mut index, "删除").await?;
            Ok(
                json!({"removed":removed,"count":listing(&index)["count"],"enabled":index.sync_enabled}),
            )
        }
        "settings" => {
            let enabled = input["enabled"].as_bool().ok_or("同步开关无效")?;
            if enabled && input["confirm"].as_bool() != Some(true) {
                return Err("开启前请确认服务器可解密的同步说明".into());
            }
            if !enabled {
                index.sync_enabled = false;
                save(&scope, &index)?;
                post(connection, &scope, "settings", json!({"enabled":false}))
                    .await
                    .map_err(|error| format!("本机已停止同步，服务器开关更新失败：{error}"))?;
                return Ok(json!({"enabled":false}));
            }
            post(connection, &scope, "settings", json!({"enabled":true})).await?;
            index.sync_enabled = true;
            save(&scope, &index)?;
            sync(connection, &scope, &mut index, true).await
        }
        "sync" => sync(connection, &scope, &mut index, true).await,
        "delete-cloud" => {
            if input["confirm"].as_bool() != Some(true) {
                return Err("请确认删除服务器凭据副本".into());
            }
            index.sync_enabled = false;
            save(&scope, &index)?;
            let result = post(connection, &scope, "delete", json!({"confirm":true})).await?;
            Ok(
                json!({"enabled":false,"deleted":result["deleted"],"count":listing(&index)["count"]}),
            )
        }
        _ => Err("凭据库操作无效".into()),
    }
}
