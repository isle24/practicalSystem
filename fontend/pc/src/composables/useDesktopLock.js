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
  let appliedVersion = 0;
  let statusError = false;

  function apply(result) {
    if (disposed || !result || typeof result.locked !== 'boolean') return result;
    const nextRevision = Number(result.revision ?? 0);
    if (nextRevision < revision) return result;
    revision = nextRevision;
    appliedVersion++;
    if (statusError) { state.error = ''; statusError = false; }
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
    if (tracked) { activeOperations++; state.busy = true; state.error = ''; statusError = false; }
    try {
      return apply(await bridge.lock(action, payload));
    } catch (error) {
      if (!disposed && tracked) { state.error = error.message || '客户端锁定操作失败'; statusError = false; }
      throw error;
    } finally {
      if (tracked) { activeOperations--; if (!disposed) state.busy = activeOperations > 0; }
    }
  }

  async function refresh() {
    const version = appliedVersion;
    try {
      const result = await call('status', {}, false);
      if (!result || typeof result.locked !== 'boolean') throw new Error('客户端锁定状态响应无效，请重新读取');
      return result;
    }
    catch (error) {
      if (!disposed && version === appliedVersion) { state.error = error.message || '无法读取客户端锁定状态'; statusError = true; state.locked = true; }
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
    clearError: () => { state.error = ''; statusError = false; },
    lockNow: () => call('manual').finally(() => refresh().catch(() => {})),
    saveSettings: preferences => call('settings', preferences),
    unlockPassword: password => call('password-unlock', { password }),
    readLocalPassword: () => call('local-password-status'),
    saveLocalPassword: (password, confirmation) => call('local-password-set', { password, confirmation }),
    clearLocalPassword: () => call('local-password-clear'),
    unlockLocalPassword: password => call('local-password-unlock', { password }),
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
