<template>
  <div class="mobile-binding">
    <el-input v-model="mobile" inputmode="tel" maxlength="11" placeholder="请输入手机号" :disabled="busy" />
    <div class="mobile-code-row">
      <el-input v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="请输入验证码" :disabled="busy" />
      <el-button :disabled="busy || remaining > 0 || !validMobile" @click="send">{{ remaining ? `${remaining}s` : '获取验证码' }}</el-button>
      <el-button type="primary" :disabled="busy || !validMobile || !/^\d{6}$/.test(code)" :loading="busy" @click="verify">验证绑定</el-button>
    </div>
    <small v-if="message" role="status">{{ message }}</small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { sendMobileCode, verifyMobile } from '../api/system';
const props = defineProps({ mobile: { type: String, default: '' } });
const emit = defineEmits(['verified']);
const mobile = ref(props.mobile);
const code = ref('');
const busy = ref(false);
const remaining = ref(0);
const message = ref('');
const validMobile = computed(() => /^1\d{10}$/.test(mobile.value));
let timer;
watch(() => props.mobile, value => { mobile.value = value; });
onBeforeUnmount(() => clearInterval(timer));
async function send() {
  if (busy.value || remaining.value || !validMobile.value) return;
  busy.value = true;
  message.value = '';
  try {
    await sendMobileCode({ mobile: mobile.value });
    remaining.value = 60;
    clearInterval(timer);
    timer = setInterval(() => { if (--remaining.value <= 0) clearInterval(timer); }, 1000);
  } catch (error) { message.value = error.message; }
  finally { busy.value = false; }
}
async function verify() {
  if (busy.value) return;
  busy.value = true;
  message.value = '';
  try {
    const data = await verifyMobile({ mobile: mobile.value, sms_code: code.value });
    code.value = '';
    message.value = '手机号已验证';
    emit('verified', data);
  } catch (error) { message.value = error.message; }
  finally { busy.value = false; }
}
</script>

<style scoped>
.mobile-binding { display: grid; gap: 10px; min-width: 0; }
.mobile-code-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.mobile-code-row > .el-input { flex: 1 1 120px; min-width: 100px; }
.mobile-code-row > .el-button { margin: 0; }
.mobile-binding small { color: #525866; line-height: 1.5; }
</style>
