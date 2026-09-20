use crate::connection::Connection;
use serde_json::Value;
use sha2::{Digest, Sha256};

/// 从当前学校会话读取小型鉴权响应。
pub async fn read(connection: &Connection, path: &str) -> Result<Value, String> {
    let target = connection
        .origin
        .join(path)
        .map_err(|_| "学校接口地址无效")?;
    let mut response = connection
        .client
        .get(target)
        .timeout(std::time::Duration::from_secs(15))
        .send()
        .await
        .map_err(|_| "学校接口连接失败，请检查网络")?;
    let status = response.status();
    let mut bytes = Vec::new();
    while let Some(chunk) = response.chunk().await.map_err(|_| "读取学校信息失败")? {
        if bytes.len() + chunk.len() > 1024 * 1024 {
            return Err("学校响应过大".into());
        }
        bytes.extend_from_slice(&chunk);
    }
    let value: Value = serde_json::from_slice(&bytes).map_err(|_| "学校接口格式不正确")?;
    if !status.is_success() || value["code"].as_i64() != Some(0) {
        return Err(value["message"]
            .as_str()
            .unwrap_or("学校权限校验失败")
            .to_owned());
    }
    Ok(value["data"].clone())
}

/// 获取服务器确认的当前账号，禁止使用前端自报账号。
pub async fn account(connection: &Connection) -> Result<u64, String> {
    let context = read(connection, "api/auth/context").await?;
    let id = context["account_id"].as_u64().unwrap_or(0);
    if id == 0 {
        return Err("请先登录学校账号".into());
    }
    Ok(id)
}

/// 生成本机资源命名键，不在文件名暴露学校和账号。
pub fn digest(value: &str) -> String {
    format!("{:x}", Sha256::digest(value.as_bytes()))
}
