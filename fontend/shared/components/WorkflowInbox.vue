<script setup>
import { computed, ref, watch } from 'vue';
import WorkflowHistory from './WorkflowHistory.vue';
const props = defineProps({ request: { type: Function, required: true }, backendUrl: { type: Function, default: value => value }, accountId: { type: [Number, String], required: true } });
const emit = defineEmits(['open-expense']);
const kind = ref('review');
const items = ref([]);
const selected = ref(null);
const busy = ref(false);
const message = ref('');
const page = ref(1);
const total = ref(0);
const application = computed(() => selected.value?.snapshot_json?.application || selected.value?.snapshot_json?.expense || selected.value?.snapshot_json || {});
const base = computed(() => selected.value?.snapshot_json?.base || {});
const labels = { base_application: '基地申报', base_expense: '基地建设费用申请' };
const title = item => item.snapshot_json?.application?.title || item.snapshot_json?.expense?.title || item.snapshot_json?.title || labels[item.entity_type] || '审批申请';
async function load() {
  busy.value = true;
  message.value = '';
  try {
    const data = await props.request(`/workflow/inbox?kind=${kind.value}&page=${page.value}&page_size=20`);
    items.value = data.items || [];
    total.value = Number(data.pagination?.total || 0);
  } catch (error) { message.value = error.message; } finally { busy.value = false; }
}
function open(item) {
  if (item.entity_type === 'base_expense') { emit('open-expense', item.entity_id); return; }
  selected.value = item;
}
function changeKind(value) { kind.value = value; page.value = 1; selected.value = null; load(); }
watch(() => props.accountId, () => { page.value = 1; selected.value = null; load(); }, { immediate: true });
</script>

<template>
  <section class="workflow-inbox">
    <header><strong>我的审批</strong><button :disabled="busy" @click="changeKind('review')" :class="{ active: kind === 'review' }">待我审批</button><button :disabled="busy" @click="changeKind('cc')" :class="{ active: kind === 'cc' }">抄送给我</button><button :disabled="busy" @click="load">刷新</button></header>
    <p v-if="message" role="status">{{ message }}</p>
    <template v-if="selected">
      <button @click="selected = null">返回列表</button>
      <article class="workflow-inbox-detail">
        <h3>{{ title(selected) }}</h3>
        <dl><dt>类型</dt><dd>{{ labels[selected.entity_type] || '审批申请' }}</dd><dt>实习基地</dt><dd>{{ base.name || application.base_name || '—' }}</dd><dt>申报内容</dt><dd class="workflow-inbox-content">{{ String(application.content || '').replace(/<[^>]*>/g, '') || '—' }}</dd></dl>
      <WorkflowHistory :key="selected.instance_id" :entity-type="selected.entity_type" :entity-id="selected.entity_id" :account-id="accountId" :request="request" :backend-url="backendUrl" @updated="load" />
      </article>
    </template>
    <template v-else>
      <p v-if="!busy && !items.length" class="workflow-inbox-empty">{{ kind === 'review' ? '暂无待处理审批' : '暂无抄送记录' }}</p>
      <button v-for="item in items" :key="item.task_id" class="workflow-inbox-item" @click="open(item)"><strong>{{ title(item) }}</strong><span>{{ labels[item.entity_type] || '审批申请' }} · {{ item.nodes_json?.[Number(item.position) - 1]?.name }} · 第 {{ item.round }} 轮</span></button>
      <footer v-if="total"><button :disabled="busy || page <= 1" @click="page--; load()">上一页</button><span>{{ page }} / {{ Math.max(1, Math.ceil(total / 20)) }}</span><button :disabled="busy || page * 20 >= total" @click="page++; load()">下一页</button></footer>
    </template>
  </section>
</template>

<style scoped>
.workflow-inbox { height: 100%; overflow: auto; box-sizing: border-box; padding: 18px; background: #fff; color: #253248; }
header,footer { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
header strong { margin-right: auto; }
button { padding: 8px 12px; border: 1px solid #dce2eb; border-radius: 5px; background: #fff; color: #253248; font: inherit; cursor: pointer; }
button.active,button:hover:not(:disabled) { background: #edf4ff; color: #2359a8; }
button:disabled { opacity: .5; cursor: default; }
.workflow-inbox-item { display: flex; flex-direction: column; gap: 8px; width: 100%; text-align: left; margin: 10px 0; padding: 16px; }
.workflow-inbox-item span,.workflow-inbox-empty { font-size: 13px; color: #6d798b; }
.workflow-inbox-detail { margin-top: 16px; }
dl { display: grid; grid-template-columns: 90px minmax(0,1fr); gap: 10px; font-size: 14px; }
dt { color: #667388; } dd { margin: 0; overflow-wrap: anywhere; }
.workflow-inbox-content { white-space: pre-wrap; }
footer { margin-top: 16px; justify-content: flex-end; font-size: 13px; }
</style>
