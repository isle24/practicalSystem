<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import WorkflowHistory from './WorkflowHistory.vue';
import { workflowEntityLabels, workflowSnapshot } from '../workflowPresentation';
const props = defineProps({
  request: { type: Function, required: true },
  backendUrl: { type: Function, default: value => value },
  accountId: { type: [Number, String], required: true },
  entityType: { type: String, default: '' },
});
const emit = defineEmits(['open-expense']);
const kind = ref('review');
const items = ref([]);
const selected = ref(null);
const busy = ref(false);
const message = ref('');
const page = ref(1);
const pageSize = ref(20);
const total = ref(0);
let generation = 0;
let disposed = false;
const application = computed(() => workflowSnapshot(selected.value));
const base = computed(() => selected.value?.snapshot_json?.base || {});
const label = entityType => workflowEntityLabels[entityType] || '审批申请';
const title = item => {
  const snapshot = workflowSnapshot(item);
  return snapshot.title || snapshot.course_name || snapshot.name || label(item.entity_type);
};
async function load() {
  const version = ++generation;
  const current = () => !disposed && version === generation;
  busy.value = true;
  message.value = '';
  try {
    const query = new URLSearchParams({ kind: kind.value, page: page.value, page_size: pageSize.value });
    if (props.entityType) query.set('entity_type', props.entityType);
    const data = await props.request(`/workflow/inbox?${query}`);
    if (!current()) return;
    items.value = data.items || [];
    pageSize.value = Number(data.pagination?.page_size || pageSize.value);
    total.value = Number(data.pagination?.total || 0);
    const lastPage = Math.max(1, Math.ceil(total.value / pageSize.value));
    if (page.value > lastPage) { page.value = lastPage; await load(); return; }
    const updated = items.value.find(item => item.task_id === selected.value?.task_id);
    if (updated && updated.instance_id === selected.value.instance_id) selected.value = updated;
  } catch (error) { if (current()) { items.value = []; message.value = error.message; } }
  finally { if (current()) busy.value = false; }
}
function open(item) {
  if (item.entity_type === 'base_expense') { emit('open-expense', item); return; }
  selected.value = item;
}
function changeKind(value) { kind.value = value; page.value = 1; selected.value = null; items.value = []; load(); }
watch(() => [props.accountId, props.entityType], () => { page.value = 1; selected.value = null; items.value = []; total.value = 0; load(); }, { immediate: true });
onBeforeUnmount(() => { disposed = true; generation++; });
defineExpose({ load });
</script>

<template>
  <section class="workflow-inbox">
    <header><strong>我的审批</strong><button :disabled="busy" @click="changeKind('review')" :class="{ active: kind === 'review' }">待我审批</button><button :disabled="busy" @click="changeKind('cc')" :class="{ active: kind === 'cc' }">抄送给我</button><button :disabled="busy" @click="load">刷新</button></header>
    <p v-if="message" role="status">{{ message }}</p>
    <template v-if="selected">
      <button @click="selected = null">返回列表</button>
      <article class="workflow-inbox-detail">
        <h3>{{ title(selected) }}</h3>
        <p>{{ label(selected.entity_type) }} · {{ selected.kind === 'cc' ? '抄送' : '待办' }} · 第 {{ selected.round }} 轮</p>
        <dl v-if="selected.entity_type === 'base_application'"><dt>实习基地</dt><dd>{{ base.name || application.base_name || '—' }}</dd><dt>申报内容</dt><dd class="workflow-inbox-content">{{ String(application.description || application.content || '').replace(/<[^>]*>/g, '') || '—' }}</dd></dl>
        <WorkflowHistory
          :key="selected.task_id"
          :entity-type="selected.entity_type"
          :entity-id="selected.entity_id"
          :instance-id="selected.instance_id"
          :instance-revision="selected.revision"
          :task-id="selected.task_id"
          :review-allowed="selected.kind === 'review'"
          :account-id="accountId"
          :request="request"
          :backend-url="backendUrl"
          @updated="load"
        />
      </article>
    </template>
    <template v-else>
      <p v-if="!busy && !items.length" class="workflow-inbox-empty">{{ kind === 'review' ? '暂无待处理审批' : '暂无抄送记录' }}</p>
      <button v-for="item in items" :key="item.task_id" class="workflow-inbox-item" @click="open(item)"><strong>{{ title(item) }}</strong><span>{{ [label(item.entity_type), item.nodes_json?.[Number(item.position) - 1]?.name, `第 ${item.round} 轮`].filter(Boolean).join(' · ') }}</span></button>
      <footer><span>共 {{ total }} 条</span><button :disabled="busy || page <= 1" @click="page--; load()">上一页</button><span>{{ page }} / {{ Math.max(1, Math.ceil(total / pageSize)) }}</span><button :disabled="busy || page * pageSize >= total" @click="page++; load()">下一页</button></footer>
    </template>
  </section>
</template>

<style scoped>
.workflow-inbox { height: 100%; overflow: auto; box-sizing: border-box; padding: 18px; background: var(--surface, #fff); color: var(--text, #253248); }
header,footer { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
header strong { margin-right: auto; }
button { padding: 8px 12px; border: 1px solid var(--line, #dce2eb); border-radius: 5px; background: var(--surface, #fff); color: var(--text, #253248); font: inherit; cursor: pointer; }
button.active,button:hover:not(:disabled) { background: var(--primary-soft, #edf4ff); color: var(--primary-ink, #2359a8); }
button:disabled { opacity: .5; cursor: default; }
.workflow-inbox-item { display: flex; flex-direction: column; gap: 8px; width: 100%; text-align: left; margin: 10px 0; padding: 16px; }
.workflow-inbox-item span,.workflow-inbox-empty { font-size: 13px; color: var(--muted, #6d798b); }
.workflow-inbox-detail { margin-top: 16px; }
dl { display: grid; grid-template-columns: 90px minmax(0,1fr); gap: 10px; font-size: 14px; }
dt { color: var(--muted, #667388); } dd { margin: 0; overflow-wrap: anywhere; }
.workflow-inbox-content { white-space: pre-wrap; }
footer { margin-top: 16px; justify-content: flex-end; font-size: 13px; }
</style>
