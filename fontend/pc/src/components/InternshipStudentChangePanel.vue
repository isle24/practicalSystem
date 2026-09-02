<template>
  <section class="student-change-panel">
    <div class="student-change-tabs">
      <button type="button" :class="{ active: activeView === 'profiles' }" @click="switchView('profiles')">当前资料</button>
      <button type="button" :class="{ active: activeView === 'changes' }" @click="switchView('changes')">变更记录</button>
    </div>

    <el-alert v-if="message" :title="message" type="warning" :closable="false" show-icon />

    <DataListPanel
      v-if="activeView === 'profiles'"
      :columns="profileColumns"
      :filters="listFilters"
      :filter-values="filters"
      :loading="loading"
      :pagination="profiles.pagination"
      :rows="profiles.items"
      storage-key="internship-student-profiles"
      @filter-change="updateFilter"
      @page-change="loadProfiles"
      @reset="resetFilters"
      @search="loadProfiles(1)"
    >
      <template #actions="{ row }">
        <el-button size="small" type="primary" plain @click="openProfile(row)">查看</el-button>
        <el-button v-if="isStudent" size="small" type="primary" @click="openChange(row)">申请变更</el-button>
      </template>
    </DataListPanel>

    <DataListPanel
      v-else
      :columns="changeColumns"
      :filters="listFilters"
      :filter-values="filters"
      :loading="loading"
      :pagination="changes.pagination"
      :rows="changes.items"
      storage-key="internship-student-changes"
      @filter-change="updateFilter"
      @page-change="loadChanges"
      @reset="resetFilters"
      @search="loadChanges(1)"
    >
      <template #toolbar>
        <el-button v-if="isStudent && taskProfiles.length" :icon="Plus" @click="openChange(taskProfiles[0])">新增变更</el-button>
      </template>
      <template #actions="{ row }">
        <el-button size="small" type="primary" plain @click="openDetail(row)">查看</el-button>
        <el-button v-if="isStudent && ['draft', 'modify'].includes(row.status)" link type="primary" @click="openChange(null, row)">编辑</el-button>
        <el-button v-if="isTeacher && canApprove && row.status === 'wait'" link type="success" @click="openReview(row)">审核</el-button>
      </template>
    </DataListPanel>

    <OperationDialog
      :visible="dialog.visible"
      :title="dialog.title"
      :busy="saving"
      dialog-class="student-change-dialog"
      @close="closeDialog"
    >
      <div v-if="dialog.mode === 'profile'" class="student-change-detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="学生">{{ dialog.item.student_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="实习任务">{{ dialog.item.arrangement_title || '-' }}</el-descriptions-item>
          <el-descriptions-item label="实习地点">{{ dialog.item.location || '-' }}</el-descriptions-item>
          <el-descriptions-item label="实习岗位">{{ dialog.item.position || '-' }}</el-descriptions-item>
          <el-descriptions-item label="开始日期">{{ dialog.item.start_date || '-' }}</el-descriptions-item>
          <el-descriptions-item label="结束日期">{{ dialog.item.end_date || '-' }}</el-descriptions-item>
          <el-descriptions-item label="资料状态">{{ statusText(dialog.item.status) }}</el-descriptions-item>
          <el-descriptions-item label="生效时间">{{ dialog.item.effective_at || '-' }}</el-descriptions-item>
        </el-descriptions>
      </div>

      <el-form v-else-if="dialog.mode === 'edit'" label-position="top" class="student-change-form">
        <div class="student-change-form-grid">
          <el-form-item label="实习任务" class="span-two">
            <el-select v-model="form.arrangement_id" filterable placeholder="请选择实习任务" :disabled="Boolean(form.id)" @change="selectProfile">
              <el-option v-for="item in taskProfiles" :key="item.arrangement_id" :label="profileTaskLabel(item)" :value="item.arrangement_id" />
            </el-select>
          </el-form-item>
          <el-form-item label="变更类型">
            <el-select v-model="form.change_type" placeholder="请选择变更类型">
              <el-option v-for="item in changeTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="期望生效日期">
            <input v-model="form.effective_date" class="native-control" type="date">
          </el-form-item>
          <template v-if="showCompanyFields">
            <el-form-item label="实习单位">
              <el-select v-model="form.after_payload.company_id" clearable filterable placeholder="请选择实习单位" @change="normalizeCompanyRelations">
                <el-option v-for="item in options.companies || []" :key="item.company_id" :label="item.company_name" :value="item.company_id" />
              </el-select>
            </el-form-item>
            <el-form-item label="实习基地">
              <el-select v-model="form.after_payload.base_id" clearable filterable placeholder="请选择实习基地">
                <el-option v-for="item in availableBases" :key="item.id" :label="item.name" :value="item.id" />
              </el-select>
            </el-form-item>
          </template>
          <el-form-item v-if="showMentorField" label="企业导师" class="span-two">
            <el-select v-model="form.after_payload.enterprise_mentor_id" clearable filterable placeholder="请选择企业导师">
              <el-option v-for="item in availableMentors" :key="item.id" :label="mentorLabel(item)" :value="item.id" />
            </el-select>
          </el-form-item>
          <template v-if="showLocationFields">
            <el-form-item label="实习地点">
              <el-input v-model="form.after_payload.location" maxlength="255" placeholder="请输入实习地点" />
            </el-form-item>
            <el-form-item label="实习岗位">
              <el-input v-model="form.after_payload.position" maxlength="180" placeholder="请输入实习岗位" />
            </el-form-item>
            <el-form-item label="开始日期">
              <input v-model="form.after_payload.start_date" class="native-control" type="date">
            </el-form-item>
            <el-form-item label="结束日期">
              <input v-model="form.after_payload.end_date" class="native-control" type="date">
            </el-form-item>
          </template>
          <el-form-item label="变更原因" class="span-two">
            <el-input v-model="form.reason" type="textarea" :rows="4" maxlength="2000" show-word-limit placeholder="请说明变更原因" />
          </el-form-item>
        </div>
      </el-form>

      <div v-else-if="dialog.mode === 'review'" class="student-change-review">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="学生">{{ dialog.item.student_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="实习任务">{{ dialog.item.arrangement_title || '-' }}</el-descriptions-item>
          <el-descriptions-item label="变更类型">{{ changeTypeText(dialog.item.change_type) }}</el-descriptions-item>
          <el-descriptions-item label="变更原因">{{ dialog.item.reason || '-' }}</el-descriptions-item>
        </el-descriptions>
        <el-segmented v-model="review.status" :options="reviewStatusOptions" />
        <el-input v-model="review.opinion" type="textarea" :rows="5" maxlength="500" show-word-limit placeholder="请填写审核意见" />
      </div>

      <div v-else class="student-change-detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="学生">{{ dialog.item.student_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="实习任务">{{ dialog.item.arrangement_title || '-' }}</el-descriptions-item>
          <el-descriptions-item label="变更类型">{{ changeTypeText(dialog.item.change_type) }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ statusText(dialog.item.status) }}</el-descriptions-item>
          <el-descriptions-item label="变更原因" :span="2">{{ dialog.item.reason || '-' }}</el-descriptions-item>
        </el-descriptions>
        <div class="student-change-snapshots">
          <section><strong>变更前</strong><dl><template v-for="item in snapshotItems(dialog.item.before_payload)" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></template></dl></section>
          <section><strong>变更后</strong><dl><template v-for="item in snapshotItems(dialog.item.after_payload)" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></template></dl></section>
        </div>
        <el-timeline v-if="dialog.records.length" class="student-change-timeline">
          <el-timeline-item v-for="item in dialog.records" :key="item.id" :timestamp="item.created_at" placement="top">
            <strong>{{ statusText(item.from_status) }} → {{ statusText(item.to_status) }}</strong>
            <p>{{ item.content || item.opinion || '-' }}</p>
          </el-timeline-item>
        </el-timeline>
      </div>

      <template #footer>
        <el-button @click="closeDialog">关闭</el-button>
        <template v-if="dialog.mode === 'edit'">
          <el-button :disabled="saving" :loading="saving" @click="saveChange('draft')">保存草稿</el-button>
          <el-button type="primary" :disabled="saving" :loading="saving" @click="saveChange('wait')">提交审核</el-button>
        </template>
        <el-button v-else-if="dialog.mode === 'review'" type="primary" :disabled="saving" :loading="saving" @click="submitReview">提交审核</el-button>
      </template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Plus } from '@lucide/vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  fetchInternshipMentors,
  fetchInternshipStudentChangeDetail,
  fetchInternshipStudentChanges,
  fetchInternshipStudentProfiles,
  reviewInternshipStudentChange,
  saveInternshipStudentChange,
} from '../api/system';
import DataListPanel from './DataListPanel.vue';
import OperationDialog from './OperationDialog.vue';

const props = defineProps({
  roleType: { type: String, default: '' },
  options: { type: Object, default: () => ({}) },
  defaultFilters: { type: Object, default: () => ({}) },
  canApprove: { type: Boolean, default: false },
});

const isStudent = computed(() => props.roleType === 'student');
const isTeacher = computed(() => props.roleType === 'teacher');
const activeView = ref(props.roleType === 'student' ? 'profiles' : 'changes');
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const mentors = ref([]);
const profiles = reactive(emptyList());
const changes = reactive(emptyList());
const filters = reactive(emptyFilters());
const dialog = reactive(emptyDialog());
const form = reactive(emptyForm());
const review = reactive({ status: 'accept', opinion: '' });

const changeTypeOptions = [
  { value: 'profile', label: '综合资料' },
  { value: 'company', label: '实习单位' },
  { value: 'mentor', label: '企业导师' },
  { value: 'location', label: '地点或岗位' },
  { value: 'termination', label: '终止实习' },
  { value: 'other', label: '其他变更' },
];
const reviewStatusOptions = [
  { value: 'accept', label: '通过' },
  { value: 'modify', label: '退回修改' },
];
const showCompanyFields = computed(() => ['profile', 'company', 'other'].includes(form.change_type));
const showMentorField = computed(() => ['profile', 'company', 'mentor', 'other'].includes(form.change_type));
const showLocationFields = computed(() => ['profile', 'location', 'other'].includes(form.change_type));
const availableBases = computed(() => (props.options.bases || []).filter(item => !form.after_payload.company_id || Number(item.company_id) === Number(form.after_payload.company_id)));
const availableMentors = computed(() => mentors.value.filter(item => !form.after_payload.company_id || Number(item.company_id) === Number(form.after_payload.company_id)));
const selectedCategory = computed(() => (props.options.internship_categories || []).find(item => Number(item.id) === Number(filters.category_id || 0)) || null);
const filteredProfessions = computed(() => (props.options.professions || []).filter((item) => {
  const gradeMatches = !filters.grade_id || Number(item.grade_id || 0) === Number(filters.grade_id);
  const departmentMatches = !filters.dep_id || Number(item.dep_id || 0) === Number(filters.dep_id);
  return gradeMatches && departmentMatches;
}));
const filteredArrangements = computed(() => (props.options.arrangements || []).filter((item) => {
  const categoryMatches = !filters.category_id || Number(item.category_id || 0) === Number(filters.category_id);
  const gradeMatches = !filters.grade_id || Number(item.grade_id || 0) === Number(filters.grade_id);
  const cohortMatches = !filters.graduation_cohort_id || Number(item.graduation_cohort_id || 0) === Number(filters.graduation_cohort_id);
  const departmentMatches = !filters.dep_id || Number(item.dep_id || 0) === Number(filters.dep_id);
  const professionMatches = !filters.profession_id || Number(item.profession_id || 0) === Number(filters.profession_id);
  return categoryMatches && gradeMatches && cohortMatches && departmentMatches && professionMatches;
}));
const taskProfiles = computed(() => {
  const items = new Map();
  (profiles.items || []).forEach(item => items.set(Number(item.arrangement_id), item));
  (props.options.arrangements || []).forEach((item) => {
    const arrangementId = Number(item.id || item.arrangement_id || 0);
    if (arrangementId > 0 && !items.has(arrangementId)) {
      items.set(arrangementId, {
        arrangement_id: arrangementId,
        arrangement_title: item.title || item.name || '',
        course_name: item.course_name || '',
      });
    }
  });
  return Array.from(items.values());
});
const listFilters = computed(() => {
  if (isStudent.value) return [];
  const scopeFilter = selectedCategory.value?.scope_type === 'cohort'
    ? selectFilter('graduation_cohort_id', '毕业届次', props.options.graduation_cohorts, 'cohort_id', 'cohort_name')
    : selectedCategory.value
      ? selectFilter('grade_id', '年级', props.options.grades, 'grade_id', 'grade_name')
      : null;
  return [
    selectFilter('category_id', '实习类别', props.options.internship_categories, 'id', 'name'),
    scopeFilter,
    selectFilter('dep_id', '学院', props.options.departments, 'dep_id', 'dep_name'),
    selectFilter('profession_id', '专业', filteredProfessions.value, 'profession_id', 'profession_name'),
    selectFilter('arrangement_id', '实习任务', filteredArrangements.value, 'id', 'title'),
    { key: 'status', label: '状态', type: 'select', options: statusOptions() },
    { key: 'keyword', label: '关键词', placeholder: '学生、学号、任务或原因' },
  ].filter(Boolean);
});
const profileColumns = [
  { prop: 'student_name', label: '学生', width: 110 },
  { prop: 'student_num', label: '学号', width: 130 },
  { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
  { prop: 'dep_name', label: '学院', minWidth: 130 },
  { prop: 'profession_name', label: '专业', minWidth: 130 },
  { prop: 'location', label: '实习地点', minWidth: 180 },
  { prop: 'position', label: '实习岗位', minWidth: 130 },
  { key: 'period', label: '实习时间', minWidth: 190, formatter: row => dateRange(row.start_date, row.end_date) },
  { key: 'status', label: '状态', width: 90, tag: true, tagType: row => statusType(row.status), formatter: row => statusText(row.status) },
];
const changeColumns = [
  { prop: 'student_name', label: '学生', width: 110 },
  { prop: 'student_num', label: '学号', width: 130 },
  { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
  { key: 'change_type', label: '变更类型', width: 110, formatter: row => changeTypeText(row.change_type) },
  { prop: 'reason', label: '变更原因', minWidth: 220 },
  { prop: 'effective_date', label: '生效日期', width: 110 },
  { key: 'status', label: '状态', width: 90, tag: true, tagType: row => statusType(row.status), formatter: row => statusText(row.status) },
  { prop: 'submitted_at', label: '提交时间', width: 168 },
];

onMounted(async () => {
  applyDefaults();
  await Promise.all([loadProfiles(1), loadChanges(1), loadMentors()]);
});

watch(() => props.roleType, role => {
  activeView.value = role === 'student' ? 'profiles' : 'changes';
});

watch(() => JSON.stringify(props.defaultFilters || {}), () => {
  if (applyDefaults()) loadActive(1);
});

watch(() => JSON.stringify([
  props.options.internship_categories,
  props.options.grades,
  props.options.graduation_cohorts,
]), () => {
  if (applyDefaults()) loadActive(1);
});

async function loadProfiles(page = 1) {
  await loadList(profiles, fetchInternshipStudentProfiles, page);
}

async function loadChanges(page = 1) {
  await loadList(changes, fetchInternshipStudentChanges, page);
}

async function loadList(target, fetcher, page) {
  loading.value = true;
  message.value = '';
  try {
    const data = await fetcher(queryParams(page));
    Object.assign(target, emptyList(), data || {});
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

async function loadMentors() {
  try {
    const data = await fetchInternshipMentors({ page: 1, page_size: 100 });
    mentors.value = data.items || [];
  } catch {
    mentors.value = [];
  }
}

function loadActive(page = 1) {
  return activeView.value === 'profiles' ? loadProfiles(page) : loadChanges(page);
}

function switchView(view) {
  activeView.value = view;
  loadActive(1);
}

function queryParams(page) {
  const params = { page, page_size: 20 };
  if (!isStudent.value) {
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) params[key] = value;
    });
  }
  return params;
}

function updateFilter({ key, value }) {
  filters[key] = value ?? '';
  if (key === 'category_id') normalizeCategoryFilters();
  if (key === 'dep_id' && filters.profession_id && !(props.options.professions || []).some(item => Number(item.profession_id) === Number(filters.profession_id) && Number(item.dep_id) === Number(value))) {
    filters.profession_id = '';
  }
  if (filters.profession_id && !filteredProfessions.value.some(item => Number(item.profession_id) === Number(filters.profession_id))) {
    filters.profession_id = '';
  }
  if (filters.arrangement_id && !filteredArrangements.value.some(item => Number(item.id) === Number(filters.arrangement_id))) {
    filters.arrangement_id = '';
  }
}

function resetFilters() {
  Object.assign(filters, emptyFilters());
  applyDefaults();
  loadActive(1);
}

function applyDefaults() {
  let changed = false;
  if (!filters.category_id && props.options.internship_categories?.length) {
    filters.category_id = defaultCategoryId();
    changed = true;
  }
  for (const key of ['grade_id', 'graduation_cohort_id', 'dep_id', 'profession_id']) {
    const value = props.defaultFilters?.[key];
    if ((filters[key] === '' || filters[key] === null) && value !== '' && value !== null && value !== undefined) {
      filters[key] = value;
      changed = true;
    }
  }
  if (normalizeCategoryFilters()) changed = true;
  return changed;
}

function defaultCategoryId() {
  const category = (props.options.internship_categories || []).find(item => item.scope_type === 'cohort' && props.options.graduation_cohorts?.length)
    || (props.options.internship_categories || []).find(item => item.scope_type === 'grade' && props.options.grades?.length)
    || props.options.internship_categories?.[0];
  return category?.id || '';
}

function normalizeCategoryFilters() {
  const category = selectedCategory.value;
  if (!category) return false;
  let changed = false;
  if (category.scope_type === 'cohort') {
    if (filters.grade_id !== '') {
      filters.grade_id = '';
      changed = true;
    }
    if (!filters.graduation_cohort_id) {
      filters.graduation_cohort_id = currentCohortId();
      changed = true;
    }
  } else {
    if (filters.graduation_cohort_id !== '') {
      filters.graduation_cohort_id = '';
      changed = true;
    }
    if (!filters.grade_id) {
      filters.grade_id = currentGradeId();
      changed = true;
    }
  }
  return changed;
}

function currentGradeId() {
  const current = (props.options.grades || []).find(item => ['true', '1'].includes(String(item.is_current)) || Number(item.is_current) === 1);
  return current?.grade_id || props.options.grades?.[0]?.grade_id || '';
}

function currentCohortId() {
  const current = (props.options.graduation_cohorts || []).find(item => ['true', '1'].includes(String(item.is_current)) || Number(item.is_current) === 1);
  return current?.cohort_id || props.options.graduation_cohorts?.[0]?.cohort_id || '';
}

function openProfile(row) {
  Object.assign(dialog, emptyDialog(), { visible: true, mode: 'profile', title: '学生实习资料', item: row });
}

function openChange(profile, change = null) {
  const selected = profile || taskProfiles.value.find(item => Number(item.arrangement_id) === Number(change?.arrangement_id)) || {};
  Object.assign(form, emptyForm(), {
    id: change?.id || null,
    arrangement_id: change?.arrangement_id || selected.arrangement_id || null,
    change_type: change?.change_type || 'profile',
    effective_date: change?.effective_date || '',
    reason: change?.reason || '',
    after_payload: { ...profilePayload(selected), ...(change?.after_payload || {}) },
  });
  Object.assign(dialog, emptyDialog(), { visible: true, mode: 'edit', title: change ? '编辑实习资料变更' : '新增实习资料变更', item: change || selected });
}

function selectProfile() {
  const profile = taskProfiles.value.find(item => Number(item.arrangement_id) === Number(form.arrangement_id));
  if (profile) form.after_payload = profilePayload(profile);
}

function normalizeCompanyRelations() {
  if (form.after_payload.base_id && !availableBases.value.some(item => Number(item.id) === Number(form.after_payload.base_id))) {
    form.after_payload.base_id = null;
  }
  if (form.after_payload.enterprise_mentor_id && !availableMentors.value.some(item => Number(item.id) === Number(form.after_payload.enterprise_mentor_id))) {
    form.after_payload.enterprise_mentor_id = null;
  }
}

async function openDetail(row) {
  saving.value = true;
  message.value = '';
  try {
    const data = await fetchInternshipStudentChangeDetail({ id: row.id });
    Object.assign(dialog, emptyDialog(), {
      visible: true,
      mode: 'detail',
      title: '实习资料变更详情',
      item: data.item || row,
      records: data.records || [],
      reviews: data.reviews || [],
    });
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

function openReview(row) {
  Object.assign(review, { status: 'accept', opinion: '' });
  Object.assign(dialog, emptyDialog(), { visible: true, mode: 'review', title: '审核实习资料变更', item: row });
}

function closeDialog() {
  if (!saving.value) Object.assign(dialog, emptyDialog());
}

async function saveChange(status) {
  if (saving.value) return;
  if (!form.arrangement_id) return ElMessage.warning('请选择实习任务');
  if (status === 'wait' && String(form.reason || '').trim().length < 1) return ElMessage.warning('请填写变更原因');
  if (status === 'wait') {
    try {
      await ElMessageBox.confirm('提交后将发送给校内指导教师审核，确认继续？', '提交确认', { type: 'warning' });
    } catch {
      return;
    }
  }
  saving.value = true;
  try {
    await saveInternshipStudentChange({
      id: form.id || undefined,
      arrangement_id: form.arrangement_id,
      change_type: form.change_type,
      effective_date: form.effective_date || undefined,
      reason: form.reason,
      after_payload: submissionPayload(),
      status,
    });
    ElMessage.success(status === 'wait' ? '变更申请已提交' : '变更草稿已保存');
    Object.assign(dialog, emptyDialog());
    await Promise.all([loadProfiles(1), loadChanges(1)]);
    activeView.value = 'changes';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function submitReview() {
  if (saving.value) return;
  if (review.status === 'modify' && String(review.opinion || '').trim().length < 5) return ElMessage.warning('退回原因至少填写 5 个字');
  try {
    await ElMessageBox.confirm(`确认${review.status === 'accept' ? '通过' : '退回'}该变更申请？`, '审核确认', { type: 'warning' });
  } catch {
    return;
  }
  saving.value = true;
  try {
    await reviewInternshipStudentChange({ id: dialog.item.id, status: review.status, opinion: review.opinion });
    ElMessage.success(review.status === 'accept' ? '变更申请已通过' : '变更申请已退回');
    Object.assign(dialog, emptyDialog());
    await loadChanges(1);
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

function submissionPayload() {
  const payload = { ...form.after_payload };
  if (form.change_type === 'termination') {
    payload.status = 'terminated';
    payload.terminated_at = form.effective_date || '';
    payload.termination_reason = form.reason;
  }
  return payload;
}

function profilePayload(profile = {}) {
  return {
    company_id: profile.company_id || null,
    base_id: profile.base_id || null,
    enterprise_mentor_id: profile.enterprise_mentor_id || null,
    location: profile.location || '',
    position: profile.position || '',
    start_date: profile.start_date || '',
    end_date: profile.end_date || '',
    status: profile.status || 'active',
  };
}

function snapshotItems(payload = {}) {
  const labels = {
    company_id: '实习单位', base_id: '实习基地', enterprise_mentor_id: '企业导师', location: '实习地点', position: '实习岗位',
    start_date: '开始日期', end_date: '结束日期', status: '资料状态', terminated_at: '终止日期', termination_reason: '终止原因',
  };
  return Object.entries(labels).map(([key, label]) => ({ label, value: snapshotValue(key, payload?.[key]) }));
}

function snapshotValue(key, value) {
  if (key === 'company_id') return (props.options.companies || []).find(item => Number(item.company_id) === Number(value))?.company_name || value || '-';
  if (key === 'base_id') return (props.options.bases || []).find(item => Number(item.id) === Number(value))?.name || value || '-';
  if (key === 'enterprise_mentor_id') return mentors.value.find(item => Number(item.id) === Number(value))?.name || value || '-';
  if (key === 'status') return statusText(value);
  return value || '-';
}

function emptyFilters() {
  return { category_id: '', graduation_cohort_id: '', grade_id: '', dep_id: '', profession_id: '', arrangement_id: '', status: '', keyword: '' };
}

function emptyList() {
  return { items: [], pagination: { page: 1, page_size: 20, total: 0 } };
}

function emptyDialog() {
  return { visible: false, mode: '', title: '', item: {}, records: [], reviews: [] };
}

function emptyForm() {
  return { id: null, arrangement_id: null, change_type: 'profile', effective_date: '', reason: '', after_payload: profilePayload() };
}

function selectFilter(key, label, items = [], valueKey, labelKey) {
  return { key, label, type: 'select', placeholder: `请选择${label}`, options: (items || []).map(item => ({ value: item[valueKey], label: item[labelKey] || item[valueKey] })) };
}

function statusOptions() {
  return ['draft', 'wait', 'accept', 'modify'].map(value => ({ value, label: statusText(value) }));
}

function statusText(value) {
  return ({ draft: '草稿', wait: '待审核', accept: '已通过', modify: '需修改', active: '有效', terminated: '已终止' }[value] || value || '-');
}

function statusType(value) {
  return ({ accept: 'success', active: 'success', wait: 'warning', draft: 'info', modify: 'danger', terminated: 'info' }[value] || 'primary');
}

function changeTypeText(value) {
  return changeTypeOptions.find(item => item.value === value)?.label || value || '-';
}

function dateRange(start, end) {
  return start || end ? `${start || '-'} 至 ${end || '-'}` : '-';
}

function profileTaskLabel(item) {
  return [item.arrangement_title, item.course_name, item.student_name].filter(Boolean).join(' / ') || `任务 ${item.arrangement_id}`;
}

function mentorLabel(item) {
  return [item.name, item.company_name, item.phone].filter(Boolean).join(' / ') || `导师 ${item.id}`;
}
</script>

<style scoped>
.student-change-panel {
  min-height: 0;
  height: 100%;
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr);
  gap: 10px;
}

.student-change-tabs {
  min-height: 38px;
  display: flex;
  align-items: flex-end;
  gap: 24px;
  border-bottom: 1px solid var(--line);
}

.student-change-tabs button {
  position: relative;
  height: 38px;
  border: 0;
  padding: 0 2px;
  color: var(--muted);
  background: transparent;
  font: inherit;
  cursor: pointer;
}

.student-change-tabs button.active {
  color: var(--primary);
  font-weight: 600;
}

.student-change-tabs button.active::after {
  position: absolute;
  right: 0;
  bottom: -1px;
  left: 0;
  height: 2px;
  background: var(--primary);
  content: '';
}

.student-change-form,
.student-change-review,
.student-change-detail {
  min-height: 0;
  overflow: auto;
  padding: 16px 18px;
}

.student-change-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px 16px;
}

.student-change-form-grid .span-two {
  grid-column: 1 / -1;
}

.student-change-form :deep(.el-select) {
  width: 100%;
}

.native-control {
  width: 100%;
  height: 40px;
  border: 1px solid var(--line);
  border-radius: var(--control-radius);
  padding: 0 12px;
  color: var(--text);
  background: #fff;
}

.student-change-review {
  display: grid;
  align-content: start;
  gap: 16px;
}

.student-change-snapshots {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  margin-top: 16px;
}

.student-change-snapshots section {
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 14px;
  background: #f8fafc;
}

.student-change-snapshots dl {
  display: grid;
  grid-template-columns: 92px minmax(0, 1fr);
  gap: 8px 12px;
  margin: 12px 0 0;
  font-size: 13px;
}

.student-change-snapshots dt {
  color: var(--muted);
}

.student-change-snapshots dd {
  min-width: 0;
  margin: 0;
  overflow-wrap: anywhere;
}

.student-change-timeline {
  margin-top: 18px;
}

.student-change-timeline p {
  margin: 5px 0 0;
  color: var(--muted);
}

@media (max-width: 760px) {
  .student-change-form-grid,
  .student-change-snapshots {
    grid-template-columns: 1fr;
  }

  .student-change-form-grid .span-two {
    grid-column: auto;
  }
}
</style>
