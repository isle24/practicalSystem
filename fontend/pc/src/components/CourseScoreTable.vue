<script setup>
const props = defineProps({
  rows: { type: Array, default: () => [] },
  attendanceIndexes: { type: Array, default: () => [] },
  projectIndexes: { type: Array, default: () => [] },
  allVisibleSelected: { type: Boolean, default: false },
  formatCell: { type: Function, required: true },
  scoreCell: { type: Function, required: true },
  storageKey: { type: String, default: 'course-score-sheet' },
});
const selectedStudentIds = defineModel('selectedStudentIds', { type: Array, default: () => [] });
const emit = defineEmits(['select-all']);
const projectStatus = { accept: '已通过', wait: '待审核', modify: '退回修改', draft: '草稿', missing: '未录入' };
</script>

<template>
  <el-table
    :data="props.rows"
    :row-key="row => `${row.plan_id || 0}:${row.student_id || row.sequence}`"
    :width-storage-key="props.storageKey"
    height="100%"
    class="course-score-table"
    empty-text="暂无成绩记载数据"
  >
    <el-table-column type="expand" column-key="project_details" width="46">
      <template #default="{ row }">
        <section class="course-score-project-detail">
          <p>每个项目的考勤占一格，历史单次考勤逐项显示。课程考勤小计按项目权重折算。</p>
          <el-table v-if="row.projects?.length" :data="row.projects" size="small">
            <el-table-column prop="project_title" label="项目" min-width="160" />
            <el-table-column prop="attendance_score" label="考勤" min-width="78" />
            <el-table-column prop="operation_score" label="实操" min-width="78" />
            <el-table-column prop="report_score" label="报告" min-width="78" />
            <el-table-column prop="score_value" label="项目原始分" min-width="100" />
            <el-table-column label="项目权重" min-width="90"><template #default="{ row: project }">{{ project.weight }}%</template></el-table-column>
            <el-table-column label="审核状态" min-width="96"><template #default="{ row: project }">{{ projectStatus[project.status] || project.status }}</template></el-table-column>
          </el-table>
          <p v-else>暂无已绑定项目</p>
        </section>
      </template>
    </el-table-column>
    <el-table-column column-key="student_selection" min-width="54" align="center">
      <template #header>
        <input
          type="checkbox"
          :checked="props.allVisibleSelected"
          :disabled="!props.rows.length"
          aria-label="选择当前页全部学生"
          @change="emit('select-all', $event)"
        >
      </template>
      <template #default="{ row }">
        <input
          v-model="selectedStudentIds"
          type="checkbox"
          :value="Number(row.student_id)"
          :disabled="!row.student_id"
          :aria-label="`选择${row.student_name || row.student_num || '学生'}`"
        >
      </template>
    </el-table-column>
    <el-table-column prop="sequence" column-key="sequence" label="序号" min-width="70" align="center"><template #default="{ row }">{{ props.formatCell(row.sequence) }}</template></el-table-column>
    <el-table-column prop="student_num" column-key="student_num" label="学号" min-width="140" align="center"><template #default="{ row }">{{ props.formatCell(row.student_num) }}</template></el-table-column>
    <el-table-column prop="student_name" column-key="student_name" label="姓名" min-width="100" align="center"><template #default="{ row }">{{ props.formatCell(row.student_name) }}</template></el-table-column>
    <el-table-column prop="class_name" column-key="class_name" label="行政班级" min-width="160" align="center"><template #default="{ row }">{{ props.formatCell(row.class_name) }}</template></el-table-column>
    <el-table-column label="考勤与课堂表现" align="center">
      <el-table-column v-for="index in props.attendanceIndexes" :key="`att-${index}`" :prop="`attendance_${index}`" :column-key="`attendance_${index}`" :label="String(index)" min-width="70" align="center"><template #default="{ row }">{{ props.scoreCell(row, `attendance_${index}`) }}</template></el-table-column>
      <el-table-column prop="attendance_total" column-key="attendance_total" label="小计" min-width="80" align="center"><template #default="{ row }">{{ props.scoreCell(row, 'attendance_total') }}</template></el-table-column>
    </el-table-column>
    <el-table-column label="项目（实操）成绩" align="center">
      <el-table-column v-for="index in props.projectIndexes" :key="`project-${index}`" :prop="`project_${index}`" :column-key="`project_${index}`" :label="String(index)" min-width="70" align="center"><template #default="{ row }">{{ props.scoreCell(row, `project_${index}`) }}</template></el-table-column>
    </el-table-column>
    <el-table-column prop="report_score" column-key="report_score" min-width="100" align="center">
      <template #header>课程报告</template>
      <template #default="{ row }">{{ props.scoreCell(row, 'report_score') }}</template>
    </el-table-column>
    <el-table-column prop="preview_score" column-key="preview_score" label="试算成绩" min-width="96" align="center"><template #default="{ row }">{{ props.scoreCell(row, 'preview_score') }}</template></el-table-column>
    <el-table-column prop="total_score" column-key="total_score" label="课程最终成绩" min-width="110" align="center"><template #default="{ row }">{{ props.scoreCell(row, 'total_score') }}</template></el-table-column>
    <el-table-column column-key="accepted_projects" label="已审核项目" min-width="104" align="center"><template #default="{ row }">{{ row.accepted_project_count }} / {{ row.expected_project_count }}</template></el-table-column>
  </el-table>
</template>

<style scoped>
.course-score-project-detail {
  padding: 8px 16px 16px;
}
</style>
