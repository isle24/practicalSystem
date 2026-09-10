<template>
  <button
    type="button"
    class="icon-upload-button"
    :class="buttonClass"
    :disabled="uploading"
    :title="uploading ? '正在上传' : `上传${label || '图标'}`"
    :aria-label="`上传${label || '图标'}`"
    @click="chooseFile"
  >
    <img v-if="iconUrl" :src="resolvedUrl" :alt="label">
    <component v-else :is="icon" :size="size" />
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
import { computed, ref } from 'vue';

const props = defineProps({
  icon: { type: null, required: true },
  iconUrl: { type: String, default: '' },
  label: { type: String, default: '' },
  size: { type: Number, default: 24 },
  accept: { type: String, default: 'image/jpeg,image/png,image/webp,image/gif,image/x-icon' },
  uploading: { type: Boolean, default: false },
  buttonClass: { type: [String, Array, Object], default: '' },
  backendUrl: { type: Function, required: true },
});

const emit = defineEmits(['select']);
const inputRef = ref(null);
const resolvedUrl = computed(() => (props.iconUrl ? props.backendUrl(props.iconUrl) : ''));

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
.icon-upload-button{width:56px;height:56px;flex-shrink:0;display:grid;place-items:center;border:1px dashed #cdd6e2;border-radius:8px;background:#f5f8fc;color:#526b8a;cursor:pointer;overflow:hidden;padding:8px}.icon-upload-button:hover{border-color:#648ddd;background:#edf3fc}.icon-upload-button:disabled{opacity:.5;cursor:wait}.icon-upload-button img{width:100%;height:100%;object-fit:contain}.hidden-file{display:none}
</style>
