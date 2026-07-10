<template>
  <AppSheet
    :model-value="practiceReviewDialog.visible"
    :title="practiceReviewDialogTitle"
    :subtitle="practiceReviewDialogRuleText"
    :close-on-overlay="false"
    @update:model-value="!$event && closePracticeReviewDialog()"
  >
    <section class="practice-review-content">
      <section v-if="practiceReviewTargetDetails.length" class="review-target-card">
        <div v-for="item in practiceReviewTargetDetails" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </div>
      </section>
      <AppSegmented
        v-if="practiceReviewDialog.mode === 'review'"
        :model-value="practiceReviewDialog.status"
        :items="practiceReviewStatusOptions(practiceReviewDialog.module, practiceReviewDialog.entity)"
        @update:model-value="setPracticeReviewStatus"
      />
      <label class="app-field">
        <span>{{ practiceReviewReasonLabel }}</span>
        <textarea
          v-model="practiceReviewDialog.reason"
          :maxlength="practiceRuleMax(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status) || undefined"
          rows="5"
          @input="trimPracticeReviewMax"
        />
        <small>
          {{ textLength(practiceReviewDialog.reason) }} / {{ practiceRuleMaxText(practiceReviewDialog.module, practiceReviewDialog.entity, practiceReviewDialog.status) }}
        </small>
      </label>
    </section>
    <template #footer>
      <AppButton variant="secondary" @click="closePracticeReviewDialog">取消</AppButton>
      <AppButton
        v-if="practiceReviewDialog.mode === 'review'"
        variant="secondary"
        :loading="practiceModule(practiceReviewDialog.module).loading"
        @click="savePracticeReviewDraftFromDialog"
      >
        保存草稿
      </AppButton>
      <AppButton :loading="practiceModule(practiceReviewDialog.module).loading" @click="confirmPracticeReview">提交审核</AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import AppButton from '../../components/ui/AppButton.vue';
import AppSegmented from '../../components/ui/AppSegmented.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import { usePracticeContext } from './practiceContext';

const {
  closePracticeReviewDialog,
  confirmPracticeReview,
  practiceModule,
  practiceReviewDialog,
  practiceReviewDialogRuleText,
  practiceReviewDialogTitle,
  practiceReviewReasonLabel,
  practiceReviewStatusOptions,
  practiceReviewTargetDetails,
  practiceRuleMax,
  practiceRuleMaxText,
  savePracticeReviewDraftFromDialog,
  setPracticeReviewStatus,
  textLength,
  trimPracticeReviewMax,
} = usePracticeContext();
</script>

<style scoped>
.practice-review-content {
  display: grid;
  gap: 14px;
  padding: 14px 16px;
}
</style>
