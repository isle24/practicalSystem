<template>
  <van-cell title="手机号绑定" :value="boundMobile || '验证绑定'" is-link @click="open" />
  <AppSheet v-model="visible" title="手机号绑定" :close-on-overlay="!busy">
    <div class="mobile-binding-form">
      <van-field v-model="mobile" type="tel" maxlength="11" label="手机号" placeholder="请输入手机号" :disabled="busy" />
      <van-field v-model="code" type="digit" maxlength="6" autocomplete="one-time-code" label="验证码" placeholder="请输入验证码" :disabled="busy">
        <template #button>
          <AppButton size="small" variant="secondary" :disabled="busy || remaining > 0 || !validMobile" @click="send">{{ remaining ? `${remaining}s` : '获取验证码' }}</AppButton>
        </template>
      </van-field>
      <p v-if="message" role="status">{{ message }}</p>
    </div>
    <template #footer>
      <AppButton :loading="busy" :disabled="busy || !validMobile || !/^\d{6}$/.test(code)" @click="verify">验证绑定</AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { fetchProfileSettings, sendMobileCode, verifyMobile } from '../api/system';
import AppButton from './ui/AppButton.vue';
import AppSheet from './ui/AppSheet.vue';

const emit = defineEmits(['verified']);
const visible = ref(false);
const mobile = ref('');
const boundMobile = ref('');
const code = ref('');
const message = ref('');
const busy = ref(false);
const remaining = ref(0);
const validMobile = computed(() => /^1\d{10}$/.test(mobile.value));
let timer;
onBeforeUnmount(() => clearInterval(timer));

async function open() {
  if (busy.value) return;
  visible.value = true;
  busy.value = true;
  message.value = '';
  try {
    const data = await fetchProfileSettings();
    mobile.value = data.user.mobile || '';
    boundMobile.value = mobile.value;
  } catch (error) { message.value = error.message; }
  finally { busy.value = false; }
}

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
  if (busy.value || !validMobile.value || !/^\d{6}$/.test(code.value)) return;
  busy.value = true;
  message.value = '';
  try {
    const data = await verifyMobile({ mobile: mobile.value, sms_code: code.value });
    boundMobile.value = data.user.mobile || '';
    code.value = '';
    message.value = '手机号已验证';
    emit('verified', data);
  } catch (error) { message.value = error.message; }
  finally { busy.value = false; }
}
</script>

<style scoped>
.mobile-binding-form { padding: 12px 0 20px; }
.mobile-binding-form p { margin: 12px 16px 0; color: var(--app-text-secondary); line-height: 1.5; }
</style>
