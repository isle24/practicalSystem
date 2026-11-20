<script setup>
import FormFieldLabel from './FormFieldLabel.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { workflowEntityLabels, workflowEventOutcome, workflowPlanFields, workflowSnapshot } from '../workflowPresentation';

const props = defineProps({
  reviewAllowed: { type: Boolean, default: true },
  entityType: { type: String, required: true },
  entityId: { type: [Number, String], required: true },
  instanceId: { type: [Number, String], default: 0 },
  instanceRevision: { type: [Number, String], default: '' },
  taskId: { type: [Number, String], default: 0 },
  accountId: { type: [Number, String], required: true },
  request: { type: Function, required: true },
  backendUrl: { type: Function, default: value => value },
});
const emit = defineEmits(['updated']);
const items = ref([]);
const busy = ref(false);
const message = ref('');
const opinion = ref('');
const decision = ref('');
const signature = ref(null);
const useSignature = ref(false);
const current = computed(() => props.instanceId
  ? items.value.find(item => Number(item.id) === Number(props.instanceId)) || null
  : items.value[0] || null);
const activeNode = computed(() => current.value?.nodes_json?.[Number(current.value.active_position) - 1] || null);
const pendingTask = computed(() => current.value?.status === 'wait' && current.value.tasks?.some(task =>
  task.kind === 'review' && task.status === 'pending'
  && (!props.taskId || Number(task.id) === Number(props.taskId))
  && Number(task.position) === Number(current.value.active_position) && Number(task.account_id) === Number(props.accountId)));
const staleRevision = computed(() => props.reviewAllowed && pendingTask.value && props.instanceRevision !== ''
  && String(current.value.revision) !== String(props.instanceRevision));
const canReview = computed(() => props.reviewAllowed && pendingTask.value && !staleRevision.value);
const isPlan = computed(() => ['internship_plan', 'lab_plan', 'training_plan', 'social_practice_plan'].includes(props.entityType));
const planFields = computed(() => current.value && isPlan.value
  ? workflowPlanFields(workflowSnapshot(current.value)).filter(([label, value]) => value || ['计划名称', '课程', '学年学期', '提交内容'].includes(label))
  : []);
const previousRound = computed(() => current.value?.previous_round || items.value[1]?.round || null);
const planChanges = computed(() => {
  const previous = current.value?.previous_snapshot_json;
  const prior = previous ? { snapshot_json: previous } : items.value[1];
  if (!isPlan.value || !current.value || !prior) return [];
  const fields = new Map(workflowPlanFields(workflowSnapshot(prior)));
  return workflowPlanFields(workflowSnapshot(current.value))
    .filter(([label, value]) => fields.get(label) !== value)
    .map(([label, value]) => ({ label, before: fields.get(label), after: value }));
});
const statusNames = { draft: '草稿', wait: '待审批', accept: '已通过', modify: '已退回', cancelled: '已取消' };
const actionNames = { start: '提交', accept: '通过', modify: '退回', cancel: '取消' };
const visibleHistory = instance => (instance.history || []).filter(event => event.action !== 'save_submit');
const opinionRule = computed(() => {
  const plan = isPlan.value;
  return decision.value === 'modify' ? { min: plan ? 8 : 1, max: plan ? 800 : 2000 } : { min: 0, max: plan && decision.value === 'accept' ? 300 : 2000 };
});
const opinionHelp = computed(() => !decision.value ? '请选择审批结果；退回必须说明原因' : decision.value === 'modify' ? `请说明修改要求，${opinionRule.value.min} 至 ${opinionRule.value.max} 字` : `可填写审批意见，最多 ${opinionRule.value.max} 字`);
const opinionError = computed(() => {
  if (!decision.value) return '';
  const length = Array.from(opinion.value.trim()).length;
  return length < opinionRule.value.min || length > opinionRule.value.max ? `审批意见须为 ${opinionRule.value.min} 至 ${opinionRule.value.max} 字` : '';
});
function candidateName(instance, actorId) {
  for (const node of instance.nodes_json || []) {
    const actor = node.candidates?.find(item => Number(item.account_id) === Number(actorId));
    if (actor) return actor.name;
  }
  return Number(instance.applicant_id) === Number(actorId) ? '申请人' : `账号 ${actorId}`;
}
function eventActor(instance, event) {
  const demo = event.result_json?.school_demo;
  if (!demo) return candidateName(instance, event.actor_id);
  if (demo.operator.account_id === demo.subject.account_id) return `${demo.operator.name}以${demo.subject.role_name}身份办理`;
  return `${demo.operator.name} 代 ${demo.subject.name}（${demo.subject.role_name}）办理`;
}
let generation = 0;
let disposed = false;
const sourceKey = () => [props.entityType, props.entityId, props.instanceId, props.instanceRevision, props.taskId, props.reviewAllowed, props.accountId].join(':');
async function reload() {
  const version = ++generation;
  items.value = [];
  signature.value = null;
  useSignature.value = false;
  opinion.value = '';
  decision.value = '';
  busy.value = false;
  message.value = '';
  if (!props.entityId) return;
  busy.value = true;
  message.value = '';
  try {
    const query = new URLSearchParams({ entity_type: props.entityType, entity_id: props.entityId });
    if (props.instanceId) query.set('instance_id', props.instanceId);
    const data = await props.request(`/workflow/history?${query}`);
    if (disposed || version !== generation) return;
    items.value = (data.items || []).filter(item => !props.instanceId || Number(item.id) === Number(props.instanceId));
    if (canReview.value) {
      const result = await props.request('/signature/current');
      if (!disposed && version === generation) signature.value = result.signature;
    }
  } catch (error) { if (!disposed && version === generation) message.value = error.message; }
  finally { if (!disposed && version === generation) busy.value = false; }
}
async function review() {
  const action = decision.value;
  if (disposed || busy.value || !canReview.value) return;
  const version = generation;
  const source = sourceKey();
  if (!['accept', 'modify'].includes(action)) { message.value = '请选择审批结果'; return; }
  if (opinionError.value) { message.value = opinionError.value; return; }
  if (activeNode.value?.signature_required && (!signature.value || !useSignature.value)) { message.value = '此节点需要本人签名，请先在个人设置中保存签名，并勾选用于本次审批。'; return; }
  busy.value = true;
  message.value = '';
  try {
    await props.request('/workflow/review', { method: 'POST', body: JSON.stringify({ instance_id: current.value.id, action, opinion: opinion.value, signature_id: useSignature.value ? signature.value?.id : null, revision: current.value.revision }) });
    if (disposed || version !== generation) return;
    await reload();
    if (disposed || source !== sourceKey()) return;
    emit('updated', current.value);
  } catch (error) { if (!disposed && version === generation) message.value = error.message; }
  finally { if (!disposed && version === generation) busy.value = false; }
}
watch(sourceKey, reload, { immediate: true });
onBeforeUnmount(() => { disposed = true; generation++; });
defineExpose({ reload });
</script>

<template>
  <section class="workflow-history" :aria-busy="busy">
    <header><strong>审批流程</strong><button type="button" :disabled="busy" @click="reload">刷新</button></header>
    <p v-if="message" class="workflow-feedback" role="status">{{ message }}</p>
    <p v-if="staleRevision" class="workflow-feedback" role="status">该待办的审批进度已变更，请返回列表刷新后重新打开。</p>
    <p v-if="!busy && !items.length && !message" class="workflow-muted">{{ instanceId ? '当前审批轮次已不可查看，请返回列表刷新。' : '尚未提交审批。请返回业务表单保存并提交。' }}</p>
    <section v-if="planFields.length" class="workflow-round">
      <strong>{{ workflowEntityLabels[entityType] }} · 第 {{ current.round }} 轮提交内容</strong>
      <dl class="workflow-snapshot">
        <template v-for="[label, value] in planFields" :key="label"><dt>{{ label }}</dt><dd>{{ value || '提交时未填写' }}</dd></template>
      </dl>
      <details v-if="previousRound">
        <summary>与第 {{ previousRound }} 轮的变更{{ planChanges.length ? `（${planChanges.length} 项）` : '' }}</summary>
        <p v-if="!planChanges.length" class="workflow-muted">提交字段没有变化。</p>
        <dl v-for="change in planChanges" :key="change.label" class="workflow-snapshot">
          <dt>{{ change.label }}</dt><dd><small>上轮</small> {{ change.before || '未填写' }}<br><small>本轮</small> {{ change.after || '未填写' }}</dd>
        </dl>
      </details>
    </section>
    <p v-if="current" class="workflow-muted">当前状态：{{ statusNames[current.status] || current.status }}。{{ current.status === 'wait' ? (canReview ? '请选择审批结果；节点要求签名时，确认使用本人签名后提交。' : '等待当前节点审批人处理，可刷新查看进度。') : current.status === 'modify' || current.status === 'cancelled' ? '请返回原业务表单按意见修改，再重新提交审核。' : '本轮审批已完成；需调整内容时先在原业务中发起通过后修改。' }}</p>
    <article v-for="instance in items" :key="instance.id" class="workflow-round">
      <div class="workflow-round-title"><strong>第 {{ instance.round }} 轮</strong><span>{{ statusNames[instance.status] || instance.status }}</span><time>{{ instance.created_at }}</time></div>
      <ol class="workflow-nodes">
        <li v-for="(node, index) in instance.nodes_json" :key="index" :class="{ active: instance.status === 'wait' && Number(instance.active_position) === index + 1 }">
          <strong>{{ node.name }}</strong><span>{{ node.kind === 'cc' ? '抄送' : node.mode === 'all' ? '会签' : '或签' }}</span>
          <small>{{ node.candidates?.map(item => item.name).join('、') }}</small>
        </li>
      </ol>
      <ul class="workflow-events">
        <li v-for="event in visibleHistory(instance)" :key="event.id"><div><strong>{{ instance.nodes_json?.[Number(event.position) - 1]?.name || '流程' }} · {{ eventActor(instance, event) }} · {{ actionNames[event.action] || event.action }}</strong><time>{{ event.created_at }}</time></div><p v-if="workflowEventOutcome(instance, event)">{{ workflowEventOutcome(instance, event) }}</p><p v-if="event.opinion">{{ event.opinion }}</p><small v-if="event.signature_json">已签名 · 版本 {{ event.signature_json.version }} · {{ event.signature_json.signed_at }}</small></li>
      </ul>
    </article>
    <form v-if="canReview" class="workflow-review" @submit.prevent="review">
      <fieldset :disabled="busy"><legend><FormFieldLabel :required="true">审批结果</FormFieldLabel></legend><label><input v-model="decision" type="radio" value="accept" /> 通过</label><label><input v-model="decision" type="radio" value="modify" /> 退回修改</label></fieldset>
      <label><FormFieldLabel :required="decision ? decision === 'modify' : undefined" :help="opinionHelp" :error="opinionError">审批意见</FormFieldLabel><textarea v-model="opinion" rows="3" :maxlength="opinionRule.max" :disabled="busy" placeholder="退回时必须填写意见" /></label>
      <div v-if="signature" class="workflow-signature-choice"><label><input v-model="useSignature" type="checkbox" :disabled="busy" /> <FormFieldLabel :required="Boolean(activeNode?.signature_required)">使用本人电子签名</FormFieldLabel></label><img :src="backendUrl(signature.url)" alt="本人电子签名" /></div>
      <p v-else class="workflow-muted">{{ activeNode?.signature_required ? '本节点要求本人电子签名，请先在个人设置中保存签名，再刷新并确认用于本次审批。' : '尚未设置电子签名，可在个人设置中手写或扫码签名。' }}</p>
      <div class="workflow-review-actions"><button class="workflow-accept" type="submit" :disabled="busy || !decision">{{ decision === 'accept' ? '确认通过' : decision === 'modify' ? '退回修改' : '请选择审批结果' }}</button></div>
    </form>
  </section>
</template>

<style scoped>
.workflow-history { color: var(--text, #253248); font-size: 14px; }
.workflow-history header,.workflow-round-title,.workflow-events li > div { display: flex; align-items: center; gap: 12px; }
.workflow-history header strong { margin-right: auto; }
.workflow-history button { border: 1px solid var(--line, #dbe1ea); border-radius: 5px; padding: 7px 14px; background: var(--surface, #fff); color: var(--text, #253248); cursor: pointer; font: inherit; }
.workflow-history button:disabled { opacity: .55; cursor: default; }
.workflow-history button:hover:not(:disabled) { background: var(--surface-2, #eef4fd); }
.workflow-round { margin-top: 16px; padding: 14px; border: 1px solid var(--line, #e2e7ef); border-radius: 8px; }
.workflow-round-title { flex-wrap: wrap; }
.workflow-snapshot { display: grid; grid-template-columns: minmax(76px, auto) minmax(0, 1fr); gap: 8px 12px; }
.workflow-snapshot dt,.workflow-snapshot small { color: var(--muted, #657185); }
.workflow-snapshot dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.workflow-history time,.workflow-muted,.workflow-events small { font-size: 12px; color: var(--muted, #657185); }
.workflow-nodes { padding-left: 20px; }
.workflow-nodes li { padding: 8px; border-radius: 5px; }
.workflow-nodes li.active { background: var(--primary-soft, #eef4ff); color: var(--primary-ink, #245fac); }
.workflow-nodes span { font-size: 12px; margin-left: 8px; }
.workflow-nodes small { display: block; margin-top: 4px; }
.workflow-events { list-style: none; padding: 0; margin: 0; }
.workflow-events li { border-top: 1px solid var(--line, #ecf0f5); padding: 10px 0; }
.workflow-events li > div { flex-wrap: wrap; justify-content: space-between; }
.workflow-events p { white-space: pre-wrap; overflow-wrap: anywhere; margin: 6px 0; }
.workflow-review { margin-top: 16px; display: grid; gap: 12px; }
.workflow-review fieldset { display: flex; gap: 20px; border: 1px solid var(--line, #dbe1ea); border-radius: 5px; padding: 10px; }
.workflow-review textarea { display: block; width: 100%; box-sizing: border-box; margin-top: 6px; padding: 10px; border: 1px solid var(--line, #dbe1ea); border-radius: 5px; font: inherit; resize: vertical; color: var(--text, #253248); background: var(--surface, #fff); }
.workflow-signature-choice { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }
.workflow-signature-choice label { display: flex; align-items: center; gap: 6px; }
.workflow-signature-choice img { width: 150px; height: 70px; object-fit: contain; background: #fff; border: 1px solid var(--line, #e2e7ef); }
.workflow-review-actions { display: flex; gap: 10px; }
.workflow-review-actions .workflow-accept { background: var(--primary, #285fae); color: var(--primary-contrast, #fff); }
.workflow-feedback { background: var(--amber-soft, #fff7e8); color: var(--amber, #765415); padding: 10px; border-radius: 5px; }
</style>
