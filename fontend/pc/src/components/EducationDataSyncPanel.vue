<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { ElMessageBox } from 'element-plus';
import { RefreshCw, Save } from '@lucide/vue';
import FormFieldLabel from '../../../shared/components/FormFieldLabel.vue';
import ListPagination from './ListPagination.vue';
import { request } from '../api/client';

const props = defineProps({
  sessionKey: { type: String, required: true },
  active: { type: Boolean, default: true },
});

const resources = [{ value: 'student', label: '学生' }, { value: 'teacher', label: '教职工' }];
const activeStatuses = ['queued', 'fetching', 'applying', 'retry_wait'];
const statusNames = {
  queued: '等待执行', fetching: '拉取中', applying: '写入中', retry_wait: '等待自动重试',
  completed: '同步完成', completed_with_errors: '完成但有失败项', failed: '任务失败', cancelled: '已取消',
  staged: '已暂存', pending: '待处理', created: '已新增', updated: '已更新', unchanged: '无变化', skipped: '已跳过',
};
const countFields = [
  ['received_count', '已接收'], ['distinct_count', '不同编号'], ['duplicate_count', '重复编号'],
  ['created_count', '新增'], ['updated_count', '更新'], ['unchanged_count', '无变化'],
  ['skipped_count', '跳过'], ['failed_count', '失败'],
];
const editableFields = ['base_url', 'page_size', 'student_policy', 'interval_seconds', 'enabled', 'diagnostics_enabled'];
const emptyPage = () => ({ page: 1, page_size: 20, total: 0 });
const defaultForm = () => ({
  base_url: '', app_id: '', app_key: '', page_size: 100, student_policy: 'active_new',
  interval_seconds: 1, enabled: false, diagnostics_enabled: true,
});
const initialState = () => ({
  tab: 'jobs', settings: null, form: defaultForm(), loadingSettings: false, loadingJobs: false,
  schedule: null, scheduleEnabled: false, loadingSchedule: false, scheduleError: '', scheduleNotice: '',
  loadingDetail: false, loadingItems: false, busy: '', error: '', notice: '', jobsError: '', detailError: '',
  jobs: [], jobsPage: emptyPage(), activeCount: null, selectedJob: null, items: [], itemsPage: emptyPage(), itemStatus: '',
  probes: {}, lastRefreshed: '', filterForm: { resource: 'student', number: '', name: '', mobile: '' }, filteredProbe: null, filterError: '',
});
const state = reactive(initialState());
const pageVisible = ref(typeof document === 'undefined' || document.visibilityState === 'visible');
let generation = 0;
let disposed = false;
let controller = new AbortController();
let pollTimer = null;
let polling = false;
let jobsSequence = 0;
let detailSequence = 0;
let itemsSequence = 0;
let scheduleSequence = 0;
let scheduleEditVersion = 0;
let filteredSequence = 0;
const requestIds = new Map();

const dirty = computed(() => Boolean(state.settings) && (
  editableFields.some(key => state.form[key] !== state.settings[key])
  || Boolean(state.form.app_id || state.form.app_key)
));
const hasActiveJob = computed(() => state.activeCount > 0 || state.jobs.some(isActive) || isActive(state.selectedJob));
const pendingRequest = computed(() => state.busy !== '' || state.loadingSettings);
const scheduleDirty = computed(() => Boolean(state.schedule) && state.scheduleEnabled !== state.schedule.enabled);
const scheduleNeedsTakeover = computed(() => state.schedule?.enabled === false
  && (state.schedule.blocked_code === 'actor_revoked'
    || (state.schedule.owner?.configured && state.schedule.owner.active === false)));
const scheduleEnableBlockedReason = computed(() => {
  if (!state.settings) return '请先读取连接配置。';
  if (dirty.value) return '连接配置有未保存的修改，请先保存配置。';
  if (!state.settings.ready || !state.settings.enabled) return state.settings.blocked_reason || '请先完成连接配置并启用同步。';
  if (scheduleNeedsTakeover.value) return '';
  return state.schedule?.blocked_reason || '';
});
const canSaveSchedule = computed(() => Boolean(state.schedule) && scheduleDirty.value && !pendingRequest.value
  && !state.loadingSchedule && (!state.scheduleEnabled || !scheduleEnableBlockedReason.value));
const scheduleStatus = computed(() => {
  if (!state.schedule) return '定时设置未读取';
  if (!state.schedule.enabled) return '已停用';
  return state.schedule.blocked_reason ? '已启用，等待解除阻塞' : '已启用';
});
const canRun = computed(() => Boolean(state.settings?.ready && state.settings?.enabled) && !dirty.value && !pendingRequest.value);
const filteredFilters = computed(() => Object.fromEntries([
  [state.filterForm.resource === 'student' ? 'xh' : 'jzgh', state.filterForm.number.trim()],
  ['xm', state.filterForm.name.trim()], ['sjhm', state.filterForm.mobile.trim()],
].filter(([, value]) => value)));
const hasFilteredConditions = computed(() => Object.keys(filteredFilters.value).length > 0);
const canStartFiltered = computed(() => canRun.value && !hasActiveJob.value && hasFilteredConditions.value
  && state.filteredProbe?.total > 0 && state.filteredProbe.configRevision === state.settings.revision);
const runBlockedReason = computed(() => {
  if (!state.settings) return '请先读取并保存同步配置。';
  if (dirty.value) return '配置有未保存的修改，请保存后继续。';
  if (!state.settings.ready || !state.settings.enabled) return state.settings.blocked_reason || '请完成配置并启用同步。';
  if (pendingRequest.value) return '正在处理操作，请稍候。';
  return '';
});
const nextStep = computed(() => {
  if (!state.settings || dirty.value || !state.settings.ready) return '配置';
  if (hasActiveJob.value) return '查看任务进度';
  if (state.selectedJob?.status === 'completed_with_errors' || state.selectedJob?.status === 'failed') return '排查失败项';
  if (state.selectedJob?.status === 'completed') return state.settings.diagnostics_enabled && state.selectedJob.scope !== 'filtered' ? '核对结果并确认联调' : '查看同步结果';
  return '检查连接并发起同步';
});
const canValidate = computed(() => state.selectedJob?.status === 'completed'
  && state.selectedJob.scope !== 'filtered'
  && Number(state.selectedJob.failed_count) === 0 && state.settings?.diagnostics_enabled
  && Number(state.selectedJob.config_revision) === Number(state.settings.revision)
  && state.activeCount === 0 && !hasActiveJob.value && !state.loadingJobs && !state.jobsError && !dirty.value && !pendingRequest.value);
const writingPhase = computed(() => state.selectedJob?.phase === 'apply' || state.selectedJob?.status === 'applying');
const progress = computed(() => {
  const job = state.selectedJob;
  if (!job) return null;
  if (['stage_wait', 'verify'].includes(job.phase)) return null;
  const denominator = Number(writingPhase.value ? job.distinct_count : job.remote_total);
  if (!denominator) return null;
  const processed = ['created_count', 'updated_count', 'unchanged_count', 'skipped_count', 'failed_count']
    .reduce((sum, key) => sum + Number(job[key] || 0), 0);
  return Math.min(100, Math.round(100 * (writingPhase.value ? processed : Number(job.received_count || 0)) / denominator));
});

function isActive(job) { return activeStatuses.includes(job?.status); }
function isScheduledJob(job) { return String(job?.request_id || '').startsWith('daily.'); }
function jobScopeLabel(job) { return isScheduledJob(job) ? '定时 · 全量' : (job?.scope === 'filtered' ? '按条件' : '全量'); }
function phaseLabel(value) { return { fetch: '拉取', fetching: '拉取', stage_wait: '等待页面消费', verify: '校验', apply: '档案写入', applying: '档案写入' }[value] || value || '—'; }
function scheduleTime(value) {
  if (!value) return '—';
  if (!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(value)) return value;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false });
}
function resourceLabel(value) { return resources.find(item => item.value === value)?.label || '未知资源'; }
function statusLabel(value) { return statusNames[value] || value || '—'; }
function statusType(value) {
  if (['failed', 'completed_with_errors'].includes(value)) return 'danger';
  if (value === 'completed') return 'success';
  if (['retry_wait', 'skipped'].includes(value)) return 'warning';
  return 'info';
}
function numberText(value) { return value === null || value === undefined ? '—' : Number(value).toLocaleString('zh-CN'); }
function isCurrent(token) { return !disposed && token === generation; }
function showError(error, key = 'error') { state[key] = error?.message || '请求失败，请稍后重试。'; }
function api(path, body, token = generation) {
  return request(`/education-sync/${path}`, {
    ...(body === undefined ? {} : { method: 'POST', body: JSON.stringify(body) }),
    signal: controller.signal, isCurrent: () => isCurrent(token),
  });
}
function applySettings(settings) {
  state.settings = settings;
  state.form = { ...defaultForm(), ...Object.fromEntries(editableFields.map(key => [key, settings[key]])) };
  state.probes = {};
  state.filteredProbe = null;
  filteredSequence++;
}
function applySchedule(schedule) {
  state.schedule = schedule;
  state.scheduleEnabled = schedule.enabled;
}
function pagination(data, page) { return { ...emptyPage(), ...(data || {}), page }; }

async function loadSettings() {
  if (state.loadingSettings || state.busy) return;
  const token = generation;
  state.loadingSettings = true;
  state.error = '';
  try {
    const settings = await api('settings', undefined, token);
    if (isCurrent(token)) applySettings(settings);
  } catch (error) {
    if (isCurrent(token)) showError(error);
  } finally {
    if (isCurrent(token)) state.loadingSettings = false;
  }
}

async function reloadSettings() {
  const token = generation;
  if (dirty.value) {
    try { await ElMessageBox.confirm('重新读取会放弃尚未保存的配置修改。', '重新读取配置', { confirmButtonText: '放弃修改并读取', cancelButtonText: '返回' }); }
    catch { return; }
  }
  if (isCurrent(token)) await Promise.all([loadSettings(), loadSchedule(true)]);
}

async function loadSchedule(quiet = false) {
  if (state.loadingSchedule || state.busy === 'schedule' || (quiet && scheduleDirty.value)) return;
  const token = generation;
  const sequence = ++scheduleSequence;
  const editVersion = scheduleEditVersion;
  state.loadingSchedule = !quiet;
  if (!quiet) state.scheduleError = '';
  try {
    const data = await api('schedule', undefined, token);
    if (!isCurrent(token) || sequence !== scheduleSequence || editVersion !== scheduleEditVersion) return;
    applySchedule(data.schedule);
    state.scheduleError = '';
  } catch (error) {
    if (isCurrent(token) && sequence === scheduleSequence) showError(error, 'scheduleError');
  } finally {
    if (isCurrent(token) && sequence === scheduleSequence) state.loadingSchedule = false;
  }
}

async function reloadSchedule() {
  if (pendingRequest.value || state.loadingSchedule) return;
  const token = generation;
  if (scheduleDirty.value) {
    try { await ElMessageBox.confirm('重新读取会放弃尚未保存的定时设置修改。', '重新读取定时设置', { confirmButtonText: '放弃修改并读取', cancelButtonText: '返回' }); }
    catch { return; }
  }
  if (isCurrent(token)) await loadSchedule();
}

function scheduleEdited() {
  scheduleEditVersion++;
  state.scheduleNotice = '';
}

async function saveSchedule() {
  if (!canSaveSchedule.value) return;
  const token = generation;
  scheduleSequence++;
  state.busy = 'schedule';
  state.scheduleError = '';
  state.scheduleNotice = '';
  try {
    const data = await api('schedule', { revision: Number(state.schedule.revision), enabled: state.scheduleEnabled }, token);
    if (!isCurrent(token)) return;
    applySchedule(data.schedule);
    state.scheduleNotice = data.schedule.enabled
      ? '定时设置已保存，每日北京时间 03:00 同步本校学生和教职工。'
      : '每日自动同步已停用，定时任务将停止继续执行；手动任务需在任务记录中自行取消。';
  } catch (error) {
    if (isCurrent(token)) showError(error, 'scheduleError');
  } finally {
    if (isCurrent(token)) { state.busy = ''; schedulePoll(); }
  }
}

function validateForm() {
  const form = state.form;
  const url = form.base_url.trim();
  if (url) {
    let parsed;
    try { parsed = new URL(url); } catch { throw new Error('网关基础地址格式不正确。'); }
    if (parsed.protocol !== 'https:' || parsed.username || parsed.password || parsed.search || parsed.hash) {
      throw new Error('请填写不含账号、密码、查询参数或片段的 HTTPS 基础地址。');
    }
  }
  if (!Number.isInteger(form.page_size) || form.page_size < 10 || form.page_size > 500) throw new Error('每页条数须为 10–500 的整数。');
  if (!Number.isInteger(form.interval_seconds) || form.interval_seconds < 1 || form.interval_seconds > 60) throw new Error('请求间隔须为 1–60 秒的整数。');
  for (const key of ['app_id', 'app_key']) {
    if (form[key] && (/[\s\x00-\x1f\x7f]/.test(form[key]) || new TextEncoder().encode(form[key]).length > 512)) {
      throw new Error('应用编号和密钥不允许空白或控制字符，长度最多 512 字节。');
    }
  }
  if (form.enabled && (!url || !(form.app_id && form.app_id !== '******' || state.settings.app_id_configured)
    || !(form.app_key && form.app_key !== '******' || state.settings.app_key_configured))) {
    throw new Error('启用时必须填写 HTTPS 基础地址，并保存完整的应用编号和密钥。');
  }
}

async function saveSettings() {
  if (pendingRequest.value || !state.settings) return;
  await mutate('save', async token => {
    validateForm();
    const body = { ...Object.fromEntries(editableFields.map(key => [key, state.form[key]])), revision: Number(state.settings.revision) };
    body.base_url = body.base_url.trim();
    for (const key of ['app_id', 'app_key']) {
      if (state.form[key] && state.form[key] !== '******') body[key] = state.form[key];
    }
    const settings = await api('save-settings', body, token);
    if (!isCurrent(token)) return;
    applySettings(settings);
    state.notice = '配置已保存。地址、凭据或范围变更后，已有任务不能继续使用旧配置。';
    await loadSchedule(true);
  });
}

async function clearCredentials() {
  if (pendingRequest.value || !state.settings) return;
  const token = generation;
  try {
    await ElMessageBox.confirm('此操作立即停用同步，并清空已保存的应用编号和密钥。未保存的表单修改不会写入。', '清空同步凭据', {
      type: 'warning', confirmButtonText: '停用并清空', cancelButtonText: '返回',
    });
  } catch { return; }
  if (!isCurrent(token)) return;
  await mutate('clear', async current => {
    const settings = await api('save-settings', { revision: Number(state.settings.revision), enabled: false, clear_credentials: true }, current);
    if (isCurrent(current)) {
      applySettings(settings);
      state.notice = '同步已停用，应用编号和密钥已清空。';
      await loadSchedule(true);
    }
  });
}

async function mutate(name, action) {
  if (pendingRequest.value) return;
  const token = generation;
  state.busy = name;
  state.error = '';
  state.notice = '';
  try { await action(token); }
  catch (error) { if (isCurrent(token)) showError(error); }
  finally { if (isCurrent(token)) { state.busy = ''; schedulePoll(); } }
}

async function probe(resource) {
  if (!canRun.value) return;
  await mutate(`probe:${resource}`, async token => {
    delete state.probes[resource];
    try {
      const data = await api('probe', { resource }, token);
      if (isCurrent(token)) state.probes[resource] = data;
    } catch (error) {
      if (isCurrent(token)) state.probes[resource] = { success: false, message: error.message, diagnostics: safeProbeDiagnostics(error.payload?.data?.diagnostics) };
      throw error;
    }
  });
}

async function probeFiltered() {
  if (!canRun.value || hasActiveJob.value || !hasFilteredConditions.value) return;
  const resource = state.filterForm.resource;
  const filters = { ...filteredFilters.value };
  const configRevision = state.settings.revision;
  const sequence = ++filteredSequence;
  state.filteredProbe = null;
  state.filterError = '';
  await mutate('probe:filtered', async token => {
    try {
      const data = await api('probe', { resource, filters }, token);
      if (!isCurrent(token) || sequence !== filteredSequence) return;
      if (data.scope !== 'filtered') throw new Error('后端尚未支持按条件同步，请更新并重启后端后重新检查。');
      state.filteredProbe = { total: Number(data.pagination.total), recordCount: data.record_count, configRevision };
    } catch (error) {
      if (isCurrent(token) && sequence === filteredSequence) showError(error, 'filterError');
    }
  });
}

async function startFiltered() {
  if (!canStartFiltered.value) return;
  const token = generation;
  const resource = state.filterForm.resource;
  const filters = { ...filteredFilters.value };
  const checked = state.filteredProbe;
  const sequence = filteredSequence;
  try {
    await ElMessageBox.confirm(`当前条件检查匹配 ${numberText(checked.total)} 条${resourceLabel(resource)}，将按这些条件重新拉取并同步档案。来源数据可能变化，实际匹配数量以任务结果为准。仍按学号或工号匹配档案、跳过管理员；保留已有密码、角色及绑定，不删除来源中缺失的记录，新档案不自动开户。`, '按条件同步', {
      confirmButtonText: '按条件同步', cancelButtonText: '返回', type: 'warning',
    });
  } catch { return; }
  if (!isCurrent(token) || sequence !== filteredSequence || state.filteredProbe !== checked || !canStartFiltered.value) return;
  await mutate('start:filtered', async current => {
    const submissionKey = `start:filtered:${sequence}:${checked.configRevision}`;
    const data = await api('start', { resource, filters, request_id: requestId(submissionKey) }, current);
    if (!isCurrent(current)) return;
    requestIds.delete(submissionKey);
    state.filteredProbe = null;
    state.notice = '按条件同步任务已提交，将按本次条件拉取、进入页面队列并写入档案；实际匹配数量请查看任务结果。';
    await loadJobs(1);
    if (isCurrent(current)) await selectJob(data.job);
  });
}

const probeDiagnosticLabels = { stage: '执行阶段', error: '错误分类', exception_type: '异常类型', exception_file: '代码文件', exception_line: '行号', redis_extension_loaded: 'PHP Redis 扩展（1为已加载）', curl_errno: 'HTTP 传输错误码', http_status: 'HTTP 状态', business_code: '业务码', remote_current: '返回页码', remote_size: '返回每页条数', remote_total: '返回总量', remote_pages: '返回页数', sqlstate: 'SQLSTATE', driver_code: '数据库错误码' };
function safeProbeDiagnostics(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  return Object.fromEntries(Object.keys(probeDiagnosticLabels).filter(key => (typeof value[key] === 'number' && Number.isFinite(value[key])) || (typeof value[key] === 'string' && /^[a-zA-Z0-9_.:\\-]{1,200}$/.test(value[key]))).map(key => [key, value[key]]));
}

function requestId(key) {
  if (!requestIds.has(key)) {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    requestIds.set(key, Array.from(bytes, value => value.toString(16).padStart(2, '0')).join(''));
  }
  return requestIds.get(key);
}

async function startJob(resource, previousJob = null) {
  if (!canRun.value || hasActiveJob.value) return;
  const token = generation;
  const filteredRetry = previousJob?.scope === 'filtered';
  const action = previousJob ? (filteredRetry ? '按原条件重新拉取' : '重新完整拉取') : '发起同步';
  try {
    await ElMessageBox.confirm(`${resourceLabel(resource)}按学号或工号新增、更新基础档案，跳过管理员；保留已有密码、角色及账号绑定，不删除来源中缺失的记录，新档案不会自动开户。${previousJob ? (filteredRetry ? '将使用原任务的查询条件创建新任务，从第一页重新拉取；条件不可用时会报错，实际匹配数量以新任务结果为准。' : '将创建新任务从第一页拉取。') : ''}`, action, {
      confirmButtonText: action, cancelButtonText: '返回', type: 'warning',
    });
  } catch { return; }
  if (!isCurrent(token) || !canRun.value || hasActiveJob.value) return;
  const key = previousJob ? `retry:${previousJob.id}` : `start:${resource}`;
  await mutate(key, async current => {
    const submissionKey = `${key}:${state.settings.revision}`;
    const data = await api(previousJob ? 'retry' : 'start', {
      ...(previousJob ? { job_id: previousJob.id } : { resource }), request_id: requestId(submissionKey),
    }, current);
    if (!isCurrent(current)) return;
    requestIds.delete(submissionKey);
    state.tab = 'jobs';
    state.notice = `${filteredRetry ? '按原条件重新拉取的' : ''}任务已提交，将依次拉取、进入页面队列、写入档案，请核对最终结果。`;
    await loadJobs(1);
    if (isCurrent(current)) await selectJob(data.job);
  });
}

async function cancelJob(job) {
  if (pendingRequest.value || !isActive(job)) return;
  const token = generation;
  try {
    await ElMessageBox.confirm('取消后停止后续处理，已写入的档案不会回滚。', '取消同步任务', { confirmButtonText: '取消任务', cancelButtonText: '继续执行', type: 'warning' });
  } catch { return; }
  if (!isCurrent(token)) return;
  await mutate(`cancel:${job.id}`, async current => {
    const data = await api('cancel', { job_id: job.id }, current);
    if (!isCurrent(current)) return;
    if (state.selectedJob?.id === job.id) state.selectedJob = data.job;
    state.notice = '已提交取消，请核对任务的最终状态。';
    await loadJobs(state.jobsPage.page);
  });
}

async function confirmValidated() {
  if (!canValidate.value) return;
  const token = generation;
  const jobId = state.selectedJob.id;
  try {
    await ElMessageBox.confirm(`请确认已在部署服务器核对任务 #${jobId} 的总量、跳过管理员、重复编号、变更计数，并重跑核对幂等。所有同步任务结束后才可关闭诊断，常规任务及异常日志继续保留。连接检查通过不能代替完整联调。`, '确认服务器联调并关闭诊断', {
      confirmButtonText: '已核对，关闭诊断', cancelButtonText: '尚未完成核对', type: 'warning',
    });
  } catch { return; }
  if (!isCurrent(token) || !canValidate.value || state.selectedJob.id !== jobId) return;
  await mutate('validate', async current => {
    await loadJobs(1, true);
    if (!isCurrent(current)) return;
    if (state.jobsError || state.activeCount === null) throw new Error('无法确认当前任务状态，请刷新任务记录后重试。');
    if (hasActiveJob.value) throw new Error('仍有同步任务运行，请等待所有任务结束后再确认联调。');
    const settings = await api('confirm-validated', { job_id: jobId, revision: Number(state.settings.revision) }, current);
    if (isCurrent(current)) { applySettings(settings); state.notice = '已记录管理员确认并关闭诊断日志，常规任务和异常日志继续保留。'; }
  });
}

async function loadJobs(page = state.jobsPage.page, quiet = false) {
  const token = generation;
  const sequence = ++jobsSequence;
  state.loadingJobs = !quiet;
  state.jobsError = '';
  try {
    const data = await api(`jobs?page=${page}&page_size=20`, undefined, token);
    if (!isCurrent(token) || sequence !== jobsSequence) return;
    state.jobs = data.items || [];
    state.jobsPage = pagination(data.pagination, page);
    state.activeCount = Number.isInteger(data.active_count) && data.active_count >= 0 ? data.active_count : null;
    state.lastRefreshed = new Date().toLocaleTimeString('zh-CN', { hour12: false });
  } catch (error) {
    if (isCurrent(token) && sequence === jobsSequence) showError(error, 'jobsError');
  } finally {
    if (isCurrent(token) && sequence === jobsSequence) { state.loadingJobs = false; schedulePoll(); }
  }
}

async function selectJob(job) {
  if (!job) return;
  state.selectedJob = job;
  state.items = [];
  state.itemsPage = emptyPage();
  state.itemStatus = '';
  itemsSequence++;
  await loadDetail();
}

async function loadDetail(quiet = false) {
  if (!state.selectedJob) return;
  const token = generation;
  const sequence = ++detailSequence;
  const itemsVersion = itemsSequence;
  const jobId = state.selectedJob.id;
  state.loadingDetail = !quiet;
  state.detailError = '';
  try {
    const data = await api(`detail?job_id=${jobId}`, undefined, token);
    if (!isCurrent(token) || sequence !== detailSequence || jobId !== state.selectedJob?.id) return;
    state.selectedJob = data.job;
    if (itemsVersion === itemsSequence) await loadItems(state.itemsPage.page, quiet);
  } catch (error) {
    if (isCurrent(token) && sequence === detailSequence) showError(error, 'detailError');
  } finally {
    if (isCurrent(token) && sequence === detailSequence) state.loadingDetail = false;
  }
}

async function loadItems(page = 1, quiet = false) {
  if (!state.selectedJob) return;
  const token = generation;
  const sequence = ++itemsSequence;
  const jobId = state.selectedJob.id;
  state.loadingItems = !quiet;
  if (!quiet) state.items = [];
  state.detailError = '';
  try {
    const query = new URLSearchParams({ job_id: String(jobId), page: String(page), page_size: '20', status: state.itemStatus });
    const data = await api(`items?${query}`, undefined, token);
    if (!isCurrent(token) || sequence !== itemsSequence || jobId !== state.selectedJob?.id) return;
    state.items = data.items || [];
    state.itemsPage = pagination(data.pagination, page);
  } catch (error) {
    if (isCurrent(token) && sequence === itemsSequence) showError(error, 'detailError');
  } finally {
    if (isCurrent(token) && sequence === itemsSequence) state.loadingItems = false;
  }
}

function stopPoll() { window.clearTimeout(pollTimer); pollTimer = null; }
function schedulePoll() {
  stopPoll();
  if (disposed || polling || !props.active || !pageVisible.value || (!hasActiveJob.value && !state.schedule?.enabled) || state.tab !== 'jobs') return;
  const token = generation;
  pollTimer = window.setTimeout(async () => {
    if (!isCurrent(token)) return;
    polling = true;
    try {
      await Promise.all([loadJobs(state.jobsPage.page, true), loadSchedule(true)]);
      if (isCurrent(token) && props.active && pageVisible.value && state.tab === 'jobs' && state.selectedJob) await loadDetail(true);
    } finally {
      if (isCurrent(token)) { polling = false; schedulePoll(); }
    }
  }, hasActiveJob.value ? 5000 : 30000);
}
function visibilityChanged() { pageVisible.value = document.visibilityState === 'visible'; schedulePoll(); }
watch(() => [props.active, state.tab, hasActiveJob.value, state.schedule?.enabled], schedulePoll);
watch(() => [state.filterForm.resource, state.filterForm.number, state.filterForm.name, state.filterForm.mobile], () => {
  filteredSequence++;
  state.filteredProbe = null;
  state.filterError = '';
}, { flush: 'sync' });
watch(() => props.sessionKey, async () => {
  generation++;
  controller.abort();
  controller = new AbortController();
  stopPoll();
  polling = false;
  jobsSequence++;
  detailSequence++;
  itemsSequence++;
  scheduleSequence++;
  scheduleEditVersion++;
  filteredSequence++;
  requestIds.clear();
  Object.assign(state, initialState());
  if (!props.sessionKey) return;
  await Promise.all([loadSettings(), loadSchedule(), loadJobs(1)]);
}, { immediate: true });
onMounted(() => document.addEventListener('visibilitychange', visibilityChanged));
onBeforeUnmount(() => {
  disposed = true;
  generation++;
  controller.abort();
  stopPoll();
  requestIds.clear();
  state.form.app_id = '';
  state.form.app_key = '';
  document.removeEventListener('visibilitychange', visibilityChanged);
});
</script>

<template>
  <section class="education-data-sync" aria-label="教务数据同步">
    <header class="sync-header">
      <div><h2>教务数据同步</h2><p>当前学校的学生与教职工档案，按编号同步。</p></div>
      <el-tag :type="state.settings?.ready ? 'success' : 'warning'">{{ state.settings ? (state.settings.ready ? '已就绪' : '未就绪') : '配置未读取' }}</el-tag>
    </header>
    <div class="sync-current-step" aria-live="polite">
      <strong>当前步骤：{{ nextStep }}</strong>
      <span v-if="runBlockedReason">{{ runBlockedReason }}</span>
      <span v-else-if="hasActiveJob">等待任务写入完成后核对结果；拉取完成不代表同步成功。</span>
      <span v-else>先检查连接，再发起同步；完成后查看结果明细。</span>
      <el-button v-if="!state.settings?.ready || dirty" link type="primary" @click="state.tab = 'settings'">打开连接配置</el-button>
      <el-button v-else-if="state.tab !== 'jobs'" link type="primary" @click="state.tab = 'jobs'">打开同步任务</el-button>
    </div>
    <el-alert v-if="state.error" :title="state.error" type="error" :closable="false" show-icon />
    <el-alert v-if="state.notice" :title="state.notice" type="success" :closable="false" />

    <el-tabs v-model="state.tab">
      <el-tab-pane label="同步任务" name="jobs" class="sync-jobs-pane">
        <section class="sync-section sync-schedule" aria-label="每日自动同步" :aria-busy="state.loadingSchedule || state.busy === 'schedule'">
          <div class="sync-section-heading">
            <div><h3>每日自动同步</h3><span>固定北京时间 03:00（Asia/Shanghai），同步当前学校的学生和教职工。</span></div>
            <el-button :icon="RefreshCw" :loading="state.loadingSchedule" :disabled="pendingRequest || state.loadingSchedule" @click="reloadSchedule">重新读取定时设置</el-button>
          </div>
          <el-alert v-if="state.scheduleError" :title="state.scheduleError" type="error" :closable="false" show-icon />
          <el-alert v-if="state.scheduleNotice" :title="state.scheduleNotice" type="success" :closable="false" />
          <div class="sync-schedule-fields">
            <label class="sync-schedule-toggle">
              <FormFieldLabel :required="true" help="仅对当前学校生效；切换后须保存定时设置。">自动同步开关</FormFieldLabel>
              <el-switch v-model="state.scheduleEnabled" :disabled="pendingRequest || state.loadingSchedule || !state.schedule || (!state.scheduleEnabled && Boolean(scheduleEnableBlockedReason))" active-text="启用" inactive-text="停用" @change="scheduleEdited" />
            </label>
            <dl class="sync-schedule-meta" aria-live="polite">
              <div><dt>已保存状态</dt><dd>{{ scheduleStatus }}</dd></div>
              <div><dt>下次执行（北京时间）</dt><dd>{{ state.schedule ? (state.schedule.next_run_at ? scheduleTime(state.schedule.next_run_at) : (state.schedule.enabled ? '等待调度' : '未启用')) : '尚未读取' }}</dd></div>
            </dl>
          </div>
          <p v-if="state.schedule?.blocked_reason || scheduleEnableBlockedReason || scheduleNeedsTakeover" class="sync-help" role="status">
            <span v-if="state.schedule?.blocked_reason">定时阻塞原因：{{ state.schedule.blocked_reason }}</span>
            <span v-if="scheduleEnableBlockedReason && scheduleEnableBlockedReason !== state.schedule?.blocked_reason">开启受限：{{ scheduleEnableBlockedReason }}</span>
            <span v-if="scheduleNeedsTakeover">重新启用并保存后，将由当前管理员接管定时设置。</span>
            <el-button v-if="!state.settings?.ready || !state.settings?.enabled || dirty" link type="primary" @click="state.tab = 'settings'">打开连接配置</el-button>
          </p>
          <p class="sync-help">仅超级管理员和学校管理员维护；每次定时执行前重新校验启用管理员的权限及已保存的连接配置。停用后定时任务将停止继续执行；手动任务需在下方任务记录中自行取消。</p>
          <div class="sync-actions">
            <el-button type="primary" :icon="Save" :disabled="!canSaveSchedule" :loading="state.busy === 'schedule'" @click="saveSchedule">保存定时设置</el-button>
            <span class="sync-help">{{ scheduleDirty ? '定时设置有未保存的修改；下次执行时间仍按已保存设置显示。' : `定时版本 ${state.schedule?.revision ?? '—'}；连接配置版本 ${state.schedule?.source_config_revision ?? '—'}` }}</span>
          </div>
        </section>

        <section class="sync-section">
          <div class="sync-section-heading"><h3>人员同步</h3><span>教务计划、课表：等待接口文档，暂未接入。</span></div>
          <p class="sync-help">按学号、工号增改；跳过管理员，保留密码、角色及绑定。不删除来源中缺失的记录，新档案不自动开户。</p>
          <p class="sync-help">拉取 → 页面队列 → 档案写入。页面消费完成后才核对最终结果，拉取完成不代表同步成功。</p>
          <div v-for="resource in resources" :key="resource.value" class="sync-resource-row">
            <strong>{{ resource.label }}</strong>
            <span v-if="resource.value === 'student'">{{ { active_new: '新增仅在校；已有档案继续同步学籍，不自动停用登录', all: '全部历史学生', existing: '仅更新已有学生档案' }[state.settings?.student_policy] || '请先读取配置' }}</span>
            <span v-else>按工号匹配；管理员整条跳过</span>
            <div class="sync-actions">
              <el-button :disabled="!canRun || hasActiveJob" :loading="state.busy === `probe:${resource.value}`" @click="probe(resource.value)">检查连接</el-button>
              <el-button type="primary" :disabled="!canRun || hasActiveJob" :loading="state.busy === `start:${resource.value}`" @click="startJob(resource.value)">同步{{ resource.label }}</el-button>
            </div>
          </div>
          <p class="sync-help">连接检查由服务器只请求第一页，不入库。{{ hasActiveJob ? '当前有任务运行，完成或取消后可再次检查或发起。' : runBlockedReason }}</p>
          <div v-for="resource in resources.filter(item => state.probes[item.value])" :key="resource.value" class="sync-probe-result" role="status">
            <strong>{{ resource.label }}连接检查：{{ state.probes[resource.value].success ? '通过' : '未通过' }}</strong>
            <template v-if="state.probes[resource.value].success">
            <span>第一页 {{ numberText(state.probes[resource.value].record_count) }} 条</span>
            <span>远端总量 {{ numberText(state.probes[resource.value].pagination?.total) }} 条 / {{ numberText(state.probes[resource.value].pagination?.pages) }} 页</span>
            <span>HTTP {{ numberText(state.probes[resource.value].diagnostics?.http_status) }}，业务码 {{ numberText(state.probes[resource.value].diagnostics?.business_code) }}，耗时 {{ numberText(state.probes[resource.value].diagnostics?.duration_ms) }} ms</span>
            </template>
            <template v-else><span v-for="(value, key) in state.probes[resource.value].diagnostics" :key="key">{{ probeDiagnosticLabels[key] }}：{{ value }}</span></template>
            <small>{{ state.probes[resource.value].message || '连接检查不代表完整同步和服务器联调已完成。' }}</small>
          </div>
        </section>

        <section class="sync-section" aria-label="按条件同步">
          <div class="sync-section-heading"><h3>按条件同步</h3><span>至少填写一个条件；先检查匹配数量，再发起同步。</span></div>
          <p class="sync-help">单人同步请优先填写完整学号或工号。检查仅从服务器读取第一页并显示数量，不写入档案，不展示人员原始资料。</p>
          <el-form class="sync-form" :disabled="state.busy === 'start:filtered'" @submit.prevent>
            <label><FormFieldLabel :required="true" help="切换资源后清空编号和检查结果。">同步对象</FormFieldLabel><el-select v-model="state.filterForm.resource" @change="state.filterForm.number = ''">
              <el-option v-for="resource in resources" :key="resource.value" :value="resource.value" :label="resource.label" />
            </el-select></label>
            <label><FormFieldLabel :required="false" help="单人同步请填写完整编号，最多 80 字；与其他条件至少填写一项。">{{ state.filterForm.resource === 'student' ? '学号' : '工号' }}</FormFieldLabel><el-input v-model="state.filterForm.number" maxlength="80" clearable autocomplete="off" :placeholder="state.filterForm.resource === 'student' ? '请输入完整学号' : '请输入完整工号'" /></label>
            <label><FormFieldLabel :required="false" help="最多 80 字；可能匹配多人，请先检查数量。">姓名</FormFieldLabel><el-input v-model="state.filterForm.name" maxlength="80" clearable autocomplete="off" placeholder="按姓名筛选" /></label>
            <label><FormFieldLabel :required="false" help="按来源登记值填写，最多 40 字。">手机号</FormFieldLabel><el-input v-model="state.filterForm.mobile" maxlength="40" clearable autocomplete="off" placeholder="按手机号筛选" /></label>
          </el-form>
          <el-alert v-if="state.filterError" :title="state.filterError" type="error" :closable="false" show-icon />
          <div v-if="state.filteredProbe" class="sync-probe-result" role="status">
            <strong>匹配 {{ numberText(state.filteredProbe.total) }} 条{{ resourceLabel(state.filterForm.resource) }}</strong>
            <span>本次检查读取第一页 {{ numberText(state.filteredProbe.recordCount) }} 条，未写入档案。</span>
            <small v-if="state.filteredProbe.total === 0">没有匹配记录；请调整条件后重新检查，当前不能发起同步。</small>
            <small v-else-if="state.filteredProbe.total === 1">当前匹配 1 条；确认条件后可发起按条件同步。检查结果不冻结来源数据，实际数量以任务结果为准。</small>
            <small v-else>当前匹配多条记录，发起后将同步符合条件的全部记录。需要单人同步时请填写完整编号后重新检查；实际数量以任务结果为准。</small>
          </div>
          <p class="sync-help" aria-live="polite">
            <template v-if="runBlockedReason">{{ runBlockedReason }}<el-button v-if="!state.settings?.ready || !state.settings?.enabled || dirty" link type="primary" @click="state.tab = 'settings'">打开连接配置</el-button></template>
            <template v-else-if="hasActiveJob">当前有任务运行；完成或取消后可检查条件并发起同步。</template>
            <template v-else-if="!hasFilteredConditions">当前步骤：填写条件。下一步：点击“按条件检查”，不填写条件不会发起全量同步。</template>
            <template v-else-if="!state.filteredProbe">当前步骤：等待检查。下一步：点击“按条件检查”读取匹配数量。</template>
            <template v-else-if="state.filteredProbe.total > 0">当前步骤：已检查。下一步：核对数量后点击“按条件同步”。</template>
          </p>
          <div class="sync-actions">
            <el-button :disabled="!canRun || hasActiveJob || !hasFilteredConditions" :loading="state.busy === 'probe:filtered'" @click="probeFiltered">按条件检查</el-button>
            <el-button type="primary" :disabled="!canStartFiltered" :loading="state.busy === 'start:filtered'" @click="startFiltered">按条件同步</el-button>
          </div>
        </section>

        <section class="sync-section">
          <div class="sync-section-heading">
            <div><h3>任务记录</h3><span>{{ state.lastRefreshed ? `最近读取 ${state.lastRefreshed}` : '尚未读取任务' }}{{ hasActiveJob ? '；当前页面可见时每 5 秒刷新' : '' }}</span></div>
            <el-button :icon="RefreshCw" :loading="state.loadingJobs" @click="loadJobs()">刷新任务</el-button>
          </div>
          <el-alert v-if="state.jobsError" :title="state.jobsError" type="error" :closable="false" />
          <el-table :data="state.jobs" v-loading="state.loadingJobs" size="small" row-key="id" empty-text="尚无同步任务；完成连接配置后发起学生或教职工同步。" width-storage-key="education-data-sync:jobs">
            <el-table-column prop="id" label="任务 ID" width="100" />
            <el-table-column label="资源" width="110"><template #default="{ row }"><div>{{ resourceLabel(row.resource_type) }}</div><span class="sync-trigger">{{ jobScopeLabel(row) }}</span></template></el-table-column>
            <el-table-column label="状态" min-width="155"><template #default="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
            <el-table-column label="接收 / 远端总量" min-width="150" align="right"><template #default="{ row }">{{ numberText(row.received_count) }} / {{ numberText(row.remote_total) }}</template></el-table-column>
            <el-table-column label="失败" width="75" align="right"><template #default="{ row }">{{ numberText(row.failed_count) }}</template></el-table-column>
            <el-table-column prop="created_at" label="创建时间" min-width="170" />
            <el-table-column label="操作" width="240" fixed="right"><template #default="{ row }">
              <el-button link type="primary" @click="selectJob(row)">查看结果</el-button>
              <el-button v-if="isActive(row)" link type="danger" :disabled="pendingRequest" @click="cancelJob(row)">取消</el-button>
              <el-button v-else-if="['failed', 'completed_with_errors', 'cancelled'].includes(row.status)" link type="primary" :disabled="!canRun || hasActiveJob" @click="startJob(row.resource_type, row)">{{ row.scope === 'filtered' ? '按原条件重新拉取' : '重新拉取' }}</el-button>
            </template></el-table-column>
          </el-table>
          <ListPagination :current-page="state.jobsPage.page" :page-size="20" :total="Number(state.jobsPage.total)" layout="total, prev, pager, next" @current-change="page => loadJobs(page)" />
        </section>

        <section v-if="state.selectedJob" class="sync-section" v-loading="state.loadingDetail">
          <div class="sync-section-heading">
            <div><h3>任务 #{{ state.selectedJob.id }} · {{ resourceLabel(state.selectedJob.resource_type) }} · {{ jobScopeLabel(state.selectedJob) }}</h3><span>{{ statusLabel(state.selectedJob.status) }} · {{ state.selectedJob.uuid }}</span></div>
            <div class="sync-actions"><el-button :icon="RefreshCw" @click="loadDetail()">刷新结果</el-button><el-button v-if="isActive(state.selectedJob)" type="danger" plain :disabled="pendingRequest" @click="cancelJob(state.selectedJob)">取消任务</el-button></div>
          </div>
          <el-alert v-if="state.detailError" :title="state.detailError" type="error" :closable="false" />
          <el-alert v-if="state.selectedJob.error_code || state.selectedJob.error_message" type="error" :closable="false" :title="`${state.selectedJob.error_code || '任务异常'}：${state.selectedJob.error_message || '请根据错误码排查服务器日志。'}`" />
          <div class="sync-job-meta">
            <span>阶段 {{ phaseLabel(state.selectedJob.phase) }}</span>
            <span v-if="state.selectedJob.phase === 'stage_wait'">页面已入队，等待消费后写入档案。</span>
            <span>下一页 {{ numberText(state.selectedJob.next_page) }} / 远端 {{ numberText(state.selectedJob.remote_pages) }} 页</span>
            <span>每页 {{ numberText(state.selectedJob.page_size) }} 条</span>
            <span v-if="state.selectedJob.next_run_at">下次执行 {{ state.selectedJob.next_run_at }}</span>
            <span>开始 {{ state.selectedJob.started_at || '尚未开始' }}</span><span>结束 {{ state.selectedJob.finished_at || '尚未结束' }}</span>
          </div>
          <div v-if="isActive(state.selectedJob) && progress !== null" class="sync-progress"><span>{{ writingPhase ? '写入' : '拉取' }}进度</span><el-progress :percentage="progress" /></div>
          <dl class="sync-counts"><div v-for="[key, label] in countFields" :key="key"><dt>{{ label }}</dt><dd>{{ numberText(state.selectedJob[key]) }}</dd></div></dl>
          <div class="sync-result-toolbar">
            <label><FormFieldLabel :required="false" help="留空显示全部结果。">结果筛选</FormFieldLabel>
              <el-select v-model="state.itemStatus" clearable placeholder="全部结果" @change="loadItems(1)">
                <el-option v-for="status in ['pending', 'created', 'updated', 'unchanged', 'skipped', 'failed']" :key="status" :value="status" :label="statusLabel(status)" />
              </el-select>
            </label>
            <p class="sync-help">仅显示学号或工号、结果原因及变更字段名，不显示个人原始资料。结果明细保留 30 天，历史任务汇总保留。</p>
          </div>
          <el-alert v-if="state.selectedJob.details_expired" type="info" :closable="false" title="本任务已超过结果明细保留期，以下统计仍保留；明细为空不表示本轮没有变更。" />
          <el-table :data="state.items" v-loading="state.loadingItems" row-key="id" size="small" empty-text="当前筛选下没有结果；任务拉取和校验期间可能尚未写入。" width-storage-key="education-data-sync:items">
            <el-table-column prop="business_key" label="学号 / 工号" min-width="160" />
            <el-table-column label="页 / 行" width="85"><template #default="{ row }">{{ row.page_number }} / {{ row.row_number }}</template></el-table-column>
            <el-table-column label="结果" width="105"><template #default="{ row }">{{ statusLabel(row.status) }}</template></el-table-column>
            <el-table-column prop="result_code" label="结果代码" min-width="160" show-overflow-tooltip />
            <el-table-column prop="message" label="原因 / 处理说明" min-width="240" show-overflow-tooltip />
            <el-table-column label="变更字段" min-width="180" show-overflow-tooltip><template #default="{ row }">{{ Array.isArray(row.changed_fields) ? row.changed_fields.join('、') || '—' : '—' }}</template></el-table-column>
            <el-table-column prop="target_id" label="档案 ID" width="95" />
          </el-table>
          <ListPagination :current-page="state.itemsPage.page" :page-size="20" :total="Number(state.itemsPage.total)" layout="total, prev, pager, next" @current-change="page => loadItems(page)" />
          <div class="sync-validation">
            <div><strong>服务器联调确认</strong><p>部署后核对完整分页、管理员跳过、重复编号、变更计数，并重跑核对幂等。所有同步任务结束后，才可使用当前配置版本下“同步完成”且没有失败项的全量任务确认。</p><p v-if="state.selectedJob.scope === 'filtered'">按条件同步不能用于确认全量联调或关闭诊断；请完成全量同步后核对。</p><p v-if="hasActiveJob">仍有任务运行；请等待所有学生和教职工任务结束后再关闭诊断。</p><p v-else-if="state.jobsError || state.activeCount === null">全校任务状态尚未读取成功，请刷新任务记录后再确认。</p><p v-if="state.settings && Number(state.selectedJob.config_revision) !== Number(state.settings.revision)">本任务使用配置版本 {{ state.selectedJob.config_revision }}，当前版本 {{ state.settings.revision }}；需按当前配置重新同步后确认。</p></div>
            <el-button v-if="state.settings?.diagnostics_enabled" :disabled="!canValidate" :loading="state.busy === 'validate'" @click="confirmValidated">确认联调并关闭诊断</el-button>
            <span v-else>诊断已关闭，常规任务及异常日志保留。</span>
          </div>
        </section>
      </el-tab-pane>

      <el-tab-pane label="连接配置" name="settings" class="sync-settings-pane">
        <section class="sync-section sync-settings-body" v-loading="state.loadingSettings">
          <div class="sync-section-heading"><div><h3>学校网关配置</h3><span>仅超级管理员和学校管理员维护；修改后须重新检查连接。</span></div><el-button :icon="RefreshCw" :disabled="pendingRequest" @click="reloadSettings">重新读取</el-button></div>
          <el-alert type="info" :closable="false" title="请求从服务器通过 HTTPS 发出。超级管理员可在学校设置的平台集成中批准网关来源，服务器使用受信任的 CA 验证证书。" />
          <p class="sync-help">服务器批准状态：{{ state.settings ? (state.settings.allowed_origin ? '当前已保存地址获准' : '当前已保存地址尚未获准') : '未读取' }}。学校配置不能自行批准网关。{{ state.settings?.blocked_reason }}</p>
          <el-form :disabled="pendingRequest || !state.settings" class="sync-form" @submit.prevent>
            <label class="sync-form-wide"><FormFieldLabel :submit-required="true" help="启用时必填。包含服务前缀的 HTTPS 基础地址，不含资源路径、查询参数或账号密码。停用时可先保存未完整配置。">网关基础地址</FormFieldLabel><el-input v-model="state.form.base_url" maxlength="500" placeholder="https://网关主机/服务前缀" autocomplete="off" /></label>
            <label><FormFieldLabel :submit-required="true" :help="`启用时须已配置；${state.settings?.app_id_configured ? '已保存，留空保留' : '尚未配置'}。最多 512 字节，不含空白。`">应用编号</FormFieldLabel><el-input v-model="state.form.app_id" type="password" autocomplete="new-password" :placeholder="state.settings?.app_id_configured ? '已配置，留空保持不变' : '请输入应用编号'" /></label>
            <label><FormFieldLabel :submit-required="true" :help="`启用时须已配置；${state.settings?.app_key_configured ? '已保存，留空保留' : '尚未配置'}。最多 512 字节，不含空白。`">应用密钥</FormFieldLabel><el-input v-model="state.form.app_key" type="password" autocomplete="new-password" :placeholder="state.settings?.app_key_configured ? '已配置，留空保持不变' : '请输入应用密钥'" /></label>
            <label class="sync-form-wide"><FormFieldLabel :required="true" help="只影响学生范围；同步学籍不会自动禁用已有登录账号。">学生同步范围</FormFieldLabel><el-select v-model="state.form.student_policy">
              <el-option value="active_new" label="新增仅在校，已有档案继续同步学籍" /><el-option value="all" label="全部历史学生" /><el-option value="existing" label="仅更新已有学生档案" />
            </el-select></label>
            <label><FormFieldLabel :required="true" help="10–500 条，整数；默认 100 条。">每页条数</FormFieldLabel><el-input-number v-model="state.form.page_size" :min="10" :max="500" :step="1" :precision="0" controls-position="right" /></label>
            <label><FormFieldLabel :required="true" help="1–60 秒，整数；同校请求共享间隔，默认 1 秒。">请求间隔（秒）</FormFieldLabel><el-input-number v-model="state.form.interval_seconds" :min="1" :max="60" :step="1" :precision="0" controls-position="right" /></label>
            <label><FormFieldLabel :required="true" help="保存启用时由服务器验证地址、凭据和证书配置。">同步开关</FormFieldLabel><el-switch v-model="state.form.enabled" active-text="启用" inactive-text="停用" /></label>
            <div><FormFieldLabel :required="true" help="联调阶段保留诊断。成功任务核对后，从任务结果执行“确认联调并关闭诊断”；可重新开启排错。">诊断日志</FormFieldLabel><el-switch v-model="state.form.diagnostics_enabled" :disabled="Boolean(state.settings?.diagnostics_enabled)" active-text="开启" inactive-text="关闭" /></div>
          </el-form>
        </section>
        <footer class="sync-config-footer"><div class="sync-actions"><el-button type="primary" :icon="Save" :disabled="pendingRequest || !state.settings" :loading="state.busy === 'save'" @click="saveSettings">保存配置</el-button><span class="sync-help">{{ dirty ? '有未保存的修改' : `配置版本 ${state.settings?.revision ?? '—'}` }}</span></div><el-button type="danger" plain :disabled="pendingRequest || !state.settings || !(state.settings.app_id_configured || state.settings.app_key_configured)" @click="clearCredentials">停用并清空凭据</el-button></footer>
      </el-tab-pane>
    </el-tabs>
  </section>
</template>

<style scoped>
.education-data-sync { display: flex; flex-direction: column; flex: 1 1 0; height: 100%; min-height: 0; overflow: hidden; gap: 12px; min-width: 0; color: var(--text, #202b3a); }
.education-data-sync > .sync-header, .education-data-sync > .sync-current-step, .education-data-sync > .el-alert { flex: 0 0 auto; }
.sync-header, .sync-section-heading, .sync-config-footer, .sync-validation { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.sync-header h2 { margin: 0; font-size: 20px; }
.sync-header p, .sync-help, .sync-section-heading span, .sync-job-meta, .sync-validation p { margin: 5px 0; color: var(--muted, #627083); font-size: 12px; line-height: 1.65; }
.sync-current-step { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; padding: 10px 12px; background: var(--surface-2, #f5f7fa); border-radius: var(--control-radius, 6px); font-size: 13px; }
.sync-current-step > span { color: var(--muted, #627083); }
.sync-section { display: grid; gap: 12px; padding: 16px 0; min-width: 0; border-bottom: 1px solid var(--line, #dce2e9); }
.sync-section:first-child { padding-top: 4px; }
.sync-section h3 { margin: 0; font-size: 15px; font-weight: 600; }
.sync-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.sync-actions :deep(.el-button + .el-button) { margin-left: 0; }
.sync-schedule-fields { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(0, 2fr); align-items: start; gap: 16px; }
.sync-schedule-toggle { display: grid; gap: 8px; min-width: 0; }
.sync-schedule-meta { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 12px; margin: 0; font-size: 13px; line-height: 1.65; }
.sync-schedule-meta dt, .sync-trigger { color: var(--muted, #627083); font-size: 12px; }
.sync-schedule-meta dd { margin: 6px 0 0; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.sync-resource-row { display: grid; grid-template-columns: 70px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line, #dce2e9); font-size: 13px; }
.sync-resource-row > span { color: var(--muted, #627083); line-height: 1.6; }
.sync-probe-result { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12px; padding: 12px; background: var(--surface-2, #f5f7fa); }
.sync-probe-result small { flex-basis: 100%; color: var(--muted, #627083); line-height: 1.6; }
.sync-job-meta { display: flex; flex-wrap: wrap; gap: 8px 18px; }
.sync-progress { display: grid; grid-template-columns: 85px minmax(0, 1fr); gap: 10px; align-items: center; font-size: 12px; }
.sync-counts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 0; gap: 16px; }
.sync-counts dt { color: var(--muted, #627083); font-size: 12px; }
.sync-counts dd { margin: 4px 0 0; font-size: 22px; font-variant-numeric: tabular-nums; }
.sync-result-toolbar { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 16px; }
.sync-result-toolbar label { display: grid; gap: 6px; width: 200px; }
.sync-validation { padding: 14px; background: var(--surface-2, #f5f7fa); font-size: 13px; }
.sync-validation > div { flex: 1 1 350px; }
.sync-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin: 0; padding: 0; border: 0; min-width: 0; }
.sync-form > label, .sync-form > div { display: grid; align-content: start; gap: 8px; min-width: 0; }
.sync-form-wide { grid-column: 1 / -1; }
.sync-form :deep(.el-input-number), .sync-form :deep(.el-select) { width: 100%; }
.sync-config-footer { flex: 0 0 auto; padding: 12px 0 2px; background: var(--surface, #fff); border-top: 1px solid var(--line, #dce2e9); }
.education-data-sync > :deep(.el-tabs) { display: flex; flex: 1 1 0; flex-direction: column; min-height: 0; overflow: hidden; }
.education-data-sync :deep(.el-tabs__header) { flex: 0 0 auto; }
.education-data-sync :deep(.el-tabs__content) { flex: 1 1 0; min-width: 0; min-height: 0; overflow: hidden; }
.education-data-sync :deep(.el-tab-pane) { height: 100%; min-width: 0; min-height: 0; }
.sync-jobs-pane { overflow: auto; overscroll-behavior: contain; padding-right: 6px; box-sizing: border-box; }
.sync-settings-pane { display: flex; flex-direction: column; overflow: hidden; }
.sync-settings-body { flex: 1 1 0; min-height: 0; overflow: auto; align-content: start; border-bottom: 0; padding-right: 6px; }
@media (max-width: 760px) {
  .sync-schedule-fields, .sync-schedule-meta { grid-template-columns: minmax(0, 1fr); }
  .sync-resource-row { grid-template-columns: 65px minmax(0, 1fr); }
  .sync-resource-row .sync-actions { grid-column: 1 / -1; }
  .sync-form { grid-template-columns: minmax(0, 1fr); }
  .sync-counts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
