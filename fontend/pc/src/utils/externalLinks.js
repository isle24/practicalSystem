/** 收藏、桌面与菜单共用的外链入口。 */
export async function openExternalLink(item, mode = item?.open_mode || 'client') {
  const url = new URL(String(item?.url || ''));
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('链接地址无效');
  if (window.__PRACTICAL_DESKTOP__?.openExternal) {
    await window.__PRACTICAL_DESKTOP__.openExternal(url.href, mode);
  } else {
    window.open(url.href, '_blank', 'noopener,noreferrer');
  }
}
