<template>
  <div class="app-page-transition">
    <Transition :name="transitionName" mode="out-in">
      <div :key="pageKey" class="app-page-transition-view"><slot /></div>
    </Transition>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  pageKey: { type: [String, Number], required: true },
  direction: { type: String, default: 'tab' },
});

const transitionName = computed(() => {
  if (props.direction === 'forward') {
    return 'app-page-forward';
  }
  if (props.direction === 'back') {
    return 'app-page-back';
  }
  return 'app-tab';
});
</script>

<style scoped>
.app-page-transition {
  position: relative;
  min-height: 100%;
}

.app-page-transition-view {
  min-height: 100%;
}
</style>
