import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';

export function useWechatBinding({ context, request, backendUrl }) {
  const inWechat = /wxwork/i.test(navigator.userAgent);
  const callbackUrl = new URL(window.location.href);
  const oauthResult = callbackUrl.searchParams.get('wechat_oauth');
  callbackUrl.searchParams.delete('wechat_oauth');
  if (oauthResult) window.history.replaceState(null, '', callbackUrl.pathname + callbackUrl.search + callbackUrl.hash);
  const state = reactive({ loading: false, message: '', status: null, accountId: null });
  let attempted = Boolean(oauthResult);
  let pending = null;
  const required = computed(() => ['teacher', 'student'].includes(context().role_type));
  const status = computed(() => state.accountId === context().account_id ? state.status : null);
  const blocked = computed(() => inWechat && required.value && (!status.value?.bound || !status.value?.identity_matches));

  function start() {
    if (!inWechat) { state.message = '请在企业微信中打开系统完成绑定'; return; }
    const url = new URL(window.location.href);
    state.loading = true;
    window.location.assign(backendUrl(`/api/wechat/oauth/start?return_path=${encodeURIComponent(url.pathname + url.search + url.hash)}`));
  }

  async function refresh(autoStart = true) {
    if (!context().account_id || !required.value) {
      state.status = null;
      state.message = '';
      return;
    }
    if (pending) return pending;
    const accountId = context().account_id;
    pending = (async () => {
      state.loading = true;
      try {
        const result = await request('/wechat/binding-status');
        if (accountId !== context().account_id) return;
        state.accountId = accountId;
        state.status = result;
        if (!result.configured) state.message = '请管理员先配置企业微信应用';
        else if (result.bound && result.identity_ready && !result.identity_matches) state.message = '当前企业微信与此系统账号绑定的身份不一致，请退出后使用对应账号登录，或联系管理员解除绑定。';
        else state.message = '';
        if (autoStart && inWechat && result.configured && !result.identity_ready && !attempted) {
          attempted = true;
          start();
        }
      } catch (error) { state.message = error.message; }
      finally { state.loading = false; }
    })();
    try { await pending; } finally { pending = null; }
  }

  async function bind() {
    if (state.loading) return;
    state.loading = true;
    try {
      const result = await request('/wechat/bind', { method: 'POST', body: '{}' });
      state.accountId = context().account_id;
      state.status = result;
      state.message = '';
      window.location.reload();
    } catch (error) { state.message = error.message; }
    finally { state.loading = false; }
  }

  function expired() { refresh(false); }
  onMounted(() => window.addEventListener('practical-wechat-required', expired));
  onBeforeUnmount(() => window.removeEventListener('practical-wechat-required', expired));
  return { state, status, blocked, inWechat, refresh, start, bind };
}
