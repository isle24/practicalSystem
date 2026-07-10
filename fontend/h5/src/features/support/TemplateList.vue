<template>
  <section class="app-support-tools">
    <select v-model="model.filters.category_id" @change="emit('load', 1, false)">
      <option value="">全部分类</option>
      <option v-for="item in model.categories" :key="item.id" :value="item.id">{{ item.name }}</option>
    </select>
    <label>
      <Search :size="17" />
      <input v-model="model.filters.keyword" placeholder="搜索模板" @keyup.enter="emit('load', 1, false)">
    </label>
  </section>

  <AppErrorState v-if="model.message && !model.items.length" :message="model.message" @retry="emit('load', 1, false)" />
  <section v-else-if="model.items.length" class="app-support-list">
    <AppListCard
      v-for="item in model.items"
      :key="item.id"
      :title="item.name"
      :subtitle="item.category_name || '未分类'"
      :meta="[item.description || item.file?.download_name || '暂无说明']"
      :footer-text="`版本 ${item.version || '-'} · 下载 ${item.download_count || 0}`"
    >
      <template #icon><FileText :size="19" /></template>
      <template #actions>
        <AppButton variant="secondary" size="small" @click="emit('download', item)">
          <template #icon><Download :size="15" /></template>
          下载
        </AppButton>
      </template>
    </AppListCard>
  </section>
  <AppEmptyState v-else title="暂无模板" description="学校管理员上传模板后会显示在这里。" />

  <footer v-if="model.items.length" class="app-support-footer">
    <span>共 {{ model.pagination.total || 0 }} 条</span>
    <AppButton v-if="canLoadMore" variant="secondary" size="small" :loading="model.loading" @click="emit('load', model.pagination.page + 1, true)">
      加载更多
    </AppButton>
  </footer>
</template>

<script setup>
import { Download, FileText, Search } from '@lucide/vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppEmptyState from '../../components/ui/AppEmptyState.vue';
import AppErrorState from '../../components/ui/AppErrorState.vue';
import AppListCard from '../../components/ui/AppListCard.vue';

defineProps({
  model: { type: Object, required: true },
  canLoadMore: { type: Boolean, default: false },
});

const emit = defineEmits(['download', 'load']);
</script>

<style scoped>
.app-support-tools {
  display: grid;
  grid-template-columns: 116px minmax(0, 1fr);
  gap: 8px;
  margin-bottom: 12px;
}

.app-support-tools select,
.app-support-tools label {
  height: var(--app-touch-size);
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  color: var(--app-text);
  background: var(--app-surface);
}

.app-support-tools select {
  min-width: 0;
  padding: 0 9px;
}

.app-support-tools label {
  min-width: 0;
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr);
  align-items: center;
  padding: 0 8px;
  color: var(--app-text-secondary);
}

.app-support-tools input {
  min-width: 0;
  height: 100%;
  border: 0;
  outline: 0;
  color: var(--app-text);
  background: transparent;
}

.app-support-list {
  display: grid;
  gap: 10px;
}

.app-support-footer {
  min-height: 52px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  color: var(--app-text-secondary);
  font-size: 12px;
}
</style>
