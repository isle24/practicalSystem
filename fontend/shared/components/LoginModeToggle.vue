<template>
  <button type="button" class="login-mode-corner" :disabled="disabled" :aria-label="mode === 'password' ? '切换企业微信扫码登录' : '切换账号密码登录'" :title="mode === 'password' ? '企业微信扫码登录' : '账号密码登录'" @click="$emit('update:mode', mode === 'password' ? 'wechat' : 'password')">
    <QrCode v-if="mode === 'password'" :size="28" />
    <Monitor v-else :size="28" />
  </button>
</template>

<script setup>
import { Monitor, QrCode } from '@lucide/vue';
defineProps({ mode: { type: String, default: 'password' }, disabled: Boolean });
defineEmits(['update:mode']);
</script>

<style scoped>
.login-mode-corner { position: absolute; top: 12px; right: 12px; width: 58px; height: 58px; display: flex; justify-content: flex-end; align-items: flex-start; padding: 7px; clip-path: polygon(0 0,100% 0,100% 100%); border: 0; border-radius: 0 8px 0 0; background: #4169ff; color: #fff; cursor: pointer; }
.login-mode-corner:hover:not(:disabled) { background: #2852df; }
.login-mode-corner:focus-visible { clip-path: none; }
.login-mode-corner:disabled { cursor: default; opacity: .55; }
</style>
