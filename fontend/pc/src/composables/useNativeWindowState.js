import { onBeforeUnmount, onMounted, reactive, readonly } from 'vue';

export function useNativeWindowState(enabled) {
  const bridge = window.__PRACTICAL_DESKTOP__;
  const state = reactive({ maximized: false, fullscreen: false });
  let disposed = false;
  let version = 0;
  let refreshing = false;
  let timer;
  let settleTimer;
  let fullscreenChanging = false;

  function apply(result) {
    if (typeof result?.maximized === 'boolean') state.maximized = result.maximized;
    if (typeof result?.fullscreen === 'boolean') state.fullscreen = result.fullscreen;
  }

  async function refresh() {
    if (!enabled || disposed || refreshing) return;
    const current = version;
    refreshing = true;
    try {
      const result = await bridge.windowControls('status');
      if (!disposed && current === version) apply(result);
    } catch {} finally { refreshing = false; }
  }

  function scheduleRefresh() {
    clearTimeout(settleTimer);
    settleTimer = setTimeout(refresh, 120);
  }

  async function control(action) {
    if (!enabled || disposed) return;
    const current = ++version;
    try {
      const result = await bridge.windowControls(action);
      if (!disposed && current === version) apply(result);
      return result;
    } finally { if (!disposed) scheduleRefresh(); }
  }

  async function keydown(event) {
    if (event.key !== 'F11' || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    event.preventDefault();
    if (event.repeat || fullscreenChanging) return;
    fullscreenChanging = true;
    try { await control('fullscreen'); } catch {} finally { fullscreenChanging = false; }
  }

  function visibilityChanged() { if (!document.hidden) refresh(); }

  onMounted(() => {
    if (!enabled) return;
    window.addEventListener('practical-native-window-state', scheduleRefresh);
    window.addEventListener('resize', scheduleRefresh);
    window.addEventListener('focus', scheduleRefresh);
    window.addEventListener('keydown', keydown);
    document.addEventListener('visibilitychange', visibilityChanged);
    refresh();
    timer = setInterval(() => { if (!document.hidden) refresh(); }, 2000);
  });

  onBeforeUnmount(() => {
    disposed = true;
    version++;
    clearInterval(timer);
    clearTimeout(settleTimer);
    window.removeEventListener('practical-native-window-state', scheduleRefresh);
    window.removeEventListener('resize', scheduleRefresh);
    window.removeEventListener('focus', scheduleRefresh);
    window.removeEventListener('keydown', keydown);
    document.removeEventListener('visibilitychange', visibilityChanged);
  });

  return { state: readonly(state), control };
}
