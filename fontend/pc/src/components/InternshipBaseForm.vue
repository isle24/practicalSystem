<template>
  <section class="internship-dialog-component base-form-component">
    <div class="internship-detail-scroll">
      <div class="internship-section-head">
        <div>
          <strong>{{ readonly ? '基地资料' : '基地类型' }}</strong>
          <small>{{ local.base_type === 'temporary' ? '临时基地仅维护必要联系和服务范围' : '长期基地维护完整申报资料' }}</small>
        </div>
        <el-segmented v-if="!readonly" v-model="local.base_type" :options="baseTypeOptions" />
        <el-tag v-else :type="local.base_type === 'temporary' ? 'warning' : 'success'">
          {{ local.base_type === 'temporary' ? '临时基地' : '长期基地' }}
        </el-tag>
      </div>

      <div class="operation-form base-main-form">
        <label>
          <span>基地名称{{ local.base_type === 'temporary' ? '（可选）' : '' }}</span>
          <input v-model="local.name" :disabled="readonly" :placeholder="local.base_type === 'temporary' ? '留空后按位置和负责人自动生成' : ''">
        </label>
        <label v-if="local.base_type === 'long_term'">
          <span>基地编号</span>
          <input v-model="local.code" :disabled="readonly">
        </label>
        <label>
          <span>所属学院</span>
          <el-select v-model="local.dep_id" clearable filterable :disabled="readonly">
            <el-option v-for="item in options.departments || []" :key="item.dep_id" :label="item.dep_name" :value="item.dep_id" />
          </el-select>
        </label>
        <label>
          <span>服务专业</span>
          <el-select v-model="local.profession_ids" multiple collapse-tags collapse-tags-tooltip filterable :disabled="readonly">
            <el-option v-for="item in professionOptions" :key="item.profession_id" :label="item.profession_name" :value="item.profession_id" />
          </el-select>
        </label>
        <label class="span-2">
          <span>基地所在位置</span>
          <input v-model="local.address" :disabled="readonly">
        </label>
        <label class="span-2">
          <span>服务课程</span>
          <textarea v-model="local.service_courses" rows="3" :disabled="readonly" />
        </label>
        <label>
          <span>基地负责人</span>
          <input v-model="local.manager.name" :disabled="readonly">
        </label>
        <label>
          <span>负责人电话</span>
          <input v-model="local.manager.phone" :disabled="readonly">
        </label>
        <template v-if="local.base_type === 'long_term'">
          <label>
            <span>合作单位</span>
            <el-select v-model="local.company_id" clearable filterable :disabled="readonly">
              <el-option v-for="item in options.companies || []" :key="item.company_id" :label="item.company_name" :value="item.company_id" />
            </el-select>
          </label>
          <label><span>基地类别</span><input v-model="local.category" :disabled="readonly"></label>
          <label><span>基地面积（平方米）</span><input v-model="local.area" type="number" min="0" :disabled="readonly"></label>
          <label><span>可接收人数</span><input v-model="local.capacity" type="number" min="0" :disabled="readonly"></label>
          <label><span>年均接收人数</span><input v-model="local.annual_student_count" type="number" min="0" :disabled="readonly"></label>
          <label><span>当前接收人数</span><input v-model="local.current_student_count" type="number" min="0" :disabled="readonly"></label>
          <label>
            <span>负责人性别</span>
            <el-select v-model="local.manager.gender" clearable :disabled="readonly">
              <el-option label="男" value="男" />
              <el-option label="女" value="女" />
            </el-select>
          </label>
          <label><span>负责人出生日期</span><input v-model="local.manager.birth_date" type="date" :disabled="readonly"></label>
          <label><span>负责人职务/职称</span><input v-model="local.manager.title" :disabled="readonly"></label>
          <label><span>负责人学历</span><input v-model="local.manager.education" :disabled="readonly"></label>
          <label class="span-2"><span>负责人职责</span><textarea v-model="local.manager.duties" rows="3" :disabled="readonly" /></label>
        </template>
      </div>

      <template v-if="local.base_type === 'long_term'">
        <BasePersonRows v-model="local.teachers" title="校内指导教师" :readonly="readonly" />
        <BasePersonRows v-model="local.mentors" title="企业指导教师" :readonly="readonly" />

        <section class="internship-edit-section">
          <header>
            <div><strong>既有实习基地</strong><small>维护已有合作基础和合作内容</small></div>
            <el-button v-if="!readonly" :icon="Plus" size="small" @click="addExistingSite">新增</el-button>
          </header>
          <div v-if="local.existing_sites.length" class="dynamic-row-list">
            <div v-for="(item, index) in local.existing_sites" :key="`site-${index}`" class="dynamic-form-row two-columns">
              <label><span>基地名称</span><input v-model="item.site_name" :disabled="readonly"></label>
              <label><span>合作内容</span><input v-model="item.cooperation" :disabled="readonly"></label>
              <el-button v-if="!readonly" text type="danger" :icon="Trash2" @click="local.existing_sites.splice(index, 1)">删除</el-button>
            </div>
          </div>
          <el-empty v-else description="暂无既有基地" :image-size="52" />
        </section>

        <section class="internship-edit-section">
          <header><div><strong>合作单位概况</strong><small>用于长期基地申报书</small></div></header>
          <div class="operation-form base-main-form compact-form">
            <label><span>单位名称</span><input v-model="local.company_profile.company_name" :disabled="readonly"></label>
            <label><span>注册资本</span><input v-model="local.company_profile.registered_capital" :disabled="readonly"></label>
            <label><span>员工人数</span><input v-model="local.company_profile.employee_count" type="number" min="0" :disabled="readonly"></label>
            <label><span>年均接收实习人数</span><input v-model="local.company_profile.annual_intern_count" type="number" min="0" :disabled="readonly"></label>
            <label><span>高级职称人数</span><input v-model="local.company_profile.senior_title_count" type="number" min="0" :disabled="readonly"></label>
            <label class="span-2"><span>主营业务</span><textarea v-model="local.company_profile.main_business" rows="3" :disabled="readonly" /></label>
          </div>
        </section>

        <section class="internship-edit-section">
          <header><div><strong>基地建设方案</strong><small>填写建设目标、实施内容和保障措施</small></div></header>
          <textarea v-model="local.construction_content" class="section-textarea" rows="7" :disabled="readonly" />
        </section>

        <section class="internship-edit-section">
          <header>
            <div><strong>预算明细</strong><small>金额用于申报书汇总</small></div>
            <el-button v-if="!readonly" :icon="Plus" size="small" @click="addBudget">新增</el-button>
          </header>
          <div v-if="local.budgets.length" class="dynamic-row-list">
            <div v-for="(item, index) in local.budgets" :key="`budget-${index}`" class="dynamic-form-row budget-row">
              <label><span>项目</span><input v-model="item.item_name" :disabled="readonly"></label>
              <label><span>内容</span><input v-model="item.content" :disabled="readonly"></label>
              <label><span>金额</span><input v-model="item.amount" type="number" min="0" step="0.01" :disabled="readonly"></label>
              <label><span>备注</span><input v-model="item.remark" :disabled="readonly"></label>
              <el-button v-if="!readonly" text type="danger" :icon="Trash2" @click="local.budgets.splice(index, 1)">删除</el-button>
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
import { computed, reactive, watch } from 'vue';
import { Plus, Save, Trash2 } from '@lucide/vue';
import BasePersonRows from './InternshipBasePersonRows.vue';

const props = defineProps({
  detail: { type: Object, default: () => ({}) },
  loading: { type: Boolean, default: false },
  options: { type: Object, default: () => ({}) },
  readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['cancel', 'submit']);
const baseTypeOptions = [
  { label: '长期基地', value: 'long_term' },
  { label: '临时基地', value: 'temporary' },
];
const local = reactive(emptyForm());
const professionOptions = computed(() => {
  const depId = Number(local.dep_id || 0);
  return (props.options.professions || []).filter(item => !depId || Number(item.dep_id) === depId);
});

watch(
  () => props.detail,
  value => Object.assign(local, emptyForm(value)),
  { immediate: true, deep: true },
);

watch(
  () => local.dep_id,
  () => {
    const visible = new Set(professionOptions.value.map(item => Number(item.profession_id)));
    local.profession_ids = local.profession_ids.filter(id => visible.has(Number(id)));
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
    user_id: value.user_id || null,
    name: value.name || '',
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

function submit() {
  emit('submit', JSON.parse(JSON.stringify(local)));
}
</script>
