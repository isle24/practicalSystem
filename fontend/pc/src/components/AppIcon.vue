<template>
  <span class="app-icon" :class="color">
    <img v-if="iconUrl" :src="resolvedUrl" :alt="label">
    <component v-else :is="icon" :size="size" />
  </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  icon: { type: null, required: true },
  iconUrl: { type: String, default: '' },
  label: { type: String, default: '' },
  color: { type: String, default: '' },
  size: { type: Number, default: 24 },
  backendUrl: { type: Function, required: true },
});

const resolvedUrl = computed(() => (props.iconUrl ? props.backendUrl(props.iconUrl) : ''));
</script>
