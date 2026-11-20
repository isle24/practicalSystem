<template>
  <div class="iam-login-entry">
    <span class="iam-login-entry-label">其他方式</span>
    <button type="button" :disabled="disabled" @click="$emit('click')">
      <span class="iam-login-entry-icon"><ShieldCheck :size="16" aria-hidden="true" /></span>
      <span>{{ displayName }}</span>
    </button>
  </div>
</template>

<script setup>
import { ShieldCheck } from '@lucide/vue';

defineProps({ displayName: { type: String, default: '统一身份认证' }, disabled: Boolean });
defineEmits(['click']);
</script>

<style scoped>
.iam-login-entry { display: grid; justify-items: center; gap: 4px; min-width: 0; }
.iam-login-entry-label { display: flex; align-items: center; justify-self: stretch; gap: 12px; color: var(--muted, var(--app-text-secondary, #5f6b78)); font-size: 12px; line-height: 1.5; }
.iam-login-entry-label::before, .iam-login-entry-label::after { content: ''; flex: 1; height: 1px; background: var(--line, var(--app-line, #d9dfe5)); }
.iam-login-entry button { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; max-width: 100%; padding: 4px 10px; border: 0; border-radius: 8px; background: transparent; color: var(--primary-ink, var(--primary, #4169ff)); font: inherit; font-size: 13px; text-align: left; overflow-wrap: anywhere; cursor: pointer; }
.iam-login-entry button:hover:not(:disabled) { background: color-mix(in srgb, var(--primary, #4169ff) 8%, transparent); }
.iam-login-entry button:focus-visible { outline: 2px solid var(--primary, #4169ff); outline-offset: 2px; }
.iam-login-entry button:disabled { opacity: .55; cursor: default; }
.iam-login-entry-icon { display: grid; place-items: center; flex: none; width: 28px; height: 28px; border-radius: 50%; background: var(--primary, #4169ff); color: var(--primary-contrast, #fff); }
</style>
