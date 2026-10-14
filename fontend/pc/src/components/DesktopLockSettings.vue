<template>
  <section class="profile-panel-card desktop-lock-settings">
    <header><strong>客户端锁定</strong><small>自动锁定设置仅保存在本机。</small></header>
    <form @submit.prevent="save">
      <div class="lock-setting-row"><div><strong id="desktop-lock-enabled">无操作自动锁定</strong><small>锁定后需要当前账号密码或企业微信扫码验证。</small></div><el-switch v-model="enabled" aria-labelledby="desktop-lock-enabled" :disabled="lock.busy || lock.locked" /></div>
      <div class="lock-setting-row"><label for="desktop-lock-minutes">无操作时长（分钟）</label><el-input-number id="desktop-lock-minutes" v-model="minutes" :min="1" :max="480" :precision="0" :disabled="lock.busy || lock.locked || !enabled" /></div>
      <p v-if="message || lock.error" class="lock-setting-message" :class="{ saved }" role="status">{{ message || lock.error }}</p>
      <div class="lock-setting-actions"><el-button :disabled="lock.busy" @click="emit('close')">返回</el-button><el-button type="primary" native-type="submit" :loading="lock.busy" :disabled="lock.locked">保存本机设置</el-button></div>
    </form>
  </section>
</template>
<script setup>
import { ref, watch } from 'vue';
const props = defineProps({ lock: { type: Object, required: true } });
const emit = defineEmits(['close']);
const enabled = ref(true);
const minutes = ref(30);
const message = ref('');
const saved = ref(false);
watch(() => [props.lock.settings.enabled, props.lock.settings.idle_minutes], ([on, idle]) => { enabled.value = on; minutes.value = idle; }, { immediate: true });
async function save() {
  message.value = ''; saved.value = false;
  if (!Number.isInteger(minutes.value) || minutes.value < 1 || minutes.value > 480) { message.value = '请输入 1 至 480 分钟'; return; }
  try { await props.lock.saveSettings({ enabled: enabled.value, idle_minutes: minutes.value }); message.value = '本机设置已保存'; saved.value = true; }
  catch (error) { message.value = error.message; }
}
</script>
<style scoped>
.desktop-lock-settings form{display:flex;flex-direction:column;gap:20px}.lock-setting-row{display:flex;justify-content:space-between;align-items:center;gap:20px}.lock-setting-row>div:first-child{display:flex;flex-direction:column;gap:6px}.lock-setting-row strong,.lock-setting-row label{font-size:14px;font-weight:500;color:var(--text)}.lock-setting-row small{font-size:12px;color:var(--muted);line-height:1.6}.lock-setting-actions{display:flex;justify-content:flex-end;gap:8px}.lock-setting-message{font-size:13px;color:var(--el-color-danger,#dc2626);margin:0}.lock-setting-message.saved{color:var(--el-color-success,#16a34a)}
</style>
