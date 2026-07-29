<template>
  <section class="app-record-section">
    <header class="app-record-section-title">
      <ClipboardList :size="20" />
      <strong>我的实习任务</strong>
    </header>
    <div class="app-record-list">
      <AppListCard
        v-for="row in internship.options.arrangements"
        :key="row.id"
        :title="row.title"
        :subtitle="joinFact([row.course_name, row.teacher_name])"
        :meta="[dateRangeText(row.start_date, row.end_date)].filter(Boolean)"
        :footer-text="row.student_count ? `${row.student_count} 人` : ''"
        clickable
        @open="openTimelineDialog('arrangement', row)"
      >
        <template #actions>
          <AppButton variant="quiet" size="small" @click="openTimelineDialog('arrangement', row)">记录</AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!internship.options.arrangements.length" class="mobile-empty">暂无绑定任务</div>
  </section>

  <section class="mobile-card form-card">
    <header>
      <ClipboardList :size="20" />
      <strong>提交实习方式申请</strong>
    </header>
    <label>
      <span>实习任务</span>
      <select v-model.number="internship.forms.application.arrangement_id">
        <option v-for="item in internship.options.arrangements" :key="item.id" :value="item.id">
          {{ item.title }}
        </option>
      </select>
    </label>
    <label>
      <span>申请类型</span>
      <select v-model="internship.forms.application.type">
        <option value="centralized">集中实习</option>
        <option value="distributed">分散实习</option>
        <option value="autonomous">自主实习</option>
      </select>
    </label>
    <label>
      <span>申请说明</span>
      <textarea v-model="internship.forms.application.remark" rows="4" />
    </label>
    <AppActionBar>
      <AppButton variant="secondary" :loading="internship.loading" @click="submitApplication('draft')">保存草稿</AppButton>
      <AppButton :loading="internship.loading" @click="submitApplication('wait')">提交审核</AppButton>
    </AppActionBar>
  </section>

  <section class="app-record-section">
    <header class="app-record-section-title">
      <FileClock :size="20" />
      <strong>历史申请记录</strong>
    </header>
    <div class="app-record-list">
      <AppListCard
        v-for="row in internship.lists.applications.items"
        :key="row.id"
        :title="row.arrangement_title || '实习方式申请'"
        :subtitle="row.student_name || ''"
        :status="row.status"
        :meta="[`教师：${statusText(row.teacher_status)}`, `管理员：${statusText(row.admin_status)}`]"
      >
        <template #actions>
          <AppButton variant="quiet" size="small" @click="openTimelineDialog('application', row)">记录</AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!internship.lists.applications.items.length" class="mobile-empty">暂无申请记录</div>
    <div class="mobile-list-footer">
      <span>共 {{ internship.lists.applications.pagination.total || 0 }} 条</span>
      <AppButton
        v-if="canLoadMore('applications')"
        variant="secondary"
        size="small"
        :loading="internship.loading"
        @click="loadMoreInternshipList('applications')"
      >
        加载更多
      </AppButton>
    </div>
  </section>
</template>

<script setup>
import { ClipboardList, FileClock } from '@lucide/vue';
import AppActionBar from '../../components/ui/AppActionBar.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import { useInternshipContext } from './internshipContext';

const {
  canLoadMore,
  dateRangeText,
  internship,
  joinFact,
  loadMoreInternshipList,
  openTimelineDialog,
  statusText,
  submitApplication,
} = useInternshipContext();
</script>

<style scoped>
.app-record-section {
  margin-bottom: 12px;
}

.app-record-section-title {
  min-height: 40px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 2px 2px 8px;
}

.app-record-section-title svg {
  color: var(--app-primary);
}

.app-record-list {
  display: grid;
  gap: 10px;
}
</style>
