<template>
  <div class="mobile-list-footer">
    <span>共 {{ internship.lists[listKey].pagination.total || 0 }} 条</span>
    <AppButton
      v-if="canLoadMore(listKey)"
      variant="secondary"
      size="small"
      :loading="internship.loading"
      @click="loadMoreInternshipList(listKey)"
    >
      加载更多
    </AppButton>
  </div>
</template>

<script setup>
import AppButton from '../../components/ui/AppButton.vue';
import { useInternshipContext } from './internshipContext';

defineProps({
  listKey: { type: String, required: true },
});

const { canLoadMore, internship, loadMoreInternshipList } = useInternshipContext();
</script>
