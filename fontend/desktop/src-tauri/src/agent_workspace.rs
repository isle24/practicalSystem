use serde_json::{json, Value};
use sha1::{Digest, Sha1};
use std::{
    collections::HashSet,
    fs,
    io::Read,
    path::{Component, Path},
    time::{SystemTime, UNIX_EPOCH},
};
use tauri::AppHandle;
use tauri_plugin_dialog::DialogExt;
use uuid::Uuid;

const BUILTIN_ID: &str = "builtin-practical-system";
const MAX_BYTES: usize = 3 * 1024 * 1024;
const SKILL_BYTES: usize = 1024 * 1024;
const BUILTIN_CONTENT: &str = include_str!("../../agent/skills/practical-system/SKILL.md");

fn now() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|n| n.as_secs() as i64)
        .unwrap_or(0)
}

fn id() -> String {
    Uuid::new_v4().to_string()
}

fn conversation() -> Value {
    let timestamp = now();
    json!({"id":id(),"title":"新会话","created_at":timestamp,"updated_at":timestamp,"mode":"school","profile_id":"","model":"","revision":"","messages":[],"history":[]})
}

fn builtin(enabled: bool) -> Value {
    json!({"id":BUILTIN_ID,"type":"skill","name":"实习实践系统","description":"通过受学校授权和本人权限约束的工具访问系统能力。","enabled":enabled,"content":BUILTIN_CONTENT,"resources":[],"builtin":true})
}

pub fn normalize(scope: &Value, stored: Value) -> Value {
    let mut data = if stored["version"] == 2 && stored.is_object() {
        stored
    } else {
        let mut first = conversation();
        let mut digest = Sha1::new();
        digest.update(Uuid::NAMESPACE_URL.as_bytes());
        digest.update(format!("practical-system:agent-legacy:{scope}").as_bytes());
        let digest = digest.finalize();
        let mut bytes = [0u8; 16];
        bytes.copy_from_slice(&digest[..16]);
        bytes[6] = (bytes[6] & 0x0f) | 0x50;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        first["id"] = json!(Uuid::from_bytes(bytes).to_string());
        for field in [
            "messages",
            "history",
            "mode",
            "profile_id",
            "model",
            "revision",
        ] {
            if !stored[field].is_null() {
                first[field] = stored[field].clone();
            }
        }
        json!({"version":2,"conversations":[first]})
    };
    data["version"] = json!(2);
    data["scope"] = scope.clone();
    data.as_object_mut().unwrap().remove("__storage_dirty");
    for field in [
        "conversations",
        "memories",
        "plugins",
        "tasks",
        "reminders",
        "events",
    ] {
        if !data[field].is_array() {
            data[field] = json!([]);
        }
    }
    if data["conversations"]
        .as_array()
        .is_some_and(|items| items.is_empty())
    {
        data["conversations"]
            .as_array_mut()
            .unwrap()
            .push(conversation());
    }
    let active_id = data["active_conversation_id"].as_str().unwrap_or("");
    if !data["conversations"]
        .as_array()
        .unwrap()
        .iter()
        .any(|item| item["id"].as_str() == Some(active_id))
    {
        data["active_conversation_id"] = data["conversations"][0]["id"].clone();
    }
    let plugins = data["plugins"].as_array_mut().unwrap();
    let enabled = plugins
        .iter()
        .find(|item| item["id"] == BUILTIN_ID)
        .and_then(|item| item["enabled"].as_bool())
        .unwrap_or(true);
    plugins.retain(|item| item["id"] != BUILTIN_ID);
    plugins.insert(0, builtin(enabled));
    data
}

pub fn view(data: &Value) -> Value {
    let mut visible = data.clone();
    if let Some(object) = visible.as_object_mut() {
        object.remove("scope");
        object.remove("__imported_scope_keys");
        object.remove("__storage_dirty");
    }
    if let Some(items) = visible["conversations"].as_array_mut() {
        for item in items {
            if let Some(object) = item.as_object_mut() {
                object.remove("messages");
                object.remove("execution_scope");
            }
        }
    }
    if let Some(items) = visible["plugins"].as_array_mut() {
        for item in items {
            if item["type"] == "mcp" {
                let has_token = item["token"].as_str().is_some_and(|s| !s.is_empty());
                let has_env = item["env"].as_object().is_some_and(|v| !v.is_empty());
                if let Some(object) = item.as_object_mut() {
                    object.remove("token");
                    object.remove("env");
                    object.insert("has_token".into(), json!(has_token));
                    object.insert("has_env".into(), json!(has_env));
                }
            }
        }
    }
    visible
}

pub fn active(data: &Value) -> Result<&Value, String> {
    let active_id = data["active_conversation_id"]
        .as_str()
        .ok_or("当前会话无效")?;
    data["conversations"]
        .as_array()
        .ok_or("会话数据无效")?
        .iter()
        .find(|item| item["id"].as_str() == Some(active_id))
        .ok_or("当前会话不存在".into())
}

fn string(item: &Value, field: &str, max: usize, required: bool) -> Result<String, String> {
    let value = match item.get(field) {
        None | Some(Value::Null) => "",
        Some(Value::String(value)) => value,
        _ => return Err(format!("{field} 必须是文本")),
    };
    if value.len() > max || value.contains('\0') || (required && value.trim().is_empty()) {
        return Err(format!("{field} 为空或超出长度限制"));
    }
    Ok(value.to_string())
}

fn enabled(item: &Value, previous: Option<&Value>) -> Result<bool, String> {
    match item.get("enabled") {
        Some(Value::Bool(value)) => Ok(*value),
        None => Ok(previous
            .and_then(|v| v["enabled"].as_bool())
            .unwrap_or(false)),
        _ => Err("enabled 必须是布尔值".into()),
    }
}

fn checked_id(value: &str) -> Result<(), String> {
    if Uuid::parse_str(value).is_err() {
        return Err("项目 ID 无效".into());
    }
    Ok(())
}

fn size(data: &Value) -> Result<(), String> {
    if serde_json::to_vec(data)
        .map_err(|_| "工作区内容无效")?
        .len()
        > MAX_BYTES
    {
        return Err("本机工作区超过 3 MiB，请删除不再需要的会话或插件".into());
    }
    Ok(())
}

pub fn edit(data: &mut Value, input: &Value) -> Result<Value, String> {
    let mut candidate = data.clone();
    let result = edit_inner(&mut candidate, input)?;
    size(&candidate)?;
    *data = candidate;
    Ok(result)
}

fn edit_inner(data: &mut Value, input: &Value) -> Result<Value, String> {
    let section = input["section"].as_str().ok_or("缺少工作区分区")?;
    let operation = input["operation"].as_str().ok_or("缺少工作区操作")?;
    if !["conversations", "memories", "plugins", "tasks", "reminders"].contains(&section) {
        return Err("不支持的工作区分区".into());
    }
    let item = input.get("item").cloned().unwrap_or(json!({}));
    if !item.is_object() {
        return Err("项目必须是对象".into());
    }
    for value in [input.get("id"), item.get("id")].into_iter().flatten() {
        if !value.is_null() && !value.is_string() {
            return Err("项目 ID 必须是文本".into());
        }
    }
    if let (Some(outer), Some(inner)) = (input["id"].as_str(), item["id"].as_str()) {
        if !outer.is_empty() && !inner.is_empty() && outer != inner {
            return Err("项目 ID 不一致".into());
        }
    }
    let supplied_id = input["id"]
        .as_str()
        .or_else(|| item["id"].as_str())
        .unwrap_or("");
    let item_id = if supplied_id.is_empty() && operation == "save" {
        id()
    } else {
        supplied_id.to_string()
    };
    if item_id == BUILTIN_ID && section != "plugins" {
        return Err("内置 Skill ID 不可用于其他分区".into());
    }
    if item_id != BUILTIN_ID {
        checked_id(&item_id)?;
    }
    let list = data[section].as_array().ok_or("工作区分区无效")?;
    let position = list.iter().position(|value| value["id"] == item_id);
    let previous = position.map(|index| list[index].clone());
    if operation != "save" && position.is_none() {
        return Err("项目不存在".into());
    }
    if operation == "select" {
        if section != "conversations" {
            return Err("仅会话支持选择操作".into());
        }
        data["active_conversation_id"] = json!(item_id);
        return Ok(json!({"section":section,"operation":operation,"id":item_id}));
    }
    if operation == "delete" {
        if item_id == BUILTIN_ID {
            return Err("内置 Skill 不能删除".into());
        }
        if previous
            .as_ref()
            .is_some_and(|value| value["last_status"] == "running")
        {
            return Err("正在执行的项目不能删除".into());
        }
        data[section]
            .as_array_mut()
            .unwrap()
            .remove(position.unwrap());
        if section == "conversations" {
            if data[section].as_array().unwrap().is_empty() {
                data[section].as_array_mut().unwrap().push(conversation());
            }
            if data["active_conversation_id"] == item_id {
                data["active_conversation_id"] = data[section][0]["id"].clone();
            }
        }
        return Ok(json!({"section":section,"operation":operation,"id":item_id}));
    }
    if operation == "run" || operation == "skip" {
        if section != "tasks" && section != "reminders" {
            return Err("此项目不支持执行操作".into());
        }
        let mut value = previous.unwrap();
        if value["last_status"] == "running" {
            return Err("此项目正在执行".into());
        }
        let timestamp = now();
        value["last_status"] = json!(if operation == "run" {
            "running"
        } else {
            "skipped"
        });
        value["last_run_at"] = json!(timestamp);
        value["missed_count"] = json!(0);
        if value["next_run_at"]
            .as_i64()
            .is_some_and(|next| next <= timestamp)
        {
            advance(&mut value, timestamp);
        }
        data[section][position.unwrap()] = value.clone();
        event(
            data,
            section,
            &value,
            if operation == "run" {
                "running"
            } else {
                "skipped"
            },
            timestamp,
        );
        value["kind"] = json!(if section == "tasks" {
            "task"
        } else {
            "reminder"
        });
        return Ok(json!({"section":section,"operation":operation,"id":item_id,"item":value}));
    }
    if operation != "save" {
        return Err("不支持的工作区操作".into());
    }
    let limit = match section {
        "conversations" => 30,
        "plugins" => 30,
        _ => 100,
    };
    if position.is_none() && list.len() >= limit {
        return Err(format!("此分区最多保存 {limit} 项"));
    }
    let mut value = match section {
        "conversations" => {
            let mut value = previous.clone().unwrap_or_else(conversation);
            let title = string(&item, "title", 240, false)?;
            if !title.trim().is_empty() {
                value["title"] = json!(title);
            }
            value["updated_at"] = json!(now());
            value
        }
        "memories" => {
            json!({"title":string(&item,"title",240,true)?,"content":string(&item,"content",16000,true)?,"enabled":enabled(&item,previous.as_ref())?,"updated_at":now()})
        }
        "plugins" => plugin(&item, previous.as_ref(), &item_id)?,
        "tasks" | "reminders" => schedule(&item, previous.as_ref(), section == "tasks")?,
        _ => unreachable!(),
    };
    value["id"] = json!(item_id);
    if let Some(index) = position {
        data[section][index] = value;
    } else {
        data[section].as_array_mut().unwrap().push(value);
    }
    if section == "conversations" && position.is_none() {
        data["active_conversation_id"] = json!(item_id);
    }
    let visible = view(data);
    let saved = visible[section]
        .as_array()
        .unwrap()
        .iter()
        .find(|value| value["id"] == item_id)
        .cloned();
    Ok(json!({"section":section,"operation":operation,"id":item_id,"item":saved}))
}

fn plugin(item: &Value, previous: Option<&Value>, item_id: &str) -> Result<Value, String> {
    if item_id == BUILTIN_ID {
        let previous = previous.ok_or("内置 Skill 不存在")?;
        for (field, value) in item.as_object().unwrap() {
            if !["id", "enabled"].contains(&field.as_str()) && previous.get(field) != Some(value) {
                return Err("内置 Skill 仅允许修改启用状态".into());
            }
        }
        return Ok(builtin(enabled(item, Some(previous))?));
    }
    if item["builtin"].as_bool().unwrap_or(false) {
        return Err("不能创建内置 Skill".into());
    }
    let kind = item["type"].as_str().ok_or("缺少插件类型")?;
    if previous.is_some_and(|v| v["type"] != kind) {
        return Err("不能修改插件类型".into());
    }
    let name = string(item, "name", 240, true)?;
    let is_enabled = enabled(item, previous)?;
    match kind {
        "skill" => {
            let content = string(item, "content", SKILL_BYTES, true)?;
            let resources = item.get("resources").cloned().unwrap_or(json!([]));
            validate_resources(&content, &resources)?;
            Ok(
                json!({"type":"skill","name":name,"description":string(item,"description",2000,false)?,"enabled":is_enabled,"content":content,"resources":resources,"builtin":false}),
            )
        }
        "mcp" => {
            let transport = item["transport"].as_str().ok_or("缺少 MCP 传输类型")?;
            let mut value = json!({"type":"mcp","name":name,"enabled":is_enabled,"transport":transport,"url":"","token":"","command":"","args":[],"env":{}});
            let same;
            match transport {
                "http" => {
                    let raw = string(item, "url", 4096, true)?;
                    let url = url::Url::parse(&raw).map_err(|_| "MCP URL 无效")?;
                    if !["http", "https"].contains(&url.scheme())
                        || url.host_str().is_none()
                        || !url.username().is_empty()
                        || url.password().is_some()
                        || url.query().is_some()
                        || url.fragment().is_some()
                        || raw.chars().any(char::is_control)
                    {
                        return Err(
                            "MCP URL 必须是无认证信息、查询参数和片段的 HTTP/HTTPS 地址".into()
                        );
                    }
                    same = previous.is_some_and(|v| v["transport"] == transport && v["url"] == raw);
                    value["url"] = json!(raw);
                    let token = string(item, "token", 8192, false)?;
                    if token.chars().any(char::is_control) {
                        return Err("MCP Token 含无效字符".into());
                    }
                    value["token"] = if token.is_empty() && same && item["clear_token"] != true {
                        previous.unwrap()["token"].clone()
                    } else {
                        json!(token)
                    };
                }
                "stdio" => {
                    let command = string(item, "command", 4096, true)?;
                    if !Path::new(&command).is_absolute() || command.chars().any(char::is_control) {
                        return Err("MCP 命令必须是绝对路径且不能包含控制字符".into());
                    }
                    let args = item.get("args").cloned().unwrap_or(json!([]));
                    let args_list = args.as_array().ok_or("MCP 参数必须是文本数组")?;
                    if args_list.len() > 64
                        || args_list.iter().any(|v| {
                            v.as_str()
                                .is_none_or(|s| s.len() > 4096 || s.chars().any(char::is_control))
                        })
                    {
                        return Err("MCP 参数无效或过多".into());
                    }
                    same = previous.is_some_and(|v| {
                        v["transport"] == transport && v["command"] == command && v["args"] == args
                    });
                    value["command"] = json!(command);
                    value["args"] = args;
                }
                _ => return Err("MCP 仅支持 http 或 stdio".into()),
            }
            if transport == "stdio" {
                let env = match item.get("env") {
                    Some(value) => value.clone(),
                    None if same && item["clear_env"] != true => previous.unwrap()["env"].clone(),
                    None => json!({}),
                };
                let object = env.as_object().ok_or("MCP 环境变量必须是对象")?;
                if object.len() > 64
                    || object.iter().any(|(key, value)| {
                        key.is_empty()
                            || key.len() > 128
                            || !key.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
                            || value
                                .as_str()
                                .is_none_or(|s| s.len() > 8192 || s.contains('\0'))
                    })
                {
                    return Err("MCP 环境变量无效或超出限制".into());
                }
                value["env"] = env;
            }
            Ok(value)
        }
        _ => Err("不支持的插件类型".into()),
    }
}

fn schedule(item: &Value, previous: Option<&Value>, task: bool) -> Result<Value, String> {
    if previous.is_some_and(|v| v["last_status"] == "running") {
        return Err("正在执行的项目不能修改".into());
    }
    let schedule = item["schedule"].as_str().ok_or("缺少计划类型")?;
    if !["once", "interval"].contains(&schedule) {
        return Err("计划类型无效".into());
    }
    let next = item["next_run_at"]
        .as_i64()
        .filter(|n| *n > 0 && *n < 253402300800)
        .ok_or("执行时间无效")?;
    let interval = if schedule == "interval" {
        item["interval_minutes"]
            .as_i64()
            .filter(|n| *n >= 5 && *n <= 525600)
            .ok_or("重复间隔必须为 5 分钟至 1 年")?
    } else {
        0
    };
    let mut value = json!({"name":string(item,"name",240,true)?,"enabled":enabled(item,previous)?,"schedule":schedule,"next_run_at":next,"interval_minutes":interval,"last_status":previous.map(|v|v["last_status"].clone()).unwrap_or(json!("pending")),"last_run_at":previous.map(|v|v["last_run_at"].clone()).unwrap_or(json!(0)),"missed_count":previous.map(|v|v["missed_count"].clone()).unwrap_or(json!(0))});
    if task {
        let mode = item["mode"].as_str().unwrap_or("school");
        if !["school", "personal"].contains(&mode) {
            return Err("任务模型来源无效".into());
        }
        let profile = string(item, "profile_id", 80, false)?;
        if !profile.is_empty() && profile != "legacy" {
            checked_id(&profile)?;
        }
        value["prompt"] = json!(string(item, "prompt", 16000, true)?);
        value["mode"] = json!(mode);
        value["profile_id"] = json!(profile);
        value["model"] = json!(string(item, "model", 240, false)?);
    } else {
        value["content"] = json!(string(item, "content", 16000, true)?);
    }
    Ok(value)
}

#[allow(clippy::too_many_arguments)]
pub fn complete(
    data: &mut Value,
    conversation_id: &str,
    mode: &str,
    profile_id: &str,
    model: &str,
    revision: &str,
    messages: Value,
    history: Value,
) -> Result<(), String> {
    if !messages.is_array() || !history.is_array() {
        return Err("会话消息格式无效".into());
    }
    let mut candidate = data.clone();
    let value = candidate["conversations"]
        .as_array_mut()
        .ok_or("会话数据无效")?
        .iter_mut()
        .find(|item| item["id"] == conversation_id)
        .ok_or("会话已删除")?;
    value["mode"] = json!(mode);
    value["profile_id"] = json!(profile_id);
    value["model"] = json!(model);
    value["revision"] = json!(revision);
    value["messages"] = messages;
    if value["title"] == "新会话" {
        if let Some(text) = history
            .as_array()
            .unwrap()
            .iter()
            .filter(|item| item["role"] == "user")
            .filter_map(|item| item["text"].as_str().or_else(|| item["content"].as_str()))
            .find(|text| !text.trim().is_empty())
        {
            value["title"] = json!(text
                .split_whitespace()
                .collect::<Vec<_>>()
                .join(" ")
                .chars()
                .take(30)
                .collect::<String>());
        }
    }
    let mut history = history.as_array().unwrap().clone();
    if history.len() > 60 {
        history.drain(..history.len() - 60);
    }
    value["history"] = json!(history);
    value["updated_at"] = json!(now());
    size(&candidate)?;
    *data = candidate;
    Ok(())
}

fn append_context(output: &mut String, text: &str, limit: usize) {
    if output.len() >= limit {
        return;
    }
    let remaining = limit - output.len();
    let mut end = text.len().min(remaining);
    while !text.is_char_boundary(end) {
        end -= 1;
    }
    output.push_str(&text[..end]);
}

pub fn context(data: &Value) -> Value {
    let mut memories = String::new();
    let mut skills = String::new();
    for item in data["memories"]
        .as_array()
        .into_iter()
        .flatten()
        .filter(|item| item["enabled"] == true)
    {
        let text = format!(
            "\n{}\n{}\n",
            item["title"].as_str().unwrap_or(""),
            item["content"].as_str().unwrap_or("")
        );
        append_context(&mut memories, &text, 16 * 1024);
    }
    for item in data["plugins"]
        .as_array()
        .into_iter()
        .flatten()
        .filter(|item| item["type"] == "skill" && item["enabled"] == true)
    {
        let text = format!(
            "\n{}\n{}\n{}\n",
            item["name"].as_str().unwrap_or(""),
            item["description"].as_str().unwrap_or(""),
            item["content"].as_str().unwrap_or("")
        );
        append_context(&mut skills, &text, 16 * 1024);
        for resource in item["resources"].as_array().into_iter().flatten() {
            let text = format!(
                "\n{}\n{}\n",
                resource["path"].as_str().unwrap_or(""),
                resource["content"].as_str().unwrap_or("")
            );
            append_context(&mut skills, &text, 16 * 1024);
        }
    }
    json!({"memories":memories,"skills":skills})
}

fn advance(item: &mut Value, timestamp: i64) {
    if item["schedule"] == "interval" {
        let interval = item["interval_minutes"]
            .as_i64()
            .unwrap_or(5)
            .max(5)
            .saturating_mul(60);
        let previous = item["next_run_at"].as_i64().unwrap_or(timestamp);
        let count = timestamp.saturating_sub(previous).max(0) / interval + 1;
        item["next_run_at"] = json!(previous.saturating_add(count.saturating_mul(interval)));
    } else {
        item["enabled"] = json!(false);
    }
}

fn trim_events(data: &mut Value) {
    if let Some(events) = data["events"].as_array_mut() {
        if events.len() > 100 {
            events.drain(..events.len() - 100);
        }
    }
}

fn event(data: &mut Value, section: &str, item: &Value, status: &str, timestamp: i64) {
    if !data["events"].is_array() {
        data["events"] = json!([]);
    }
    data["events"].as_array_mut().unwrap().push(json!({"id":id(),"kind":if section == "tasks" {"task"} else {"reminder"},"item_id":item["id"],"name":item["name"],"status":status,"created_at":timestamp}));
    trim_events(data);
}

pub fn due(data: &mut Value, timestamp: i64, interrupted: bool) -> Vec<Value> {
    let mut ready = Vec::new();
    for section in ["tasks", "reminders"] {
        let count = data[section].as_array().map(Vec::len).unwrap_or(0);
        for index in 0..count {
            let mut item = data[section][index].clone();
            if item["last_status"] == "running" {
                if interrupted {
                    item["last_status"] = json!("missed");
                    item["missed_count"] =
                        json!(item["missed_count"].as_u64().unwrap_or(0).saturating_add(1));
                    data[section][index] = item.clone();
                    event(data, section, &item, "missed", timestamp);
                }
                continue;
            }
            if item["enabled"] != true
                || item["next_run_at"]
                    .as_i64()
                    .is_none_or(|n| n <= 0 || n > timestamp)
            {
                continue;
            }
            let missed = interrupted
                || timestamp.saturating_sub(item["next_run_at"].as_i64().unwrap_or(timestamp)) > 20;
            item["last_run_at"] = json!(timestamp);
            item["last_status"] = json!(if missed { "missed" } else { "running" });
            if missed {
                item["missed_count"] =
                    json!(item["missed_count"].as_u64().unwrap_or(0).saturating_add(1));
            }
            advance(&mut item, timestamp);
            data[section][index] = item.clone();
            event(
                data,
                section,
                &item,
                if missed { "missed" } else { "running" },
                timestamp,
            );
            if !missed {
                item["kind"] = json!(if section == "tasks" {
                    "task"
                } else {
                    "reminder"
                });
                ready.push(item);
            }
        }
    }
    ready
}

pub fn finish(
    data: &mut Value,
    kind: &str,
    item_id: &str,
    status: &str,
    timestamp: i64,
) -> Result<(), String> {
    let section = match kind {
        "task" => "tasks",
        "reminder" => "reminders",
        _ => return Err("计划项目类型无效".into()),
    };
    if ![
        "completed",
        "failed",
        "done",
        "error",
        "cancelled",
        "missed",
    ]
    .contains(&status)
    {
        return Err("计划执行状态无效".into());
    }
    let item = data[section]
        .as_array_mut()
        .ok_or("计划数据无效")?
        .iter_mut()
        .find(|v| v["id"] == item_id)
        .ok_or("计划项目不存在")?;
    if item["last_status"] != "running" {
        return Err("计划项目未在执行".into());
    }
    item["last_status"] = json!(status);
    if status == "missed" {
        item["missed_count"] = json!(item["missed_count"].as_u64().unwrap_or(0).saturating_add(1));
    }
    item["last_run_at"] = json!(timestamp);
    let item = item.clone();
    event(data, section, &item, status, timestamp);
    Ok(())
}

fn resource_path(raw: &str) -> Result<(), String> {
    if raw.is_empty()
        || raw.len() > 500
        || raw.contains('\\')
        || raw.contains(':')
        || raw
            .split('/')
            .any(|part| part.is_empty() || part == "." || part == "..")
        || raw.chars().any(char::is_control)
        || Path::new(raw)
            .components()
            .any(|p| !matches!(p, Component::Normal(_)))
    {
        return Err("Skill 资源路径无效".into());
    }
    let extension = Path::new(raw)
        .extension()
        .and_then(|v| v.to_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    if ![
        "md",
        "txt",
        "json",
        "yaml",
        "yml",
        "toml",
        "csv",
        "tsv",
        "xml",
        "rst",
        "ini",
        "cfg",
        "properties",
    ]
    .contains(&extension.as_str())
    {
        return Err("Skill 只允许指令和文本资源，不能包含脚本或二进制文件".into());
    }
    Ok(())
}

fn validate_resources(content: &str, resources: &Value) -> Result<(), String> {
    let resources = resources.as_array().ok_or("Skill 资源必须是数组")?;
    if resources.len() > 63 {
        return Err("Skill 最多包含 64 个文本文件".into());
    }
    let mut bytes = content.len();
    let mut paths = HashSet::new();
    for resource in resources {
        let path = string(resource, "path", 500, true)?;
        resource_path(&path)?;
        if !paths.insert(path) {
            return Err("Skill 资源路径重复".into());
        }
        bytes = bytes.saturating_add(string(resource, "content", SKILL_BYTES, false)?.len());
    }
    if bytes > SKILL_BYTES {
        return Err("Skill 文本总量不得超过 1 MiB".into());
    }
    Ok(())
}

pub fn import_skill(app: &AppHandle) -> Result<Value, String> {
    let selected = app
        .dialog()
        .file()
        .add_filter("Skill", &["md", "zip"])
        .blocking_pick_file()
        .ok_or("已取消 Skill 导入")?;
    let path = selected.into_path().map_err(|_| "只支持本机 Skill 文件")?;
    let metadata = fs::symlink_metadata(&path).map_err(|_| "无法读取 Skill 文件")?;
    if !metadata.is_file()
        || metadata.file_type().is_symlink()
        || metadata.len() > SKILL_BYTES as u64
    {
        return Err("Skill 文件必须是最多 1 MiB 的普通文件".into());
    }
    let extension = path
        .extension()
        .and_then(|v| v.to_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    let (content, resources) = match extension.as_str() {
        "md" => (
            fs::read_to_string(&path).map_err(|_| "Skill 必须使用 UTF-8 编码")?,
            json!([]),
        ),
        "zip" => read_skill_zip(&path)?,
        _ => return Err("仅支持 .md 或 .zip Skill 文件".into()),
    };
    validate_resources(&content, &resources)?;
    let name = path
        .file_stem()
        .and_then(|v| v.to_str())
        .unwrap_or("导入 Skill")
        .chars()
        .take(80)
        .collect::<String>();
    Ok(
        json!({"id":id(),"type":"skill","name":name,"description":"本机导入的指令与文本资源","enabled":false,"content":content,"resources":resources,"builtin":false}),
    )
}

fn read_skill_zip(path: &Path) -> Result<(String, Value), String> {
    let file = fs::File::open(path).map_err(|_| "无法读取 Skill 压缩包")?;
    let mut archive = zip::ZipArchive::new(file).map_err(|_| "Skill ZIP 格式无效")?;
    if archive.len() > 128 {
        return Err("Skill ZIP 条目过多".into());
    }
    let mut files = Vec::new();
    let mut names = HashSet::new();
    let mut total = 0usize;
    for index in 0..archive.len() {
        let mut entry = archive
            .by_index(index)
            .map_err(|_| "无法读取 Skill ZIP 条目")?;
        if entry.unix_mode().is_some_and(|mode| {
            mode & 0o170000 != 0 && mode & 0o170000 != 0o100000 && mode & 0o170000 != 0o040000
        }) {
            return Err("Skill ZIP 不允许符号链接或特殊文件".into());
        }
        let raw = entry.name().to_string();
        if entry.is_dir() {
            let dir = raw.trim_end_matches('/');
            if dir.is_empty()
                || dir.contains('\\')
                || dir.contains(':')
                || dir.chars().any(char::is_control)
                || Path::new(dir)
                    .components()
                    .any(|p| !matches!(p, Component::Normal(_)))
            {
                return Err("Skill ZIP 目录无效".into());
            }
            continue;
        }
        resource_path(&raw)?;
        if !names.insert(raw.clone()) {
            return Err("Skill ZIP 路径重复".into());
        }
        if files.len() >= 64
            || entry.size() > SKILL_BYTES as u64
            || (entry.compressed_size() > 0 && entry.size() / entry.compressed_size().max(1) > 200)
        {
            return Err("Skill ZIP 超出文件数量、大小或压缩比限制".into());
        }
        let mut bytes = Vec::new();
        (&mut entry)
            .take((SKILL_BYTES - total + 1) as u64)
            .read_to_end(&mut bytes)
            .map_err(|_| "无法读取 Skill 文本")?;
        total = total.saturating_add(bytes.len());
        if total > SKILL_BYTES {
            return Err("Skill 文本总量不得超过 1 MiB".into());
        }
        let content = String::from_utf8(bytes).map_err(|_| "Skill 文本必须使用 UTF-8 编码")?;
        if content.contains('\0') {
            return Err("Skill 不允许二进制内容".into());
        }
        files.push((raw, content));
    }
    let candidates: Vec<usize> = files
        .iter()
        .enumerate()
        .filter(|(_, (name, _))| {
            Path::new(name)
                .file_name()
                .and_then(|v| v.to_str())
                .is_some_and(|v| v.eq_ignore_ascii_case("SKILL.md"))
        })
        .map(|(index, _)| index)
        .collect();
    if candidates.len() != 1 {
        return Err("Skill ZIP 必须且只能包含一个 SKILL.md".into());
    }
    let (_, content) = files.remove(candidates[0]);
    let resources = files
        .into_iter()
        .map(|(path, content)| json!({"path":path,"content":content}))
        .collect::<Vec<_>>();
    Ok((content, json!(resources)))
}
