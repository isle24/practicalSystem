<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { request } from '../api/client';

const props = defineProps({ modelValue: { type: Array, default: () => [] }, type: { type: String, required: true }, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const results = ref([]), selected = ref({}), loading = ref(false), error = ref(''), keyword = ref('');
const pagination = ref({ page: 0, page_size: 30, total: 0 });
const ids = computed(() => props.modelValue.map(Number));
const options = computed(() => [...new Map([...ids.value.map(id => selected.value[id] || { value: id, label: '正在读取已选目标' }), ...results.value].map(row => [row.value, row])).values()]);
const hasMore = computed(() => pagination.value.page * pagination.value.page_size < pagination.value.total);
const option = row => ({ value: Number(row.id), label: props.type === 'accounts' ? `${row.name}（${row.login_name}）` : row.name });
let sequence = 0, hydration = 0, timer, alive = true;
const controllers = new Set();

async function fetchOptions(params) {
  const controller = new AbortController(); controllers.add(controller);
  try { return await request(`/favorite/share-options?${new URLSearchParams({ type: props.type, ...params })}`, { signal: controller.signal }); }
  finally { controllers.delete(controller); }
}
async function load(page = 1, ticket = sequence) {
  if (!alive || ticket !== sequence) return;
  loading.value = true; error.value = '';
  try {
    const data = await fetchOptions({ keyword: keyword.value, page, page_size: 30 });
    if (!alive || ticket !== sequence) return;
    results.value = page === 1 ? data.items.map(option) : [...results.value, ...data.items.map(option)];
    pagination.value = data.pagination;
    for (const row of results.value) if (ids.value.includes(row.value)) selected.value[row.value] = row;
  } catch (reason) { if (alive && ticket === sequence) error.value = reason.message; }
  finally { if (alive && ticket === sequence) loading.value = false; }
}
function search(value = '') {
  keyword.value = value; const ticket = ++sequence; clearTimeout(timer);
  loading.value = true;
  timer = setTimeout(() => load(1, ticket), 250);
}
function update(values) {
  for (const row of options.value) if (values.includes(row.value)) selected.value[row.value] = row;
  emit('update:modelValue', values);
}
watch(() => props.type, () => {
  sequence++; hydration++; clearTimeout(timer);
  controllers.forEach(controller => controller.abort());
  results.value = []; selected.value = {}; keyword.value = ''; error.value = ''; loading.value = false;
  pagination.value = { page: 0, page_size: 30, total: 0 };
}, { flush: 'sync' });
watch(() => [props.type, ...ids.value], async () => {
  const ticket = ++hydration;
  const missing = ids.value.filter(id => !selected.value[id]);
  try {
    for (let offset = 0; offset < missing.length; offset += 100) {
      const batch = missing.slice(offset, offset + 100);
      const data = await fetchOptions({ ids: batch.join(',') });
      if (!alive || ticket !== hydration) return;
      for (const row of data.items) selected.value[row.id] = option(row);
      for (const id of batch) if (!selected.value[id]) selected.value[id] = { value: id, label: '目标已停用或超出管理范围，请移除' };
    }
  } catch (reason) { if (alive && ticket === hydration) error.value = reason.message; }
}, { immediate: true });
onBeforeUnmount(() => { alive = false; sequence += 1; hydration += 1; clearTimeout(timer); controllers.forEach(controller => controller.abort()); });
</script>

<template>
  <div class="favorite-target-select">
    <el-select-v2 :model-value="modelValue" :options="options" :disabled="disabled" multiple filterable remote :remote-method="search" :loading="loading" collapse-tags collapse-tags-tooltip :max-collapse-tags="3" :placeholder="type === 'college' ? '搜索并选择学院' : '搜索姓名或登录账号，可多选'" @update:model-value="update" @visible-change="visible => visible && search('')">
      <template #footer><div class="favorite-target-footer"><span>已加载 {{ results.length }} / {{ pagination.total }} 项</span><el-button v-if="hasMore" text :loading="loading" @click.stop="load(pagination.page + 1)">加载更多</el-button></div></template>
    </el-select-v2>
    <small v-if="error" role="alert">{{ error }}</small>
  </div>
</template>

<style scoped>
.favorite-target-select{min-width:0;width:100%}.favorite-target-footer{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--muted)}small[role=alert]{display:block;margin-top:6px;color:var(--danger)}
</style>
