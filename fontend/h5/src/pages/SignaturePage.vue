<template>
  <section class="signature-page">
    <h2>个人电子签名</h2>
    <p>请书写本人姓名。保存后产生新版本，原有审批签名不变。个人签名不替代安全承诺书签字文件。</p>
    <p v-if="sessionId">本次签名来自电脑二维码，仅同一系统用户可保存。</p>
    <div v-if="signature" class="preview"><img :src="backendUrl(signature.url)" alt="当前个人签名"><span>当前版本 {{ signature.version }}</span></div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="message" role="status">{{ message }}</p>
    <template v-if="ready && !completed">
      <SignatureCanvas ref="canvas" :disabled="busy" @change="hasInk = $event" />
      <label class="confirmation"><input v-model="confirmed" type="checkbox" :disabled="busy">确认这是本人书写的签名，用于后续本人确认的审批。</label>
      <button type="button" :disabled="busy || !hasInk || !confirmed" @click="save">{{ busy ? '正在保存' : '保存个人签名' }}</button>
    </template>
    <button v-if="completed" type="button" @click="$emit('done')">返回我的</button>
  </section>
</template>
<script setup>
import { onMounted, ref } from 'vue';
import SignatureCanvas from '../../../shared/components/SignatureCanvas.vue';
import { backendUrl, request } from '../api/client';
const props = defineProps({ sessionId: { type: String, default: '' } });
defineEmits(['done']);
const canvas = ref(null);
const signature = ref(null);
const ready = ref(false);
const completed = ref(false);
const busy = ref(false);
const hasInk = ref(false);
const confirmed = ref(false);
const error = ref('');
const message = ref('');
onMounted(async () => {
  try {
    if (props.sessionId) {
      const status = await request(`/signature/session-status?mobile=1&session_id=${encodeURIComponent(props.sessionId)}`);
      if (status.state !== 'pending') throw new Error('本次二维码已使用或取消，请在电脑重新获取。');
    }
    signature.value = (await request('/signature/current')).signature;
    ready.value = true;
  } catch (failure) { error.value = failure.message; }
});
async function save() {
  if (busy.value || !confirmed.value || !hasInk.value) return;
  busy.value = true; error.value = '';
  try {
    const form = new FormData();
    form.append('file', await canvas.value.blob(), 'signature.png');
    if (props.sessionId) form.append('session_id', props.sessionId);
    const result = await request('/signature/save', { method: 'POST', headers: { 'X-Signature-Intent': 'personal' }, body: form });
    signature.value = result.signature; completed.value = true;
    message.value = props.sessionId ? '签名已保存，请返回电脑继续操作。' : '个人签名已保存。';
  } catch (failure) { error.value = failure.message; }
  finally { busy.value = false; }
}
</script>
<style scoped>
.signature-page { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; color: #334155; }
h2 { margin: 0 0 12px; font-size: 20px; }
p { font-size: 14px; line-height: 1.6; }
.preview { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; margin: 16px 0; }
.preview img { max-width: 200px; max-height: 90px; object-fit: contain; }
.preview span { font-size: 13px; color: #64748b; }
.confirmation { display: flex; align-items: flex-start; gap: 8px; line-height: 1.5; font-size: 14px; margin: 18px 0; }
button { border: 1px solid #cbd5e1; background: #fff; color: #0f172a; border-radius: 6px; padding: 10px 14px; }
button:disabled { opacity: .5; }
.error { color: #b91c1c; }
</style>
