<template>
  <section class="base-visit-page">
    <van-notice-bar v-if="state.message" color="#8a5a00" background="#fff3d8" left-icon="warning-o" :text="state.message" />

    <section class="mobile-card base-visit-card">
      <header class="base-visit-head">
        <div>
          <strong>基地走访</strong>
          <small>{{ state.options.is_teacher ? '填写本人走访时间和记录' : '安排教师并查看走访进度' }}</small>
        </div>
        <AppButton v-if="canCreate" size="small" @click="openAssign()">新增安排</AppButton>
      </header>

      <MobileFilterSheet
        :select-filters="selectFilters"
        :status-options="statusOptions"
        :values="state.filters"
        keyword-placeholder="基地、教师或走访事项"
        :loading="state.loading"
        @update-filter="updateFilter"
        @search="loadList(1)"
        @reset="resetFilters"
      />
      <label class="base-visit-date-filter">
        <span>走访日期</span>
        <input v-model="state.filters.visit_date" type="date" @change="loadList(1)">
      </label>

      <div v-if="state.items.length" class="base-visit-list">
        <AppListCard
          v-for="row in state.items"
          :key="row.id"
          :title="row.title || row.base_name || `走访安排 ${row.id}`"
          :subtitle="[row.base_name, row.teacher_name].filter(Boolean).join(' / ')"
          :status="row.status"
          :status-label="statusText(row.status)"
          :meta="planFacts(row)"
          clickable
          @open="openDetail(row.id)"
        >
          <template #actions>
            <AppButton v-if="row.can_schedule" variant="quiet" size="small" @click="openSchedule(row)">填写时间</AppButton>
            <AppButton v-if="row.can_record" variant="quiet" size="small" @click="openRecord(row)">{{ row.status === 'completed' ? '修改记录' : '填写记录' }}</AppButton>
          </template>
        </AppListCard>
      </div>
      <AppEmptyState v-else-if="!state.loading" title="暂无走访安排" description="当前筛选范围内没有基地走访数据" />
      <AppLoadingState v-if="state.loading && !state.items.length" text="正在读取走访安排" />
      <footer class="base-visit-footer">
        <span>共 {{ state.pagination.total }} 条</span>
        <AppButton v-if="canLoadMore" variant="secondary" size="small" :loading="state.loading" @click="loadMore">加载更多</AppButton>
      </footer>
    </section>

    <AppSheet v-model="state.detailVisible" title="走访详情" :subtitle="state.detail.item?.base_name || ''">
      <div v-if="state.detail.item" class="base-visit-detail">
        <van-notice-bar v-if="Number(state.detail.item.conflict_count) > 0" :text="`同校同日同时段另有 ${state.detail.item.conflict_count} 个安排，请协调时间`" />
        <dl v-for="item in detailFacts" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value || '-' }}</dd></dl>
        <section v-if="state.detail.item.remark"><strong>安排说明</strong><p>{{ state.detail.item.remark }}</p></section>
        <section v-if="state.detail.item.cancel_reason"><strong>取消原因</strong><p>{{ state.detail.item.cancel_reason }}</p></section>
        <section v-if="state.detail.record"><strong>走访记录</strong><p>{{ state.detail.record.content }}</p></section>
        <dl v-if="state.detail.record"><dt>实际时间</dt><dd>{{ state.detail.record.actual_at || '-' }}</dd><dt>参加人员</dt><dd>{{ state.detail.record.participants || '-' }}</dd><dt>接待人员</dt><dd>{{ state.detail.record.contact_person || '-' }}</dd></dl>
        <section v-if="state.detail.record?.problems"><strong>发现问题</strong><p>{{ state.detail.record.problems }}</p></section>
        <section v-if="state.detail.record?.follow_up"><strong>后续措施</strong><p>{{ state.detail.record.follow_up }}</p></section>
        <section v-if="state.detail.record?.attachments?.length" class="base-visit-files">
          <strong>附件</strong>
          <article v-for="file in state.detail.record.attachments" :key="file.id || file.file_id">
            <span>{{ file.download_name || file.name || `附件 ${file.id || file.file_id}` }}</span>
            <FilePreviewButton :file="file" />
          </article>
        </section>
      </div>
      <template #footer>
        <AppButton v-if="canManage && state.detail.permissions.can_assign && state.detail.item?.status !== 'completed'" variant="secondary" @click="openAssign(state.detail.item)">修改安排</AppButton>
        <AppButton v-if="state.detail.permissions.can_schedule" variant="secondary" @click="openSchedule(state.detail.item)">填写时间</AppButton>
        <AppButton v-if="state.detail.permissions.can_record" @click="openRecord(state.detail.item)">{{ state.detail.item?.status === 'completed' ? '修改记录' : '填写记录' }}</AppButton>
        <AppButton v-if="canManage && state.detail.permissions.can_cancel" variant="danger" @click="openCancel(state.detail.item)">取消安排</AppButton>
      </template>
    </AppSheet>

    <AppSheet :model-value="state.formVisible" :title="formTitle" :subtitle="formSubtitle" :close-on-overlay="false" @update:model-value="handleFormVisibility">
      <form class="base-visit-form" :inert="state.saving || state.uploading" @submit.prevent="submitForm">
        <template v-if="state.formType === 'assign'">
          <label><span>搜索基地</span><input v-model="state.optionSearch.base_keyword" placeholder="基地名称" @input="queueOptions"></label>
          <label><span>基地</span><select v-model="state.form.base_id" required><option value="">请选择基地</option><option v-for="item in state.options.bases" :key="item.id" :value="item.id">{{ item.name }}{{ item.dep_name ? ` / ${item.dep_name}` : '' }}</option></select></label>
          <label><span>搜索教师</span><input v-model="state.optionSearch.teacher_keyword" placeholder="姓名或工号" @input="queueOptions"></label>
          <label><span>负责教师</span><select v-model="state.form.teacher_id" required><option value="">请选择教师</option><option v-for="item in state.options.teachers" :key="item.teacher_id" :value="item.teacher_id">{{ item.teacher_name }}{{ item.teacher_num ? ` / ${item.teacher_num}` : '' }}</option></select></label>
          <label><span>走访事项</span><input v-model.trim="state.form.title" maxlength="180" required></label>
          <label><span>基地类型</span><input v-model.trim="state.form.base_category" maxlength="80"></label>
          <label><span>基地位置</span><input v-model.trim="state.form.base_location" maxlength="40"></label>
          <label><span>安排说明</span><textarea v-model.trim="state.form.remark" rows="4" maxlength="10000" /></label>
        </template>
        <template v-else-if="state.formType === 'schedule'">
          <label><span>走访日期</span><input v-model="state.form.visit_date" type="date" required></label>
          <label><span>时段</span><select v-model="state.form.visit_period" required><option value="am">上午</option><option value="pm">下午</option></select></label>
          <label><span>开始时间</span><input v-model="state.form.start_time" type="time" required></label>
          <label><span>结束时间</span><input v-model="state.form.end_time" type="time"></label>
          <label><span>联系电话</span><input v-model.trim="state.form.contact_phone" type="tel" maxlength="40"></label>
          <small>时段按开始时间归类；同校同日同半天有其他安排时提示，仍可保存。</small>
        </template>
        <template v-else-if="state.formType === 'record'">
          <label><span>实际走访时间</span><input v-model="state.form.actual_at" type="datetime-local" required></label>
          <label><span>参加人员</span><input v-model.trim="state.form.participants" maxlength="500"></label>
          <label><span>接待人员</span><input v-model.trim="state.form.contact_person" maxlength="180"></label>
          <label><span>走访内容</span><textarea v-model.trim="state.form.content" rows="6" maxlength="20000" required /></label>
          <label><span>发现问题</span><textarea v-model.trim="state.form.problems" rows="4" maxlength="10000" /></label>
          <label><span>后续措施</span><textarea v-model.trim="state.form.follow_up" rows="4" maxlength="10000" /></label>
          <label class="base-visit-upload"><span>附件</span><input type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt" @change="uploadAttachments"><em>{{ state.uploading ? '正在上传' : '选择文件' }}</em></label>
          <div class="base-visit-files">
            <article v-for="file in state.form.attachments" :key="file.id || file.file_id"><span>{{ file.download_name || file.name || `附件 ${file.id || file.file_id}` }}</span><button type="button" @click="removeAttachment(file)">移除</button></article>
          </div>
        </template>
        <template v-else>
          <label><span>取消原因</span><textarea v-model.trim="state.form.reason" rows="5" maxlength="1000" required /></label>
        </template>
      </form>
      <template #footer>
        <AppButton variant="secondary" :disabled="state.saving || state.uploading" @click="requestCloseForm">关闭</AppButton>
        <AppButton :loading="state.saving || state.uploading" @click="submitForm">保存</AppButton>
      </template>
    </AppSheet>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, watch } from 'vue';
import { showToast } from 'vant';
import FilePreviewButton from '../../../../shared/components/FilePreviewButton.vue';
import MobileFilterSheet from '../../components/MobileFilterSheet.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppEmptyState from '../../components/ui/AppEmptyState.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import AppLoadingState from '../../components/ui/AppLoadingState.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import { uploadFile } from '../../api/system';
import { assignBaseVisit, cancelBaseVisit, fetchBaseVisitDetail, fetchBaseVisitList, fetchBaseVisitOptions, saveBaseVisitRecord, scheduleBaseVisit } from './baseVisitApi';

const props = defineProps({
  sessionKey: { type: String, required: true },
  canManage: { type: Boolean, default: false },
});

const state = reactive({
  loading: false,
  saving: false,
  uploading: false,
  message: '',
  items: [],
  pagination: { page: 1, page_size: 20, total: 0 },
  filters: { keyword: '', status: '', dep_id: '', visit_date: '' },
  options: { can_assign: false, is_teacher: false, bases: [], teachers: [], departments: [] },
  optionSearch: { base_keyword: '', teacher_keyword: '' },
  detailVisible: false,
  detail: { item: null, record: null, permissions: {} },
  formVisible: false,
  formType: '',
  form: {},
});
let optionTimer = null;
let listTimer = null;
let mounted = true;
let sessionGeneration = 0;
let listGeneration = 0;
let optionsGeneration = 0;
let detailGeneration = 0;
let formGeneration = 0;

const statusOptions = [
  { value: 'pending_time', label: '待填时间' },
  { value: 'scheduled', label: '已安排' },
  { value: 'completed', label: '已完成' },
  { value: 'cancelled', label: '已取消' },
];
const selectFilters = computed(() => [{ key: 'dep_id', label: '所属部门', placeholder: '全部部门', options: state.options.departments.map(item => ({ value: item.dep_id, label: item.dep_name })) }]);
const canCreate = computed(() => props.canManage && state.options.can_assign);
const canLoadMore = computed(() => state.items.length < Number(state.pagination.total || 0));
const formTitle = computed(() => ({ assign: state.form.id ? '修改走访安排' : '新增走访安排', schedule: '填写走访时间', record: state.detail.record ? '修改走访记录' : '填写走访记录', cancel: '取消走访安排' }[state.formType] || '基地走访'));
const formSubtitle = computed(() => state.detail.item?.base_name || '');
const detailFacts = computed(() => {
  const row = state.detail.item || {};
  return [
    ['状态', statusText(row.status)], ['负责教师', row.teacher_name], ['所在部门', row.teacher_department], ['基地', row.base_name],
    ['基地学院', row.base_department], ['地址', row.base_address], ['类型', row.base_category], ['位置', row.base_location],
    ['走访时间', scheduleText(row)], ['联系电话', row.contact_phone], ['填写时间', row.scheduled_at], ['创建时间', row.created_at],
  ].map(([label, value]) => ({ label, value }));
});

watch(() => props.sessionKey, async () => {
  invalidateRequests();
  resetState();
  await reload();
});
onMounted(reload);
onBeforeUnmount(() => {
  mounted = false;
  invalidateRequests();
  clearTimeout(optionTimer);
  clearTimeout(listTimer);
});
defineExpose({ reload, beforeLeave });

async function reload() {
  await Promise.all([loadOptions(), loadList(1)]);
}

function resetState() {
  state.items = [];
  state.pagination = { page: 1, page_size: 20, total: 0 };
  state.filters = { keyword: '', status: '', dep_id: '', visit_date: '' };
  state.options = { can_assign: false, is_teacher: false, bases: [], teachers: [], departments: [] };
  state.detailVisible = false;
  state.formVisible = false;
  state.detail = { item: null, record: null, permissions: {} };
  state.message = '';
  state.loading = false;
  state.saving = false;
  state.uploading = false;
}

function invalidateRequests() {
  sessionGeneration += 1;
  listGeneration += 1;
  optionsGeneration += 1;
  detailGeneration += 1;
  formGeneration += 1;
}

async function loadOptions() {
  const requestGeneration = ++optionsGeneration;
  const requestSession = sessionGeneration;
  const sessionKey = props.sessionKey;
  const params = { ...state.optionSearch };
  try {
    const data = await fetchBaseVisitOptions(params);
    if (!isCurrent(requestSession, sessionKey) || requestGeneration !== optionsGeneration) return;
    for (const [key, idKey, selectedId] of [['bases', 'id', state.form.base_id], ['teachers', 'teacher_id', state.form.teacher_id]]) {
      const selected = state.options[key].find(item => Number(item[idKey]) === Number(selectedId));
      if (selected && !(data[key] || []).some(item => Number(item[idKey]) === Number(selectedId))) data[key] = [selected, ...(data[key] || [])];
    }
    state.options = { ...state.options, ...data };
  } catch (error) {
    if (!isCurrent(requestSession, sessionKey) || requestGeneration !== optionsGeneration) return;
    state.message = error.message;
  }
}

function queueOptions() {
  clearTimeout(optionTimer);
  optionTimer = setTimeout(loadOptions, 300);
}

async function loadList(page = 1, append = false) {
  const requestGeneration = ++listGeneration;
  const requestSession = sessionGeneration;
  const sessionKey = props.sessionKey;
  const params = { ...state.filters, page, page_size: state.pagination.page_size };
  state.loading = true;
  state.message = '';
  try {
    const data = await fetchBaseVisitList(params);
    if (!isCurrent(requestSession, sessionKey) || requestGeneration !== listGeneration) return;
    state.items = append ? [...state.items, ...(data.items || [])] : (data.items || []);
    state.pagination = { ...state.pagination, ...(data.pagination || {}), page };
  } catch (error) {
    if (!isCurrent(requestSession, sessionKey) || requestGeneration !== listGeneration) return;
    state.message = error.message;
  } finally {
    if (isCurrent(requestSession, sessionKey) && requestGeneration === listGeneration) state.loading = false;
  }
}

function loadMore() {
  return loadList(Number(state.pagination.page || 1) + 1, true);
}

function updateFilter({ key, value }) {
  state.filters[key] = value;
  clearTimeout(listTimer);
  listTimer = setTimeout(() => loadList(1), 250);
}

function resetFilters() {
  state.filters = { keyword: '', status: '', dep_id: '', visit_date: '' };
  loadList(1);
}

async function openDetail(id) {
  try {
    const data = await fetchFreshDetail(id);
    if (!data) return;
    applyDetail(data);
    state.detailVisible = true;
  } catch (error) {
    showToast(error.message);
  }
}

async function fetchFreshDetail(id) {
  const requestGeneration = ++detailGeneration;
  const requestSession = sessionGeneration;
  const sessionKey = props.sessionKey;
  let data;
  try {
    data = await fetchBaseVisitDetail(id);
  } catch (error) {
    if (!isCurrent(requestSession, sessionKey) || requestGeneration !== detailGeneration) return null;
    throw error;
  }
  if (!isCurrent(requestSession, sessionKey) || requestGeneration !== detailGeneration || Number(data.item?.id) !== Number(id)) return null;
  return data;
}

function applyDetail(data) {
  state.detail = { item: data.item || null, record: data.record || null, permissions: data.permissions || {} };
}

function openAssign(row = null) {
  if (!beforeLeave()) return;
  applyDetail({ item: row, permissions: row || {} });
  if (row) {
    state.options.bases = [{ id: row.base_id, name: row.base_name, dep_name: row.base_department }];
    state.options.teachers = [{ teacher_id: row.teacher_id, teacher_name: row.teacher_name }];
  }
  beginForm('assign');
  state.form = {
    id: row?.id || 0, revision: row?.revision, base_id: row?.base_id || '', teacher_id: row?.teacher_id || '', title: row?.title || '',
    base_category: row?.base_category || '', base_location: row?.base_location || '', remark: row?.remark || '',
  };
  state.optionSearch.base_keyword = row?.base_name || '';
  state.optionSearch.teacher_keyword = row?.teacher_name || '';
  loadOptions();
  state.formVisible = true;
}

function openSchedule(row) {
  if (!beforeLeave()) return;
  applyDetail({ item: row, permissions: row });
  beginForm('schedule');
  state.form = { id: row.id, revision: row.revision, visit_date: row.visit_date || '', visit_period: row.visit_period || 'am', start_time: row.start_time || '', end_time: row.end_time || '', contact_phone: row.contact_phone || '' };
  state.formVisible = true;
}

async function openRecord(row) {
  if (!beforeLeave()) return;
  let data;
  try {
    data = await fetchFreshDetail(row.id);
  } catch (error) {
    showToast(error.message);
    return;
  }
  if (!data || Number(data.item?.id) !== Number(row.id)) return;
  applyDetail(data);
  const record = data.record || {};
  beginForm('record');
  state.form = {
    id: data.item.id, revision: data.item.revision, actual_at: toLocalDateTime(record.actual_at), participants: record.participants || '',
    contact_person: record.contact_person || '', content: record.content || '', problems: record.problems || '', follow_up: record.follow_up || '',
    attachments: (record.attachments || []).map(file => ({ ...file, file_id: file.file_id || file.id })),
  };
  state.formVisible = true;
}

function openCancel(row) {
  if (!props.canManage || !beforeLeave()) return;
  applyDetail({ item: row, permissions: row });
  beginForm('cancel');
  state.form = { id: row.id, revision: row.revision, reason: '' };
  state.formVisible = true;
}

async function submitForm() {
  if (state.saving || state.uploading) return;
  const error = validateForm();
  if (error) {
    showToast(error);
    return;
  }
  state.saving = true;
  const requestSession = sessionGeneration;
  const sessionKey = props.sessionKey;
  const requestForm = formGeneration;
  try {
    let data;
    if (state.formType === 'assign') data = await assignBaseVisit({ ...state.form });
    if (state.formType === 'schedule') data = await scheduleBaseVisit({ ...state.form, end_time: state.form.end_time || null });
    if (state.formType === 'record') data = await saveBaseVisitRecord({ ...state.form, actual_at: apiDateTime(state.form.actual_at), attachment_ids: state.form.attachments.map(file => Number(file.file_id || file.id)).filter(Boolean), attachments: undefined });
    if (state.formType === 'cancel') data = await cancelBaseVisit({ ...state.form });
    if (!isCurrent(requestSession, sessionKey) || requestForm !== formGeneration) return;
    applyDetail(data);
    state.saving = false;
    state.formVisible = false;
    state.detailVisible = true;
    await loadList(1);
    const conflicts = Number(data.item?.conflict_count || 0);
    showToast(conflicts > 0 ? `已保存，同一时段另有 ${conflicts} 个安排` : '已保存');
  } catch (error) {
    if (!isCurrent(requestSession, sessionKey) || requestForm !== formGeneration) return;
    showToast(error.message);
  } finally {
    if (isCurrent(requestSession, sessionKey) && requestForm === formGeneration) state.saving = false;
  }
}

function validateForm() {
  if (state.formType === 'assign') {
    if (!props.canManage || !state.options.can_assign) return '当前账号不能安排基地走访';
    if (!state.form.base_id) return '请选择基地';
    if (!state.form.teacher_id) return '请选择负责教师';
    if (!state.form.title) return '请填写走访事项';
  }
  if (state.formType === 'schedule') {
    if (!state.form.visit_date || !state.form.visit_period || !state.form.start_time) return '请完整填写走访日期、时段和开始时间';
    if ((state.form.start_time < '12:00' ? 'am' : 'pm') !== state.form.visit_period) return '开始时间与上午/下午不一致';
    if (state.form.end_time && state.form.end_time <= state.form.start_time) return '结束时间必须晚于开始时间';
  }
  if (state.formType === 'record' && (!state.form.actual_at || !state.form.content)) return '请填写实际走访时间和走访内容';
  if (state.formType === 'cancel' && (!props.canManage || !state.form.reason)) return '请填写取消原因';
  return '';
}

async function uploadAttachments(event) {
  if (state.saving || state.uploading || state.formType !== 'record') {
    event.target.value = '';
    return;
  }
  const remaining = Math.max(0, 20 - (state.form.attachments?.length || 0));
  const selected = Array.from(event.target.files || []);
  const files = selected.slice(0, remaining);
  event.target.value = '';
  if (!remaining) {
    showToast('附件最多 20 个');
    return;
  }
  if (selected.length > remaining) showToast(`附件最多 20 个，本次选择前 ${remaining} 个`);
  if (!files.length) return;
  const requestSession = sessionGeneration;
  const sessionKey = props.sessionKey;
  const requestForm = formGeneration;
  const targetId = Number(state.form.id || 0);
  state.uploading = true;
  try {
    for (const file of files) {
      const uploaded = await uploadFile(file, { category: 'base_visit', is_temporary: 'false' });
      if (!isCurrent(requestSession, sessionKey) || requestForm !== formGeneration || Number(state.form.id || 0) !== targetId) return;
      state.form.attachments.push({ id: uploaded.file_id, file_id: uploaded.file_id, name: uploaded.name || file.name, url: uploaded.url || '' });
    }
  } catch (error) {
    if (!isCurrent(requestSession, sessionKey) || requestForm !== formGeneration) return;
    showToast(error.message);
  } finally {
    if (isCurrent(requestSession, sessionKey) && requestForm === formGeneration) state.uploading = false;
  }
}

function beginForm(type) {
  formGeneration += 1;
  detailGeneration += 1;
  state.detailVisible = false;
  state.formType = type;
  state.formVisible = true;
}

function handleFormVisibility(value) {
  if (value) {
    state.formVisible = true;
    return;
  }
  requestCloseForm();
}

function requestCloseForm() {
  if (state.saving || state.uploading) {
    showToast('正在处理，请稍候');
    return;
  }
  formGeneration += 1;
  state.formVisible = false;
}

function beforeLeave() {
  if (!state.saving && !state.uploading) return true;
  showToast('正在处理，请稍候');
  return false;
}

function isCurrent(requestSession, sessionKey) {
  return mounted && requestSession === sessionGeneration && sessionKey === props.sessionKey;
}

function removeAttachment(file) {
  if (state.saving || state.uploading) return;
  const id = Number(file.file_id || file.id);
  state.form.attachments = state.form.attachments.filter(item => Number(item.file_id || item.id) !== id);
}

function planFacts(row) {
  return [scheduleText(row), row.teacher_department, row.base_address, Number(row.conflict_count || 0) > 0 ? `同一时段另有 ${row.conflict_count} 个安排` : ''].filter(Boolean);
}

function scheduleText(row) {
  if (!row?.visit_date) return '待填写走访时间';
  const period = row.visit_period === 'pm' ? '下午' : '上午';
  const time = [row.start_time, row.end_time].filter(Boolean).join('-');
  return `${row.visit_date} ${period}${time ? ` ${time}` : ''}`;
}

function statusText(status) {
  return statusOptions.find(item => item.value === status)?.label || status || '-';
}

function toLocalDateTime(value) {
  return value ? String(value).slice(0, 16).replace(' ', 'T') : '';
}

function apiDateTime(value) {
  if (!value) return '';
  const text = String(value).replace('T', ' ');
  return text.length === 16 ? `${text}:00` : text;
}
</script>

<style scoped>
.base-visit-page,.base-visit-card,.base-visit-list,.base-visit-form,.base-visit-detail,.base-visit-files{display:grid;gap:12px}.base-visit-head,.base-visit-footer,.base-visit-files article{display:flex;align-items:center;justify-content:space-between;gap:10px}.base-visit-head>div{display:grid;gap:3px}.base-visit-head small,.base-visit-footer,.base-visit-date-filter span{color:var(--app-text-secondary);font-size:12px}.base-visit-date-filter{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:10px}.base-visit-date-filter input,.base-visit-form input,.base-visit-form select,.base-visit-form textarea{width:100%;box-sizing:border-box;border:1px solid var(--app-line);border-radius:var(--app-radius);padding:10px 11px;color:var(--app-text);background:var(--app-surface);font:inherit}.base-visit-form{padding:14px 16px}.base-visit-form label{display:grid;gap:6px}.base-visit-form label>span{color:var(--app-text-secondary);font-size:13px}.base-visit-form textarea{resize:vertical}.base-visit-detail{padding:14px 16px}.base-visit-detail dl{display:grid;grid-template-columns:82px minmax(0,1fr);gap:7px;margin:0;padding-bottom:8px;border-bottom:1px solid var(--app-line)}.base-visit-detail dt{color:var(--app-text-secondary)}.base-visit-detail dd{margin:0;color:var(--app-text);overflow-wrap:anywhere}.base-visit-detail section{display:grid;gap:6px}.base-visit-detail p{margin:0;white-space:pre-wrap;line-height:1.6}.base-visit-files article{padding:8px 0;border-top:1px solid var(--app-line)}.base-visit-files article span{min-width:0;overflow-wrap:anywhere}.base-visit-files button{border:0;color:var(--app-danger);background:transparent}.base-visit-upload em{color:var(--app-primary);font-size:12px;font-style:normal}.base-visit-page :deep(.app-sheet>footer){flex-wrap:wrap}.base-visit-page :deep(.app-sheet>footer .app-button){flex:1 1 42%}
</style>
