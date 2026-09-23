import { computed, onBeforeUnmount, onMounted, reactive, watch } from 'vue';

export function useWechatBinding({ context, request, backendUrl }) {
  const inWechat = /wxwork/i.test(navigator.userAgent);
  const callbackUrl = new URL(window.location.href);
  const oauthResult = callbackUrl.searchParams.get('wechat_oauth');
  const requested = callbackUrl.searchParams.get('wechat_bind') === '1';
  callbackUrl.searchParams.delete('wechat_oauth');
  callbackUrl.searchParams.delete('wechat_bind');
  if (oauthResult || requested) window.history.replaceState(null, '', callbackUrl.pathname + callbackUrl.search + callbackUrl.hash);
  const state = reactive({ loading: false, message: '', status: null, sessionKey: '', open: requested || Boolean(oauthResult) });
  let attempted = Boolean(oauthResult);
  let generation = 0;
  let pending = null;
  const sessionKey = computed(() => {
    const current = context();
    return current.account_id ? [window.__PRACTICAL_DESKTOP__?.serverOrigin || backendUrl('/'), current.school_database_id, current.school_code, current.school_id, current.account_id].join(':') : '';
  });
  const required = computed(() => ['teacher', 'student'].includes(context().role_type));
  const status = computed(() => state.sessionKey === sessionKey.value ? state.status : null);
  const blocked = computed(() => {
    const binding = status.value || context().wechat_binding || {};
    if (!sessionKey.value || !required.value || binding.enforced === false) return false;
    return !binding.bound || (inWechat && (!binding.identity_ready || !binding.identity_matches));
  });
  const show = computed(() => Boolean(context().account_id && required.value && (blocked.value || state.open)));
  const publicUrl = computed(() => new URL('/h5/?wechat_bind=1', window.__PRACTICAL_DESKTOP__?.serverOrigin || backendUrl('/')).href);

  watch(sessionKey, (key, previous) => {
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
    if (!inWechat) { state.message = '请在企业微信中打开系统完成绑定'; return; }
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
    if (!blocked.value) state.open = false;
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

  async function expired() {
    const wasBlocked = blocked.value;
    const result = await refresh(false);
    if (result && wasBlocked && !blocked.value) window.location.reload();
  }
  onMounted(() => window.addEventListener('practical-wechat-required', expired));
  onBeforeUnmount(() => window.removeEventListener('practical-wechat-required', expired));
  return { state, status, blocked, show, inWechat, publicUrl, refresh, start, bind, open, close, recheck, copyLink };
}
