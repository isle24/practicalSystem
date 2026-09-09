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
      <component :is="item.icon" v-if="item.icon" :size="16" />
      <span>{{ item.label || item.name }}</span>
      <small v-if="item.count !== null && item.count !== undefined">{{ item.count }}</small>
    </button>
  </div>
</template>

<script setup>
defineProps({
  items: { type: Array, default: () => [] },
  modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);
</script>
