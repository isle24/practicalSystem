<template>
  <div class="base-course-field">
    <el-select-v2 :model-value="modelValue" :options="options" multiple filterable remote clearable collapse-tags collapse-tags-tooltip :max-collapse-tags="3" :remote-method="search" :loading="loading" aria-label="服务课程" placeholder="搜索并选择服务课程" @update:model-value="value => emit('update:modelValue', value)" @visible-change="visible => visible && search('')" />
    <div class="base-course-hint"><small v-if="error" role="alert">{{ error }}</small><small v-else-if="hasMore">请输入完整课程名称或代码筛选。</small><el-button link type="primary" @click="openLibrary">维护课程库</el-button></div>
    <small v-if="legacyText && !modelValue.length">历史课程：{{ legacyText }}。选择课程后使用课程库关联。</small>
    <OperationDialog :visible="library.open" title="服务课程库" dialog-class="base-course-library" :busy="library.saving" @close="closeLibrary">
      <div class="base-course-library-body">
        <form class="base-course-edit-form" @submit.prevent="save">
          <strong>{{ library.form.id ? '修改课程' : '新增课程' }}</strong>
          <label><span>课程代码</span><el-input v-model="library.form.code" maxlength="120" :disabled="library.saving" placeholder="请输入课程代码" /></label>
          <label><span>课程名称</span><el-input v-model="library.form.name" maxlength="180" :disabled="library.saving" placeholder="请输入课程名称" /></label>
          <label><span>所属学院</span><el-select-v2 v-model="library.form.dep_id" :options="departmentOptions" clearable filterable :disabled="library.saving" placeholder="请选择学院" /></label>
          <label><span>启用状态</span><el-switch v-model="library.form.status" active-value="enabled" inactive-value="disabled" active-text="启用" inactive-text="停用" :disabled="library.saving" /></label>
          <div class="base-course-edit-actions"><el-button :disabled="library.saving" @click="newCourse">新增课程</el-button><el-button type="primary" :loading="library.saving" native-type="submit">保存课程</el-button></div>
          <el-alert v-if="library.error" :title="library.error" type="error" :closable="false" class="wide" />
        </form>
        <DataListPanel :rows="library.rows" :columns="columns" :filters="[{ key: 'keyword', label: '课程名称或代码', placeholder: '搜索课程名称或代码' }]" :filter-values="library.filters" :pagination="library.pagination" :loading="library.loading" :actions="[{ key: 'edit', label: '修改课程', disabled: row => !row.can_manage }]" storage-key="base-course-library" @filter-change="({ key, value }) => library.filters[key] = value" @search="loadLibrary(1)" @reset="resetLibrary" @page-change="loadLibrary" @row-action="({ row }) => editCourse(row)" />
      </div>
      <template #footer><el-button :disabled="library.saving" @click="closeLibrary">关闭</el-button></template>
    </OperationDialog>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import DataListPanel from './DataListPanel.vue';
import OperationDialog from './OperationDialog.vue';
import { fetchBaseCourses, saveBaseCourse } from '../api/baseCatalog';
const props = defineProps({ modelValue: { type: Array, default: () => [] }, selected: { type: Array, default: () => [] }, departments: { type: Array, default: () => [] }, depId: { type: [Number, String], default: null }, legacyText: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
const rows = ref([]), savedRows = ref([]), loading = ref(false), error = ref(''), hasMore = ref(false);
const options = computed(() => {
  const selected = new Set(props.modelValue.map(Number));
  const items = new Map([...props.selected, ...savedRows.value, ...rows.value].map(item => [Number(item.id), item]));
  for (const id of selected) if (!items.has(id)) items.set(id, { id, name: `课程 ${id}（正在读取）`, code: '' });
  return [...items.values()].filter(item => item.status !== 'disabled' || selected.has(Number(item.id))).map(item => ({ value: Number(item.id), label: [item.name, item.code].filter(Boolean).join(' · ') + (item.status === 'disabled' ? '（已停用）' : '') }));
});
const departmentOptions = computed(() => props.departments.map(item => ({ value: Number(item.dep_id), label: item.dep_name })));
const newForm = () => ({ id: null, revision: null, name: '', code: '', dep_id: props.depId || null, status: 'enabled' });
const library = reactive({ open: false, form: newForm(), filters: { keyword: '' }, rows: [], pagination: { page: 1, page_size: 50, total: 0 }, loading: false, saving: false, error: '' });
const columns = [{ prop: 'code', label: '课程代码', minWidth: 120 }, { prop: 'name', label: '课程名称', minWidth: 180, required: true }, { prop: 'dep_name', label: '所属学院', minWidth: 130, formatter: row => row.dep_name || '全校通用' }, { prop: 'status', label: '状态', width: 90, formatter: row => row.status === 'enabled' ? '启用' : '停用' }];
let alive = true, sequence = 0, listSequence = 0, hydrateSequence = 0, timer, controller, listController, hydrateController;
function search(keyword = '') {
  clearTimeout(timer); controller?.abort(); const version = ++sequence; loading.value = true;
  timer = setTimeout(async () => {
    controller = new AbortController();
    try { const data = await fetchBaseCourses({ keyword }, { signal: controller.signal }); if (alive && version === sequence) { rows.value = data.items || []; hasMore.value = data.has_more; error.value = ''; } }
    catch (reason) { if (alive && version === sequence && reason.name !== 'AbortError') error.value = reason.message; }
    finally { if (alive && version === sequence) loading.value = false; }
  }, 250);
}
async function loadLibrary(page = 1) {
  listController?.abort(); listController = new AbortController(); const version = ++listSequence; library.loading = true;
  try { const data = await fetchBaseCourses({ ...library.filters, manage: '1', page }, { signal: listController.signal }); if (alive && library.open && version === listSequence) { library.rows = data.items || []; library.pagination = data.pagination; } }
  catch (reason) { if (alive && version === listSequence && reason.name !== 'AbortError') library.error = reason.message; }
  finally { if (alive && version === listSequence) library.loading = false; }
}
function resetLibrary() { library.filters.keyword = ''; loadLibrary(1); }
function openLibrary() { library.open = true; newCourse(); loadLibrary(1); }
function closeLibrary() { if (library.saving) return; library.open = false; listSequence++; listController?.abort(); library.loading = false; }
function newCourse() { library.form = newForm(); library.error = ''; }
function editCourse(row) { if (!library.saving && row.can_manage) { library.form = { ...row }; library.error = ''; } }
async function save() {
  if (library.saving) return;
  if (!library.form.name.trim() || !library.form.code.trim()) { library.error = '请填写课程代码和名称。'; return; }
  library.saving = true; library.error = '';
  try {
    const data = await saveBaseCourse({ ...library.form }); if (!alive) return;
    savedRows.value = [...savedRows.value.filter(item => Number(item.id) !== Number(data.item.id)), data.item];
    library.form = { ...data.item };
    await loadLibrary(library.pagination.page);
    search('');
  } catch (reason) { if (alive) library.error = reason.message; }
  finally { if (alive) library.saving = false; }
}
watch(() => props.modelValue.join(','), async () => {
  const known = new Set([...props.selected, ...savedRows.value, ...rows.value].map(item => Number(item.id)));
  const missing = props.modelValue.filter(id => !known.has(Number(id)));
  hydrateController?.abort(); const version = ++hydrateSequence; if (!missing.length) return;
  hydrateController = new AbortController();
  try { const data = await fetchBaseCourses({ ids: missing.join(',') }, { signal: hydrateController.signal }); if (alive && version === hydrateSequence) savedRows.value = [...savedRows.value, ...(data.items || [])]; }
  catch (reason) { if (alive && version === hydrateSequence && reason.name !== 'AbortError') error.value = reason.message; }
}, { immediate: true });
onBeforeUnmount(() => { alive = false; clearTimeout(timer); controller?.abort(); listController?.abort(); hydrateController?.abort(); });
</script>

<style scoped>
.base-course-field { min-width: 0; display: grid; gap: 6px; }
.base-course-hint { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.base-course-hint .el-button { margin-left: auto; }
small { color: var(--muted); font-size: 12px; line-height: 1.6; }
small[role='alert'] { color: var(--danger); }
:global(.operation-dialog.base-course-library) { width: min(940px, calc(100vw - 48px)); }
.base-course-library-body { min-height: 0; display: flex; flex-direction: column; gap: 18px; padding: 18px; overflow: auto; }
.base-course-library-body > .data-list-panel { flex: 1; min-height: 260px; }
.base-course-edit-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 16px; padding-bottom: 18px; border-bottom: 1px solid var(--line); }
.base-course-edit-form > strong, .base-course-edit-actions, .wide { grid-column: 1 / -1; }
.base-course-edit-form > label { min-width: 0; display: grid; gap: 8px; font-size: 13px; }
.base-course-edit-actions { display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 600px) { .base-course-edit-form { grid-template-columns: 1fr; } }
</style>
