import { computed, onBeforeUnmount, onMounted, reactive, watch } from 'vue';

export function useWechatBinding({ context, request, backendUrl, publicOrigin = () => backendUrl('/') }) {
  const bindingRoles = ['teacher', 'student', 'super_admin', 'school_admin', 'college_admin', 'profession_admin'];
  const inWechat = /wxwork/i.test(navigator.userAgent);
  const callbackUrl = new URL(window.location.href);
  const oauthResult = callbackUrl.searchParams.get('wechat_oauth');
  const requested = callbackUrl.searchParams.get('wechat_bind') === '1';
  callbackUrl.searchParams.delete('wechat_oauth');
  callbackUrl.searchParams.delete('wechat_bind');
  if (oauthResult || requested) window.history.replaceState(null, '', callbackUrl.pathname + callbackUrl.search + callbackUrl.hash);
  const state = reactive({ scan: null, scanLoading: false, loading: false, message: '', status: null, sessionKey: '', open: requested || Boolean(oauthResult) });
  let attempted = Boolean(oauthResult);
  let generation = 0;
  let pending = null;
  let pollTimer = null;
  let scanGeneration = 0;
  let disposed = false;
  const schoolOrigin = () => typeof publicOrigin === 'function' ? publicOrigin() : publicOrigin;
  const sessionKey = computed(() => {
    const current = context();
    return current.account_id ? [schoolOrigin(), current.school_database_id, current.school_code, current.school_id, current.account_id].join(':') : '';
  });
  const required = computed(() => bindingRoles.includes(context().role_type));
  const status = computed(() => state.sessionKey === sessionKey.value ? state.status : null);
  const blocked = computed(() => {
    const binding = status.value || context().wechat_binding || {};
    const forceInWechat = inWechat && ['teacher', 'student'].includes(context().role_type);
    if (!sessionKey.value || !required.value || (binding.enforced === false && !forceInWechat)) return false;
    return !binding.bound || (inWechat && (!binding.identity_ready || !binding.identity_matches));
  });
  const show = computed(() => Boolean(context().account_id && required.value && (blocked.value || state.open || (inWechat && status.value && !status.value.bound))));
  const publicUrl = computed(() => new URL('/h5/?wechat_bind=1', schoolOrigin()).href);

  watch(sessionKey, (key, previous) => {
    cancelScan();
    generation++;
    pending = null;
    state.status = null;
    state.sessionKey = '';
    state.loading = false;
    state.message = '';
    if (previous) {
      attempted = false;
      state.open = false;
    }
  }, { flush: 'sync' });

  function currentSession(key, version) {
    return key === sessionKey.value && version === generation;
  }

  function start() {
    state.open = true;
    if (!inWechat) return createScan();
    if (status.value?.configured === false || status.value?.binding_outdated) return;
    const url = new URL(window.location.href);
    state.loading = true;
    window.location.assign(backendUrl(`/api/wechat/oauth/start?return_path=${encodeURIComponent(url.pathname + url.search + url.hash)}`));
  }

  async function refresh(autoStart = true) {
    if (!context().account_id || !required.value) {
      state.status = null;
      state.message = '';
      return null;
    }
    const key = sessionKey.value;
    const version = generation;
    if (pending?.sessionKey === key && pending.generation === version) {
      pending.autoStart ||= autoStart;
      return pending.promise;
    }
    const job = { sessionKey: key, generation: version, autoStart, promise: null };
    pending = job;
    job.promise = (async () => {
      state.loading = true;
      try {
        const result = await request('/wechat/binding-status');
        if (!currentSession(key, version)) return null;
        state.sessionKey = key;
        state.status = result;
        if (!result.configured) state.message = '企业微信尚未配置，请联系学校管理员完成配置。';
        else if (result.binding_outdated) state.message = '当前绑定不属于学校正在使用的企业微信，请联系学校管理员解除旧绑定后重新绑定。';
        else if (result.bound && inWechat && result.identity_ready && !result.identity_matches) state.message = '当前企业微信与此系统账号绑定的身份不一致，请退出后使用对应账号登录，或联系管理员解除绑定。';
        else if (result.bound && inWechat && result.enforced && !result.identity_ready) state.message = '当前账号已绑定企业微信，请获取当前企业微信身份完成确认。';
        else if (result.bound) state.message = '当前账号已绑定学校企业微信。';
        else state.message = '';
        if (job.autoStart && inWechat && result.configured && !result.binding_outdated
          && (result.enforced || state.open) && !result.identity_ready && !attempted) {
          attempted = true;
          start();
        }
        return result;
      } catch (error) {
        if (currentSession(key, version)) state.message = error.message;
        return null;
      } finally {
        if (currentSession(key, version)) state.loading = false;
        if (pending === job) pending = null;
      }
    })();
    return job.promise;
  }

  function open() {
    state.open = true;
    return refresh();
  }

  function close() {
    if (!blocked.value) {
      state.open = false;
      cancelScan();
    }
  }

  async function recheck() {
    if (state.loading) return;
    const wasBlocked = blocked.value;
    const result = await refresh(false);
    if (result && !blocked.value && (wasBlocked || result.bound)) window.location.reload();
  }

  async function copyLink() {
    const key = sessionKey.value;
    const version = generation;
    try {
      await navigator.clipboard.writeText(publicUrl.value);
      if (currentSession(key, version)) state.message = '已复制学校网址，请在企业微信中打开并登录当前系统账号完成绑定。';
    } catch {
      if (currentSession(key, version)) state.message = '请选中下方学校网址复制，在企业微信中打开并登录当前系统账号。';
    }
  }

  async function bind() {
    if (state.loading) return;
    const key = sessionKey.value;
    const version = generation;
    state.loading = true;
    try {
      const result = await request('/wechat/bind', { method: 'POST', body: '{}' });
      if (!currentSession(key, version)) return;
      state.sessionKey = key;
      state.status = result;
      state.message = '';
      window.location.reload();
    } catch (error) {
      if (currentSession(key, version)) state.message = error.message;
    } finally {
      if (currentSession(key, version)) state.loading = false;
    }
  }

  async function cancelScan() {
    scanGeneration++;
    clearTimeout(pollTimer);
    pollTimer = null;
    const scan = state.scan;
    state.scan = null;
    state.scanLoading = false;
    if (!scan?.session_id || ['confirmed', 'expired', 'cancelled', 'failed'].includes(scan.state)) return;
    try {
      await request('/wechat/scan-cancel', { method: 'POST', body: JSON.stringify({ session_id: scan.session_id }), keepalive: true });
    } catch {}
  }

  async function createScan() {
    if (state.scanLoading || inWechat || !show.value || status.value?.configured === false || status.value?.binding_outdated) return;
    const cancellation = cancelScan();
    const key = sessionKey.value;
    const version = generation;
    const scanVersion = ++scanGeneration;
    state.scanLoading = true;
    state.message = '';
    try {
      await cancellation;
      if (!currentSession(key, version) || scanVersion !== scanGeneration || !show.value || disposed) return;
      const school = new URL(schoolOrigin());
      if (school.protocol !== 'https:') throw new Error('扫码绑定需要学校 HTTPS 地址，请检查学校服务地址。');
      const result = await request('/wechat/scan-session', { method: 'POST', body: '{}' });
      if (!currentSession(key, version) || scanVersion !== scanGeneration || disposed) {
        await request('/wechat/scan-cancel', { method: 'POST', body: JSON.stringify({ session_id: result.session_id }), keepalive: true }).catch(() => {});
        return;
      }
      const scanUrl = new URL(result.scan_url);
      if (scanUrl.protocol !== 'https:' || scanUrl.origin !== school.origin || scanUrl.pathname !== '/api/wechat/scan/start') {
        await request('/wechat/scan-cancel', { method: 'POST', body: JSON.stringify({ session_id: result.session_id }) }).catch(() => {});
        throw new Error('二维码学校地址不一致，请联系管理员检查配置。');
      }
      state.scan = { ...result, expiresAt: Date.now() + result.expires_in * 1000 };
      pollTimer = setTimeout(() => pollScan(key, version, scanVersion), 2000);
    } catch (error) {
      if (currentSession(key, version) && scanVersion === scanGeneration) state.message = error.message;
    } finally {
      if (currentSession(key, version) && scanVersion === scanGeneration) state.scanLoading = false;
    }
  }

  async function pollScan(key, version, scanVersion) {
    if (!currentSession(key, version) || scanVersion !== scanGeneration || !state.scan || disposed) return;
    if (Date.now() >= state.scan.expiresAt) {
      state.scan.state = 'expired';
      state.message = '二维码已过期，请刷新二维码。';
      return;
    }
    try {
      const result = await request(`/wechat/scan-status?session_id=${encodeURIComponent(state.scan.session_id)}`);
      if (!currentSession(key, version) || scanVersion !== scanGeneration || !state.scan || disposed) return;
      state.scan.state = result.state;
      if (result.state === 'confirmed') {
        await refresh(false);
        if (currentSession(key, version)) window.location.reload();
        return;
      }
      if (['expired', 'cancelled', 'failed'].includes(result.state)) {
        state.message = result.state === 'failed' ? '绑定未完成，请在电脑刷新二维码后重试。' : '二维码已失效，请刷新二维码。';
        return;
      }
      state.message = result.state === 'scanned' ? '已扫码，请在手机核对账号并确认绑定。' : '';
    } catch (error) {
      if (!currentSession(key, version) || scanVersion !== scanGeneration || !state.scan || disposed) return;
      state.message = error.message;
      if ([401, 403].includes(error.status)) { await cancelScan(); return; }
    }
    if (currentSession(key, version) && scanVersion === scanGeneration && state.scan && !disposed) pollTimer = setTimeout(() => pollScan(key, version, scanVersion), 2000);
  }

  watch(() => [show.value, status.value?.configured, status.value?.bound], ([visible, configured, bound]) => {
    if (!visible || bound) { cancelScan(); return; }
    if (!inWechat && configured && !state.scan && !state.scanLoading) createScan();
  });

  async function expired() {
    const wasBlocked = blocked.value;
    const result = await refresh(false);
    if (result && wasBlocked && !blocked.value) window.location.reload();
  }
  onMounted(() => {
    window.addEventListener('practical-wechat-required', expired);
    window.addEventListener('pagehide', cancelScan);
  });
  onBeforeUnmount(() => {
    disposed = true;
    window.removeEventListener('practical-wechat-required', expired);
    window.removeEventListener('pagehide', cancelScan);
    cancelScan();
  });
  return { state, status, cancelScan, createScan, blocked, show, inWechat, publicUrl, refresh, start, bind, open, close, recheck, copyLink };
}
