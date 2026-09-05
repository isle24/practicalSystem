<template>
  <van-notice-bar
    v-if="moduleState.message"
    color="#8a5a00"
    background="#fff3d8"
    left-icon="warning-o"
    :text="moduleState.message"
  />

  <section class="summary-band internship-summary">
    <div v-for="item in summaries" :key="item.name">
      <strong>{{ item.value }}</strong>
      <span>{{ item.name }}</span>
    </div>
  </section>

  <section v-if="isStudentRole" class="student-guide-card">
    <header>
      <Route :size="20" />
      <strong>{{ practiceModuleName(moduleType) }}流程</strong>
    </header>
    <div class="student-guide-steps">
      <button
        v-for="step in practiceFlowSteps"
        :key="step.panel"
        type="button"
        :class="{ active: currentPanel.key === step.panel }"
        @click="switchPracticePanel(moduleType, step.panel)"
      >
        {{ step.label }}
      </button>
    </div>
  </section>

  <section v-if="isTeacherRole" class="practice-teacher-navigation">
    <div v-for="group in teacherPanelGroups" :key="group.title">
      <small>{{ group.title }}</small>
      <AppScrollTabs
        class="practice-switch"
        :model-value="moduleState.panel"
        :items="group.items"
        @update:model-value="switchPracticePanel(moduleType, $event)"
      />
    </div>
  </section>
  <AppScrollTabs
    v-else
    class="practice-switch"
    :model-value="moduleState.panel"
    :items="panels"
    @update:model-value="switchPracticePanel(moduleType, $event)"
  />

  <section class="mobile-card">
    <header>
      <component :is="currentPanel.icon" :size="20" />
      <strong>{{ currentPanel.title }}</strong>
      <AppButton
        v-if="primaryAction"
        class="practice-primary-action"
        variant="quiet"
        size="small"
        @click="handlePracticePrimaryAction(moduleType)"
      >
        {{ primaryAction.label }}
      </AppButton>
    </header>
    <AppScrollTabs
      v-if="moduleState.panel === 'scores' && scoreTabs.length > 1"
      class="practice-score-tabs"
      :model-value="moduleState.scoreView"
      :items="scoreTabs"
      @update:model-value="switchPracticeScoreView(moduleType, $event)"
    />
    <MobileFilterSheet
      v-if="!isStudentRole"
      :select-filters="filters.filter(filter => filter.key !== 'status')"
      :status-options="filters.find(filter => filter.key === 'status')?.options || []"
      :values="moduleState.filters[currentPanel.key]"
      keyword-placeholder="标题、课程、学生、内容"
      :loading="moduleState.loading"
      @update-filter="payload => updatePracticeListFilter(moduleType, payload)"
      @search="reloadPracticeList(moduleType)"
      @reset="resetPracticeListFilters(moduleType)"
    />
    <section v-if="currentPanel.key === 'courseScores' && moduleState.courseScore.summary" class="practice-course-score-summary">
      <div><strong>{{ moduleState.courseScore.summary.student_count || 0 }}</strong><span>课程学生</span></div>
      <div><strong>{{ moduleState.courseScore.summary.page_completed_count || 0 }}</strong><span>本页已完成</span></div>
      <div><strong>{{ moduleState.courseScore.summary.page_average_score ?? '-' }}</strong><span>本页平均分</span></div>
    </section>
    <div v-if="isScheduleBoard" class="practice-schedule-groups">
      <section v-for="group in scheduleGroups" :key="group.date" class="practice-schedule-day">
        <header>{{ group.date }}</header>
        <article v-for="row in group.rows" :key="row.id" class="practice-schedule-item">
          <div>
            <strong>{{ practiceRowTitle(moduleType, row) }}</strong>
            <span>{{ practiceTypeName(row.module_type) }}</span>
          </div>
          <p v-for="fact in practiceRowFacts(moduleType, row)" :key="fact">{{ fact }}</p>
        </article>
      </section>
      <div v-if="!scheduleGroups.length" class="mobile-empty">{{ currentPanel.emptyText }}</div>
    </div>
    <div v-else class="app-practice-list">
      <AppListCard
        v-for="row in rows"
        :key="row.id || `${row.plan_id || 0}:${row.student_id || 0}`"
        :title="practiceRowTitle(moduleType, row)"
        :subtitle="rowSubtitle(row)"
        :status="row.status"
        :meta="practiceRowFacts(moduleType, row)"
      >
        <template v-if="practiceRowActions(moduleType, row).length" #actions>
          <AppButton
            v-for="action in practiceRowActions(moduleType, row)"
            :key="action.key"
            variant="quiet"
            size="small"
            @click="handlePracticeAction(moduleType, action, row)"
          >
            {{ action.label }}
          </AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!isScheduleBoard && !rows.length" class="mobile-empty">{{ currentPanel.emptyText }}</div>
    <div v-if="!isScheduleBoard" class="mobile-list-footer">
      <span>共 {{ practiceListTotal(moduleType) }} 条</span>
      <AppButton
        v-if="canLoadMorePractice(moduleType)"
        variant="secondary"
        size="small"
        :loading="moduleState.loading"
        @click="loadMorePracticeList(moduleType)"
      >
        加载更多
      </AppButton>
    </div>
  </section>

  <PracticeReviewPage />
  <PracticeExecutionPage />
  <PracticeMaterialPage />
  <PracticeArchivePage :module-type="moduleType" />
  <PracticeTimeline />
</template>

<script setup>
import { computed } from 'vue';
import { Route } from '@lucide/vue';
import MobileFilterSheet from '../../components/MobileFilterSheet.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import AppScrollTabs from '../../components/ui/AppScrollTabs.vue';
import PracticeExecutionPage from './PracticeExecutionPage.vue';
import PracticeArchivePage from './PracticeArchivePage.vue';
import PracticeMaterialPage from './PracticeMaterialPage.vue';
import PracticeReviewPage from './PracticeReviewPage.vue';
import PracticeTimeline from './PracticeTimeline.vue';
import { usePracticeContext } from './practiceContext';

const props = defineProps({
  moduleType: { type: String, required: true },
});

const {
  canLoadMorePractice,
  currentPracticePanel,
  currentPracticeRows,
  handlePracticeAction,
  isStudentRole,
  isTeacherRole,
  isAdminRole,
  loadMorePracticeList,
  practiceFilters,
  practiceFlowSteps,
  practiceListTotal,
  practiceModule,
  practiceModuleName,
  practiceScheduleGroups,
  practicePanels,
  practicePrimaryAction,
  practiceScoreTabs,
  practiceRowActions,
  practiceRowFacts,
  practiceRowTitle,
  practiceRowValue,
  practiceTypeName,
  practiceSummaries,
  reloadPracticeList,
  resetPracticeListFilters,
  statusText,
  switchPracticePanel,
  switchPracticeScoreView,
  handlePracticePrimaryAction,
  updatePracticeListFilter,
} = usePracticeContext();

const moduleState = computed(() => practiceModule(props.moduleType));
const summaries = computed(() => practiceSummaries(props.moduleType));
const panels = computed(() => practicePanels(props.moduleType));
const currentPanel = computed(() => currentPracticePanel(props.moduleType));
const filters = computed(() => practiceFilters(props.moduleType));
const rows = computed(() => currentPracticeRows(props.moduleType));
const isScheduleBoard = computed(() => isAdminRole.value && currentPanel.value.key === 'schedules');
const scheduleGroups = computed(() => practiceScheduleGroups(props.moduleType));
const primaryAction = computed(() => practicePrimaryAction(props.moduleType));
const scoreTabs = computed(() => practiceScoreTabs(props.moduleType));
const teacherPanelGroups = computed(() => {
  const preparationKeys = new Set(['plans', 'syllabus', 'lessonPlans', 'reflections', 'scores']);
  return [
    { title: '教学准备与提交', items: panels.value.filter(item => preparationKeys.has(item.key)) },
    { title: '教学实施与评阅', items: panels.value.filter(item => !preparationKeys.has(item.key)) },
  ].filter(group => group.items.length);
});

function rowSubtitle(row) {
  const value = practiceRowValue(props.moduleType, row);
  return value === statusText(row.status) ? '' : value;
}
</script>

<style scoped>
.app-practice-list {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}

.practice-teacher-navigation {
  display: grid;
  gap: 8px;
  margin-bottom: 12px;
}

.practice-teacher-navigation > div {
  min-width: 0;
}

.practice-teacher-navigation small {
  display: block;
  margin: 0 2px 6px;
  color: var(--app-text-secondary);
  font-size: 12px;
}

.practice-teacher-navigation :deep(.app-scroll-tabs),
.practice-score-tabs {
  margin-bottom: 0;
}

.practice-score-tabs {
  padding: 10px 12px 0;
}

.practice-primary-action {
  margin-left: auto;
}

.student-guide-steps button {
  width: 100%;
  border: 0;
  border-radius: var(--app-radius-sm);
  padding: 8px 10px;
  color: inherit;
  text-align: left;
  background: transparent;
}

.student-guide-steps button.active {
  color: var(--app-primary);
  background: var(--app-primary-soft);
}

.practice-course-score-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  padding: 12px 12px 0;
}

.practice-course-score-summary div {
  min-width: 0;
  display: grid;
  gap: 2px;
  padding: 10px 6px;
  border-radius: var(--app-radius-sm);
  text-align: center;
  background: var(--app-surface-muted);
}

.practice-course-score-summary strong {
  font-size: 17px;
}

.practice-course-score-summary span {
  color: var(--app-text-secondary);
  font-size: 11px;
}

.practice-schedule-groups {
  display: grid;
  gap: 12px;
  padding-top: 12px;
}

.practice-schedule-day {
  border: 1px solid var(--line);
  border-radius: 8px;
  overflow: hidden;
}

.practice-schedule-day > header {
  min-height: 38px;
  display: flex;
  align-items: center;
  padding: 0 12px;
  color: var(--muted);
  background: #f7f9fc;
  font-size: 13px;
  font-weight: 600;
}

.practice-schedule-item {
  display: grid;
  gap: 5px;
  padding: 12px;
  border-top: 1px solid var(--line);
}

.practice-schedule-item > div {
  display: flex;
  align-items: center;
  gap: 8px;
}

.practice-schedule-item strong {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
}

.practice-schedule-item span {
  flex: 0 0 auto;
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 1px 5px;
  color: var(--muted);
  font-size: 11px;
}

.practice-schedule-item p {
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.45;
}
</style>
