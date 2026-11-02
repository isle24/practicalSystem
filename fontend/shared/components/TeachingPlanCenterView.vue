<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import FormFieldLabel from './FormFieldLabel.vue';
import { teachingPlanFieldLabel, teachingPlanProgress, teachingPlanStage, teachingPlanValue, useTeachingPlanCenter } from '../useTeachingPlanCenter';

const props = defineProps({ request: { type: Function, required: true }, canImport: Boolean, canAssign: Boolean, canChange: Boolean, mobile: Boolean, sessionKey: { type: String, default: '' } });
const emit = defineEmits(['open-target']);
const { state, types, categories, cohorts, requiresCohort, sourceFields, canAssign, canComplete, canChange, canApply, canReconcile, canRetain, retainReasonReady, assignmentReady, supplementReady, load, openDetail, closeDetail, mutate, loadBatches, openBatch, closeBatch, loadBatchPage, upload, confirmImport } = useTeachingPlanCenter(props);
const workspace = ref(null);
watch(() => [state.detail?.id, state.batch?.id || state.batch?.batch?.id], async values => {
  if (!values.some(Boolean)) return;
  await nextTick();
  workspace.value?.scrollIntoView({ block: 'start' });
  workspace.value?.focus({ preventScroll: true });
});
const stageOptions = ['unassigned', 'needs_completion', 'accepted', 'change_pending'];
const years = computed(() => {
  const year = new Date().getFullYear();
  return [...new Set([...(state.options.academic_years || []), ...Array.from({ length: 9 }, (_, i) => `${year + 1 - i}-${year + 2 - i}`)])].sort().reverse();
});
const departments = computed(() => state.options.departments || []);
const batch = computed(() => ({ ...state.batch, ...(state.batch?.batch || {}) }));
const changeRows = computed(() => state.change?.diff || state.detail?.pending_change?.diff_json || []);
const missing = computed(() => (state.detail?.missing_fields || []).map(item => typeof item === 'string' ? teachingPlanFieldLabel(item) : item.label || item.message || teachingPlanFieldLabel(item.field)).filter(Boolean));
const mappingIssues = computed(() => (state.detail?.mapping_issues || []).map(item => typeof item === 'string' ? item : item.message || item.label || item.code).filter(Boolean));
const targetStatus = value => ({ draft: '草稿', wait: '待审核', modify: '退回修改', accept: '已通过', refuse: '未通过', active: '进行中' })[value] || value || '未记录';
const typeLabel = value => types.value.find(item => item.value === value)?.label || '未分类';
const sourceName = row => row.source?.course_name || row.course_name || row.raw_data?.['课程名称'] || '未填写课程名称';
const sourceScope = row => [row.source?.grade_name || row.raw_data?.['年级'], row.source?.profession_name || row.raw_data?.['专业名称']].filter(Boolean).join(' / ');
const batchLabel = status => ({ queued: '排队中', parsing: '解析中', validating: '校验中', pending_confirm: '待确认导入', publishing: '导入中', completed: '已完成', partial_failed: '部分失败', failed: '失败', cancelled: '已取消' })[status] || status;
const optionLabel = item => `${item.name || item.category_name || item.cohort_name || item.graduation_cohort_name || item.label || item.id}${item.disabled ? `（${item.disabled_reason || item.reason || '不可用'}）` : ''}`;
const previewLabels = { academic_year: '学年', semester: '学期', grade_name: '年级', dep_name: '学院', profession_code: '专业代码', profession_name: '专业名称', course_code: '课程代码', course_name: '课程名称', course_category: '课程类别', course_nature: '课程性质', credit: '学分', weekly_hours: '周学时', total_hours: '总学时', theory_hours: '理论学时', experiment_hours: '实验学时', practice_hours: '实践学时', other_hours: '其他学时', student_count: '人数', source_status: '来源状态', mapping_status: '关联状态', business_type: '业务类型', candidate_status: '候选状态' };
const nextStep = computed(() => {
  const detail = state.detail;
  if (!detail) return '';
  if (detail.target?.unavailable) return detail.target.reason || '请联系学校管理员核对目标计划权限与归属。';
  if (detail.can_reconcile_change) return '原计划审核已通过，点击“同步审核结果”更新中心来源版本。';
  if (detail.can_retain_approved) return '目标计划已经人工审核通过。请核对当前已审核内容，填写保留原因后同步来源版本；此操作仅更新中心关联状态。';
  if (teachingPlanProgress(detail) === '审核中') return '目标计划正在原审核流程中。请等待审核完成，随后回中心刷新并同步结果。';
  if (teachingPlanProgress(detail) === '已退回') return '目标计划已退回。请打开原计划按意见修改；存在来源变更时可重新准备差异，再提交原审核。';
  if (detail.can_complete_initial) return detail.target_id ? '打开目标计划保存缺少的必需内容，然后回中心刷新并完成首次导入。' : '补齐本页生成条件，再完成首次导入创建绑定目标计划。';
  if (detail.stage === 'needs_completion') return '本计划当前不能首次免审。请打开原计划核对内容及审核记录，按原流程提交审核，通过后回中心同步。';
  if (detail.stage === 'unassigned') return '选择计划类型，确认生成对应计划。';
  if (detail.stage === 'accepted') return '当前来源版本已生效。后续业务修改与来源变更按原计划审核流程处理。';
  if (detail.pending_change) return '准备并核对来源差异，待目标计划处于可编辑状态后应用差异并提交审核。';
  return '请先处理当前阻塞原因，再刷新计划状态。';
});
const reviewSource = computed(() => {
  const detail = state.detail;
  if (!detail) return {};
  if (detail.reconcile_source) return detail.reconcile_source;
  return detail.pending_change?.status === 'submitted' ? detail.pending_change : detail.binding?.source_snapshot || {};
});
const reviewSourceHasNewer = computed(() => reviewSource.value.source_hash && reviewSource.value.source_hash !== state.detail?.source_hash);
const retainedDiff = computed(() => (state.detail?.retain_diff || []).filter(item => !['id', 'uuid', 'parent_id', 'source_type', 'source_plan_id', 'source_plan_version', 'source_updated_at', 'created_at', 'updated_at', 'deleted_at', 'operator_id', 'creator_id', 'submitter_id'].includes(item.field)));
function retainedValue(field, value) {
  if (field === 'category_id') return categories.value.find(item => Number(item.id) === Number(value))?.name || teachingPlanValue(value);
  if (field === 'graduation_cohort_id') return cohorts.value.find(item => Number(item.id) === Number(value))?.name || teachingPlanValue(value);
  if (['content_json', 'plan_content'].includes(field)) {
    let content = value;
    if (typeof content === 'string') { try { content = JSON.parse(content); } catch { return content; } }
    if (content && typeof content === 'object' && !Array.isArray(content)) { const { teaching_plan_source, ...business } = content; return teachingPlanValue(business); }
  }
  return teachingPlanValue(value);
}
const businessLabels = { title: '计划名称', code: '计划编号', grade_id: '年级编号', dep_id: '学院编号', organizer_dep_id: '组织学院编号', profession_id: '专业编号', class_id: '班级编号', start_date: '开始日期', end_date: '结束日期', content_json: '教学内容', plan_content: '计划内容', description: '计划说明', score_rule: '成绩规则', participation_mode: '参与模式', teacher_match_mode: '师生匹配模式', teacher_confirm_hours: '教师确认时限', max_reselect_count: '重选次数', default_team_submit_mode: '团队提交模式' };
const currentApprovedFields = computed(() => {
  const row = state.detail?.target_record || {};
  return [['title', '计划名称'], ['course_name', '课程名称'], ['course_code', '课程代码'], ['credit', '学分'], ['semester', '学期'], ['start_date', '开始日期'], ['end_date', '结束日期'], ['practice_start_at', '实践开始'], ['practice_end_at', '实践结束']]
    .filter(([key]) => row[key] !== undefined && row[key] !== null && row[key] !== '')
    .map(([key, label]) => ({ key, label, value: row[key] }));
});
function previewSourceRows(item) {
  const before = item.before_json?.raw_payload || {};
  const after = item.after_json?.raw_payload || {};
  const fields = state.options.source_fields?.length ? state.options.source_fields : [...new Set([...Object.keys(before), ...Object.keys(after)])].map(key => ({ key, label: key }));
  return fields.map(field => ({ ...field, before: before[field.key] ?? before[field.label], after: after[field.key] ?? after[field.label] }));
}
const importChangeLabel = value => ({ created: '新增', updated: '更新', unchanged: '未变化', skipped: '跳过', conflict: '冲突' })[value] || value || '';
function previewDiff(item) { return item.diff_json || item.field_changes || item.changes || item.diff || []; }
function chooseFile(event) { state.upload.file = event.target.files?.[0] || null; event.target.value = ''; }
function switchTab(tab) { state.tab = tab; state.error = ''; if (tab === 'batches') loadBatches(); else load(); }
function target() { if (state.detail?.target_id) emit('open-target', { ...state.detail, target_type: state.detail.target_type || state.detail.classification }); }

</script>

<template>
  <section class="teaching-center" :class="{ 'is-mobile': mobile }" :aria-busy="state.loading || state.busy">
    <header class="center-header">
      <div><h1>教学计划中心</h1><p>查看原始教学计划，按专业所属学院分配并补齐业务计划。</p></div>
      <button type="button" :disabled="state.loading || state.busy" @click="state.tab === 'batches' ? loadBatches(state.batchPagination.page) : load(state.pagination.page)">刷新</button>
    </header>
    <p class="center-note">以“专业学院名称”匹配学院全名或简称；只有唯一匹配才能分配。学院管理员仅处理本学院，归属不明或重名需由学校管理员修正基础档案。</p>
    <div class="center-tabs" aria-label="教学计划视图">
      <button type="button" :disabled="state.busy || state.loading" :aria-pressed="state.tab === 'records'" @click="switchTab('records')">原始计划</button>
      <button v-if="canImport" type="button" :disabled="state.busy || state.loading" :aria-pressed="state.tab === 'batches'" @click="switchTab('batches')">上传与批次</button>
    </div>
    <p v-if="state.error && !state.detail && !state.batch" class="center-error" role="alert">{{ state.error }}</p>
    <p v-if="state.receipt && !state.detail && !state.batch" class="center-receipt" role="status">{{ state.receipt }}</p>

    <template v-if="state.tab === 'records'">
      <form class="center-filters" @submit.prevent="load(1)">
        <label><span>关键词</span><input v-model.trim="state.filters.keyword" placeholder="课程代码、课程名称、专业"></label>
        <label><span>学年</span><select v-model="state.filters.academic_year"><option value="">全部学年</option><option v-for="year in years" :key="year">{{ year }}</option></select></label>
        <label><span>学期</span><select v-model="state.filters.semester"><option value="">全部学期</option><option value="1">第一学期</option><option value="2">第二学期</option></select></label>
        <label><span>当前阶段</span><select v-model="state.filters.stage"><option value="">全部阶段</option><option v-for="stage in stageOptions" :key="stage" :value="stage">{{ teachingPlanStage(stage) }}</option></select></label>
        <label><span>分类</span><select v-model="state.filters.classification"><option value="">全部分类</option><option v-for="item in types" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
        <label v-if="departments.length > 1"><span>归属学院</span><select v-model="state.filters.owner_dep_id"><option value="">权限范围内全部学院</option><option v-for="item in departments" :key="item.dep_id || item.id" :value="item.dep_id || item.id">{{ item.dep_name || item.name }}</option></select></label>
        <button type="submit" class="primary" :disabled="state.loading || state.busy">查询</button>
      </form>
      <p v-if="state.loading" role="status">正在读取原始计划…</p>
      <div v-else-if="!state.rows.length" class="center-empty">当前筛选条件下没有原始计划。学校管理员可在“上传与批次”导入并确认教学计划。</div>
      <div v-else class="center-records">
        <article v-for="row in state.rows" :key="row.id" class="center-record">
          <div class="record-title"><strong>{{ sourceName(row) }}</strong><span>{{ row.source?.course_code || row.raw_data?.['课程代码'] || '无课程代码' }}</span></div>
          <div class="record-facts"><span>{{ sourceScope(row) }}</span><span>归属：{{ row.owner_dep_name || '未确定' }}</span><span>{{ typeLabel(row.classification) }} · {{ teachingPlanProgress(row) }}</span></div>
          <p v-if="row.mapping_issues?.length" class="center-error">{{ row.mapping_issues.map(item => typeof item === 'string' ? item : item.message || item.label || item.code).join('；') }}</p>
          <p v-if="row.missing_fields?.length">待补齐：{{ row.missing_fields.map(item => typeof item === 'string' ? teachingPlanFieldLabel(item) : item.label || teachingPlanFieldLabel(item.field)).join('、') }}</p>
          <p v-if="row.source_status && row.source_status !== 'active'" class="center-error">来源已{{ row.source_status === 'missing' ? '在最新批次缺失' : '停用' }}，不能继续首次导入。</p>
          <p v-if="row.stage === 'conflict'" class="center-error">来源已有旧业务绑定，请学校管理员先核对。</p>
          <p v-if="row.pending_change">来源存在变更，需查看差异并经目标计划审核。</p>
          <button type="button" :disabled="state.busy" @click="openDetail(row)">查看原始数据与处理</button>
        </article>
      </div>
      <nav class="center-pagination" aria-label="原始计划分页"><span>共 {{ state.pagination.total }} 条</span><button type="button" :disabled="state.loading || state.pagination.page <= 1" @click="load(state.pagination.page - 1)">上一页</button><span>{{ state.pagination.page }}</span><button type="button" :disabled="state.loading || state.pagination.page * state.pagination.page_size >= state.pagination.total" @click="load(state.pagination.page + 1)">下一页</button></nav>
    </template>

    <template v-else-if="canImport">
      <form class="center-upload" @submit.prevent="upload">
        <h2>上传教学计划</h2>
        <p>沿用教务开课计划模板的 45 列，上传后先校验、预览，再确认导入。从本入口新上传的批次按增量更新，未出现在本文件中的已有来源保留，分类由管理员明确选择。</p>
        <div class="center-form-grid">
          <label><FormFieldLabel required help="与文件中的学年一致">学年</FormFieldLabel><select v-model="state.upload.academic_year" required><option value="" disabled>请选择学年</option><option v-for="year in years" :key="year">{{ year }}</option></select></label>
          <label><FormFieldLabel required help="与文件中的学期一致">学期</FormFieldLabel><select v-model="state.upload.semester" required><option value="" disabled>请选择学期</option><option value="1">第一学期</option><option value="2">第二学期</option></select></label>
          <label class="wide"><FormFieldLabel required help="xls / xlsx，1B 至 500MB；请使用完整45列模板">教学计划文件</FormFieldLabel><input type="file" accept=".xls,.xlsx" :disabled="state.busy" @change="chooseFile"></label>
        </div>
        <p v-if="state.upload.file">已选择：{{ state.upload.file.name }}</p>
        <button type="submit" class="primary" :disabled="state.busy || !state.upload.file">{{ state.busy ? '正在处理…' : '上传并校验' }}</button>
      </form>
      <h2>导入批次</h2>
      <p v-if="state.loading">正在读取批次…</p><p v-else-if="!state.batches.length" class="center-empty">尚无教学计划导入批次。</p>
      <div class="center-records"><article v-for="item in state.batches" :key="item.id" class="center-record"><strong>{{ item.original_name || item.file_name || `批次 ${item.id}` }}</strong><span>{{ item.academic_year }} / {{ item.semester }} · {{ batchLabel(item.status) }}</span><span>{{ item.created_at }}</span><button type="button" :disabled="state.busy" @click="openBatch(item)">查看校验与预览</button></article></div>
      <nav class="center-pagination" aria-label="导入批次分页"><span>共 {{ state.batchPagination.total }} 批</span><button type="button" :disabled="state.loading || state.batchPagination.page <= 1" @click="loadBatches(state.batchPagination.page - 1)">上一页</button><span>{{ state.batchPagination.page }}</span><button type="button" :disabled="state.loading || state.batchPagination.page * state.batchPagination.page_size >= state.batchPagination.total" @click="loadBatches(state.batchPagination.page + 1)">下一页</button></nav>
    </template>

    <section v-if="state.detail || state.detailLoading || state.batch" ref="workspace" class="center-workspace" tabindex="-1" aria-label="教学计划处理详情" :aria-busy="state.detailLoading">
      <p v-if="state.error" class="center-error" role="alert">{{ state.error }}</p>
      <p v-if="state.receipt" class="center-receipt" role="status">{{ state.receipt }}</p>
      <p v-if="state.detailLoading" role="status">正在读取详情…</p>
      <template v-if="state.detail">
        <header class="center-header"><div><h2>{{ sourceName(state.detail) }}</h2><p>当前阶段：{{ teachingPlanProgress(state.detail) }} · 归属学院：{{ state.detail.owner_dep_name || '未确定' }}</p></div><button type="button" :disabled="state.busy" @click="closeDetail">关闭详情</button></header>
        <p>来源批次：{{ state.detail.last_seen_batch_id || '未记录' }}<template v-if="state.detail.row_number"> · 原始第 {{ state.detail.row_number }} 行</template></p>
        <p v-if="state.detail.blocked_reason" class="center-error">{{ state.detail.blocked_reason }}</p>
        <p v-if="state.detail.stage === 'conflict'" class="center-error">阻塞原因：来源已有旧候选或业务计划绑定。请学校管理员先核对关联，避免重复生成。</p>
        <p v-if="mappingIssues.length" class="center-error" role="alert">阻塞原因：{{ mappingIssues.join('；') }}。修正学院全名、简称或专业档案后刷新。</p>
        <p v-if="missing.length" class="center-note">当前缺项：{{ missing.join('、') }}。</p>
        <p class="center-note">下一步：{{ nextStep }}</p>
        <p v-if="mobile && state.detail.target_id && state.detail.target_type !== 'social_practice' && canComplete" class="center-note">手机端可查看计划及流程。请在 PC 端打开本条绑定计划的原表单补齐，保存后回中心完成首次导入。</p>
        <div class="center-actions"><button v-if="state.detail.target_id" type="button" :disabled="state.busy || state.detail.target?.unavailable" @click="target">打开{{ typeLabel(state.detail.target_type) }}计划 #{{ state.detail.target_id }}</button><button type="button" :disabled="state.busy || state.detailLoading" @click="openDetail(state.detail)">刷新此计划</button></div>
        <form v-if="canAssign || ((canComplete || canChange) && !state.detail.target_id)" class="center-assignment" @submit.prevent="canAssign && (state.confirmation = 'assign')">
          <h3>{{ canAssign ? '分配业务类型' : '补齐已绑定类型的生成条件' }}</h3><p>首次生成将唯一绑定目标计划，之后不能重复分类。字段完整时免审生效；存在缺项时保存为待补齐计划。</p>
          <div class="center-form-grid">
            <label><FormFieldLabel required help="由管理员人工判断，生成后锁定">计划类型</FormFieldLabel><select v-model="state.classification" required :disabled="state.busy || Boolean(state.detail.intake_id)"><option value="" disabled>请选择类型</option><option v-for="item in types" :key="item.value" :value="item.value" :disabled="item.disabled">{{ item.label }}{{ item.disabled ? `（${item.reason || '不可用'}）` : '' }}</option></select></label>
            <label v-if="state.classification === 'internship'"><FormFieldLabel required help="按类别决定年级或毕业届次范围">实习类别</FormFieldLabel><select v-model="state.supplement.category_id" required :disabled="state.busy"><option value="" disabled>请选择实习类别</option><option v-for="item in categories" :key="item.id" :value="item.id" :disabled="item.disabled">{{ optionLabel(item) }}</option></select></label>
            <label v-if="requiresCohort"><FormFieldLabel required help="仅显示当前可用毕业届次">毕业届次</FormFieldLabel><select v-model="state.supplement.graduation_cohort_id" required :disabled="state.busy"><option value="" disabled>请选择毕业届次</option><option v-for="item in cohorts" :key="item.id" :value="item.id" :disabled="item.disabled">{{ optionLabel(item) }}</option></select></label>
          </div>
          <button v-if="canAssign" type="submit" class="primary" :disabled="state.busy || !assignmentReady">确认分类并生成</button>
        </form>
        <p v-else-if="state.detail.intake_id" class="center-note">已绑定{{ typeLabel(state.detail.classification) }}计划 #{{ state.detail.target_id || '待生成' }}，分类已锁定。</p>
        <div v-if="canComplete" class="center-actions"><button type="button" class="primary" :disabled="state.busy || (!state.detail.target_id && !supplementReady)" @click="state.confirmation = 'complete'">完成首次导入</button><span>仅首次可信导入且未经过人工审核的完整计划可免审；服务端会重新校验。</span></div>
        <section v-if="state.detail.pending_change || state.change" class="center-change">
          <h3>来源变更</h3><p>先准备差异，再到目标计划按原流程发起修改；处于可编辑状态后才可应用差异并提交原审核流程。</p>
          <div class="center-actions"><button v-if="canChange" type="button" :disabled="state.busy" @click="mutate('prepare-change')">{{ state.detail.pending_change?.status === 'submitted' ? '重新准备退回变更' : '准备变更并查看差异' }}</button><button v-if="canApply" type="button" :disabled="state.busy" @click="state.confirmation = 'apply-change'">应用差异并提交审核</button></div>
          <p v-if="state.detail.pending_change?.status === 'submitted'">目标计划当前状态：{{ targetStatus(state.detail.target_status) }}。{{ teachingPlanProgress(state.detail) === '已退回' ? '按退回意见修改后可重新准备变更。' : '请按上方下一步提示处理。' }}</p>
          <p v-if="state.change?.message">{{ state.change.message }}</p>
          <div v-if="changeRows.length" class="center-table-wrap"><table><thead><tr><th>字段</th><th>原值</th><th>来源新值</th></tr></thead><tbody><tr v-for="(item, index) in changeRows" :key="index"><th>{{ item.label || item.field }}</th><td>{{ teachingPlanValue(item.before ?? item.local) }}</td><td>{{ teachingPlanValue(item.after ?? item.source) }}</td></tr></tbody></table></div>
          <p v-else>点击准备变更，读取当前计划与来源字段差异。</p>
        </section>
        <section v-if="canReconcile || canRetain" class="center-review-sync">
          <h3>同步原计划审核结果</h3>
          <p>目标计划 #{{ state.detail.target_id }} 当前状态：{{ targetStatus(state.detail.target_status) }}。本操作保留已审核业务内容，只同步本次审核对应的来源版本和中心关联状态。</p>
          <p>本次审核对应来源批次：{{ reviewSource.batch_id || reviewSource.last_seen_batch_id || '首次分配来源' }}。</p>
          <p v-if="reviewSourceHasNewer" class="center-note">审核期间已收到更新的来源。同步本次审核后，新来源仍保留待处理，需要单独核对差异和审核。</p>
          <dl class="approved-fields"><div v-for="field in currentApprovedFields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ teachingPlanValue(field.value) }}</dd></div></dl>
          <div v-if="canRetain && retainedDiff.length" class="center-table-wrap"><table><thead><tr><th>字段</th><th>原审核来源快照</th><th>当前已审核内容</th></tr></thead><tbody><tr v-for="(difference, index) in retainedDiff" :key="index"><th>{{ difference.label || businessLabels[difference.field] || previewLabels[difference.field] || teachingPlanFieldLabel(difference.field) }}</th><td>{{ retainedValue(difference.field, difference.before) }}</td><td>{{ retainedValue(difference.field, difference.after) }}</td></tr></tbody></table></div>
          <p v-if="canRetain">以上为计划字段差异；适用范围、材料要求和成绩规则等关联内容请打开原计划核对。</p>
          <div v-if="canReconcile" class="center-actions"><button type="button" class="primary" :disabled="state.busy" @click="mutate('reconcile-change')">同步审核结果</button></div>
          <form v-if="canRetain" @submit.prevent="retainReasonReady && (state.confirmation = 'retain-approved')">
            <label><FormFieldLabel required help="8 至 800 字，说明为何保留当前人工审核后的业务内容">保留原因</FormFieldLabel><textarea v-model="state.retainReason" rows="4" minlength="8" maxlength="800" required :disabled="state.busy" placeholder="请说明已核对的业务内容、调整原因与审核结果"></textarea></label>
            <p>{{ [...state.retainReason.trim()].length }} / 800 字</p>
            <button type="submit" :disabled="state.busy || !retainReasonReady">保留本次人工审核结果并同步来源版本</button>
          </form>
        </section>
        <div v-if="['assign', 'complete', 'apply-change', 'retain-approved'].includes(state.confirmation)" class="center-confirm" role="alert">
          <p v-if="state.confirmation === 'retain-approved'">确认保留计划 #{{ state.detail.target_id }} 当前已经人工审核通过的业务内容，并将本次审核对应的来源版本与其关联？本次不修改业务计划，也不会执行首次免审。保留原因：{{ state.retainReason.trim() }}</p>
          <p v-else>{{ state.confirmation === 'assign' ? `确认生成${typeLabel(state.classification)}计划？生成后类型锁定，不能再次分配。` : state.confirmation === 'complete' ? '确认已在目标计划保存全部必需内容？完成首次导入将重新校验当前来源、绑定及审批历史。' : '确认将已展示的来源差异应用到可编辑计划？原计划中未涉及的业务设置保留，确认后提交原审核流程。' }}</p>
          <button type="button" class="primary" :disabled="state.busy || (state.confirmation === 'assign' && !assignmentReady) || (state.confirmation === 'retain-approved' && !retainReasonReady)" @click="mutate(state.confirmation)">{{ state.busy ? '正在处理…' : '确认执行' }}</button><button type="button" :disabled="state.busy" @click="state.confirmation = ''">取消</button>
        </div>
        <details class="center-source" open><summary>原始教学计划字段（{{ sourceFields.length }} 列，只读）</summary><dl><div v-for="field in sourceFields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ teachingPlanValue(field.value) }}</dd></div></dl></details>
      </template>
      <template v-if="state.batch">
        <header class="center-header"><div><h2>批次 {{ batch.id }} 校验与预览</h2><p>{{ batchLabel(batch.status) }} · {{ batch.academic_year }} / {{ batch.semester }}</p></div><button type="button" :disabled="state.busy" @click="closeBatch">关闭批次</button></header>
        <p v-if="batch.blocked_reason" class="center-error">{{ batch.blocked_reason }}</p>
        <p v-if="batch.error_message" class="center-error">{{ batch.error_message }}</p>
        <p class="center-note">导入方式：{{ batch.import_semantics_label || '尚未读取，请刷新批次核对' }}。</p>
        <p>确认导入按本批次的导入方式处理原始数据，不会直接生成业务计划。</p>
        <div class="center-actions"><button type="button" :disabled="state.busy || state.detailLoading" @click="openBatch(batch)">刷新批次</button><button v-if="batch.can_confirm_import" type="button" class="primary" :disabled="state.busy" @click="state.confirmation = 'import'">确认导入原始数据</button><button v-if="batch.can_cancel_import" type="button" :disabled="state.busy" @click="state.confirmation = 'cancel-import'">取消此批次</button></div>
        <div v-if="state.confirmation === 'import'" class="center-confirm"><p>确认导入本批次的原始教学计划？{{ batch.import_semantics_label || '导入方式尚未读取，请先刷新批次' }}。请先核对全部校验问题、前后值及完整源字段。</p><button type="button" class="primary" :disabled="state.busy" @click="confirmImport(false)">确认导入</button><button type="button" :disabled="state.busy" @click="state.confirmation = ''">取消</button></div>
        <div v-if="state.confirmation === 'cancel-import'" class="center-confirm"><p>确认取消批次 #{{ batch.id }}？本批次剩余待导入数据将停止处理；已发布的原始数据保留。修正文件和学年学期后可重新上传。</p><button type="button" :disabled="state.busy || !batch.can_cancel_import" @click="confirmImport(true)">确认取消批次</button><button type="button" :disabled="state.busy" @click="state.confirmation = ''">返回预览</button></div>
        <section v-for="kind in ['issues', 'changes']" :key="kind" class="center-batch-preview"><h3>{{ kind === 'issues' ? '校验问题' : '待导入预览与前后差异' }}</h3><p v-if="!state.batch[kind]?.items?.length">本页无{{ kind === 'issues' ? '校验问题' : '差异记录' }}。</p><article v-for="(item, index) in state.batch[kind]?.items || []" :key="item.id || index"><strong>{{ item.row_number ? `第 ${item.row_number} 行` : item.source_key || `记录 ${index + 1}` }}</strong><p v-if="item.message || item.issue_message">{{ item.message || item.issue_message }}</p><p v-if="kind === 'issues'">{{ item.issue_detail || item.detail || item.reason || '' }}</p><div v-else-if="previewDiff(item).length" class="center-table-wrap"><table><thead><tr><th>字段</th><th>原值</th><th>新值</th></tr></thead><tbody><tr v-for="(difference, diffIndex) in previewDiff(item)" :key="diffIndex"><th>{{ difference.label || previewLabels[difference.field] || difference.field }}</th><td>{{ teachingPlanValue(difference.before) }}</td><td>{{ teachingPlanValue(difference.after) }}</td></tr></tbody></table></div><p v-else-if="kind === 'changes'">{{ item.after_json?.course_name || item.before_json?.course_name || '本条无字段差异' }} · {{ importChangeLabel(item.change_type) }}</p><details v-if="kind === 'changes' && (item.after_json?.raw_payload || item.before_json?.raw_payload)" class="batch-source-preview"><summary>查看此行完整原始字段（{{ state.options.source_fields?.length || 45 }} 列）</summary><div class="center-table-wrap"><table><thead><tr><th>字段</th><th>导入前</th><th>待导入</th></tr></thead><tbody><tr v-for="field in previewSourceRows(item)" :key="field.key"><th>{{ field.label }}</th><td>{{ teachingPlanValue(field.before) }}</td><td>{{ teachingPlanValue(field.after) }}</td></tr></tbody></table></div></details></article><nav v-if="state.batch[kind]?.pagination" class="center-pagination" :aria-label="kind === 'issues' ? '校验问题分页' : '原始差异分页'"><span>共 {{ state.batch[kind].pagination.total }} 条</span><button type="button" :disabled="state.detailLoading || state.batch[kind].pagination.page <= 1" @click="loadBatchPage(kind, state.batch[kind].pagination.page - 1)">上一页</button><span>{{ state.batch[kind].pagination.page }}</span><button type="button" :disabled="state.detailLoading || state.batch[kind].pagination.page * state.batch[kind].pagination.page_size >= state.batch[kind].pagination.total" @click="loadBatchPage(kind, state.batch[kind].pagination.page + 1)">下一页</button></nav></section>
      </template>
    </section>
  </section>
</template>

<style scoped>
.teaching-center { color: var(--text, var(--app-text, #202b3a)); font-size: 14px; line-height: 1.6; padding: 16px; min-width: 0; }
.center-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 12px; }
h1 { font-size: 20px; } h2 { font-size: 17px; } h3 { font-size: 15px; } h1, h2, h3, p { margin: 0 0 10px; } h1, h2, h3 { font-weight: 600; }
.center-header p, .center-note, .record-facts { color: var(--muted, var(--app-text-secondary, #627083)); }
.center-note, .center-receipt, .center-error, .center-confirm { padding: 10px 12px; background: var(--surface-2, var(--app-surface-muted, #f5f7fa)); border-radius: 6px; overflow-wrap: anywhere; }
.center-error { color: var(--danger, var(--app-danger, #b42318)); } .center-receipt { color: var(--success, #237143); }
button, input, select, textarea { font: inherit; color: inherit; border: 1px solid var(--line, var(--app-line, #dce2e9)); background: var(--surface, var(--app-surface, #fff)); border-radius: 5px; min-height: 36px; }
button { padding: 6px 12px; cursor: pointer; } button:disabled { opacity: .55; cursor: default; } button:not(:disabled):hover { border-color: var(--primary, var(--app-primary, #2563eb)); }
button.primary { background: var(--primary, var(--app-primary, #2563eb)); color: white; border-color: transparent; }
button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible { outline: 2px solid var(--primary, var(--app-primary, #2563eb)); outline-offset: 2px; }
input, select, textarea { width: 100%; min-width: 0; padding: 7px 9px; box-sizing: border-box; } input[type=file] { max-width: 100%; }
label { display: grid; align-content: start; gap: 6px; min-width: 0; } .center-tabs, .center-actions, .center-pagination { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 12px 0; }
.center-tabs button[aria-pressed=true] { color: var(--primary, var(--app-primary, #2563eb)); background: var(--surface-2, var(--app-surface-muted, #f5f7fa)); }
.center-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); align-items: end; gap: 10px; margin: 16px 0; }
.center-records { display: grid; } .center-record { display: grid; gap: 6px; padding: 14px 0; border-bottom: 1px solid var(--line, var(--app-line, #dce2e9)); } .center-record > button { justify-self: start; }
.record-title, .record-facts { display: flex; flex-wrap: wrap; gap: 6px 18px; } .record-title span { color: var(--muted, var(--app-text-secondary, #627083)); } .center-record p { margin: 0; }
.center-pagination { justify-content: flex-end; } .center-pagination > span:first-child { margin-right: auto; } .center-empty { padding: 28px 12px; background: var(--surface-2, var(--app-surface-muted, #f5f7fa)); }
.center-workspace { margin-top: 20px; padding-top: 20px; border-top: 2px solid var(--line, var(--app-line, #dce2e9)); } .center-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; } .wide { grid-column: 1 / -1; }
.center-upload, .center-assignment, .center-change, .center-review-sync { padding: 14px 0; } .center-source { margin-top: 20px; } summary { cursor: pointer; font-weight: 600; padding: 8px 0; }
.center-source dl, .approved-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; } .center-source dl > div, .approved-fields > div { padding: 10px; border-bottom: 1px solid var(--line, var(--app-line, #dce2e9)); min-width: 0; } dt { color: var(--muted, var(--app-text-secondary, #627083)); font-size: 12px; } dd { margin: 4px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.center-confirm button + button { margin-left: 8px; } .center-table-wrap { overflow-x: auto; } table { width: 100%; border-collapse: collapse; table-layout: fixed; } th, td { text-align: left; vertical-align: top; padding: 10px; border-bottom: 1px solid var(--line, var(--app-line, #dce2e9)); overflow-wrap: anywhere; } th { font-weight: 500; }
.center-batch-preview { margin: 18px 0; } .center-batch-preview article { padding: 10px 0; border-bottom: 1px solid var(--line, var(--app-line, #dce2e9)); } pre { white-space: pre-wrap; overflow-wrap: anywhere; margin: 6px 0; font-size: 12px; }
@media (max-width: 600px) { .teaching-center { padding: 12px; } .center-header { gap: 10px; } .center-header h1 { font-size: 19px; } .center-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .center-filters label:first-child { grid-column: 1 / -1; } .center-form-grid, .center-source dl, .approved-fields { grid-template-columns: minmax(0, 1fr); } .wide { grid-column: auto; } button, input, select, textarea { min-height: 42px; } .center-actions { align-items: stretch; } .center-actions > button { flex: 1 1 auto; } .record-facts { display: grid; gap: 2px; } }
</style>
