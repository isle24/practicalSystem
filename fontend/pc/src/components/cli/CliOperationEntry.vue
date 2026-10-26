<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { request } from '../../api/client';
import { cliError, cliMutation, cliTime, cliTimestamp, useCliScope } from '../../utils/cli';

const props = defineProps({ operationId: { type: String, required: true }, sessionKey: { type: String, required: true }, accountName: { type: String, default: '' } });
const emit = defineEmits(['close', 'logout']);
const begin = useCliScope(() => props.sessionKey ? `${props.sessionKey}:${props.operationId}` : '');
const operation = ref(null), loading = ref(false), action = ref(''), checked = ref(false), error = ref(''), uncertain = ref(false), now = ref(Date.now());
let generation = 0, pollTimer;
const clockTimer = setInterval(() => { now.value = Date.now(); }, 1000);
const validId = computed(() => /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(props.operationId));
const expired = computed(() => operation.value?.state === 'expired' || (operation.value?.state === 'pending' && cliTimestamp(operation.value?.expires_at) <= now.value));
const pending = computed(() => operation.value?.state === 'pending' && !expired.value);
const canDecide = computed(() => props.sessionKey && pending.value && !loading.value && !action.value && !uncertain.value && /^[a-f0-9]{64}$/i.test(operation.value?.preview_hash || ''));
const labels = { pending: '等待确认', approved: '已批准，等待执行', executing: '正在执行', completed: '执行完成', failed: '执行失败', unknown: '结果待核对', denied: '已拒绝', cancelled: '已取消', expired: '已过期' };
const statusText = computed(() => expired.value ? labels.expired : labels[operation.value?.state] || '状态待读取');
const preview = computed(() => operation.value?.preview || {});
const json = value => JSON.stringify(value ?? {}, null, 2);
const notice = computed(() => {
  if (expired.value) return '本次预览已过期，需要从原调用方重新生成预览。';
  if (operation.value?.state === 'approved') return '已批准这次固定操作。请返回原调用方执行，或等待正在运行的客户端继续。';
  if (operation.value?.state === 'executing') return '服务器正在处理这次操作，请等待回执。';
  if (operation.value?.state === 'unknown') return '结果尚不能确认。请核对已有业务记录和本页回执，勿重新提交相同操作。';
  if (operation.value?.state === 'completed') return '执行已结束，请检查完整回执中的各项结果。';
  return operation.value?.error?.message || '';
});
function schedulePoll() {
  clearTimeout(pollTimer);
  if (['approved', 'executing'].includes(operation.value?.state)) pollTimer = setTimeout(() => load(true), 5000);
}
async function load(quiet = false) {
  if (action.value) return;
  clearTimeout(pollTimer);
  if (!validId.value) { error.value = '操作编号无效，请使用原调用方提供的完整确认地址。'; return; }
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  const ticket = ++generation;
  if (!quiet) loading.value = true;
  checked.value = false; error.value = '';
  try {
    const data = await request(`/cli/operations/confirmation?operation_id=${encodeURIComponent(props.operationId)}`, { ...scope.options, refreshOnUnauthorized: false });
    if (!scope.current() || ticket !== generation) return;
    if (data?.operation_id !== props.operationId || !labels[data.state] || (data.state === 'pending' && !data.preview)) throw new Error('操作预览响应不完整，请重新读取。');
    operation.value = data; uncertain.value = false; schedulePoll();
  } catch (reason) {
    if (scope.current() && ticket === generation) { error.value = cliError(reason); uncertain.value = true; }
  } finally { if (scope.current() && ticket === generation) loading.value = false; scope.finish(); }
}
async function decide(decision) {
  if (!canDecide.value || (decision === 'approve' && !checked.value)) return;
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  action.value = decision; checked.value = false; error.value = '';
  try {
    const data = await cliMutation(`/cli/operations/${decision}`, { operation_id: props.operationId, preview_hash: operation.value.preview_hash }, { ...scope.options, accountSession: true, refreshOnUnauthorized: false });
    if (!scope.current()) return;
    if (data?.operation_id !== props.operationId || !labels[data.state]) throw new Error('确认响应不完整，请重新读取操作状态。');
    operation.value = data; schedulePoll();
  } catch (reason) { if (scope.current()) { uncertain.value = true; error.value = `${cliError(reason)} 请重新读取状态后再操作。`; } }
  finally { if (scope.current()) action.value = ''; scope.finish(); }
}
function downloadReceipt() {
  if (!operation.value) return;
  const url = URL.createObjectURL(new Blob([json(operation.value)], { type: 'application/json;charset=utf-8' }));
  const link = document.createElement('a'); link.href = url; link.download = `cli-operation-${props.operationId}.json`; link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
watch(() => [props.sessionKey, props.operationId], () => {
  generation++; clearTimeout(pollTimer); operation.value = null; loading.value = false; action.value = ''; checked.value = false; error.value = ''; uncertain.value = false;
  if (props.sessionKey) load();
}, { immediate: true });
watch(expired, value => { if (value) checked.value = false; });
onBeforeUnmount(() => { generation++; clearTimeout(pollTimer); clearInterval(clockTimer); });
</script>

<template>
  <main class="cli-operation-shell"><section class="cli-operation-card" aria-labelledby="operation-title">
    <header><div><h1 id="operation-title">确认业务操作</h1><p>当前登录：{{ accountName || '当前账号' }}</p></div><el-button :disabled="Boolean(action)" @click="emit('logout')">更换账号</el-button></header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <p v-if="!sessionKey">登录会话不可用，请重新登录当前学校账号。</p>
    <div v-loading="loading" class="cli-operation-content"><template v-if="operation">
      <h2>{{ preview.summary || operation.capability_id }}</h2>
      <dl><div><dt>学校</dt><dd>{{ operation.school?.name || operation.school?.domain || '当前学校' }}</dd></div><div><dt>账号</dt><dd>{{ operation.account?.name || accountName }}<small>{{ operation.account?.login_name }}</small></dd></div><div><dt>状态</dt><dd role="status">{{ statusText }}</dd></div><div><dt>确认有效期</dt><dd>{{ cliTime(operation.expires_at) }}</dd></div></dl>
      <p v-if="notice" class="cli-operation-notice" role="status">{{ notice }}</p>
      <p v-if="operation.preview_available === false">参数和预览已超过 7 天保留期；操作状态与防重放标识仍保留。</p>
      <p v-if="operation.state === 'completed' && operation.receipt_available === false">完整回执已超过 30 天保留期，请在原业务模块查询处理记录。</p>
      <section><h3>操作影响</h3><ul v-if="Array.isArray(preview.effects) && preview.effects.length"><li v-for="(effect, index) in preview.effects" :key="index">{{ typeof effect === 'string' ? effect : json(effect) }}</li></ul><p v-else>请核对下面的对象和完整参数。</p></section>
      <section><h3>涉及对象</h3><pre>{{ json(preview.targets || []) }}</pre></section>
      <section><h3>完整参数</h3><pre>{{ json(preview.arguments || {}) }}</pre></section>
      <section v-if="operation.result !== null && operation.result !== undefined"><h3>执行回执</h3><details><summary>展开完整回执</summary><pre>{{ json(operation.result) }}</pre></details><el-button @click="downloadReceipt">下载完整回执</el-button></section>
      <p class="cli-operation-id">操作编号：<code>{{ operation.operation_id }}</code></p>
      <el-checkbox v-if="pending" v-model="checked" :disabled="!canDecide" class="cli-operation-check">我已核对涉及对象、完整参数和操作影响，批准执行这一次操作</el-checkbox>
    </template><p v-else-if="loading">正在读取服务器操作预览...</p></div>
    <footer><el-button :disabled="Boolean(action)" @click="emit('close')">返回系统</el-button><div><el-button :loading="loading" :disabled="Boolean(action) || !sessionKey" @click="load()">重新读取</el-button><el-button v-if="pending" type="danger" plain :loading="action === 'deny'" :disabled="!canDecide" @click="decide('deny')">拒绝</el-button><el-button v-if="pending" type="primary" :loading="action === 'approve'" :disabled="!canDecide || !checked" @click="decide('approve')">批准本次操作</el-button></div></footer>
  </section></main>
</template>

<style scoped>
.cli-operation-shell { height: 100vh; overflow: auto; box-sizing: border-box; padding: 32px 20px; background: var(--surface-2); color: var(--text); }
.cli-operation-card { max-width: 960px; margin: 0 auto; padding: 28px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; display: flex; flex-direction: column; gap: 20px; }
header, footer, footer > div { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
h1 { margin: 0; font-size: 22px; } h2 { margin: 0; font-size: 18px; overflow-wrap: anywhere; } h3 { margin: 0 0 10px; font-size: 15px; }
p { margin: 6px 0 0; color: var(--muted); font-size: 14px; line-height: 1.7; } .cli-operation-content { display: flex; flex-direction: column; gap: 20px; min-height: 70px; }
dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin: 0; } dt { font-size: 12px; color: var(--muted); margin-bottom: 5px; } dd { margin: 0; font-size: 14px; overflow-wrap: anywhere; } dd small { display: block; color: var(--muted); }
ul { padding-left: 20px; margin: 0; } li { font-size: 14px; line-height: 1.7; margin: 5px 0; overflow-wrap: anywhere; }
pre { margin: 0; padding: 14px; max-height: 380px; overflow: auto; background: var(--surface-2); border: 1px solid var(--line); border-radius: 6px; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 13px; line-height: 1.6; }
.cli-operation-notice { padding: 12px; background: var(--surface-2); color: var(--text); }.cli-operation-id { font-size: 12px; overflow-wrap: anywhere; }
.cli-operation-check { white-space: normal; height: auto; }.cli-operation-check :deep(.el-checkbox__label) { white-space: normal; line-height: 1.7; } summary { cursor: pointer; font-size: 14px; padding: 10px 0; } details { margin-bottom: 12px; }
footer { padding-top: 18px; border-top: 1px solid var(--line); } footer :deep(.el-button + .el-button) { margin-left: 0; }
@media (max-width: 600px) { .cli-operation-shell { padding: 14px 10px; }.cli-operation-card { padding: 18px; } dl { grid-template-columns: 1fr; } footer > div { width: 100%; } footer :deep(.el-button) { min-height: 44px; } }
</style>
