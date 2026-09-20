import { request } from '../api/client';

/** 收藏、桌面与菜单共用的外链入口。 */
export async function openExternalLink(item, mode) {
  const id = Number(item?.favoriteId || item?.id) || 0;
  if (id) item = await request(`/favorite/detail?id=${id}`);
  mode ||= item?.open_mode || 'client';
  const url = new URL(String(item?.url || ''));
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('链接地址无效');
  if (window.__PRACTICAL_DESKTOP__?.openExternal) {
    await window.__PRACTICAL_DESKTOP__.openExternal(url.href, mode, id);
  } else {
    for (const { key, value } of item.request_config?.query || []) url.searchParams.set(key, value);
    if (item.request_config?.method === 'POST') {
      const form = document.createElement('form');
      form.method = 'POST'; form.action = url.href; form.target = '_blank'; form.rel = 'noopener noreferrer';
      for (const { key, value } of item.request_config.form || []) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = value; form.appendChild(input); }
      document.body.appendChild(form); HTMLFormElement.prototype.submit.call(form); form.remove();
    } else window.open(url.href, '_blank', 'noopener,noreferrer');
  }
}
