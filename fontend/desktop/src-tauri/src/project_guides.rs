use serde_json::{json, Value};

const MENU_GUIDES: &str =
    include_str!("../../agent/skills/practical-system/references/menu-guides.json");

pub fn query(input: &Value) -> Result<Value, String> {
    let object = input.as_object().ok_or("操作说明参数无效")?;
    if object
        .keys()
        .any(|key| !["action", "key", "keyword", "module", "offset"].contains(&key.as_str()))
    {
        return Err("操作说明包含未支持的参数".into());
    }
    for field in ["action", "key", "keyword", "module"] {
        if object
            .get(field)
            .is_some_and(|value| value.as_str().is_none_or(|text| text.len() > 240))
        {
            return Err("操作说明参数须为有限长度的文本".into());
        }
    }
    let entries: Vec<Value> =
        serde_json::from_str(MENU_GUIDES).map_err(|_| "内置菜单说明格式无效")?;
    match input["action"].as_str().unwrap_or("list") {
        "read" => {
            let key = input["key"]
                .as_str()
                .filter(|key| !key.is_empty())
                .ok_or("请选择菜单说明 key")?;
            let guide = entries
                .into_iter()
                .find(|item| item["key"] == key)
                .ok_or("菜单说明不存在，请先查询索引")?;
            Ok(
                json!({"guide":guide,"source":"builtin","notice":"说明不授予业务权限；实际执行以学校实时能力和当前账号范围为准"}),
            )
        }
        "list" => {
            let keyword = input["keyword"]
                .as_str()
                .unwrap_or("")
                .trim()
                .to_lowercase();
            let module = input["module"].as_str().unwrap_or("");
            let offset = match input.get("offset") {
                None => 0,
                Some(value) => value
                    .as_u64()
                    .filter(|value| *value <= 10000)
                    .ok_or("索引偏移无效")? as usize,
            };
            let filtered: Vec<_> = entries
                .iter()
                .filter(|item| {
                    (module.is_empty() || item["module"] == module)
                        && (keyword.is_empty()
                            || ["key", "title", "purpose"].iter().any(|key| {
                                item[*key]
                                    .as_str()
                                    .unwrap_or("")
                                    .to_lowercase()
                                    .contains(&keyword)
                            }))
                })
                .collect();
            let items: Vec<_> = filtered.iter().skip(offset).take(20).map(|item| json!({"key":item["key"],"module":item["module"],"title":item["title"],"purpose":item["purpose"]})).collect();
            let next = (offset + items.len() < filtered.len()).then_some(offset + items.len());
            Ok(json!({"items":items,"total":filtered.len(),"next_offset":next,"source":"builtin"}))
        }
        _ => Err("操作说明只支持 list 和 read".into()),
    }
}
