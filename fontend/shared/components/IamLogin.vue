<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FormFieldLabel from './FormFieldLabel.vue';

const props = defineProps({
  request: { type: Function, required: true },
  publicOrigin: { type: [String, Function], default: '' },
  client: { type: String, default: 'WEB' },
  displayName: { type: String, default: '统一身份认证' },
});
const emit = defineEmits(['logged-in']);
const session = ref(null);
const accounts = ref([]);
const selectedAccount = ref(null);
const busy = ref(false);
const message = ref('');
const now = ref(Date.now());
const seconds = computed(() => session.value ? Math.max(0, Math.ceil((session.value.expiresAt - now.value) / 1000)) : 0);
const active = computed(() => ['pending', 'authorizing', 'verifying', 'awaiting_return', 'ready'].includes(session.value?.state));
let generation = 0;
let disposed = false;
let pollTimer;
let clockTimer;
let controller;

function schoolOrigin() {
  const value = typeof props.publicOrigin === 'function' ? props.publicOrigin() : props.publicOrigin;
  return new URL(value || window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin).origin;
}
function storageKey() { return `practical:iam-login:${schoolOrigin()}:${props.client}`; }
function remember(id) {
  try {
    if (id) sessionStorage.setItem(storageKey(), id);
    else sessionStorage.removeItem(storageKey());
  } catch {
    if (id) throw new Error('浏览器无法保存认证进度，请允许当前学校使用会话存储后重试。');
  }
}
function stop() { clearTimeout(pollTimer); controller?.abort(); controller = null; }
function finished(state, text) {
  stop();
  if (session.value) session.value.state = state;
  accounts.value = [];
  selectedAccount.value = null;
  message.value = text;
  remember('');
}
async function cancelSession(id) {
  if (id) await props.request('/auth/iam/cancel', { method: 'POST', body: JSON.stringify({ session_id: id }), refreshOnUnauthorized: false, keepalive: true, timeoutMs: 5000 }).catch(() => {});
}
async function cancel() {
  if (busy.value) return;
  generation++;
  const id = session.value?.session_id;
  finished('cancelled', '已取消认证，可重新开始。');
  await cancelSession(id);
}
async function openAuthorization() {
  if (!session.value?.authorization_url || busy.value) return;
  try {
    if (window.__PRACTICAL_DESKTOP__) await window.__PRACTICAL_DESKTOP__.openExternal(session.value.authorization_url, 'browser');
    else if (props.client === 'H5') window.location.assign(session.value.authorization_url);
    else window.open(session.value.authorization_url, '_blank', 'noopener,noreferrer');
  } catch (error) { message.value = error.message || '打开学校认证失败，请重试。'; }
}
async function create() {
  if (busy.value || disposed) return;
  const popup = props.client === 'WEB' && !window.__PRACTICAL_DESKTOP__ ? window.open('about:blank', '_blank') : null;
  if (popup) popup.opener = null;
  const version = ++generation;
  const previous = session.value;
  stop();
  session.value = null;
  accounts.value = [];
  selectedAccount.value = null;
  message.value = '';
  busy.value = true;
  let id = '';
  try {
    if (previous && ['pending', 'authorizing', 'verifying', 'awaiting_return', 'ready'].includes(previous.state)) await cancelSession(previous.session_id);
    if (disposed || version !== generation) { popup?.close(); return; }
    const pending = new AbortController();
    controller = pending;
    const result = await props.request('/auth/iam/create', { method: 'POST', body: JSON.stringify({ client: props.client }), refreshOnUnauthorized: false, signal: pending.signal });
    id = result.session_id;
    if (disposed || version !== generation) { popup?.close(); return; }
    const url = new URL(result.authorization_url);
    if (url.origin !== schoolOrigin() || url.protocol !== 'https:' || url.pathname !== '/api/auth/iam/start' || url.username || url.password || url.hash
      || !/^[a-f0-9]{64}$/.test(id) || !Number.isFinite(result.expires_in) || result.expires_in <= 0) throw new Error('学校认证响应无效，请联系管理员。');
    remember(id);
    session.value = { session_id: id, state: result.state, authorization_url: url.href, expiresAt: Date.now() + result.expires_in * 1000 };
    now.value = Date.now();
    if (window.__PRACTICAL_DESKTOP__) await window.__PRACTICAL_DESKTOP__.openExternal(url.href, 'browser');
    else if (props.client === 'H5') window.location.assign(url.href);
    else if (popup) popup.location.replace(url.href);
    else message.value = '请点击“打开学校认证”继续授权。';
    pollTimer = setTimeout(() => poll(version), 1500);
  } catch (error) {
    popup?.close();
    if (!disposed && version === generation) finished('failed', error.message || '创建学校认证失败，请重试。');
    if (id) await cancelSession(id);
  } finally {
    if (!disposed && version === generation) { busy.value = false; controller = null; }
  }
}
async function poll(version = generation) {
  clearTimeout(pollTimer);
  if (disposed || version !== generation || !active.value || busy.value || controller) return;
  const current = session.value;
  const pending = new AbortController();
  controller = pending;
  try {
    const result = await props.request(`/auth/iam/status?session_id=${encodeURIComponent(current.session_id)}`, { refreshOnUnauthorized: false, signal: pending.signal });
    if (disposed || version !== generation || current !== session.value) return;
    current.expiresAt = Date.now() + result.expires_in * 1000;
    current.state = result.state;
    message.value = result.message || '';
    now.value = Date.now();
    if (result.state === 'ready') {
      accounts.value = result.accounts || [];
      selectedAccount.value = null;
    } else if (!['pending', 'authorizing', 'verifying', 'awaiting_return'].includes(result.state)) {
      finished(result.state, result.message || '本次认证已结束，请重新认证。');
    }
  } catch (error) {
    if (!disposed && version === generation && error.name !== 'AbortError') message.value = error.message || '读取认证状态失败，请刷新状态。';
  } finally {
    if (controller === pending) controller = null;
    if (!disposed && version === generation && ['pending', 'authorizing', 'verifying', 'awaiting_return'].includes(session.value?.state)) pollTimer = setTimeout(() => poll(version), 2000);
  }
}
async function login() {
  if (busy.value || disposed || session.value?.state !== 'ready' || !selectedAccount.value) return;
  if (seconds.value === 0) { finished('expired', '认证已过期，请重新开始。'); return; }
  const version = generation;
  const current = session.value;
  stop();
  const pending = new AbortController();
  controller = pending;
  busy.value = true;
  message.value = '';
  try {
    const result = await props.request('/auth/iam/consume', { method: 'POST', body: JSON.stringify({ session_id: current.session_id, account_id: selectedAccount.value }), refreshOnUnauthorized: false, signal: pending.signal });
    if (disposed || version !== generation) return;
    finished('consumed', '认证完成。');
    emit('logged-in', result);
  } catch (error) {
    if (!disposed && version === generation) finished('failed', error.message || '登录失败，请重新认证。');
  } finally {
    if (controller === pending) controller = null;
    if (!disposed && version === generation) busy.value = false;
  }
}
function resume() { if (active.value && session.value.state !== 'ready') poll(); }
onMounted(() => {
  try {
    const id = sessionStorage.getItem(storageKey());
    if (id) {
      if (!/^[a-f0-9]{64}$/.test(id)) remember('');
      else { session.value = { session_id: id, state: 'pending', expiresAt: Date.now() + 300000 }; poll(); }
    }
  } catch { message.value = '无法读取认证进度，请重新认证。'; }
  clockTimer = setInterval(() => {
    now.value = Date.now();
    if (active.value && !busy.value && seconds.value === 0) finished('expired', '认证已过期，请重新开始。');
  }, 1000);
  window.addEventListener('pagehide', stop);
  window.addEventListener('pageshow', resume);
});
onBeforeUnmount(() => {
  disposed = true;
  generation++;
  stop();
  clearInterval(clockTimer);
  window.removeEventListener('pagehide', stop);
  window.removeEventListener('pageshow', resume);
});
defineExpose({
  start() { if (!active.value) create(); },
});
</script>

<template>
  <section class="iam-login" :aria-label="`${displayName}登录`" :aria-busy="busy">
    <h2>{{ session?.state === 'ready' ? '学校身份已确认' : displayName }}</h2>
    <p v-if="!session || !active">使用已开通的本校学号或工号账号登录。</p>
    <p v-else-if="session.state === 'awaiting_return'" role="status">学校认证已完成，正在返回本机客户端。</p>
    <p v-else-if="session.state !== 'ready'" role="status">{{ ['authorizing', 'verifying'].includes(session.state) ? '正在核对学校身份，请稍候。' : '请在学校认证页面完成授权，然后返回此处。' }}</p>
    <p v-if="active" class="iam-meta">本次认证剩余 {{ seconds }} 秒</p>
    <fieldset v-if="session?.state === 'ready'">
      <legend><FormFieldLabel :required="true" help="请选择本次登录使用的本校账号。">登录账号</FormFieldLabel></legend>
      <label v-for="account in accounts" :key="account.id" class="iam-account">
        <input v-model="selectedAccount" type="radio" :value="account.id" name="iam-login-account" :disabled="busy">
        <span>{{ account.name }} · {{ account.login_name }}<small>{{ account.role_name }}</small></span>
      </label>
      <button type="button" class="iam-primary" :disabled="busy || !selectedAccount" @click="login">{{ busy ? '正在登录' : '确认登录' }}</button>
    </fieldset>
    <p v-if="message" class="iam-message" :class="{ 'is-error': ['failed', 'expired'].includes(session?.state) }" role="status">{{ message }}</p>
    <div class="iam-actions">
      <template v-if="active">
        <button v-if="session.authorization_url && session.state === 'pending'" type="button" :disabled="busy" @click="openAuthorization">打开学校认证</button>
        <button v-if="session.state !== 'ready'" type="button" :disabled="busy" @click="poll()">刷新状态</button>
        <button type="button" :disabled="busy" @click="cancel">取消认证</button>
      </template>
      <button v-else type="button" class="iam-primary" :disabled="busy" @click="create">{{ busy ? '正在准备认证' : session ? '重新认证' : '前往学校认证' }}</button>
    </div>
  </section>
</template>

<style scoped>
.iam-login { display: grid; gap: 14px; min-width: 0; color: var(--text, var(--app-text, inherit)); }
.iam-login h2 { margin: 8px 0; font-size: 20px; text-align: center; }
.iam-login p { margin: 0; font-size: 14px; line-height: 1.7; overflow-wrap: anywhere; }
.iam-meta { color: var(--muted, var(--app-text-secondary, #627083)); font-size: 12px !important; font-variant-numeric: tabular-nums; }
.iam-login fieldset { min-width: 0; margin: 0; padding: 12px; border: 1px solid var(--line, var(--app-line, #dce2e9)); border-radius: 8px; }
.iam-login .iam-account { display: flex; align-items: center; gap: 10px; padding: 12px 0; cursor: pointer; }
.iam-login .iam-account input { flex: 0 0 18px; width: 18px; min-height: 18px; margin: 0; padding: 0; }
.iam-account span { min-width: 0; overflow-wrap: anywhere; }
.iam-login .iam-account small { display: block; margin: 4px 0 0; padding: 0; background: transparent; color: var(--muted, var(--app-text-secondary, #627083)); }
.iam-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.iam-login button { min-height: 44px; padding: 10px 16px; border: 1px solid var(--line, var(--app-line, #dce2e9)); border-radius: var(--control-radius, 8px); background: var(--surface, var(--app-surface, #fff)); color: inherit; font: inherit; font-size: 14px; cursor: pointer; }
.iam-login .iam-primary { width: 100%; color: var(--primary-contrast, #fff); background: var(--primary, var(--app-primary, #4169ff)); border-color: transparent; }
.iam-login button:disabled { opacity: .6; cursor: default; }
.iam-login button:focus-visible { outline: 2px solid var(--primary, var(--app-primary, #4169ff)); outline-offset: 3px; }
.iam-message.is-error { color: var(--danger, var(--app-danger, #c9364f)); }
</style>
