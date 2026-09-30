<template>
  <div class="shared-qr-code">
    <img v-if="source" :src="source" :width="size" :height="size" :alt="label">
    <p v-if="error" role="alert">{{ error }}</p>
  </div>
</template>
<script setup>
import { ref, watch } from 'vue';
import QRCode from 'qrcode';
const props = defineProps({ value: { type: String, default: '' }, size: { type: Number, default: 220 }, label: { type: String, default: '二维码' } });
const source = ref('');
const error = ref('');
let generation = 0;
watch(() => [props.value, props.size], async () => {
  const version = ++generation;
  source.value = '';
  error.value = '';
  if (!props.value) return;
  try {
    const result = await QRCode.toDataURL(props.value, { width: props.size, margin: 2, errorCorrectionLevel: 'M' });
    if (version === generation) source.value = result;
  } catch {
    if (version === generation) error.value = '二维码生成失败，请刷新后重试';
  }
}, { immediate: true });
</script>
<style scoped>
.shared-qr-code { text-align: center; }
img { display: inline-block; max-width: 100%; height: auto; }
p { color: #b91c1c; }
</style>
