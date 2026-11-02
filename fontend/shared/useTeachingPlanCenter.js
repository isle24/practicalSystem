import { computed, onBeforeUnmount, reactive, watch } from 'vue';

export const teachingPlanTypes = [
  { value: 'internship', label: '实习' }, { value: 'social_practice', label: '社会实践' },
  { value: 'lab', label: '实验' }, { value: 'training', label: '实训' },
];
export function teachingPlanStage(value) {
  return ({ unassigned: '待分类', needs_mapping: '归属待处理', needs_completion: '待补齐', accepted: '已生效', change_pending: '待确认变更', conflict: '存在冲突' })[value] || value || '待分类';
}
export function teachingPlanProgress(row) {
  if (!row) return '';
  if (row.target?.unavailable) return '目标不可访问';
  if (['needs_mapping', 'conflict'].includes(row.stage)) return teachingPlanStage(row.stage);
  if (row.review_status === 'content_conflict') return '审核已通过，内容冲突待确认';
  if (row.can_reconcile_change || row.can_retain_approved) return '已通过待同步';
  if (row.target?.review_evidence?.entered_manual_review && ['draft', 'modify', 'refuse', 'reject', 'rejected'].includes(row.target_status)) return '已退回';
  if (row.target?.review_evidence?.entered_manual_review && row.target_status === 'wait') return '审核中';
  return ({ in_review: '审核中', returned: '已退回', accepted_pending_sync: '已通过待同步' })[row.review_status] || teachingPlanStage(row.stage);
}
export function teachingPlanFieldLabel(field) {
  return ({ category_id: '实习类别', graduation_cohort_id: '毕业届次', register_start_at: '报名开始时间', register_end_at: '报名结束时间', practice_start_at: '实践开始时间', practice_end_at: '实践结束时间', result_deadline_at: '成果截止时间', score_deadline_at: '成绩截止时间', requirements: '材料要求', 'score_rules.centralized': '集中实践成绩规则', 'score_rules.distributed': '分散实践成绩规则' })[field] || field;
}
export function teachingPlanValue(value) {
  return value === null || value === undefined || value === '' ? '未填写' : typeof value === 'object' ? JSON.stringify(value) : String(value);
}

export function useTeachingPlanCenter(props) {
  const state = reactive({
    loading: false, busy: false, detailLoading: false, error: '', receipt: '', tab: 'records',
    options: {}, rows: [], pagination: { page: 1, page_size: 20, total: 0 },
    filters: { keyword: '', academic_year: '', semester: '', stage: '', classification: '', owner_dep_id: '' },
    detail: null, classification: '', supplement: { category_id: '', graduation_cohort_id: '' }, change: null,
    batches: [], batchPagination: { page: 1, page_size: 10, total: 0 }, batch: null,
    upload: { academic_year: '', semester: '', file: null }, confirmation: '', retainReason: '',
  });
  let alive = true;
  let context = 0;
  const requests = new Map();
  let actionKey = '';
  let requestId = '';
  const types = computed(() => state.options.classifications || teachingPlanTypes);
  const categories = computed(() => state.options.categories || state.options.internship_categories || []);
  const cohorts = computed(() => state.options.cohorts || state.options.graduation_cohorts || []);
  const category = computed(() => categories.value.find(item => Number(item.id) === Number(state.supplement.category_id)));
  const requiresCohort = computed(() => state.classification === 'internship' && category.value?.scope_type === 'cohort');
  const sourceFields = computed(() => {
    const raw = state.detail?.raw_data || {};
    const fields = state.options.source_fields || Object.keys(raw).map(key => ({ key, label: key }));
    return fields.map(field => ({ ...field, value: raw[field.key] ?? raw[field.label] ?? state.detail?.source?.[field.key] }));
  });
  const canAssign = computed(() => props.canAssign && state.detail && !state.detail.intake_id && !state.detail.target_id && state.detail.source_status === 'active' && state.detail.stage === 'unassigned'
    && state.detail.ownership_status === 'matched' && !state.detail.mapping_issues?.length);
  const canComplete = computed(() => props.canAssign && Boolean(state.detail?.can_complete_initial));
  const supplementReady = computed(() => state.classification && (state.classification !== 'internship'
    || (category.value && !category.value.disabled && (!requiresCohort.value || cohorts.value.some(item => Number(item.id) === Number(state.supplement.graduation_cohort_id) && !item.disabled)))));
  const assignmentReady = computed(() => canAssign.value && supplementReady.value);
  const canChange = computed(() => props.canChange && Boolean(state.detail?.can_prepare_change));
  const canApply = computed(() => props.canChange && Boolean(state.detail?.can_apply_change));
  const canRetain = computed(() => props.canChange && Boolean(state.detail?.can_retain_approved) && !state.detail?.can_reconcile_change);
  const retainReasonReady = computed(() => { const length = [...state.retainReason.trim()].length; return length >= 8 && length <= 800; });
  const canReconcile = computed(() => props.canChange && Boolean(state.detail?.can_reconcile_change));
  const endpoint = (path, params = {}) => `/teaching-plan-center/${path}${Object.keys(params).length ? `?${new URLSearchParams(params)}` : ''}`;
  const get = (path, params) => props.request(endpoint(path, params));
  const post = (path, payload) => props.request(endpoint(path), { method: 'POST', body: JSON.stringify(payload) });
  async function read(key, task, apply) {
    const version = (requests.get(key) || 0) + 1;
    const generation = context;
    requests.set(key, version);
    const current = () => alive && generation === context && requests.get(key) === version;
    try { const value = await task(); if (current()) apply(value); }
    catch (error) { if (current()) state.error = error.message || '读取失败，请重试'; }
    return current();
  }
  async function load(page = 1) {
    state.loading = true;
    state.error = '';
    if (await read('list', () => get('list', { ...state.filters, page, page_size: state.pagination.page_size }), data => {
      state.rows = data.items || [];
      state.pagination = { ...state.pagination, ...data.pagination, page };
    })) state.loading = false;
  }
  async function openDetail(row) {
    state.detailLoading = true;
    state.error = '';
    state.detail = null;
    state.batch = null;
    requests.set('batch', (requests.get('batch') || 0) + 1);
    requests.set('batch-page', (requests.get('batch-page') || 0) + 1);
    state.change = null;
    state.confirmation = ''; state.retainReason = '';
    if (await read('detail', () => get('detail', { id: row.id || row.source_id }), data => {
      state.detail = data.item || data;
      state.classification = state.detail.classification || '';
      const supplement = state.detail.target_id ? {} : state.detail.binding?.supplement || {};
      state.supplement = { category_id: supplement.category_id || '', graduation_cohort_id: '' };
      state.supplement.graduation_cohort_id = supplement.graduation_cohort_id || '';
    })) state.detailLoading = false;
  }
  function closeDetail() {
    requests.set('detail', (requests.get('detail') || 0) + 1);
    state.detail = null; state.detailLoading = false; state.change = null; state.confirmation = '';
  }
  function basePayload() {
    return { id: state.detail.id || state.detail.source_id, revision: state.detail.revision || 0, source_hash: state.detail.source_hash };
  }
  function payload() {
    return { ...basePayload(), classification: state.classification, supplement: !state.detail.target_id && state.classification === 'internship'
      ? { category_id: state.supplement.category_id, ...(requiresCohort.value ? { graduation_cohort_id: state.supplement.graduation_cohort_id } : {}) } : {} };
  }
  async function mutate(path) {
    if (state.busy || !state.detail) return;
    if (path === 'retain-approved' && (!canRetain.value || !retainReasonReady.value)) { state.error = '请填写 8 至 800 字的保留原因'; return; }
    const generation = context;
    const row = { id: state.detail.id || state.detail.source_id };
    const operation = path === 'retain-approved' ? 'reconcile-change' : path;
    const body = operation === 'reconcile-change' ? basePayload() : payload();
    if (path === 'retain-approved') Object.assign(body, {
      retain_approved: true, reason: state.retainReason.trim(),
      target_fingerprint: state.detail.target?.business_fingerprint,
      review_evidence_hash: state.detail.target?.review_evidence?.evidence_hash,
    });
    if (['prepare-change', 'apply-change', 'reconcile-change'].includes(operation)) body.change_id = state.change?.change_id || state.detail.pending_change?.id || state.detail.pending_change?.change_id;
    const key = JSON.stringify([operation, body]);
    if (actionKey !== key) { actionKey = key; requestId = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`; }
    body.request_id = requestId;
    state.busy = true; state.error = ''; state.receipt = '';
    try {
      const result = await post(operation, body);
      if (!alive || generation !== context) return;
      state.confirmation = '';
      if (path === 'prepare-change') { state.detail = result.item || result; state.change = result; return; }
      state.receipt = result.message || (operation === 'reconcile-change' ? `已同步本次审核对应的来源版本，业务计划内容保持不变。${(result.item || result).pending_change ? '仍有新的来源变更，请继续核对处理。' : ''}` : path === 'apply-change' ? '变更已提交原审核流程，以目标计划审核状态为准。' : '处理完成，请核对当前状态及缺项。');
      await openDetail(row);
      await load(state.pagination.page);
    } catch (error) { if (alive && generation === context) state.error = error.message || '处理失败，请刷新核对后重试'; }
    finally { if (alive && generation === context) state.busy = false; }
  }
  async function loadBatches(page = 1) {
    state.loading = true;
    if (await read('batches', () => get('batches', { page, page_size: 10 }), data => {
      state.batches = data.items || []; state.batchPagination = { ...state.batchPagination, ...data.pagination, page };
    })) state.loading = false;
  }
  async function openBatch(row) {
    closeDetail();
    requests.set('batch-page', (requests.get('batch-page') || 0) + 1);
    state.batch = null; state.confirmation = ''; state.detailLoading = true;
    if (await read('batch', () => get('batch-detail', { id: row.id }), data => { state.batch = data; })) state.detailLoading = false;
  }
  function closeBatch() {
    requests.set('batch', (requests.get('batch') || 0) + 1);
    requests.set('batch-page', (requests.get('batch-page') || 0) + 1);
    state.batch = null; state.confirmation = ''; state.detailLoading = false;
  }
  async function loadBatchPage(type, page) {
    const id = state.batch?.batch?.id || state.batch?.id;
    if (!id) return;
    state.detailLoading = true;
    if (await read('batch-page', () => get(type === 'issues' ? 'issues' : 'import-changes', { id, batch_id: id, page, page_size: 20 }), data => { if (state.batch) state.batch[type] = data; })) state.detailLoading = false;
  }
  async function upload() {
    if (state.busy || !props.canImport) return;
    const file = state.upload.file;
    if (!/^\d{4}-\d{4}$/.test(state.upload.academic_year) || !['1', '2'].includes(state.upload.semester)) { state.error = '请选择学年与学期'; return; }
    if (!file || !/\.xlsx?$/i.test(file.name) || !file.size || file.size > 500 * 1024 * 1024) { state.error = '请选择 1B 至 500MB 的 xls 或 xlsx 文件'; return; }
    const generation = context;
    state.busy = true; state.error = '';
    const body = new FormData();
    body.append('file', file); body.append('academic_year', state.upload.academic_year); body.append('semester', state.upload.semester);
    try {
      await props.request(endpoint('import'), { method: 'POST', body, timeoutMs: 0 });
      if (!alive || generation !== context) return;
      state.receipt = '文件已接收，请刷新批次查看校验结果；确认导入后进入原始计划列表。';
      state.upload.file = null;
      await loadBatches();
    } catch (error) { if (alive && generation === context) state.error = error.message; }
    finally { if (alive && generation === context) state.busy = false; }
  }
  async function confirmImport(cancel = false) {
    const batch = { ...state.batch, ...(state.batch?.batch || {}) };
    if (state.busy || !batch?.id || !props.canImport) return;
    if (!(cancel ? batch.can_cancel_import : batch.can_confirm_import)) { state.error = batch.blocked_reason || '批次状态已变化，请刷新后重试'; return; }
    const generation = context;
    state.busy = true; state.error = '';
    try {
      const result = await post(cancel ? 'cancel-import' : 'confirm-import', { id: batch.id });
      if (!alive || generation !== context) return;
      state.receipt = result.message || (cancel ? '批次已取消，可修正文件后重新上传；已发布的原始数据保留。' : '批次已确认，处理进度请刷新批次查看。'); state.confirmation = '';
      await openBatch(batch); await loadBatches(state.batchPagination.page); await load();
    } catch (error) { if (alive && generation === context) state.error = error.message; }
    finally { if (alive && generation === context) state.busy = false; }
  }
  watch(() => state.retainReason, () => { if (state.confirmation === 'retain-approved') state.confirmation = ''; });
  watch(() => state.classification, () => { state.supplement = { category_id: '', graduation_cohort_id: '' }; state.confirmation = ''; }, { flush: 'sync' });
  watch(() => state.supplement.category_id, () => { state.supplement.graduation_cohort_id = ''; }, { flush: 'sync' });
  watch(() => props.sessionKey, async () => {
    context++; requests.clear(); state.detail = null; state.batch = null; state.rows = []; state.batches = []; state.error = ''; state.receipt = ''; state.busy = false; state.detailLoading = false;
    state.options = {}; state.classification = ''; state.supplement = { category_id: '', graduation_cohort_id: '' }; state.change = null; state.confirmation = ''; state.retainReason = ''; state.upload = { academic_year: '', semester: '', file: null }; state.filters = { keyword: '', academic_year: '', semester: '', stage: '', classification: '', owner_dep_id: '' };
    await read('options', () => get('options'), data => { state.options = data; });
    await load();
  }, { immediate: true });
  onBeforeUnmount(() => { alive = false; context++; });
  return { state, types, categories, cohorts, requiresCohort, sourceFields, canAssign, canComplete, canChange, canApply, canReconcile, canRetain, retainReasonReady, assignmentReady, supplementReady, load, openDetail, closeDetail, mutate, loadBatches, openBatch, closeBatch, loadBatchPage, upload, confirmImport };
}
