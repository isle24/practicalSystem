<template>
  <button
    type="button"
    class="icon-upload-button"
    :class="buttonClass"
    :disabled="uploading"
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
