<template>
  <van-popup
    :show="modelValue"
    class="app-dialog-popup"
    position="center"
    :close-on-click-overlay="closeOnOverlay"
    @update:show="emit('update:modelValue', $event)"
  >
    <section class="app-dialog" role="dialog" aria-modal="true" :aria-label="title">
      <header>
        <strong>{{ title }}</strong>
        <small v-if="description">{{ description }}</small>
      </header>
      <div class="app-dialog-content"><slot /></div>
      <footer v-if="$slots.footer"><slot name="footer" /></footer>
    </section>
  </van-popup>
</template>

<script setup>
defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  closeOnOverlay: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>

<style>
.app-dialog-popup.van-popup {
  width: min(88vw, 380px);
  border-radius: var(--app-radius);
  overflow: hidden;
  box-shadow: 0 24px 60px rgba(24, 33, 43, .22);
}
</style>

<style scoped>
.app-dialog {
  color: var(--app-text);
  background: var(--app-surface);
}

.app-dialog > header {
  display: grid;
  gap: 5px;
  padding: 18px 18px 12px;
}

.app-dialog > header strong {
  font-size: 18px;
  line-height: 1.35;
}

.app-dialog > header small {
  color: var(--app-text-secondary);
  font-size: 13px;
  line-height: 1.5;
}

.app-dialog-content {
  padding: 4px 18px 18px;
  color: var(--app-text-secondary);
  line-height: 1.6;
}

.app-dialog > footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 10px 12px;
  border-top: 1px solid var(--app-line);
  background: var(--app-surface-muted);
}
</style>
