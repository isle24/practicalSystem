<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Download, RefreshCw, Upload } from '@lucide/vue';
import OperationDialog from './OperationDialog.vue';
import {
  fetchAccountImportDetail,
  fetchAccountImportTasks,
  previewStudentAccounts,
  previewTeacherAccounts,
  retryAccountImport,
  startStudentAccounts,
  startTeacherAccounts,
  teacherAccountTemplateUrl,
} from '../api/accountImports';

const props = defineProps({
  visible: { type: Boolean, default: false },
  mode: { type: String, default: 'teacher' },
  sessionKey: { type: String, default: '' },
});
const emit = defineEmits(['close', 'completed']);
const fileInput = ref(null);
const state = reactive({
  loading: false,
  previewLoading: false,
  submitting: false,
  detailLoading: false,
  message: '',
  fileName: '',
  preview: null,
  password: '',
  tasks: [],
  selectedId: null,
  task: null,
});
const teacherMode = computed(() => props.mode === 'teacher');
const passwordBytes = computed(() => new TextEncoder().encode(state.password).length);
const validPassword = computed(() => Array.from(state.password).length >= 6 && passwordBytes.value <= 72
  && !state.password.includes('\0') && !/^[ \t\n\r\v]*$/.test(state.password));
const canStart = computed(() => !!state.preview && !state.previewLoading && !state.submitting && (teacherMode.value
  ? Number(state.preview.valid_rows) > 0
  : Number(state.preview.eligible_rows) > 0 && validPassword.value));
const pendingTasks = computed(() => state.tasks.some(task => isPending(task.status)));
const taskProgress = computed(() => Math.min(100, Math.max(0, Number(state.task?.progress) || 0)));
let generation = 0;
let listGeneration = 0;
let detailGeneration = 0;
let pollTimer = null;
let readController = null;
let requestKey = '';
let disposed = false;
const reportedTasks = new Set();

function isPending(status) {
  return ['queued', 'processing'].includes(status);
}

function statusText(status) {
  return ({ queued: '排队中', processing: '处理中', completed: '已完成', completed_with_errors: '部分失败', partial_failed: '部分失败', failed: '失败' })[status] || status || '-';
}

function statusType(status) {
  return status === 'completed' ? 'success' : isFailedStatus(status) ? 'danger' : 'info';
}

function isFailedStatus(status) {
  return ['failed', 'completed_with_errors', 'partial_failed'].includes(status);
}

function active(token) {
  return !disposed && props.visible && !!props.sessionKey && token === generation;
}

function stopPolling() {
  window.clearTimeout(pollTimer);
  pollTimer = null;
}

function schedulePolling(token) {
  stopPolling();
  if (active(token) && !state.submitting && pendingTasks.value) pollTimer = window.setTimeout(() => loadTasks(token, false), 2500);
}

function recordTask(task) {
  if (!task) return;
  const index = state.tasks.findIndex(item => item.id === task.id);
  if (index < 0) state.tasks.unshift(task);
  else state.tasks.splice(index, 1, task);
  reportCompleted(task);
}

function reportCompleted(task) {
  const resultKey = `${task.id}:${task.updated_at}:${task.status}`;
  if (!isPending(task.status) && !reportedTasks.has(resultKey)) {
    reportedTasks.add(resultKey);
    emit('completed', task);
  }
}

async function loadTasks(token = generation, showLoading = true) {
  if (!active(token)) return;
  const listToken = ++listGeneration;
  stopPolling();
  if (showLoading) state.loading = true;
  try {
    const data = await fetchAccountImportTasks(props.mode, { signal: readController.signal });
    if (!active(token) || listToken !== listGeneration) return;
    const previousTasks = new Map(state.tasks.map(task => [task.id, task.status]));
    state.tasks = data.items || [];
    state.tasks.forEach(task => {
      if (isPending(previousTasks.get(task.id)) && !isPending(task.status)) reportCompleted(task);
    });
    if (!state.tasks.some(item => item.id === state.selectedId)) state.selectedId = state.tasks[0]?.id || null;
    if (state.selectedId) await loadTask(state.selectedId, token, false);
    else state.task = null;
  } catch (error) {
    if (active(token) && listToken === listGeneration) state.message = error.message;
  } finally {
    if (active(token) && listToken === listGeneration) {
      state.loading = false;
      schedulePolling(token);
    }
  }
}

async function loadTask(id, token = generation, showLoading = true) {
  if (!active(token)) return;
  const detailToken = ++detailGeneration;
  state.selectedId = id;
  if (showLoading) state.detailLoading = true;
  try {
    const data = await fetchAccountImportDetail(id, { signal: readController.signal });
    if (!active(token) || detailToken !== detailGeneration) return;
    state.task = data.task;
    recordTask(data.task);
  } catch (error) {
    if (active(token) && detailToken === detailGeneration) state.message = error.message;
  } finally {
    if (active(token) && detailToken === detailGeneration) state.detailLoading = false;
  }
}

async function loadStudentPreview() {
  if (state.previewLoading || state.submitting || !props.sessionKey) return;
  const token = generation;
  state.previewLoading = true;
  state.preview = null;
  state.password = '';
  state.message = '';
  requestKey = '';
  try {
    const data = await previewStudentAccounts({ signal: readController.signal });
    if (!active(token)) return;
    state.preview = data;
    requestKey = createRequestKey();
  } catch (error) {
    if (active(token)) state.message = error.message;
  } finally {
    if (active(token)) state.previewLoading = false;
  }
}

async function handleFileChange(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || state.previewLoading || state.submitting || !props.sessionKey) return;
  state.message = '';
  state.preview = null;
  state.fileName = file.name;
  requestKey = '';
  if (!/\.(xls|xlsx)$/i.test(file.name)) {
    state.message = '请选择 .xls 或 .xlsx 文件';
    return;
  }
  const token = generation;
  state.previewLoading = true;
  try {
    const data = await previewTeacherAccounts(file, { signal: readController.signal });
    if (!active(token)) return;
    state.preview = data;
    requestKey = createRequestKey();
  } catch (error) {
    if (active(token)) state.message = error.message;
  } finally {
    if (active(token)) state.previewLoading = false;
  }
}

function createRequestKey() {
  if (window.crypto.randomUUID) return window.crypto.randomUUID();
  const bytes = window.crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

async function startImport() {
  if (!canStart.value || !active(generation)) return;
  const token = generation;
  state.submitting = true;
  stopPolling();
  ++listGeneration;
  ++detailGeneration;
  state.loading = false;
  state.detailLoading = false;
  state.message = '';
  try {
    const pending = teacherMode.value
      ? startTeacherAccounts({ file_id: state.preview.file_id, preview_token: state.preview.preview_token, request_key: requestKey })
      : startStudentAccounts({ password: state.password, request_key: requestKey });
    state.password = '';
    const data = await pending;
    if (!active(token)) return;
    state.preview = null;
    state.fileName = '';
    requestKey = '';
    state.selectedId = data.task.id;
    state.task = data.task;
    recordTask(data.task);
    state.message = '导入任务已排队，可关闭弹窗，稍后重新打开查看进度。';
  } catch (error) {
    if (active(token)) state.message = error.message;
  } finally {
    if (active(token)) {
      state.submitting = false;
      schedulePolling(token);
    }
  }
}

async function retryTask(task) {
  if (task.retryable !== true || state.submitting || !active(generation)) return;
  const token = generation;
  state.submitting = true;
  stopPolling();
  ++listGeneration;
  ++detailGeneration;
  state.loading = false;
  state.detailLoading = false;
  state.message = '';
  try {
    const data = await retryAccountImport(task.id);
    if (!active(token)) return;
    state.selectedId = data.task.id;
    state.task = data.task;
    recordTask(data.task);
    state.message = '任务已重新排队';
  } catch (error) {
    if (active(token)) state.message = error.message;
  } finally {
    if (active(token)) {
      state.submitting = false;
      schedulePolling(token);
    }
  }
}

watch(() => [props.visible, props.mode, props.sessionKey], ([visible, , sessionKey], previous) => {
  const token = ++generation;
  ++listGeneration;
  ++detailGeneration;
  stopPolling();
  readController?.abort();
  readController = null;
  requestKey = '';
  state.password = '';
  state.preview = null;
  state.fileName = '';
  state.message = '';
  state.loading = false;
  state.previewLoading = false;
  state.submitting = false;
  state.detailLoading = false;
  state.tasks = [];
  state.selectedId = null;
  state.task = null;
  if (previous?.[2] !== sessionKey) reportedTasks.clear();
  if (!visible || !sessionKey) return;
  readController = new AbortController();
  loadTasks(token);
  if (!teacherMode.value) loadStudentPreview();
}, { immediate: true });

onBeforeUnmount(() => {
  disposed = true;
  ++generation;
  stopPolling();
  readController?.abort();
  state.password = '';
});
</script>

<template>
  <OperationDialog :visible="visible" :title="teacherMode ? '导入教师账号' : '学生导入用户'" dialog-class="account-import-dialog" @close="emit('close')">
    <div class="account-import-body">
      <section class="account-import-section">
        <template v-if="teacherMode">
          <p>使用 39 列标准表头的教师名单，支持 .xls、.xlsx。工号作为登录名，新账号初始密码为工号后加“666”，已有账号密码保持不变。</p>
          <small>学院名称会忽略空格和不可见字符，并匹配学院全称、已维护简称或去掉“学院”后缀的名称；简称有歧义时需先维护学院档案。</small>
          <div class="account-import-actions">
            <a :href="teacherAccountTemplateUrl()" class="account-import-template" target="_blank" rel="noopener noreferrer"><Download :size="15" />下载教师模板</a>
            <el-button :icon="Upload" :loading="state.previewLoading" :disabled="state.submitting" @click="fileInput?.click()">选择文件并预览</el-button>
            <span>{{ state.fileName }}</span>
            <input ref="fileInput" type="file" accept=".xls,.xlsx" hidden @change="handleFileChange">
          </div>
          <template v-if="state.preview">
            <p>共 {{ state.preview.total_rows }} 行，可导入 {{ state.preview.valid_rows }} 行，问题 {{ state.preview.invalid_rows }} 行，提示 {{ state.preview.warning_rows || 0 }} 行。预览最多显示 50 行。</p>
            <el-alert v-if="Number(state.preview.invalid_rows) > 0" title="存在错误行，确认导入时将跳过错误行，只处理可导入数据；邮箱格式提示不影响导入。" type="warning" :closable="false" />
            <el-table :data="state.preview.items || []" max-height="220" stripe size="small">
              <el-table-column prop="row_number" label="行号" width="65" />
              <el-table-column prop="values.teacher_num" label="工号" min-width="110" />
              <el-table-column prop="values.teacher_name" label="姓名" min-width="100" />
              <el-table-column prop="values.dep_name" label="部门" min-width="150" />
              <el-table-column label="校验结果" min-width="260"><template #default="{ row }"><span v-if="row.errors?.length">{{ row.errors.join('；') }}</span><span v-else-if="row.warnings?.length" class="account-import-warning">{{ row.warnings.join('；') }}</span><span v-else>可导入</span></template></el-table-column>
            </el-table>
            <ul v-if="state.preview.errors?.length" class="account-import-errors"><li v-for="(error, index) in state.preview.errors" :key="index">{{ error.row_number ? `第 ${error.row_number} 行：` : '' }}{{ error.message }}</li></ul>
          </template>
        </template>
        <template v-else>
          <p>导入范围为全部已发布、有效的学生档案，不按学年、学期筛选。已有账号密码保持不变。</p>
          <div class="account-import-actions"><el-button :icon="RefreshCw" :loading="state.previewLoading" :disabled="state.submitting" @click="loadStudentPreview">刷新导入数量</el-button></div>
          <p v-if="state.preview">学生档案 {{ state.preview.total_rows }} 人，可导入 {{ state.preview.eligible_rows }} 人（含已关联账号 {{ state.preview.existing_rows }} 人），不符合导入条件 {{ state.preview.unmapped_rows }} 人。</p>
          <label class="account-import-password"><span>统一初始密码</span><el-input v-model="state.password" type="password" autocomplete="new-password" show-password :disabled="state.submitting" placeholder="至少 6 个字符，最多 72 字节" /></label>
          <small>仅用于本次新建账号，至少 6 个字符，按 UTF-8 编码不超过 72 字节。</small>
        </template>
        <div class="account-import-actions account-import-confirm">
          <el-button v-if="teacherMode && Number(state.preview?.invalid_rows) > 0" type="warning" :disabled="!canStart" :loading="state.submitting" @click="startImport">跳过错误行并导入</el-button>
          <el-button v-else type="primary" :disabled="!canStart" :loading="state.submitting" @click="startImport">确认导入并排队</el-button>
        </div>
      </section>

      <el-alert v-if="state.message" :title="state.message" type="info" :closable="false" />

      <section class="account-import-section">
        <div class="account-import-actions"><strong>最近导入任务</strong><el-button link :icon="RefreshCw" :loading="state.loading" :disabled="state.submitting" @click="loadTasks()">刷新</el-button><small>关闭弹窗不会取消任务。</small></div>
        <el-table :data="state.tasks" max-height="200" stripe size="small" v-loading="state.loading" empty-text="暂无导入任务">
          <el-table-column prop="id" label="任务" width="75" />
          <el-table-column prop="created_at" label="创建时间" min-width="160" />
          <el-table-column label="状态" width="105"><template #default="{ row }"><el-tag :type="statusType(row.status)">{{ statusText(row.status) }}</el-tag></template></el-table-column>
          <el-table-column label="进度" min-width="125"><template #default="{ row }">{{ row.processed_rows || 0 }} / {{ row.total_rows || 0 }}</template></el-table-column>
          <el-table-column label="操作" width="170"><template #default="{ row }"><el-button link type="primary" :disabled="state.submitting" @click="loadTask(row.id)">详情</el-button><el-button v-if="row.retryable === true" link type="warning" :disabled="state.submitting" @click="retryTask(row)">重新排队</el-button></template></el-table-column>
        </el-table>
        <div v-if="state.task" v-loading="state.detailLoading" class="account-import-detail">
          <strong>任务 #{{ state.task.id }} · {{ statusText(state.task.status) }}</strong>
          <el-progress :percentage="taskProgress" :status="state.task.status === 'completed' ? 'success' : isFailedStatus(state.task.status) ? 'exception' : undefined" />
          <p>新增 {{ state.task.created_count || 0 }}，更新 {{ state.task.updated_count || 0 }}，关联 {{ state.task.linked_count || 0 }}，跳过 {{ state.task.skipped_count || 0 }}，失败 {{ state.task.failed_count || 0 }}。</p>
          <p v-if="state.task.status === 'completed_with_errors'" class="account-import-error">请修正失败记录后重新发起导入，已有账号密码保持不变。</p>
          <p v-if="state.task.error_message" class="account-import-error">{{ state.task.error_message }}</p>
          <ul v-if="state.task.errors?.length" class="account-import-errors"><li v-for="(error, index) in state.task.errors" :key="index">{{ error.row_number ? `第 ${error.row_number} 行：` : error.source_id ? `档案 #${error.source_id}：` : '' }}{{ error.message }}</li></ul>
        </div>
      </section>
    </div>
    <template #footer><el-button @click="emit('close')">关闭</el-button></template>
  </OperationDialog>
</template>

<style scoped>
:global(.operation-dialog.account-import-dialog) { width: min(940px, calc(100vw - 36px)); }
.account-import-body { display: grid; gap: 16px; min-height: 0; padding: 18px; overflow: auto; }
.account-import-section { display: grid; gap: 12px; min-width: 0; }
.account-import-section + .account-import-section { padding-top: 16px; border-top: 1px solid var(--el-border-color); }
.account-import-section p { margin: 0; font-size: 13px; line-height: 1.7; }
.account-import-section small { color: var(--el-text-color-secondary); }
.account-import-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.account-import-actions > span { overflow-wrap: anywhere; font-size: 13px; }
.account-import-template { display: inline-flex; align-items: center; gap: 5px; color: var(--el-color-primary); font-size: 13px; text-decoration: none; }
.account-import-password { display: grid; grid-template-columns: 110px minmax(200px, 360px); align-items: center; gap: 12px; font-size: 13px; }
.account-import-confirm { justify-content: flex-end; }
.account-import-detail { display: grid; gap: 10px; padding: 12px; background: var(--el-fill-color-light); border-radius: 6px; }
.account-import-errors { max-height: 140px; margin: 0; padding-left: 20px; overflow: auto; color: var(--el-color-danger); font-size: 12px; line-height: 1.8; }
.account-import-error { color: var(--el-color-danger); }
.account-import-warning { color: var(--el-color-warning); }
@media (max-width: 600px) { .account-import-password { grid-template-columns: 1fr; } }
</style>
