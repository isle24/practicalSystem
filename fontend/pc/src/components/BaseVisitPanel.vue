<template>
  <section class="base-visit-panel">
    <header class="base-visit-heading">
      <strong>基地巡查</strong>
      <span>{{ state.options.is_teacher ? '填写本人走访时间和走访记录' : '安排负责教师，查看走访进度与记录' }}</span>
    </header>
    <el-alert v-if="state.message" :title="state.message" :type="state.error ? 'error' : 'success'" :closable="false" show-icon />
    <DataListPanel
      :columns="columns"
      :filters="listFilters"
      :filter-values="state.filters"
      :rows="state.rows"
      :pagination="state.pagination"
      :loading="state.loading"
      :storage-key="`practical:pc:columns:${sessionKey}:baseVisits`"
      @filter-change="setFilter"
      @search="loadList(1)"
      @reset="resetFilters"
      @page-change="loadList"
    >
      <template #toolbar>
        <label class="base-visit-date-filter"><span>走访日期</span><input v-model="state.filters.visit_date" type="date" aria-label="走访日期" @change="loadList(1)"></label>
        <el-button :loading="state.loading || state.optionsLoading" :disabled="busy" @click="refresh">刷新</el-button>
        <el-button v-if="state.options.can_assign" type="primary" :icon="Plus" :disabled="busy || state.optionsLoading" @click="openCreate">新增安排</el-button>
      </template>
      <template #cell-conflict_count="{ row }">
        <el-tag v-if="Number(row.conflict_count) > 0" type="warning">{{ row.conflict_count }} 项同日同时段安排</el-tag>
        <span v-else>—</span>
      </template>
      <template #actions="{ row }">
        <el-button :disabled="busy" @click="openDialog('detail', row)">查看详情</el-button>
        <el-button v-if="canAct('assign', row)" :disabled="busy" @click="openDialog('assign', row)">修改安排</el-button>
        <el-button v-if="canAct('schedule', row)" :disabled="busy" type="primary" @click="openDialog('schedule', row)">填写时间</el-button>
        <el-button v-if="canAct('record', row)" :disabled="busy" type="primary" @click="openDialog('record', row)">{{ row.status === 'completed' ? '修改走访记录' : '填写走访记录' }}</el-button>
        <el-button v-if="canAct('cancel', row)" :disabled="busy" type="danger" plain @click="openDialog('cancel', row)">取消安排</el-button>
      </template>
    </DataListPanel>

    <OperationDialog :visible="Boolean(state.dialogMode)" :title="dialogTitle" :busy="busy" dialog-class="base-visit-dialog" @close="closeDialog">
      <div class="base-visit-dialog-body" v-loading="state.detailLoading" :inert="busy">
        <el-alert v-if="state.dialogError" :title="state.dialogError" type="error" :closable="false" show-icon />
        <div v-if="state.detail?.item" class="base-visit-context">
          <strong>{{ state.detail.item.title }}</strong>
          <span>{{ state.detail.item.base_name }} · {{ state.detail.item.teacher_name }}</span>
          <el-tag :type="statusType(state.detail.item.status)">{{ statusText(state.detail.item.status) }}</el-tag>
        </div>

        <template v-if="!state.detailLoading && state.dialogMode === 'detail' && state.detail">
          <el-alert v-if="Number(state.detail.item.conflict_count) > 0" :title="conflictText(state.detail.item)" type="warning" :closable="false" show-icon />
          <dl class="base-visit-facts">
            <dt>基地学院</dt><dd>{{ state.detail.item.base_department || '—' }}</dd>
            <dt>基地名称</dt><dd>{{ state.detail.item.base_name || '—' }}</dd>
            <dt>基地地址</dt><dd>{{ state.detail.item.base_address || '—' }}</dd>
            <dt>基地类型</dt><dd>{{ state.detail.item.base_category || '—' }}</dd>
            <dt>基地位置</dt><dd>{{ state.detail.item.base_location || '—' }}</dd>
            <dt>负责教师</dt><dd>{{ state.detail.item.teacher_name || '—' }}</dd>
            <dt>所在部门</dt><dd>{{ state.detail.item.teacher_department || '—' }}</dd>
            <dt>联系电话</dt><dd>{{ state.detail.item.contact_phone || '—' }}</dd>
            <dt>走访时间</dt><dd>{{ scheduleText(state.detail.item) }}</dd>
            <dt>填写时间</dt><dd>{{ state.detail.item.scheduled_at || '—' }}</dd>
            <dt>备注</dt><dd>{{ state.detail.item.remark || '—' }}</dd>
            <template v-if="state.detail.item.cancel_reason"><dt>取消原因</dt><dd>{{ state.detail.item.cancel_reason }}</dd></template>
          </dl>
          <section v-if="state.detail.record" class="base-visit-record-detail">
            <h3>走访记录</h3>
            <dl class="base-visit-facts">
              <dt>实际走访时间</dt><dd>{{ state.detail.record.actual_at || '—' }}</dd>
              <dt>参加人员</dt><dd>{{ state.detail.record.participants || '—' }}</dd>
              <dt>接待人员</dt><dd>{{ state.detail.record.contact_person || '—' }}</dd>
            </dl>
            <section v-for="field in recordTextFields" :key="field.key" class="base-visit-record-text">
              <strong>{{ field.label }}</strong><p>{{ state.detail.record[field.key] || '—' }}</p>
            </section>
            <div v-if="state.detail.record.attachments?.length" class="base-visit-files">
              <strong>附件</strong>
              <article v-for="file in state.detail.record.attachments" :key="file.file_id || file.id">
                <a v-if="file.url" :href="backendUrl(file.url)" target="_blank" rel="noopener noreferrer">{{ fileName(file) }}</a><span v-else>{{ fileName(file) }}</span>
                <FilePreviewButton :file="file" />
              </article>
            </div>
          </section>
          <el-empty v-else description="尚未填写走访记录" :image-size="64" />
        </template>

        <form v-else-if="!state.detailLoading && state.dialogMode === 'assign'" class="base-visit-form" @submit.prevent="saveDialog">
          <label class="base-visit-wide"><span>走访事项</span><el-input v-model="state.form.title" maxlength="180" placeholder="请输入走访事项" /></label>
          <label><span>实习基地</span>
            <el-select v-model="state.form.base_id" filterable remote :remote-method="searchBases" :loading="state.baseLoading" placeholder="输入基地名称搜索" @change="selectBase">
              <el-option v-for="base in baseOptions" :key="base.id" :label="`${base.name}${base.dep_name ? ` / ${base.dep_name}` : ''}`" :value="Number(base.id)" />
            </el-select>
          </label>
          <label><span>负责教师</span>
            <el-select v-model="state.form.teacher_id" filterable remote :remote-method="searchTeachers" :loading="state.teacherLoading" placeholder="输入姓名或工号搜索" @change="selectTeacher">
              <el-option v-for="teacher in teacherOptions" :key="teacher.teacher_id" :label="`${teacher.teacher_name}${teacher.teacher_num ? ` / ${teacher.teacher_num}` : ''}${teacher.dep_name ? ` / ${teacher.dep_name}` : ''}`" :value="Number(teacher.teacher_id)" />
            </el-select>
          </label>
          <dl v-if="selectedBase || selectedTeacher" class="base-visit-facts base-visit-wide">
            <dt>基地地址</dt><dd>{{ selectedBase?.address || '—' }}</dd>
            <dt>基地学院</dt><dd>{{ selectedBase?.dep_name || '—' }}</dd>
            <dt>教师部门</dt><dd>{{ selectedTeacher?.dep_name || '—' }}</dd>
          </dl>
          <label><span>基地类型</span><el-input v-model="state.form.base_category" maxlength="80" placeholder="请输入基地类型" /></label>
          <label><span>基地位置</span><el-input v-model="state.form.base_location" maxlength="40" placeholder="请输入基地位置" /></label>
          <label class="base-visit-wide"><span>备注</span><el-input v-model="state.form.remark" type="textarea" :rows="3" maxlength="10000" /></label>
        </form>

        <form v-else-if="!state.detailLoading && state.dialogMode === 'schedule'" class="base-visit-form" @submit.prevent="saveDialog">
          <label><span>走访日期</span><input v-model="state.form.visit_date" type="date" required></label>
          <label><span>走访时段</span><el-select v-model="state.form.visit_period" placeholder="请选择上午或下午"><el-option label="上午" value="am" /><el-option label="下午" value="pm" /></el-select></label>
          <label><span>开始时间</span><input v-model="state.form.start_time" type="time" required></label>
          <label><span>结束时间（选填）</span><input v-model="state.form.end_time" type="time"></label>
          <label class="base-visit-wide"><span>联系电话（选填）</span><el-input v-model="state.form.contact_phone" maxlength="40" placeholder="请输入联系电话" /></label>
          <small class="base-visit-wide">时段按开始时间归类；同校同日同半天有其他安排时提示，仍可保存。</small>
        </form>

        <form v-else-if="!state.detailLoading && state.dialogMode === 'record'" class="base-visit-form" @submit.prevent="saveDialog">
          <label class="base-visit-wide"><span>实际走访时间</span><input v-model="state.form.actual_at" type="datetime-local" step="1" required></label>
          <label><span>参加人员</span><el-input v-model="state.form.participants" maxlength="500" /></label>
          <label><span>接待人员</span><el-input v-model="state.form.contact_person" maxlength="180" /></label>
          <label v-for="field in recordTextFields" :key="field.key" class="base-visit-wide"><span>{{ field.label }}{{ field.key === 'content' ? '（必填）' : '' }}</span><el-input v-model="state.form[field.key]" type="textarea" :rows="field.key === 'content' ? 6 : 3" :maxlength="field.maxlength" /></label>
          <section class="base-visit-wide base-visit-files">
            <header><strong>附件（{{ state.form.attachments.length }}/20）</strong><el-button :icon="Upload" :loading="state.uploading" :disabled="state.form.attachments.length >= 20" @click="attachmentInput?.click()">上传附件</el-button></header>
            <input ref="attachmentInput" type="file" hidden multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt" @change="uploadAttachments">
            <article v-for="file in state.form.attachments" :key="file.file_id || file.id">
              <a v-if="file.url" :href="backendUrl(file.url)" target="_blank" rel="noopener noreferrer">{{ fileName(file) }}</a><span v-else>{{ fileName(file) }}</span>
              <FilePreviewButton :file="file" /><el-button link type="danger" @click="removeAttachment(file)">移除</el-button>
            </article>
            <small v-if="!state.form.attachments.length">暂无附件</small>
          </section>
        </form>

        <form v-else-if="!state.detailLoading && state.dialogMode === 'cancel'" class="base-visit-form" @submit.prevent="saveDialog">
          <label class="base-visit-wide"><span>取消原因</span><el-input v-model="state.form.reason" type="textarea" :rows="4" maxlength="1000" placeholder="请输入取消原因" /></label>
        </form>
      </div>
      <template #footer>
        <el-button :disabled="busy" @click="closeDialog">关闭</el-button>
        <template v-if="state.dialogMode === 'detail' && state.detail && !state.detailLoading">
          <el-button v-if="canAct('assign', state.detail.item, state.detail.permissions)" :disabled="busy" @click="openDialog('assign', state.detail.item)">修改安排</el-button>
          <el-button v-if="canAct('schedule', state.detail.item, state.detail.permissions)" :disabled="busy" type="primary" @click="openDialog('schedule', state.detail.item)">填写时间</el-button>
          <el-button v-if="canAct('record', state.detail.item, state.detail.permissions)" :disabled="busy" type="primary" @click="openDialog('record', state.detail.item)">{{ state.detail.record ? '修改走访记录' : '填写走访记录' }}</el-button>
          <el-button v-if="canAct('cancel', state.detail.item, state.detail.permissions)" :disabled="busy" type="danger" plain @click="openDialog('cancel', state.detail.item)">取消安排</el-button>
        </template>
        <el-button v-else-if="state.dialogMode !== 'detail'" :type="state.dialogMode === 'cancel' ? 'danger' : 'primary'" :loading="state.saving" :disabled="state.detailLoading || state.uploading" @click="saveDialog">{{ saveButtonText }}</el-button>
      </template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Plus, Upload } from '@lucide/vue';
import DataListPanel from './DataListPanel.vue';
import OperationDialog from './OperationDialog.vue';
import FilePreviewButton from '../../../shared/components/FilePreviewButton.vue';
import { backendUrl } from '../api/client';
import { uploadFile } from '../api/system';
import { assignBaseVisit, cancelBaseVisit, fetchBaseVisitDetail, fetchBaseVisitOptions, fetchBaseVisits, saveBaseVisitRecord, scheduleBaseVisit } from '../api/baseVisits';

const props = defineProps({ sessionKey: { type: String, required: true } });
const emptyFilters = () => ({ keyword: '', status: '', dep_id: '', visit_date: '' });
const initialState = () => ({
  options: { can_assign: false, is_teacher: false, bases: [], teachers: [], departments: [] },
  rows: [], pagination: { page: 1, page_size: 20, total: 0 }, filters: emptyFilters(),
  loading: false, optionsLoading: false, baseLoading: false, teacherLoading: false,
  saving: false, uploading: false, detailLoading: false,
  message: '', error: false, dialogError: '', dialogMode: '', detail: null, form: {},
});
const state = reactive(initialState());
const selectedBase = ref(null);
const selectedTeacher = ref(null);
const attachmentInput = ref(null);
const busy = computed(() => state.saving || state.uploading);
const dialogTitle = computed(() => ({ detail: '走访详情', assign: state.detail ? '修改走访安排' : '新增走访安排', schedule: '填写走访时间', record: state.detail?.record ? '修改走访记录' : '填写走访记录', cancel: '取消走访安排' }[state.dialogMode] || '基地巡查'));
const saveButtonText = computed(() => ({ assign: '保存安排', schedule: '保存时间', record: '保存记录', cancel: '确认取消' }[state.dialogMode] || '保存'));
const statusOptions = [{ value: 'pending_time', label: '待填时间' }, { value: 'scheduled', label: '已安排' }, { value: 'completed', label: '已完成' }, { value: 'cancelled', label: '已取消' }];
const recordTextFields = [{ key: 'content', label: '走访内容', maxlength: 20000 }, { key: 'problems', label: '发现问题', maxlength: 10000 }, { key: 'follow_up', label: '后续措施', maxlength: 10000 }];
const columns = [
  { prop: 'title', label: '走访事项', minWidth: 180 },
  { prop: 'base_name', label: '基地名称', minWidth: 180, required: true },
  { prop: 'base_department', label: '基地学院', minWidth: 130 },
  { prop: 'teacher_name', label: '负责教师', minWidth: 110 },
  { prop: 'teacher_department', label: '所在部门', minWidth: 130 },
  { key: 'visit_time', label: '走访时间', minWidth: 230, formatter: scheduleText },
  { prop: 'contact_phone', label: '联系电话', minWidth: 140 },
  { prop: 'status', label: '状态', minWidth: 100, tag: true, tagType: row => statusType(row.status), formatter: row => statusText(row.status) },
  { prop: 'conflict_count', label: '时段提醒', minWidth: 200 },
  { prop: 'base_address', label: '基地地址', minWidth: 220, defaultVisible: false },
  { prop: 'base_category', label: '基地类型', minWidth: 120, defaultVisible: false },
  { prop: 'base_location', label: '基地位置', minWidth: 150, defaultVisible: false },
  { prop: 'scheduled_at', label: '填写时间', minWidth: 170, defaultVisible: false },
  { prop: 'remark', label: '备注', minWidth: 180, defaultVisible: false },
];
const listFilters = computed(() => [
  { key: 'keyword', label: '基地、教师或走访事项', placeholder: '请输入基地、教师或走访事项' },
  { key: 'status', label: '状态', type: 'select', options: statusOptions },
  ...(state.options.departments.length ? [{ key: 'dep_id', label: '学院', type: 'select', options: state.options.departments.map(item => ({ value: item.dep_id, label: item.dep_name })) }] : []),
]);
const baseOptions = computed(() => includeSelection(state.options.bases, selectedBase.value, 'id'));
const teacherOptions = computed(() => includeSelection(state.options.teachers, selectedTeacher.value, 'teacher_id'));
const pending = new Map();
const searchTimers = {};
let sessionVersion = 0;
let dialogVersion = 0;

function includeSelection(items, selected, key) {
  return selected && !items.some(item => Number(item[key]) === Number(selected[key])) ? [selected, ...items] : items;
}

function statusText(status) {
  return statusOptions.find(item => item.value === status)?.label || status || '—';
}

function statusType(status) {
  return { pending_time: 'warning', scheduled: 'primary', completed: 'success', cancelled: 'info' }[status] || 'info';
}

function periodText(period) {
  return { am: '上午', pm: '下午' }[period] || '';
}

function scheduleText(item) {
  if (!item.visit_date) return '待填时间';
  const time = [item.start_time?.slice(0, 5), item.end_time?.slice(0, 5)].filter(Boolean).join('—');
  return [item.visit_date, periodText(item.visit_period), time].filter(Boolean).join(' ');
}

function conflictText(item) {
  return `同校 ${item.visit_date} ${periodText(item.visit_period)}另有 ${item.conflict_count} 项走访安排，当前安排已保存，请协调时间。`;
}

function canAct(action, item, permissions = item) {
  if (!item || permissions?.[`can_${action}`] !== true) return false;
  if (action === 'record') return ['scheduled', 'completed'].includes(item.status);
  return ['pending_time', 'scheduled'].includes(item.status);
}

function stopRequest(key) {
  pending.get(key)?.controller.abort();
  pending.delete(key);
}

function beginRequest(key) {
  stopRequest(key);
  const ticket = { controller: new AbortController(), session: sessionVersion };
  pending.set(key, ticket);
  return ticket;
}

function isCurrent(key, ticket) {
  return ticket.session === sessionVersion && pending.get(key) === ticket;
}

async function loadOptions(kind = '', keyword = '') {
  const key = `options:${kind}`;
  const ticket = beginRequest(key);
  const loadingKey = kind === 'base' ? 'baseLoading' : kind === 'teacher' ? 'teacherLoading' : 'optionsLoading';
  state[loadingKey] = true;
  try {
    const params = kind ? { [`${kind}_keyword`]: keyword } : {};
    const data = await fetchBaseVisitOptions(params, { signal: ticket.controller.signal });
    if (!isCurrent(key, ticket)) return;
    state.options.can_assign = data.can_assign === true;
    state.options.is_teacher = data.is_teacher === true;
    if (!kind || kind === 'base') state.options.bases = data.bases || [];
    if (!kind || kind === 'teacher') state.options.teachers = data.teachers || [];
    if (!kind) state.options.departments = data.departments || [];
  } catch (error) {
    if (!isCurrent(key, ticket)) return;
    if (state.dialogMode) state.dialogError = error.message;
    else { state.message = error.message; state.error = true; }
  } finally {
    if (isCurrent(key, ticket)) { state[loadingKey] = false; pending.delete(key); }
  }
}

function queueSearch(kind, keyword) {
  window.clearTimeout(searchTimers[kind]);
  stopRequest(`options:${kind}`);
  state[kind === 'base' ? 'baseLoading' : 'teacherLoading'] = true;
  searchTimers[kind] = window.setTimeout(() => loadOptions(kind, keyword), 250);
}

const searchBases = keyword => queueSearch('base', keyword);
const searchTeachers = keyword => queueSearch('teacher', keyword);

async function loadList(page = 1) {
  const ticket = beginRequest('list');
  state.loading = true;
  try {
    const data = await fetchBaseVisits({ ...state.filters, page, page_size: state.pagination.page_size }, { signal: ticket.controller.signal });
    if (!isCurrent('list', ticket)) return;
    state.rows = data.items || [];
    state.pagination = { page, page_size: 20, total: 0, ...data.pagination };
  } catch (error) {
    if (isCurrent('list', ticket)) { state.message = error.message; state.error = true; }
  } finally {
    if (isCurrent('list', ticket)) { state.loading = false; pending.delete('list'); }
  }
}

function setFilter({ key, value }) {
  state.filters[key] = value ?? '';
}

function resetFilters() {
  state.filters = emptyFilters();
  loadList(1);
}

function refresh() {
  state.message = '';
  state.error = false;
  loadOptions();
  loadList(state.pagination.page);
}

function selectBase(id) {
  selectedBase.value = baseOptions.value.find(item => Number(item.id) === Number(id)) || null;
  state.form.base_category = selectedBase.value?.base_category || '';
  state.form.base_location = '';
}

function selectTeacher(id) {
  selectedTeacher.value = teacherOptions.value.find(item => Number(item.teacher_id) === Number(id)) || null;
}

function cancelSearches() {
  for (const kind of ['base', 'teacher']) {
    window.clearTimeout(searchTimers[kind]);
    stopRequest(`options:${kind}`);
  }
  state.baseLoading = false;
  state.teacherLoading = false;
}

function closeDialog() {
  if (busy.value) return;
  dialogVersion += 1;
  stopRequest('detail');
  cancelSearches();
  state.dialogMode = '';
  state.dialogError = '';
  state.detailLoading = false;
  state.detail = null;
  state.form = {};
  selectedBase.value = null;
  selectedTeacher.value = null;
}

function openCreate() {
  if (busy.value || !state.options.can_assign) return;
  closeDialog();
  state.form = { base_id: null, teacher_id: null, title: '', base_category: '', base_location: '', remark: '' };
  state.dialogMode = 'assign';
}

async function openDialog(mode, item) {
  if (busy.value) return;
  closeDialog();
  state.dialogMode = 'detail';
  state.detailLoading = true;
  const ticket = beginRequest('detail');
  try {
    const data = await fetchBaseVisitDetail(item.id, { signal: ticket.controller.signal });
    if (!isCurrent('detail', ticket)) return;
    state.detail = data;
    if (mode !== 'detail' && !canAct(mode, data.item, data.permissions)) {
      state.dialogError = '当前安排已变更或没有此项操作权限，请查看最新详情。';
      return;
    }
    state.form = formFor(mode, data);
    state.dialogMode = mode;
  } catch (error) {
    if (isCurrent('detail', ticket)) state.dialogError = error.message;
  } finally {
    if (isCurrent('detail', ticket)) { state.detailLoading = false; pending.delete('detail'); }
  }
}

function formFor(mode, detail) {
  const item = detail.item;
  if (mode === 'assign') {
    selectedBase.value = { id: item.base_id, name: item.base_name, dep_name: item.base_department, address: item.base_address, base_category: item.base_category };
    selectedTeacher.value = { teacher_id: item.teacher_id, teacher_name: item.teacher_name, dep_name: item.teacher_department };
    return { base_id: Number(item.base_id), teacher_id: Number(item.teacher_id), title: item.title || '', base_category: item.base_category || '', base_location: item.base_location || '', remark: item.remark || '' };
  }
  if (mode === 'schedule') return { visit_date: item.visit_date || '', visit_period: item.visit_period || '', start_time: item.start_time?.slice(0, 5) || '', end_time: item.end_time?.slice(0, 5) || '', contact_phone: item.contact_phone || '' };
  if (mode === 'record') {
    const record = detail.record || {};
    return { actual_at: (record.actual_at || localDateTime()).replace(' ', 'T'), participants: record.participants || '', contact_person: record.contact_person || '', content: record.content || '', problems: record.problems || '', follow_up: record.follow_up || '', attachments: [...(record.attachments || [])] };
  }
  return mode === 'cancel' ? { reason: '' } : {};
}

function localDateTime() {
  const value = new Date();
  const pad = number => String(number).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function formError(mode, form) {
  if (mode === 'assign' && (!form.base_id || !form.teacher_id || !form.title?.trim())) return '请选择实习基地、负责教师，并填写走访事项。';
  if (mode === 'schedule') {
    if (!form.visit_date || !form.visit_period || !form.start_time) return '请填写走访日期、时段和开始时间。';
    if ((form.start_time < '12:00' ? 'am' : 'pm') !== form.visit_period) return '开始时间与上午/下午不一致。';
    if (form.end_time && form.end_time <= form.start_time) return '结束时间应晚于开始时间。';
  }
  if (mode === 'record' && (!form.actual_at || !form.content?.trim())) return '请填写实际走访时间和走访内容。';
  if (mode === 'cancel' && !form.reason?.trim()) return '请填写取消原因。';
  return '';
}

async function saveDialog() {
  if (busy.value || state.detailLoading || !['assign', 'schedule', 'record', 'cancel'].includes(state.dialogMode)) return;
  const mode = state.dialogMode;
  const item = state.detail?.item;
  if (item ? !canAct(mode, item, state.detail.permissions) : mode !== 'assign' || !state.options.can_assign) return;
  state.dialogError = formError(mode, state.form);
  if (state.dialogError) return;
  const version = sessionVersion;
  const currentDialog = dialogVersion;
  const payload = { ...state.form, ...(item ? { id: item.id, revision: item.revision } : {}) };
  if (mode === 'record') {
    payload.actual_at = payload.actual_at.replace('T', ' ');
    if (payload.actual_at.length === 16) payload.actual_at += ':00';
    payload.attachment_ids = [...new Set(payload.attachments.map(file => Number(file.file_id || file.id)).filter(Boolean))];
    delete payload.attachments;
  }
  state.saving = true;
  try {
    const save = { assign: assignBaseVisit, schedule: scheduleBaseVisit, record: saveBaseVisitRecord, cancel: cancelBaseVisit }[mode];
    const data = await save(payload);
    if (version !== sessionVersion || currentDialog !== dialogVersion) return;
    state.detail = data;
    state.dialogMode = 'detail';
    state.form = {};
    state.message = { assign: '走访安排已保存', schedule: '走访时间已保存', record: '走访记录已保存', cancel: '走访安排已取消' }[mode];
    state.error = false;
    await loadList(state.pagination.page);
  } catch (error) {
    if (version === sessionVersion && currentDialog === dialogVersion) state.dialogError = error.message;
  } finally {
    if (version === sessionVersion && currentDialog === dialogVersion) state.saving = false;
  }
}

function fileName(file) {
  return file.download_name || file.name || file.file_name || `附件 #${file.file_id || file.id}`;
}

async function uploadAttachments(event) {
  const files = Array.from(event.target.files || []);
  event.target.value = '';
  if (!files.length || busy.value || state.dialogMode !== 'record') return;
  if (state.form.attachments.length + files.length > 20) {
    state.dialogError = '附件最多 20 个，请减少选择的文件。';
    return;
  }
  const version = sessionVersion;
  const currentDialog = dialogVersion;
  state.uploading = true;
  state.dialogError = '';
  try {
    for (const file of files) {
      const uploaded = await uploadFile(file, { category: 'base_visit', is_temporary: 'false' });
      if (version !== sessionVersion || currentDialog !== dialogVersion) return;
      if (!state.form.attachments.some(item => Number(item.file_id || item.id) === Number(uploaded.file_id))) {
        state.form.attachments.push({ id: uploaded.file_id, file_id: uploaded.file_id, name: uploaded.name || file.name, url: uploaded.url || '' });
      }
    }
  } catch (error) {
    if (version === sessionVersion && currentDialog === dialogVersion) state.dialogError = error.message;
  } finally {
    if (version === sessionVersion && currentDialog === dialogVersion) state.uploading = false;
    event.target.value = '';
  }
}

function removeAttachment(file) {
  const id = Number(file.file_id || file.id);
  state.form.attachments = state.form.attachments.filter(item => Number(item.file_id || item.id) !== id);
}

function invalidateSession() {
  sessionVersion += 1;
  dialogVersion += 1;
  for (const ticket of pending.values()) ticket.controller.abort();
  pending.clear();
  for (const timer of Object.values(searchTimers)) window.clearTimeout(timer);
}

watch(() => props.sessionKey, () => {
  invalidateSession();
  Object.assign(state, initialState());
  selectedBase.value = null;
  selectedTeacher.value = null;
  loadOptions();
  loadList(1);
}, { immediate: true, flush: 'sync' });

onBeforeUnmount(invalidateSession);
</script>

<style scoped>
.base-visit-panel { height: 100%; min-height: 0; min-width: 0; display: flex; flex-direction: column; gap: 10px; padding: 12px; box-sizing: border-box; }
.base-visit-heading { display: flex; align-items: baseline; flex-wrap: wrap; gap: 8px 16px; }
.base-visit-heading > strong { font-size: 16px; }
.base-visit-heading > span, .base-visit-date-filter, .base-visit-form small, .base-visit-files small { color: var(--muted); font-size: 12px; }
.base-visit-panel > .data-list-panel { flex: 1; min-height: 0; }
.base-visit-date-filter { display: flex; align-items: center; gap: 8px; }
.base-visit-date-filter input { height: var(--toolbar-control-height, 38px); border: 1px solid var(--line); border-radius: var(--control-radius); padding: 0 8px; color: var(--text); background: #fff; }
:global(.operation-dialog.base-visit-dialog) { width: min(860px, calc(100vw - 48px)); }
.base-visit-dialog-body { min-height: 160px; display: flex; flex-direction: column; gap: 16px; padding: 18px; overflow: auto; }
.base-visit-context { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; }
.base-visit-context > span { color: var(--muted); font-size: 13px; }
.base-visit-facts { display: grid; grid-template-columns: 110px minmax(0, 1fr); gap: 10px 16px; margin: 0; font-size: 13px; line-height: 1.6; }
.base-visit-facts dt { color: var(--muted); }
.base-visit-facts dd { min-width: 0; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.base-visit-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.base-visit-form > label { display: flex; flex-direction: column; gap: 8px; min-width: 0; font-size: 13px; }
.base-visit-wide { grid-column: 1 / -1; }
.base-visit-form input[type='date'], .base-visit-form input[type='time'], .base-visit-form input[type='datetime-local'] { width: 100%; min-width: 0; height: var(--control-height, 34px); padding: 0 10px; border: 1px solid var(--line); border-radius: var(--control-radius); color: var(--text); font: inherit; background: #fff; box-sizing: border-box; }
.base-visit-form .el-select { width: 100%; }
.base-visit-record-detail, .base-visit-record-text, .base-visit-files { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.base-visit-record-detail h3 { margin: 0; font-size: 15px; }
.base-visit-record-text > strong, .base-visit-files > strong, .base-visit-files header { font-size: 13px; }
.base-visit-record-text p { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 13px; line-height: 1.8; }
.base-visit-files header, .base-visit-files article { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.base-visit-files header { justify-content: space-between; }
.base-visit-files article > a, .base-visit-files article > span { flex: 1; min-width: 0; overflow-wrap: anywhere; font-size: 13px; }
.base-visit-files article > a { color: var(--el-color-primary); }
@media (max-width: 680px) { .base-visit-form { grid-template-columns: minmax(0, 1fr); } .base-visit-facts { grid-template-columns: 90px minmax(0, 1fr); } }
</style>
