<template>
  <AppSheet
    :model-value="practiceMaterialDialog.visible"
    :title="`${practiceMaterialDialog.row ? '编辑' : '新增'}${practiceMaterialTitle(practiceMaterialDialog.panel)}`"
    :close-on-overlay="false"
    @update:model-value="!$event && closePracticeMaterialDialog()"
  >
    <section class="practice-material-content">
      <label class="app-field">
        <span>开课任务</span>
        <select v-model.number="practiceMaterialDialog.form.plan_id" @change="changePracticeMaterialPlan">
          <option :value="null" disabled>请选择开课任务</option>
          <option v-for="plan in practiceMaterialPlans(practiceMaterialDialog.module, practiceMaterialDialog.entity)" :key="plan.id" :value="plan.id">
            {{ plan.title || plan.course_name }}
          </option>
        </select>
      </label>
      <label class="app-field">
        <span>标题</span>
        <input v-model="practiceMaterialDialog.form.title" maxlength="180">
      </label>
      <template v-if="practiceMaterialDialog.entity === 'gradeRule'">
        <div class="practice-ratio-fields">
          <label class="app-field">
            <span>考勤与课堂表现（%）</span>
            <input v-model.number="practiceMaterialDialog.form.attendance_weight" type="number" min="0" max="100" inputmode="decimal">
          </label>
          <label class="app-field">
            <span>项目实操（%）</span>
            <input v-model.number="practiceMaterialDialog.form.operation_weight" type="number" min="0" max="100" inputmode="decimal">
          </label>
          <label class="app-field">
            <span>项目报告（%）</span>
            <input v-model.number="practiceMaterialDialog.form.report_weight" type="number" min="0" max="100" inputmode="decimal">
          </label>
        </div>
        <small class="ratio-total">合计 {{ ratioTotal }}%</small>
        <div class="practice-project-ratios">
          <header><strong>课程项目权重</strong><span>合计 {{ projectRatioTotal }}%</span></header>
          <small v-if="!practiceMaterialDialog.form.projects.length">当前开课任务暂无已发布项目</small>
          <label v-for="project in practiceMaterialDialog.form.projects" :key="project.project_id" class="app-field">
            <span>{{ project.title || `项目 ${project.project_id}` }}</span>
            <input v-model.number="project.weight" type="number" min="0" max="100" inputmode="decimal">
          </label>
        </div>
      </template>
      <label v-else class="app-field">
        <span>内容</span>
        <textarea v-model="practiceMaterialDialog.form.content" rows="10" maxlength="30000" />
      </label>
    </section>
    <template #footer>
      <AppButton variant="secondary" @click="closePracticeMaterialDialog">取消</AppButton>
      <AppButton
        v-if="practiceMaterialDialog.entity !== 'gradeRule'"
        variant="secondary"
        :loading="practiceModule(practiceMaterialDialog.module).loading"
        @click="submitPracticeMaterial('draft')"
      >
        保存草稿
      </AppButton>
      <AppButton
        :loading="practiceModule(practiceMaterialDialog.module).loading"
        @click="submitPracticeMaterial('wait')"
      >
        {{ practiceMaterialDialog.entity === 'gradeRule' ? '保存方案' : '提交审核' }}
      </AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import { computed } from 'vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import { usePracticeContext } from './practiceContext';

const {
  changePracticeMaterialPlan,
  closePracticeMaterialDialog,
  practiceMaterialDialog,
  practiceMaterialPlans,
  practiceMaterialTitle,
  practiceModule,
  submitPracticeMaterial,
} = usePracticeContext();

const ratioTotal = computed(() => [
  practiceMaterialDialog.form.attendance_weight,
  practiceMaterialDialog.form.operation_weight,
  practiceMaterialDialog.form.report_weight,
].reduce((sum, value) => sum + Number(value || 0), 0));

const projectRatioTotal = computed(() => Math.round((practiceMaterialDialog.form.projects || [])
  .reduce((sum, project) => sum + Number(project.weight || 0), 0) * 100) / 100);
</script>

<style scoped>
.practice-material-content {
  display: grid;
  gap: 14px;
  padding: 14px 16px;
}

.practice-ratio-fields {
  display: grid;
  gap: 12px;
}

.ratio-total {
  margin-top: -6px;
  color: var(--app-text-secondary);
  text-align: right;
}

.practice-project-ratios {
  display: grid;
  gap: 12px;
  padding-top: 4px;
}

.practice-project-ratios header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--app-line);
}

.practice-project-ratios header span,
.practice-project-ratios > small {
  color: var(--app-text-secondary);
  font-size: 12px;
}
</style>
