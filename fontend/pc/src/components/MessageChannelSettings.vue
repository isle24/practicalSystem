<script setup>
import { onMounted, reactive, ref } from 'vue';
import { request } from '../api/client';

const busy = ref(false);
const error = ref('');
const success = ref('');
const form = reactive({ channels: ['internal'], sms_url: '', sms_sender: '', sms_token: '', sms_token_configured: false, clear_sms_token: false });
async function execute(action) {
  busy.value = true; error.value = ''; success.value = '';
  try { await action(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function save() {
  await execute(async () => {
    Object.assign(form, await request('/workflow-message/settings', { method: 'POST', body: JSON.stringify(form) }), { sms_token: '', clear_sms_token: false });
    success.value = '通知渠道已保存';
  });
}
onMounted(() => execute(async () => Object.assign(form, await request('/workflow-message/settings'))));
</script>

<template>
  <section class="channel-settings" aria-label="流程消息渠道">
    <h3>流程消息渠道</h3>
    <p>实际投递渠道由流程节点、学校设置、消息模板和个人通知偏好共同决定。</p>
    <form @submit.prevent="save">
      <fieldset :disabled="busy">
        <legend>学校允许渠道</legend>
        <label><input v-model="form.channels" type="checkbox" value="internal">站内消息</label>
        <label><input v-model="form.channels" type="checkbox" value="wechat">企业微信</label>
        <label><input v-model="form.channels" type="checkbox" value="sms">业务短信</label>
      </fieldset>
      <div class="channel-fields">
        <label>业务短信网关地址<input v-model.trim="form.sms_url" type="url" placeholder="https://sms.example.com/messages" :disabled="busy"></label>
        <label>短信签名<input v-model.trim="form.sms_sender" maxlength="80" :disabled="busy"></label>
        <label>网关凭据<input v-model="form.sms_token" type="password" autocomplete="new-password" :placeholder="form.sms_token_configured ? '已配置，留空保持不变' : '尚未配置'" :disabled="busy"></label>
        <label><input v-model="form.clear_sms_token" type="checkbox" :disabled="busy">清除已保存凭据</label>
      </div>
      <p>短信需接收人启用短信通知并绑定已验证手机号。企业微信沿用现有应用配置。</p>
      <button class="primary-button" type="submit" :disabled="busy">{{ busy ? '处理中' : '保存渠道设置' }}</button>
      <p v-if="error" role="alert">{{ error }}</p>
      <p v-if="success" role="status">{{ success }}</p>
    </form>
  </section>
</template>

<style scoped>
.channel-settings { padding: 20px; }
.channel-settings p { color: #64748b; font-size: 13px; line-height: 1.7; }
fieldset { display: flex; gap: 24px; border: 1px solid #dbe2ec; padding: 16px; margin: 16px 0; }
.channel-fields { display: grid; gap: 14px; max-width: 620px; }
.channel-fields label { display: grid; gap: 6px; font-size: 14px; }
.channel-fields label:has(input[type=checkbox]) { display: flex; align-items: center; }
input:not([type=checkbox]) { padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; }
[role=alert] { color: #b91c1c !important; }
[role=status] { color: #15803d !important; }
</style>
