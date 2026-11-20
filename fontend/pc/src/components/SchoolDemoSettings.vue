<script setup>
import { onMounted, ref } from 'vue';
import { request } from '../api/client';
import { schoolDemo } from '../../../shared/schoolDemo';

const enabled = ref(false);
const ready = ref(false);
const saving = ref(false);
const message = ref('');
onMounted(async () => {
  try { enabled.value = (await request('/school-demo/settings')).enabled; ready.value = true; }
  catch (error) { message.value = error.message; }
});
async function save(value) {
  saving.value = true;
  message.value = '';
  try {
    enabled.value = (await request('/school-demo/settings', { method: 'POST', body: JSON.stringify({ enabled: value }) })).enabled;
    schoolDemo.enabled = enabled.value;
    if (!enabled.value && schoolDemo.selection) schoolDemo.error = '已关闭本校演示，请退出代办。';
    message.value = enabled.value ? '已开启本校业务演示' : '已关闭本校业务演示';
  } catch (error) { message.value = error.message; }
  finally { saving.value = false; }
}
</script>

<template>
  <section class="school-demo-settings">
    <div><strong>本校业务演示</strong><p>允许学校管理员和超级管理员代办本校新旧业务单据。操作写入实际业务记录，审批签名使用实际管理员的签名。</p></div>
    <el-switch :model-value="enabled" :loading="saving" :disabled="!ready || saving" aria-label="本校业务演示" @change="save" />
    <small v-if="message" role="status">{{ message }}</small>
  </section>
</template>

<style scoped>
.school-demo-settings { margin-top: 14px; display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; align-items: center; }
p { margin: 3px 0; line-height: 1.5; color: var(--muted, #586678); font-size: 13px; }
small { grid-column: 1/-1; }
</style>
