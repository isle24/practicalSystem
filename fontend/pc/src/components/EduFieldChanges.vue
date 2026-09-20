<script setup>
import { computed } from 'vue';

const props = defineProps({ value: { type: [Array, Object, String], default: () => [] } });
const labels = {
  student_num: '学号', student_name: '姓名', gender: '性别', grade_code: '年级代码', grade_name: '年级',
  dep_code: '学院代码', dep_name: '学院', profession_code: '专业代码', profession_name: '专业',
  class_num: '班号', class_name: '班级', student_status: '学籍状态', campus_status: '在校状态',
  enrollment_status: '有无学籍', education_level: '学历层次', training_level: '培养层次',
  enrollment_date: '入学日期', graduation_year: '毕业届次', academic_year: '来源学年', semester: '来源学期',
  course_code: '课程代码', course_name: '课程名称', course_category: '课程类别', course_nature: '课程性质',
  credit: '学分', weekly_hours: '周学时', total_hours: '总学时', theory_hours: '理论学时',
  experiment_hours: '实验学时', practice_hours: '实践学时', other_hours: '其他学时',
  student_count: '学生人数', teacher_num: '教师工号', teacher_name: '教师姓名',
  source_status: '来源状态', mapping_status: '关联状态', business_type: '业务类型',
  candidate_status: '候选状态', grade_id: '年级编号', dep_id: '学院编号', profession_id: '专业编号',
  class_id: '班级编号', student_id: '学生档案编号', teacher_id: '教师档案编号', teaching_class_name: '教学班',
};
const hidden = new Set(['email', 'raw_payload', 'sensitive_payload_cipher', 'mobile_hmac', 'identity_last_six_hmac', 'source_hash', 'source_key', 'scope_key']);
const changes = computed(() => {
  let rows = props.value;
  if (typeof rows === 'string') {
    try { rows = JSON.parse(rows); } catch { return []; }
  }
  return Array.isArray(rows) ? rows.filter(row => row?.field && !hidden.has(row.field)) : [];
});
function text(value) {
  if (value === null || value === undefined || value === '') return '未填写';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}
function valueText(field, value) {
  if (['source_status', 'mapping_status', 'candidate_status', 'business_type'].includes(field)) {
    return ({ active: '有效', inactive: '停用', missing: '来源缺失', pending: '待处理', matched: '已关联', unmatched: '未关联', ignored: '已忽略', confirmed: '已确认', generated: '已生成', internship: '实习', training: '实训', lab: '实验', social_practice: '社会实践' })[value] || text(value);
  }
  return text(value);
}
</script>

<template>
  <div v-if="changes.length" class="edu-field-changes">
    <div class="edu-field-changes__head"><span>字段</span><span>原值</span><span>新值</span></div>
    <div v-for="(item, index) in changes" :key="`${item.field}-${index}`" class="edu-field-changes__row">
      <strong>{{ labels[item.field] || item.field }}</strong>
      <span class="edu-field-changes__before">{{ valueText(item.field, item.before) }}</span>
      <span>{{ valueText(item.field, item.after) }}</span>
    </div>
  </div>
  <span v-else>无可展示的字段差异</span>
</template>

<style scoped>
.edu-field-changes { min-width: 0; font-size: 13px; }
.edu-field-changes__head, .edu-field-changes__row { display: grid; grid-template-columns: minmax(90px, 1fr) repeat(2, minmax(0, 2fr)); gap: 16px; padding: 9px 12px; }
.edu-field-changes__head { color: var(--text-secondary, #667085); background: var(--el-fill-color-light); }
.edu-field-changes__row + .edu-field-changes__row { border-top: 1px solid var(--el-border-color-lighter); }
.edu-field-changes__row > * { min-width: 0; overflow-wrap: anywhere; white-space: pre-wrap; font-weight: normal; }
.edu-field-changes__before { color: var(--text-secondary, #667085); }
</style>
