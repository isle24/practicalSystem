<template>
  <section class="practice-period-manager">
    <div class="admin-toolbar">
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openDialog()">新增课节</el-button>
      <el-button :icon="RefreshCw" :loading="state.loading" @click="loadPeriods">刷新</el-button>
    </div>
    <el-alert v-if="state.message" type="warning" :closable="false" show-icon :title="state.message" />
    <el-table :data="state.rows" height="100%" stripe v-loading="state.loading">
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
        <div class="operation-form">
          <label><span>课节名称</span><input v-model="state.form.name"></label>
          <label><span>排序</span><input v-model.number="state.form.sort" type="number" min="0"></label>
          <label><span>开始时间</span><input v-model="state.form.start_time" type="time"></label>
          <label><span>结束时间</span><input v-model="state.form.end_time" type="time"></label>
          <label><span>状态</span><el-switch v-model="state.form.status" active-value="enabled" inactive-value="disabled" active-text="启用" inactive-text="停用" /></label>
        </div>
        <template #footer><el-button @click="closeDialog">取消</el-button><el-button type="primary" :loading="state.saving" @click="save">保存</el-button></template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { onMounted, reactive } from 'vue';
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
  form: emptyForm(),
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
  state.visible = true;
}

function closeDialog() {
  state.visible = false;
}

async function save() {
  const form = state.form;
  if (!form.name.trim() || !form.start_time || !form.end_time) {
    state.message = '请填写完整课节信息';
    return;
  }
  if (form.end_time <= form.start_time) {
    state.message = '结束时间必须晚于开始时间';
    return;
  }
  state.saving = true;
  state.message = '';
  try {
    await savePracticePeriod(form);
    closeDialog();
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
