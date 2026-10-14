<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ElSelectV2 } from 'element-plus';
import { fetchUserFilterOptions } from '../api/system';

const props = defineProps({
  modelValue: { type: [Number, String], default: '' },
  type: { type: String, required: true },
  roleType: { type: String, required: true },
  depId: { type: [Number, String], default: '' },
  professionId: { type: [Number, String], default: '' },
  sessionKey: { type: String, required: true },
  label: { type: String, required: true },
});
const emit = defineEmits(['update:modelValue', 'change']);
const rows = ref([]), selected = ref(null), loading = ref(false), error = ref(''), hasMore = ref(false);
const options = computed(() => [...new Map([
  ...(props.modelValue ? [selected.value || { id: Number(props.modelValue), label: `${props.label} ${props.modelValue}（正在读取）` }] : []),
  ...rows.value,
].map(item => [item.id, { ...item, value: item.id }])).values()]);
const scopeKey = computed(() => JSON.stringify([props.sessionKey, props.type, props.roleType, props.depId, props.professionId]));
let timer, revision = 0, hydration = 0, alive = true, page = 1, keyword = '';
const controllers = new Set();
const searchControllers = new Set();

function parameters(extra = {}) {
  return { type: props.type, role_type: props.roleType, dep_id: props.depId, profession_id: props.professionId, ...extra };
}
async function fetchOptions(extra) {
  const controller = new AbortController();
  controllers.add(controller);
  if (!extra.selected_id) searchControllers.add(controller);
  try { return await fetchUserFilterOptions(parameters(extra), { signal: controller.signal }); }
  finally { controllers.delete(controller); searchControllers.delete(controller); }
}
function cancel() {
  clearTimeout(timer);
  controllers.forEach(controller => controller.abort());
}
function search(value = '') {
  clearTimeout(timer);
  searchControllers.forEach(controller => controller.abort());
  keyword = value;
  page = 1;
  const current = ++revision;
  loading.value = true;
  error.value = '';
  timer = setTimeout(() => loadPage(1, current), 250);
}
async function loadPage(nextPage, current = ++revision) {
  loading.value = true;
  error.value = '';
  try {
    const data = await fetchOptions({ keyword, page: nextPage });
    if (!alive || current !== revision) return;
    rows.value = nextPage === 1 ? data.items : [...rows.value, ...data.items];
    page = nextPage;
    hasMore.value = data.has_more;
    selected.value = rows.value.find(item => item.id === Number(props.modelValue)) || selected.value;
  } catch (reason) {
    if (alive && current === revision && reason.name !== 'AbortError') error.value = reason.message;
  } finally {
    if (alive && current === revision) loading.value = false;
  }
}
function update(value) {
  selected.value = options.value.find(item => item.id === Number(value)) || null;
  emit('update:modelValue', value ?? '');
  emit('change', selected.value);
}
watch(scopeKey, () => {
  revision++; hydration++; cancel();
  rows.value = []; selected.value = null; loading.value = false; hasMore.value = false; error.value = ''; page = 1; keyword = '';
}, { flush: 'sync' });
watch(() => [scopeKey.value, props.modelValue], async ([, id]) => {
  const current = ++hydration;
  if (!id) { selected.value = null; return; }
  selected.value = rows.value.find(item => item.id === Number(id)) || null;
  if (selected.value) return;
  try {
    const data = await fetchOptions({ selected_id: id, page: 1 });
    if (!alive || current !== hydration) return;
    selected.value = data.items[0] || { id: Number(id), label: `${props.label} ${id}（不可用，请清除）`, disabled: true };
  } catch (reason) {
    if (alive && current === hydration && reason.name !== 'AbortError') error.value = reason.message;
  }
}, { immediate: true });
onBeforeUnmount(() => { alive = false; revision++; hydration++; cancel(); });
</script>

<template>
  <div class="remote-academic-select">
    <el-select-v2 :model-value="modelValue ? Number(modelValue) : undefined" :options="options" clearable filterable remote :remote-method="search" :loading="loading" :aria-label="label" :placeholder="`搜索${label}`" @update:model-value="update" @visible-change="visible => { if (visible) search(); }" />
    <small v-if="error" role="alert">{{ error }}</small>
    <el-button v-else-if="hasMore" link type="primary" :loading="loading" @click="loadPage(page + 1)">加载更多{{ label }}</el-button>
  </div>
</template>

<style scoped>
.remote-academic-select { width: 168px; min-width: 0; }
small { display: block; color: var(--danger); line-height: 1.5; }
</style>
