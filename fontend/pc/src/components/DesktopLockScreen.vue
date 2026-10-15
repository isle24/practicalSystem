<template>
  <Teleport to="body">
    <section ref="screen" class="desktop-lock-screen" role="dialog" aria-modal="true" aria-labelledby="desktop-lock-heading" tabindex="-1" @keydown="trapFocus">
      <header class="desktop-lock-window-controls"><div @mousedown="windowAction('drag')">实践系统</div><button type="button" aria-label="最小化" @click="windowAction('minimize')"><Minus :size="15" /></button><button type="button" aria-label="最大化或还原" @click="windowAction('maximize')"><Square :size="13" /></button><button type="button" aria-label="关闭学校窗口" @click="windowAction('close')"><X :size="17" /></button></header>
      <div v-if="recovery" class="desktop-lock-recovery"><PasswordRecovery :request="lock.recoveryRequest" :public-origin="publicOrigin" @close="recovery = false" @done="recovered" /></div>
      <div v-else class="desktop-lock-card">
        <LoginModeToggle v-if="lock.locked && lock.initialized" :mode="mode" :disabled="lock.busy || !lock.initialized" @update:mode="selectMode" />
        <div class="desktop-lock-mark"><LockKeyhole :size="28" /></div>
        <h1 id="desktop-lock-heading">{{ !lock.initialized ? (lock.error ? '无法读取锁定状态' : '正在读取锁定状态') : lock.locked ? '客户端已锁定' : '正在恢复工作状态' }}</h1>
        <p v-if="lock.initialized" class="desktop-lock-identity">{{ lock.context?.school_name || '当前学校' }}<br>{{ lock.context?.user_name || lock.context?.login_name || '当前账号' }}<span v-if="lock.context?.login_name">{{ lock.context.login_name }}</span></p>
        <p class="desktop-lock-help">{{ lock.initialized ? '工作状态已保留，请验证当前账号以继续。' : '工作区保持锁定，读取客户端状态后才能继续。' }}</p>
        <button v-if="!lock.initialized" type="button" :disabled="lock.busy || refreshing" @click="refreshStatus">{{ refreshing ? '正在读取…' : '重新读取锁定状态' }}</button>

        <form v-if="lock.initialized && lock.locked && mode === 'password'" class="desktop-lock-form" @submit.prevent="unlock">
          <div class="desktop-lock-password-types" role="group" aria-label="密码验证方式"><button v-for="item in passwordTypes" :key="item.value" type="button" :aria-pressed="passwordType === item.value" :disabled="lock.busy || !lock.initialized" @click="selectPasswordType(item.value)">{{ item.label }}</button></div>
          <label for="desktop-unlock-password">{{ passwordType === 'local' ? '本机锁屏密码' : '当前账号密码' }}</label><input id="desktop-unlock-password" ref="passwordInput" v-model="password" type="password" :autocomplete="passwordType === 'local' ? 'off' : 'current-password'" :disabled="lock.busy || !lock.initialized" required :placeholder="passwordType === 'local' ? '请输入已设置的本机锁屏密码' : '请输入当前账号密码'">
          <p v-if="passwordType === 'local'" class="desktop-lock-password-hint">仅用于此设备的当前学校和账号。未设置或忘记时，可使用账号密码或企业微信解锁。</p>
          <button type="submit" class="desktop-lock-primary" :disabled="lock.busy || !password || !lock.initialized">{{ lock.busy ? '正在验证…' : '解锁' }}</button>
        </form>
        <div v-else-if="lock.initialized && lock.locked" class="desktop-lock-qr">
          <QRCode v-if="scan && ['pending', 'scanned'].includes(scan.state)" :value="scan.scan_url" :size="220" label="当前账号企业微信解锁二维码" />
          <p v-if="scan && ['pending', 'scanned'].includes(scan.state)" role="status">{{ scan.state === 'scanned' ? '已扫码，请在企业微信完成验证' : '请用当前账号绑定的企业微信扫码' }}<br>剩余 {{ seconds }} 秒</p>
          <p v-else>{{ scan?.state === 'expired' ? '二维码已过期' : '请获取解锁二维码' }}</p>
          <button type="button" :disabled="lock.busy || !lock.initialized" @click="createScan">{{ lock.busy ? '正在生成…' : '重新获取二维码' }}</button>
        </div>
        <p v-if="message || lock.error" class="desktop-lock-error" role="alert">{{ message || lock.error }}</p>
        <div v-if="lock.locked" class="desktop-lock-actions"><button v-if="lock.initialized" type="button" :disabled="lock.busy" @click="openRecovery">忘记密码</button><button type="button" :disabled="lock.busy" @click="exit">退出原会话并重新登录</button></div>
      </div>
    </section>
  </Teleport>
</template>
<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { LockKeyhole, Minus, Square, X } from '@lucide/vue';
import QRCode from '../../../shared/components/QRCode.vue';
import LoginModeToggle from '../../../shared/components/LoginModeToggle.vue';
import PasswordRecovery from '../../../shared/components/PasswordRecovery.vue';
const props = defineProps({ lock: { type: Object, required: true }, publicOrigin: { type: [String, Function], default: '' } });
const screen = ref(null);
const passwordInput = ref(null);
const password = ref('');
const mode = ref('password');
const passwordType = ref('account');
const passwordTypes = [{ value: 'account', label: '账号密码' }, { value: 'local', label: '本机锁屏密码' }];
const message = ref('');
const recovery = ref(false);
const refreshing = ref(false);
const scan = ref(null);
const now = ref(Date.now());
const seconds = computed(() => scan.value ? Math.max(0, Math.ceil((scan.value.expiresAt - now.value) / 1000)) : 0);
let generation = 0;
let disposed = false;
let pollTimer = null;
let countdown = null;
let previousFocus = null;
let blocked = [];

async function windowAction(action) { try { await window.__PRACTICAL_DESKTOP__?.windowControls(action); } catch (error) { message.value = error.message; } }
function trapFocus(event) {
  if (event.key !== 'Tab') return;
  const items = [...screen.value.querySelectorAll('button:not(:disabled),input:not(:disabled),[tabindex="0"]')].filter(element => element.offsetParent !== null);
  if (!items.length) { event.preventDefault(); screen.value.focus(); return; }
  const first = items[0]; const last = items[items.length - 1];
  if (event.shiftKey && (document.activeElement === first || document.activeElement === screen.value)) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
function ensureFocus(event) { if (screen.value && !screen.value.contains(event.target)) screen.value.focus(); }
async function refreshStatus() {
  if (disposed || refreshing.value || props.lock.busy) return;
  refreshing.value = true;
  try { await props.lock.refresh(); } catch {} finally { if (!disposed) refreshing.value = false; }
}
async function cancelScan() {
  generation++; clearTimeout(pollTimer); pollTimer = null;
  const previous = scan.value; scan.value = null;
  if (previous?.session_id && ['pending', 'scanned', 'ready'].includes(previous.state)) await props.lock.cancelWechat(previous.session_id).catch(() => {});
}
async function selectMode(value) {
  if (disposed || props.lock.busy || !props.lock.initialized || value === mode.value) return;
  const cancellation = cancelScan(); const version = generation;
  password.value = ''; message.value = ''; props.lock.clearError(); mode.value = value;
  await cancellation;
  if (disposed || version !== generation) return;
  if (value === 'wechat') await createScan(); else { await nextTick(); if (!disposed && version === generation) passwordInput.value?.focus(); }
}
async function unlock() {
  if (disposed || props.lock.busy || !props.lock.initialized || !password.value) return;
  message.value = '';
  const value = password.value; password.value = '';
  try { await (passwordType.value === 'local' ? props.lock.unlockLocalPassword(value) : props.lock.unlockPassword(value)); } catch (error) { message.value = error.message; passwordInput.value?.focus(); }
}
async function selectPasswordType(value) {
  if (disposed || props.lock.busy || !props.lock.initialized || value === passwordType.value) return;
  passwordType.value = value; password.value = ''; message.value = ''; props.lock.clearError();
  await nextTick(); if (!disposed) passwordInput.value?.focus();
}
async function createScan() {
  if (props.lock.busy || disposed || !props.lock.initialized || recovery.value || mode.value !== 'wechat') return;
  const cancellation = cancelScan(); const version = generation; message.value = ''; props.lock.clearError();
  await cancellation;
  if (disposed || version !== generation || recovery.value || mode.value !== 'wechat') return;
  let created = '';
  try {
    const result = await props.lock.createWechat(); created = result.session_id;
    if (disposed || version !== generation) { if (created) await props.lock.cancelWechat(created).catch(() => {}); return; }
    const expected = typeof props.publicOrigin === 'function' ? props.publicOrigin() : props.publicOrigin;
    const origin = new URL(expected || window.__PRACTICAL_DESKTOP__.serverOrigin).origin;
    const url = new URL(result.scan_url);
    if (url.protocol !== 'https:' || url.origin !== origin || url.pathname !== '/api/auth/wechat-login/start') throw new Error('扫码地址与当前学校不一致');
    if (!/^[a-f0-9]{64}$/.test(result.session_id) || !['pending', 'scanned'].includes(result.state) || !Number.isFinite(Number(result.expires_in)) || Number(result.expires_in) <= 0) throw new Error('扫码解锁响应无效');
    scan.value = { ...result, expiresAt: Date.now() + Number(result.expires_in) * 1000 };
    now.value = Date.now(); schedulePoll(version);
  } catch (error) {
    if (created) await props.lock.cancelWechat(created).catch(() => {});
    if (!disposed && version === generation) message.value = error.message;
  }
}
function schedulePoll(version) { pollTimer = setTimeout(() => poll(version), 2000); }
async function poll(version) {
  if (disposed || version !== generation || !scan.value) return;
  const current = scan.value;
  if (Date.now() >= current.expiresAt) { current.state = 'expired'; await props.lock.cancelWechat(current.session_id).catch(() => {}); return; }
  try {
    const result = await props.lock.pollWechat(current.session_id);
    if (disposed || version !== generation || scan.value !== current) return;
    message.value = '';
    current.state = result.state;
    if (result.state === 'ready') {
      try { await props.lock.consumeWechat(current.session_id); }
      catch (error) { if (!disposed && version === generation) { message.value = error.message; current.state = 'expired'; } }
      return;
    }
    if (['expired', 'cancelled', 'consumed'].includes(result.state)) return;
    if (!['pending', 'scanned'].includes(result.state)) throw new Error('扫码状态无效，请重新获取二维码');
  } catch (error) { if (!disposed && version === generation) message.value = error.message; }
  if (!disposed && version === generation) schedulePoll(version);
}
async function openRecovery() {
  if (disposed || props.lock.busy || !props.lock.initialized) return;
  const cancellation = cancelScan(); const version = generation;
  password.value = ''; message.value = ''; props.lock.clearError();
  await cancellation;
  if (!disposed && version === generation) recovery.value = true;
}
async function exit() {
  if (disposed || props.lock.busy) return;
  const cancellation = cancelScan(); const version = generation; password.value = '';
  await cancellation;
  if (disposed || version !== generation) return;
  try { await props.lock.exitSession(); } catch (error) { if (!disposed && version === generation) message.value = error.message; }
}
async function recovered() { recovery.value = false; message.value = '密码已重置，请退出原会话后重新登录。'; await exit(); }
onMounted(async () => {
  previousFocus = document.activeElement;
  blocked = [...document.body.children].filter(element => !element.contains(screen.value) && element.tagName !== 'SCRIPT').map(element => ({ element, inert: element.inert }));
  for (const item of blocked) item.element.inert = true;
  document.addEventListener('focusin', ensureFocus);
  countdown = setInterval(() => { now.value = Date.now(); }, 1000);
  await nextTick(); passwordInput.value?.focus();
});
onBeforeUnmount(() => {
  disposed = true; password.value = ''; cancelScan(); clearInterval(countdown);
  document.removeEventListener('focusin', ensureFocus);
  for (const item of blocked) item.element.inert = item.inert;
  if (previousFocus?.isConnected) previousFocus.focus();
});
</script>
<style scoped>
.desktop-lock-password-types{display:flex;gap:8px}.desktop-lock-password-types button{flex:1;min-width:0;font-size:13px}.desktop-lock-password-types button[aria-pressed="true"]{border-color:var(--primary,#2563eb);color:var(--primary,#2563eb);background:var(--surface-2,#eef2f6)}.desktop-lock-password-hint{margin:0;font-size:12px;line-height:1.7;color:var(--muted,#64748b)}
.desktop-lock-screen{position:fixed;inset:0;z-index:2147483000;display:flex;flex-direction:column;align-items:center;padding:56px 24px 24px;box-sizing:border-box;background:var(--surface-2,#eef2f6);overflow:auto;color:var(--text,#1f2937);outline:none}.desktop-lock-window-controls{position:absolute;inset:0 0 auto;display:flex;height:42px;align-items:stretch;user-select:none;border-bottom:1px solid var(--line,#dce2ea)}.desktop-lock-window-controls>div{flex:1;display:flex;align-items:center;padding:0 16px;font-size:12px}.desktop-lock-window-controls button{width:46px;display:grid;place-items:center;padding:0;border:0;border-radius:0;background:transparent}.desktop-lock-window-controls button:hover{background:var(--line,#dce2ea)}.desktop-lock-window-controls button:last-child:hover{background:#d92d36;color:#fff}.desktop-lock-card{position:relative;flex:none;margin:auto 0;width:min(100%,420px);padding:32px;box-sizing:border-box;border:1px solid var(--line,#dce2ea);border-radius:18px;background:var(--surface,#fff);box-shadow:0 20px 60px #00000014;text-align:center}.desktop-lock-mark{display:grid;place-items:center;width:56px;height:56px;margin:0 auto 18px;border-radius:16px;background:var(--surface-2,#eef2f6);color:var(--primary,#2563eb)}h1{margin:0 0 14px;font-size:23px;font-weight:600}.desktop-lock-identity{font-size:15px;line-height:1.8;margin:0}.desktop-lock-identity span{display:block;font-size:13px;color:var(--muted,#64748b)}.desktop-lock-help{font-size:13px;color:var(--muted,#64748b);line-height:1.7;margin:14px 0 20px}button,input{font:inherit}button{cursor:pointer;border:1px solid var(--line,#dce2ea);border-radius:8px;background:var(--surface,#fff);color:inherit;padding:10px 14px}button:disabled{cursor:default;opacity:.55}button:focus-visible,input:focus-visible{outline:2px solid var(--primary,#2563eb);outline-offset:2px}.desktop-lock-form{display:flex;flex-direction:column;gap:12px;text-align:left}.desktop-lock-form label{font-size:13px}.desktop-lock-form input{border:1px solid var(--line,#dce2ea);border-radius:8px;padding:12px;background:var(--surface,#fff);color:inherit;min-width:0}.desktop-lock-primary{background:var(--primary,#2563eb);border-color:var(--primary,#2563eb);color:var(--primary-contrast,#fff)}.desktop-lock-error{font-size:13px;line-height:1.7;color:var(--el-color-danger,#dc2626);text-align:left;margin:16px 0 0;overflow-wrap:anywhere}.desktop-lock-actions{display:flex;justify-content:space-between;gap:16px;margin-top:22px}.desktop-lock-actions button{padding:0;border:0;background:none;color:var(--muted,#64748b);font-size:12px;text-align:left}.desktop-lock-qr{display:flex;flex-direction:column;align-items:center;gap:10px}.desktop-lock-qr p{font-size:13px;line-height:1.8;color:var(--muted,#64748b);margin:0}.desktop-lock-recovery{flex:none;margin:auto 0;width:min(100%,500px);background:var(--surface,#fff);border:1px solid var(--line,#dce2ea);border-radius:18px;padding:28px;box-sizing:border-box}@media(max-width:520px){.desktop-lock-screen{padding:44px 12px 12px}.desktop-lock-card{padding:24px}.desktop-lock-recovery{padding:20px}}
</style>
