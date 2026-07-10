<template>
  <section class="internship-page">
    <van-notice-bar
      v-if="internship.message"
      color="#8a5a00"
      background="#fff3d8"
      left-icon="warning-o"
      :text="internship.message"
    />

    <AppSegmented
      class="internship-page-segmented"
      :model-value="internship.panel"
      :items="internshipPanels"
      @update:model-value="switchInternshipPanel"
    />

    <InternshipOverview v-if="internship.panel === 'workbench'" />
    <InternshipTaskList v-else-if="isStudentRole && internship.panel === 'apply'" />
    <InternshipSubmitHub v-else-if="isStudentRole && internship.panel === 'submit'" />
    <InternshipReviewList v-else-if="canReviewInternship && internship.panel === 'review'" />
    <InternshipScorePage v-else-if="internship.panel === 'score'" />
    <InternshipManageList v-else-if="isAdminRole && internship.panel === 'manage'" />
  </section>

  <InternshipTimeline />
</template>

<script>
import AppSegmented from '../../components/ui/AppSegmented.vue';
import InternshipManageList from './InternshipManageList.vue';
import InternshipOverview from './InternshipOverview.vue';
import InternshipReviewList from './InternshipReviewList.vue';
import InternshipScorePage from './InternshipScorePage.vue';
import InternshipSubmitHub from './InternshipSubmitHub.vue';
import InternshipTaskList from './InternshipTaskList.vue';
import InternshipTimeline from './InternshipTimeline.vue';
import { useInternshipContext } from './internshipContext';

export default {
  name: 'InternshipPage',
  components: {
    AppSegmented,
    InternshipManageList,
    InternshipOverview,
    InternshipReviewList,
    InternshipScorePage,
    InternshipSubmitHub,
    InternshipTaskList,
    InternshipTimeline,
  },
  setup() {
    return useInternshipContext();
  },
};
</script>

<style scoped>
.internship-page-segmented {
  margin-bottom: 12px;
}
</style>
