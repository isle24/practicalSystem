<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({ entityType: { type: String, required: true }, entityId: { type: [Number, String], required: true }, accountId: { type: [Number, String], required: true }, request: { type: Function, required: true }, backendUrl: { type: Function, default: value => value } });
const emit = defineEmits(['updated']);
const items = ref([]);
const busy = ref(false);
const message = ref('');
const opinion = ref('');
const signature = ref(null);
const useSignature = ref(false);
const current = computed(() => items.value[0] || null);
const activeNode = computed(() => current.value?.nodes_json?.[Number(current.value.active_position) - 1] || null);
const canReview = computed(() => current.value?.status === 'wait' && current.value.tasks?.some(task => task.status === 'pending' && Number(task.position) === Number(current.value.active_position) && Number(task.account_id) === Number(props.accountId)));
const statusNames = { draft: '草稿', wait: '待审批', accept: '已通过', modify: '已退回', cancelled: '已取消' };
const actionNames = { start: '提交', accept: '通过', modify: '退回', cancel: '取消' };
function candidateName(instance, actorId) {
  for (const node of instance.nodes_json || []) {
    const actor = node.candidates?.find(item => Number(item.account_id) === Number(actorId));
    if (actor) return actor.name;
  }
  return Number(instance.applicant_id) === Number(actorId) ? '申请人' : `账号 ${actorId}`;
}
let generation = 0;
async function reload() {
  const version = ++generation;
  items.value = [];
  signature.value = null;
  useSignature.value = false;
  opinion.value = '';
  if (!props.entityId) return;
  busy.value = true;
  message.value = '';
  try {
    const data = await props.request(`/workflow/history?entity_type=${encodeURIComponent(props.entityType)}&entity_id=${encodeURIComponent(props.entityId)}`);
    if (version !== generation) return;
    items.value = data.items || [];
    if (canReview.value) {
      const result = await props.request('/signature/current');
      if (version === generation) signature.value = result.signature;
    }
  } catch (error) { if (version === generation) message.value = error.message; }
  finally { if (version === generation) busy.value = false; }
}
async function review(action) {
  if (busy.value || !canReview.value) return;
  if (action === 'modify' && !opinion.value.trim()) { message.value = '请填写退回意见'; return; }
  if (activeNode.value?.signature_required && (!signature.value || !useSignature.value)) { message.value = '此节点需要本人签名，请先在个人设置中保存签名，并勾选用于本次审批。'; return; }
  busy.value = true;
  message.value = '';
  try {
    await props.request('/workflow/review', { method: 'POST', body: JSON.stringify({ instance_id: current.value.id, action, opinion: opinion.value, signature_id: useSignature.value ? signature.value?.id : null, revision: current.value.revision }) });
    await reload();
    emit('updated');
  } catch (error) { message.value = error.message; } finally { busy.value = false; }
}
watch(() => [props.entityType, props.entityId, props.accountId], reload, { immediate: true });
defineExpose({ reload });
</script>

<template>
  <section class="workflow-history" :aria-busy="busy">
    <header><strong>审批流程</strong><button type="button" :disabled="busy" @click="reload">刷新</button></header>
    <p v-if="message" class="workflow-feedback" role="status">{{ message }}</p>
    <p v-if="!busy && !items.length && !message" class="workflow-muted">尚未提交审批。</p>
    <article v-for="instance in items" :key="instance.id" class="workflow-round">
      <div class="workflow-round-title"><strong>第 {{ instance.round }} 轮</strong><span>{{ statusNames[instance.status] || instance.status }}</span><time>{{ instance.created_at }}</time></div>
      <ol class="workflow-nodes">
        <li v-for="(node, index) in instance.nodes_json" :key="index" :class="{ active: instance.status === 'wait' && Number(instance.active_position) === index + 1 }">
          <strong>{{ node.name }}</strong><span>{{ node.kind === 'cc' ? '抄送' : node.mode === 'all' ? '会签' : '或签' }}</span>
          <small>{{ node.candidates?.map(item => item.name).join('、') }}</small>
        </li>
      </ol>
      <ul class="workflow-events">
        <li v-for="event in instance.history" :key="event.id"><div><strong>{{ candidateName(instance, event.actor_id) }} · {{ actionNames[event.action] || event.action }}</strong><time>{{ event.created_at }}</time></div><p v-if="event.opinion">{{ event.opinion }}</p><small v-if="event.signature_json">已签名 · 版本 {{ event.signature_json.version }} · {{ event.signature_json.signed_at }}</small></li>
      </ul>
    </article>
    <form v-if="canReview" class="workflow-review" @submit.prevent="review('accept')">
      <label>审批意见<textarea v-model="opinion" rows="3" maxlength="2000" :disabled="busy" placeholder="退回时必须填写意见" /></label>
      <div v-if="signature" class="workflow-signature-choice"><label><input v-model="useSignature" type="checkbox" :disabled="busy" /> 使用本人电子签名{{ activeNode?.signature_required ? '（必填）' : '' }}</label><img :src="backendUrl(signature.url)" alt="本人电子签名" /></div>
      <p v-else class="workflow-muted">尚未设置电子签名，可在个人设置中手写或扫码签名。</p>
      <div class="workflow-review-actions"><button class="workflow-accept" type="submit" :disabled="busy">通过</button><button type="button" :disabled="busy" @click="review('modify')">退回</button></div>
    </form>
  </section>
</template>

<style scoped>
.workflow-history { color: #253248; font-size: 14px; }
.workflow-history header,.workflow-round-title,.workflow-events li > div { display: flex; align-items: center; gap: 12px; }
.workflow-history header strong { margin-right: auto; }
.workflow-history button { border: 1px solid #dbe1ea; border-radius: 5px; padding: 7px 14px; background: #fff; color: #253248; cursor: pointer; font: inherit; }
.workflow-history button:disabled { opacity: .55; cursor: default; }
.workflow-history button:hover:not(:disabled) { background: #eef4fd; }
.workflow-round { margin-top: 16px; padding: 14px; border: 1px solid #e2e7ef; border-radius: 8px; }
.workflow-round-title { flex-wrap: wrap; }
.workflow-history time,.workflow-muted,.workflow-events small { font-size: 12px; color: #657185; }
.workflow-nodes { padding-left: 20px; }
.workflow-nodes li { padding: 8px; border-radius: 5px; }
.workflow-nodes li.active { background: #eef4ff; color: #245fac; }
.workflow-nodes span { font-size: 12px; margin-left: 8px; }
.workflow-nodes small { display: block; margin-top: 4px; }
.workflow-events { list-style: none; padding: 0; margin: 0; }
.workflow-events li { border-top: 1px solid #ecf0f5; padding: 10px 0; }
.workflow-events li > div { flex-wrap: wrap; justify-content: space-between; }
.workflow-events p { white-space: pre-wrap; overflow-wrap: anywhere; margin: 6px 0; }
.workflow-review { margin-top: 16px; display: grid; gap: 12px; }
.workflow-review textarea { display: block; width: 100%; box-sizing: border-box; margin-top: 6px; padding: 10px; border: 1px solid #dbe1ea; border-radius: 5px; font: inherit; resize: vertical; }
.workflow-signature-choice { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }
.workflow-signature-choice img { width: 150px; height: 70px; object-fit: contain; background: #fff; border: 1px solid #e2e7ef; }
.workflow-review-actions { display: flex; gap: 10px; }
.workflow-review-actions .workflow-accept { background: #285fae; color: #fff; }
.workflow-feedback { background: #fff7e8; color: #765415; padding: 10px; border-radius: 5px; }
</style>
