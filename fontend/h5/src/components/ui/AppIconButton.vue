<template>
  <button
    type="button"
    class="app-icon-button"
    :class="[`is-${variant}`, `is-${size}`]"
    :title="label"
    :aria-label="label"
    :disabled="disabled"
    @click="emit('click', $event)"
  >
    <slot />
    <span v-if="badge" class="app-icon-button-badge">{{ badge }}</span>
  </button>
</template>

<script setup>
defineProps({
  label: { type: String, required: true },
  variant: { type: String, default: 'quiet' },
  size: { type: String, default: 'medium' },
  badge: { type: [String, Number], default: '' },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['click']);
</script>

<style scoped>
.app-icon-button {
  position: relative;
  width: var(--app-touch-size);
  height: var(--app-touch-size);
  flex: 0 0 var(--app-touch-size);
  border: 1px solid transparent;
  border-radius: var(--app-radius);
  display: grid;
  place-items: center;
  padding: 0;
  color: var(--app-text);
  background: transparent;
  cursor: pointer;
  transform: scale(1);
  transition: color var(--app-motion-control) var(--app-ease-standard),
    border-color var(--app-motion-control) var(--app-ease-standard),
    background var(--app-motion-control) var(--app-ease-standard),
    opacity var(--app-motion-control) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.app-icon-button.is-small {
  width: 36px;
  height: 36px;
  flex-basis: 36px;
}

.app-icon-button.is-secondary {
  border-color: var(--app-line);
  color: var(--app-primary);
  background: var(--app-surface);
}

.app-icon-button:active:not(:disabled) {
  background: var(--app-surface-pressed);
  transform: scale(.94);
}

.app-icon-button:disabled {
  opacity: .46;
  cursor: default;
  transform: none;
}

.app-icon-button-badge {
  position: absolute;
  top: 1px;
  right: -2px;
  min-width: 17px;
  height: 17px;
  border: 2px solid var(--app-surface);
  border-radius: 999px;
  display: grid;
  place-items: center;
  padding: 0 3px;
  color: #fff;
  background: var(--app-danger);
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
}
</style>
