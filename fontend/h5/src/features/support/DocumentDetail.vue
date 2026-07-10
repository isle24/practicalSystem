<template>
  <AppSheet
    :model-value="visible"
    :title="detail.article?.title || '文档详情'"
    :subtitle="detail.article?.category_name || '未分类'"
    @update:model-value="!$event && emit('close')"
  >
    <section class="app-document-detail">
      <div v-if="detail.article" class="app-document-meta">
        <span>版本 {{ detail.article.version || '-' }}</span>
        <span>浏览 {{ detail.article.view_count || 0 }}</span>
      </div>
      <article v-if="detail.article" class="support-mobile-rich" v-html="detail.article.content" />
      <AppLoadingState v-else-if="detail.loading" />
      <AppErrorState v-else-if="detail.message" :message="detail.message" :retryable="false" />
      <AppEmptyState v-else title="暂无文档内容" />
    </section>
    <template #footer>
      <AppButton block variant="secondary" @click="emit('close')">关闭</AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import AppButton from '../../components/ui/AppButton.vue';
import AppEmptyState from '../../components/ui/AppEmptyState.vue';
import AppErrorState from '../../components/ui/AppErrorState.vue';
import AppLoadingState from '../../components/ui/AppLoadingState.vue';
import AppSheet from '../../components/ui/AppSheet.vue';

defineProps({
  visible: { type: Boolean, default: false },
  detail: { type: Object, required: true },
});

const emit = defineEmits(['close']);
</script>

<style scoped>
.app-document-detail {
  padding: 14px 16px;
}

.app-document-meta {
  display: flex;
  gap: 12px;
  margin-bottom: 10px;
  color: var(--app-text-secondary);
  font-size: 12px;
}
</style>
