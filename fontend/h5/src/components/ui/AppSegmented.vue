<template>
  <div class="app-segmented" :style="segmentedStyle" role="tablist">
    <span v-if="activeIndex >= 0" class="app-segmented-indicator" :style="indicatorStyle" />
    <button
      v-for="item in items"
      :key="item.value ?? item.key"
      type="button"
      role="tab"
      :aria-selected="modelValue === (item.value ?? item.key)"
      :class="{ active: modelValue === (item.value ?? item.key) }"
      @click="select(item.value ?? item.key)"
    >
      <component :is="item.icon" v-if="item.icon" :size="iconSize" />
      <span>{{ item.label ?? item.name ?? item.title }}</span>
      <em v-if="item.count !== undefined && item.count !== null">{{ item.count }}</em>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  items: { type: Array, default: () => [] },
  iconSize: { type: Number, default: 17 },
});

const emit = defineEmits(['update:modelValue', 'change']);
const activeIndex = computed(() => props.items.findIndex(item => (item.value ?? item.key) === props.modelValue));
const segmentedStyle = computed(() => ({ '--segment-count': Math.max(props.items.length, 1) }));
const indicatorStyle = computed(() => ({ transform: `translateX(${Math.max(activeIndex.value, 0) * 100}%)` }));

function select(value) {
  if (value === props.modelValue) {
    return;
  }
  emit('update:modelValue', value);
  emit('change', value);
}
</script>

<style scoped>
.app-segmented {
  position: relative;
  min-height: 42px;
  display: grid;
  grid-template-columns: repeat(var(--segment-count), minmax(0, 1fr));
  gap: 0;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  padding: 3px;
  background: var(--app-neutral-soft);
  overflow: hidden;
}

.app-segmented-indicator {
  position: absolute;
  top: 3px;
  bottom: 3px;
  left: 3px;
  width: calc((100% - 6px) / var(--segment-count));
  border: 1px solid color-mix(in srgb, var(--app-primary) 24%, var(--app-line));
  border-radius: 6px;
  background: var(--app-surface);
  box-shadow: 0 1px 4px rgba(24, 33, 43, .08);
  transition: transform var(--app-motion-tab) var(--app-ease-enter);
}

.app-segmented button {
  position: relative;
  z-index: 1;
  min-width: 0;
  min-height: 34px;
  border: 0;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 0 8px;
  color: var(--app-text-secondary);
  background: transparent;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  transition: color var(--app-motion-tab) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.app-segmented button:active {
  transform: scale(.97);
}

.app-segmented button.active {
  color: var(--app-primary);
  font-weight: 600;
}

.app-segmented button span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-segmented button em {
  min-width: 18px;
  height: 18px;
  border-radius: 999px;
  display: grid;
  place-items: center;
  padding: 0 4px;
  color: var(--app-text-secondary);
  background: var(--app-surface-pressed);
  font-size: 10px;
  font-style: normal;
}
</style>
