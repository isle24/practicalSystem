<script setup>
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import { ElCheckbox, ElMessageBox } from 'element-plus';
import { Check, Download, RefreshCw, Upload } from '@lucide/vue';
import EduFieldChanges from './EduFieldChanges.vue';
import OperationDialog from './OperationDialog.vue';
import {
  cancelEduBatch,
  classifyEduCandidate,
  confirmEduCandidates,
  fetchEduBatchDetail,
  fetchEduBatches,
  fetchEduCandidates,
  fetchEduChanges,
  fetchEduIssues,
  fetchEduSource,
  publishEduBatch,
  resolveEduIssue,
  uploadEduData,
} from '../api/system';

const props = defineProps({
  canImport: { type: Boolean, default: false },
  canConfirm: { type: Boolean, default: false },
  canIssue: { type: Boolean, default: false },
});

const state = reactive({
  tab: 'batches',
  loading: false,
  message: '',
  uploadType: '',
  uploadLoading: false,
  academic_year: '2026-2027',
  semester: '1',
  batches: [],
  batchPagination: { page: 1, page_size: 20, total: 0 },
  batchStatus: '',
  selectedBatch: null,
  detailVisible: false,
  detailLoading: false,
  detail: { changes: { items: [], pagination: {} }, issues: { items: [], pagination: {} } },
  sourceType: 'student',
  sourceItems: [],
  sourcePagination: { page: 1, page_size: 20, total: 0 },
  sourceLoading: false,
  candidates: [],
  candidatePagination: { page: 1, page_size: 20, total: 0 },
  candidateLoading: false,
  candidateSubmitting: false,
  selectedCandidateIds: [],
  issues: [],
  issuePagination: { page: 1, page_size: 20, total: 0 },
  issueBatchId: '',
});

let inputElement = null;
let refreshTimer = null;

const templateDefinitions = [
  { type: 'student', label: '在校学生模板' },
  { type: 'teaching_plan', label: '开课计划模板' },
  { type: 'course_offering', label: '开课情况模板' },
];

const sourceDefinitions = [
  { type: 'student', label: '学生数据' },
  { type: 'teaching_plan', label: '开课计划' },
  { type: 'course_offering', label: '开课情况' },
];

const processing = computed(() => state.batches.some(item => ['queued', 'parsing', 'validating', 'publishing'].includes(item.status)));
const candidateSelectionReady = computed(() => state.selectedCandidateIds.length > 0);

function statusLabel(status) {
  return {
    queued: '排队中',
    parsing: '解析中',
    validating: '校验中',
    pending_confirm: '待确认',
    publishing: '发布中',
    completed: '已完成',
    partial_failed: '部分失败',
    failed: '失败',
    cancelled: '已取消',
  }[status] || status || '-';
}

function statusType(status) {
  return {
    completed: 'success',
    pending_confirm: 'warning',
    failed: 'danger',
    partial_failed: 'danger',
    cancelled: 'info',
  }[status] || 'info';
}

function typeLabel(type) {
  return templateDefinitions.find(item => item.type === type)?.label || type || '-';
}

function downloadTemplate(type) {
  window.open(`/api/edu-data/template?type=${encodeURIComponent(type)}`, '_blank', 'noopener,noreferrer');
}

function chooseUpload(type) {
  if (!props.canImport || state.uploadLoading) {
    return;
  }
  state.uploadType = type;
  if (!inputElement) {
    inputElement = document.createElement('input');
    inputElement.type = 'file';
    inputElement.accept = '.xls,.xlsx';
    inputElement.addEventListener('change', handleFileChange);
  }
  inputElement.value = '';
  inputElement.click();
}

async function handleFileChange(event) {
  const file = event.target.files?.[0];
  const type = state.uploadType;
  if (!file || !type) {
    return;
  }
  if (file.size > 500 * 1024 * 1024) {
    state.message = '文件不能超过 500MB';
    return;
  }
  state.uploadLoading = true;
  state.message = '';
  try {
    await uploadEduData(type, file, type === 'student' ? {} : { academic_year: state.academic_year, semester: state.semester });
    state.message = `${typeLabel(type)}导入任务已排队`;
    state.tab = 'batches';
    await loadBatches(1);
    ensurePolling();
  } catch (error) {
    state.message = error.message;
  } finally {
    state.uploadLoading = false;
  }
}

async function loadBatches(page = 1) {
  state.loading = true;
  try {
    const data = await fetchEduBatches({ page, page_size: state.batchPagination.page_size, status: state.batchStatus });
    state.batches = data.items || [];
    state.batchPagination = { ...state.batchPagination, ...(data.pagination || {}), page };
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function openBatch(row) {
  state.selectedBatch = row;
  state.issueBatchId = String(row.id);
  state.detailVisible = true;
  state.detailLoading = true;
  try {
    state.detail = await fetchEduBatchDetail(row.id);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.detailLoading = false;
  }
}

async function loadDetailPage(type, page) {
  if (state.detailLoading || !state.selectedBatch) return;
  state.detailLoading = true;
  try {
    const params = { page, page_size: 20 };
    state.detail[type] = type === 'changes'
      ? await fetchEduChanges(state.selectedBatch.id, params)
      : await fetchEduIssues(state.selectedBatch.id, params);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.detailLoading = false;
  }
}

async function publishBatch(row) {
  if (!props.canConfirm || !['pending_confirm', 'partial_failed'].includes(row.status)) {
    return;
  }
  try {
    await ElMessageBox.confirm('确认发布该批次？发布后会更新当前教务源数据和基础档案。', '确认发布', { type: 'warning' });
    await publishEduBatch(row.id);
    state.message = '批次已发布';
    await loadBatches(state.batchPagination.page || 1);
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      state.message = error.message;
    }
  }
}

async function cancelBatch(row) {
  if (!props.canConfirm || !['queued', 'parsing', 'validating', 'pending_confirm'].includes(row.status)) {
    return;
  }
  try {
    await ElMessageBox.confirm('确认取消该批次？暂存数据将被清理。', '确认取消', { type: 'warning' });
    await cancelEduBatch(row.id);
    state.message = '批次已取消';
    await loadBatches(state.batchPagination.page || 1);
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      state.message = error.message;
    }
  }
}

async function loadSource(page = 1) {
  state.sourceLoading = true;
  try {
    const data = await fetchEduSource(state.sourceType, { page, page_size: state.sourcePagination.page_size });
    state.sourceItems = data.items || [];
    state.sourcePagination = { ...state.sourcePagination, ...(data.pagination || {}), page };
  } catch (error) {
    state.message = error.message;
  } finally {
    state.sourceLoading = false;
  }
}

async function loadCandidates(page = 1) {
  state.candidateLoading = true;
  try {
    const data = await fetchEduCandidates({ page, page_size: state.candidatePagination.page_size, candidate_status: 'pending' });
    state.candidates = data.items || [];
    state.candidatePagination = { ...state.candidatePagination, ...(data.pagination || {}), page };
  } catch (error) {
    state.message = error.message;
  } finally {
    state.candidateLoading = false;
  }
}

async function loadIssues(page = 1) {
  if (!state.issueBatchId) {
    state.issues = [];
    return;
  }
  try {
    const data = await fetchEduIssues(state.issueBatchId, { page, page_size: state.issuePagination.page_size });
    state.issues = data.items || [];
    state.issuePagination = { ...state.issuePagination, ...(data.pagination || {}), page };
  } catch (error) {
    state.message = error.message;
  }
}

async function resolveIssue(row) {
  if (!props.canIssue || String(row.resolved) === 'true') {
    return;
  }
  try {
    await ElMessageBox.confirm('确认将该问题标记为已处理？', '处理导入问题', { type: 'warning' });
    await resolveEduIssue(row.id);
    state.message = '导入问题已处理';
    await loadIssues(state.issuePagination.page || 1);
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      state.message = error.message;
    }
  }
}

async function classify(row, type) {
  if (!props.canConfirm || !type) {
    return;
  }
  try {
    await classifyEduCandidate(row.id, type);
    state.message = '候选项已分类';
    await loadCandidates(state.candidatePagination.page || 1);
  } catch (error) {
    state.message = error.message;
  }
}

async function confirmSelectedCandidates() {
  if (!props.canConfirm || !candidateSelectionReady.value || state.candidateSubmitting) {
    return;
  }
  state.candidateSubmitting = true;
  try {
    const ids = [...state.selectedCandidateIds];
    await ElMessageBox.confirm(`将为选中的 ${ids.length} 条课程记录生成业务草稿，不会自动提交审核，确认继续？`, '确认生成业务草稿', { type: 'warning' });
    const result = await confirmEduCandidates(ids);
    state.message = `业务草稿生成完成：${result.created || 0} 条，失败 ${result.failed || 0} 条`;
    state.selectedCandidateIds = [];
    await loadCandidates(1);
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') state.message = error.message;
  } finally {
    state.candidateSubmitting = false;
  }
}

function toggleCandidate(row, checked) {
  const id = Number(row.id);
  if (checked && !state.selectedCandidateIds.includes(id)) {
    state.selectedCandidateIds.push(id);
  } else if (!checked) {
    state.selectedCandidateIds = state.selectedCandidateIds.filter(item => item !== id);
  }
}

function handleTabChange(tab) {
  if (tab === 'batches') loadBatches(1);
  if (tab === 'student' || tab === 'teaching_plan' || tab === 'course_offering') {
    state.sourceType = tab;
    loadSource(1);
  }
  if (tab === 'candidates') loadCandidates(1);
  if (tab === 'issues') loadIssues(1);
}

function ensurePolling() {
  if (refreshTimer || !processing.value) {
    return;
  }
  refreshTimer = window.setInterval(async () => {
    await loadBatches(state.batchPagination.page || 1);
    if (!processing.value) {
      window.clearInterval(refreshTimer);
      refreshTimer = null;
    }
  }, 3000);
}

onMounted(async () => {
  await loadBatches(1);
  ensurePolling();
});

onBeforeUnmount(() => {
  if (refreshTimer) {
    window.clearInterval(refreshTimer);
    refreshTimer = null;
  }
  inputElement?.removeEventListener('change', handleFileChange);
});
</script>

<template>
  <section class="admin-panel edu-data-panel">
    <header class="admin-toolbar edu-data-toolbar">
      <div class="edu-data-toolbar-title">
        <strong>教务数据</strong>
        <small>按标准模板导入，发布前保留差异和问题记录</small>
      </div>
      <label class="edu-period-field">
        <span>学年</span>
        <input v-model.trim="state.academic_year" maxlength="40" placeholder="2026-2027">
      </label>
      <label class="edu-period-field">
        <span>学期</span>
        <input v-model.trim="state.semester" maxlength="20" placeholder="1">
      </label>
      <el-button :icon="RefreshCw" :loading="state.loading" @click="loadBatches(1)">刷新</el-button>
    </header>

    <section class="edu-template-actions">
      <div v-for="item in templateDefinitions" :key="item.type" class="edu-template-item">
        <span>{{ item.label }}</span>
        <el-button link type="primary" :icon="Download" @click="downloadTemplate(item.type)">下载模板</el-button>
        <el-button v-if="props.canImport" link type="success" :icon="Upload" :loading="state.uploadLoading && state.uploadType === item.type" @click="chooseUpload(item.type)">上传</el-button>
      </div>
    </section>

    <el-tabs v-model="state.tab" @tab-change="handleTabChange">
      <el-tab-pane label="导入批次" name="batches" />
      <el-tab-pane v-for="item in sourceDefinitions" :key="item.type" :label="item.label" :name="item.type" />
      <el-tab-pane label="业务候选" name="candidates" />
      <el-tab-pane label="导入问题" name="issues" />
    </el-tabs>

    <el-alert v-if="state.message" :title="state.message" type="warning" :closable="false" show-icon />

    <el-table v-if="state.tab === 'batches'" :data="state.batches" stripe size="small" v-loading="state.loading" class="edu-data-table">
      <el-table-column type="index" label="序号" width="60" />
      <el-table-column prop="import_type" label="类型" width="120">
        <template #default="{ row }">{{ typeLabel(row.import_type) }}</template>
      </el-table-column>
      <el-table-column prop="name" label="文件" min-width="220" show-overflow-tooltip />
      <el-table-column prop="academic_year" label="学年" width="110" />
      <el-table-column prop="semester" label="学期" width="70" />
      <el-table-column label="进度" width="130">
        <template #default="{ row }"><el-progress :percentage="Number(row.progress || 0)" :stroke-width="8" /></template>
      </el-table-column>
      <el-table-column label="状态" width="110"><template #default="{ row }"><el-tag :type="statusType(row.status)">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
      <el-table-column label="数量" width="170"><template #default="{ row }">{{ row.total_rows || 0 }} / 有效 {{ row.valid_rows || 0 }} / 问题 {{ row.invalid_rows || 0 }}</template></el-table-column>
      <el-table-column label="操作" width="210" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openBatch(row)">详情</el-button>
          <el-button v-if="props.canConfirm && ['pending_confirm', 'partial_failed'].includes(row.status)" link type="success" @click="publishBatch(row)">{{ row.status === 'partial_failed' ? '继续发布' : '发布' }}</el-button>
          <el-button v-if="props.canConfirm && ['queued', 'parsing', 'validating', 'pending_confirm'].includes(row.status)" link type="warning" @click="cancelBatch(row)">取消</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-table v-else-if="['student', 'teaching_plan', 'course_offering'].includes(state.tab)" :data="state.sourceItems" stripe size="small" v-loading="state.sourceLoading" class="edu-data-table">
      <el-table-column type="index" label="序号" width="60" />
      <el-table-column prop="student_num" label="学号" width="120" />
      <el-table-column prop="student_name" label="学生" width="110" />
      <el-table-column prop="course_code" label="课程代码" width="130" />
      <el-table-column prop="course_name" label="课程名称" min-width="190" show-overflow-tooltip />
      <el-table-column prop="grade_name" label="年级" width="90" />
      <el-table-column label="学院" min-width="150" show-overflow-tooltip><template #default="{ row }">{{ row.dep_name || row.open_dep_name || '-' }}</template></el-table-column>
      <el-table-column prop="profession_name" label="专业" min-width="160" show-overflow-tooltip />
      <el-table-column prop="source_status" label="来源状态" width="100" />
      <el-table-column prop="mapping_status" label="映射状态" width="100" />
    </el-table>

    <section v-else-if="state.tab === 'candidates'" class="edu-candidate-section">
      <div class="edu-candidate-toolbar">
        <span>待确认业务候选</span>
        <el-button type="primary" :icon="Check" :loading="state.candidateSubmitting" :disabled="!props.canConfirm || !candidateSelectionReady" @click="confirmSelectedCandidates">生成业务草稿</el-button>
      </div>
      <el-table :data="state.candidates" stripe size="small" v-loading="state.candidateLoading" class="edu-data-table">
        <el-table-column label="选择" width="60">
          <template #default="{ row }"><el-checkbox :aria-label="`选择${row.name || '课程'}`" :disabled="!props.canConfirm || state.candidateSubmitting" :model-value="state.selectedCandidateIds.includes(Number(row.id))" @change="value => toggleCandidate(row, value)" /></template>
        </el-table-column>
        <el-table-column prop="name" label="课程" min-width="220" show-overflow-tooltip />
        <el-table-column prop="business_type" label="分类" width="130">
          <template #default="{ row }">
            <el-select :model-value="row.business_type" size="small" :disabled="!props.canConfirm || state.candidateSubmitting" @change="value => classify(row, value)">
              <el-option label="待分类" value="pending" /><el-option label="实习" value="internship" /><el-option label="实验" value="lab" /><el-option label="实训" value="training" /><el-option label="社会实践" value="social_practice" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column prop="classification_reason" label="分类依据" min-width="210" show-overflow-tooltip />
        <el-table-column prop="mapping_status" label="映射" width="100" />
      </el-table>
    </section>

    <el-table v-else :data="state.issues" stripe size="small" class="edu-data-table">
      <el-table-column prop="batch_id" label="批次" width="80" />
      <el-table-column prop="row_number" label="行号" width="80" />
      <el-table-column prop="field_name" label="字段" width="140" />
      <el-table-column prop="issue_type" label="类型" width="120" />
      <el-table-column prop="message" label="问题" min-width="260" />
      <el-table-column prop="resolved" label="处理状态" width="100" />
      <el-table-column label="操作" width="100" fixed="right">
        <template #default="{ row }">
          <el-button v-if="props.canIssue && String(row.resolved) !== 'true'" link type="primary" @click="resolveIssue(row)">标记处理</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div v-if="state.tab === 'batches'" class="file-pagination">
      <span>共 {{ state.batchPagination.total || 0 }} 条</span>
      <el-pagination size="small" layout="prev, pager, next" :current-page="state.batchPagination.page" :page-size="state.batchPagination.page_size" :total="state.batchPagination.total" @current-change="loadBatches" />
    </div>

    <div v-else-if="['student', 'teaching_plan', 'course_offering'].includes(state.tab)" class="file-pagination">
      <span>共 {{ state.sourcePagination.total || 0 }} 条</span>
      <el-pagination size="small" layout="prev, pager, next" :current-page="state.sourcePagination.page" :page-size="state.sourcePagination.page_size" :total="state.sourcePagination.total" @current-change="loadSource" />
    </div>

    <div v-else-if="state.tab === 'candidates'" class="file-pagination">
      <span>共 {{ state.candidatePagination.total || 0 }} 条</span>
      <el-pagination size="small" layout="prev, pager, next" :current-page="state.candidatePagination.page" :page-size="state.candidatePagination.page_size" :total="state.candidatePagination.total" @current-change="loadCandidates" />
    </div>

    <div v-else-if="state.tab === 'issues'" class="file-pagination">
      <span>共 {{ state.issuePagination.total || 0 }} 条</span>
      <el-pagination size="small" layout="prev, pager, next" :current-page="state.issuePagination.page" :page-size="state.issuePagination.page_size" :total="state.issuePagination.total" @current-change="loadIssues" />
    </div>

    <OperationDialog :visible="state.detailVisible" title="导入批次详情" dialog-class="edu-batch-detail-dialog" @close="state.detailVisible = false">
      <div class="edu-batch-detail-body">
      <el-skeleton v-if="state.detailLoading" :rows="8" animated />
      <template v-else>
        <p class="edu-detail-title">{{ state.selectedBatch?.name || '-' }} · {{ statusLabel(state.selectedBatch?.status) }}</p>
        <el-tabs>
          <el-tab-pane label="差异">
            <el-table :data="state.detail.changes?.items || []" size="small" max-height="54vh">
              <el-table-column label="类型" width="80"><template #default="{ row }">{{ ({ created: '新增', updated: '更新', unchanged: '未变化', missing: '缺失', deleted: '删除' })[row.change_type] || row.change_type }}</template></el-table-column>
              <el-table-column label="数据记录" min-width="190" show-overflow-tooltip><template #default="{ row }">{{ row.after_json?.course_name || row.after_json?.student_name || row.before_json?.course_name || row.source_key }}</template></el-table-column>
              <el-table-column label="字段差异" min-width="380"><template #default="{ row }"><details class="edu-change-detail"><summary>查看字段变更</summary><EduFieldChanges :value="row.diff_json" /></details></template></el-table-column>
            </el-table>
            <el-pagination layout="total, prev, pager, next" :current-page="state.detail.changes?.pagination?.page || 1" :page-size="20" :total="state.detail.changes?.pagination?.total || 0" @current-change="loadDetailPage('changes', $event)" />
          </el-tab-pane>
          <el-tab-pane label="问题">
            <el-table :data="state.detail.issues?.items || []" size="small"><el-table-column prop="row_number" label="行号" width="80" /><el-table-column prop="field_name" label="字段" width="130" /><el-table-column prop="message" label="问题" min-width="320" /></el-table>
            <el-pagination layout="total, prev, pager, next" :current-page="state.detail.issues?.pagination?.page || 1" :page-size="20" :total="state.detail.issues?.pagination?.total || 0" @current-change="loadDetailPage('issues', $event)" />
          </el-tab-pane>
        </el-tabs>
      </template>
      </div>
    </OperationDialog>
  </section>
</template>

<style scoped>
.edu-data-panel.admin-panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-height: 0;
  overflow: auto;
}
.edu-data-panel > * { flex-shrink: 0; }
.edu-batch-detail-body { padding: 20px 24px; min-height: 0; overflow: auto; }
.edu-change-detail summary { color: var(--el-color-primary); cursor: pointer; padding: 6px 0; }
:global(.edu-batch-detail-dialog) { width: min(1100px, 94vw); }

.edu-data-toolbar {
  display: flex;
  align-items: end;
  gap: 12px;
  flex-wrap: wrap;
}

.edu-data-toolbar-title {
  display: grid;
  gap: 3px;
  margin-right: auto;
}

.edu-data-toolbar-title small,
.edu-template-item span,
.edu-detail-title {
  color: var(--text-secondary, #667085);
}

.edu-period-field {
  display: grid;
  gap: 4px;
}

.edu-period-field span {
  font-size: 12px;
  color: var(--text-secondary, #667085);
}

.edu-period-field input {
  width: 118px;
}

.edu-template-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
  gap: 12px;
}

.edu-template-item {
  display: flex;
  align-items: center;
  min-height: 54px;
  gap: 10px;
  flex-wrap: wrap;
  padding: 10px 12px;
  border: 1px solid var(--border-color, #d9e0ea);
  border-radius: 8px;
  background: var(--panel-background, #fff);
}

.edu-template-item span {
  margin-right: auto;
}

.edu-data-panel .edu-data-table {
  flex: 1 0 260px;
  min-height: 260px;
}

.edu-candidate-section {
  display: grid;
  gap: 12px;
}

.edu-candidate-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.edu-detail-title {
  margin: 0 0 12px;
}

@media (max-width: 980px) {
  .edu-template-actions {
    grid-template-columns: 1fr;
  }
}
</style>
