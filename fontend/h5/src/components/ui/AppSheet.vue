<template>
  <van-popup
    :show="modelValue"
    class="app-sheet-popup"
    position="bottom"
    teleport="body"
    :z-index="2100"
    safe-area-inset-bottom
    :close-on-click-overlay="closeOnOverlay"
    @update:show="emit('update:modelValue', $event)"
  >
    <section class="app-sheet">
      <header>
        <div>
          <strong>{{ title }}</strong>
          <small v-if="subtitle">{{ subtitle }}</small>
        </div>
        <AppIconButton label="关闭" size="small" @click="emit('update:modelValue', false)">
          <X :size="19" />
        </AppIconButton>
      </header>
      <div class="app-sheet-content"><slot /></div>
      <footer v-if="$slots.footer"><slot name="footer" /></footer>
    </section>
  </van-popup>
</template>

<script setup>
import { X } from '@lucide/vue';
import AppIconButton from './AppIconButton.vue';

defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  closeOnOverlay: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue']);
</script>

<style>
.app-sheet-popup.van-popup {
  max-height: min(86dvh, 760px);
  border-radius: var(--app-radius) var(--app-radius) 0 0;
  overflow: hidden;
  box-shadow: var(--app-shadow-sheet);
}

.van-popup-slide-bottom-enter-active,
.van-popup-slide-bottom-leave-active {
  transition-duration: var(--app-motion-sheet) !important;
  transition-timing-function: var(--app-ease-spring) !important;
}
</style>

<style scoped>
.app-sheet {
  max-height: min(86dvh, 760px);
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  color: var(--app-text);
  background: var(--app-surface);
}

.app-sheet > header {
  min-height: 62px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px 10px 16px;
  border-bottom: 1px solid var(--app-line);
}

.app-sheet > header > div {
  min-width: 0;
  display: grid;
  gap: 3px;
}

.app-sheet > header strong {
  font-size: 17px;
  line-height: 1.3;
}

.app-sheet > header small {
  color: var(--app-text-secondary);
  font-size: 12px;
  line-height: 1.35;
}

.app-sheet-content {
  min-height: 0;
  overflow: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}

.app-sheet > footer {
  display: flex;
  gap: 10px;
  padding: 10px 16px calc(10px + env(safe-area-inset-bottom));
  border-top: 1px solid var(--app-line);
  background: var(--app-surface);
}

.app-sheet > footer :deep(.app-button) {
  flex: 1 1 0;
}
</style>
