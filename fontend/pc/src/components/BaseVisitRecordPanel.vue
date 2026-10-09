<template>
  <section class="visit-record-panel">
    <header><strong>走访记录</strong><span>记录实际走访情况</span></header>
    <el-alert v-if="state.message" :title="state.message" type="error" :closable="false" />
    <DataListPanel :columns="columns" :filters="filters" :filter-values="state.filters" :extra-filter-keys="['actual_date']" :rows="state.rows" :pagination="state.pagination" :loading="state.loading" :storage-key="`practical:pc:columns:${sessionKey}:baseVisitRecords`" @filter-change="setFilter" @search="loadList(1)" @reset="resetFilters" @page-change="loadList">
      <template #filters><label class="date-filter"><span>实际走访日期</span><input v-model="state.filters.actual_date" type="date" @change="loadList(1)"></label></template>
      <template #toolbar><el-button :disabled="busy" @click="loadList(state.pagination.page)">刷新</el-button><el-button v-if="state.canWrite" type="primary" :disabled="busy" @click="createRecord">新增记录</el-button></template>
      <template #actions="{ row }"><el-button :disabled="busy" @click="openRecord(row.id, false)">查看详情</el-button><el-button v-if="row.can_edit" :disabled="busy" @click="openRecord(row.id, true)">修改记录</el-button></template>
    </DataListPanel>
    <OperationDialog :visible="Boolean(state.mode)" :title="state.mode === 'detail' ? '走访记录详情' : state.form.id ? '修改走访记录' : '新增走访记录'" :busy="busy" dialog-class="visit-record-dialog" @close="closeDialog">
      <div class="record-body" v-loading="state.detailLoading">
        <el-alert v-if="state.dialogError" :title="state.dialogError" type="error" :closable="false" />
        <template v-if="!state.detailLoading && state.mode === 'detail'">
          <dl class="record-facts"><dt>基地名称</dt><dd>{{ state.item.base_name || '—' }}</dd><dt>基地地址</dt><dd>{{ state.item.base_address || '—' }}</dd><dt>实际走访时间</dt><dd>{{ state.item.actual_at }}</dd><dt>参加人员</dt><dd>{{ state.item.participants || '—' }}</dd><dt>接待人员</dt><dd>{{ state.item.contact_person || '—' }}</dd><dt>关联安排</dt><dd>{{ state.item.visit_id ? `安排 #${state.item.visit_id}` : '独立记录' }}</dd><dt>更新时间</dt><dd>{{ state.item.updated_at }}</dd></dl>
          <section v-for="field in textFields" :key="field.key" class="record-text"><strong>{{ field.label }}</strong><p>{{ state.item[field.key] || '—' }}</p></section>
          <div class="record-files"><article v-for="file in state.item.attachments || []" :key="file.file_id || file.id"><span>{{ fileName(file) }}</span><FilePreviewButton :file="file" /><a v-if="file.url" :href="backendUrl(file.url)" target="_blank" rel="noopener noreferrer">下载</a></article></div>
        </template>
        <form v-else-if="!state.detailLoading && state.mode === 'edit'" class="record-form" @submit.prevent="saveRecord">
          <label><span>实习基地（必选）</span><el-select v-model="state.form.base_id" filterable remote :remote-method="searchBases" :loading="state.baseLoading" :disabled="Boolean(state.form.id || state.form.visit_id)" placeholder="输入基地名称搜索"><el-option v-for="base in state.bases" :key="base.id" :value="Number(base.id)" :label="base.name" /></el-select></label>
          <label><span>关联走访安排（可选）</span><el-select v-model="state.form.visit_id" filterable remote clearable :remote-method="searchPlans" :loading="state.planLoading" :disabled="Boolean(state.form.id)" placeholder="输入基地或人员搜索" @change="selectPlan"><el-option v-for="plan in state.plans" :key="plan.id" :value="Number(plan.id)" :label="`${plan.base_name} · ${plan.visit_date || '未填写日期'} · #${plan.id}`" /></el-select><el-button v-if="state.planHasMore && !state.form.id" :loading="state.planLoading" :disabled="busy || state.planLoading" @click="loadMorePlans">加载更多安排</el-button></label>
          <label><span>实际走访时间（必填）</span><input v-model="state.form.actual_at" type="datetime-local" step="1" required></label>
          <label><span>参加人员（必选）</span><el-select v-model="state.form.participant_ids" multiple filterable remote :remote-method="searchParticipants" :loading="state.participantLoading" placeholder="输入姓名或单位搜索"><el-option v-for="person in state.people" :key="person.account_id" :value="Number(person.account_id)" :label="personLabel(person)" /></el-select></label>
          <label><span>参加人员补充</span><el-input v-model="state.form.participants" maxlength="500" placeholder="留空时使用所选人员姓名；可补充其他参加人员" /></label>
          <label><span>接待人员</span><el-input v-model="state.form.contact_person" maxlength="180" /></label>
          <label v-for="field in textFields" :key="field.key" class="wide"><span>{{ field.label }}{{ field.key === 'content' ? '（必填）' : '' }}</span><el-input v-model="state.form[field.key]" type="textarea" :rows="field.key === 'content' ? 6 : 3" :maxlength="field.max" /></label>
          <div class="record-files wide"><header><strong>附件（{{ state.form.attachments.length }}/20）</strong><el-button :icon="Upload" :loading="state.uploading" :disabled="busy || state.form.attachments.length >= 20" @click="attachmentInput?.click()">上传附件</el-button><input ref="attachmentInput" type="file" hidden multiple :disabled="busy" @change="uploadAttachments"></header><small v-if="!state.form.attachments.length">暂无附件</small><article v-for="file in state.form.attachments" :key="file.file_id || file.id"><span>{{ fileName(file) }}</span><FilePreviewButton :file="file" /><el-button :disabled="busy" link type="danger" @click="removeAttachment(file)">移除</el-button></article></div>
        </form>
      </div>
      <template #footer><el-button :disabled="busy" @click="closeDialog">关闭</el-button><el-button v-if="state.mode === 'edit'" type="primary" :loading="state.saving" :disabled="busy || state.detailLoading || state.linkLoading" @click="saveRecord">保存记录</el-button><el-button v-else-if="state.item.can_edit" type="primary" :disabled="busy" @click="openRecord(state.item.id, true)">修改记录</el-button></template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Upload } from '@lucide/vue';
import DataListPanel from './DataListPanel.vue';
import OperationDialog from './OperationDialog.vue';
import FilePreviewButton from '../../../shared/components/FilePreviewButton.vue';
import { backendUrl } from '../api/client';
import { uploadFile } from '../api/system';
import { fetchBaseVisitRecords, fetchBaseVisitRecordDetail, saveIndependentBaseVisitRecord, fetchBaseVisitOptions, fetchBaseVisits, fetchBaseVisitDetail } from '../api/baseVisits';

const props = defineProps({ sessionKey: { type: String, default: '' } });
const columns = [{ prop: 'base_name', label: '基地名称', minWidth: 170 }, { prop: 'actual_at', label: '实际走访时间', minWidth: 170 }, { prop: 'participants', label: '参加人员', minWidth: 160 }, { prop: 'contact_person', label: '接待人员', minWidth: 120 }, { prop: 'author_name', label: '填写人', minWidth: 110 }, { prop: 'updated_at', label: '更新时间', minWidth: 170 }];
const filters = [{ key: 'keyword', label: '关键词', type: 'text', placeholder: '基地或人员' }];
const textFields = [{ key: 'content', label: '走访内容', max: 20000 }, { key: 'problems', label: '发现问题', max: 10000 }, { key: 'follow_up', label: '后续措施', max: 10000 }];
const initialState = () => ({ rows: [], filters: { keyword: '', actual_date: '' }, pagination: { page: 1, page_size: 20, total: 0 }, canWrite: false, loading: false, message: '', mode: '', dialogError: '', detailLoading: false, saving: false, uploading: false, linkLoading: false, item: {}, form: {}, bases: [], people: [], plans: [], baseLoading: false, participantLoading: false, planLoading: false, planKeyword: '', planPage: 0, planHasMore: false });
const state = reactive(initialState());
const attachmentInput = ref(null);
const busy = computed(() => state.saving || state.uploading);
const requests = new Map();
const timers = new Map();
let session = 0;
let dialog = 0;

function begin(key) {
  requests.get(key)?.controller.abort();
  const ticket = { controller: new AbortController(), session, dialog };
  requests.set(key, ticket);
  return ticket;
}
function current(key, ticket) { return requests.get(key) === ticket && ticket.session === session && (key === 'list' || ticket.dialog === dialog); }
function merge(items, preserved, key) { return [...new Map([...items, ...preserved].map(item => [Number(item[key]), item])).values()]; }
function personLabel(person) { return [person.name || '人员', person.dep_name || person.organization_name].filter(Boolean).join(' · '); }
function fileName(file) { return file.download_name || file.name || file.file_name || '附件'; }
function setFilter({ key, value }) { state.filters[key] = value ?? ''; }
function resetFilters() { state.filters = { keyword: '', actual_date: '' }; loadList(1); }

async function loadList(page = 1) {
  const ticket = begin('list'); state.loading = true;
  try {
    const data = await fetchBaseVisitRecords({ ...state.filters, page, page_size: state.pagination.page_size }, { signal: ticket.controller.signal });
    if (!current('list', ticket)) return;
    state.rows = data.items || []; state.pagination = data.pagination; state.canWrite = data.can_write_record === true; state.message = '';
  } catch (error) { if (current('list', ticket)) state.message = error.message; }
  finally { if (current('list', ticket)) { state.loading = false; requests.delete('list'); } }
}

async function options(kind, keyword = '', page = 1) {
  const ticket = begin(kind); state[`${kind}Loading`] = true;
  try {
    const data = kind === 'plan'
      ? await fetchBaseVisits({ keyword, record_eligible: '1', page, page_size: 50 }, { signal: ticket.controller.signal })
      : await fetchBaseVisitOptions({ [`${kind}_keyword`]: keyword }, { signal: ticket.controller.signal });
    if (!current(kind, ticket)) return;
    if (kind === 'base') state.bases = merge(data.bases || [], state.bases.filter(base => Number(base.id) === state.form.base_id), 'id');
    if (kind === 'participant') state.people = merge(data.participants || [], state.people.filter(person => state.form.participant_ids?.includes(Number(person.account_id))), 'account_id');
    if (kind === 'plan') {
      const preserved = state.plans.filter(plan => Number(plan.id) === state.form.visit_id);
      state.plans = merge(page > 1 ? merge(state.plans, data.items || [], 'id') : data.items || [], preserved, 'id');
      state.planKeyword = keyword; state.planPage = data.pagination.page;
      state.planHasMore = data.pagination.page * data.pagination.page_size < data.pagination.total;
    }
  } catch (error) { if (current(kind, ticket)) state.dialogError = error.message; }
  finally { if (current(kind, ticket)) { state[`${kind}Loading`] = false; requests.delete(kind); } }
}
function loadMorePlans() { if (!state.planLoading && state.planHasMore) options('plan', state.planKeyword, state.planPage + 1); }
function queue(kind, keyword) {
  if (kind === 'plan') { state.planKeyword = keyword; state.planHasMore = false; }
  clearTimeout(timers.get(kind)); requests.get(kind)?.controller.abort(); requests.delete(kind);
  timers.set(kind, setTimeout(() => options(kind, keyword), 250));
}
const searchBases = keyword => queue('base', keyword);
const searchParticipants = keyword => queue('participant', keyword);
const searchPlans = keyword => queue('plan', keyword);
function closeDialog() {
  if (busy.value) return;
  dialog += 1;
  for (const [key, ticket] of requests) if (key !== 'list') { ticket.controller.abort(); requests.delete(key); }
  for (const timer of timers.values()) clearTimeout(timer); timers.clear();
  Object.assign(state, { mode: '', dialogError: '', detailLoading: false, linkLoading: false, form: {}, item: {}, bases: [], people: [], plans: [], baseLoading: false, participantLoading: false, planLoading: false, planKeyword: '', planPage: 0, planHasMore: false });
}
function localDateTime() {
  const date = new Date(); const pad = value => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}
function createRecord() {
  if (busy.value || !state.canWrite) return;
  closeDialog(); state.mode = 'edit';
  state.form = { base_id: null, visit_id: null, actual_at: localDateTime(), participant_ids: [], participants: '', contact_person: '', content: '', problems: '', follow_up: '', attachments: [] };
  options('base'); options('participant'); options('plan');
}
async function openRecord(id, edit) {
  if (busy.value) return;
  closeDialog(); state.mode = 'detail'; state.detailLoading = true;
  const ticket = begin('detail');
  try {
    const data = await fetchBaseVisitRecordDetail(id, { signal: ticket.controller.signal });
    if (!current('detail', ticket)) return;
    state.item = data.item;
    if (edit && !data.item.can_edit) { state.dialogError = '无修改记录权限'; return; }
    if (edit) {
      state.form = { ...data.item, participants: data.item.participants_auto_generated ? '' : data.item.participants || '', actual_at: data.item.actual_at.replace(' ', 'T'), participant_ids: [...data.item.participant_ids], attachments: [...data.item.attachments] };
      state.bases = [{ id: data.item.base_id, name: data.item.base_name }];
      state.people = merge(data.item.participant_ids.map(account_id => ({ account_id, name: '历史参加人员' })), data.item.participant_rows || [], 'account_id');
      state.plans = data.item.visit_id ? [{ id: data.item.visit_id, base_name: data.item.base_name }] : [];
      state.mode = 'edit'; options('participant');
    }
  } catch (error) { if (current('detail', ticket)) state.dialogError = error.message; }
  finally { if (current('detail', ticket)) { state.detailLoading = false; requests.delete('detail'); } }
}
async function selectPlan(id) {
  requests.get('link')?.controller.abort(); requests.delete('link'); state.linkLoading = false;
  if (!id) { state.form.visit_revision = null; return; }
  const ticket = begin('link'); state.linkLoading = true;
  try {
    const data = await fetchBaseVisitDetail(id, { signal: ticket.controller.signal });
    if (!current('link', ticket)) return;
    if (!data.permissions.can_record || data.record) { state.form.visit_id = null; state.dialogError = data.record ? '此安排已有记录，请从记录列表打开修改' : '此安排不能填写记录'; return; }
    state.form.base_id = data.item.base_id; state.form.visit_revision = data.item.revision;
    state.form.participant_ids = [...data.item.participant_ids]; state.people = merge(state.people, data.item.participant_rows || [], 'account_id');
    state.bases = merge(state.bases, [{ id: data.item.base_id, name: data.item.base_name }], 'id'); state.dialogError = '';
  } catch (error) { if (current('link', ticket)) { state.form.visit_id = null; state.dialogError = error.message; } }
  finally { if (current('link', ticket)) { state.linkLoading = false; requests.delete('link'); } }
}
async function saveRecord() {
  if (busy.value || state.detailLoading || state.linkLoading || state.mode !== 'edit') return;
  if (!state.form.base_id || !state.form.actual_at || !state.form.participant_ids?.length || !state.form.content?.trim()) { state.dialogError = '请选择基地、参加人员，并填写实际走访时间和内容'; return; }
  const version = session; const opened = dialog;
  const form = state.form;
  const payload = { id: form.id, revision: form.revision, visit_id: form.visit_id, visit_revision: form.visit_revision, base_id: form.base_id, actual_at: form.actual_at.replace('T', ' '), participant_ids: form.participant_ids, participants: form.participants, participants_text_mode: form.participants?.trim() ? 'custom' : 'auto', contact_person: form.contact_person, content: form.content, problems: form.problems, follow_up: form.follow_up, attachment_ids: [...new Set(form.attachments.map(file => Number(file.file_id || file.id)))] };
  if (payload.actual_at.length === 16) payload.actual_at += ':00';
  state.saving = true; state.dialogError = '';
  try {
    const data = await saveIndependentBaseVisitRecord(payload);
    if (version !== session || opened !== dialog) return;
    state.item = data.item; state.mode = 'detail'; state.form = {}; await loadList(state.pagination.page);
  } catch (error) { if (version === session && opened === dialog) state.dialogError = error.message; }
  finally { if (version === session && opened === dialog) state.saving = false; }
}
async function uploadAttachments(event) {
  const files = Array.from(event.target.files || []); event.target.value = '';
  if (!files.length || busy.value || state.mode !== 'edit') return;
  if (state.form.attachments.length + files.length > 20) { state.dialogError = '附件最多 20 个'; return; }
  const version = session; const opened = dialog; state.uploading = true;
  try {
    for (const file of files) {
      const uploaded = await uploadFile(file, { category: 'base_visit', is_temporary: 'false' });
      if (version !== session || opened !== dialog) return;
      state.form.attachments.push({ ...uploaded, file_id: uploaded.file_id, name: uploaded.name || file.name });
    }
  } catch (error) { if (version === session && opened === dialog) state.dialogError = error.message; }
  finally { if (version === session && opened === dialog) state.uploading = false; }
}
function removeAttachment(file) { state.form.attachments = state.form.attachments.filter(item => Number(item.file_id || item.id) !== Number(file.file_id || file.id)); }
function invalidate() { session += 1; dialog += 1; for (const ticket of requests.values()) ticket.controller.abort(); requests.clear(); for (const timer of timers.values()) clearTimeout(timer); timers.clear(); }
watch(() => props.sessionKey, () => { invalidate(); Object.assign(state, initialState()); loadList(1); }, { immediate: true, flush: 'sync' });
onBeforeUnmount(invalidate);
</script>

<style scoped>
.visit-record-panel { height: 100%; min-height: 0; display: flex; flex-direction: column; gap: 10px; padding: 12px; box-sizing: border-box; }
.visit-record-panel > header { display: flex; align-items: baseline; gap: 16px; }
.visit-record-panel > header span, .date-filter { font-size: 12px; color: var(--muted); }
.visit-record-panel > .data-list-panel { flex: 1; min-height: 0; }
.date-filter { display: flex; align-items: center; gap: 8px; }
.date-filter input, .record-form input[type='datetime-local'] { color: var(--text); background: var(--surface); border: 1px solid var(--line); border-radius: var(--control-radius); padding: 8px; font: inherit; box-sizing: border-box; }
:global(.operation-dialog.visit-record-dialog) { width: min(860px, calc(100vw - 48px)); }
.record-body { padding: 18px; display: flex; flex-direction: column; gap: 16px; min-height: 160px; overflow: auto; }
.record-facts { display: grid; grid-template-columns: 110px minmax(0, 1fr); gap: 10px 16px; font-size: 13px; }
.record-facts dt { color: var(--muted); }
.record-facts dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.record-text p { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.8; font-size: 13px; }
.record-text strong, .record-files strong { font-size: 13px; }
.record-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.record-form > label { display: flex; flex-direction: column; gap: 8px; font-size: 13px; min-width: 0; }
.record-form .el-select { width: 100%; }
.wide { grid-column: 1 / -1; }
.record-files { display: flex; flex-direction: column; gap: 10px; }
.record-files header, .record-files article { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.record-files header { justify-content: space-between; }
.record-files small { color: var(--muted); font-size: 12px; }
.record-files article > span { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.record-files a { color: var(--el-color-primary); }
@media (max-width: 680px) { .record-form { grid-template-columns: minmax(0, 1fr); } }
</style>
