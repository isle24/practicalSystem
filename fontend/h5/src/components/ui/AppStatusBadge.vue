<template>
  <span class="app-status-badge" :class="`is-${meta.tone}`">
    <i aria-hidden="true" />
    <span>{{ meta.label }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { statusMeta } from '../../constants/status';

const props = defineProps({
  status: { type: [String, Number], default: '' },
  label: { type: String, default: '' },
});

const meta = computed(() => statusMeta(props.status, props.label));
</script>

<style scoped>
.app-status-badge {
  --status-color: var(--app-neutral);
  --status-bg: var(--app-neutral-soft);
  min-height: 24px;
  border: 1px solid color-mix(in srgb, var(--status-color) 20%, transparent);
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  color: var(--status-color);
  background: var(--status-bg);
  font-size: 12px;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
  transition: color var(--app-motion-tab) var(--app-ease-standard),
    border-color var(--app-motion-tab) var(--app-ease-standard),
    background var(--app-motion-tab) var(--app-ease-standard);
}

.app-status-badge i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

.app-status-badge.is-info {
  --status-color: var(--app-info);
  --status-bg: var(--app-info-soft);
}

.app-status-badge.is-warning {
  --status-color: var(--app-warning);
  --status-bg: var(--app-warning-soft);
}

.app-status-badge.is-success {
  --status-color: var(--app-success);
  --status-bg: var(--app-success-soft);
}

.app-status-badge.is-danger {
  --status-color: var(--app-danger);
  --status-bg: var(--app-danger-soft);
}
</style>
