<template>
  <div class="base-manager-select">
    <el-select-v2 :model-value="modelValue" :options="options" filterable remote clearable :disabled="disabled" :loading="loading" :remote-method="search" placeholder="输入姓名或单位搜索负责人" @update:model-value="update" @visible-change="visible => visible && search('')" />
    <small v-if="error" role="alert">{{ error }}</small>
    <small v-else-if="!modelValue && selected?.name">历史负责人：{{ selected.name }}。选择人员后更新负责人资料。</small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { fetchBasePeople } from '../api/baseCatalog';
const props = defineProps({ modelValue: { type: [Number, String], default: null }, selected: { type: Object, default: null }, disabled: Boolean });
const emit = defineEmits(['update:modelValue', 'change']);
const rows = ref([]), hydrated = ref(null), loading = ref(false), error = ref('');
const options = computed(() => {
  const map = new Map(rows.value.map(item => [Number(item.id), item]));
  if (props.modelValue && !map.has(Number(props.modelValue))) map.set(Number(props.modelValue), hydrated.value || { id: Number(props.modelValue), name: props.selected?.name || '原负责人', dep_name: props.selected?.dep_name || '' });
  return [...map.values()].map(item => ({ ...item, value: Number(item.id), label: [item.name, item.dep_name || item.organization_name].filter(Boolean).join(' · ') }));
});
let timer, sequence = 0, hydration = 0, alive = true, controller, hydrateController;
function search(keyword = '') {
  clearTimeout(timer); controller?.abort(); const version = ++sequence; loading.value = true;
  timer = setTimeout(async () => {
    controller = new AbortController();
    try { const data = await fetchBasePeople({ keyword }, { signal: controller.signal }); if (alive && version === sequence) { rows.value = data.items || []; error.value = ''; } }
    catch (reason) { if (alive && version === sequence && reason.name !== 'AbortError') error.value = reason.message; }
    finally { if (alive && version === sequence) loading.value = false; }
  }, 250);
}
function update(id) {
  const item = options.value.find(option => option.value === Number(id));
  emit('update:modelValue', id || null);
  emit('change', item || null);
}
watch(() => props.modelValue, async id => {
  hydrateController?.abort(); const version = ++hydration; hydrated.value = null;
  if (!id) return;
  hydrateController = new AbortController();
  try { const data = await fetchBasePeople({ ids: id }, { signal: hydrateController.signal }); if (alive && version === hydration) hydrated.value = data.items?.[0] || null; }
  catch (reason) { if (alive && version === hydration && reason.name !== 'AbortError') error.value = reason.message; }
}, { immediate: true });
onBeforeUnmount(() => { alive = false; clearTimeout(timer); controller?.abort(); hydrateController?.abort(); });
</script>
<style scoped>
.base-manager-select { width: 100%; min-width: 0; }
small { display: block; color: var(--muted); font-size: 12px; line-height: 1.6; margin-top: 6px; }
small[role='alert'] { color: var(--danger); }
</style>
