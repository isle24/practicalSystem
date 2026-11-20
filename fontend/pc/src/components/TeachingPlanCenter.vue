<script setup>
import { ref, watch } from 'vue';
import { RefreshCw, Search, Upload } from '@lucide/vue';
import TeachingPlanCenterView from '../../../shared/components/TeachingPlanCenterView.vue';
import FormFieldLabel from '../../../shared/components/FormFieldLabel.vue';
import { teachingPlanFieldLabel } from '../../../shared/useTeachingPlanCenter';
import OperationDialog from './OperationDialog.vue';
import ListPagination from './ListPagination.vue';
import { request } from '../api/client';

const props = defineProps({ canImport: Boolean, canAssign: Boolean, canChange: Boolean, sessionKey: { type: String, default: '' } });
const emit = defineEmits(['open-target']);
const importVisible = ref(false);
watch(() => props.sessionKey, () => { importVisible.value = false; });
function showImport(view) {
  if (view.state.busy) return;
  view.closeWorkspace(); view.state.error = ''; view.state.receipt = ''; importVisible.value = true;
}
function closeImport(view) { if (!view.state.busy) importVisible.value = false; }
function selectFile(event, view) {
  view.chooseFile(event); view.state.error = '';
  const file = view.state.upload.file;
  if (file && (!/\.xlsx?$/i.test(file.name) || !file.size || file.size > 500 * 1024 * 1024)) {
    view.state.upload.file = null; view.state.error = '请选择 1B 至 500MB 的 xls 或 xlsx 文件。';
  }
}
async function uploadFile(view) {
  const session = props.sessionKey;
  const uploaded = await view.upload();
  if (session !== props.sessionKey || !uploaded) return;
  importVisible.value = false; view.state.tab = 'batches';
}
function rowIssue(row) {
  const issues = (row.mapping_issues || []).map(item => typeof item === 'string' ? item : item.message || item.label || item.code);
  if (row.source_status && row.source_status !== 'active') issues.push(row.source_status === 'missing' ? '最新批次缺失' : '来源已停用');
  if (row.stage === 'conflict') issues.push('存在旧业务绑定');
  if (row.missing_fields?.length) issues.push(`待补齐：${row.missing_fields.map(item => typeof item === 'string' ? teachingPlanFieldLabel(item) : item.label || teachingPlanFieldLabel(item.field)).join('、')}`);
  if (row.pending_change) issues.push('来源变更待审核');
  return issues.filter(Boolean).join('；') || '—';
}
function canAssignRow(row) {
  return props.canAssign && !row.intake_id && !row.target_id && row.stage === 'unassigned'
    && row.source_status === 'active' && row.ownership_status === 'matched' && !row.mapping_issues?.length;
}
function confirmationText(view) {
  const { state, batch } = view;
  return ({
    assign: `确认生成${view.typeLabel(state.classification)}计划？生成后类型锁定，不能再次分配。`,
    complete: '确认已在目标计划保存全部必需内容？完成首次导入将重新校验当前来源、绑定及审批历史。',
    'apply-change': '确认将已展示的来源差异应用到可编辑计划并提交原审核流程？原计划中未涉及的业务设置保留。',
    'retain-approved': `确认保留计划 #${state.detail?.target_id} 当前已经人工审核通过的内容，并关联本次审核来源版本？不修改业务计划，不执行首次免审。保留原因：${state.retainReason.trim()}`,
    import: `确认导入批次 #${batch.id} 的原始教学计划？${batch.import_semantics_label || '导入方式尚未读取，请先刷新批次'}。请先核对校验问题、前后值及完整源字段。`,
    'cancel-import': `确认取消批次 #${batch.id}？停止剩余待导入数据，已经发布的原始数据保留。`,
  })[state.confirmation] || '';
}
function confirmationDisabled(view) {
  const action = view.state.confirmation;
  return view.state.busy || view.state.detailLoading
    || (action === 'assign' && !view.assignmentReady)
    || (action === 'complete' && (!view.canComplete || (!view.state.detail.target_id && !view.supplementReady)))
    || (action === 'apply-change' && !view.canApply)
    || (action === 'retain-approved' && (!view.canRetain || !view.retainReasonReady))
    || (action === 'import' && !view.batch.can_confirm_import)
    || (action === 'cancel-import' && !view.batch.can_cancel_import);
}
function executeConfirmation(view) {
  if (confirmationDisabled(view)) return;
  if (['import', 'cancel-import'].includes(view.state.confirmation)) view.confirmImport(view.state.confirmation === 'cancel-import');
  else view.mutate(view.state.confirmation);
}
</script>

<template>
  <TeachingPlanCenterView class="teaching-center-pc" :request="request" :can-import="canImport" :can-assign="canAssign" :can-change="canChange" :session-key="sessionKey" :workspace-component="OperationDialog" @open-target="emit('open-target', $event)">
    <template #header-actions="view">
      <div class="pc-center-actions">
        <el-button :icon="RefreshCw" :disabled="view.state.busy" :loading="view.state.loading" @click="view.state.tab === 'batches' ? view.loadBatches(view.state.batchPagination.page) : view.load(view.state.pagination.page)">刷新</el-button>
        <el-button v-if="canImport" type="primary" :icon="Upload" :disabled="view.state.busy" @click="showImport(view)">导入 Excel</el-button>
      </div>
    </template>
    <template #navigation="view">
      <el-tabs :model-value="view.state.tab" class="pc-center-tabs" @tab-change="view.switchTab">
        <el-tab-pane label="原始计划" name="records" :disabled="view.state.busy || view.state.loading" />
        <el-tab-pane v-if="canImport" label="导入批次" name="batches" :disabled="view.state.busy || view.state.loading" />
      </el-tabs>
    </template>
    <template #records="view">
      <section class="pc-center-list">
        <form class="pc-center-filters" @submit.prevent="view.load(1)">
          <label class="keyword"><FormFieldLabel :required="false">关键词</FormFieldLabel><el-input v-model.trim="view.state.filters.keyword" clearable placeholder="课程代码、课程名称、专业" /></label>
          <label><FormFieldLabel :required="false">学年</FormFieldLabel><el-select v-model="view.state.filters.academic_year" clearable placeholder="全部学年"><el-option v-for="year in view.years" :key="year" :value="year" :label="year" /></el-select></label>
          <label><FormFieldLabel :required="false">学期</FormFieldLabel><el-select v-model="view.state.filters.semester" clearable placeholder="全部学期"><el-option value="1" label="第一学期" /><el-option value="2" label="第二学期" /></el-select></label>
          <label><FormFieldLabel :required="false">当前阶段</FormFieldLabel><el-select v-model="view.state.filters.stage" clearable placeholder="全部阶段"><el-option v-for="stage in view.stageOptions" :key="stage" :value="stage" :label="view.teachingPlanStage(stage)" /></el-select></label>
          <label><FormFieldLabel :required="false">分类</FormFieldLabel><el-select v-model="view.state.filters.classification" clearable placeholder="全部分类"><el-option v-for="item in view.types" :key="item.value" :value="item.value" :label="item.label" /></el-select></label>
          <label v-if="view.departments.length > 1"><FormFieldLabel :required="false">归属学院</FormFieldLabel><el-select v-model="view.state.filters.owner_dep_id" clearable filterable placeholder="权限范围内全部学院"><el-option v-for="item in view.departments" :key="item.dep_id || item.id" :value="item.dep_id || item.id" :label="item.dep_name || item.name" /></el-select></label>
          <el-button native-type="submit" type="primary" :icon="Search" :disabled="view.state.busy" :loading="view.state.loading">查询</el-button>
        </form>
        <div class="pc-center-table">
          <el-table :data="view.state.rows" row-key="id" size="small" height="100%" v-loading="view.state.loading" width-storage-key="teaching-plan-center:records" empty-text="当前筛选条件下没有原始计划。可调整条件查询；学校管理员可从右上角导入 Excel。">
            <el-table-column label="课程名称" min-width="200" show-overflow-tooltip><template #default="{ row }">{{ view.sourceName(row) }}</template></el-table-column>
            <el-table-column label="课程代码" width="125" show-overflow-tooltip><template #default="{ row }">{{ row.source?.course_code || row.raw_data?.['课程代码'] || '—' }}</template></el-table-column>
            <el-table-column label="年级 / 专业" min-width="185" show-overflow-tooltip><template #default="{ row }">{{ view.sourceScope(row) || '—' }}</template></el-table-column>
            <el-table-column prop="owner_dep_name" label="归属学院" min-width="145" show-overflow-tooltip />
            <el-table-column label="分类" width="95"><template #default="{ row }">{{ view.typeLabel(row.classification) }}</template></el-table-column>
            <el-table-column label="当前阶段" min-width="150" show-overflow-tooltip><template #default="{ row }">{{ view.teachingPlanProgress(row) }}</template></el-table-column>
            <el-table-column label="阻塞原因 / 待办" min-width="230" show-overflow-tooltip><template #default="{ row }">{{ rowIssue(row) }}</template></el-table-column>
            <el-table-column label="操作" width="130" fixed="right"><template #default="{ row }"><el-button link type="primary" :disabled="view.state.busy" @click="view.openDetail(row)">查看</el-button><el-button v-if="canAssignRow(row)" link type="primary" :disabled="view.state.busy" @click="view.openDetail(row)">分配</el-button></template></el-table-column>
          </el-table>
        </div>
        <ListPagination :current-page="view.state.pagination.page" :page-size="view.state.pagination.page_size" :total="Number(view.state.pagination.total)" :disabled="view.state.loading || view.state.busy" layout="total, prev, pager, next" @current-change="view.load" />
      </section>
    </template>
    <template #batches="view">
      <section class="pc-center-list">
        <p class="pc-center-help">上传后先校验与预览，再确认导入原始计划；业务类型由管理员逐条分配。</p>
        <div class="pc-center-table">
          <el-table :data="view.state.batches" row-key="id" height="100%" size="small" v-loading="view.state.loading" width-storage-key="teaching-plan-center:batches" empty-text="尚无导入批次。点击右上角“导入 Excel”上传教学计划。">
            <el-table-column prop="id" label="批次" width="90" />
            <el-table-column label="文件名称" min-width="240" show-overflow-tooltip><template #default="{ row }">{{ row.original_name || row.file_name || `批次 ${row.id}` }}</template></el-table-column>
            <el-table-column prop="academic_year" label="学年" width="125" />
            <el-table-column label="学期" width="100"><template #default="{ row }">{{ String(row.semester) === '1' ? '第一学期' : String(row.semester) === '2' ? '第二学期' : row.semester }}</template></el-table-column>
            <el-table-column label="状态" min-width="130"><template #default="{ row }">{{ view.batchLabel(row.status) }}</template></el-table-column>
            <el-table-column prop="created_at" label="上传时间" min-width="170" />
            <el-table-column label="操作" width="145" fixed="right"><template #default="{ row }"><el-button link type="primary" :disabled="view.state.busy" @click="view.openBatch(row)">查看校验与预览</el-button></template></el-table-column>
          </el-table>
        </div>
        <ListPagination :current-page="view.state.batchPagination.page" :page-size="view.state.batchPagination.page_size" :total="Number(view.state.batchPagination.total)" :disabled="view.state.loading || view.state.busy" layout="total, prev, pager, next" @current-change="view.loadBatches" />
      </section>
    </template>
    <template #import-dialog="view">
      <OperationDialog :visible="importVisible" title="导入教学计划 Excel" dialog-class="teaching-center-import-dialog" :busy="view.state.busy" @close="closeImport(view)">
        <form class="pc-center-import-body" @submit.prevent="uploadFile(view)">
          <el-alert v-if="view.state.error" type="error" :closable="false" :title="view.state.error" />
          <p class="pc-center-help">使用教务开课计划完整 45 列模板。上传后先校验、预览，再确认导入；本文件未出现的已有来源保留，不会自动分配业务类型。</p>
          <div class="pc-center-import-fields">
            <label><FormFieldLabel required help="与文件中的学年一致。">学年</FormFieldLabel><el-select v-model="view.state.upload.academic_year" :disabled="view.state.busy" placeholder="请选择学年"><el-option v-for="year in view.years" :key="year" :value="year" :label="year" /></el-select></label>
            <label><FormFieldLabel required help="与文件中的学期一致。">学期</FormFieldLabel><el-select v-model="view.state.upload.semester" :disabled="view.state.busy" placeholder="请选择学期"><el-option value="1" label="第一学期" /><el-option value="2" label="第二学期" /></el-select></label>
            <label class="wide"><FormFieldLabel required help="xls / xlsx，文件大小 1B 至 500MB；上传后还会校验列结构与数据。">教学计划文件</FormFieldLabel><input type="file" accept=".xls,.xlsx" :disabled="view.state.busy" @change="event => selectFile(event, view)"><span v-if="view.state.upload.file" class="pc-center-help">已选择：{{ view.state.upload.file.name }}（{{ (view.state.upload.file.size / 1024 / 1024).toFixed(2) }} MB）</span></label>
          </div>
        </form>
        <template #footer><el-button :disabled="view.state.busy" @click="closeImport(view)">取消</el-button><el-button type="primary" :loading="view.state.busy" :disabled="view.state.busy || !view.state.upload.file || !view.state.upload.academic_year || !view.state.upload.semester" @click="uploadFile(view)">上传并校验</el-button></template>
      </OperationDialog>
    </template>
    <template #workspace-footer="view">
      <div class="pc-center-footer">
        <template v-if="view.state.confirmation">
          <p class="pc-center-confirm" role="alert">{{ confirmationText(view) }}</p>
          <div class="pc-center-actions"><el-button :disabled="view.state.busy" @click="view.state.confirmation = ''">返回核对</el-button><el-button :type="view.state.confirmation === 'cancel-import' ? 'danger' : 'primary'" :disabled="confirmationDisabled(view)" :loading="view.state.busy" @click="executeConfirmation(view)">{{ view.state.confirmation === 'cancel-import' ? '确认取消批次' : view.state.confirmation === 'import' ? '确认导入' : '确认执行' }}</el-button></div>
        </template>
        <div v-else class="pc-center-actions">
          <el-button :disabled="view.state.busy" @click="view.closeWorkspace">关闭</el-button>
          <template v-if="view.state.detail">
            <el-button :disabled="view.state.busy || view.state.detailLoading" @click="view.openDetail(view.state.detail)">刷新状态</el-button>
            <el-button v-if="view.canAssign" type="primary" :disabled="view.state.busy || !view.assignmentReady" @click="view.state.confirmation = 'assign'">确认分类并生成</el-button>
            <el-button v-if="view.canComplete" type="primary" :disabled="view.state.busy || (!view.state.detail.target_id && !view.supplementReady)" @click="view.state.confirmation = 'complete'">完成首次导入</el-button>
            <el-button v-if="view.canApply" type="primary" :disabled="view.state.busy" @click="view.state.confirmation = 'apply-change'">应用差异并提交审核</el-button>
            <el-button v-if="view.canReconcile" type="primary" :disabled="view.state.busy" @click="view.mutate('reconcile-change')">同步审核结果</el-button>
            <el-button v-if="view.canRetain" type="primary" :disabled="view.state.busy || !view.retainReasonReady" @click="view.state.confirmation = 'retain-approved'">保留人工审核结果并同步</el-button>
          </template>
          <template v-else-if="view.state.batch"><el-button :disabled="view.state.busy || view.state.detailLoading" @click="view.openBatch(view.batch)">刷新批次</el-button><el-button v-if="view.batch.can_cancel_import" :disabled="view.state.busy" @click="view.state.confirmation = 'cancel-import'">取消此批次</el-button><el-button v-if="view.batch.can_confirm_import" type="primary" :disabled="view.state.busy" @click="view.state.confirmation = 'import'">确认导入原始数据</el-button></template>
        </div>
      </div>
    </template>
  </TeachingPlanCenterView>
</template>

<style scoped>
.teaching-center-pc { display: flex; flex-direction: column; flex: 1 1 0; min-height: 0; height: 100%; overflow: auto; overscroll-behavior: contain; box-sizing: border-box; padding: 0; container: teaching-center / size; }
.pc-center-actions { display: flex; justify-content: flex-end; align-items: center; flex-wrap: wrap; gap: 8px; }
.pc-center-actions :deep(.el-button + .el-button) { margin-left: 0; }
.pc-center-tabs { flex: 0 0 auto; }
.pc-center-tabs :deep(.el-tabs__header) { margin-bottom: 10px; }
.pc-center-tabs :deep(.el-tabs__content) { display: none; }
.pc-center-list { display: flex; flex: 1 1 0; flex-direction: column; gap: 12px; min-height: 0; overflow: hidden; }
.pc-center-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); align-items: end; gap: 10px; flex: 0 0 auto; }
.pc-center-filters label, .pc-center-import-fields label { display: grid; gap: 6px; min-width: 0; }
.pc-center-filters .keyword { min-width: 0; }
.pc-center-filters > .el-button { justify-self: start; }
.pc-center-table { flex: 1 1 0; min-height: 100px; min-width: 0; overflow: hidden; }
.pc-center-list > .list-pagination { flex: 0 0 auto; padding-bottom: 2px; }
.pc-center-help { margin: 0; color: var(--muted, #627083); font-size: 12px; line-height: 1.6; overflow-wrap: anywhere; }
.pc-center-import-body { min-height: 0; overflow: auto; display: grid; align-content: start; gap: 16px; padding: 20px; }
.pc-center-import-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.pc-center-import-fields .wide { grid-column: 1 / -1; }
.pc-center-import-fields input[type=file] { max-width: 100%; padding: 10px; border: 1px solid var(--line, #dce2e9); border-radius: 5px; color: var(--text); background: var(--surface); }
.pc-center-footer { width: 100%; min-width: 0; }
.pc-center-confirm { max-height: 110px; overflow: auto; margin: 0 0 12px; font-size: 13px; line-height: 1.65; overflow-wrap: anywhere; }
:global(.operation-dialog.teaching-center-processing-dialog) { width: min(1080px, calc(100vw - 48px)); height: min(820px, calc(100dvh - var(--desktop-top-inset, 0px) - 122px)); grid-template-rows: auto minmax(0, 1fr) auto; }
:global(.operation-dialog.teaching-center-import-dialog) { width: min(700px, calc(100vw - 48px)); grid-template-rows: auto minmax(0, 1fr) auto; }
@media (max-width: 760px) { .pc-center-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .pc-center-filters .keyword { min-width: 0; } .pc-center-import-fields { grid-template-columns: minmax(0, 1fr); } }
@container teaching-center (max-height: 620px) {
  .pc-center-list { flex: 0 0 auto; overflow: visible; }
  .pc-center-table { flex: 0 0 220px; height: 220px; min-height: 220px; }
  .pc-center-list > .list-pagination { padding-bottom: 10px; }
}
</style>
