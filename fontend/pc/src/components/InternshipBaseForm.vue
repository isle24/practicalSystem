<template>
  <section class="internship-dialog-component base-form-component">
    <TextTabs v-model="activeTab" :items="tabs" class="base-detail-tabs" />

    <div class="internship-detail-scroll base-tab-body">
      <template v-if="activeTab === 'profile'">
        <div class="internship-section-head">
          <div>
            <strong>{{ readonly ? (local.name || '未命名基地') : '基地类型' }}</strong>
            <small>{{ local.base_type === 'temporary' ? '临时基地仅维护必要联系和服务范围' : '长期基地维护完整申报资料' }}</small>
          </div>
          <el-segmented v-if="!readonly" v-model="local.base_type" :options="baseTypeOptions" />
          <el-tag v-else :type="local.base_type === 'temporary' ? 'warning' : 'success'">
            {{ local.base_type === 'temporary' ? '临时基地' : '长期基地' }}
          </el-tag>
        </div>

        <template v-if="readonly">
          <div class="detail-summary-grid base-profile-summary">
            <article><span>基地名称</span><strong>{{ valueText(local.name) }}</strong></article>
            <article v-if="local.base_type === 'long_term'"><span>基地编号</span><strong>{{ valueText(local.code) }}</strong></article>
            <article><span>所属学院</span><strong>{{ departmentText }}</strong></article>
            <article><span>服务专业</span><strong :title="professionText">{{ professionText }}</strong></article>
            <article><span>基地负责人</span><strong>{{ valueText(local.manager.name) }}</strong></article>
            <article><span>负责人电话</span><strong>{{ valueText(local.manager.phone) }}</strong></article>
            <template v-if="local.base_type === 'long_term'">
              <article><span>合作单位</span><strong>{{ companyText }}</strong></article>
              <article><span>基地类别</span><strong>{{ valueText(local.category) }}</strong></article>
              <article><span>基地面积</span><strong>{{ valueText(local.area, ' 平方米') }}</strong></article>
              <article><span>可接收人数</span><strong>{{ valueText(local.capacity, ' 人') }}</strong></article>
              <article><span>年均接收人数</span><strong>{{ valueText(local.annual_student_count, ' 人') }}</strong></article>
              <article><span>当前接收人数</span><strong>{{ valueText(local.current_student_count, ' 人') }}</strong></article>
              <article><span>负责人性别</span><strong>{{ valueText(local.manager.gender) }}</strong></article>
              <article><span>负责人出生日期</span><strong>{{ valueText(local.manager.birth_date) }}</strong></article>
              <article><span>负责人职务/职称</span><strong>{{ valueText(local.manager.title) }}</strong></article>
              <article><span>负责人学历</span><strong>{{ valueText(local.manager.education) }}</strong></article>
            </template>
          </div>
          <section class="internship-edit-section">
            <header><div><strong>服务范围</strong><small>基地位置、服务课程和负责人职责</small></div></header>
            <div class="base-long-text-list">
              <article><span>基地所在位置</span><p>{{ valueText(local.address) }}</p></article>
              <article><span>服务课程</span><p>{{ valueText(local.service_courses) }}</p></article>
              <article v-if="local.base_type === 'long_term'"><span>负责人职责</span><p>{{ valueText(local.manager.duties) }}</p></article>
            </div>
          </section>
        </template>

        <div v-else class="operation-form base-main-form">
          <label>
            <span>基地名称{{ local.base_type === 'temporary' ? '（可选）' : '' }}</span>
            <input v-model="local.name" :placeholder="local.base_type === 'temporary' ? '留空后按位置和负责人自动生成' : ''">
          </label>
          <label v-if="local.base_type === 'long_term'"><span>基地编号</span><input v-model="local.code"></label>
          <label>
            <span>所属学院</span>
            <el-select v-model="local.dep_id" clearable filterable>
              <el-option v-for="item in options.departments || []" :key="item.dep_id" :label="item.dep_name" :value="item.dep_id" />
            </el-select>
          </label>
          <label>
            <span>服务专业</span>
            <el-select v-model="local.profession_ids" multiple collapse-tags collapse-tags-tooltip filterable>
              <el-option v-for="item in professionOptions" :key="item.profession_id" :label="item.profession_name" :value="item.profession_id" />
            </el-select>
          </label>
          <label class="span-2"><span>基地所在位置</span><input v-model="local.address"></label>
          <label class="span-2"><span>服务课程</span><textarea v-model="local.service_courses" rows="3" /></label>
          <label><span>基地负责人</span><input v-model="local.manager.name"></label>
          <label><span>负责人电话</span><input v-model="local.manager.phone"></label>
          <template v-if="local.base_type === 'long_term'">
            <label>
              <span>合作单位</span>
              <el-select v-model="local.company_id" clearable filterable>
                <el-option v-for="item in options.companies || []" :key="item.company_id" :label="item.company_name" :value="item.company_id" />
              </el-select>
            </label>
            <label><span>基地类别</span><input v-model="local.category"></label>
            <label><span>基地面积（平方米）</span><input v-model="local.area" type="number" min="0"></label>
            <label><span>可接收人数</span><input v-model="local.capacity" type="number" min="0"></label>
            <label><span>年均接收人数</span><input v-model="local.annual_student_count" type="number" min="0"></label>
            <label><span>当前接收人数</span><input v-model="local.current_student_count" type="number" min="0"></label>
            <label>
              <span>负责人性别</span>
              <el-select v-model="local.manager.gender" clearable>
                <el-option label="男" value="男" />
                <el-option label="女" value="女" />
              </el-select>
            </label>
            <label><span>负责人出生日期</span><input v-model="local.manager.birth_date" type="date"></label>
            <label><span>负责人职务/职称</span><input v-model="local.manager.title"></label>
            <label><span>负责人学历</span><input v-model="local.manager.education"></label>
            <label class="span-2"><span>负责人职责</span><textarea v-model="local.manager.duties" rows="3" /></label>
          </template>
        </div>
      </template>

      <template v-else-if="activeTab === 'people'">
        <BasePersonRows
          v-model="local.teachers"
          title="校内指导教师"
          :readonly="readonly"
          directory
          :can-sync="canSyncTeachers"
        />
        <BasePersonRows v-model="local.mentors" title="企业指导教师" :readonly="readonly" />
      </template>

      <template v-else-if="activeTab === 'cooperation'">
        <section class="internship-edit-section">
          <header>
            <div><strong>既有实习基地</strong><small>维护已有合作基础和合作内容</small></div>
            <el-button v-if="!readonly" :icon="Plus" size="small" @click="addExistingSite">新增</el-button>
          </header>
          <el-table v-if="readonly && local.existing_sites.length" :data="local.existing_sites" stripe size="small">
            <el-table-column prop="site_name" label="基地名称" min-width="200" />
            <el-table-column prop="cooperation" label="合作内容" min-width="320" show-overflow-tooltip />
          </el-table>
          <div v-else-if="local.existing_sites.length" class="dynamic-row-list">
            <div v-for="(item, index) in local.existing_sites" :key="`site-${index}`" class="dynamic-form-row two-columns">
              <label><span>基地名称</span><input v-model="item.site_name"></label>
              <label><span>合作内容</span><input v-model="item.cooperation"></label>
              <el-button text type="danger" :icon="Trash2" @click="local.existing_sites.splice(index, 1)">删除</el-button>
            </div>
          </div>
          <el-empty v-else description="暂无既有基地" :image-size="52" />
        </section>

        <section class="internship-edit-section">
          <header><div><strong>合作单位概况</strong><small>用于长期基地申报书</small></div></header>
          <template v-if="readonly">
            <div class="detail-summary-grid">
              <article><span>单位名称</span><strong>{{ valueText(local.company_profile.company_name) }}</strong></article>
              <article><span>注册资本</span><strong>{{ valueText(local.company_profile.registered_capital) }}</strong></article>
              <article><span>员工人数</span><strong>{{ valueText(local.company_profile.employee_count, ' 人') }}</strong></article>
              <article><span>年均接收实习人数</span><strong>{{ valueText(local.company_profile.annual_intern_count, ' 人') }}</strong></article>
              <article><span>高级职称人数</span><strong>{{ valueText(local.company_profile.senior_title_count, ' 人') }}</strong></article>
            </div>
            <div class="base-long-text-list"><article><span>主营业务</span><p>{{ valueText(local.company_profile.main_business) }}</p></article></div>
          </template>
          <div v-else class="operation-form base-main-form compact-form">
            <label><span>单位名称</span><input v-model="local.company_profile.company_name"></label>
            <label><span>注册资本</span><input v-model="local.company_profile.registered_capital"></label>
            <label><span>员工人数</span><input v-model="local.company_profile.employee_count" type="number" min="0"></label>
            <label><span>年均接收实习人数</span><input v-model="local.company_profile.annual_intern_count" type="number" min="0"></label>
            <label><span>高级职称人数</span><input v-model="local.company_profile.senior_title_count" type="number" min="0"></label>
            <label class="span-2"><span>主营业务</span><textarea v-model="local.company_profile.main_business" rows="3" /></label>
          </div>
        </section>
      </template>

      <template v-else-if="activeTab === 'declarations'">
        <div v-if="local.declarations.length" class="base-declaration-list">
          <section v-for="declaration in local.declarations" :key="declaration.id" class="internship-edit-section base-declaration-item">
            <header>
              <div>
                <strong>{{ declaration.declaration_year }} 年度申报</strong>
                <small>{{ valueText(declaration.base_category) }} · {{ valueText(declaration.base_level) }}</small>
              </div>
              <el-tag :type="declaration.project_status === '是' ? 'success' : 'info'">
                {{ declaration.project_status === '是' ? '已立项' : valueText(declaration.project_status) }}
              </el-tag>
            </header>
            <div class="detail-summary-grid base-declaration-summary">
              <article><span>立项经费</span><strong>{{ moneyValueText(declaration.approved_amount) }}</strong></article>
              <article><span>服务专业数</span><strong>{{ valueText(declaration.service_profession_count, ' 个') }}</strong></article>
              <article><span>指导教师总数</span><strong>{{ valueText(declaration.teacher_count, ' 人') }}</strong></article>
              <article><span>校外教师数</span><strong>{{ valueText(declaration.external_teacher_count, ' 人') }}</strong></article>
              <article><span>预计接纳人次</span><strong>{{ valueText(declaration.expected_student_visits, ' 人次') }}</strong></article>
              <article><span>预计接纳生天数</span><strong>{{ valueText(declaration.expected_student_days, ' 生天') }}</strong></article>
              <article><span>行业企业共建</span><strong>{{ valueText(declaration.industry_cobuilt) }}</strong></article>
              <article><span>课程纳入培养方案</span><strong>{{ valueText(declaration.curriculum_in_plan) }}</strong></article>
              <article><span>是否挂牌</span><strong>{{ valueText(declaration.has_signboard) }}</strong></article>
              <article><span>是否签订协议</span><strong>{{ valueText(declaration.has_agreement) }}</strong></article>
            </div>
            <div class="base-long-text-list">
              <article><span>计划实习内容</span><p>{{ valueText(declaration.planned_content) }}</p></article>
              <article v-if="declaration.remark"><span>备注</span><p>{{ declaration.remark }}</p></article>
            </div>
            <div class="base-declaration-data-grid">
              <section>
                <header><strong>经费预算</strong><small>合计 {{ declarationBudgetTotal(declaration) }}</small></header>
                <el-table v-if="declaration.budgets.length" :data="declaration.budgets" stripe size="small">
                  <el-table-column prop="item_name" label="项目" min-width="180" />
                  <el-table-column label="金额" width="130"><template #default="{ row }">{{ moneyValueText(row.amount) }}</template></el-table-column>
                </el-table>
                <el-empty v-else description="暂无预算" :image-size="44" />
              </section>
              <section>
                <header><strong>历年接纳人数</strong><small>年度申报快照</small></header>
                <el-table v-if="declaration.reception_stats.length" :data="declaration.reception_stats" stripe size="small">
                  <el-table-column prop="stat_year" label="年份" min-width="100" />
                  <el-table-column prop="student_count" label="接纳人数" min-width="110" />
                </el-table>
                <el-empty v-else description="暂无接纳记录" :image-size="44" />
              </section>
            </div>
          </section>
        </div>
        <el-empty v-else description="暂无年度申报资料" :image-size="64" />
      </template>

      <template v-else-if="activeTab === 'construction'">
        <section class="internship-edit-section">
          <header><div><strong>基地建设方案</strong><small>填写建设目标、实施内容和保障措施</small></div></header>
          <p v-if="readonly" class="detail-long-text">{{ valueText(local.construction_content) }}</p>
          <textarea v-else v-model="local.construction_content" class="section-textarea" rows="7" />
        </section>

        <section class="internship-edit-section">
          <header>
            <div><strong>预算明细</strong><small>合计 {{ budgetTotalText }}</small></div>
            <el-button v-if="!readonly" :icon="Plus" size="small" @click="addBudget">新增</el-button>
          </header>
          <el-table v-if="readonly && local.budgets.length" :data="local.budgets" stripe size="small">
            <el-table-column prop="item_name" label="项目" min-width="160" />
            <el-table-column prop="content" label="内容" min-width="260" show-overflow-tooltip />
            <el-table-column label="金额" width="120"><template #default="{ row }">{{ moneyText(row.amount) }}</template></el-table-column>
            <el-table-column prop="remark" label="备注" min-width="180" show-overflow-tooltip />
          </el-table>
          <div v-else-if="local.budgets.length" class="dynamic-row-list">
            <div v-for="(item, index) in local.budgets" :key="`budget-${index}`" class="dynamic-form-row budget-row">
              <label><span>项目</span><input v-model="item.item_name"></label>
              <label><span>内容</span><input v-model="item.content"></label>
              <label><span>金额</span><input v-model="item.amount" type="number" min="0" step="0.01"></label>
              <label><span>备注</span><input v-model="item.remark"></label>
              <el-button text type="danger" :icon="Trash2" @click="local.budgets.splice(index, 1)">删除</el-button>
            </div>
          </div>
          <el-empty v-else description="暂无预算明细" :image-size="52" />
        </section>
      </template>
    </div>

    <footer class="internship-component-footer">
      <el-button @click="emit('cancel')">关闭</el-button>
      <el-button v-if="!readonly" type="primary" :icon="Save" :loading="loading" :disabled="loading" @click="submit">
        保存基地
      </el-button>
    </footer>
  </section>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Building2, CalendarRange, ClipboardList, Handshake, Plus, Save, Trash2, UsersRound } from '@lucide/vue';
import BasePersonRows from './InternshipBasePersonRows.vue';
import TextTabs from './TextTabs.vue';

const props = defineProps({
  detail: { type: Object, default: () => ({}) },
  loading: { type: Boolean, default: false },
  options: { type: Object, default: () => ({}) },
  readonly: { type: Boolean, default: false },
  canSyncTeachers: { type: Boolean, default: false },
});

const emit = defineEmits(['cancel', 'submit']);
const baseTypeOptions = [
  { label: '长期基地', value: 'long_term' },
  { label: '临时基地', value: 'temporary' },
];
const local = reactive(emptyForm());
const activeTab = ref('profile');
const professionOptions = computed(() => {
  const depId = Number(local.dep_id || 0);
  return (props.options.professions || []).filter(item => !depId || Number(item.dep_id) === depId);
});
const tabs = computed(() => {
  const items = [{ key: 'profile', label: '基地资料', icon: Building2, count: null }];
  if (local.base_type === 'long_term') {
    items.push(
      { key: 'people', label: '指导人员', icon: UsersRound, count: local.teachers.length + local.mentors.length },
      { key: 'cooperation', label: '合作单位', icon: Handshake, count: local.existing_sites.length },
      { key: 'declarations', label: '年度申报', icon: CalendarRange, count: local.declarations.length },
      { key: 'construction', label: '建设与预算', icon: ClipboardList, count: local.budgets.length },
    );
  }
  return items;
});
const departmentText = computed(() => props.detail.item?.dep_name
  || (props.options.departments || []).find(item => Number(item.dep_id) === Number(local.dep_id))?.dep_name
  || '-');
const companyText = computed(() => props.detail.item?.company_name
  || (props.options.companies || []).find(item => Number(item.company_id) === Number(local.company_id))?.company_name
  || '-');
const professionText = computed(() => {
  const detailNames = (props.detail.professions || []).map(item => item.profession_name).filter(Boolean);
  if (detailNames.length) {
    return detailNames.join('、');
  }
  const selected = new Set(local.profession_ids.map(Number));
  return (props.options.professions || [])
    .filter(item => selected.has(Number(item.profession_id)))
    .map(item => item.profession_name)
    .filter(Boolean)
    .join('、') || '-';
});
const budgetTotal = computed(() => local.budgets.reduce((sum, item) => sum + (Number(item.amount) || 0), 0));
const budgetTotalText = computed(() => moneyText(budgetTotal.value));

watch(
  () => props.detail,
  value => {
    Object.assign(local, emptyForm(value));
    activeTab.value = 'profile';
  },
  { immediate: true, deep: true },
);

watch(
  () => local.dep_id,
  () => {
    const visible = new Set(professionOptions.value.map(item => Number(item.profession_id)));
    local.profession_ids = local.profession_ids.filter(id => visible.has(Number(id)));
  },
);

watch(
  () => local.base_type,
  value => {
    if (value === 'temporary') {
      activeTab.value = 'profile';
    }
  },
);

function emptyForm(detail = {}) {
  const item = detail.item || detail || {};
  return {
    id: item.id || null,
    uuid: item.uuid || '',
    base_type: item.base_type || 'long_term',
    name: item.name || '',
    code: item.code || '',
    company_id: item.company_id || null,
    dep_id: item.dep_id || null,
    profession_ids: [...(detail.profession_ids || [])],
    address: item.address || '',
    area: item.area ?? '',
    annual_student_count: item.annual_student_count ?? 0,
    current_student_count: item.current_student_count ?? 0,
    service_courses: item.service_courses || '',
    category: item.category || '',
    capacity: item.capacity ?? 0,
    manager: person(detail.manager || {
      name: item.manager_name || '',
      phone: item.manager_phone || '',
    }),
    teachers: (detail.teachers || []).map(person),
    mentors: (detail.mentors || []).map(person),
    existing_sites: (detail.existing_sites || []).map(site => ({
      site_name: site.site_name || '',
      cooperation: site.cooperation || '',
    })),
    company_profile: {
      company_name: detail.company_profile?.company_name || '',
      registered_capital: detail.company_profile?.registered_capital || '',
      main_business: detail.company_profile?.main_business || '',
      employee_count: detail.company_profile?.employee_count ?? 0,
      annual_intern_count: detail.company_profile?.annual_intern_count ?? 0,
      senior_title_count: detail.company_profile?.senior_title_count ?? 0,
    },
    construction_content: detail.construction?.content || '',
    declarations: (detail.declarations || []).map(declaration => ({
      ...declaration,
      budgets: [...(declaration.budgets || [])],
      reception_stats: [...(declaration.reception_stats || [])],
    })),
    budgets: (detail.budgets || []).map(budget => ({
      item_name: budget.item_name || '',
      content: budget.content || '',
      amount: budget.amount ?? '',
      remark: budget.remark || '',
    })),
  };
}

function person(value = {}) {
  return {
    user_id: value.user_id ? Number(value.user_id) : null,
    teacher_id: value.teacher_id ? Number(value.teacher_id) : null,
    teacher_num: value.teacher_num || '',
    name: value.name || '',
    dep_name: value.dep_name || '',
    profession_name: value.profession_name || '',
    email: value.email || '',
    gender: value.gender || '',
    birth_date: value.birth_date || '',
    title: value.title || '',
    education: value.education || '',
    phone: value.phone || '',
    duties: value.duties || '',
  };
}

function addExistingSite() {
  local.existing_sites.push({ site_name: '', cooperation: '' });
}

function addBudget() {
  local.budgets.push({ item_name: '', content: '', amount: '', remark: '' });
}

function valueText(value, suffix = '') {
  return value === null || value === undefined || value === '' ? '-' : `${value}${suffix}`;
}

function moneyText(value) {
  return `${(Number(value) || 0).toFixed(2)} 元`;
}

/** 格式化可空金额 */
function moneyValueText(value) {
  return value === null || value === undefined || value === '' ? '-' : moneyText(value);
}

/** 计算年度申报预算合计 */
function declarationBudgetTotal(declaration) {
  const total = (declaration.budgets || []).reduce((sum, item) => sum + (Number(item.amount) || 0), 0);
  return moneyText(total);
}

function submit() {
  emit('submit', JSON.parse(JSON.stringify(local)));
}
</script>
