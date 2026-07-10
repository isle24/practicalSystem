<template>
  <div class="app-scroll-tabs" role="tablist">
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
      <span>{{ item.label ?? item.shortTitle ?? item.name ?? item.title }}</span>
      <em v-if="item.count !== undefined && item.count !== null">{{ item.count }}</em>
    </button>
  </div>
</template>

<script setup>
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  items: { type: Array, default: () => [] },
  iconSize: { type: Number, default: 17 },
});

const emit = defineEmits(['update:modelValue', 'change']);

function select(value) {
  if (value === props.modelValue) {
    return;
  }
  emit('update:modelValue', value);
  emit('change', value);
}
</script>

<style scoped>
.app-scroll-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  overflow-x: auto;
  overscroll-behavior-x: contain;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
}

.app-scroll-tabs::-webkit-scrollbar {
  display: none;
}

.app-scroll-tabs button {
  flex: 0 0 auto;
  min-width: 70px;
  min-height: 40px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 0 11px;
  color: var(--app-text-secondary);
  background: var(--app-surface);
  font: inherit;
  transition: color var(--app-motion-control) var(--app-ease-standard),
    border-color var(--app-motion-control) var(--app-ease-standard),
    background var(--app-motion-control) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.app-scroll-tabs button:active {
  transform: scale(.97);
}

.app-scroll-tabs button.active {
  border-color: color-mix(in srgb, var(--app-primary) 30%, var(--app-line));
  color: var(--app-primary);
  background: var(--app-primary-soft);
}

.app-scroll-tabs span {
  font-size: 12px;
  white-space: nowrap;
}

.app-scroll-tabs em {
  min-width: 18px;
  height: 18px;
  border-radius: 999px;
  display: grid;
  place-items: center;
  padding: 0 4px;
  color: var(--app-primary);
  background: var(--app-surface);
  font-size: 10px;
  font-style: normal;
}
</style>
