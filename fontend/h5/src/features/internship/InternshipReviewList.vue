<template>
  <section v-if="reviewListTabs.length > 1" class="mobile-list-switch">
    <button
      v-for="item in reviewListTabs"
      :key="item.key"
      :class="{ active: internship.reviewList === item.key }"
      @click="switchMobileList('review', item.key)"
    >
      <component :is="item.icon" :size="17" />
      <span>{{ item.shortTitle }}</span>
    </button>
  </section>

  <section v-if="currentReviewListConfig" class="mobile-card">
    <header>
      <component :is="currentReviewListConfig.icon" :size="20" />
      <strong>{{ currentReviewListConfig.title }}</strong>
    </header>
    <MobileFilterSheet
      :select-filters="mobileListSelectFilters(currentReviewListConfig)"
      :status-options="currentReviewListConfig.statusOptions"
      :values="internship.filters[currentReviewListConfig.key]"
      :keyword-placeholder="currentReviewListConfig.keywordPlaceholder"
      :loading="internship.loading"
      @update-filter="payload => updateInternshipListFilter(currentReviewListConfig.key, payload)"
      @search="reloadInternshipList(currentReviewListConfig.key)"
      @reset="resetInternshipListFilters(currentReviewListConfig.key)"
    />
    <div class="app-review-list">
      <AppListCard
        v-for="row in mobileListRows(currentReviewListConfig.key)"
        :key="row.id"
        :title="mobileListTitle(currentReviewListConfig.key, row)"
        :subtitle="mobileListValue(currentReviewListConfig.key, row)"
        :status="row.status"
        :meta="mobileListFacts(currentReviewListConfig.key, row)"
      >
        <template v-if="mobileListActions(currentReviewListConfig.key, row, 'review').length" #actions>
          <AppButton
            v-for="action in mobileListActions(currentReviewListConfig.key, row, 'review')"
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
    <div v-if="!mobileListRows(currentReviewListConfig.key).length" class="mobile-empty">
      {{ currentReviewListConfig.emptyText }}
    </div>
    <InternshipListFooter :list-key="currentReviewListConfig.key" />
  </section>

  <AppSheet
    :model-value="internship.reviewDialog.visible"
    :title="reviewDialogTitle"
    :subtitle="reviewDialogRuleText"
    :close-on-overlay="false"
    @update:model-value="!$event && closeReviewDialog()"
  >
    <section class="app-review-content">
      <section v-if="reviewDialogTargetDetails.length" class="review-target-card">
        <div v-for="item in reviewDialogTargetDetails" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </div>
      </section>
      <AppSegmented
        v-if="internship.reviewDialog.mode === 'review'"
        :model-value="internship.reviewDialog.status"
        :items="internshipReviewStatusOptions(internship.reviewDialog.entity)"
        @update:model-value="setInternshipReviewStatus"
      />
      <label class="app-field">
        <span>{{ reviewDialogReasonLabel }}</span>
        <textarea
          v-model="internship.reviewDialog.reason"
          :maxlength="reviewRuleMax(internship.reviewDialog.entity, internship.reviewDialog.status) || undefined"
          rows="5"
          @input="trimReviewDialogMax"
        />
        <small>
          {{ textLength(internship.reviewDialog.reason) }} / {{ reviewRuleMaxText(internship.reviewDialog.entity, internship.reviewDialog.status) }}
        </small>
      </label>
    </section>
    <template #footer>
      <AppButton variant="secondary" @click="closeReviewDialog">取消</AppButton>
      <AppButton
        v-if="internship.reviewDialog.mode === 'review'"
        variant="secondary"
        :loading="internship.loading"
        @click="saveInternshipDialogReviewDraft"
      >
        保存草稿
      </AppButton>
      <AppButton :loading="internship.loading" @click="confirmReviewDialog">提交审核</AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import MobileFilterSheet from '../../components/MobileFilterSheet.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import AppSegmented from '../../components/ui/AppSegmented.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import InternshipListFooter from './InternshipListFooter.vue';
import { useInternshipContext } from './internshipContext';

const {
  closeReviewDialog,
  confirmReviewDialog,
  currentReviewListConfig,
  handleMobileListAction,
  internship,
  internshipReviewStatusOptions,
  mobileListActions,
  mobileListFacts,
  mobileListRows,
  mobileListSelectFilters,
  mobileListTitle,
  mobileListValue,
  reloadInternshipList,
  resetInternshipListFilters,
  reviewDialogReasonLabel,
  reviewDialogRuleText,
  reviewDialogTargetDetails,
  reviewDialogTitle,
  reviewListTabs,
  reviewRuleMax,
  reviewRuleMaxText,
  saveInternshipDialogReviewDraft,
  setInternshipReviewStatus,
  switchMobileList,
  textLength,
  trimReviewDialogMax,
  updateInternshipListFilter,
} = useInternshipContext();
</script>

<style scoped>
.app-review-list {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}

.app-review-content {
  display: grid;
  gap: 14px;
  padding: 14px 16px;
}
</style>
