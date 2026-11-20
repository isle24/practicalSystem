<template>
  <img class="app-icon" :src="source" :width="size" :height="size" alt="" decoding="async" @error="failedUrl = customUrl">
</template>

<script setup>
import { computed, ref } from 'vue';
import { menuArtwork } from '../menuArtwork.js';

const props = defineProps({
  menuKey: { type: [String, Number], default: '' },
  iconName: { type: String, default: '' },
  iconUrl: { type: String, default: '' },
  size: { type: Number, default: 24 },
  backendUrl: { type: Function, default: url => url },
});

const failedUrl = ref('');
const customUrl = computed(() => props.iconUrl ? props.backendUrl(props.iconUrl) : '');
const source = computed(() => customUrl.value && customUrl.value !== failedUrl.value
  ? customUrl.value
  : menuArtwork(props.menuKey, props.iconName));
</script>

<style scoped>
.app-icon {
  display: block;
  flex-shrink: 0;
  object-fit: cover;
  border-radius: 25%;
}

@supports (corner-shape: squircle) {
  .app-icon {
    border-radius: 50%;
    corner-shape: squircle;
  }
}
</style>
