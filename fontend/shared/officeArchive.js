import { unzipSync } from 'fflate';

/** 解压前检查 Office 容器声明的体积和条目数。 */
export function validateOfficeArchive(bytes) {
  let total = 0, entries = 0;
  unzipSync(bytes, { filter(file) {
    total += file.originalSize; entries++;
    if (total > 100 * 1024 * 1024 || file.originalSize > 32 * 1024 * 1024 || entries > 10000) throw new Error('文档解压体积过大，请下载查看');
    return false;
  } });
}
