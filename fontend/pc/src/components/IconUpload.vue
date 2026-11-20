<template>
  <button
    type="button"
    class="icon-upload-button"
    :class="buttonClass"
    :disabled="uploading"
    :title="uploading ? '正在上传' : `上传${label || '图标'}（JPG、PNG、WebP 或 GIF）`"
    :aria-label="`上传${label || '图标'}`"
    @click="chooseFile"
  >
    <AppIcon :menu-key="menuKey" :icon-name="iconName" :icon-url="iconUrl" :backend-url="backendUrl" />
  </button>
  <input
    ref="inputRef"
    class="hidden-file"
    type="file"
    :accept="accept"
    @change="handleFileChange"
  >
</template>

<script setup>
import { ref } from 'vue';
import AppIcon from '../../../shared/components/AppIcon.vue';

defineProps({
  menuKey: { type: String, default: '' },
  iconName: { type: String, default: '' },
  iconUrl: { type: String, default: '' },
  label: { type: String, default: '' },
  accept: { type: String, default: '.jpg,.jpeg,.png,.webp,.gif' },
  uploading: { type: Boolean, default: false },
  buttonClass: { type: [String, Array, Object], default: '' },
  backendUrl: { type: Function, required: true },
});

const emit = defineEmits(['select']);
const inputRef = ref(null);

function chooseFile() {
  inputRef.value?.click();
}

function handleFileChange(event) {
  const file = event.target?.files?.[0];
  if (file) {
    emit('select', file);
  }
  event.target.value = '';
}
</script>

<style scoped>
.icon-upload-button {
  width: 56px;
  height: 56px;
  flex-shrink: 0;
  padding: 0;
  border: 1px dashed var(--line);
  border-radius: 25%;
  background: transparent;
  cursor: pointer;
}
.icon-upload-button:hover { border-color: var(--primary); }
.icon-upload-button:disabled { opacity: .5; cursor: wait; }
.icon-upload-button .app-icon { width: 100%; height: 100%; }
.hidden-file { display: none; }
</style>
