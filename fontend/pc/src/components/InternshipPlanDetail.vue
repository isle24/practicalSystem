<template>
  <section class="internship-dialog-component">
    <div class="internship-detail-scroll">
      <div class="detail-summary-grid plan-summary-grid">
        <article><span>课程名称</span><strong>{{ plan.course_name || '-' }}</strong></article>
        <article><span>课程代码</span><strong>{{ plan.course_code || '-' }}</strong></article>
        <article><span>实习类别</span><strong>{{ plan.category_name || '-' }}</strong></article>
        <article><span>{{ scopeLabel }}</span><strong>{{ scopeName || '-' }}</strong></article>
        <article><span>学院</span><strong>{{ plan.dep_name || '-' }}</strong></article>
        <article><span>专业</span><strong>{{ plan.profession_name || '-' }}</strong></article>
        <article><span>学分</span><strong>{{ plan.credit ?? '-' }}</strong></article>
        <article><span>成绩规则</span><strong>{{ scoreRuleText(plan.score_rule) }}</strong></article>
        <article><span>计划状态</span><el-tag :type="statusTagType(plan.status)">{{ statusText(plan.status) }}</el-tag></article>
      </div>

      <section class="internship-edit-section plan-source-section">
        <header><div><strong>教学计划来源</strong><small>导入数据保留原始教师、时间和地点，不自动推断任务</small></div></header>
        <div class="detail-text-grid">
          <label><span>课程类别</span><strong>{{ plan.course_category || '-' }}</strong></label>
          <label><span>总学分</span><strong>{{ plan.total_credit ?? '-' }}</strong></label>
          <label><span>实习学分</span><strong>{{ plan.internship_credit ?? '-' }}</strong></label>
          <label><span>总学时</span><strong>{{ plan.total_hours ?? '-' }}</strong></label>
          <label><span>实习学时</span><strong>{{ plan.internship_hours ?? '-' }}</strong></label>
          <label><span>原始教师</span><strong>{{ plan.source_teacher || '-' }}</strong></label>
          <label><span>原始时间</span><strong>{{ plan.source_time || '-' }}</strong></label>
          <label><span>原始地点</span><strong>{{ plan.source_location || '-' }}</strong></label>
          <label class="wide-field"><span>备注</span><strong>{{ plan.remark || '-' }}</strong></label>
        </div>
      </section>

      <section class="internship-edit-section">
        <header>
          <div>
            <strong>任务拆分</strong>
            <small>一个计划可拆分多个任务，每个任务唯一对应一名负责老师</small>
          </div>
          <el-button v-if="canManage" type="primary" plain :icon="Plus" @click="emit('add-task', plan)">新增任务</el-button>
        </header>
        <el-table v-if="tasks.length" :data="tasks" max-height="360" stripe size="small">
          <el-table-column prop="task_no" label="任务编号" width="130" />
          <el-table-column prop="title" label="任务名称" min-width="180" />
          <el-table-column prop="teacher_name" label="负责老师" width="120" />
          <el-table-column prop="class_names" label="班级" min-width="160" show-overflow-tooltip />
          <el-table-column label="时间" min-width="190">
            <template #default="{ row }">{{ dateRange(row.start_date, row.end_date) }}</template>
          </el-table-column>
          <el-table-column prop="location" label="地点" min-width="140" show-overflow-tooltip />
          <el-table-column prop="task_binding_count" label="绑定学生" width="90" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }"><el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag></template>
          </el-table-column>
          <el-table-column label="操作" width="210" fixed="right">
            <template #default="{ row }">
              <el-button size="small" type="primary" plain @click="emit('implementation', row)">实习实施</el-button>
              <el-button v-if="canManage" size="small" link type="primary" @click="emit('edit-task', row)">变更</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-else description="该计划尚未拆分任务" :image-size="64" />
      </section>
    </div>

    <footer class="internship-component-footer">
      <el-button @click="emit('close')">关闭</el-button>
      <el-button v-if="canEdit" type="primary" :icon="Edit3" @click="emit('edit-plan', plan)">编辑计划</el-button>
    </footer>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { Edit3, Plus } from '@lucide/vue';

const props = defineProps({
  canManage: { type: Boolean, default: false },
  plan: { type: Object, default: () => ({}) },
  statusTagType: { type: Function, required: true },
  statusText: { type: Function, required: true },
});

const emit = defineEmits(['add-task', 'close', 'edit-plan', 'edit-task', 'implementation']);
const tasks = computed(() => Array.isArray(props.plan.tasks) ? props.plan.tasks : []);
const canEdit = computed(() => props.canManage && ['draft', 'modify'].includes(String(props.plan.status || '')));
const scopeLabel = computed(() => props.plan.scope_type === 'cohort' || props.plan.graduation_cohort_id ? '毕业届次' : '年级');
const scopeName = computed(() => scopeLabel.value === '毕业届次' ? props.plan.cohort_name : props.plan.grade_name);

function scoreRuleText(value) {
  return {
    average: '按任务平均',
    sum: '按任务累计',
    weighted: '按权重核定',
    manual: '人工核定',
  }[value] || value || '-';
}

function dateRange(start, end) {
  if (!start && !end) {
    return '-';
  }
  return `${start || '-'} 至 ${end || '-'}`;
}
</script>
