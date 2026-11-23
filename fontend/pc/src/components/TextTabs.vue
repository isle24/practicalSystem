<template>
  <div class="text-tabs" role="tablist">
    <button
      v-for="item in items"
      :key="item.key"
      type="button"
      role="tab"
      :aria-selected="modelValue === item.key"
      :class="{ active: modelValue === item.key }"
      @click="emit('update:modelValue', item.key)"
    >
      <AppIcon v-if="item.icon" :menu-key="item.key" :icon="item.icon" :size="18" />
      <span>{{ item.label || item.name }}</span>
      <small v-if="item.count !== null && item.count !== undefined">{{ item.count }}</small>
    </button>
  </div>
</template>

<script setup>
import AppIcon from '../../../shared/components/AppIcon.vue';

defineProps({
  items: { type: Array, default: () => [] },
  modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);
</script>
