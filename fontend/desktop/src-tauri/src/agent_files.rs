mod attachments;
mod table;

pub(crate) use attachments::{pick_attachments, validate_attachments};
#[cfg(unix)]
use cap_fs_ext::OpenOptionsSyncExt;
use cap_fs_ext::{DirExt, FollowSymlinks, MetadataExt, OpenOptionsFollowExt};
use cap_std::fs::{Dir, File, Metadata, OpenOptions, Permissions};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::{
    ffi::OsStr,
    fs,
    io::{self, Read, Write},
    path::{Component, Path, PathBuf},
};
use tauri::AppHandle;
use tauri_plugin_dialog::DialogExt;

const SOURCE_BYTES: usize = 16 * 1024 * 1024;
const TEXT_BYTES: usize = 24 * 1024;
const WRITE_BYTES: usize = 32 * 1024;
const PAGE_ENTRIES: usize = 100;

pub(crate) fn pick_folder(app: &AppHandle) -> Result<Option<String>, String> {
    let Some(selected) = app
        .dialog()
        .file()
        .set_title("选择 Agent 项目文件夹")
        .blocking_pick_folder()
    else {
        return Ok(None);
    };
    let path = selected.into_path().map_err(|_| "只支持本机文件夹")?;
    let meta = fs::symlink_metadata(&path).map_err(|_| "无法读取所选文件夹")?;
    #[cfg(windows)]
    {
        use std::os::windows::fs::MetadataExt;
        if meta.file_attributes() & 0x400 != 0 {
            return Err("请选择普通文件夹，不能选择重解析点".into());
        }
    }
    if !meta.file_type().is_dir() {
        return Err("请选择普通项目文件夹，不能选择符号链接".into());
    }
    let canonical = path.canonicalize().map_err(|_| "无法读取所选文件夹")?;
    open_directory(&canonical)?;
    Ok(Some(
        canonical
            .to_str()
            .ok_or("项目路径无法用 UTF-8 表示")?
            .to_owned(),
    ))
}

// 每一层使用已打开的目录句柄，拒绝经过符号链接。
fn open_directory(absolute: &Path) -> Result<Dir, String> {
    let root = absolute
        .ancestors()
        .last()
        .filter(|p| p.is_absolute())
        .ok_or("本机项目目录无效")?;
    let mut dir = Dir::open_ambient_dir(root, cap_std::ambient_authority())
        .map_err(|_| "无法打开文件系统目录")?;
    for part in absolute
        .strip_prefix(root)
        .map_err(|_| "目录无效")?
        .components()
    {
        let Component::Normal(name) = part else {
            return Err("目录路径无效".into());
        };
        dir = dir
            .open_dir_nofollow(name)
            .map_err(|_| "目录不存在、不可读或含有链接")?;
        #[cfg(windows)]
        {
            use cap_std::fs::MetadataExt as _;
            if dir
                .dir_metadata()
                .map_err(|_| "无法读取目录状态")?
                .file_attributes()
                & 0x400
                != 0
            {
                return Err("项目路径含有重解析点".into());
            }
        }
    }
    Ok(dir)
}

fn unsafe_name(value: &str) -> bool {
    value.is_empty()
        || value.contains(['/', '\\', ':'])
        || matches!(value, "." | "..")
        || value.chars().any(|ch| {
            ch.is_control() || matches!(ch, '\u{202a}'..='\u{202e}' | '\u{2066}'..='\u{2069}')
        })
        || (cfg!(windows)
            && (value.ends_with([' ', '.']) || {
                let base = value
                    .split('.')
                    .next()
                    .unwrap_or("")
                    .trim_end()
                    .to_ascii_uppercase();
                matches!(
                    base.as_str(),
                    "CON" | "PRN" | "AUX" | "NUL" | "CONIN$" | "CONOUT$"
                ) || base
                    .strip_prefix("COM")
                    .or_else(|| base.strip_prefix("LPT"))
                    .is_some_and(|n| {
                        matches!(
                            n,
                            "0" | "1"
                                | "2"
                                | "3"
                                | "4"
                                | "5"
                                | "6"
                                | "7"
                                | "8"
                                | "9"
                                | "¹"
                                | "²"
                                | "³"
                        )
                    })
            }))
}

fn project_path(root: &str, relative: &str, allow_root: bool) -> Result<PathBuf, String> {
    let base = Path::new(root);
    if !base.is_absolute() {
        return Err("当前会话尚未绑定本机项目目录".into());
    }
    if allow_root && matches!(relative, "" | ".") {
        return Ok(base.into());
    }
    if relative.len() > 1024 || relative.split('/').any(unsafe_name) {
        return Err("请提供项目内的相对路径，以 / 分隔且不含 .、.. 或特殊名称".into());
    }
    Ok(base.join(relative))
}

fn parent_and_name(path: &Path) -> Result<(Dir, &OsStr), String> {
    Ok((
        open_directory(path.parent().ok_or("文件缺少上级目录")?)?,
        path.file_name().ok_or("文件名无效")?,
    ))
}

fn open_regular(dir: &Dir, name: &OsStr) -> io::Result<(File, Metadata)> {
    let non_regular = || io::Error::new(io::ErrorKind::InvalidInput, "只能读取普通文件");
    let entry = dir.symlink_metadata(name)?;
    if !entry.is_file() {
        return Err(non_regular());
    }
    let mut options = OpenOptions::new();
    options.read(true).follow(FollowSymlinks::No);
    #[cfg(unix)]
    options.nonblock(true);
    let file = dir.open_with(name, &options)?;
    let meta = file.metadata()?;
    if !meta.is_file() {
        return Err(non_regular());
    }
    #[cfg(windows)]
    {
        use cap_std::fs::MetadataExt as _;
        if meta.file_attributes() & 0x400 != 0 {
            return Err(non_regular());
        }
    }
    Ok((file, meta))
}

fn file_bytes(file: File, size: u64) -> Result<Vec<u8>, String> {
    if size > SOURCE_BYTES as u64 {
        return Err("单个原文件不能超过 16 MiB".into());
    }
    let mut bytes = Vec::with_capacity(size as usize);
    file.take(SOURCE_BYTES as u64 + 1)
        .read_to_end(&mut bytes)
        .map_err(|_| "无法读取本机文件")?;
    if bytes.len() > SOURCE_BYTES {
        return Err("单个原文件不能超过 16 MiB".into());
    }
    Ok(bytes)
}

pub(crate) fn read(root: &str, params: &Value) -> Result<Value, String> {
    let list = match params["action"].as_str() {
        Some("list") => true,
        Some("read") => false,
        _ => return Err("未知的文件读取操作".into()),
    };
    let relative = match params.get("path") {
        None if list => "",
        Some(Value::String(path)) => path,
        _ => return Err("请提供项目内的文件路径".into()),
    };
    let path = project_path(root, relative, list)?;
    let offset = match params.get("offset") {
        None => 0,
        Some(value) => value
            .as_u64()
            .and_then(|n| usize::try_from(n).ok())
            .ok_or("偏移量无效")?,
    };
    if list {
        let dir = open_directory(&path)?;
        let entries = dir.entries().map_err(|_| "无法列出项目目录")?;
        let mut page = Vec::new();
        for entry in entries.skip(offset).take(PAGE_ENTRIES + 1) {
            if page.len() == PAGE_ENTRIES {
                return Ok(
                    json!({"path":relative,"entries":page,"next_offset":offset + PAGE_ENTRIES}),
                );
            }
            let entry = entry.map_err(|_| "无法读取目录条目")?;
            let name = entry.file_name();
            let kind = entry.file_type().map_err(|_| "无法读取条目类型")?;
            let kind = if name.to_str().is_none_or(unsafe_name) {
                "unsupported"
            } else if kind.is_symlink() {
                "link"
            } else if kind.is_dir() {
                "directory"
            } else if kind.is_file() {
                "file"
            } else {
                "unsupported"
            };
            page.push(json!({"name":name.to_string_lossy(),"kind":kind}));
        }
        return Ok(json!({"path":relative,"entries":page,"next_offset":null}));
    }
    let (dir, name) = parent_and_name(&path)?;
    let (file, meta) = open_regular(&dir, name).map_err(|_| "文件不存在、不可读或不是普通文件")?;
    if meta.nlink() > 1 {
        return Err("项目文件存在多个硬链接，不能通过项目目录读取".into());
    }
    let name = name.to_str().ok_or("文件名无法用 UTF-8 表示")?;
    let extension = attachments::extension(name);
    if attachments::image_extension(&extension) {
        return Ok(
            json!({"path":relative,"kind":"image","message":"请在聊天输入框使用“添加附件”选择这张图片，模型即可读取图像内容。"}),
        );
    }
    let bytes = file_bytes(file, meta.len())?;
    let mut result =
        attachments::document(name, &extension, &bytes, offset, params["sheet"].as_str())?;
    result["path"] = json!(relative);
    Ok(result)
}

#[derive(PartialEq)]
struct FileState {
    id: (u64, u64),
    digest: [u8; 32],
    permissions: Permissions,
}

fn existing_file(dir: &Dir, name: &OsStr) -> Result<Option<FileState>, String> {
    let (file, meta) = match open_regular(dir, name) {
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(None),
        Ok(open) => open,
        Err(_) => return Err("只能覆盖可读取的普通文件，不能覆盖目录、链接或特殊文件".into()),
    };
    if meta.permissions().readonly() || meta.nlink() > 1 {
        return Err("不能覆盖只读文件或有多个硬链接的文件".into());
    }
    let bytes = file_bytes(file, meta.len())?;
    attachments::decode_text(&bytes).map_err(|_| "只能以完整文本覆盖已有文本文件")?;
    Ok(Some(FileState {
        id: (meta.dev(), meta.ino()),
        digest: Sha256::digest(&bytes).into(),
        permissions: meta.permissions(),
    }))
}

pub(crate) struct WriteProposal {
    path: PathBuf,
    parent_id: (u64, u64),
    relative: String,
    content: String,
    prior: Option<FileState>,
}

pub(crate) fn prepare_write(root: &str, params: &Value) -> Result<WriteProposal, String> {
    if params["action"] != "write" {
        return Err("文件写入操作无效".into());
    }
    let relative = params["path"].as_str().ok_or("缺少写入文件的相对路径")?;
    let path = project_path(root, relative, false)?;
    let content = params["content"]
        .as_str()
        .ok_or("请提供完整的 UTF-8 文本")?;
    if content.len() > WRITE_BYTES
        || content
            .chars()
            .any(|c| c.is_control() && !matches!(c, '\n' | '\r' | '\t'))
    {
        return Err("单次写入最多 32 KiB，只能写入可预览的 UTF-8 文本".into());
    }
    let (dir, name) = parent_and_name(&path)?;
    let parent = dir.dir_metadata().map_err(|_| "无法读取目标目录")?;
    let prior = existing_file(&dir, name)?;
    Ok(WriteProposal {
        path,
        parent_id: (parent.dev(), parent.ino()),
        relative: relative.into(),
        content: content.into(),
        prior,
    })
}

impl WriteProposal {
    pub(crate) fn preview(&self) -> String {
        let action = if self.prior.is_some() {
            "覆盖文件"
        } else {
            "新建文件"
        };
        format!(
            "{action}\n路径：{}\n完整新内容（UTF-8，{} 字节）\n\n{}",
            self.path.display(),
            self.content.len(),
            self.content
        )
    }

    pub(crate) fn apply(self, cancelled: impl Fn() -> bool) -> Result<Value, String> {
        if cancelled() {
            return Err("工作区文件写入已取消".into());
        }
        let (dir, name) = parent_and_name(&self.path)?;
        let parent = dir.dir_metadata().map_err(|_| "无法读取目标目录")?;
        if (parent.dev(), parent.ino()) != self.parent_id
            || existing_file(&dir, name)? != self.prior
        {
            return Err("确认期间目录或文件已变化，请重新读取并确认".into());
        }
        let temporary = format!(".practical-agent-{}.tmp", uuid::Uuid::new_v4().simple());
        let mut options = OpenOptions::new();
        options.write(true).create_new(true);
        #[cfg(unix)]
        {
            use cap_std::fs::{OpenOptionsExt, PermissionsExt};
            if let Some(prior) = &self.prior {
                options.mode(prior.permissions.mode() & 0o777);
            }
        }
        let mut file = dir
            .open_with(&temporary, &options)
            .map_err(|_| "无法在目标目录写入文件")?;
        let mut exclusive = false;
        let commit = (|| -> io::Result<()> {
            file.write_all(self.content.as_bytes())?;
            if let Some(prior) = self.prior.as_ref() {
                #[cfg(unix)]
                let permissions = {
                    use cap_std::fs::PermissionsExt;
                    Permissions::from_mode(prior.permissions.mode() & 0o777)
                };
                #[cfg(not(unix))]
                let permissions = prior.permissions.clone();
                file.set_permissions(permissions)?;
            }
            file.sync_all()?;
            drop(file);
            if cancelled() {
                return Err(io::ErrorKind::Interrupted.into());
            }
            if self.prior.is_some() {
                dir.rename(&temporary, &dir, name)
            } else {
                let result = publish_new(&dir, &temporary, name);
                exclusive = result
                    .as_ref()
                    .is_err_and(|error| error.kind() == io::ErrorKind::Unsupported);
                result
            }
        })();
        if let Err(error) = commit {
            let _ = dir.remove_file(&temporary);
            if cancelled() {
                return Err("工作区文件写入已取消".into());
            }
            if !exclusive {
                return Err(if error.kind() == io::ErrorKind::AlreadyExists {
                    "同名文件已出现，请重新读取并确认"
                } else {
                    "无法原子保存项目文件，请检查磁盘和写入权限"
                }
                .into());
            }
            // FAT 等卷缺少原子发布能力，新建时独占名称并同步完整内容。
            let mut destination = dir
                .open_with(name, OpenOptions::new().write(true).create_new(true))
                .map_err(|error| {
                    if error.kind() == io::ErrorKind::AlreadyExists {
                        "同名文件已出现，请重新读取并确认"
                    } else {
                        "无法在目标目录独占创建文件"
                    }
                })?;
            destination
                .write_all(self.content.as_bytes())
                .and_then(|_| destination.sync_all())
                .map_err(|_| {
                    format!(
                        "已新建「{}」，但内容未能完整写入或同步，请检查该文件",
                        self.relative
                    )
                })?;
        }
        Ok(
            json!({"path":self.relative,"written_bytes":self.content.len(),"created":self.prior.is_none(),"atomic_publish":!exclusive}),
        )
    }
}

// 在同一目录内原子发布，目标路径出现时保留其内容。
fn publish_new(dir: &Dir, temporary: &str, name: &OsStr) -> io::Result<()> {
    #[cfg(windows)]
    {
        let directory = winx::file::get_file_path(&dir.try_clone()?.into_std_file())?;
        let mut file = tempfile::TempPath::try_from_path(directory.join(temporary))?;
        file.disable_cleanup(true);
        file.persist_noclobber(directory.join(name))
            .map_err(|error| error.error)
    }
    #[cfg(not(windows))]
    {
        #[cfg(any(target_os = "linux", target_os = "macos"))]
        {
            use rustix::{
                fs::{renameat_with, RenameFlags},
                io::Errno,
            };
            match renameat_with(dir, temporary, dir, name, RenameFlags::NOREPLACE) {
                Ok(()) => return Ok(()),
                Err(code)
                    if [Errno::INVAL, Errno::NOSYS, Errno::NOTSUP, Errno::OPNOTSUPP]
                        .contains(&code) =>
                {
                    ()
                }
                Err(error) => return Err(error.into()),
            }
        }
        if let Err(error) = dir.hard_link(temporary, dir, name) {
            if error.raw_os_error().is_some_and(|code| {
                [libc::EPERM, libc::ENOTSUP, libc::EOPNOTSUPP, libc::ENOSYS].contains(&code)
            }) {
                return Err(io::ErrorKind::Unsupported.into());
            }
            return Err(error);
        }
        let _ = dir.remove_file(temporary);
        Ok(())
    }
}
