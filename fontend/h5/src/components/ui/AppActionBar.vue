<template>
  <footer class="app-action-bar" :class="{ 'is-sticky': sticky }">
    <slot />
  </footer>
</template>

<script setup>
defineProps({
  sticky: { type: Boolean, default: true },
});
</script>

<style scoped>
.app-action-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 10px var(--app-page-gutter) calc(10px + env(safe-area-inset-bottom));
  border-top: 1px solid color-mix(in srgb, var(--app-line) 86%, transparent);
  background: rgba(255, 255, 255, .94);
  box-shadow: 0 -6px 20px rgba(24, 33, 43, .05);
  backdrop-filter: blur(18px) saturate(135%);
}

.app-action-bar.is-sticky {
  position: sticky;
  bottom: calc(var(--app-tabbar-height) + env(safe-area-inset-bottom));
  z-index: var(--app-z-action);
}

.app-action-bar :deep(.app-button) {
  flex: 1 1 0;
}
</style>
