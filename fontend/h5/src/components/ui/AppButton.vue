<template>
  <button
    :type="type"
    class="app-button"
    :class="[`is-${variant}`, `is-${size}`, { 'is-block': block, 'is-loading': loading, 'is-selected': selected }]"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : 'false'"
    :aria-pressed="selected ? 'true' : undefined"
    @click="handleClick"
  >
    <LoaderCircle v-if="loading" class="app-button-spinner" :size="iconSize" />
    <slot v-else name="icon" />
    <span v-if="$slots.default" class="app-button-label"><slot /></span>
  </button>
</template>

<script setup>
import { computed } from 'vue';
import { LoaderCircle } from '@lucide/vue';

const props = defineProps({
  type: { type: String, default: 'button' },
  variant: { type: String, default: 'primary' },
  size: { type: String, default: 'medium' },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  selected: { type: Boolean, default: false },
});

const emit = defineEmits(['click']);
const iconSize = computed(() => (props.size === 'small' ? 15 : 17));

function handleClick(event) {
  if (!props.disabled && !props.loading) {
    emit('click', event);
  }
}
</script>

<style scoped>
.app-button {
  min-width: var(--app-touch-size);
  min-height: var(--app-touch-size);
  border: 1px solid transparent;
  border-radius: var(--app-radius);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 0 15px;
  color: var(--app-surface);
  background: var(--app-primary);
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  line-height: 1;
  cursor: pointer;
  transform: scale(1);
  transition: color var(--app-motion-control) var(--app-ease-standard),
    border-color var(--app-motion-control) var(--app-ease-standard),
    background var(--app-motion-control) var(--app-ease-standard),
    opacity var(--app-motion-control) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.app-button:active:not(:disabled) {
  transform: scale(.97);
}

.app-button.is-block {
  width: 100%;
}

.app-button.is-small {
  min-height: 36px;
  padding: 0 11px;
  font-size: 13px;
}

.app-button.is-large {
  min-height: 48px;
  padding: 0 18px;
  font-size: 15px;
}

.app-button.is-secondary {
  border-color: var(--app-line);
  color: var(--app-primary);
  background: var(--app-surface);
}

.app-button.is-secondary.is-selected {
  border-color: color-mix(in srgb, var(--app-primary) 32%, var(--app-line));
  color: var(--app-primary);
  background: var(--app-primary-soft);
}

.app-button.is-quiet {
  border-color: transparent;
  color: var(--app-primary);
  background: transparent;
}

.app-button.is-danger {
  color: #fff;
  background: var(--app-danger);
}

.app-button:disabled {
  cursor: default;
  opacity: .52;
  transform: none;
}

.app-button-spinner {
  animation: app-button-spin .8s linear infinite;
}

.app-button-label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@keyframes app-button-spin {
  to { transform: rotate(360deg); }
}
</style>
