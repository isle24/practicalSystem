use crate::{
    agent_profiles::{self, Store},
    connection::Connection,
    school_api,
};
use aes_gcm::{
    aead::{rand_core::RngCore, Aead, KeyInit, OsRng, Payload},
    Aes256Gcm, Nonce,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use serde_json::{json, Value};
use tauri::AppHandle;
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use tokio_util::sync::CancellationToken;
use zeroize::{Zeroize, Zeroizing};

struct Secret(Value);
impl Drop for Secret {
    fn drop(&mut self) {
        agent_profiles::wipe(&mut self.0);
    }
}

async fn download(
    connection: &Connection,
    scope: &Value,
    remote: &Value,
) -> Result<Secret, String> {
    let mut key = Zeroizing::new([0u8; 32]);
    OsRng.fill_bytes(key.as_mut());
    let device = uuid::Uuid::new_v4().to_string();
    let mut body = json!({"device_id":device,"transport_key":STANDARD.encode(key.as_ref()),"profile_id":remote["id"],"revision":remote["revision"],"purpose":"sync"});
    let envelope =
        school_api::client_agent_post(connection, "api/client-agent/personal-download", &body)
            .await;
    if let Some(Value::String(value)) = body.get_mut("transport_key") {
        value.zeroize();
    }
    let envelope = envelope?;
    if envelope["algorithm"] != "AES-256-GCM" {
        return Err("云端配置加密信封格式无效".into());
    }
    let nonce = STANDARD
        .decode(envelope["nonce"].as_str().unwrap_or(""))
        .map_err(|_| "云端配置加密信封无效")?;
    let mut encrypted = STANDARD
        .decode(envelope["ciphertext"].as_str().unwrap_or(""))
        .map_err(|_| "云端配置加密信封无效")?;
    let tag = STANDARD
        .decode(envelope["tag"].as_str().unwrap_or(""))
        .map_err(|_| "云端配置加密信封无效")?;
    if nonce.len() != 12 || tag.len() != 16 || encrypted.len() > 32768 {
        return Err("云端配置加密信封无效".into());
    }
    encrypted.extend_from_slice(&tag);
    let cipher = Aes256Gcm::new_from_slice(key.as_ref()).map_err(|_| "云端配置解密失败")?;
    let clear = Zeroizing::new(
        cipher
            .decrypt(
                Nonce::from_slice(&nonce),
                Payload {
                    msg: &encrypted,
                    aad: b"practical-client-agent:v1",
                },
            )
            .map_err(|_| "云端配置解密失败")?,
    );
    let value = Secret(serde_json::from_slice(&clear).map_err(|_| "云端配置格式无效")?);
    if value.0["scope"] != *scope
        || value.0["device_id"] != device
        || value.0["mode"] != "personal"
        || value.0["profile_id"] != remote["id"]
        || value.0["revision"] != remote["revision"]
    {
        return Err("云端配置不属于当前学校账号或版本已变更".into());
    }
    Ok(value)
}

fn cloud_summary(value: &Value) -> Value {
    json!({"id":value["id"],"name":value["name"],"endpoint":value["endpoint"],"model":value["model"],"enabled":value["enabled"],"has_key":value["has_key"]})
}

async fn confirm(app: &AppHandle, scope: &Value, cancel: &CancellationToken) -> Result<(), String> {
    let (sender, receiver) = tokio::sync::oneshot::channel();
    app.dialog().message(format!("将双向同步个人模型配置及 API Key，并传播已确认的删除。记忆、会话和插件不会上传。\n\n学校：{}\n账号：{}\n\n两端冲突会单独列出，不自动覆盖。",scope["origin"].as_str().unwrap_or(""),scope["account_id"]))
        .title("同步模型配置").buttons(MessageDialogButtons::OkCancel).show(move |accepted|{let _=sender.send(accepted);});
    tokio::select! {biased;_=cancel.cancelled()=>Err("配置同步已取消".into()), result=receiver=>{
        if result.unwrap_or(false) {Ok(())} else {Err("已取消云端同步，本机配置保持原状".into())}
    }}
}

pub async fn sync(
    app: &AppHandle,
    connection: &Connection,
    scope: &Value,
    input: &Value,
    cancel: &CancellationToken,
) -> Result<Value, String> {
    let resolutions = input
        .get("resolutions")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    if resolutions.len() > 100
        || resolutions.iter().any(|item| {
            !matches!(item["choice"].as_str(), Some("local" | "cloud"))
                || item["id"].as_str().is_none()
                || item["local_revision"].as_str().is_none()
                || item["cloud_revision"].as_str().is_none()
        })
    {
        return Err("冲突处理参数无效".into());
    }
    confirm(app, scope, cancel).await?;
    let operation = async {
        let remote = school_api::read(connection, "api/client-agent/bootstrap").await?;
        if remote["scope"] != *scope {
            return Err("云端配置不属于当前学校账号".to_owned());
        }
        let cloud = remote["profiles"]
            .as_array()
            .ok_or("云端服务尚未支持提供方同步，请先更新服务端")?;
        if cloud.len() > 20 {
            return Err("云端提供方数量超过同步上限".into());
        }
        let mut store = Store::open(app, scope)?;
        let mut uploaded = 0;
        let mut downloaded = 0;
        let mut removed = 0;
        let mut conflicts = Vec::new();
        let mut errors = Vec::new();
        let known = store.ids();
        for item in cloud {
            if cancel.is_cancelled() {
                return Err("配置同步已取消；已完成项已保存在本机".into());
            }
            let remote_id = item["id"].as_str().ok_or("云端提供方标识无效")?;
            if remote_id == "legacy" && store.imported_legacy(&item["revision"]) {
                continue;
            }
            if known.iter().any(|id| {
                store
                    .find(id)
                    .is_some_and(|local| local["cloud_id"] == remote_id || local["id"] == remote_id)
            }) {
                continue;
            }
            match download(connection, scope, item)
                .await
                .and_then(|config| store.import(app, None, &config.0))
            {
                Ok(_) => downloaded += 1,
                Err(message) => errors.push(message),
            }
        }
        for id in store.ids() {
            if cancel.is_cancelled() {
                return Err("配置同步已取消；已完成项已保存在本机".into());
            }
            let mut local = Secret(store.find(&id).unwrap().clone());
            let cloud_id = local.0["cloud_id"]
                .as_str()
                .filter(|id| !id.is_empty())
                .unwrap_or(&id)
                .to_owned();
            let current = cloud.iter().find(|item| item["id"] == cloud_id);
            let linked = !local.0["cloud_id"].as_str().unwrap_or("").is_empty();
            let dirty = local.0["revision"] != local.0["synced_revision"];
            let deleted = local.0["deleted"] == true;
            let cloud_revision = current
                .and_then(|item| item["revision"].as_str())
                .unwrap_or("");
            let conflict = match current {
                Some(_) => {
                    (!linked || local.0["cloud_revision"] != cloud_revision)
                        && (dirty || deleted || !linked)
                }
                None => linked && dirty && !deleted && cloud_id != "legacy",
            };
            let choice = resolutions
                .iter()
                .find(|item| {
                    item["id"] == id
                        && item["local_revision"] == local.0["revision"]
                        && item["cloud_revision"] == cloud_revision
                })
                .and_then(|item| item["choice"].as_str());
            if conflict && choice.is_none() {
                conflicts.push(json!({"id":id,"local":if deleted {Value::Null}else{agent_profiles::summary(&local.0)},"cloud":current.map(|item|cloud_summary(item)).unwrap_or(Value::Null),"local_revision":local.0["revision"],"cloud_revision":cloud_revision}));
                continue;
            }
            let pull = choice == Some("cloud")
                || (!dirty
                    && !deleted
                    && current.is_some()
                    && local.0["cloud_revision"] != cloud_revision);
            if pull {
                if let Some(item) = current {
                    match download(connection, scope, item)
                        .await
                        .and_then(|config| store.import(app, Some(&id), &config.0))
                    {
                        Ok(_) => downloaded += 1,
                        Err(message) => errors.push(message),
                    }
                } else {
                    store.remove(&id);
                    store.persist(app)?;
                    removed += 1;
                }
                continue;
            }
            if deleted {
                if let Some(item) = current {
                    if item["id"] == "legacy" {
                        errors.push("旧版兼容配置不支持云端删除，请在原助手设置中管理".into());
                        continue;
                    }
                    let body =
                        json!({"id":item["id"],"revision":item["revision"],"snapshot":false});
                    match school_api::client_agent_post(
                        connection,
                        "api/client-agent/personal-delete",
                        &body,
                    )
                    .await
                    {
                        Ok(result) if result["deleted_profile_id"] == item["id"] => (),
                        Ok(_) => {
                            errors.push("云端未确认删除结果，本机删除记录已保留".into());
                            continue;
                        }
                        Err(message) => {
                            errors.push(message);
                            continue;
                        }
                    }
                }
                store.remove(&id);
                store.persist(app)?;
                removed += 1;
                continue;
            }
            if current.is_none() && linked && !dirty && choice != Some("local") {
                if cloud_id == "legacy" {
                    local.0["cloud_id"] = json!("");
                    local.0["cloud_revision"] = json!("");
                    local.0["synced_revision"] = json!("");
                    store.put(local.0.clone())?;
                    store.persist(app)?;
                } else {
                    store.remove(&id);
                    store.persist(app)?;
                    removed += 1;
                }
                continue;
            }
            if dirty || choice == Some("local") {
                let create = current.is_none() || cloud_id == "legacy";
                let target = if create {
                    id.as_str()
                } else {
                    cloud_id.as_str()
                };
                local.0["cloud_attempted"] = json!(true);
                store.put(local.0.clone())?;
                store.persist(app)?;
                let body = Secret(
                    json!({"id":target,"create":create,"snapshot":false,"revision":if create {""}else{cloud_revision},
                    "name":local.0["name"],"enabled":local.0["enabled"],"endpoint":local.0["endpoint"],"model":local.0["model"],"api_key":local.0["api_key"],"clear_key":local.0["api_key"].as_str()==Some("")}),
                );
                match school_api::client_agent_post(
                    connection,
                    "api/client-agent/personal-save",
                    &body.0,
                )
                .await
                {
                    Ok(result)
                        if result["saved_profile_id"] == target
                            && result["saved_revision"]
                                .as_str()
                                .is_some_and(|value| value.len() == 32) =>
                    {
                        local.0["cloud_id"] = json!(target);
                        local.0["cloud_revision"] = result["saved_revision"].clone();
                        local.0["synced_revision"] = local.0["revision"].clone();
                        store.put(local.0.clone())?;
                        store.persist(app)?;
                        uploaded += 1;
                    }
                    Ok(_) => errors.push(
                        "云端未返回明确保存版本，本机配置已保留；请更新服务端后再次同步".into(),
                    ),
                    Err(message) => errors.push(message),
                }
            }
        }
        let summary=format!("同步完成：上传 {uploaded} 项，下载 {downloaded} 项，删除 {removed} 项，冲突 {} 项，失败 {} 项。{}",conflicts.len(),errors.len(),errors.first().map(|message|format!(" {message}；本机未完成的修改仍保留。")).unwrap_or_default());
        Ok(
            json!({"profiles":store.snapshots(),"conflicts":conflicts,"sync_summary":summary,"school":remote["school"]}),
        )
    };
    tokio::select! {biased;_=cancel.cancelled()=>Err("配置同步已取消；已完成项已保存在本机".into()),result=tokio::time::timeout(std::time::Duration::from_secs(150),operation)=>result.map_err(|_|"配置同步超时；已完成项已保存，未完成项请重新同步")?}
}
