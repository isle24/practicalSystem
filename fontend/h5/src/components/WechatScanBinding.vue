<template>
  <main class="wechat-scan-page">
    <section aria-labelledby="scan-title">
      <h1 id="scan-title">确认企业微信绑定</h1>
      <p v-if="loading">正在读取扫码信息</p>
      <p v-if="done" role="status">绑定成功，请返回电脑继续操作。</p>
      <form v-else-if="details" @submit.prevent="confirm">
        <dl>
          <dt>系统账号</dt><dd>{{ details.account_name }}</dd>
          <dt>用户姓名</dt><dd>{{ details.user_name }}</dd>
          <dt>企业微信身份</dt><dd>{{ details.wechat_userid }}</dd>
        </dl>
        <p>请核对两端身份，仅绑定本人账号。输入该系统账号密码后确认，无需在手机登录。</p>
        <label for="scan-account-password">系统账号密码</label>
        <input id="scan-account-password" v-model="password" type="password" autocomplete="current-password" maxlength="4096" required :disabled="busy || expired">
        <button type="submit" :disabled="busy || expired || !password">{{ busy ? '正在确认' : expired ? '二维码已过期' : '确认绑定' }}</button>
      </form>
      <p v-if="message" role="alert">{{ message }}</p>
      <p v-if="!done">取消或过期后，请在电脑重新生成二维码。</p>
    </section>
  </main>
</template>
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { request } from '../api/client';
const details = ref(null);
const password = ref('');
const loading = ref(true);
const busy = ref(false);
const done = ref(false);
const expired = ref(false);
const message = ref('');
let timer;
onMounted(async () => {
  try {
    details.value = await request('/wechat/scan/context');
    timer = setTimeout(() => { expired.value = true; password.value = ''; message.value = '二维码已过期，请在电脑重新生成。'; }, details.value.expires_in * 1000);
  } catch (error) { message.value = error.message; }
  finally { loading.value = false; }
});
onBeforeUnmount(() => { clearTimeout(timer); password.value = ''; });
async function confirm() {
  if (busy.value || expired.value) return;
  busy.value = true;
  message.value = '';
  try {
    await request('/wechat/scan/confirm', { method: 'POST', body: JSON.stringify({ nonce: details.value.nonce, password: password.value }) });
    done.value = true;
    clearTimeout(timer);
  } catch (error) { message.value = error.message; }
  finally { password.value = ''; busy.value = false; }
}
</script>
<style scoped>
.wechat-scan-page { min-height: 100dvh; display: grid; place-items: center; padding: 20px; box-sizing: border-box; background: #f1f5f9; color: #172033; }
section { width: min(100%, 440px); box-sizing: border-box; padding: 24px; border-radius: 14px; background: #fff; }
h1 { font-size: 22px; margin-top: 0; }
p { line-height: 1.7; }
dt { margin-top: 14px; color: #64748b; font-size: 13px; }
dd { margin: 5px 0; overflow-wrap: anywhere; }
label { display: block; margin-top: 20px; }
input, button { box-sizing: border-box; width: 100%; margin-top: 10px; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font: inherit; }
button { background: #2563eb; color: #fff; border: 0; }
button:disabled { opacity: .5; }
[role="alert"] { color: #b91c1c; }
</style>
