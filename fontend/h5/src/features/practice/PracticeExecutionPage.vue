<template>
  <AppSheet
    :model-value="practiceExecutionDialog.visible"
    :title="practiceExecutionDialogTitle"
    :subtitle="practiceExecutionDialogSubtitle"
    :close-on-overlay="false"
    @update:model-value="!$event && closePracticeExecutionDialog()"
  >
    <section class="practice-execution-content">
      <label class="app-field">
        <span>项目</span>
        <select v-model.number="practiceExecutionDialog.form.project_id" @change="changePracticeExecutionProject">
          <option
            v-for="item in practiceExecutionProjectOptions()"
            :key="item.id"
            :value="item.id"
          >
            {{ item.title || item.course_name }}
          </option>
        </select>
      </label>
      <template v-if="practiceExecutionDialog.execution === 'sign_in'">
        <div class="gps-sign-card">
          <div class="gps-sign-head">
            <span>
              <strong>{{ practiceGpsTitle }}</strong>
              <small>{{ practiceExecutionDialog.form.location || practiceGpsHint }}</small>
            </span>
            <AppButton
              variant="secondary"
              size="small"
              :loading="practiceExecutionDialog.form.locating"
              @click="locatePracticePosition"
            >
              重新定位
            </AppButton>
          </div>
          <div class="gps-map-preview">
            <img v-if="practiceMapUrl" :src="practiceMapUrl" alt="签到定位地图">
            <div v-else>
              <MapPin :size="24" />
              <span>获取 GPS 后显示地图</span>
            </div>
          </div>
          <div class="gps-coordinate-grid">
            <span>经度 {{ coordinateText(practiceExecutionDialog.form.longitude) }}</span>
            <span>纬度 {{ coordinateText(practiceExecutionDialog.form.latitude) }}</span>
            <span>精度 {{ practiceAccuracyText }}</span>
          </div>
          <small v-if="practiceExecutionDialog.form.gps_error" class="gps-error">{{ practiceExecutionDialog.form.gps_error }}</small>
        </div>
      </template>
      <template v-else-if="practiceExecutionDialog.execution === 'score'">
        <label class="app-field">
          <span>学生</span>
          <select v-model.number="practiceExecutionDialog.form.student_id" @change="applyPracticeStudentScore">
            <option :value="null" disabled>请选择项目学生</option>
            <option v-for="student in practiceExecutionDialog.students" :key="student.student_id" :value="student.student_id">
              {{ student.student_name }} / {{ student.student_num }}
            </option>
          </select>
        </label>
        <div class="practice-score-fields">
          <label class="app-field">
            <span>考勤与课堂表现</span>
            <input v-model="practiceExecutionDialog.form.attendance_score" type="number" min="0" max="100" inputmode="decimal">
          </label>
          <label class="app-field">
            <span>项目实操</span>
            <input v-model="practiceExecutionDialog.form.material_score" type="number" min="0" max="100" inputmode="decimal">
          </label>
          <label class="app-field">
            <span>课程报告</span>
            <input v-model="practiceExecutionDialog.form.report_score" type="number" min="0" max="100" inputmode="decimal">
          </label>
          <label class="app-field">
            <span>总评成绩</span>
            <input v-model="practiceExecutionDialog.form.score_value" type="number" min="0" max="100" inputmode="decimal" placeholder="不填则按分项平均">
          </label>
        </div>
      </template>
      <template v-else>
        <label class="app-field">
          <span>标题</span>
          <input v-model="practiceExecutionDialog.form.title">
        </label>
        <label class="app-field">
          <span>日期</span>
          <input v-model="practiceExecutionDialog.form.date" type="date">
        </label>
        <label class="app-field">
          <span>内容</span>
          <textarea v-model="practiceExecutionDialog.form.content" rows="5" />
        </label>
        <label v-if="practiceExecutionDialog.execution === 'report'" class="app-field">
          <span>反思小结</span>
          <textarea v-model="practiceExecutionDialog.form.reflection_summary" rows="4" maxlength="5000" placeholder="总结本项目的收获、问题和改进方向" />
          <small class="app-field-help">{{ Array.from(practiceExecutionDialog.form.reflection_summary || '').length }} / 5000，提交审核时必填</small>
        </label>
      </template>
      <label v-if="practiceExecutionDialog.execution !== 'score'" class="app-field">
        <span>备注</span>
        <textarea v-model="practiceExecutionDialog.form.remark" rows="3" />
      </label>
    </section>
    <template #footer>
      <AppButton variant="secondary" @click="closePracticeExecutionDialog">取消</AppButton>
      <AppButton
        v-if="practiceExecutionDialog.execution !== 'sign_in'"
        variant="secondary"
        :loading="practiceModule(practiceExecutionDialog.module).loading"
        @click="submitPracticeExecution('draft')"
      >
        保存草稿
      </AppButton>
      <AppButton
        :loading="practiceModule(practiceExecutionDialog.module).loading"
        :disabled="practiceExecutionDialog.execution === 'sign_in' && !practiceGpsReady"
        @click="submitPracticeExecution(practiceExecutionDialog.execution === 'sign_in' ? 'signed' : 'wait')"
      >
        {{ practiceExecutionDialog.execution === 'sign_in' ? '提交签到' : '提交审核' }}
      </AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import { MapPin } from '@lucide/vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import { usePracticeContext } from './practiceContext';

const {
  closePracticeExecutionDialog,
  changePracticeExecutionProject,
  coordinateText,
  applyPracticeStudentScore,
  locatePracticePosition,
  practiceAccuracyText,
  practiceExecutionDialog,
  practiceExecutionProjectOptions,
  practiceExecutionDialogSubtitle,
  practiceExecutionDialogTitle,
  practiceGpsHint,
  practiceGpsReady,
  practiceGpsTitle,
  practiceMapUrl,
  practiceModule,
  submitPracticeExecution,
} = usePracticeContext();
</script>

<style scoped>
.practice-execution-content {
  display: grid;
  gap: 14px;
  padding: 14px 16px;
}

.practice-score-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

@media (max-width: 360px) {
  .practice-score-fields {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
