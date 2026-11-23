use aes_gcm::aead::{rand_core::RngCore, OsRng};
use std::{
    fs,
    io::{self, ErrorKind, Read, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt},
    path::Path,
};
use tempfile::NamedTempFile;
use zeroize::Zeroizing;

pub fn check_directory(path: &Path) -> io::Result<()> {
    let metadata = fs::symlink_metadata(path)?;
    if !metadata.is_dir() || !is_private(&metadata) {
        return Err(ErrorKind::PermissionDenied.into());
    }
    Ok(())
}

fn is_private(metadata: &fs::Metadata) -> bool {
    metadata.uid() == unsafe { libc::geteuid() } && metadata.mode() & 0o077 == 0
}

pub fn open_private_file(path: &Path) -> io::Result<fs::File> {
    let file = fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(path)?;
    let metadata = file.metadata()?;
    if !metadata.is_file() || !is_private(&metadata) {
        return Err(ErrorKind::PermissionDenied.into());
    }
    Ok(file)
}

pub fn install(path: &Path, key: &[u8; 32]) -> io::Result<()> {
    let directory = path.parent().ok_or(ErrorKind::InvalidInput)?;
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o700)
        .create(directory)?;
    check_directory(directory)?;
    let mut temporary = NamedTempFile::new_in(directory)?;
    temporary.write_all(key)?;
    temporary.as_file().sync_all()?;
    temporary
        .persist_noclobber(path)
        .map_err(|error| error.error)?;
    Ok(())
}

pub fn load(path: &Path, create: bool) -> io::Result<Zeroizing<[u8; 32]>> {
    let directory = path.parent().ok_or(ErrorKind::InvalidInput)?;
    let mut key = Zeroizing::new([0u8; 32]);
    let mut file = match check_directory(directory).and_then(|_| open_private_file(path)) {
        Ok(file) => file,
        Err(error) if create && error.kind() == ErrorKind::NotFound => {
            OsRng
                .try_fill_bytes(key.as_mut())
                .map_err(|_| io::Error::other("无法生成本机随机密钥"))?;
            match install(path, &key) {
                Ok(()) => return Ok(key),
                Err(error) if error.kind() == ErrorKind::AlreadyExists => open_private_file(path)?,
                Err(error) => return Err(error),
            }
        }
        Err(error) => return Err(error),
    };
    if file.metadata()?.len() != 32 {
        return Err(ErrorKind::InvalidData.into());
    }
    file.read_exact(key.as_mut())?;
    Ok(key)
}
