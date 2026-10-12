import { onBeforeUnmount, reactive, watch } from 'vue';

const allowedModes = new Set(['current', 'client', 'browser']);
const reservedIds = new Set(['__proto__', 'constructor', 'prototype']);
const emptyModes = () => Object.create(null);
const validId = id => typeof id === 'string' && id.length > 0 && id.length <= 300
  && !/[\u0000-\u001f\u007f]/.test(id) && !reservedIds.has(id);

function readModes(raw) {
  if (raw === null) return emptyModes();
  const stored = JSON.parse(raw);
  if (!stored || typeof stored !== 'object' || Array.isArray(stored)) throw new Error();
  const modes = emptyModes();
  for (const [id, mode] of Object.entries(stored)) {
    if (!validId(id) || !allowedModes.has(mode)) throw new Error();
    modes[id] = mode;
  }
  return modes;
}

export function useDesktopOpenPreferences(scope) {
  const state = reactive({ modes: emptyModes(), error: '' });
  let currentKey = '';

  watch(scope, value => {
    state.modes = emptyModes();
    state.error = '';
    currentKey = typeof value === 'string' && value ? `practical:desktop-open:v1:${value}` : '';
    if (!currentKey) return;
    try { state.modes = readModes(window.localStorage.getItem(currentKey)); }
    catch { state.error = '本机打开方式读取失败，已恢复默认设置'; }
  }, { immediate: true, flush: 'sync' });

  function getMode(id) {
    if (!validId(id) || !Object.hasOwn(state.modes, id)) return '';
    return allowedModes.has(state.modes[id]) ? state.modes[id] : '';
  }

  function setMode(id, mode) {
    if (!validId(id) || (mode !== '' && !allowedModes.has(mode))) return false;
    if (!currentKey) {
      state.error = '请登录后保存本机打开方式';
      return false;
    }
    const modes = Object.assign(emptyModes(), state.modes);
    if (mode === '') delete modes[id];
    else modes[id] = mode;
    try { window.localStorage.setItem(currentKey, JSON.stringify(modes)); }
    catch {
      state.error = '本机打开方式保存失败，已保留原设置';
      return false;
    }
    state.modes = modes;
    state.error = '';
    return true;
  }

  function syncStorage(event) {
    if (!currentKey || (event.key !== currentKey && event.key !== null)) return;
    try {
      if (event.storageArea !== window.localStorage) return;
      const modes = readModes(event.key === null ? null : event.newValue);
      state.modes = modes;
      state.error = '';
    } catch { state.error = '本机打开方式同步失败，已保留原设置'; }
  }
  window.addEventListener('storage', syncStorage);
  onBeforeUnmount(() => window.removeEventListener('storage', syncStorage));
  return { state, getMode, setMode };
}
