/** 识别常见中文文本编码；无 BOM 时优先使用严格 UTF-8。 */
export function decodePreviewText(bytes, encoding = 'auto') {
  let selected = encoding;
  if (selected === 'auto') {
    if (bytes[0] === 0xff && bytes[1] === 0xfe) selected = 'utf-16le';
    else if (bytes[0] === 0xfe && bytes[1] === 0xff) selected = 'utf-16be';
    else {
      try { return { text: new TextDecoder('utf-8', { fatal: true }).decode(bytes), encoding: 'utf-8' }; }
      catch { selected = 'gb18030'; }
    }
  }
  return { text: new TextDecoder(selected).decode(bytes), encoding: selected };
}

/** 限制文本展示体积，保留完整内容下载。 */
export function previewTextContent(bytes, encoding) {
  const decoded = decodePreviewText(bytes, encoding);
  if (decoded.text.includes('\0')) throw new Error('文件包含二进制内容，请检查编码或下载查看');
  const truncated = decoded.text.length > 500000;
  return { ...decoded, text: decoded.text.slice(0, 500000).replace(/\r\n?/g, '\n'), truncated };
}
