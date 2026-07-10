<template>
  <section class="mobile-list-switch multi">
    <button
      v-for="item in manageListTabs"
      :key="item.key"
      :class="{ active: internship.manageList === item.key }"
      @click="switchMobileList('manage', item.key)"
    >
      <component :is="item.icon" :size="17" />
      <span>{{ item.shortTitle }}</span>
    </button>
  </section>

  <section v-if="currentManageListConfig" class="mobile-card">
    <header>
      <component :is="currentManageListConfig.icon" :size="20" />
      <strong>{{ currentManageListConfig.title }}</strong>
    </header>
    <MobileFilterSheet
      :select-filters="mobileListSelectFilters(currentManageListConfig)"
      :status-options="currentManageListConfig.statusOptions"
      :status-key="currentManageListConfig.statusKey || 'status'"
      :values="internship.filters[currentManageListConfig.key]"
      :keyword-placeholder="currentManageListConfig.keywordPlaceholder"
      :loading="internship.loading"
      @update-filter="payload => updateInternshipListFilter(currentManageListConfig.key, payload)"
      @search="reloadInternshipList(currentManageListConfig.key)"
      @reset="resetInternshipListFilters(currentManageListConfig.key)"
    />
    <div class="app-manage-list">
      <AppListCard
        v-for="row in mobileListRows(currentManageListConfig.key)"
        :key="row.id"
        :title="mobileListTitle(currentManageListConfig.key, row)"
        :subtitle="mobileListValue(currentManageListConfig.key, row)"
        :status="row[currentManageListConfig.statusKey || 'status']"
        :meta="mobileListFacts(currentManageListConfig.key, row)"
      >
        <template v-if="mobileListActions(currentManageListConfig.key, row, 'manage').length" #actions>
          <AppButton
            v-for="action in mobileListActions(currentManageListConfig.key, row, 'manage')"
            :key="action.key"
            variant="quiet"
            size="small"
            @click="handleMobileListAction(action, row)"
          >
            {{ action.label }}
          </AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!mobileListRows(currentManageListConfig.key).length" class="mobile-empty">
      {{ currentManageListConfig.emptyText }}
    </div>
    <InternshipListFooter :list-key="currentManageListConfig.key" />
  </section>
</template>

<script setup>
import MobileFilterSheet from '../../components/MobileFilterSheet.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import InternshipListFooter from './InternshipListFooter.vue';
import { useInternshipContext } from './internshipContext';

const {
  currentManageListConfig,
  handleMobileListAction,
  internship,
  manageListTabs,
  mobileListActions,
  mobileListFacts,
  mobileListRows,
  mobileListSelectFilters,
  mobileListTitle,
  mobileListValue,
  reloadInternshipList,
  resetInternshipListFilters,
  switchMobileList,
  updateInternshipListFilter,
} = useInternshipContext();
</script>

<style scoped>
.app-manage-list {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}
</style>
