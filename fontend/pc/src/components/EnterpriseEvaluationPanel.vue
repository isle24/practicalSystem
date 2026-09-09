<template>
  <section class="enterprise-evaluation-panel">
    <header class="enterprise-evaluation-head">
      <div>
        <strong>毕业实习企业评价</strong>
        <span>企业导师通过短信验证后独立提交，评价结果自动进入毕业实习鉴定。</span>
      </div>
      <div class="enterprise-evaluation-rule-summary">
        <span>当前总分</span>
        <strong>{{ rule.total_score || 0 }} 分</strong>
        <el-button v-if="canConfigure" :icon="Settings" @click="openRuleDialog">评价规则</el-button>
      </div>
    </header>

    <el-alert v-if="message" :title="message" type="warning" :closable="false" show-icon />

    <DataListPanel
      :columns="columns"
      :filters="listFilters"
      :filter-values="filters"
      :loading="loading"
      :pagination="list.pagination"
      :rows="list.items"
      storage-key="internship-enterprise-evaluations"
      @filter-change="updateFilter"
      @page-change="load"
      @reset="resetFilters"
      @search="load(1)"
    >
      <template #actions="{ row }">
        <el-button v-if="row.evaluation_status === 'submitted'" size="small" type="primary" plain @click="openDetail(row)">查看评价</el-button>
        <el-button
          v-if="canManage && row.enterprise_mentor_id && row.evaluation_status !== 'submitted'"
          size="small"
          type="primary"
          :loading="invitingId === row.pair_id"
          @click="createInvitation(row)"
        >
          生成邀请
        </el-button>
        <span v-if="!row.enterprise_mentor_id" class="enterprise-missing-mentor">未绑定企业导师</span>
      </template>
    </DataListPanel>

    <OperationDialog :visible="detail.visible" title="企业评价详情" @close="detail.visible = false">
      <div class="enterprise-evaluation-detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="学生">{{ detail.item.student_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="学号">{{ detail.item.student_num || '-' }}</el-descriptions-item>
          <el-descriptions-item label="实习任务">{{ detail.item.arrangement_title || '-' }}</el-descriptions-item>
          <el-descriptions-item label="企业导师">{{ detail.item.enterprise_mentor_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="企业评分">{{ detail.item.total_score ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="提交时间">{{ detail.item.submitted_at || '-' }}</el-descriptions-item>
          <el-descriptions-item label="企业评语" :span="2">{{ detail.item.comment || '-' }}</el-descriptions-item>
        </el-descriptions>
        <section v-if="detailCriteria.length" class="enterprise-evaluation-criteria">
          <strong>分项评分</strong>
          <div v-for="item in detailCriteria" :key="item.code">
            <span>{{ item.name || item.code }}</span>
            <strong>{{ item.score ?? '-' }} / {{ item.max_score ?? '-' }} 分</strong>
          </div>
        </section>
      </div>
      <template #footer><el-button @click="detail.visible = false">关闭</el-button></template>
    </OperationDialog>

    <OperationDialog :visible="invitation.visible" title="企业评价邀请" @close="closeInvitation">
      <div class="enterprise-invitation-result">
        <el-alert title="邀请地址在有效期内可重复使用，企业导师仍需验证绑定手机号。" type="info" :closable="false" show-icon />
        <dl>
          <dt>企业导师</dt><dd>{{ invitation.data.mentor_name || '-' }}</dd>
          <dt>实习任务</dt><dd>{{ invitation.data.arrangement_title || '-' }}</dd>
          <dt>绑定手机</dt><dd>{{ invitation.data.mobile_masked || '-' }}</dd>
          <dt>有效期至</dt><dd>{{ invitation.data.expires_at || '-' }}</dd>
        </dl>
        <label>
          <span>评价地址</span>
          <el-input :model-value="invitation.data.evaluation_url" readonly />
        </label>
      </div>
      <template #footer>
        <el-button @click="closeInvitation">关闭</el-button>
        <el-button type="primary" :icon="Copy" @click="copyInvitation">复制地址</el-button>
      </template>
    </OperationDialog>

    <OperationDialog :visible="ruleDialog.visible" title="毕业实习企业评价规则" :busy="savingRule" dialog-class="enterprise-rule-dialog" @close="closeRuleDialog">
      <div class="enterprise-rule-form">
        <el-alert title="企业评价固定为 30 分，各项最高分之和必须等于 30 分；历史评价不受规则修改影响。" type="info" :closable="false" show-icon />
        <div class="enterprise-rule-items">
          <div v-for="(item, index) in ruleForm.items" :key="`${item.code}-${index}`" class="enterprise-rule-item">
            <el-input v-model="item.name" maxlength="80" placeholder="请输入评价项名称" />
            <el-input-number v-model="item.max_score" :min="1" :max="100" :precision="1" controls-position="right" />
            <el-button text type="danger" :icon="Trash2" :disabled="ruleForm.items.length <= 1" @click="removeRuleItem(index)" />
          </div>
        </div>
        <div class="enterprise-rule-actions">
          <el-button :icon="Plus" @click="addRuleItem">新增评价项</el-button>
          <span>合计</span>
          <strong>{{ ruleTotal }} 分</strong>
        </div>
      </div>
      <template #footer>
        <el-button @click="closeRuleDialog">取消</el-button>
        <el-button type="primary" :loading="savingRule" :disabled="savingRule" @click="saveRule">保存规则</el-button>
      </template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Copy, Plus, Settings, Trash2 } from '@lucide/vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  createEnterpriseEvaluationInvitation,
  fetchEnterpriseEvaluationProgress,
  fetchEnterpriseEvaluationRule,
  saveEnterpriseEvaluationRule,
} from '../api/system';
import DataListPanel from './DataListPanel.vue';
import OperationDialog from './OperationDialog.vue';

const props = defineProps({
  options: { type: Object, default: () => ({}) },
  defaultFilters: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
  canConfigure: { type: Boolean, default: false },
});

const loading = ref(false);
const savingRule = ref(false);
const invitingId = ref(0);
const message = ref('');
const list = reactive(emptyList());
const filters = reactive(emptyFilters());
const rule = reactive({ practice_type: 'graduation', total_score: 30, items: [] });
const ruleForm = reactive({ items: [] });
const detail = reactive({ visible: false, item: {} });
const invitation = reactive({ visible: false, data: {} });
const ruleDialog = reactive({ visible: false });

const ruleTotal = computed(() => ruleForm.items.reduce((total, item) => total + Number(item.max_score || 0), 0));
const detailCriteria = computed(() => Array.isArray(detail.item.criteria_json) ? detail.item.criteria_json : []);
const listFilters = computed(() => [
  selectFilter('graduation_cohort_id', '毕业届次', props.options.graduation_cohorts, 'cohort_id', 'cohort_name'),
  selectFilter('dep_id', '学院', props.options.departments, 'dep_id', 'dep_name'),
  selectFilter('profession_id', '专业', filteredProfessions.value, 'profession_id', 'profession_name'),
  selectFilter('arrangement_id', '实习任务', graduationArrangements.value, 'id', 'title'),
  { key: 'evaluation_status', label: '评价状态', type: 'select', options: [{ value: 'pending', label: '待评价' }, { value: 'submitted', label: '已评价' }] },
  { key: 'keyword', label: '关键词', placeholder: '学生、学号、任务或课程' },
]);
const filteredProfessions = computed(() => (props.options.professions || []).filter(item => !filters.dep_id || Number(item.dep_id) === Number(filters.dep_id)));
const graduationArrangements = computed(() => (props.options.arrangements || []).filter(item => (
  item.type === 'graduation' || item.category_code === 'graduation' || item.scope_type === 'cohort'
)));
const columns = [
  { prop: 'student_name', label: '学生', width: 110 },
  { prop: 'student_num', label: '学号', width: 130 },
  { prop: 'cohort_name', label: '毕业届次', width: 110 },
  { prop: 'dep_name', label: '学院', minWidth: 130 },
  { prop: 'profession_name', label: '专业', minWidth: 130 },
  { prop: 'arrangement_title', label: '实习任务', minWidth: 190 },
  { prop: 'enterprise_mentor_name', label: '企业导师', width: 120 },
  { key: 'evaluation_status', label: '评价状态', width: 100, tag: true, tagType: row => row.evaluation_status === 'submitted' ? 'success' : 'warning', formatter: row => row.evaluation_status === 'submitted' ? '已评价' : '待评价' },
  { prop: 'total_score', label: '企业评分', width: 100 },
  { prop: 'submitted_at', label: '提交时间', width: 168 },
];

onMounted(async () => {
  applyDefaults();
  await Promise.all([load(1), loadRule()]);
});

watch(() => JSON.stringify(props.defaultFilters || {}), () => {
  if (applyDefaults()) load(1);
});

async function load(page = 1) {
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchEnterpriseEvaluationProgress(queryParams(page));
    Object.assign(list, emptyList(), data || {});
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

async function loadRule() {
  try {
    const data = await fetchEnterpriseEvaluationRule({ practice_type: 'graduation' });
    Object.assign(rule, data || {});
  } catch (error) {
    message.value = error.message;
  }
}

function queryParams(page) {
  const params = { page, page_size: 20 };
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) params[key] = value;
  });
  return params;
}

function updateFilter({ key, value }) {
  filters[key] = value ?? '';
  if (key === 'dep_id' && filters.profession_id && !filteredProfessions.value.some(item => Number(item.profession_id) === Number(filters.profession_id))) {
    filters.profession_id = '';
  }
}

function resetFilters() {
  Object.assign(filters, emptyFilters());
  applyDefaults();
  load(1);
}

function applyDefaults() {
  let changed = false;
  const values = {
    graduation_cohort_id: props.defaultFilters.graduation_cohort_id || currentCohortId(),
    dep_id: props.defaultFilters.dep_id,
    profession_id: props.defaultFilters.profession_id,
  };
  Object.entries(values).forEach(([key, value]) => {
    if ((filters[key] === '' || filters[key] === null) && value !== '' && value !== null && value !== undefined) {
      filters[key] = value;
      changed = true;
    }
  });
  return changed;
}

function currentCohortId() {
  const current = (props.options.graduation_cohorts || []).find(item => ['true', '1'].includes(String(item.is_current)));
  return current?.cohort_id || props.options.graduation_cohorts?.[0]?.cohort_id || '';
}

async function createInvitation(row) {
  if (invitingId.value) return;
  try {
    await ElMessageBox.confirm(`确认生成“${row.enterprise_mentor_name || '企业导师'}”的评价邀请？`, '生成邀请', { type: 'warning' });
  } catch {
    return;
  }
  invitingId.value = row.pair_id;
  message.value = '';
  try {
    const data = await createEnterpriseEvaluationInvitation({ arrangement_id: row.arrangement_id, enterprise_mentor_id: row.enterprise_mentor_id });
    invitation.data = data || {};
    invitation.visible = true;
    await copyText(data.evaluation_url, false);
    ElMessage.success('邀请已生成并复制');
  } catch (error) {
    message.value = error.message;
  } finally {
    invitingId.value = 0;
  }
}

function openDetail(row) {
  detail.item = row;
  detail.visible = true;
}

function closeInvitation() {
  invitation.visible = false;
  invitation.data = {};
}

async function copyInvitation() {
  if (await copyText(invitation.data.evaluation_url, true)) ElMessage.success('评价地址已复制');
}

async function copyText(value, reportError) {
  try {
    await navigator.clipboard.writeText(String(value || ''));
    return true;
  } catch {
    if (reportError) ElMessage.warning('浏览器未允许自动复制，请手动复制地址');
    return false;
  }
}

function openRuleDialog() {
  ruleForm.items = (rule.items || []).map((item, index) => ({
    code: item.code || `item_${index + 1}`,
    name: item.name || '',
    max_score: Number(item.max_score || 0),
  }));
  if (!ruleForm.items.length) addRuleItem();
  ruleDialog.visible = true;
}

function closeRuleDialog() {
  if (!savingRule.value) ruleDialog.visible = false;
}

function addRuleItem() {
  ruleForm.items.push({ code: `item_${Date.now()}_${ruleForm.items.length + 1}`, name: '', max_score: 10 });
}

function removeRuleItem(index) {
  if (ruleForm.items.length > 1) ruleForm.items.splice(index, 1);
}

async function saveRule() {
  if (savingRule.value) return;
  if (ruleForm.items.some(item => !String(item.name || '').trim() || Number(item.max_score || 0) <= 0)) return ElMessage.warning('请完整填写评价项名称和分值');
  savingRule.value = true;
  message.value = '';
  try {
    const data = await saveEnterpriseEvaluationRule({ practice_type: 'graduation', criteria: ruleForm.items, total_score: ruleTotal.value });
    Object.assign(rule, data.rule || {});
    ruleDialog.visible = false;
    ElMessage.success('企业评价规则已保存');
  } catch (error) {
    message.value = error.message;
  } finally {
    savingRule.value = false;
  }
}

function emptyFilters() {
  return { graduation_cohort_id: '', dep_id: '', profession_id: '', arrangement_id: '', evaluation_status: '', keyword: '' };
}

function emptyList() {
  return { items: [], pagination: { page: 1, page_size: 20, total: 0 } };
}

function selectFilter(key, label, items = [], valueKey, labelKey) {
  return { key, label, type: 'select', options: (items || []).map(item => ({ value: item[valueKey], label: item[labelKey] || item[valueKey] })) };
}
</script>

<style scoped>
.enterprise-evaluation-panel {
  min-height: 0;
  height: 100%;
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr);
  gap: 10px;
}

.enterprise-evaluation-head {
  min-height: 58px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--line);
}

.enterprise-evaluation-head > div:first-child {
  min-width: 0;
  display: grid;
  gap: 4px;
}

.enterprise-evaluation-head > div:first-child strong {
  font-size: 16px;
}

.enterprise-evaluation-head > div:first-child span,
.enterprise-evaluation-rule-summary span,
.enterprise-missing-mentor {
  color: var(--muted);
  font-size: 12px;
}

.enterprise-evaluation-rule-summary {
  display: flex;
  align-items: center;
  gap: 9px;
  white-space: nowrap;
}

.enterprise-evaluation-detail,
.enterprise-invitation-result,
.enterprise-rule-form {
  min-height: 0;
  overflow: auto;
  padding: 16px 18px;
}

.enterprise-evaluation-detail {
  display: grid;
  align-content: start;
  gap: 16px;
}

.enterprise-evaluation-criteria {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.enterprise-evaluation-criteria > strong {
  grid-column: 1 / -1;
  font-size: 14px;
}

.enterprise-evaluation-criteria > div {
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 8px 10px;
}

.enterprise-evaluation-criteria span {
  color: var(--muted);
  font-size: 13px;
}

.enterprise-invitation-result {
  display: grid;
  gap: 16px;
}

.enterprise-invitation-result dl {
  display: grid;
  grid-template-columns: 90px minmax(0, 1fr);
  gap: 10px 14px;
  margin: 0;
}

.enterprise-invitation-result dt,
.enterprise-invitation-result label > span {
  color: var(--muted);
  font-size: 13px;
}

.enterprise-invitation-result dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.enterprise-invitation-result label {
  display: grid;
  gap: 7px;
}

.enterprise-rule-form {
  display: grid;
  align-content: start;
  gap: 14px;
}

.enterprise-rule-items {
  display: grid;
  gap: 8px;
}

.enterprise-rule-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 160px 38px;
  align-items: center;
  gap: 8px;
}

.enterprise-rule-item :deep(.el-input-number) {
  width: 100%;
}

.enterprise-rule-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-top: 4px;
}

.enterprise-rule-actions span {
  margin-left: auto;
  color: var(--muted);
  font-size: 13px;
}
</style>
