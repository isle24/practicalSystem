<template>
  <section class="icon-config-field">
    <label>
      <span>{{ iconLabel }}</span>
      <input
        :value="icon"
        :placeholder="placeholder"
        @input="emit('update:icon', $event.target.value)"
      >
    </label>
    <label class="icon-config-upload">
      <span>{{ uploadLabel }}</span>
      <IconUpload
        :button-class="uploadButtonClass"
        :icon="resolvedIcon"
        :icon-url="iconUrl"
        :label="uploadLabel"
        :uploading="uploading"
        :backend-url="backendUrl"
        @select="file => emit('select', file)"
      />
    </label>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import IconUpload from './IconUpload.vue';

const props = defineProps({
  icon: { type: String, default: '' },
  iconUrl: { type: String, default: '' },
  iconLabel: { type: String, default: '图标' },
  uploadLabel: { type: String, default: '上传图标' },
  placeholder: { type: String, default: 'lucide 图标名' },
  uploadButtonClass: { type: [String, Array, Object], default: 'favorite-icon-upload' },
  resolveIcon: { type: Function, required: true },
  uploading: { type: Boolean, default: false },
  backendUrl: { type: Function, required: true },
});

const emit = defineEmits(['select', 'update:icon']);
const resolvedIcon = computed(() => props.resolveIcon(props.icon));
</script>
