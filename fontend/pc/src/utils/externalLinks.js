import { request } from '../api/client';

export function restrictedHost(value) {
  const host = String(value || '').toLowerCase().replace(/^\[|\]$/g, '');
  if (!host || ['localhost', 'localhost.localdomain'].includes(host)
    || ['.localhost', '.local', '.internal', '.lan', '.home.arpa'].some(suffix => host.endsWith(suffix))) return true;
  if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(host)) {
    const parts = host.split('.').map(Number);
    if (parts.some(part => part < 0 || part > 255)) return true;
    const [first, second] = parts;
    return first === 0 || first === 10 || first === 127 || first >= 224
      || (first === 100 && second >= 64 && second <= 127)
      || (first === 169 && second === 254)
      || (first === 172 && second >= 16 && second <= 31)
      || (first === 192 && second === 0 && [0, 2].includes(parts[2]))
      || (first === 192 && second === 88 && parts[2] === 99)
      || (first === 192 && second === 168)
      || (first === 198 && ([18, 19].includes(second) || (second === 51 && parts[2] === 100)))
      || (first === 203 && second === 0 && parts[2] === 113);
  }
  if (host.includes(':')) {
    const segments = ipv6Segments(host);
    if (!segments) return true;
    const [a, b, c, d, e, f, g, h] = segments;
    if (segments.slice(0, 5).every(part => part === 0) && f === 0xffff) {
      return restrictedHost(`${g >> 8}.${g & 255}.${h >> 8}.${h & 255}`);
    }
    return (a & 0xe000) !== 0x2000 || (a === 0x2001 && (b <= 0x01ff || b === 0x0db8))
      || a === 0x2002 || (a === 0x3fff && b <= 0x000f) || (a === 0x0064 && b === 0xff9b)
      || (a === 0 && b === 0 && c === 0 && d === 0 && e === 0 && f === 0);
  }
  return !host.includes('.');
}

function ipv6Segments(host) {
  let value = host;
  const dotted = value.match(/(?:^|:)(\d{1,3}(?:\.\d{1,3}){3})$/);
  if (dotted) {
    const parts = dotted[1].split('.').map(Number);
    if (parts.some(part => part < 0 || part > 255)) return null;
    value = value.slice(0, -dotted[1].length) + `${((parts[0] << 8) | parts[1]).toString(16)}:${((parts[2] << 8) | parts[3]).toString(16)}`;
  }
  const halves = value.split('::');
  if (halves.length > 2) return null;
  const left = halves[0] ? halves[0].split(':') : [];
  const right = halves.length === 2 && halves[1] ? halves[1].split(':') : [];
  const missing = 8 - left.length - right.length;
  if ((halves.length === 1 && missing !== 0) || (halves.length === 2 && missing < 1)) return null;
  const values = [...left, ...Array(missing).fill('0'), ...right].map(part => /^[0-9a-f]{1,4}$/.test(part) ? Number.parseInt(part, 16) : NaN);
  return values.length === 8 && values.every(Number.isFinite) ? values : null;
}

/** 收藏、桌面与菜单共用的外链入口。 */
export async function openExternalLink(item, mode, onCurrent = null) {
  const id = Number(item?.favoriteId || item?.id) || 0;
  if (id) item = await request(`/favorite/detail?id=${id}`);
  mode ||= item?.open_mode || 'client';
  const url = new URL(String(item?.url || ''));
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('链接地址无效');
  if (restrictedHost(url.hostname)) throw new Error('链接地址不允许访问本机或内部网络');
  if (mode === 'current') {
    const school = window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin;
    let schoolOrigin;
    try { schoolOrigin = new URL(school).origin; } catch { schoolOrigin = window.location.origin; }
    if (url.origin === schoolOrigin || url.origin === window.location.origin) throw new Error('当前页只允许外部网页，不能打开学校或本机内部地址');
    if (item.request_config?.method === 'POST' || (item.request_config?.form || []).length) throw new Error('此链接需要认证交换，请使用客户端窗口打开');
    const current = { ...item, url: url.href };
    if (onCurrent) onCurrent(current);
    else window.dispatchEvent(new CustomEvent('practical-open-current-link', { detail: current }));
    return;
  }
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
