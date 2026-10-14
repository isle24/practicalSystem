import { onBeforeUnmount, onMounted, reactive } from 'vue';

export function useDesktopLock() {
  const bridge = window.__PRACTICAL_DESKTOP__;
  const supported = typeof bridge?.lock === 'function';
  const state = reactive({
    supported, initialized: false, locked: supported, busy: false, error: '',
    settings: { enabled: true, idle_minutes: 30 }, context: null, require_fresh_login: false,
  });
  let disposed = false;
  let lastActivity = 0;
  let pollTimer = null;
  let revision = -1;
  let activeOperations = 0;

  function apply(result) {
    if (disposed || !result || typeof result.locked !== 'boolean') return result;
    const nextRevision = Number(result.revision ?? 0);
    if (nextRevision < revision) return result;
    revision = nextRevision;
    state.locked = result.locked;
    state.settings.enabled = result.enabled === true;
    state.settings.idle_minutes = Number(result.idle_minutes || 30);
    state.context = result.context || null;
    state.require_fresh_login = result.require_fresh_login === true;
    state.initialized = true;
    return result;
  }

  async function call(action, payload = {}, tracked = true) {
    if (!supported) throw new Error('该功能仅在客户端中使用');
    if (tracked) { activeOperations++; state.busy = true; state.error = ''; }
    try {
      return apply(await bridge.lock(action, payload));
    } catch (error) {
      if (!disposed && tracked) state.error = error.message || '客户端锁定操作失败';
      throw error;
    } finally {
      if (tracked) { activeOperations--; if (!disposed) state.busy = activeOperations > 0; }
    }
  }

  async function refresh() {
    try { return await call('status', {}, false); }
    catch (error) {
      if (!disposed) { state.error = error.message || '无法读取客户端锁定状态'; state.locked = true; }
      throw error;
    }
  }
  function changed() { refresh().catch(() => {}); }
  function activity(event) {
    if (!event.isTrusted || document.hidden || state.locked || disposed) return;
    const now = Date.now();
    if (now - lastActivity < 5000) return;
    lastActivity = now;
    call('activity', {}, false).catch(() => {});
  }

  async function recoveryRequest(path, options = {}) {
    if (options.signal?.aborted) throw new DOMException('请求已取消', 'AbortError');
    const url = new URL(path, 'https://client.invalid');
    const payload = options.body ? JSON.parse(options.body) : Object.fromEntries(url.searchParams);
    const result = await call('recovery-request', { path: url.pathname, method: (options.method || 'GET').toUpperCase(), payload }, false);
    return result;
  }

  Object.assign(state, {
    refresh,
    lockNow: () => call('manual').finally(() => refresh().catch(() => {})),
    saveSettings: preferences => call('settings', preferences),
    unlockPassword: password => call('password-unlock', { password }),
    createWechat: () => call('wechat-create'),
    pollWechat: session_id => call('wechat-status', { session_id }, false),
    consumeWechat: session_id => call('wechat-consume', { session_id }),
    cancelWechat: session_id => call('wechat-cancel', { session_id }, false),
    exitSession: () => call('exit-session'),
    recoveryRequest,
  });

  onMounted(() => {
    if (!supported) return;
    changed();
    window.addEventListener('practical-desktop-lock-change', changed);
    window.addEventListener('focus', changed);
    document.addEventListener('visibilitychange', changed);
    for (const event of ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'touchmove']) {
      window.addEventListener(event, activity, { capture: true, passive: true });
    }
    pollTimer = window.setInterval(changed, 5000);
  });
  onBeforeUnmount(() => {
    disposed = true;
    window.clearInterval(pollTimer);
    window.removeEventListener('practical-desktop-lock-change', changed);
    window.removeEventListener('focus', changed);
    document.removeEventListener('visibilitychange', changed);
    for (const event of ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'touchmove']) {
      window.removeEventListener(event, activity, true);
    }
  });
  return state;
}
