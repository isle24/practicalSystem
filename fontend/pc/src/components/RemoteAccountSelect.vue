<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ElSelectV2 } from 'element-plus';
import { request } from '../api/client';

const props = defineProps({
  modelValue: { type: [Array, Number, String], default: () => [] },
  multiple: { type: Boolean, default: true },
  endpoint: { type: String, default: '/workflow/accounts' },
  disabled: Boolean,
});
const emit = defineEmits(['update:modelValue', 'change']);
const selectedIds = computed(() => (props.multiple ? (Array.isArray(props.modelValue) ? props.modelValue : []) : [props.modelValue]).filter(Boolean).map(Number));
const results = ref([]), selected = ref({}), loading = ref(false), error = ref(''), hasMore = ref(false);
const options = computed(() => [...new Map([
  ...selectedIds.value.map(id => selected.value[id] || { value: id, label: `账号 ${id}（正在读取）`, pending: true }),
  ...results.value,
].map(item => [item.value, item])).values()]);
let timer = null, revision = 0, alive = true, hydration = 0;
const controllers = new Set();
const option = item => ({ ...item, value: Number(item.id), label: `${item.name}（${item.login_name}）` });

async function fetchAccounts(query) {
  const controller = new AbortController();
  controllers.add(controller);
  try { return await request(`${props.endpoint}?${query}`, { signal: controller.signal }); }
  finally { controllers.delete(controller); }
}
function search(keyword = '') {
  clearTimeout(timer);
  const current = ++revision;
  loading.value = true;
  error.value = '';
  timer = setTimeout(async () => {
    try {
      const data = await fetchAccounts(new URLSearchParams({ keyword }));
      if (!alive || current !== revision) return;
      results.value = data.items.map(option);
      hasMore.value = data.has_more;
      for (const item of results.value) if (selectedIds.value.includes(item.value)) selected.value[item.value] = item;
    } catch (reason) { if (alive && current === revision) error.value = reason.message; }
    finally { if (alive && current === revision) loading.value = false; }
  }, 250);
}
function update(value) {
  const ids = props.multiple ? (value || []) : [value];
  for (const item of options.value) if (ids.includes(item.value) && !item.pending) selected.value[item.value] = item;
  emit('update:modelValue', value);
  emit('change', props.multiple ? ids.map(id => selected.value[id]).filter(Boolean) : selected.value[value] || null);
}
watch(selectedIds, async ids => {
  const current = ++hydration;
  const missing = ids.filter(id => !selected.value[id]);
  try {
    for (let index = 0; index < missing.length; index += 100) {
      const batch = missing.slice(index, index + 100);
      const data = await fetchAccounts(new URLSearchParams({ ids: batch.join(',') }));
      if (!alive || current !== hydration) return;
      for (const item of data.items) selected.value[item.id] = option(item);
      for (const id of batch) if (!selected.value[id]) selected.value[id] = { value: Number(id), label: `账号 ${id}（不可用，请移除）` };
    }
  } catch (reason) { if (alive && current === hydration) error.value = reason.message; }
}, { immediate: true });
onBeforeUnmount(() => { alive = false; clearTimeout(timer); controllers.forEach(controller => controller.abort()); });
</script>

<template>
  <div class="workflow-account-select">
    <el-select-v2 :model-value="modelValue" :options="options" :multiple="multiple" :disabled="disabled" filterable remote :remote-method="search" :loading="loading" collapse-tags collapse-tags-tooltip :max-collapse-tags="3" placeholder="输入姓名或登录账号搜索" @update:model-value="update" @visible-change="visible => { if (visible) search(); }" />
    <small v-if="error" role="alert">{{ error }}</small>
    <small v-else-if="hasMore">仅显示前 50 项，请输入更完整的姓名或账号。</small>
  </div>
</template>

<style scoped>
.workflow-account-select { width: 100%; min-width: 0; }
small { display: block; color: var(--muted); line-height: 1.6; margin-top: 6px; }
small[role="alert"] { color: var(--danger); }
</style>
