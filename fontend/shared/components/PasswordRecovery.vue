<template>
  <section class="password-recovery" aria-label="找回密码" :aria-busy="busy">
    <header><h2>找回密码</h2><button type="button" class="close-button" @click="close">取消</button></header>
    <template v-if="completed">
      <p class="success" role="status">密码已重置，请使用新密码或企业微信重新登录。</p>
      <button type="button" class="primary-button" @click="finish">返回登录</button>
    </template>
    <template v-else>
      <p class="guidance">请使用已绑定账号的企业微信扫码验证身份，仅可重置当前学校中本人直接绑定的账号。</p>
      <template v-if="scan?.state !== 'ready'">
        <div class="scan-card">
          <QRCode v-if="scan && ['pending', 'scanned'].includes(scan.state)" :value="scan.scan_url" :size="260" label="企业微信找回密码二维码" />
          <p v-else>{{ busy ? '正在生成二维码…' : '请重新获取二维码' }}</p>
        </div>
        <p v-if="scan?.state === 'scanned'" class="guidance" role="status">已扫码，请在企业微信中完成授权。</p>
      </template>
      <p v-if="activeScan" class="countdown" role="timer">{{ scan.state === 'ready' ? '身份验证' : '二维码' }}剩余 {{ seconds }} 秒</p>
      <form v-if="scan?.state === 'ready'" class="reset-form" @submit.prevent="submit">
        <fieldset>
          <legend>选择本人账号</legend>
          <label v-for="account in accounts" :key="account.id" class="account-option">
            <input v-model="selectedAccount" type="radio" :value="account.id" name="password-recovery-account" :disabled="busy" required>
            <span>{{ account.name || account.login_name }} · {{ account.login_name }}<small>{{ account.role_name }}{{ organizationLabel(account) }}</small></span>
          </label>
        </fieldset>
        <label class="password-field"><span>新密码</span><input v-model="newPassword" type="password" autocomplete="new-password" maxlength="72" :disabled="busy" required placeholder="至少 6 个字符"></label>
        <label class="password-field"><span>确认新密码</span><input v-model="confirmPassword" type="password" autocomplete="new-password" maxlength="72" :disabled="busy" required placeholder="再次输入新密码"></label>
        <p class="guidance">重置后该账号原登录设备将退出，需要重新登录。</p>
        <button type="submit" class="primary-button" :disabled="busy || !selectedAccount">{{ submitting ? '正在重置…' : '重置密码' }}</button>
      </form>
      <p v-if="message" class="message" role="alert">{{ message }}</p>
      <div class="actions"><button type="button" :disabled="busy" @click="create">{{ busy && !scan ? '正在生成二维码…' : '重新获取二维码' }}</button></div>
      <p class="guidance">未绑定企业微信或无法扫码时，请联系学校管理员处理。</p>
    </template>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import QRCode from './QRCode.vue';

const props = defineProps({ request: { type: Function, required: true }, publicOrigin: { type: [String, Function], default: '' }, client: { type: String, default: 'WEB' } });
const emit = defineEmits(['close', 'done']);
const scan = ref(null);
const accounts = ref([]);
const selectedAccount = ref(null);
const newPassword = ref('');
const confirmPassword = ref('');
const busy = ref(false);
const submitting = ref(false);
const message = ref('');
const completed = ref(null);
const now = ref(Date.now());
const activeScan = computed(() => scan.value && ['pending', 'scanned', 'ready'].includes(scan.value.state));
const seconds = computed(() => scan.value ? Math.max(0, Math.ceil((scan.value.expiresAt - now.value) / 1000)) : 0);
const api = '/auth/password-recovery';
let generation = 0;
let disposed = false;
let pollTimer = null;
let countdownTimer = null;
let controller = null;

function schoolOrigin() {
  const value = typeof props.publicOrigin === 'function' ? props.publicOrigin() : props.publicOrigin;
  return new URL(value || window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin).origin;
}

function organizationLabel(account) {
  const names = (account.organization_scopes || []).map(scope => scope.organization_name || scope.name || scope.profession_name || scope.dep_name).filter(Boolean);
  return names.length ? ` · ${[...new Set(names)].join('、')}` : '';
}

function clearPasswords() {
  newPassword.value = '';
  confirmPassword.value = '';
}

function stop() {
  clearTimeout(pollTimer);
  pollTimer = null;
  controller?.abort();
  controller = null;
}

async function cancelSession(id, requester) {
  if (!id) return;
  await requester(`${api}/wechat/cancel`, { method: 'POST', body: JSON.stringify({ session_id: id }), keepalive: true, refreshOnUnauthorized: false, timeoutMs: 5000 }).catch(() => {});
}

function cancel() {
  generation++;
  stop();
  const previous = scan.value;
  scan.value = null;
  accounts.value = [];
  selectedAccount.value = null;
  busy.value = false;
  submitting.value = false;
  completed.value = null;
  clearPasswords();
  if (previous && ['pending', 'scanned', 'ready'].includes(previous.state)) return cancelSession(previous.session_id, previous.requester);
}

function close() {
  cancel();
  emit('close');
}

function finish() {
  const account = completed.value;
  cancel();
  if (account) emit('done', account);
}

async function create() {
  if (busy.value || disposed) return;
  const cancellation = cancel();
  const version = generation;
  const requester = props.request;
  busy.value = true;
  message.value = '';
  let createdId = '';
  const pending = new AbortController();
  controller = pending;
  try {
    await cancellation;
    if (disposed || version !== generation) return;
    const origin = schoolOrigin();
    if (new URL(origin).protocol !== 'https:') throw new Error('找回密码需要学校 HTTPS 地址，请检查学校连接。');
    const result = await requester(`${api}/wechat/create`, { method: 'POST', body: JSON.stringify({ client: props.client }), signal: pending.signal, refreshOnUnauthorized: false });
    createdId = result.session_id;
    if (disposed || version !== generation) { await cancelSession(createdId, requester); return; }
    const url = new URL(result.scan_url);
    if (url.origin !== origin || url.protocol !== 'https:' || url.pathname !== '/api/auth/wechat-login/start') throw new Error('扫码地址与当前学校不一致，请联系管理员。');
    if (!/^[a-f0-9]{64}$/.test(result.session_id) || !['pending', 'scanned'].includes(result.state) || !Number.isFinite(Number(result.expires_in)) || Number(result.expires_in) <= 0) throw new Error('找回密码响应无效，请重新获取二维码。');
    scan.value = { session_id: result.session_id, scan_url: result.scan_url, state: result.state, requester, expiresAt: Date.now() + Number(result.expires_in) * 1000 };
    now.value = Date.now();
    schedulePoll(version);
  } catch (error) {
    if (createdId) await cancelSession(createdId, requester);
    if (!disposed && version === generation) message.value = error.message || '二维码生成失败，请重试。';
  } finally {
    if (controller === pending) controller = null;
    if (!disposed && version === generation) busy.value = false;
  }
}

function schedulePoll(version) {
  pollTimer = setTimeout(() => poll(version), 2000);
}

async function poll(version) {
  if (disposed || version !== generation || !activeScan.value || scan.value.state === 'ready') return;
  if (Date.now() >= scan.value.expiresAt) { expire(); return; }
  const current = scan.value;
  const pending = new AbortController();
  controller = pending;
  try {
    const result = await current.requester(`${api}/wechat/status?session_id=${encodeURIComponent(current.session_id)}`, { signal: pending.signal, refreshOnUnauthorized: false });
    if (disposed || version !== generation || scan.value !== current) return;
    if (result.session_id !== current.session_id) throw new Error('扫码状态不匹配，请重新获取二维码。');
    const remaining = Number(result.expires_in);
    if (!Number.isFinite(remaining)) throw new Error('扫码状态无效，请重新获取二维码。');
    current.expiresAt = Math.min(current.expiresAt, Date.now() + Math.max(0, remaining) * 1000);
    now.value = Date.now();
    if (Date.now() >= current.expiresAt || result.state === 'expired') { expire(); return; }
    current.state = result.state;
    if (result.state === 'ready') {
      accounts.value = Array.isArray(result.accounts) ? result.accounts.filter(account => Number(account.id) > 0 && account.login_name) : [];
      selectedAccount.value = accounts.value.length === 1 ? accounts.value[0].id : null;
      message.value = accounts.value.length ? '' : '当前学校没有该企业微信直接绑定的本人账号，请联系管理员。';
      return;
    }
    if (!['pending', 'scanned'].includes(result.state)) {
      message.value = result.message || '扫码验证已结束，请重新获取二维码。';
      return;
    }
    message.value = '';
  } catch (error) {
    if (disposed || version !== generation || scan.value !== current) return;
    message.value = error.message || '读取扫码状态失败，请重试。';
  } finally {
    if (controller === pending) controller = null;
  }
  if (!disposed && version === generation && activeScan.value) schedulePoll(version);
}

function expire() {
  if (!activeScan.value || submitting.value) return;
  const current = scan.value;
  generation++;
  stop();
  current.state = 'expired';
  accounts.value = [];
  selectedAccount.value = null;
  clearPasswords();
  message.value = '二维码或身份验证已过期，请重新获取二维码。';
  cancelSession(current.session_id, current.requester);
}

async function submit() {
  if (disposed || busy.value || scan.value?.state !== 'ready') return;
  if (Date.now() >= scan.value.expiresAt) { expire(); return; }
  if (!accounts.value.some(account => account.id === selectedAccount.value)) { message.value = '请选择本人账号。'; return; }
  if (!newPassword.value.trim() || newPassword.value.includes('\0') || Array.from(newPassword.value).length < 6 || new TextEncoder().encode(newPassword.value).length > 72) { message.value = '密码至少 6 个字符，最多 72 个 UTF-8 字节，不能包含空字符。'; return; }
  if (newPassword.value !== confirmPassword.value) { message.value = '两次输入的密码不一致。'; return; }
  const current = scan.value;
  const accountId = selectedAccount.value;
  const version = generation;
  const body = JSON.stringify({ session_id: current.session_id, account_id: accountId, new_password: newPassword.value, confirm_password: confirmPassword.value });
  clearPasswords();
  stop();
  const pending = new AbortController();
  controller = pending;
  busy.value = true;
  submitting.value = true;
  message.value = '';
  try {
    const result = await current.requester(`${api}/reset`, { method: 'POST', body, signal: pending.signal, refreshOnUnauthorized: false });
    if (disposed || version !== generation || scan.value !== current) return;
    current.state = 'consumed';
    accounts.value = [];
    selectedAccount.value = null;
    completed.value = { account_id: result.account_id, login_name: result.login_name };
  } catch (error) {
    if (!disposed && version === generation && scan.value === current) message.value = error.message || '重置失败，请重试。';
  } finally {
    if (controller === pending) controller = null;
    if (!disposed && version === generation && scan.value === current) {
      busy.value = false;
      submitting.value = false;
      if (!completed.value && Date.now() >= current.expiresAt) expire();
    }
  }
}

watch(selectedAccount, clearPasswords);
watch(() => [schoolOrigin(), props.client, props.request], () => { cancel(); message.value = ''; if (!disposed) create(); });
onMounted(() => {
  countdownTimer = setInterval(() => { now.value = Date.now(); if (activeScan.value && seconds.value === 0) expire(); }, 1000);
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
.password-recovery { width: 100%; min-width: 0; max-height: calc(100dvh - 32px); overflow-y: auto; overscroll-behavior: contain; box-sizing: border-box; padding: 16px 0 max(16px, env(safe-area-inset-bottom)); color: var(--text); background: var(--surface); }
header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
h2 { margin: 0; font-size: 20px; }
p { margin: 12px 0; line-height: 1.6; }
.guidance, .countdown { color: var(--muted); font-size: 13px; }
.scan-card { width: min(280px, 100%); aspect-ratio: 1; margin: 16px auto; display: grid; place-items: center; box-sizing: border-box; padding: 8px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface-2, var(--surface)); }
.scan-card p { padding: 12px; text-align: center; color: var(--muted); }
.countdown { text-align: center; }
.reset-form { display: grid; gap: 16px; }
fieldset { min-width: 0; margin: 0; padding: 12px; border: 1px solid var(--line); border-radius: 10px; }
legend { padding: 0 4px; font-size: 14px; }
.account-option { display: flex; align-items: center; gap: 10px; padding: 10px 0; overflow-wrap: anywhere; }
.account-option input { width: 18px; height: 18px; margin: 0; flex: 0 0 18px; }
.account-option span { min-width: 0; }
.account-option small { display: block; margin-top: 4px; color: var(--muted); }
.password-field { display: grid; gap: 8px; font-size: 14px; }
.password-field input { box-sizing: border-box; width: 100%; min-width: 0; min-height: 44px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface-2, var(--surface)); color: var(--text); font: inherit; font-size: 16px; }
button { min-height: 44px; padding: 10px 16px; border: 1px solid var(--line); border-radius: 8px; color: var(--text); background: var(--surface-2, var(--surface)); font: inherit; cursor: pointer; }
button:disabled { opacity: .6; cursor: wait; }
button:focus-visible, input:focus-visible { outline: 2px solid var(--primary-ink); outline-offset: 2px; }
.primary-button { width: 100%; color: var(--primary-contrast); background: var(--primary-ink); border-color: var(--primary-ink); }
.close-button { flex-shrink: 0; }
.actions { display: flex; justify-content: center; margin-top: 16px; }
.message { color: var(--danger, var(--text)); overflow-wrap: anywhere; }
.success { color: var(--text); }
@media (max-width: 360px) { .password-recovery { padding-top: 8px; } button { padding-inline: 12px; } }
</style>
