<template>
  <AppSheet
    :model-value="Boolean(moduleState.archive.check) || moduleState.archive.detailVisible"
    :title="moduleState.archive.detailVisible ? '归档版本详情' : '归档检查'"
    :subtitle="archiveTitle"
    @update:model-value="close"
  >
    <section class="practice-archive-content">
      <label v-if="!moduleState.archive.detailVisible" class="app-field">
        <span>开课任务</span>
        <select v-model.number="moduleState.archive.plan_id" @change="loadPracticeArchiveCheck(moduleType)">
          <option v-for="plan in practiceArchivePlans(moduleType)" :key="plan.id" :value="plan.id">
            {{ plan.title || plan.course_name }}
          </option>
        </select>
      </label>
      <div class="practice-archive-status">
        <strong>{{ moduleState.archive.check?.ready || moduleState.archive.detailVisible ? '材料归档清单' : '材料尚未齐全' }}</strong>
        <small v-if="moduleState.archive.check">
          缺少 {{ moduleState.archive.check.missing_items?.length || 0 }} 项，待处理 {{ moduleState.archive.check.pending_items?.length || 0 }} 项
        </small>
      </div>
      <div class="practice-archive-items">
        <article v-for="item in practiceArchiveItems(moduleType)" :key="item.key" :class="`is-${item.status}`">
          <div>
            <strong>{{ item.name }}</strong>
            <small>{{ item.count || 0 }} / {{ item.required_count || 0 }}</small>
          </div>
          <span>{{ practiceArchiveStatusText(item.status) }}</span>
        </article>
      </div>
    </section>
    <template v-if="!moduleState.archive.detailVisible" #footer>
      <AppButton variant="secondary" @click="closePracticeArchiveCheck(moduleType)">返回</AppButton>
      <AppButton
        v-if="canCreatePracticeArchive(moduleType)"
        :disabled="!moduleState.archive.check?.ready"
        :loading="moduleState.loading"
        @click="confirmCreatePracticeArchive(moduleType)"
      >
        正式归档
      </AppButton>
    </template>
    <template v-else #footer>
      <AppButton variant="secondary" @click="closePracticeArchiveDetail(moduleType)">返回</AppButton>
      <AppButton :loading="moduleState.loading" @click="downloadPracticeArchive(moduleType)">下载归档包</AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import { computed } from 'vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import { usePracticeContext } from './practiceContext';

const props = defineProps({
  moduleType: { type: String, required: true },
});

const {
  closePracticeArchiveCheck,
  closePracticeArchiveDetail,
  canCreatePracticeArchive,
  confirmCreatePracticeArchive,
  downloadPracticeArchive,
  loadPracticeArchiveCheck,
  practiceArchiveItems,
  practiceArchivePlans,
  practiceArchiveStatusText,
  practiceModule,
} = usePracticeContext();

const moduleState = computed(() => practiceModule(props.moduleType));
const archiveTitle = computed(() => {
  const archive = moduleState.value.archive;
  return archive.detail?.plan_title || archive.check?.plan?.title || archive.check?.plan?.course_name || '';
});

function close() {
  if (moduleState.value.archive.detailVisible) {
    closePracticeArchiveDetail(props.moduleType);
  } else {
    closePracticeArchiveCheck(props.moduleType);
  }
}
</script>

<style scoped>
.practice-archive-content {
  display: grid;
  gap: 14px;
  padding: 14px 16px 18px;
}

.practice-archive-status {
  display: grid;
  gap: 4px;
  padding: 12px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  background: var(--app-surface-muted);
}

.practice-archive-status small {
  color: var(--app-text-secondary);
}

.practice-archive-items {
  display: grid;
  gap: 9px;
}

.practice-archive-items article {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px;
  border: 1px solid var(--app-line);
  border-left-width: 3px;
  border-radius: var(--app-radius);
  background: var(--app-surface);
}

.practice-archive-items article > div {
  min-width: 0;
  display: grid;
  gap: 3px;
}

.practice-archive-items article strong {
  font-size: 14px;
}

.practice-archive-items article small {
  color: var(--app-text-secondary);
}

.practice-archive-items article > span {
  flex: 0 0 auto;
  border-radius: 5px;
  padding: 3px 7px;
  font-size: 12px;
}

.practice-archive-items .is-accepted { border-left-color: #2f8f5b; }
.practice-archive-items .is-accepted > span { color: #236f47; background: #dff3e7; }
.practice-archive-items .is-pending { border-left-color: #c28b18; }
.practice-archive-items .is-pending > span { color: #865d08; background: #f8eac4; }
.practice-archive-items .is-modify,
.practice-archive-items .is-missing { border-left-color: #c34b51; }
.practice-archive-items .is-modify > span,
.practice-archive-items .is-missing > span { color: #a1373e; background: #f8dfe1; }
</style>
