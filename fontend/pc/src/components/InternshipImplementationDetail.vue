<template>
  <section class="internship-dialog-component implementation-detail-component">
    <div class="implementation-tabs">
      <button v-for="item in tabs" :key="item.key" type="button" :class="{ active: activeTab === item.key }" @click="activeTab = item.key">
        <component :is="item.icon" :size="16" />
        <span>{{ item.label }}</span>
        <small>{{ item.count }}</small>
      </button>
    </div>

    <div class="internship-detail-scroll implementation-tab-body">
      <template v-if="activeTab === 'overview'">
        <div class="detail-summary-grid">
          <article><span>课程</span><strong>{{ task.course_name || task.title || '-' }}</strong></article>
          <article><span>任务编号</span><strong>{{ task.task_no || '-' }}</strong></article>
          <article><span>批次</span><strong>{{ task.batch_no || '-' }}</strong></article>
          <article><span>负责老师</span><strong>{{ task.teacher_name || '-' }}</strong></article>
          <article><span>届次</span><strong>{{ task.grade_name || '-' }}</strong></article>
          <article><span>学院</span><strong>{{ task.dep_name || '-' }}</strong></article>
          <article><span>专业</span><strong>{{ task.profession_name || '-' }}</strong></article>
          <article><span>实习基地</span><strong>{{ task.base_name || '-' }}</strong></article>
          <article><span>时间</span><strong>{{ dateRange(task.start_date, task.end_date) }}</strong></article>
          <article><span>地点</span><strong>{{ task.location || '-' }}</strong></article>
          <article><span>班级</span><strong>{{ classes.map(item => item.class_name).filter(Boolean).join('、') || '-' }}</strong></article>
          <article><span>学生</span><strong>{{ students.length }} 人</strong></article>
        </div>
        <section class="internship-edit-section">
          <header><div><strong>任务说明</strong><small>任务变更通过后会生成新的任务记录</small></div></header>
          <p class="detail-long-text">{{ task.description || '暂无任务说明' }}</p>
        </section>
      </template>

      <template v-else-if="activeTab === 'students'">
        <section v-if="canManage" class="implementation-add-student">
          <el-select v-model="newStudentId" filterable clearable placeholder="选择任务班级内学生">
            <el-option v-for="item in availableStudents" :key="item.student_id" :label="studentLabel(item)" :value="item.student_id" />
          </el-select>
          <el-button type="primary" :icon="UserPlus" :disabled="!newStudentId || loading" :loading="loading" @click="addStudent">绑定学生</el-button>
        </section>
        <el-table :data="students" height="100%" stripe size="small">
          <el-table-column type="index" label="序号" width="66" align="center" />
          <el-table-column prop="student_name" label="学生" width="110" />
          <el-table-column prop="student_num" label="学号" width="130" />
          <el-table-column prop="class_name" label="班级" min-width="140" />
          <el-table-column prop="profession_name" label="专业" min-width="150" />
          <el-table-column prop="teacher_name" label="负责老师" width="120" />
          <el-table-column prop="final_score" label="成绩" width="80" />
          <el-table-column v-if="canManage" label="操作" width="90" fixed="right">
            <template #default="{ row }">
              <el-popconfirm title="确认解除该学生与任务的绑定？" @confirm="emit('remove-student', row)">
                <template #reference><el-button link type="danger" :disabled="loading">解除</el-button></template>
              </el-popconfirm>
            </template>
          </el-table-column>
        </el-table>
      </template>

      <template v-else-if="activeTab === 'changes'">
        <el-table :data="changes" height="100%" stripe size="small">
          <el-table-column type="index" label="序号" width="66" align="center" />
          <el-table-column prop="reason" label="变更原因" min-width="220" />
          <el-table-column prop="submitter_name" label="提交人" width="110" />
          <el-table-column prop="submitted_at" label="提交时间" width="168" />
          <el-table-column prop="reviewer_name" label="审核人" width="110" />
          <el-table-column prop="review_opinion" label="审核意见" min-width="180" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }"><el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag></template>
          </el-table-column>
        </el-table>
      </template>

      <template v-else>
        <div class="operation-form implementation-sheet-form">
          <label><span>申请人</span><input v-model="sheet.applicant_name" :disabled="!canManage"></label>
          <label><span>申请部门</span><input v-model="sheet.applicant_department" :disabled="!canManage"></label>
          <label><span>审批编号</span><input v-model="sheet.approval_no" :disabled="!canManage"></label>
          <label><span>届次</span>
            <el-select v-model="sheet.grade_id" filterable :disabled="!canManage">
              <el-option v-for="item in options.grades || []" :key="item.grade_id" :label="item.grade_name" :value="item.grade_id" />
            </el-select>
          </label>
          <label><span>课程名称</span><input v-model="sheet.course_name" :disabled="!canManage"></label>
          <label><span>课程性质</span><input v-model="sheet.course_type" :disabled="!canManage"></label>
          <label><span>学分</span><input v-model="sheet.credit" type="number" min="0" step="0.5" :disabled="!canManage"></label>
          <label><span>实习类型</span><input v-model="sheet.practice_type" :disabled="!canManage"></label>
          <label><span>实习方式</span><input v-model="sheet.internship_mode" :disabled="!canManage"></label>
          <label><span>组织方式</span><input v-model="sheet.organize_mode" :disabled="!canManage"></label>
          <label><span>已签承诺人数</span><input v-model="sheet.signed_count" type="number" min="0" :disabled="!canManage"></label>
          <label><span>未签承诺人数</span><input v-model="sheet.unsigned_count" type="number" min="0" :disabled="!canManage"></label>
          <label class="switch-field"><span>保险已核验</span><el-switch v-model="sheet.insurance_verified" active-value="true" inactive-value="false" :disabled="!canManage" /></label>
          <label class="span-2"><span>申请内容</span><textarea v-model="sheet.detail_content" rows="5" :disabled="!canManage" /></label>
          <label class="span-2"><span>备注</span><textarea v-model="sheet.remark" rows="3" :disabled="!canManage" /></label>
        </div>

        <section class="internship-edit-section">
          <header>
            <div><strong>组织安排</strong><small>按专业、届次和批次分别维护</small></div>
            <el-button v-if="canManage" :icon="Plus" size="small" @click="addSchedule">新增</el-button>
          </header>
          <div v-if="schedules.length" class="dynamic-row-list">
            <div v-for="(item, index) in schedules" :key="`schedule-${index}`" class="dynamic-form-row schedule-row">
              <label><span>专业</span>
                <el-select v-model="item.profession_id" filterable clearable :disabled="!canManage" @change="syncProfession(item)">
                  <el-option v-for="option in options.professions || []" :key="option.profession_id" :label="option.profession_name" :value="option.profession_id" />
                </el-select>
              </label>
              <label><span>届次</span>
                <el-select v-model="item.grade_id" filterable clearable :disabled="!canManage" @change="syncGrade(item)">
                  <el-option v-for="option in options.grades || []" :key="option.grade_id" :label="option.grade_name" :value="option.grade_id" />
                </el-select>
              </label>
              <label><span>人数</span><input v-model="item.people_count" type="number" min="0" :disabled="!canManage"></label>
              <label><span>周次</span><input v-model="item.week_text" :disabled="!canManage"></label>
              <label><span>星期</span><input v-model="item.weekday_text" :disabled="!canManage"></label>
              <label><span>地点</span><input v-model="item.location" :disabled="!canManage"></label>
              <label><span>时间</span><input v-model="item.time_text" :disabled="!canManage"></label>
              <label><span>带队教师</span>
                <el-select v-model="item.teacher_id" filterable clearable :disabled="!canManage" @change="syncTeacher(item)">
                  <el-option v-for="option in options.teachers || []" :key="option.teacher_id" :label="option.teacher_name" :value="option.teacher_id" />
                </el-select>
              </label>
              <el-button v-if="canManage" text type="danger" :icon="Trash2" @click="schedules.splice(index, 1)">删除</el-button>
            </div>
          </div>
          <el-empty v-else description="暂无组织安排" :image-size="52" />
        </section>

        <section class="internship-edit-section">
          <header>
            <div><strong>费用明细</strong><small>合计 {{ totalAmount.toFixed(2) }} 元</small></div>
            <el-button v-if="canManage" :icon="Plus" size="small" @click="addExpense">新增</el-button>
          </header>
          <div v-if="expenses.length" class="dynamic-row-list">
            <div v-for="(item, index) in expenses" :key="`expense-${index}`" class="dynamic-form-row budget-row">
              <label><span>费用名称</span><input v-model="item.item_name" :disabled="!canManage"></label>
              <label><span>内容</span><input v-model="item.content" :disabled="!canManage"></label>
              <label><span>金额</span><input v-model="item.amount" type="number" min="0" step="0.01" :disabled="!canManage"></label>
              <label><span>备注</span><input v-model="item.remark" :disabled="!canManage"></label>
              <el-button v-if="canManage" text type="danger" :icon="Trash2" @click="expenses.splice(index, 1)">删除</el-button>
            </div>
          </div>
          <el-empty v-else description="暂无费用明细" :image-size="52" />
        </section>
      </template>
    </div>

    <footer class="internship-component-footer">
      <div>
        <el-button v-if="canManage" :icon="Edit3" @click="emit('edit-task', task)">任务变更</el-button>
        <el-button v-if="implementationSheet.id" :icon="Download" :loading="loading" @click="emit('export', task)">导出 PDF</el-button>
      </div>
      <div>
        <el-button @click="emit('close')">关闭</el-button>
        <template v-if="canManage && activeTab === 'sheet'">
          <el-button :loading="loading" :disabled="loading" @click="save('draft')">保存草稿</el-button>
          <el-button type="primary" :loading="loading" :disabled="loading" @click="save('confirmed')">确认实施</el-button>
        </template>
      </div>
    </footer>
  </section>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ClipboardList, Download, Edit3, History, Plus, Trash2, UserPlus, UsersRound } from '@lucide/vue';

const props = defineProps({
  canManage: { type: Boolean, default: false },
  detail: { type: Object, default: () => ({}) },
  loading: { type: Boolean, default: false },
  options: { type: Object, default: () => ({}) },
  statusTagType: { type: Function, required: true },
  statusText: { type: Function, required: true },
});

const emit = defineEmits(['add-student', 'close', 'edit-task', 'export', 'remove-student', 'save']);
const activeTab = ref('overview');
const newStudentId = ref(null);
const sheet = reactive(emptySheet());
const schedules = reactive([]);
const expenses = reactive([]);
const task = computed(() => props.detail.task || props.detail.item || {});
const classes = computed(() => props.detail.classes || []);
const students = computed(() => props.detail.students || []);
const changes = computed(() => props.detail.changes || []);
const implementationSheet = computed(() => props.detail.implementation_sheet || {});
const tabs = computed(() => [
  { key: 'overview', label: '任务概览', icon: ClipboardList, count: classes.value.length },
  { key: 'students', label: '学生绑定', icon: UsersRound, count: students.value.length },
  { key: 'changes', label: '任务变更', icon: History, count: changes.value.length },
  { key: 'sheet', label: '实施表', icon: ClipboardList, count: implementationSheet.value.id ? 1 : 0 },
]);
const availableStudents = computed(() => {
  const classIds = new Set(classes.value.map(item => Number(item.class_id || 0)).filter(Boolean));
  const boundIds = new Set(students.value.map(item => Number(item.student_id || 0)).filter(Boolean));
  return (props.options.students || []).filter(item => {
    const studentId = Number(item.student_id || 0);
    return studentId && !boundIds.has(studentId) && (!classIds.size || classIds.has(Number(item.class_id || 0)));
  });
});
const totalAmount = computed(() => expenses.reduce((sum, item) => sum + (Number(item.amount) || 0), 0));

watch(
  () => props.detail,
  value => resetForm(value),
  { immediate: true, deep: true },
);

function resetForm(detail = {}) {
  Object.assign(sheet, emptySheet(detail));
  schedules.splice(0, schedules.length, ...(detail.schedules || []).map(normalizeSchedule));
  expenses.splice(0, expenses.length, ...(detail.expenses || []).map(normalizeExpense));
  newStudentId.value = null;
}

function emptySheet(detail = {}) {
  const item = detail.implementation_sheet || {};
  const currentTask = detail.task || detail.item || {};
  return {
    id: item.id || null,
    uuid: item.uuid || '',
    applicant_name: item.applicant_name || item.applicant_user_name || '',
    applicant_department: item.applicant_department || currentTask.dep_name || '',
    approval_no: item.approval_no || '',
    grade_id: item.grade_id || currentTask.grade_id || null,
    course_name: item.course_name || currentTask.course_name || '',
    course_type: item.course_type || '',
    detail_content: item.detail_content || '',
    credit: item.credit ?? currentTask.credit ?? '',
    practice_type: item.practice_type || currentTask.type || '',
    internship_mode: item.internship_mode || '',
    organize_mode: item.organize_mode || currentTask.organize_mode || '',
    attachment_ids: Array.isArray(item.attachment_ids) ? item.attachment_ids : [],
    remark: item.remark || '',
    signed_count: item.signed_count ?? 0,
    unsigned_count: item.unsigned_count ?? 0,
    insurance_verified: item.insurance_verified || 'false',
    status: item.status || 'draft',
  };
}

function normalizeSchedule(item = {}) {
  return {
    profession_id: item.profession_id || null,
    profession_name: item.profession_name || '',
    grade_id: item.grade_id || null,
    grade_name: item.grade_name || '',
    people_count: item.people_count ?? 0,
    week_text: item.week_text || '',
    weekday_text: item.weekday_text || '',
    location: item.location || '',
    time_text: item.time_text || '',
    teacher_id: item.teacher_id || null,
    teacher_name: item.teacher_name || '',
  };
}

function normalizeExpense(item = {}) {
  return {
    item_name: item.item_name || '',
    content: item.content || '',
    amount: item.amount ?? '',
    remark: item.remark || '',
  };
}

function addStudent() {
  if (!newStudentId.value) {
    return;
  }
  emit('add-student', { student_id: newStudentId.value, arrangement_id: task.value.id });
}

function addSchedule() {
  schedules.push(normalizeSchedule({
    profession_id: task.value.profession_id || null,
    profession_name: task.value.profession_name || '',
    grade_id: task.value.grade_id || null,
    grade_name: task.value.grade_name || '',
    location: task.value.location || '',
    teacher_id: task.value.teacher_id || null,
    teacher_name: task.value.teacher_name || '',
  }));
}

function addExpense() {
  expenses.push(normalizeExpense());
}

function syncProfession(item) {
  const option = (props.options.professions || []).find(row => Number(row.profession_id) === Number(item.profession_id));
  item.profession_name = option?.profession_name || '';
}

function syncGrade(item) {
  const option = (props.options.grades || []).find(row => Number(row.grade_id) === Number(item.grade_id));
  item.grade_name = option?.grade_name || '';
}

function syncTeacher(item) {
  const option = (props.options.teachers || []).find(row => Number(row.teacher_id) === Number(item.teacher_id));
  item.teacher_name = option?.teacher_name || '';
}

function save(status) {
  emit('save', {
    ...JSON.parse(JSON.stringify(sheet)),
    arrangement_id: task.value.id,
    status,
    schedules: JSON.parse(JSON.stringify(schedules)),
    expenses: JSON.parse(JSON.stringify(expenses)),
  });
}

function studentLabel(item) {
  return [item.name || item.student_name, item.student_num, item.class_name].filter(Boolean).join(' / ');
}

function dateRange(start, end) {
  return start || end ? `${start || '-'} 至 ${end || '-'}` : '-';
}
</script>
