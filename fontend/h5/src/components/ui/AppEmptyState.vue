<template>
  <section class="app-state app-empty-state">
    <span class="app-state-icon"><Inbox :size="25" /></span>
    <strong>{{ title }}</strong>
    <p v-if="description">{{ description }}</p>
    <div v-if="$slots.action"><slot name="action" /></div>
  </section>
</template>

<script setup>
import { Inbox } from '@lucide/vue';

defineProps({
  title: { type: String, default: '暂无数据' },
  description: { type: String, default: '' },
});
</script>

<style scoped>
.app-state {
  min-height: 180px;
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 8px;
  padding: 24px 16px;
  color: var(--app-text-secondary);
  text-align: center;
}

.app-state-icon {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  color: var(--app-primary);
  background: var(--app-primary-soft);
}

.app-state strong {
  color: var(--app-text);
  font-size: 15px;
}

.app-state p {
  max-width: 280px;
  margin: 0;
  font-size: 13px;
  line-height: 1.55;
}
</style>
