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
      <span v-for="step in practiceFlowSteps" :key="step">{{ step }}</span>
    </div>
  </section>

  <AppScrollTabs
    class="practice-switch"
    :model-value="moduleState.panel"
    :items="panels"
    @update:model-value="switchPracticePanel(moduleType, $event)"
  />

  <section class="mobile-card">
    <header>
      <component :is="currentPanel.icon" :size="20" />
      <strong>{{ currentPanel.title }}</strong>
    </header>
    <MobileFilterSheet
      v-if="!isStudentRole"
      :select-filters="filters.filter(filter => filter.key !== 'status')"
      :status-options="filters.find(filter => filter.key === 'status')?.options || []"
      :values="moduleState.filters[moduleState.panel]"
      keyword-placeholder="标题、课程、学生、内容"
      :loading="moduleState.loading"
      @update-filter="payload => updatePracticeListFilter(moduleType, payload)"
      @search="reloadPracticeList(moduleType)"
      @reset="resetPracticeListFilters(moduleType)"
    />
    <div class="app-practice-list">
      <AppListCard
        v-for="row in rows"
        :key="row.id"
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
    <div v-if="!rows.length" class="mobile-empty">{{ currentPanel.emptyText }}</div>
    <div class="mobile-list-footer">
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
  loadMorePracticeList,
  practiceFilters,
  practiceFlowSteps,
  practiceListTotal,
  practiceModule,
  practiceModuleName,
  practicePanels,
  practiceRowActions,
  practiceRowFacts,
  practiceRowTitle,
  practiceRowValue,
  practiceSummaries,
  reloadPracticeList,
  resetPracticeListFilters,
  statusText,
  switchPracticePanel,
  updatePracticeListFilter,
} = usePracticeContext();

const moduleState = computed(() => practiceModule(props.moduleType));
const summaries = computed(() => practiceSummaries(props.moduleType));
const panels = computed(() => practicePanels(props.moduleType));
const currentPanel = computed(() => currentPracticePanel(props.moduleType));
const filters = computed(() => practiceFilters(props.moduleType));
const rows = computed(() => currentPracticeRows(props.moduleType));

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
</style>
