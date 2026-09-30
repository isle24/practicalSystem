<template>
  <section class="wechat-login" aria-label="企业微信扫码登录">
    <p>使用已绑定系统账号的企业微信扫码登录。</p>
    <QRCode v-if="scan && ['pending', 'scanned'].includes(scan.state)" :value="scan.scan_url" label="企业微信扫码登录二维码" />
    <p v-if="scan && ['pending', 'scanned', 'ready'].includes(scan.state)" class="countdown">二维码剩余 {{ seconds }} 秒</p>
    <p v-if="scan?.state === 'scanned'">已扫码，请在企业微信中完成身份授权。</p>
    <fieldset v-if="scan?.state === 'ready' && accounts.length">
      <legend>{{ accounts.length > 1 ? '请选择登录账号' : '确认登录账号' }}</legend>
      <label v-for="account in accounts" :key="account.id" class="account-option">
        <input v-model="selectedAccount" type="radio" :value="account.id" name="wechat-login-account" :disabled="busy">
        <span>{{ account.name }} · {{ account.login_name }}<small>{{ account.role_name }}{{ organizationLabel(account) }}</small></span>
      </label>
      <button type="button" :disabled="busy || !selectedAccount" @click="login">{{ busy ? '正在登录' : '确认登录' }}</button>
    </fieldset>
    <p v-if="message" class="message" role="status">{{ message }}</p>
    <div class="actions">
      <button type="button" :disabled="busy" @click="create">{{ busy && !scan ? '正在生成二维码' : scan ? '刷新二维码' : '生成登录二维码' }}</button>
      <button v-if="scan && ['pending', 'scanned', 'ready'].includes(scan.state)" type="button" :disabled="busy" @click="cancel">取消扫码</button>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import QRCode from './QRCode.vue';

const props = defineProps({ request: { type: Function, required: true }, publicOrigin: { type: [String, Function], default: '' }, client: { type: String, default: 'WEB' } });
const emit = defineEmits(['logged-in']);
const scan = ref(null);
const accounts = ref([]);
const selectedAccount = ref(null);
const busy = ref(false);
const message = ref('');
const now = ref(Date.now());
const seconds = computed(() => scan.value ? Math.max(0, Math.ceil((scan.value.expiresAt - now.value) / 1000)) : 0);
let generation = 0;
let pollTimer = null;
let countdownTimer = null;
let disposed = false;
let pendingController = null;

function schoolOrigin() {
  const value = typeof props.publicOrigin === 'function' ? props.publicOrigin() : props.publicOrigin;
  return new URL(value || window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin).origin;
}

function organizationLabel(account) {
  const names = (account.organization_scopes || []).map(scope => scope.organization_name || scope.name || scope.profession_name || scope.dep_name).filter(Boolean);
  return names.length ? ` · ${[...new Set(names)].join('、')}` : '';
}

function stop() {
  clearTimeout(pollTimer);
  pollTimer = null;
  pendingController?.abort();
  pendingController = null;
}

async function cancelSession(id, requester = props.request) {
  if (!id) return;
  await requester('/auth/wechat-login/cancel', { method: 'POST', body: JSON.stringify({ session_id: id }), keepalive: true, refreshOnUnauthorized: false, timeoutMs: 5000 }).catch(() => {});
}

async function cancel() {
  generation++;
  stop();
  const current = scan.value;
  scan.value = null;
  accounts.value = [];
  selectedAccount.value = null;
  busy.value = false;
  message.value = '已取消扫码登录。';
  if (current && ['pending', 'scanned', 'ready'].includes(current.state)) await cancelSession(current.session_id);
}

async function create() {
  if (busy.value || disposed) return;
  const version = ++generation;
  const requester = props.request;
  const previous = scan.value;
  stop();
  scan.value = null;
  accounts.value = [];
  selectedAccount.value = null;
  busy.value = true;
  message.value = '';
  let createdId = '';
  try {
    if (previous && ['pending', 'scanned', 'ready'].includes(previous.state)) await cancelSession(previous.session_id, requester);
    if (disposed || version !== generation) return;
    const origin = schoolOrigin();
    if (new URL(origin).protocol !== 'https:') throw new Error('扫码登录需要学校 HTTPS 地址，请检查学校连接。');
    const result = await requester('/auth/wechat-login/create', { method: 'POST', body: JSON.stringify({ client: props.client }), refreshOnUnauthorized: false });
    createdId = result.session_id;
    if (disposed || version !== generation) { await cancelSession(createdId, requester); return; }
    const url = new URL(result.scan_url);
    if (url.origin !== origin || url.protocol !== 'https:' || url.pathname !== '/api/auth/wechat-login/start') throw new Error('扫码地址与当前学校不一致，请联系管理员。');
    if (!/^[a-f0-9]{64}$/.test(result.session_id) || !Number.isFinite(Number(result.expires_in)) || result.expires_in <= 0) throw new Error('扫码登录响应无效，请重新获取二维码。');
    scan.value = { ...result, expiresAt: Date.now() + result.expires_in * 1000 };
    now.value = Date.now();
    schedulePoll(version);
  } catch (error) {
    if (createdId) await cancelSession(createdId, requester);
    if (!disposed && version === generation) message.value = error.message || '二维码生成失败，请重试。';
  } finally {
    if (!disposed && version === generation) busy.value = false;
  }
}

function schedulePoll(version) {
  pollTimer = setTimeout(() => poll(version), 2000);
}

async function poll(version) {
  if (disposed || version !== generation || !scan.value || !['pending', 'scanned', 'ready'].includes(scan.value.state)) return;
  if (Date.now() >= scan.value.expiresAt) { expire(); return; }
  const current = scan.value;
  pendingController = new AbortController();
  try {
    const result = await props.request(`/auth/wechat-login/status?session_id=${encodeURIComponent(current.session_id)}`, { refreshOnUnauthorized: false, signal: pendingController.signal });
    if (disposed || version !== generation || scan.value !== current) return;
    current.state = result.state;
    message.value = result.message || '';
    if (result.state === 'ready') {
      accounts.value = result.accounts || [];
      selectedAccount.value = accounts.value.length === 1 ? accounts.value[0].id : null;
      return;
    }
    if (['expired', 'cancelled', 'unbound', 'failed', 'consumed'].includes(result.state)) {
      if (!message.value) message.value = result.state === 'expired' ? '二维码已过期，请重新获取。' : '扫码登录已结束，请重新获取二维码。';
      return;
    }
  } catch (error) {
    if (disposed || version !== generation || scan.value !== current) return;
    message.value = error.message || '读取扫码状态失败，请重试。';
  } finally {
    if (version === generation) pendingController = null;
  }
  if (!disposed && version === generation) schedulePoll(version);
}

function expire() {
  if (busy.value || !scan.value || !['pending', 'scanned', 'ready'].includes(scan.value.state)) return;
  const id = scan.value.session_id;
  scan.value.state = 'expired';
  accounts.value = [];
  selectedAccount.value = null;
  message.value = '二维码已过期，请重新获取。';
  generation++;
  stop();
  cancelSession(id);
}

async function login() {
  if (busy.value || !selectedAccount.value || scan.value?.state !== 'ready') return;
  const version = generation;
  busy.value = true;
  message.value = '';
  try {
    const result = await props.request('/auth/wechat-login/consume', { method: 'POST', body: JSON.stringify({ session_id: scan.value.session_id, account_id: selectedAccount.value }), refreshOnUnauthorized: false });
    if (disposed || version !== generation) return;
    scan.value.state = 'consumed';
    stop();
    emit('logged-in', result);
  } catch (error) {
    if (!disposed && version === generation) message.value = error.message || '登录失败，请重新扫码。';
  } finally {
    if (!disposed && version === generation) busy.value = false;
  }
}

watch(() => [schoolOrigin(), props.client], () => { cancel(); });
onMounted(() => {
  countdownTimer = setInterval(() => { now.value = Date.now(); if (scan.value && seconds.value === 0) expire(); }, 1000);
  window.addEventListener('pagehide', cancel);
  create();
});
onBeforeUnmount(() => {
  disposed = true;
  clearInterval(countdownTimer);
  window.removeEventListener('pagehide', cancel);
  cancel();
});
defineExpose({ cancel });
</script>

<style scoped>
.wechat-login { color: var(--theme-text, inherit); text-align: center; }
.wechat-login p { margin: 12px 0; line-height: 1.6; }
.countdown { font-size: 13px; opacity: .8; }
.message { overflow-wrap: anywhere; }
fieldset { margin: 12px 0; padding: 12px; border: 1px solid var(--theme-border, #cbd5e1); border-radius: 8px; text-align: left; }
.account-option { display: flex; align-items: center; gap: 10px; padding: 10px 0; cursor: pointer; }
.account-option small { display: block; margin-top: 4px; opacity: .8; }
.actions { display: flex; gap: 10px; justify-content: center; }
button { padding: 10px 16px; border: 1px solid var(--theme-border, #cbd5e1); border-radius: 8px; background: var(--theme-panel, #f8fafc); color: inherit; cursor: pointer; }
button:disabled { opacity: .6; cursor: wait; }
fieldset button { width: 100%; margin-top: 10px; background: var(--theme-accent, #2563eb); color: #fff; border-color: transparent; }
</style>
