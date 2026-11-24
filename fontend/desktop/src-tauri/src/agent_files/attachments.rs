use super::{file_bytes, open_directory, open_regular, table, unsafe_name, TEXT_BYTES};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine};
use encoding_rs::{Encoding, GB18030};
use image::{
    codecs::jpeg::JpegEncoder, imageops, DynamicImage, ImageDecoder, ImageFormat, ImageReader,
    Limits,
};
use serde_json::{json, Value};
use std::{borrow::Cow, io::Cursor, path::Path};
use tauri::AppHandle;
use tauri_plugin_dialog::DialogExt;

const MAX_FILES: usize = 4;
const MESSAGE_BYTES: usize = 192 * 1024;
const IMAGE_BYTES: usize = 88 * 1024;
const IMAGE_EDGE: u32 = 1280;
const INPUT_PIXELS: u64 = 32_000_000;

pub(super) fn extension(name: &str) -> String {
    Path::new(name)
        .extension()
        .and_then(|ext| ext.to_str())
        .unwrap_or("")
        .to_ascii_lowercase()
}

pub(super) fn image_extension(ext: &str) -> bool {
    matches!(ext, "jpg" | "jpeg" | "png" | "webp")
}

pub(super) fn prefix(value: &str, max_bytes: usize) -> &str {
    let mut end = max_bytes.min(value.len());
    while !value.is_char_boundary(end) {
        end -= 1;
    }
    &value[..end]
}

pub(super) fn decode_text(bytes: &[u8]) -> Result<Cow<'_, str>, String> {
    let text = if let Some((encoding, bom)) = Encoding::for_bom(bytes) {
        encoding.decode_without_bom_handling_and_without_replacement(&bytes[bom..])
    } else if let Ok(utf8) = std::str::from_utf8(bytes) {
        Some(Cow::Borrowed(utf8))
    } else {
        GB18030.decode_without_bom_handling_and_without_replacement(bytes)
    }
    .ok_or("文件不是可读文本，支持 UTF-8、带 BOM 的 UTF-16 和 GB18030 / GBK")?;
    if text
        .chars()
        .any(|c| c.is_control() && !matches!(c, '\n' | '\r' | '\t' | '\u{0c}'))
    {
        return Err("文件含二进制内容，无法作为文本读取".into());
    }
    Ok(text)
}

pub(super) fn document(
    name: &str,
    ext: &str,
    bytes: &[u8],
    offset: usize,
    sheet: Option<&str>,
) -> Result<Value, String> {
    match ext {
        "xlsx" => table::xlsx(name, bytes, offset, sheet),
        "csv" | "tsv" => table::csv(name, bytes, if ext == "csv" { b',' } else { b'\t' }, offset),
        _ => {
            let decoded = decode_text(bytes)?;
            let rest = decoded
                .get(offset..)
                .ok_or("偏移量需要位于文本字符边界内，请使用 next_offset")?;
            let text = prefix(rest, TEXT_BYTES);
            let truncated = rest.len() > text.len();
            Ok(
                json!({"name":name,"kind":"text","text":text,"truncated":truncated,"next_offset":truncated.then_some(offset + text.len())}),
            )
        }
    }
}

fn image_format(bytes: &[u8]) -> Result<ImageFormat, String> {
    image::guess_format(bytes)
        .ok()
        .filter(|fmt| {
            matches!(
                fmt,
                ImageFormat::Jpeg | ImageFormat::Png | ImageFormat::WebP
            )
        })
        .ok_or_else(|| "只支持有效的 JPEG、PNG 和 WebP 图片".into())
}

fn image_attachment(name: &str, bytes: &[u8], budget: usize) -> Result<Value, String> {
    let format = image_format(bytes)?;
    let mut reader = ImageReader::with_format(Cursor::new(bytes), format);
    let mut limits = Limits::default();
    limits.max_image_width = Some(16384);
    limits.max_image_height = Some(16384);
    limits.max_alloc = Some(128 * 1024 * 1024);
    reader.limits(limits);
    let mut decoder = reader
        .into_decoder()
        .map_err(|_| "无法解码图片或图片尺寸过大")?;
    let (width, height) = decoder.dimensions();
    if width == 0
        || height == 0
        || u64::from(width) * u64::from(height) > INPUT_PIXELS
        || decoder.total_bytes() > 128 * 1024 * 1024
    {
        return Err("单张图片不能超过 3200 万像素或 128 MiB 解码体积".into());
    }
    let orientation = decoder.orientation().map_err(|_| "无法读取图片方向")?;
    let mut picture = DynamicImage::from_decoder(decoder).map_err(|_| "图片文件损坏或过大")?;
    picture.apply_orientation(orientation);
    if picture.width().max(picture.height()) > IMAGE_EDGE {
        picture = picture.thumbnail(IMAGE_EDGE, IMAGE_EDGE);
    }
    let mut encoded = Vec::new();
    let mut mime_type = "image/jpeg";
    if format != ImageFormat::Jpeg {
        picture
            .write_to(&mut Cursor::new(&mut encoded), ImageFormat::Png)
            .map_err(|_| "无法编码图片")?;
        if encoded.len() <= budget {
            mime_type = "image/png";
        }
    }
    if mime_type == "image/jpeg" {
        if picture.color().has_alpha() {
            let mut white = image::RgbaImage::from_pixel(
                picture.width(),
                picture.height(),
                image::Rgba([255; 4]),
            );
            imageops::overlay(&mut white, &picture.to_rgba8(), 0, 0);
            picture = DynamicImage::ImageRgba8(white);
        }
        picture = DynamicImage::ImageRgb8(picture.into_rgb8());
        loop {
            encoded.clear();
            JpegEncoder::new_with_quality(&mut encoded, 80)
                .encode_image(&picture)
                .map_err(|_| "无法编码图片")?;
            if encoded.len() <= budget {
                break;
            }
            let edge = (picture.width().max(picture.height()) * 3 / 4).max(1);
            picture = picture.thumbnail(edge, edge);
        }
    }
    Ok(
        json!({"name":name,"kind":"image","mime_type":mime_type,"data":BASE64.encode(encoded),"width":picture.width(),"height":picture.height()}),
    )
}

pub(crate) fn pick_attachments(app: &AppHandle) -> Result<Vec<Value>, String> {
    let selected = app
        .dialog()
        .file()
        .set_title("为本条消息添加本机附件")
        .add_filter(
            "支持的附件",
            &[
                "jpg", "jpeg", "png", "webp", "xlsx", "csv", "tsv", "txt", "md", "log", "json",
                "xml", "yaml", "yml", "toml",
            ],
        )
        .add_filter("文本文件", &["*"])
        .blocking_pick_files()
        .unwrap_or_default();
    if selected.len() > MAX_FILES {
        return Err("每条消息最多添加 4 个附件".into());
    }
    let budget = IMAGE_BYTES.min((MESSAGE_BYTES - 4096) / selected.len().max(1) * 3 / 4);
    let mut attachments = Vec::new();
    for item in selected {
        let path = item.into_path().map_err(|_| "只支持本机附件")?;
        let name = path
            .file_name()
            .and_then(|name| name.to_str())
            .filter(|name| !unsafe_name(name) && name.len() <= 255)
            .ok_or("附件文件名无效")?;
        let dir = path
            .parent()
            .ok_or("附件目录无效")?
            .canonicalize()
            .map_err(|_| "无法打开所选目录")?;
        let value = (|| -> Result<Value, String> {
            let (file, meta) = open_regular(&open_directory(&dir)?, name.as_ref())
                .map_err(|_| "附件不存在、不可读或不是普通文件")?;
            let bytes = file_bytes(file, meta.len())?;
            let ext = extension(name);
            if image_extension(&ext) {
                return image_attachment(name, &bytes, budget);
            }
            let mut value = document(name, &ext, &bytes, 0, None)?;
            value.as_object_mut().unwrap().remove("next_offset");
            Ok(value)
        })()
        .map_err(|message| format!("{name}：{message}"))?;
        attachments.push(value);
    }
    if serde_json::to_vec(&attachments)
        .map_err(|_| "附件编码失败")?
        .len()
        > MESSAGE_BYTES
    {
        return Err("本条消息的附件合计超过 192 KiB，请减少附件或分开发送".into());
    }
    Ok(attachments)
}

pub(crate) fn validate_attachments(value: &Value) -> Result<(), String> {
    if value.is_null() {
        return Ok(());
    }
    let items = value.as_array().ok_or("附件格式无效")?;
    if items.len() > MAX_FILES || value.to_string().len() > MESSAGE_BYTES {
        return Err("每条消息最多 4 个附件，附件合计不能超过 192 KiB".into());
    }
    for item in items {
        item["name"]
            .as_str()
            .filter(|name| !unsafe_name(name) && name.len() <= 255)
            .ok_or("附件文件名无效")?;
        match item["kind"].as_str() {
            Some("text" | "table") => {
                if item["text"]
                    .as_str()
                    .is_none_or(|text| text.len() > TEXT_BYTES)
                    || !item["truncated"].is_boolean()
                {
                    return Err("文本或表格附件格式无效，内容最多 24 KiB".into());
                }
            }
            Some("image") => {
                let data = BASE64
                    .decode(item["data"].as_str().ok_or("图片附件缺少内容")?)
                    .map_err(|_| "图片附件编码无效")?;
                if data.len() > IMAGE_BYTES {
                    return Err("图片附件超过 88 KiB".into());
                }
                let format = image_format(&data)?;
                if item["mime_type"].as_str() != Some(format.to_mime_type()) {
                    return Err("图片附件类型无效".into());
                }
                let mut reader = ImageReader::with_format(Cursor::new(&data), format);
                let mut limits = Limits::default();
                limits.max_image_width = Some(IMAGE_EDGE);
                limits.max_image_height = Some(IMAGE_EDGE);
                limits.max_alloc = Some(16 * 1024 * 1024);
                reader.limits(limits);
                let decoded = reader.decode().map_err(|_| "图片附件损坏或尺寸过大")?;
                if item["width"].as_u64() != Some(decoded.width().into())
                    || item["height"].as_u64() != Some(decoded.height().into())
                {
                    return Err("图片附件尺寸无效".into());
                }
            }
            _ => return Err("附件类型无效".into()),
        }
    }
    Ok(())
}
