<template>
  <AppSheet
    :model-value="internship.timelineDialog.visible"
    :title="internship.timelineDialog.title"
    :subtitle="internship.timelineDialog.subtitle"
    @update:model-value="!$event && closeTimelineDialog()"
  >
    <div class="timeline-list app-timeline-content">
      <div v-if="internship.timelineDialog.loading" class="timeline-empty">正在读取流程记录...</div>
      <template v-else-if="internshipTimelineCycles.length">
        <section
          v-for="(cycle, index) in internshipTimelineCycles"
          :key="timelineCycleKey(cycle, index)"
          class="timeline-cycle"
        >
          <span />
          <div>
            <header class="timeline-node-head">
              <strong>{{ timelineCycleTitle(cycle) }}</strong>
              <small>{{ timelineCycleTime(cycle) }}</small>
            </header>
            <p>{{ timelineCycleContent(cycle) }}</p>
            <div v-if="cycle.branches?.length" class="timeline-branches">
              <article
                v-for="(branch, branchIndex) in cycle.branches"
                :key="timelineBranchKey(branch, branchIndex)"
                class="timeline-branch"
                :class="{ reopen: isModifyAfterAcceptBranch(branch) }"
              >
                <span />
                <div>
                  <header class="timeline-node-head">
                    <strong>{{ timelineBranchTitle(branch) }}</strong>
                    <small>{{ timelineBranchTime(branch) }}</small>
                  </header>
                  <p v-if="timelineBranchContent(branch)">{{ timelineBranchContent(branch) }}</p>
                  <p v-for="review in timelineBranchReviews(branch)" :key="review.id" class="timeline-review">
                    <span>状态：{{ statusText(review.status) }}</span>
                    <span>审核意见：{{ review.opinion || '-' }}</span>
                    <span v-if="review.score !== null && review.score !== undefined">评分：{{ review.score }}</span>
                  </p>
                </div>
              </article>
            </div>
          </div>
        </section>
      </template>
      <div v-else class="timeline-empty">{{ internship.timelineDialog.message || '暂无流程记录' }}</div>
    </div>
    <template #footer>
      <AppButton block variant="secondary" @click="closeTimelineDialog">关闭</AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import AppButton from '../../components/ui/AppButton.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import { useInternshipContext } from './internshipContext';

const {
  closeTimelineDialog,
  internship,
  internshipTimelineCycles,
  isModifyAfterAcceptBranch,
  statusText,
  timelineBranchContent,
  timelineBranchKey,
  timelineBranchReviews,
  timelineBranchTime,
  timelineBranchTitle,
  timelineCycleContent,
  timelineCycleKey,
  timelineCycleTime,
  timelineCycleTitle,
} = useInternshipContext();
</script>

<style scoped>
.app-timeline-content {
  padding: 14px 16px;
}
</style>
