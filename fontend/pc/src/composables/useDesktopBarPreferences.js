import { onBeforeUnmount, reactive, watch } from 'vue';

const defaults = () => ({ autoHideStatusBar: false, autoHideDock: true });
const preferenceKeys = ['autoHideStatusBar', 'autoHideDock'];

function readPreferences(raw) {
  const preferences = defaults();
  if (raw === null) return { preferences, error: '' };
  try {
    const stored = JSON.parse(raw);
    if (!stored || typeof stored !== 'object' || Array.isArray(stored)) throw new Error();
    let invalid = false;
    for (const key of preferenceKeys) {
      if (typeof stored[key] === 'boolean') preferences[key] = stored[key];
      else invalid = true;
    }
    return { preferences, error: invalid ? '本机栏位配置无效，异常项已恢复默认设置' : '' };
  } catch {
    return { preferences, error: '本机栏位配置无效，已恢复默认设置' };
  }
}

export function useDesktopBarPreferences(scope) {
  const state = reactive({ ...defaults(), error: '' });
  let currentKey = '';

  function applyStored(raw) {
    const result = readPreferences(raw);
    Object.assign(state, result.preferences, { error: result.error });
  }

  watch(scope, value => {
    Object.assign(state, defaults(), { error: '' });
    currentKey = value ? `practical:desktop-bars:v1:${value}` : '';
    if (!currentKey) return;
    try { applyStored(window.localStorage.getItem(currentKey)); }
    catch { state.error = '本机栏位配置读取失败'; }
  }, { immediate: true, flush: 'sync' });

  function setPreference(key, value) {
    if (!preferenceKeys.includes(key) || typeof value !== 'boolean') return false;
    if (!currentKey) {
      state.error = '请登录后保存本机栏位设置';
      return false;
    }
    const preferences = Object.fromEntries(preferenceKeys.map(name => [name, name === key ? value : state[name]]));
    try { window.localStorage.setItem(currentKey, JSON.stringify(preferences)); }
    catch {
      state.error = '本机栏位设置保存失败，已保留原设置';
      return false;
    }
    Object.assign(state, preferences, { error: '' });
    return true;
  }

  function syncStorage(event) {
    if (!currentKey || (event.key !== currentKey && event.key !== null)) return;
    try {
      if (event.storageArea !== window.localStorage) return;
      applyStored(event.key === null ? null : event.newValue);
    } catch { state.error = '本机栏位配置同步失败，已保留原设置'; }
  }
  window.addEventListener('storage', syncStorage);
  onBeforeUnmount(() => window.removeEventListener('storage', syncStorage));
  return { state, setPreference };
}
