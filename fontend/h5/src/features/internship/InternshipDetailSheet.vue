<template>
  <AppSheet
    :model-value="internship.detailSheet.visible"
    :title="internship.detailSheet.title"
    :subtitle="internship.detailSheet.subtitle"
    @update:model-value="!$event && closeMobileListDetail()"
  >
    <AppLoadingState v-if="internship.detailSheet.loading" :lines="6" />
    <AppErrorState
      v-else-if="internship.detailSheet.message"
      :message="internship.detailSheet.message"
      :retryable="false"
    />
    <template v-else>
      <AppScrollTabs
        v-if="internship.detailSheet.sections.length > 1"
        v-model="activeSection"
        class="internship-detail-tabs"
        :items="sectionTabs"
      />

      <section v-if="currentSection" class="internship-mobile-detail">
        <div v-if="currentSection.items?.length" class="internship-detail-grid">
          <article v-for="item in currentSection.items" :key="item.label">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </article>
        </div>

        <div v-if="currentSection.blocks?.length" class="internship-detail-blocks">
          <article v-for="block in currentSection.blocks" :key="block.label">
            <span>{{ block.label }}</span>
            <p>{{ block.value }}</p>
          </article>
        </div>

        <div v-if="currentSection.cards?.length" class="internship-detail-cards">
          <AppListCard
            v-for="card in currentSection.cards"
            :key="card.key"
            :title="card.title"
            :subtitle="card.subtitle"
            :status="card.status"
            :meta="card.facts || []"
          >
            <template v-if="card.action" #actions>
              <AppButton variant="quiet" size="small" @click="handleMobileDetailAction(card.action)">
                {{ card.action.label }}
              </AppButton>
            </template>
          </AppListCard>
        </div>

        <AppEmptyState
          v-if="!currentSection.items?.length && !currentSection.blocks?.length && !currentSection.cards?.length"
          title="暂无详情"
        />
      </section>
    </template>

    <template #footer>
      <AppButton variant="secondary" @click="closeMobileListDetail">关闭</AppButton>
      <AppButton
        v-if="internship.detailSheet.primaryAction"
        :loading="internship.loading"
        @click="handleMobileDetailAction(internship.detailSheet.primaryAction)"
      >
        {{ internship.detailSheet.primaryAction.label }}
      </AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppEmptyState from '../../components/ui/AppEmptyState.vue';
import AppErrorState from '../../components/ui/AppErrorState.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import AppLoadingState from '../../components/ui/AppLoadingState.vue';
import AppScrollTabs from '../../components/ui/AppScrollTabs.vue';
import AppSheet from '../../components/ui/AppSheet.vue';
import { useInternshipContext } from './internshipContext';

const {
  closeMobileListDetail,
  handleMobileDetailAction,
  internship,
} = useInternshipContext();

const activeSection = ref('');
const sectionTabs = computed(() => internship.detailSheet.sections.map(section => ({
  key: section.key,
  name: section.name,
  count: section.count,
})));
const currentSection = computed(() => (
  internship.detailSheet.sections.find(section => section.key === activeSection.value)
  || internship.detailSheet.sections[0]
  || null
));

watch(
  () => [internship.detailSheet.visible, internship.detailSheet.type, internship.detailSheet.sections],
  () => {
    activeSection.value = internship.detailSheet.sections[0]?.key || '';
  },
  { deep: true },
);
</script>

<style scoped>
.internship-detail-tabs {
  margin: 0;
  padding: 0 16px;
  border-bottom: 1px solid var(--app-line);
}

.internship-detail-tabs :deep(button) {
  min-width: auto;
  min-height: 44px;
  border: 0;
  border-bottom: 2px solid transparent;
  border-radius: 0;
  padding: 0 5px;
  background: transparent;
}

.internship-detail-tabs :deep(button.active) {
  border-bottom-color: var(--app-primary);
  background: transparent;
}

.internship-mobile-detail {
  display: grid;
  gap: 14px;
  padding: 16px;
}

.internship-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  overflow: hidden;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  background: var(--app-line);
}

.internship-detail-grid article {
  min-width: 0;
  display: grid;
  align-content: start;
  gap: 5px;
  padding: 11px;
  background: var(--app-surface);
}

.internship-detail-grid span,
.internship-detail-blocks span {
  color: var(--app-text-secondary);
  font-size: 12px;
  line-height: 1.4;
}

.internship-detail-grid strong {
  overflow-wrap: anywhere;
  font-size: 14px;
  line-height: 1.5;
}

.internship-detail-blocks,
.internship-detail-cards {
  display: grid;
  gap: 10px;
}

.internship-detail-blocks article {
  display: grid;
  gap: 6px;
  padding: 12px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  background: var(--app-surface);
}

.internship-detail-blocks p {
  margin: 0;
  overflow-wrap: anywhere;
  color: var(--app-text);
  font-size: 14px;
  line-height: 1.7;
  white-space: pre-wrap;
}

@media (max-width: 360px) {
  .internship-detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
