<template>
  <TemplateList :model="model" :can-load-more="canLoadMore" @load="forwardLoad" @download="emit('download', $event)" />
</template>

<script setup>
import TemplateList from '../features/support/TemplateList.vue';

defineProps({
  model: { type: Object, required: true },
  canLoadMore: { type: Boolean, default: false },
});

const emit = defineEmits(['download', 'load']);

function forwardLoad(page, append) {
  emit('load', page, append);
}
</script>
