use serde_json::{json, Value};
use std::{
    fs,
    path::{Path, PathBuf},
    process::Stdio,
    time::Duration,
};
use tokio::process::Command;
use tokio_util::sync::CancellationToken;

pub struct ConversionFailure {
    pub message: String,
    pub pending_worker: Option<tokio::process::Child>,
}
impl From<&str> for ConversionFailure {
    fn from(value: &str) -> Self {
        Self {
            message: value.into(),
            pending_worker: None,
        }
    }
}
impl From<String> for ConversionFailure {
    fn from(value: String) -> Self {
        Self {
            message: value,
            pending_worker: None,
        }
    }
}

pub struct Engine {
    pub name: &'static str,
    executable: PathBuf,
}

fn candidate(paths: Vec<PathBuf>) -> Option<PathBuf> {
    paths.into_iter().find(|path| path.is_file())
}

fn libreoffice() -> Option<PathBuf> {
    #[cfg(target_os = "windows")]
    let paths = ["ProgramFiles", "ProgramFiles(x86)"]
        .iter()
        .filter_map(std::env::var_os)
        .map(|path| PathBuf::from(path).join("LibreOffice/program/soffice.com"))
        .collect();
    #[cfg(target_os = "macos")]
    let paths = vec![PathBuf::from(
        "/Applications/LibreOffice.app/Contents/MacOS/soffice",
    )];
    #[cfg(target_os = "linux")]
    let paths = [
        "/usr/bin/libreoffice",
        "/usr/bin/soffice",
        "/usr/local/bin/libreoffice",
        "/opt/libreoffice/program/soffice",
    ]
    .into_iter()
    .map(PathBuf::from)
    .collect();
    candidate(paths)
}

fn word() -> Option<PathBuf> {
    #[cfg(target_os = "windows")]
    {
        let mut paths = Vec::new();
        for root in ["ProgramFiles", "ProgramFiles(x86)"]
            .iter()
            .filter_map(std::env::var_os)
        {
            for directory in [
                "Microsoft Office/root/Office16",
                "Microsoft Office/Office16",
            ] {
                paths.push(PathBuf::from(&root).join(directory).join("WINWORD.EXE"));
            }
        }
        candidate(paths)
    }
    #[cfg(not(target_os = "windows"))]
    {
        None
    }
}

pub fn status() -> Value {
    json!({"word": word().is_some(), "libreoffice": libreoffice().is_some(),
        "word_conversion_platform": "windows", "conversion_format": "docx"})
}

pub fn select(preference: &str) -> Result<Engine, String> {
    let selected = match preference {
        "auto" => word()
            .map(|path| ("word", path))
            .or_else(|| libreoffice().map(|path| ("libreoffice", path))),
        "word" => word().map(|path| ("word", path)),
        "libreoffice" => libreoffice().map(|path| ("libreoffice", path)),
        _ => return Err("本机转换引擎无效".into()),
    };
    selected.map(|(name, executable)| Engine { name, executable }).ok_or_else(|| {
        if preference == "word" && !cfg!(target_os = "windows") {
            "此平台暂不支持 Microsoft Word 自动转换，请安装并选择 LibreOffice，或使用本机 Office 打开".into()
        } else {
            "未检测到可用的本机转换引擎，请安装桌面 Word（Windows）或 LibreOffice，再重新检测".into()
        }
    })
}

impl Engine {
    pub fn fingerprint(&self) -> Result<String, String> {
        let metadata = fs::metadata(&self.executable).map_err(|_| "本机转换引擎不可用")?;
        let modified = metadata
            .modified()
            .ok()
            .and_then(|time| time.duration_since(std::time::UNIX_EPOCH).ok())
            .map_or(0, |duration| duration.as_secs());
        Ok(format!("{}:{}:{modified}", self.name, metadata.len()))
    }
}

fn command(path: &Path) -> Command {
    let mut command = Command::new(path);
    command
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .kill_on_drop(true);
    #[cfg(unix)]
    {
        use std::os::unix::process::CommandExt;
        command.as_std_mut().process_group(0);
    }
    #[cfg(target_os = "windows")]
    command.creation_flags(0x08000000);
    command
}

const LIBRE_PROFILE: &str = r#"<?xml version="1.0" encoding="UTF-8"?>
<oor:items xmlns:oor="http://openoffice.org/2001/registry"><item oor:path="/org.openoffice.Office.Common/Security/Scripting">
<prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop>
<prop oor:name="DisableMacrosExecution" oor:op="fuse"><value>true</value></prop>
<prop oor:name="DisableActiveContent" oor:op="fuse"><value>true</value></prop>
<prop oor:name="BlockUntrustedRefererLinks" oor:op="fuse"><value>true</value></prop>
</item><item oor:path="/org.openoffice.Office.Writer/Content/Update">
<prop oor:name="Link" oor:op="fuse"><value>2</value></prop>
<prop oor:name="Field" oor:op="fuse"><value>false</value></prop>
<prop oor:name="Chart" oor:op="fuse"><value>false</value></prop>
</item></oor:items>"#;

#[cfg(target_os = "windows")]
fn powershell() -> Result<PathBuf, String> {
    let root = std::env::var_os("SystemRoot").ok_or("无法定位 Windows 系统目录")?;
    Ok(PathBuf::from(root).join("System32/WindowsPowerShell/v1.0/powershell.exe"))
}

pub async fn convert(
    engine: &Engine,
    directory: &Path,
    cancel: &CancellationToken,
) -> Result<(), ConversionFailure> {
    let source = directory.join("source.docx");
    let mut process = if engine.name == "word" {
        #[cfg(target_os = "windows")]
        {
            let script = directory.join("convert-word.ps1");
            fs::write(&script, include_bytes!("office_word.ps1"))
                .map_err(|_| "无法准备 Word 转换")?;
            let mut command = command(&powershell()?);
            command
                .args([
                    "-NoProfile",
                    "-NonInteractive",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                ])
                .arg(script)
                .arg("convert")
                .arg(&source)
                .arg(directory.join("source.pdf"))
                .arg(directory.join("word-owner.json"));
            command
        }
        #[cfg(not(target_os = "windows"))]
        {
            return Err("此平台暂不支持 Microsoft Word 自动转换".into());
        }
    } else {
        let profile = directory.join("profile");
        fs::create_dir_all(profile.join("user")).map_err(|_| "无法创建独立转换配置")?;
        fs::write(
            profile.join("user/registrymodifications.xcu"),
            LIBRE_PROFILE,
        )
        .map_err(|_| "无法保存独立转换配置")?;
        let profile_url =
            url::Url::from_directory_path(&profile).map_err(|_| "转换配置地址无效")?;
        let mut command = command(&engine.executable);
        command
            .arg(format!("-env:UserInstallation={profile_url}"))
            .args([
                "--headless",
                "--nologo",
                "--norestore",
                "--convert-to",
                "pdf:writer_pdf_Export",
                "--outdir",
            ])
            .arg(directory)
            .arg(source);
        command
    };
    if cancel.is_cancelled() {
        return Err("本机转换已取消".into());
    }
    if engine.name == "word" {
        process.kill_on_drop(false);
    }
    let mut child = process
        .spawn()
        .map_err(|_| "无法启动本机转换引擎，请检查安装与系统权限")?;
    let outcome = tokio::select! {
        _ = cancel.cancelled() => Err("本机转换已取消".to_owned()),
        result = tokio::time::timeout(Duration::from_secs(120), child.wait()) => match result {
            Ok(Ok(status)) if status.success() => Ok(()),
            Ok(_) => Err("本机转换失败，请检查 Office 是否已激活、文档是否加密或损坏；可手动使用本机 Office 打开".to_owned()),
            Err(_) => Err("本机转换超过 120 秒，已请求取消；可检查 Office 启动状态或手动打开".to_owned()),
        }
    };
    if outcome.is_err() {
        #[cfg(target_os = "windows")]
        if engine.name == "word" {
            let _ = fs::write(directory.join("cancel-word.flag"), b"cancel");
            if child.id().is_some() {
                if tokio::time::timeout(Duration::from_secs(3), child.wait())
                    .await
                    .is_ok()
                {
                    return outcome.map_err(ConversionFailure::from);
                }
                if !directory.join("word-owner.json").is_file() {
                    return Err(ConversionFailure {
                        message: "Word 尚在初始化，已请求取消；初始化返回后将关闭本次实例，客户端不会使用此次结果".into(),
                        pending_worker: Some(child),
                    });
                }
            }
            if let Ok(path) = powershell() {
                let mut cleanup = command(&path);
                cleanup
                    .args([
                        "-NoProfile",
                        "-NonInteractive",
                        "-ExecutionPolicy",
                        "Bypass",
                        "-File",
                    ])
                    .arg(directory.join("convert-word.ps1"))
                    .arg("stop")
                    .args(["", ""])
                    .arg(directory.join("word-owner.json"));
                if let Ok(mut cleanup) = cleanup.spawn() {
                    let _ = tokio::time::timeout(Duration::from_secs(10), cleanup.wait()).await;
                }
            }
        }
        if let Some(pid) = child.id() {
            #[cfg(unix)]
            unsafe {
                libc::kill(-(pid as i32), libc::SIGKILL);
            }
            #[cfg(target_os = "windows")]
            {
                if let Some(root) = std::env::var_os("SystemRoot") {
                    let mut terminate = command(&PathBuf::from(root).join("System32/taskkill.exe"));
                    terminate.args(["/PID", &pid.to_string(), "/T", "/F"]);
                    if let Ok(mut terminate) = terminate.spawn() {
                        let _ =
                            tokio::time::timeout(Duration::from_secs(5), terminate.wait()).await;
                    }
                }
            }
        }
        let _ = child.kill().await;
    }
    outcome.map_err(ConversionFailure::from)
}
