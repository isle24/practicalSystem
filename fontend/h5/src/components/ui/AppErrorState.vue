<template>
  <section class="app-error-state" role="alert">
    <span><CircleAlert :size="24" /></span>
    <strong>{{ title }}</strong>
    <p>{{ message }}</p>
    <AppButton v-if="retryable" variant="secondary" size="small" @click="emit('retry')">
      <template #icon><RefreshCw :size="15" /></template>
      重新加载
    </AppButton>
  </section>
</template>

<script setup>
import { CircleAlert, RefreshCw } from '@lucide/vue';
import AppButton from './AppButton.vue';

defineProps({
  title: { type: String, default: '加载失败' },
  message: { type: String, default: '请检查网络后重试' },
  retryable: { type: Boolean, default: true },
});

const emit = defineEmits(['retry']);
</script>

<style scoped>
.app-error-state {
  min-height: 180px;
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 8px;
  padding: 24px 16px;
  text-align: center;
}

.app-error-state > span {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  color: var(--app-danger);
  background: var(--app-danger-soft);
}

.app-error-state strong {
  font-size: 15px;
}

.app-error-state p {
  max-width: 280px;
  margin: 0 0 4px;
  color: var(--app-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}
</style>
