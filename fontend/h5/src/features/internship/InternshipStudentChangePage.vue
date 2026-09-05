<template>
  <section class="student-change-page">
    <AppSegmented
      v-if="isStudentRole"
      :model-value="activeView"
      :items="studentTabs"
      @update:model-value="switchView"
    />

    <MobileFilterSheet
      v-if="!isStudentRole"
      :select-filters="selectFilters"
      :status-options="statusOptions"
      :values="filters"
      keyword-placeholder="搜索学生、学号、任务或原因"
      :loading="loading"
      @update-filter="updateFilter"
      @search="loadChanges(1)"
      @reset="resetFilters"
    />

    <p v-if="message" class="student-change-message">{{ message }}</p>

    <section v-if="activeView === 'profiles'" class="student-change-section">
      <header class="student-change-section-head">
        <div>
          <strong>当前实习资料</strong>
          <span>按实习任务展示当前生效信息</span>
        </div>
      </header>
      <div class="student-change-list">
        <AppListCard
          v-for="profile in taskProfiles"
          :key="profile.arrangement_id"
          :title="profile.arrangement_title || profile.course_name || '实习任务'"
          :subtitle="profile.course_name || profile.task_no || '-'"
          :status="profile.id ? profile.status : 'pending'"
          :status-label="profile.id ? statusText(profile.status) : '待完善'"
          :meta="profileFacts(profile)"
          clickable
          @open="profile.id ? openProfile(profile) : openChange(profile)"
        >
          <template #actions>
            <AppButton variant="quiet" size="small" @click="openChange(profile)">申请变更</AppButton>
          </template>
        </AppListCard>
      </div>
      <div v-if="!taskProfiles.length && !loading" class="student-change-empty">暂无已绑定的实习任务</div>
    </section>

    <section v-else class="student-change-section">
      <header class="student-change-section-head">
        <div>
          <strong>资料变更记录</strong>
          <span>{{ changes.pagination.total || 0 }} 条申请</span>
        </div>
        <AppButton v-if="isStudentRole && taskProfiles.length" size="small" @click="openChange(taskProfiles[0])">
          <template #icon><Plus :size="16" /></template>
          新增
        </AppButton>
      </header>
      <div class="student-change-list">
        <AppListCard
          v-for="row in changes.items"
          :key="row.id"
          :title="isStudentRole ? (row.arrangement_title || '实习资料变更') : ([row.student_name, row.student_num].filter(Boolean).join(' / ') || '学生资料变更')"
          :subtitle="changeTypeText(row.change_type)"
          :status="row.status"
          :meta="changeFacts(row)"
          clickable
          @open="openDetail(row)"
        >
          <template #actions>
            <AppButton v-if="isStudentRole && canEdit(row)" variant="quiet" size="small" @click="openChange(null, row)">修改</AppButton>
            <AppButton v-if="isTeacherRole && canReviewInternship && row.status === 'wait'" size="small" @click="openReview(row)">审核</AppButton>
            <AppButton variant="quiet" size="small" @click="openDetail(row)">记录</AppButton>
          </template>
        </AppListCard>
      </div>
      <div v-if="!changes.items.length && !loading" class="student-change-empty">暂无资料变更记录</div>
      <div v-if="hasMoreChanges" class="student-change-load-more">
        <AppButton variant="secondary" size="small" :loading="loading" @click="loadChanges(changes.pagination.page + 1, true)">加载更多</AppButton>
      </div>
    </section>

    <AppSheet v-model="formDialog.visible" :title="form.id ? '修改实习资料变更' : '新增实习资料变更'" :close-on-overlay="!saving">
      <div class="student-change-form">
        <label class="app-field">
          <span>实习任务</span>
          <select v-model="form.arrangement_id" :disabled="Boolean(form.id)" @change="selectProfile">
            <option :value="null" disabled>请选择实习任务</option>
            <option v-for="item in taskProfiles" :key="item.arrangement_id" :value="item.arrangement_id">{{ taskLabel(item) }}</option>
          </select>
        </label>
        <label class="app-field">
          <span>变更类型</span>
          <select v-model="form.change_type">
            <option v-for="item in changeTypes" :key="item.value" :value="item.value">{{ item.label }}</option>
          </select>
        </label>
        <label class="app-field">
          <span>期望生效日期</span>
          <input v-model="form.effective_date" type="date">
        </label>
        <template v-if="showCompanyFields">
          <label class="app-field">
            <span>实习单位</span>
            <select v-model="form.after_payload.company_id" @change="normalizeCompanyRelations">
              <option :value="null">请选择实习单位</option>
              <option v-for="item in internship.options.companies || []" :key="item.company_id" :value="item.company_id">{{ item.company_name }}</option>
            </select>
          </label>
          <label class="app-field">
            <span>实习基地</span>
            <select v-model="form.after_payload.base_id">
              <option :value="null">请选择实习基地</option>
              <option v-for="item in availableBases" :key="item.id" :value="item.id">{{ item.name }}</option>
            </select>
          </label>
        </template>
        <label v-if="showMentorField" class="app-field">
          <span>企业导师</span>
          <select v-model="form.after_payload.enterprise_mentor_id">
            <option :value="null">请选择企业导师</option>
            <option v-for="item in availableMentors" :key="item.id" :value="item.id">{{ mentorLabel(item) }}</option>
          </select>
        </label>
        <template v-if="showLocationFields">
          <label class="app-field"><span>实习地点</span><input v-model="form.after_payload.location" maxlength="255" placeholder="请输入实习地点"></label>
          <label class="app-field"><span>实习岗位</span><input v-model="form.after_payload.position" maxlength="180" placeholder="请输入实习岗位"></label>
          <label class="app-field"><span>开始日期</span><input v-model="form.after_payload.start_date" type="date"></label>
          <label class="app-field"><span>结束日期</span><input v-model="form.after_payload.end_date" type="date"></label>
        </template>
        <label class="app-field">
          <span>变更原因</span>
          <textarea v-model="form.reason" rows="5" maxlength="2000" placeholder="请说明需要变更的内容和原因" />
          <small>{{ form.reason.length }} / 2000</small>
        </label>
      </div>
      <template #footer>
        <AppButton variant="secondary" :disabled="saving" @click="formDialog.visible = false">取消</AppButton>
        <AppButton variant="secondary" :loading="saving" @click="saveChange('draft')">保存草稿</AppButton>
        <AppButton :loading="saving" @click="saveChange('wait')">提交审核</AppButton>
      </template>
    </AppSheet>

    <AppSheet v-model="detailDialog.visible" :title="detailDialog.mode === 'profile' ? '当前实习资料' : '资料变更详情'">
      <div class="student-change-detail">
        <section class="student-change-facts">
          <div v-for="item in detailFacts" :key="item.label"><span>{{ item.label }}</span><strong>{{ item.value }}</strong></div>
        </section>
        <template v-if="detailDialog.mode === 'change'">
          <section class="student-change-snapshot">
            <strong>变更前</strong>
            <dl><template v-for="item in snapshotItems(detailDialog.item.before_payload)" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></template></dl>
          </section>
          <section class="student-change-snapshot">
            <strong>变更后</strong>
            <dl><template v-for="item in snapshotItems(detailDialog.item.after_payload)" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></template></dl>
          </section>
          <section v-if="detailDialog.records.length" class="student-change-timeline">
            <strong>流程记录</strong>
            <article v-for="item in detailDialog.records" :key="item.id">
              <i />
              <div>
                <strong>{{ statusText(item.from_status) }} → {{ statusText(item.to_status) }}</strong>
                <p>{{ item.content || item.opinion || '-' }}</p>
                <time>{{ item.created_at || '-' }}</time>
              </div>
            </article>
          </section>
        </template>
      </div>
      <template #footer><AppButton variant="secondary" block @click="detailDialog.visible = false">关闭</AppButton></template>
    </AppSheet>

    <AppSheet v-model="reviewDialog.visible" title="审核实习资料变更" :subtitle="[reviewDialog.item.student_name, reviewDialog.item.arrangement_title].filter(Boolean).join(' / ')" :close-on-overlay="!saving">
      <div class="student-change-review">
        <section class="student-change-review-reason">
          <span>学生变更原因</span>
          <strong>{{ reviewDialog.item.reason || '-' }}</strong>
        </section>
        <AppSegmented v-model="review.status" :items="reviewStatuses" />
        <label class="app-field">
          <span>{{ review.status === 'modify' ? '退回原因' : '审核意见' }}</span>
          <textarea v-model="review.opinion" rows="5" maxlength="500" :placeholder="review.status === 'modify' ? '请填写至少 5 个字的退回原因' : '可填写审核意见'" />
          <small>{{ review.opinion.length }} / 500</small>
        </label>
      </div>
      <template #footer>
        <AppButton variant="secondary" :disabled="saving" @click="reviewDialog.visible = false">取消</AppButton>
        <AppButton :loading="saving" @click="submitReview">提交审核</AppButton>
      </template>
    </AppSheet>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Plus } from '@lucide/vue';
import { showConfirmDialog, showToast } from 'vant';
import MobileFilterSheet from '../../components/MobileFilterSheet.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import AppSegmented from '../../components/ui/AppSegmented.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import {
  fetchInternshipMentors,
  fetchInternshipStudentChangeDetail,
  fetchInternshipStudentChanges,
  fetchInternshipStudentProfiles,
  reviewInternshipStudentChange,
  saveInternshipStudentChange,
} from '../../api/system';
import { useInternshipContext } from './internshipContext';

const {
  canReviewInternship,
  internship,
  isAdminRole,
  isStudentRole,
  isTeacherRole,
} = useInternshipContext();

const activeView = ref(isStudentRole.value ? 'profiles' : 'changes');
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const profiles = reactive(emptyList());
const changes = reactive(emptyList());
const mentors = ref([]);
const filters = reactive(emptyFilters());
const formDialog = reactive({ visible: false });
const detailDialog = reactive({ visible: false, mode: '', item: {}, records: [], reviews: [] });
const reviewDialog = reactive({ visible: false, item: {} });
const form = reactive(emptyForm());
const review = reactive({ status: 'accept', opinion: '' });

const studentTabs = [
  { key: 'profiles', name: '当前资料' },
  { key: 'changes', name: '变更记录' },
];
const changeTypes = [
  { value: 'profile', label: '综合资料' },
  { value: 'company', label: '实习单位' },
  { value: 'mentor', label: '企业导师' },
  { value: 'location', label: '地点或岗位' },
  { value: 'termination', label: '终止实习' },
  { value: 'other', label: '其他变更' },
];
const reviewStatuses = [
  { value: 'accept', label: '通过' },
  { value: 'modify', label: '退回修改' },
];
const statusOptions = ['draft', 'wait', 'accept', 'modify'].map(value => ({ value, label: statusText(value) }));
const showCompanyFields = computed(() => ['profile', 'company', 'other'].includes(form.change_type));
const showMentorField = computed(() => ['profile', 'company', 'mentor', 'other'].includes(form.change_type));
const showLocationFields = computed(() => ['profile', 'location', 'other'].includes(form.change_type));
const availableBases = computed(() => (internship.options.bases || []).filter(item => !form.after_payload.company_id || Number(item.company_id) === Number(form.after_payload.company_id)));
const availableMentors = computed(() => mentors.value.filter(item => !form.after_payload.company_id || Number(item.company_id) === Number(form.after_payload.company_id)));
const hasMoreChanges = computed(() => changes.items.length < Number(changes.pagination.total || 0));
const taskProfiles = computed(() => {
  const items = new Map();
  profiles.items.forEach(item => items.set(Number(item.arrangement_id), item));
  (internship.options.arrangements || []).forEach((item) => {
    const arrangementId = Number(item.id || item.arrangement_id || 0);
    if (arrangementId > 0 && !items.has(arrangementId)) {
      items.set(arrangementId, {
        arrangement_id: arrangementId,
        arrangement_title: item.title || item.name || '',
        course_name: item.course_name || '',
        task_no: item.task_no || '',
      });
    }
  });
  return Array.from(items.values());
});
const selectFilters = computed(() => {
  if (!isAdminRole.value) return [];
  const scopeFilter = selectedCategory.value?.scope_type === 'cohort'
    ? selectFilter('graduation_cohort_id', '毕业届次', internship.options.graduation_cohorts, 'cohort_id', 'cohort_name')
    : selectedCategory.value
      ? selectFilter('grade_id', '年级', internship.options.grades, 'grade_id', 'grade_name')
      : null;
  return [
    selectFilter('category_id', '实习类别', internship.options.internship_categories, 'id', 'name'),
    scopeFilter,
    selectFilter('dep_id', '学院', internship.options.departments, 'dep_id', 'dep_name'),
    selectFilter('profession_id', '专业', filteredProfessions.value, 'profession_id', 'profession_name'),
    selectFilter('arrangement_id', '实习任务', filteredArrangements.value, 'id', 'title'),
  ].filter(Boolean);
});
const selectedCategory = computed(() => (internship.options.internship_categories || []).find(item => Number(item.id) === Number(filters.category_id || 0)) || null);
const filteredProfessions = computed(() => (internship.options.professions || []).filter((item) => {
  const gradeMatches = !filters.grade_id || Number(item.grade_id || 0) === Number(filters.grade_id);
  const departmentMatches = !filters.dep_id || Number(item.dep_id || 0) === Number(filters.dep_id);
  return gradeMatches && departmentMatches;
}));
const filteredArrangements = computed(() => (internship.options.arrangements || []).filter((item) => {
  const categoryMatches = !filters.category_id || Number(item.category_id || 0) === Number(filters.category_id);
  const gradeMatches = !filters.grade_id || Number(item.grade_id || 0) === Number(filters.grade_id);
  const cohortMatches = !filters.graduation_cohort_id || Number(item.graduation_cohort_id || 0) === Number(filters.graduation_cohort_id);
  const departmentMatches = !filters.dep_id || Number(item.dep_id || 0) === Number(filters.dep_id);
  const professionMatches = !filters.profession_id || Number(item.profession_id || 0) === Number(filters.profession_id);
  return categoryMatches && gradeMatches && cohortMatches && departmentMatches && professionMatches;
}));
const detailFacts = computed(() => {
  const row = detailDialog.item || {};
  const items = [
    ['学生', row.student_name], ['学号', row.student_num], ['实习任务', row.arrangement_title],
    ['变更类型', detailDialog.mode === 'change' ? changeTypeText(row.change_type) : '当前资料'],
    ['状态', statusText(row.status)], ['变更原因', row.reason], ['生效时间', row.effective_at],
  ];
  return items.filter(([, value]) => value !== null && value !== undefined && value !== '').map(([label, value]) => ({ label, value }));
});

onMounted(async () => {
  applyDefaults();
  await Promise.all([loadProfiles(1), loadChanges(1), loadMentors()]);
});

watch(() => isStudentRole.value, (student) => {
  activeView.value = student ? 'profiles' : 'changes';
});

watch(() => JSON.stringify([
  internship.options.internship_categories,
  internship.options.grades,
  internship.options.graduation_cohorts,
  internship.options.departments,
  internship.options.professions,
]), () => {
  if (applyDefaults()) loadChanges(1);
});

function emptyList() {
  return { items: [], pagination: { page: 1, page_size: 20, total: 0 } };
}

function emptyFilters() {
  return { category_id: '', graduation_cohort_id: '', grade_id: '', dep_id: '', profession_id: '', arrangement_id: '', status: '', keyword: '' };
}

function emptyForm() {
  return {
    id: null,
    arrangement_id: null,
    change_type: 'profile',
    effective_date: '',
    reason: '',
    after_payload: { company_id: null, base_id: null, enterprise_mentor_id: null, location: '', position: '', start_date: '', end_date: '', status: 'active' },
  };
}

async function loadProfiles(page = 1) {
  if (!isStudentRole.value) return;
  await loadList(profiles, fetchInternshipStudentProfiles, page, false);
}

async function loadChanges(page = 1, append = false) {
  await loadList(changes, fetchInternshipStudentChanges, page, append);
}

async function loadList(target, fetcher, page, append) {
  loading.value = true;
  message.value = '';
  try {
    const data = await fetcher(queryParams(page));
    target.items = append ? [...target.items, ...(data.items || [])] : (data.items || []);
    target.pagination = { ...target.pagination, ...(data.pagination || {}) };
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

async function loadMentors() {
  if (!isStudentRole.value) return;
  try {
    const data = await fetchInternshipMentors({ page: 1, page_size: 100 });
    mentors.value = data.items || [];
  } catch {
    mentors.value = [];
  }
}

function queryParams(page) {
  const params = { page, page_size: 20 };
  if (!isStudentRole.value) {
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) params[key] = value;
    });
  }
  return params;
}

function switchView(view) {
  activeView.value = view;
  if (view === 'profiles') loadProfiles(1);
  else loadChanges(1);
}

function updateFilter({ key, value }) {
  filters[key] = value ?? '';
  if (key === 'category_id') normalizeCategoryFilters();
  if (key === 'dep_id' && filters.profession_id && !filteredProfessions.value.some(item => Number(item.profession_id) === Number(filters.profession_id))) {
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
  loadChanges(1);
}

function applyDefaults() {
  if (!isAdminRole.value) return false;
  const previous = JSON.stringify(filters);
  if (!filters.category_id && internship.options.internship_categories?.length) {
    filters.category_id = defaultCategoryId();
  }
  const currentGrade = (internship.options.grades || []).find(item => String(item.is_current) === 'true' || Number(item.is_current) === 1);
  const currentCohort = (internship.options.graduation_cohorts || []).find(item => String(item.is_current) === 'true' || Number(item.is_current) === 1);
  if (selectedCategory.value?.scope_type === 'cohort') {
    filters.grade_id = '';
    filters.graduation_cohort_id ||= currentCohort?.cohort_id || '';
  } else if (selectedCategory.value) {
    filters.graduation_cohort_id = '';
    filters.grade_id ||= currentGrade?.grade_id || '';
  } else {
    filters.grade_id = '';
    filters.graduation_cohort_id = '';
  }
  if ((internship.options.departments || []).length === 1) filters.dep_id ||= internship.options.departments[0].dep_id;
  if (filteredProfessions.value.length === 1) filters.profession_id ||= filteredProfessions.value[0].profession_id;
  return previous !== JSON.stringify(filters);
}

function defaultCategoryId() {
  const category = (internship.options.internship_categories || []).find(item => item.scope_type === 'cohort' && internship.options.graduation_cohorts?.length)
    || (internship.options.internship_categories || []).find(item => item.scope_type === 'grade' && internship.options.grades?.length)
    || internship.options.internship_categories?.[0];
  return category?.id || '';
}

function normalizeCategoryFilters() {
  if (selectedCategory.value?.scope_type === 'cohort') {
    filters.grade_id = '';
    filters.graduation_cohort_id ||= currentCohortId();
  } else if (selectedCategory.value) {
    filters.graduation_cohort_id = '';
    filters.grade_id ||= currentGradeId();
  } else {
    filters.grade_id = '';
    filters.graduation_cohort_id = '';
  }
}

function currentGradeId() {
  const current = (internship.options.grades || []).find(item => String(item.is_current) === 'true' || Number(item.is_current) === 1);
  return current?.grade_id || internship.options.grades?.[0]?.grade_id || '';
}

function currentCohortId() {
  const current = (internship.options.graduation_cohorts || []).find(item => String(item.is_current) === 'true' || Number(item.is_current) === 1);
  return current?.cohort_id || internship.options.graduation_cohorts?.[0]?.cohort_id || '';
}

function openProfile(profile) {
  Object.assign(detailDialog, { visible: true, mode: 'profile', item: profile, records: [], reviews: [] });
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
  formDialog.visible = true;
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
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchInternshipStudentChangeDetail({ id: row.id });
    Object.assign(detailDialog, { visible: true, mode: 'change', item: data.item || row, records: data.records || [], reviews: data.reviews || [] });
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

function openReview(row) {
  Object.assign(review, { status: 'accept', opinion: '' });
  Object.assign(reviewDialog, { visible: true, item: row });
}

async function saveChange(status) {
  if (saving.value) return;
  if (!form.arrangement_id) return showToast('请选择实习任务');
  if (status === 'wait' && !form.reason.trim()) return showToast('请填写变更原因');
  if (status === 'wait') {
    try {
      await showConfirmDialog({ title: '提交资料变更', message: '提交后将发送给校内指导教师审核，确认继续？', confirmButtonText: '提交审核', cancelButtonText: '取消' });
    } catch {
      return;
    }
  }
  saving.value = true;
  try {
    await saveInternshipStudentChange({
      id: form.id || undefined,
      arrangement_id: Number(form.arrangement_id),
      change_type: form.change_type,
      effective_date: form.effective_date || undefined,
      reason: form.reason,
      after_payload: submissionPayload(),
      status,
    });
    formDialog.visible = false;
    activeView.value = 'changes';
    showToast(status === 'wait' ? '变更申请已提交' : '变更草稿已保存');
    await Promise.all([loadProfiles(1), loadChanges(1)]);
  } catch (error) {
    message.value = error.message;
    showToast(error.message);
  } finally {
    saving.value = false;
  }
}

async function submitReview() {
  if (saving.value) return;
  if (review.status === 'modify' && review.opinion.trim().length < 5) return showToast('退回原因至少填写 5 个字');
  try {
    await showConfirmDialog({
      title: '确认审核结果',
      message: `确认${review.status === 'accept' ? '通过' : '退回'}该资料变更申请？`,
      confirmButtonText: '确认提交',
      cancelButtonText: '取消',
    });
  } catch {
    return;
  }
  saving.value = true;
  try {
    await reviewInternshipStudentChange({ id: reviewDialog.item.id, status: review.status, opinion: review.opinion });
    reviewDialog.visible = false;
    showToast(review.status === 'accept' ? '变更申请已通过' : '变更申请已退回');
    await loadChanges(1);
  } catch (error) {
    message.value = error.message;
    showToast(error.message);
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

function canEdit(row) {
  return ['draft', 'modify'].includes(row.status || '');
}

function profileFacts(row) {
  return [
    `实习地点：${row.location || '-'}`,
    `实习岗位：${row.position || '-'}`,
    `实习时间：${dateRange(row.start_date, row.end_date)}`,
    `企业导师：${row.enterprise_mentor_name || '-'}`,
  ];
}

function changeFacts(row) {
  return [
    `实习任务：${row.arrangement_title || '-'}`,
    `变更原因：${row.reason || '-'}`,
    `提交时间：${row.submitted_at || row.updated_at || '-'}`,
  ];
}

function snapshotItems(payload = {}) {
  const labels = {
    company_id: '实习单位', base_id: '实习基地', enterprise_mentor_id: '企业导师', location: '实习地点', position: '实习岗位',
    start_date: '开始日期', end_date: '结束日期', status: '资料状态', terminated_at: '终止日期', termination_reason: '终止原因',
  };
  return Object.entries(labels).map(([key, label]) => ({ label, value: snapshotValue(key, payload?.[key]) }));
}

function snapshotValue(key, value) {
  if (key === 'company_id') return (internship.options.companies || []).find(item => Number(item.company_id) === Number(value))?.company_name || value || '-';
  if (key === 'base_id') return (internship.options.bases || []).find(item => Number(item.id) === Number(value))?.name || value || '-';
  if (key === 'enterprise_mentor_id') return mentors.value.find(item => Number(item.id) === Number(value))?.name || value || '-';
  if (key === 'status') return statusText(value);
  return value || '-';
}

function selectFilter(key, label, items = [], valueKey, labelKey) {
  return { key, label, placeholder: `请选择${label}`, options: (items || []).map(item => ({ value: item[valueKey], label: item[labelKey] || item[valueKey] })) };
}

function taskLabel(item) {
  return [item.arrangement_title, item.course_name].filter(Boolean).join(' / ') || `任务 ${item.arrangement_id}`;
}

function mentorLabel(item) {
  return [item.name, item.company_name, item.phone].filter(Boolean).join(' / ') || `导师 ${item.id}`;
}

function changeTypeText(value) {
  return changeTypes.find(item => item.value === value)?.label || value || '-';
}

function statusText(value) {
  return ({ draft: '草稿', wait: '待审核', accept: '已通过', modify: '需修改', active: '有效', terminated: '已终止', pending: '待完善' }[value] || value || '-');
}

function dateRange(start, end) {
  return start || end ? `${start || '-'} 至 ${end || '-'}` : '-';
}
</script>

<style scoped>
.student-change-page { display: grid; gap: 12px; }

.student-change-message {
  margin: 0;
  border: 1px solid color-mix(in srgb, var(--app-warning) 24%, var(--app-line));
  border-radius: var(--app-radius);
  padding: 10px 12px;
  color: var(--app-warning);
  background: var(--app-warning-soft);
  font-size: 13px;
}

.student-change-section {
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  overflow: hidden;
  background: var(--app-surface);
}

.student-change-section-head {
  min-height: 58px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 12px;
  border-bottom: 1px solid var(--app-line);
  background: var(--app-surface-muted);
}

.student-change-section-head > div { min-width: 0; display: grid; gap: 2px; }
.student-change-section-head strong { font-size: 15px; }
.student-change-section-head span { color: var(--app-text-secondary); font-size: 12px; }
.student-change-list { display: grid; gap: 10px; padding: 10px; }
.student-change-empty { padding: 34px 12px; color: var(--app-text-secondary); text-align: center; }
.student-change-load-more { display: flex; justify-content: center; padding: 0 10px 12px; }
.student-change-form,
.student-change-detail,
.student-change-review { display: grid; gap: 14px; padding: 14px 16px; }
.student-change-form .app-field small,
.student-change-review .app-field small { justify-self: end; color: var(--app-text-tertiary); }

.student-change-facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  overflow: hidden;
  background: var(--app-line);
}

.student-change-facts > div { min-width: 0; display: grid; gap: 4px; padding: 10px; background: var(--app-surface); }
.student-change-facts span,
.student-change-snapshot dt,
.student-change-review-reason span { color: var(--app-text-secondary); font-size: 12px; }
.student-change-facts strong { overflow-wrap: anywhere; font-size: 13px; }

.student-change-snapshot,
.student-change-timeline,
.student-change-review-reason {
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  padding: 12px;
  background: var(--app-surface-muted);
}

.student-change-snapshot > strong,
.student-change-timeline > strong { display: block; margin-bottom: 10px; font-size: 14px; }
.student-change-snapshot dl { display: grid; grid-template-columns: minmax(80px, auto) 1fr; gap: 7px 12px; margin: 0; }
.student-change-snapshot dt,
.student-change-snapshot dd { margin: 0; overflow-wrap: anywhere; }
.student-change-snapshot dd { font-size: 13px; }

.student-change-timeline article { position: relative; display: grid; grid-template-columns: 14px minmax(0, 1fr); gap: 8px; padding-bottom: 16px; }
.student-change-timeline article:last-child { padding-bottom: 0; }
.student-change-timeline article i { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; background: var(--app-primary); }
.student-change-timeline article:not(:last-child)::before { position: absolute; top: 13px; bottom: 0; left: 3px; width: 1px; content: ''; background: var(--app-line-strong); }
.student-change-timeline article div { display: grid; gap: 4px; }
.student-change-timeline article strong { font-size: 13px; }
.student-change-timeline article p { margin: 0; color: var(--app-text-secondary); font-size: 13px; line-height: 1.5; }
.student-change-timeline article time { color: var(--app-text-tertiary); font-size: 11px; }

.student-change-review-reason { display: grid; gap: 6px; }
.student-change-review-reason strong { font-size: 13px; line-height: 1.55; }

@media (max-width: 380px) {
  .student-change-facts { grid-template-columns: 1fr; }
}
</style>
