<template>
  <section class="login-captcha" aria-label="登录安全验证码">
    <label :for="inputId">验证码</label>
    <div class="login-captcha-row">
      <input :id="inputId" :value="modelValue" :disabled="busy || expired || !image" maxlength="16" autocomplete="off" autocapitalize="characters" :spellcheck="false" placeholder="输入图片字符" @input="emit('update:modelValue', $event.target.value)">
      <button type="button" class="login-captcha-image" :disabled="busy" title="刷新验证码" aria-label="刷新验证码" @click="emit('refresh')"><img v-if="image" :src="image" alt="登录验证码" @error="imageFailed = true"><span v-else>点击获取</span></button>
    </div>
    <p v-if="expired || imageFailed" role="alert">{{ imageFailed ? '验证码图片读取失败，请刷新。' : '验证码已过期，请刷新。' }}</p>
    <small v-else>输入图片中的字符，不区分大小写。点击图片可刷新。</small>
  </section>
</template>
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
const props = defineProps({ captcha: { type: Object, default: null }, modelValue: { type: String, default: '' }, busy: { type: Boolean, default: false } });
const emit = defineEmits(['update:modelValue', 'refresh']);
const inputId = `login-captcha-${useId()}`;
const now = ref(Date.now()), deadline = ref(0), imageFailed = ref(false);
let timer;
const image = computed(() => {
  const value = props.captcha?.image;
  return typeof value === 'string' && value.length <= 256000 && /^data:image\/(?:jpeg|png);base64,[A-Za-z0-9+/]+={0,2}$/.test(value) ? value : '';
});
const expired = computed(() => Boolean(props.captcha) && now.value >= deadline.value);
watch(() => props.captcha, value => {
  now.value = Date.now(); imageFailed.value = false;
  const seconds = Number(value?.expires_in);
  deadline.value = now.value + (Number.isFinite(seconds) && seconds > 0 ? Math.min(seconds, 600) * 1000 : 0);
}, { immediate: true });
onMounted(() => { timer = window.setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => window.clearInterval(timer));
</script>
<style scoped>
.login-captcha{display:flex;flex-direction:column;gap:9px;text-align:left}.login-captcha label{margin:0;font-size:13px;font-weight:500}.login-captcha-row{display:flex;gap:10px;align-items:stretch}.login-captcha-row input{width:0;flex:1;min-width:0;min-height:46px;padding:10px 12px;border:1px solid var(--line,var(--app-line,#d9dfe5));border-radius:8px;color:inherit;background:var(--surface,var(--app-surface,transparent));font:inherit}.login-captcha-image{display:flex;align-items:center;justify-content:center;flex:none;width:150px;min-height:46px;padding:0;border:1px solid var(--line,var(--app-line,#d9dfe5));border-radius:8px;overflow:hidden;background:#fff;color:#333;cursor:pointer}.login-captcha-image img{width:100%;height:46px;object-fit:contain}.login-captcha :disabled{opacity:.6;cursor:default}.login-captcha input:focus-visible,.login-captcha button:focus-visible{outline:2px solid var(--primary,var(--app-primary,#1875dc));outline-offset:2px}.login-captcha small{font-size:12px;line-height:1.6;color:var(--muted,var(--app-text-secondary,var(--connection-muted,#64748b)))}.login-captcha p{margin:0;font-size:12px;line-height:1.6;color:var(--el-color-danger,var(--app-danger,#b63737))}@media(prefers-color-scheme:dark){.login-captcha p{color:var(--el-color-danger,var(--app-danger,#ff9e9e))}}@media(max-width:390px){.login-captcha-image{width:125px}}
</style>
