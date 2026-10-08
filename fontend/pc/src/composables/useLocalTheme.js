import { computed, onBeforeUnmount, reactive, watch } from 'vue';

export const themePresets = [
  { id: 'system', name: '跟随系统', mode: 'system', accent: '#2563eb', window: '', text: '#ffffff', opacity: 1, density: 'normal' },
  { id: 'light', name: '简洁亮色', mode: 'light', accent: '#2563eb', window: '#ffffff', text: '#ffffff', opacity: 1, density: 'normal' },
  { id: 'dark', name: '深色蓝调', mode: 'dark', accent: '#60a5fa', window: '#18212f', text: '#ffffff', opacity: 0.96, density: 'normal' },
  { id: 'forest', name: '森林绿', mode: 'dark', accent: '#34d399', window: '#14251f', text: '#ffffff', opacity: 0.95, density: 'normal' },
  { id: 'violet', name: '紫色微光', mode: 'light', accent: '#7c3aed', window: '#faf7ff', text: '#ffffff', opacity: 0.95, density: 'compact' },
];

const defaults = () => ({ ...themePresets[0], wallpaper_id: '', name: '' });
const color = value => /^#[0-9a-f]{6}$/i.test(value || '') ? value : '';
const rgb = value => [1, 3, 5].map(index => Number.parseInt(value.slice(index, index + 2), 16));
const mixColor = (source, target, ratio) => `#${rgb(source).map((channel, index) => Math.round(channel + (rgb(target)[index] - channel) * ratio).toString(16).padStart(2, '0')).join('')}`;

function luminance(value) {
  const channels = rgb(value).map(channel => channel / 255).map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
  return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}

function contrast(first, second) {
  const values = [luminance(first), luminance(second)];
  return (Math.max(...values) + 0.05) / (Math.min(...values) + 0.05);
}

function foreground(background) {
  return contrast(background, '#18212b') > contrast(background, '#ffffff') ? '#18212b' : '#ffffff';
}

function accentVariables(accent, surface) {
  const variants = Object.fromEntries([3, 5, 7, 8, 9].map(level => [`--el-color-primary-light-${level}`, mixColor(accent, surface, level / 10)]));
  const active = mixColor(accent, '#000000', 0.2);
  const target = foreground(surface);
  let ink = accent;
  for (let step = 1; contrast(ink, surface) < 4.5 && step <= 20; step++) {
    ink = mixColor(accent, target, step / 20);
  }
  return {
    ...variants,
    '--el-color-primary-dark-2': active,
    '--primary-ink': ink,
    '--primary-contrast': foreground(accent),
    '--primary-hover-contrast': foreground(variants['--el-color-primary-light-3']),
    '--primary-active-contrast': foreground(active),
    '--primary-disabled-contrast': foreground(variants['--el-color-primary-light-5']),
  };
}

function normalize(value = {}) {
  return { id: String(value.id || 'system'), name: String(value.name || '').slice(0, 40), mode: ['light', 'dark', 'system'].includes(value.mode) ? value.mode : 'system', accent: color(value.accent) || '#2563eb', window: color(value.window), text: color(value.text) || '#ffffff', opacity: Math.max(0.65, Math.min(1, Number(value.opacity) || 1)), density: ['compact', 'normal', 'comfortable'].includes(value.density) ? value.density : 'normal', wallpaper_id: String(value.wallpaper_id || '') };
}

function wallpaperStore(action, id, blob) {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('practical-local-themes', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('wallpapers');
    request.onerror = () => reject(new Error('本机壁纸存储不可用'));
    request.onsuccess = () => {
      const db = request.result;
      const transaction = db.transaction('wallpapers', action === 'get' ? 'readonly' : 'readwrite');
      const store = transaction.objectStore('wallpapers');
      const operation = action === 'put' ? store.put(blob, id) : action === 'delete' ? store.delete(id) : store.get(id);
      let result;
      operation.onsuccess = () => { result = operation.result; };
      transaction.oncomplete = () => { db.close(); resolve(result); };
      transaction.onerror = () => { db.close(); reject(new Error('本机壁纸保存失败，请检查设备空间')); };
      transaction.onabort = transaction.onerror;
    };
  });
}

export function useLocalTheme(scope) {
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const state = reactive({ saved: defaults(), preview: null, custom: [], wallpaperUrl: '', systemDark: media.matches, error: '' });
  let generation = 0;
  let currentKey = '';
  let objectUrl = '';
  const current = computed(() => state.preview || state.saved);
  const dark = computed(() => current.value.mode === 'dark' || (current.value.mode === 'system' && state.systemDark));
  const styles = computed(() => ({ '--desktop-label-color': current.value.text, '--primary': current.value.accent, '--el-color-primary': current.value.accent, '--theme-window-opacity': current.value.opacity, '--desktop-tile-padding-top': current.value.density === 'compact' ? '5px' : current.value.density === 'comfortable' ? '18px' : '10px', ...(current.value.window ? { '--surface': current.value.window, '--el-bg-color': current.value.window, '--el-bg-color-overlay': current.value.window } : {}), ...(state.wallpaperUrl ? { backgroundImage: `url("${state.wallpaperUrl}")`, backgroundSize: 'cover', backgroundPosition: 'center' } : {}) }));
  const updateSystem = event => { state.systemDark = event.matches; };
  media.addEventListener('change', updateSystem);
  watch([dark, current], () => {
    const html = document.documentElement;
    html.dataset.theme = dark.value ? 'dark' : 'light';
    html.classList.toggle('dark', dark.value);
    html.style.setProperty('--primary', current.value.accent);
    html.style.setProperty('--el-color-primary', current.value.accent);
    html.style.setProperty('--desktop-label-color', current.value.text);
    for (const key of ['--surface', '--el-bg-color', '--el-bg-color-overlay']) {
      if (current.value.window) html.style.setProperty(key, current.value.window);
      else html.style.removeProperty(key);
    }
    const value = current.value.window || (dark.value ? '#18212f' : '#ffffff');
    Object.entries(accentVariables(current.value.accent, value)).forEach(([key, value]) => html.style.setProperty(key, value));
    html.style.setProperty('--theme-window-background', `rgba(${rgb(value).join(',')},${current.value.opacity})`);
  }, { immediate: true, deep: true });

  function revokeWallpaper() {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = '';
    state.wallpaperUrl = '';
  }
  async function displayWallpaper(id) {
    const version = ++generation;
    revokeWallpaper();
    if (!id || !currentKey) return;
    try {
      const blob = await wallpaperStore('get', `${currentKey}:${id}`);
      if (version !== generation) return;
      if (blob instanceof Blob) { objectUrl = URL.createObjectURL(blob); state.wallpaperUrl = objectUrl; }
    } catch (error) { if (version === generation) state.error = error.message; }
  }
  function persist() {
    if (!currentKey) throw new Error('请登录后保存本机主题');
    localStorage.setItem(currentKey, JSON.stringify({ saved: state.saved, custom: state.custom }));
  }
  watch(scope, value => {
    generation++;
    revokeWallpaper();
    state.saved = defaults(); state.preview = null; state.custom = []; state.error = '';
    currentKey = value ? `practical:local-theme:v1:${value}` : '';
    if (!currentKey) return;
    try {
      const stored = JSON.parse(localStorage.getItem(currentKey) || 'null');
      if (stored) { state.saved = normalize(stored.saved); state.custom = Array.isArray(stored.custom) ? stored.custom.map(normalize).slice(0, 20) : []; }
    } catch { state.error = '本机主题配置无效，已使用默认主题'; }
    displayWallpaper(state.saved.wallpaper_id);
  }, { immediate: true, flush: 'sync' });

  async function selectWallpaper(file) {
    if (!currentKey) throw new Error('请登录后选择本机壁纸');
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) throw new Error('请选择不超过 8 MB 的 JPG、PNG 或 WebP 图片');
    const bitmap = await createImageBitmap(file).catch(() => null);
    if (!bitmap) throw new Error('图片内容无法读取');
    bitmap.close();
    const id = crypto.randomUUID();
    const key = currentKey;
    await wallpaperStore('put', `${key}:${id}`, file);
    if (key !== currentKey) throw new Error('登录账号已变化，请重新选择壁纸');
    return id;
  }
  function preview(value) { state.preview = normalize(value); return displayWallpaper(state.preview.wallpaper_id); }
  function cancelPreview() { state.preview = null; return displayWallpaper(state.saved.wallpaper_id); }
  async function apply(value, name = '') {
    const next = normalize(value);
    const oldSaved = state.saved, oldCustom = [...state.custom];
    if (name.trim()) {
      if (state.custom.length >= 20) throw new Error('最多保存 20 个自定义主题');
      next.id = crypto.randomUUID(); next.name = name.trim().slice(0, 40);
      state.custom.push(next);
    }
    state.saved = next;
    try { persist(); } catch (error) { state.saved = oldSaved; state.custom = oldCustom; throw new Error('本机主题保存失败'); }
    state.preview = null;
    await displayWallpaper(next.wallpaper_id);
  }
  async function remove(id) {
    const item = state.custom.find(theme => theme.id === id);
    const oldSaved = state.saved, oldCustom = [...state.custom];
    state.custom = state.custom.filter(theme => theme.id !== id);
    if (state.saved.id === id) state.saved = defaults();
    try { persist(); } catch (error) { state.saved = oldSaved; state.custom = oldCustom; throw new Error('本机主题删除失败'); }
    state.preview = null;
    if (item?.wallpaper_id && state.saved.wallpaper_id !== item.wallpaper_id && !state.custom.some(theme => theme.wallpaper_id === item.wallpaper_id)) await wallpaperStore('delete', `${currentKey}:${item.wallpaper_id}`);
    await displayWallpaper(state.saved.wallpaper_id);
  }
  onBeforeUnmount(() => { generation++; revokeWallpaper(); media.removeEventListener('change', updateSystem); });
  return { state, current, dark, styles, presets: themePresets, preview, cancelPreview, apply, remove, selectWallpaper, reset: () => apply(defaults()) };
}
