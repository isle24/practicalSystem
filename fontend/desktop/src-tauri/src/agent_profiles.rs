use crate::{agent_models, agent_store};
use serde_json::{json, Value};
use sha1::{Digest, Sha1};
use std::fs::File;
use tauri::AppHandle;
use uuid::Uuid;
use zeroize::Zeroize;

pub struct Store {
    scope: Value,
    data: Value,
    _guard: File,
}

pub fn wipe(value: &mut Value) {
    if let Some(Value::String(key)) = value.get_mut("api_key") {
        key.zeroize();
    }
}

impl Drop for Store {
    fn drop(&mut self) {
        if let Some(items) = self.data["profiles"].as_array_mut() {
            for item in items {
                wipe(item);
            }
        }
    }
}

pub fn summary(item: &Value) -> Value {
    json!({"id":item["id"],"name":item["name"],"endpoint":item["endpoint"],"model":item["model"],
        "enabled":item["enabled"]==true,"has_key":item["api_key"].as_str().is_some_and(|key|!key.is_empty()),
        "revision":item["revision"],"cloud_revision":item["cloud_revision"],
        "pending_sync":item["revision"]!=item["synced_revision"]})
}

fn text(input: &Value, field: &str, limit: usize) -> Result<String, String> {
    let value = input[field].as_str().ok_or("模型配置文本格式无效")?;
    if value.len() > limit || value.chars().any(char::is_control) {
        return Err("模型配置文本超出长度或包含控制字符".into());
    }
    Ok(value.trim().to_owned())
}

fn revision() -> String {
    Uuid::new_v4().simple().to_string()
}

fn validate_config(value: &Value) -> Result<(), String> {
    let endpoint = text(value, "endpoint", 500)?;
    let model = text(value, "model", 160)?;
    let key = value["api_key"].as_str().ok_or("模型密钥格式无效")?;
    if key.len() > 2048 || key.chars().any(char::is_control) {
        return Err("模型密钥格式或长度无效".into());
    }
    if !endpoint.is_empty() {
        agent_models::endpoint(&endpoint)?;
    }
    if value["enabled"].as_bool().is_none() {
        return Err("提供方启用状态无效".into());
    }
    if value["enabled"] == true && (endpoint.is_empty() || model.is_empty() || key.is_empty()) {
        return Err("启用前请填写接口、模型和密钥".into());
    }
    Ok(())
}

impl Store {
    pub fn open(app: &AppHandle, scope: &Value) -> Result<Self, String> {
        let mut scope = scope.clone();
        scope["storage"] = json!("model-profiles:v1");
        let guard = agent_store::acquire(app, &scope)?;
        let mut data = agent_store::read_locked(app, &scope)?;
        if !data["profiles"].is_array() {
            data["profiles"] = json!([]);
        }
        if data["profiles"].as_array().unwrap().len() > 100 {
            return Err("本机提供方记录过多，请完成同步后重试".into());
        }
        Ok(Self {
            scope,
            data,
            _guard: guard,
        })
    }

    pub fn persist(&self, app: &AppHandle) -> Result<(), String> {
        agent_store::write(app, &self.scope, &self.data)
    }

    pub fn ids(&self) -> Vec<String> {
        self.data["profiles"]
            .as_array()
            .unwrap()
            .iter()
            .filter_map(|item| item["id"].as_str().map(str::to_owned))
            .collect()
    }

    pub fn imported_legacy(&self, revision: &Value) -> bool {
        self.data.get("imported_legacy_revision") == Some(revision)
    }

    pub fn find(&self, id: &str) -> Option<&Value> {
        self.data["profiles"]
            .as_array()?
            .iter()
            .find(|item| item["id"] == id)
    }

    pub fn put(&mut self, mut item: Value) -> Result<(), String> {
        let items = self.data["profiles"].as_array_mut().unwrap();
        let index = items.iter().position(|value| value["id"] == item["id"]);
        let adds_active =
            item["deleted"] != true && index.is_none_or(|index| items[index]["deleted"] == true);
        if adds_active && items.iter().filter(|item| item["deleted"] != true).count() >= 20 {
            wipe(&mut item);
            return Err("本机最多保存 20 个提供方，请先删除不需要的配置".into());
        }
        if let Some(previous) = items.iter_mut().find(|value| value["id"] == item["id"]) {
            wipe(previous);
            *previous = item;
        } else {
            if items.len() >= 100 {
                wipe(&mut item);
                return Err("本机最多保存 20 个提供方，请先完成删除项的同步".into());
            }
            items.push(item);
        }
        Ok(())
    }

    pub fn remove(&mut self, id: &str) {
        let items = self.data["profiles"].as_array_mut().unwrap();
        if let Some(index) = items.iter().position(|item| item["id"] == id) {
            let mut item = items.remove(index);
            wipe(&mut item);
        }
    }

    pub fn snapshots(&self) -> Value {
        json!(self.data["profiles"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|item| item["deleted"] != true)
            .map(summary)
            .collect::<Vec<_>>())
    }

    pub fn save(&mut self, app: &AppHandle, input: &Value) -> Result<String, String> {
        let id = input["id"].as_str().unwrap_or("");
        let previous = if id.is_empty() {
            None
        } else {
            Some(self.find(id).ok_or("本机提供方不存在")?)
        };
        if let Some(previous) = previous {
            if previous["deleted"] == true || input["revision"] != previous["revision"] {
                return Err("本机配置已变更，请重新读取后修改".into());
            }
        }
        let mut item = previous.cloned().unwrap_or_else(||json!({"id":Uuid::new_v4().to_string(),"cloud_id":"","cloud_revision":"","synced_revision":"","cloud_attempted":false}));
        let old_endpoint = item["endpoint"].as_str().unwrap_or("").to_owned();
        item["name"] = json!(text(input, "name", 80)?);
        if item["name"].as_str() == Some("") {
            item["name"] = json!("个人模型");
        }
        item["endpoint"] = json!(text(input, "endpoint", 500)?);
        item["model"] = json!(text(input, "model", 160)?);
        let mut key = text(input, "api_key", 2048)?;
        let clear = input["clear_key"].as_bool().unwrap_or(false);
        if !clear
            && key.is_empty()
            && previous.is_some()
            && old_endpoint != item["endpoint"].as_str().unwrap_or("")
        {
            return Err("接口地址已变更，请重新填写 API Key".into());
        }
        if clear {
            wipe(&mut item);
            item["api_key"] = json!("");
        } else if !key.is_empty() {
            wipe(&mut item);
            item["api_key"] = json!(&key);
        } else if item["api_key"].is_null() {
            item["api_key"] = json!("");
        }
        key.zeroize();
        item["enabled"] = json!(!clear && input["enabled"].as_bool().ok_or("启用标记无效")?);
        item["deleted"] = json!(false);
        item["revision"] = json!(revision());
        if let Err(message) = validate_config(&item) {
            wipe(&mut item);
            return Err(message);
        }
        let id = item["id"].as_str().unwrap().to_owned();
        self.put(item)?;
        self.persist(app)?;
        Ok(id)
    }

    pub fn delete(&mut self, app: &AppHandle, input: &Value) -> Result<(), String> {
        let id = input["id"].as_str().ok_or("提供方标识无效")?;
        let previous = self.find(id).ok_or("本机提供方不存在")?;
        if input["revision"] != previous["revision"] {
            return Err("本机配置已变更，请重新读取后删除".into());
        }
        if previous["cloud_id"].as_str().unwrap_or("").is_empty()
            && previous["cloud_attempted"] != true
        {
            self.remove(id);
        } else {
            let mut item = previous.clone();
            wipe(&mut item);
            item["api_key"] = json!("");
            item["deleted"] = json!(true);
            item["revision"] = json!(revision());
            self.put(item)?;
        }
        self.persist(app)
    }

    pub fn resolve(&self, id: &str, require_enabled: bool) -> Result<Value, String> {
        let item = if id.is_empty() {
            self.data["profiles"]
                .as_array()
                .unwrap()
                .iter()
                .find(|item| item["deleted"] != true)
        } else {
            self.find(id).filter(|item| item["deleted"] != true)
        }
        .ok_or("本机模型配置不存在，请添加或同步云端配置")?;
        if require_enabled && item["enabled"] != true {
            return Err("所选本机模型已停用，请重新选择".into());
        }
        validate_config(item)?;
        let mut value = item.clone();
        value["profile_id"] = value["id"].clone();
        Ok(value)
    }

    pub fn import(
        &mut self,
        app: &AppHandle,
        local_id: Option<&str>,
        cloud: &Value,
    ) -> Result<String, String> {
        let remote_id = cloud["profile_id"].as_str().ok_or("云端提供方标识无效")?;
        let id = local_id.map(str::to_owned).unwrap_or_else(|| {
            if Uuid::parse_str(remote_id).is_ok() {
                remote_id.to_owned()
            } else {
                let mut digest = Sha1::new();
                digest.update(Uuid::NAMESPACE_URL.as_bytes());
                digest.update(
                    format!(
                        "practical-system:model-profile-legacy:{}:{}",
                        self.scope, cloud["revision"]
                    )
                    .as_bytes(),
                );
                let digest = digest.finalize();
                let mut bytes = [0u8; 16];
                bytes.copy_from_slice(&digest[..16]);
                bytes[6] = (bytes[6] & 0x0f) | 0x50;
                bytes[8] = (bytes[8] & 0x3f) | 0x80;
                Uuid::from_bytes(bytes).to_string()
            }
        });
        let mut item = json!({"id":id,"name":cloud["name"],"endpoint":cloud["endpoint"],"model":cloud["model"],
            "enabled":cloud["enabled"],"api_key":cloud["api_key"],"cloud_id":remote_id,"cloud_revision":cloud["revision"],
            "cloud_attempted":true,"deleted":false,"revision":revision()});
        item["synced_revision"] = item["revision"].clone();
        if remote_id == "legacy" {
            item["cloud_id"] = json!("");
            item["cloud_revision"] = json!("");
            item["synced_revision"] = json!("");
            item["cloud_attempted"] = json!(false);
        }
        if let Err(message) = validate_config(&item) {
            wipe(&mut item);
            return Err(message);
        }
        self.put(item)?;
        if remote_id == "legacy" {
            self.data["imported_legacy_revision"] = cloud["revision"].clone();
        }
        self.persist(app)?;
        Ok(id)
    }
}
