<template>
  <section class="profile-panel-card desktop-lock-settings">
    <header><strong>客户端锁定</strong><small>自动锁定设置仅保存在本机。</small></header>
    <form @submit.prevent="save">
      <div class="lock-setting-row"><div><strong id="desktop-lock-enabled">无操作自动锁定</strong><small>可用账号密码、本机锁屏密码或企业微信扫码解锁。</small></div><el-switch v-model="enabled" aria-labelledby="desktop-lock-enabled" :disabled="lock.busy || lock.locked" /></div>
      <div class="lock-setting-row"><label for="desktop-lock-minutes">无操作时长（分钟）</label><el-input-number id="desktop-lock-minutes" v-model="minutes" :min="1" :max="480" :precision="0" :disabled="lock.busy || lock.locked || !enabled" /></div>
      <p v-if="message || lock.error" class="lock-setting-message" :class="{ saved }" role="status">{{ message || lock.error }}</p>
      <div class="lock-setting-actions"><el-button :disabled="lock.busy" @click="emit('close')">返回</el-button><el-button type="primary" native-type="submit" :loading="lock.busy" :disabled="lock.locked">保存本机设置</el-button></div>
    </form>
    <form class="local-password-settings" @submit.prevent="savePassword">
      <div class="lock-setting-row"><div><strong>本机锁屏密码（可选）</strong><small>仅保存在此设备，按学校和账号分别保存，不修改账号密码。退出或关闭客户端后保留，直到手动修改或清除。</small></div><span class="local-password-status">{{ configured === null ? '状态未读取' : configured ? '已设置' : '未设置' }}</span></div>
      <div class="local-password-field"><label for="desktop-local-password">{{ configured ? '新的本机锁屏密码' : '设置本机锁屏密码' }}</label><el-input id="desktop-local-password" v-model="password" type="password" show-password autocomplete="new-password" placeholder="6 至 128 个字符" :disabled="lock.busy || lock.locked" /></div>
      <div class="local-password-field"><label for="desktop-local-confirmation">确认本机锁屏密码</label><el-input id="desktop-local-confirmation" v-model="confirmation" type="password" show-password autocomplete="new-password" placeholder="请再次输入" :disabled="lock.busy || lock.locked" /></div>
      <p v-if="passwordMessage" class="lock-setting-message" :class="{ saved: passwordSaved }" role="status">{{ passwordMessage }}</p>
      <div class="lock-setting-actions local-password-actions"><el-button :disabled="lock.busy || lock.locked" @click="readPassword">刷新状态</el-button><el-button :disabled="lock.busy || lock.locked || configured === false" @click="clearPassword">清除本机密码</el-button><el-button type="primary" native-type="submit" :loading="lock.busy" :disabled="lock.locked || !password || !confirmation">{{ configured ? '修改本机密码' : '保存本机密码' }}</el-button></div>
    </form>
  </section>
</template>
<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
const props = defineProps({ lock: { type: Object, required: true } });
const emit = defineEmits(['close']);
const enabled = ref(true);
const minutes = ref(30);
const message = ref('');
const saved = ref(false);
const password = ref('');
const confirmation = ref('');
const configured = ref(null);
const passwordMessage = ref('');
const passwordSaved = ref(false);
let passwordRevision = 0;
let disposed = false;
watch(() => [props.lock.settings.enabled, props.lock.settings.idle_minutes], ([on, idle]) => { enabled.value = on; minutes.value = idle; }, { immediate: true });
watch([() => props.lock.context?.school_id, () => props.lock.context?.account_id, () => props.lock.locked], () => {
  passwordRevision++; password.value = ''; confirmation.value = ''; configured.value = null; passwordMessage.value = ''; passwordSaved.value = false;
  if (!props.lock.locked && props.lock.context?.account_id) readPassword();
}, { immediate: true });
async function passwordOperation(operation, success = '') {
  const version = ++passwordRevision;
  passwordMessage.value = ''; passwordSaved.value = false;
  try {
    const result = await operation();
    if (disposed || version !== passwordRevision) return;
    configured.value = result.local_password_set === true;
    passwordMessage.value = success; passwordSaved.value = !!success;
  } catch (error) { if (!disposed && version === passwordRevision) passwordMessage.value = error.message; }
}
function readPassword() { return passwordOperation(() => props.lock.readLocalPassword()); }
async function savePassword() {
  if (props.lock.busy || props.lock.locked) return;
  if ([...password.value].length < 6 || [...password.value].length > 128) { passwordMessage.value = '本机锁屏密码须为 6 至 128 个字符'; passwordSaved.value = false; return; }
  if (password.value !== confirmation.value) { passwordMessage.value = '两次输入的本机锁屏密码不一致'; passwordSaved.value = false; return; }
  const value = password.value; const repeated = confirmation.value; password.value = ''; confirmation.value = '';
  await passwordOperation(() => props.lock.saveLocalPassword(value, repeated), '本机锁屏密码已保存，退出或关闭客户端后仍可使用');
}
async function clearPassword() {
  if (props.lock.busy || props.lock.locked) return;
  password.value = ''; confirmation.value = '';
  await passwordOperation(() => props.lock.clearLocalPassword(), '本机锁屏密码已清除，仍可用账号密码或企业微信解锁');
}
async function save() {
  message.value = ''; saved.value = false;
  if (!Number.isInteger(minutes.value) || minutes.value < 1 || minutes.value > 480) { message.value = '请输入 1 至 480 分钟'; return; }
  try { await props.lock.saveSettings({ enabled: enabled.value, idle_minutes: minutes.value }); message.value = '本机设置已保存'; saved.value = true; }
  catch (error) { message.value = error.message; }
}
onBeforeUnmount(() => { disposed = true; passwordRevision++; password.value = ''; confirmation.value = ''; });
</script>
<style scoped>
.desktop-lock-settings form{display:flex;flex-direction:column;gap:20px}.lock-setting-row{display:flex;justify-content:space-between;align-items:center;gap:20px}.lock-setting-row>div:first-child{display:flex;flex-direction:column;gap:6px}.lock-setting-row strong,.lock-setting-row label{font-size:14px;font-weight:500;color:var(--text)}.lock-setting-row small{font-size:12px;color:var(--muted);line-height:1.6}.lock-setting-actions{display:flex;justify-content:flex-end;gap:8px}.lock-setting-message{font-size:13px;color:var(--el-color-danger,#dc2626);margin:0}.lock-setting-message.saved{color:var(--el-color-success,#16a34a)}
.local-password-settings{margin-top:24px;padding-top:24px;border-top:1px solid var(--line)}.local-password-status{font-size:12px;color:var(--muted);white-space:nowrap}.local-password-field{display:flex;flex-direction:column;gap:8px}.local-password-field label{font-size:13px;color:var(--text)}.local-password-actions{flex-wrap:wrap}.local-password-actions .el-button{margin-left:0}.lock-setting-message{overflow-wrap:anywhere}@media(max-width:600px){.lock-setting-row{flex-wrap:wrap;gap:12px}.lock-setting-actions{flex-wrap:wrap}}
</style>
