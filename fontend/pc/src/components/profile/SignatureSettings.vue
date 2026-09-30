<template>
  <section class="signature-settings">
    <h3>个人电子签名</h3>
    <p>用于审批时由本人确认使用。重新签名会生成新版本，历史审批保留原签名。此处不替代安全承诺书签字文件。</p>
    <div v-if="signature" class="signature-preview">
      <img :src="backendUrl(signature.url)" alt="当前个人签名">
      <span>版本 {{ signature.version }} · {{ signature.created_at }}</span>
    </div>
    <p v-else-if="!loading">尚未设置个人签名</p>
    <p v-if="message" role="status">{{ message }}</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <button type="button" :disabled="busy || loading" @click="create">{{ signature ? '手机扫码重新签名' : '手机扫码签名' }}</button>
    <div v-if="session" class="signature-session">
      <QRCode :value="session.scan_url" label="手机签名二维码" />
      <p>请在手机登录同一系统用户后签名。二维码 {{ seconds }} 秒后失效。</p>
      <button type="button" :disabled="busy" @click="cancel">取消本次签名</button>
    </div>
  </section>
</template>
<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import QRCode from '../../../../shared/components/QRCode.vue';
import { backendUrl, request } from '../../api/client';
const signature = ref(null);
const session = ref(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const message = ref('');
const seconds = ref(0);
let pollTimer = null;
let clockTimer = null;
let generation = 0;
let alive = true;
function stop() { clearTimeout(pollTimer); clearInterval(clockTimer); pollTimer = null; clockTimer = null; generation++; }
async function load() { signature.value = (await request('/signature/current')).signature; }
async function create() {
  busy.value = true; error.value = ''; message.value = ''; stop(); session.value = null;
  try {
    const created = await request('/signature/session', { method: 'POST', body: '{}' });
    if (!alive) { await request('/signature/session-cancel', { method: 'POST', body: JSON.stringify({ session_id: created.session_id }) }); return; }
    session.value = created; seconds.value = created.expires_in;
    const expiresAt = Date.now() + seconds.value * 1000;
    const revision = generation;
    clockTimer = setInterval(() => { seconds.value = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000)); }, 1000);
    pollTimer = setTimeout(() => poll(revision), 1800);
  } catch (failure) { if (alive) error.value = failure.message; }
  finally { busy.value = false; }
}
async function poll(revision) {
  const current = session.value;
  if (!current || revision !== generation || !alive) return;
  try {
    const status = await request(`/signature/session-status?session_id=${encodeURIComponent(current.session_id)}`);
    if (revision !== generation || !alive) return;
    if (status.state === 'confirmed') { stop(); session.value = null; await load(); message.value = '签名已保存，新版本将用于后续本人确认的审批。'; return; }
    if (status.state !== 'pending') { stop(); session.value = null; message.value = '签名会话已结束，请重新获取二维码。'; return; }
    pollTimer = setTimeout(() => poll(revision), 1800);
  } catch (failure) {
    if (revision !== generation || !alive) return;
    stop(); session.value = null; error.value = failure.message;
  }
}
async function cancel() {
  const current = session.value;
  if (!current) return;
  busy.value = true; error.value = '';
  try { await request('/signature/session-cancel', { method: 'POST', body: JSON.stringify({ session_id: current.session_id }) }); stop(); session.value = null; }
  catch (failure) { error.value = failure.message; }
  finally { busy.value = false; }
}
onMounted(async () => { try { await load(); } catch (failure) { error.value = failure.message; } finally { loading.value = false; } });
onBeforeUnmount(() => {
  alive = false; stop();
  if (session.value) request('/signature/session-cancel', { method: 'POST', body: JSON.stringify({ session_id: session.value.session_id }) }).catch(() => {});
});
</script>
<style scoped>
.signature-settings { padding: 20px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); color: var(--text); }
h3 { margin: 0 0 12px; }
p { line-height: 1.6; font-size: 14px; }
.signature-preview { display: flex; align-items: center; gap: 20px; margin: 18px 0; }
.signature-preview img { object-fit: contain; max-width: 240px; max-height: 100px; padding: 12px; border-radius: 6px; background: #fff; }
.signature-preview span { font-size: 13px; color: var(--muted); }
.signature-session { margin-top: 20px; text-align: center; }
button { border: 1px solid var(--line); padding: 9px 14px; border-radius: 6px; background: var(--surface); color: var(--text); cursor: pointer; }
button:disabled { opacity: .5; cursor: default; }
.error { color: #b91c1c; }
</style>
