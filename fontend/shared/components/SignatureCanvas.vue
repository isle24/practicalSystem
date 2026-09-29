<template>
  <div class="signature-canvas">
    <canvas ref="canvas" width="1200" height="480" aria-label="手写签名区域" @pointerdown="start" @pointermove="move" @pointerup="finish" @pointercancel="finish" @lostpointercapture="finish" />
    <div class="signature-tools">
      <span>请在框内书写本人姓名</span>
      <button type="button" :disabled="disabled" @click="clear">清空重写</button>
    </div>
  </div>
</template>
<script setup>
import { ref } from 'vue';
const props = defineProps({ disabled: Boolean });
const emit = defineEmits(['change']);
const canvas = ref(null);
let active = null;
let hasInk = false;
function point(event) {
  const bounds = canvas.value.getBoundingClientRect();
  return [(event.clientX - bounds.left) * 1200 / bounds.width, (event.clientY - bounds.top) * 480 / bounds.height];
}
function start(event) {
  if (props.disabled || active !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
  event.preventDefault();
  active = event.pointerId;
  canvas.value.setPointerCapture(active);
  const context = canvas.value.getContext('2d');
  context.strokeStyle = '#111827'; context.lineWidth = 5; context.lineCap = 'round'; context.lineJoin = 'round';
  context.beginPath(); context.moveTo(...point(event));
}
function move(event) {
  if (active !== event.pointerId || props.disabled) return;
  event.preventDefault();
  const context = canvas.value.getContext('2d');
  context.lineTo(...point(event)); context.stroke();
  hasInk = true; emit('change', true);
}
function finish(event) {
  if (active !== event.pointerId) return;
  active = null;
}
function clear() {
  if (props.disabled) return;
  active = null; hasInk = false;
  canvas.value.getContext('2d').clearRect(0, 0, 1200, 480);
  emit('change', false);
}
async function blob() {
  if (!hasInk) throw new Error('请先书写本人签名');
  return new Promise((resolve, reject) => canvas.value.toBlob(value => value ? resolve(value) : reject(new Error('签名图像生成失败')), 'image/png'));
}
defineExpose({ blob, clear });
</script>
<style scoped>
canvas { display: block; width: 100%; height: auto; aspect-ratio: 5 / 2; border: 1px solid #94a3b8; border-radius: 8px; background: #fff; touch-action: none; }
.signature-tools { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 12px; font-size: 14px; color: #64748b; }
button { border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; padding: 8px 12px; color: #334155; cursor: pointer; }
button:disabled { opacity: .5; cursor: default; }
</style>
