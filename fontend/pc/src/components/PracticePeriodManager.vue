<template>
  <section class="practice-period-manager">
    <div class="admin-toolbar">
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openDialog()">新增课节</el-button>
      <el-button :icon="RefreshCw" :loading="state.loading" @click="loadPeriods">刷新</el-button>
    </div>
    <el-alert v-if="state.message && !state.visible" type="warning" :closable="false" show-icon :title="state.message" />
    <el-table width-storage-key="practice-period-manager:state-rows" :data="state.rows" height="100%" stripe v-loading="state.loading">
      <el-table-column type="index" label="序号" width="66" align="center" />
      <el-table-column prop="name" label="课节名称" min-width="180" />
      <el-table-column label="上课时间" min-width="180">
        <template #default="{ row }">{{ timeText(row.start_time) }} - {{ timeText(row.end_time) }}</template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="100" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }"><el-tag :type="row.status === 'enabled' ? 'success' : 'info'">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag></template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="110" fixed="right">
        <template #default="{ row }"><el-button link type="primary" @click="openDialog(row)">编辑</el-button></template>
      </el-table-column>
    </el-table>
    <OperationDialog
      :visible="state.visible"
      :title="state.form.id ? '编辑课节' : '新增课节'"
      dialog-class="period-edit-dialog"
      :busy="state.saving"
      @close="closeDialog"
    >
        <el-alert v-if="state.message" type="warning" :closable="false" show-icon :title="state.message" />
        <div class="operation-form">
          <label><FormFieldLabel :required="true" help="最多 60 字" :error="formErrors.name">课节名称</FormFieldLabel><input v-model="state.form.name" maxlength="60" :aria-invalid="Boolean(formErrors.name)"></label>
          <label><FormFieldLabel :required="false" help="非负整数，默认 0">排序</FormFieldLabel><input v-model.number="state.form.sort" type="number" min="0" step="1"></label>
          <label><FormFieldLabel :required="true" :error="formErrors.start_time">开始时间</FormFieldLabel><input v-model="state.form.start_time" type="time" :aria-invalid="Boolean(formErrors.start_time)"></label>
          <label><FormFieldLabel :required="true" help="结束时间必须晚于开始时间" :error="formErrors.end_time">结束时间</FormFieldLabel><input v-model="state.form.end_time" type="time" :aria-invalid="Boolean(formErrors.end_time)"></label>
          <label><FormFieldLabel :required="false">状态</FormFieldLabel><el-switch v-model="state.form.status" active-value="enabled" inactive-value="disabled" active-text="启用" inactive-text="停用" /></label>
        </div>
        <template #footer><el-button :disabled="state.saving" @click="closeDialog">取消</el-button><el-button type="primary" :loading="state.saving" @click="save">保存</el-button></template>
    </OperationDialog>
  </section>
</template>

<script setup>
import FormFieldLabel from '../../../shared/components/FormFieldLabel.vue';
import { computed, onMounted, reactive } from 'vue';
import { Plus, RefreshCw } from '@lucide/vue';
import { fetchPracticePeriods, savePracticePeriod } from '../api/system';
import OperationDialog from './OperationDialog.vue';

defineProps({ canManage: { type: Boolean, default: false } });

const state = reactive({
  loading: false,
  saving: false,
  message: '',
  rows: [],
  visible: false,
  validating: false,
  form: emptyForm(),
});

const formErrors = computed(() => {
  if (!state.validating) return {};
  const errors = {};
  const name = String(state.form.name || '').trim();
  if (!name) errors.name = '请填写课节名称';
  else if (Array.from(name).length > 60) errors.name = '课节名称最多 60 字';
  if (!state.form.start_time) errors.start_time = '请选择开始时间';
  if (!state.form.end_time) errors.end_time = '请选择结束时间';
  else if (state.form.start_time && state.form.end_time <= state.form.start_time) errors.end_time = '结束时间必须晚于开始时间';
  return errors;
});

onMounted(loadPeriods);

async function loadPeriods() {
  state.loading = true;
  state.message = '';
  try {
    const data = await fetchPracticePeriods({ page: 1, page_size: 100 });
    state.rows = data.items || [];
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function openDialog(row = null) {
  state.form = emptyForm(row || {});
  state.validating = false;
  state.message = '';
  state.visible = true;
}

function closeDialog() {
  if (state.saving) return;
  state.visible = false;
}

async function save() {
  if (state.saving) return;
  const form = state.form;
  state.validating = true;
  if (Object.keys(formErrors.value).length) return;
  state.saving = true;
  state.message = '';
  try {
    await savePracticePeriod(form);
    state.visible = false;
    await loadPeriods();
  } catch (error) {
    state.message = error.message;
  } finally {
    state.saving = false;
  }
}

function emptyForm(row = {}) {
  return {
    id: row.id || null,
    name: row.name || '',
    start_time: timeText(row.start_time),
    end_time: timeText(row.end_time),
    sort: Number(row.sort || 0),
    status: row.status || 'enabled',
  };
}

function timeText(value) {
  return value ? String(value).slice(0, 5) : '';
}
</script>
