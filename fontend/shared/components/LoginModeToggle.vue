<template>
  <div v-if="!iamAvailable && mode !== 'iam'" class="login-mode-tabs" role="group" aria-label="登录方式" :data-mode="mode">
    <button type="button" :disabled="disabled" :aria-pressed="mode === 'password'" @click="$emit('update:mode', 'password')">账号密码</button>
    <button type="button" :disabled="disabled" :aria-pressed="mode === 'wechat'" @click="$emit('update:mode', 'wechat')">扫码登录</button>
  </div>
  <button type="button" class="login-mode-corner" :disabled="disabled" :aria-label="mode === 'password' ? '切换企业微信扫码登录' : '切换账号密码登录'" :title="mode === 'password' ? '企业微信扫码登录' : '账号密码登录'" @click="$emit('update:mode', mode === 'password' ? 'wechat' : 'password')">
    <QrCode v-if="mode === 'password'" :size="24" aria-hidden="true" />
    <Monitor v-else :size="24" aria-hidden="true" />
  </button>
</template>

<script setup>
import { Monitor, QrCode } from '@lucide/vue';
defineProps({ mode: { type: String, default: 'password' }, iamAvailable: Boolean, disabled: Boolean });
defineEmits(['update:mode']);
</script>

<style scoped>
.login-mode-tabs { display: none; }
.login-mode-corner { position: absolute; top: 12px; right: 12px; width: 58px; height: 58px; display: grid; place-items: start end; padding: 5px; isolation: isolate; border: 0; border-radius: 0 8px 0 0; background: transparent; color: var(--primary-contrast, #fff); cursor: pointer; }
.login-mode-corner::before { content: ''; position: absolute; inset: 0; z-index: -1; border-radius: inherit; clip-path: polygon(0 0, 100% 0, 100% 100%); background: var(--primary, #4169ff); }
.login-mode-corner:hover:not(:disabled)::before { background: var(--primary-hover, color-mix(in srgb, var(--primary, #4169ff) 88%, #000)); }
.login-mode-corner:hover:not(:disabled) { color: var(--primary-hover-contrast, var(--primary-contrast, #fff)); }
.login-mode-corner:focus-visible { outline: 2px solid var(--primary, #4169ff); outline-offset: 3px; }
.login-mode-corner:disabled { cursor: default; opacity: .55; }
</style>
