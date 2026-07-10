<template>
  <DocumentList :model="model" :can-load-more="canLoadMore" @load="forwardLoad" @open="emit('open', $event)" />
</template>

<script setup>
import DocumentList from '../features/support/DocumentList.vue';

defineProps({
  model: { type: Object, required: true },
  canLoadMore: { type: Boolean, default: false },
});

const emit = defineEmits(['load', 'open']);

function forwardLoad(page, append) {
  emit('load', page, append);
}
</script>
