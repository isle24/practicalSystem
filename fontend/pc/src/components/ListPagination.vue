<template>
  <div class="list-pagination" :class="attrs.class" :style="attrs.style">
    <span class="list-pagination-size">{{ pageSize }} 条/页</span>
    <ElPagination v-bind="paginationAttrs()" :page-size="pageSize">
      <template v-for="(_, name) in $slots" #[name]="slotProps">
        <slot :name="name" v-bind="slotProps || {}" />
      </template>
    </ElPagination>
  </div>
</template>

<script setup>
import { useAttrs } from 'vue';
import { ElPagination } from 'element-plus';

defineOptions({ inheritAttrs: false });
defineProps({ pageSize: { type: Number, default: 10 } });

const attrs = useAttrs();

function paginationAttrs() {
  return Object.fromEntries(Object.entries(attrs).filter(([key]) => key !== 'class' && key !== 'style'));
}
</script>

<style scoped>
.list-pagination {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px 14px;
  max-width: 100%;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}

.list-pagination-size {
  flex-shrink: 0;
  white-space: nowrap;
}

.list-pagination :deep(.el-pagination) {
  min-width: 0;
  flex-wrap: wrap;
  row-gap: 8px;
}
</style>
