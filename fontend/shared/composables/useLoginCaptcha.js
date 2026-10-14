import { onBeforeUnmount, reactive, watch } from 'vue';

export function useLoginCaptcha({ loginName, request }) {
  const state = reactive({ required: false, captcha: null, code: '', loading: false, error: '' });
  let revision = 0, controller, disposed = false, expiresAt = 0;

  function clear(keepRequired = false) {
    revision++;
    controller?.abort(); controller = null;
    const required = keepRequired && state.required;
    Object.assign(state, { required, captcha: null, code: '', loading: false, error: '' });
    expiresAt = 0;
  }

  function apply(data) {
    if (!data || typeof data.captcha_required !== 'boolean') return;
    state.required = data.captcha_required;
    state.code = '';
    const captcha = data.captcha;
    const seconds = Number(captcha?.expires_in);
    if (captcha && typeof captcha.id === 'string' && /^[a-f0-9]{64}$/.test(captcha.id) && typeof captcha.image === 'string'
      && captcha.image.length <= 256000 && /^data:image\/(?:jpeg|png);base64,[A-Za-z0-9+/]+={0,2}$/.test(captcha.image)
      && Number.isFinite(seconds) && seconds > 0 && seconds <= 600) {
      state.captcha = { id: captcha.id, image: captcha.image, expires_in: seconds };
      expiresAt = Date.now() + seconds * 1000;
    } else {
      state.captcha = null; expiresAt = 0;
      if (state.required) state.error = '请重新获取验证码。';
    }
  }

  function accept(error) {
    const data = error?.payload?.data;
    if ((error?.status >= 500 || [409, 429].includes(error?.status)) && data?.captcha_required !== true) return;
    state.error = '';
    apply(data);
  }

  async function refresh() {
    if (disposed || state.loading || !loginName().trim()) return;
    clear(true);
    const current = revision, name = loginName(), pending = new AbortController();
    controller = pending; state.loading = true;
    try {
      const data = await request('/auth/login-captcha', { method: 'POST', body: JSON.stringify({ login_name: name }), signal: pending.signal, refreshOnUnauthorized: false });
      if (disposed || revision !== current || name !== loginName()) return;
      apply(data);
    } catch (error) {
      if (!disposed && current === revision) state.error = error.message || '验证码读取失败，请重试。';
    } finally {
      if (controller === pending) controller = null;
      if (!disposed && current === revision) state.loading = false;
    }
  }

  async function ready() {
    if (state.loading || disposed) return false;
    if (!state.required) return true;
    if (!state.captcha || Date.now() >= expiresAt) {
      await refresh();
      if (state.required) { state.error ||= '请填写新验证码。'; return false; }
    }
    if (state.required && !state.code.trim()) { state.error = '请填写验证码。'; return false; }
    return true;
  }

  function payload() {
    return state.required ? { captcha_id: state.captcha?.id || '', captcha_code: state.code } : {};
  }
  watch(loginName, () => clear(true));
  onBeforeUnmount(() => { disposed = true; clear(); });
  return { state, accept, clear, refresh, ready, payload };
}
