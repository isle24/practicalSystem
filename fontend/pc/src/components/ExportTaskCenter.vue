<template>
  <section class="support-panel export-task-panel">
    <div class="support-toolbar">
      <el-select v-model="filters.status" @change="loadTasks(1)">
        <el-option label="全部状态" value="all" />
        <el-option label="待处理" value="pending" />
        <el-option label="处理中" value="processing" />
        <el-option label="已完成" value="completed" />
        <el-option label="失败" value="failed" />
        <el-option label="超时" value="timeout" />
      </el-select>
      <el-input v-model="filters.keyword" clearable placeholder="搜索文件名、类型、创建人" @keyup.enter="loadTasks(1)" />
      <el-button :icon="Search" :loading="loading" @click="loadTasks(1)">查询</el-button>
      <el-button :icon="RefreshCw" :loading="loading" @click="loadTasks(pagination.page)">刷新</el-button>
      <el-button type="primary" :icon="Plus" @click="openCreateDialog">创建任务</el-button>
    </div>

    <section class="support-table">
      <el-table :data="tasks" height="100%" stripe v-loading="loading">
        <el-table-column prop="file_name" label="文件名" min-width="220" />
        <el-table-column prop="type" label="类型" width="140" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="进度" width="160">
          <template #default="{ row }">
            <el-progress :percentage="Number(row.progress || 0)" :stroke-width="8" />
          </template>
        </el-table-column>
        <el-table-column prop="total_rows" label="行数" width="90" />
        <el-table-column prop="user_name" label="创建人" width="120" />
        <el-table-column prop="created_at" label="创建时间" width="168" />
        <el-table-column prop="finished_at" label="完成时间" width="168" />
        <el-table-column label="失败原因" min-width="180">
          <template #default="{ row }">
            <span class="log-payload" :title="row.error_message || '-'">{{ row.error_message || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" :disabled="!row.file_url" @click="openFile(row)">下载</el-button>
            <el-button link type="primary" :disabled="!['failed', 'timeout'].includes(row.status)" @click="retryTask(row)">重试</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="file-pagination">
        <span>共 {{ pagination.total }} 个任务</span>
        <el-pagination
          size="small"
          layout="prev, pager, next"
          :current-page="pagination.page"
          :page-size="pagination.page_size"
          :total="pagination.total"
          @current-change="loadTasks"
        />
      </div>
    </section>

    <small v-if="message">{{ message }}</small>

    <div v-if="createDialog.visible" class="operation-mask" @click.self="createDialog.visible = false">
      <section class="operation-dialog export-create-dialog">
        <header>
          <strong>创建导出任务</strong>
          <button type="button" @click="createDialog.visible = false">关闭</button>
        </header>
        <div class="operation-form">
          <label>
            <span>导出类型</span>
            <input v-model="createDialog.form.type" placeholder="如 internship_journals">
          </label>
          <label>
            <span>文件名</span>
            <input v-model="createDialog.form.file_name" placeholder="不填则自动生成">
          </label>
          <label class="span-2">
            <span>参数 JSON</span>
            <textarea v-model="createDialog.paramsText" rows="7" placeholder='{"grade_id":1}' />
          </label>
        </div>
        <footer>
          <el-button @click="createDialog.visible = false">取消</el-button>
          <el-button type="primary" :icon="Save" :loading="saving" @click="createTask">创建</el-button>
        </footer>
      </section>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Plus, RefreshCw, Save, Search } from '@lucide/vue';
import { createExportTask, fetchExportTasks, retryExportTask } from '../api/system';

const loading = ref(false);
const saving = ref(false);
const message = ref('');
const tasks = ref([]);
const filters = reactive({
  status: 'all',
  keyword: '',
});
const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0,
});
const createDialog = reactive({
  visible: false,
  paramsText: '{}',
  form: {
    type: 'common_export',
    file_name: '',
  },
});

onMounted(() => loadTasks(1));

async function loadTasks(page = 1) {
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchExportTasks({
      page,
      page_size: pagination.page_size,
      status: filters.status,
      keyword: filters.keyword,
    });
    tasks.value = data.items || [];
    Object.assign(pagination, data.pagination || {});
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

function openCreateDialog() {
  createDialog.form = {
    type: 'common_export',
    file_name: '',
  };
  createDialog.paramsText = '{}';
  createDialog.visible = true;
}

async function createTask() {
  saving.value = true;
  message.value = '';
  try {
    let params = {};
    if (createDialog.paramsText.trim()) {
      params = JSON.parse(createDialog.paramsText);
    }
    await createExportTask({
      ...createDialog.form,
      params,
    });
    createDialog.visible = false;
    await loadTasks(1);
    message.value = '导出任务已创建';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function retryTask(row) {
  saving.value = true;
  message.value = '';
  try {
    await retryExportTask(row.id);
    await loadTasks(pagination.page || 1);
    message.value = '已重新排队';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

function openFile(row) {
  if (row.file_url) {
    window.open(row.file_url, '_blank', 'noopener');
  }
}

function statusText(status) {
  const names = {
    pending: '待处理',
    processing: '处理中',
    completed: '已完成',
    failed: '失败',
    timeout: '超时',
  };
  return names[status] || status || '-';
}

function statusTagType(status) {
  if (status === 'completed') {
    return 'success';
  }
  if (['failed', 'timeout'].includes(status)) {
    return 'danger';
  }
  if (status === 'processing') {
    return 'warning';
  }
  return 'info';
}
</script>
