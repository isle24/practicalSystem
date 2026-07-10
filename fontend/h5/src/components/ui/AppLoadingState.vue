<template>
  <section class="app-loading-state" aria-live="polite" aria-busy="true">
    <span v-for="index in lines" :key="index" :style="lineStyle(index)" />
  </section>
</template>

<script setup>
defineProps({
  lines: { type: Number, default: 4 },
});

function lineStyle(index) {
  const widths = ['72%', '100%', '88%', '62%'];
  return { width: widths[(index - 1) % widths.length] };
}
</script>

<style scoped>
.app-loading-state {
  min-height: 180px;
  display: grid;
  align-content: center;
  gap: 12px;
  padding: 24px 16px;
}

.app-loading-state span {
  height: 14px;
  border-radius: 5px;
  background: linear-gradient(90deg, var(--app-neutral-soft) 20%, #f8fafc 50%, var(--app-neutral-soft) 80%);
  background-size: 240% 100%;
  animation: app-skeleton 1.25s ease-in-out infinite;
}

@keyframes app-skeleton {
  from { background-position: 100% 0; }
  to { background-position: -100% 0; }
}
</style>
