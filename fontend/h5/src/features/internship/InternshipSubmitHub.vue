<template>
  <section class="student-submit-cards">
    <button
      v-for="item in studentSubmitCards"
      :key="item.key"
      :class="{ active: internship.submitSection === item.key, expired: item.expired }"
      @click="openStudentSubmitSection(item.key)"
    >
      <component :is="item.icon" :size="20" />
      <span>
        <strong>{{ item.title }}</strong>
        <small>{{ item.desc }}</small>
      </span>
      <em>{{ item.meta }}</em>
    </button>
  </section>

  <InternshipFormPage />

  <section v-if="internship.submitSection === 'sign'" class="app-record-section">
    <header class="app-record-section-title">
      <MapPin :size="20" />
      <strong>签到记录</strong>
    </header>
    <div class="app-record-list">
      <AppListCard
        v-for="row in internship.lists.signIns.items"
        :key="row.id"
        :title="row.arrangement_title || '实习签到'"
        :subtitle="joinFact([row.date, row.sign_time])"
        :meta="[row.location || '-']"
        :footer-text="signTypeText(row.sign_type)"
        clickable
        @open="openTimelineDialog('sign_in', row)"
      />
    </div>
    <div v-if="!internship.lists.signIns.items.length" class="mobile-empty">暂无签到记录</div>
    <InternshipListFooter list-key="signIns" />
  </section>

  <section v-if="internship.submitSection === 'journal'" class="app-record-section">
    <header class="app-record-section-title">
      <FileClock :size="20" />
      <strong>日志记录</strong>
    </header>
    <div class="app-record-list">
      <AppListCard
        v-for="row in internship.lists.journals.items"
        :key="row.id"
        :title="row.title"
        :subtitle="row.date || row.created_at || '-'"
        :status="row.status"
      >
        <template #actions>
          <AppButton v-if="canEditStudentWork(row)" variant="quiet" size="small" @click="editStudentWork('journal', row)">修改</AppButton>
          <AppButton variant="quiet" size="small" @click="openTimelineDialog('journal', row)">记录</AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!internship.lists.journals.items.length" class="mobile-empty">暂无日志记录</div>
    <InternshipListFooter list-key="journals" />
  </section>

  <section v-if="internship.submitSection === 'report'" class="app-record-section">
    <header class="app-record-section-title">
      <FileText :size="20" />
      <strong>报告记录</strong>
    </header>
    <div class="app-record-list">
      <AppListCard
        v-for="row in internship.lists.reports.items"
        :key="row.id"
        :title="row.title"
        :subtitle="row.created_at || '-'"
        :status="row.status"
      >
        <template #actions>
          <AppButton v-if="canEditStudentWork(row)" variant="quiet" size="small" @click="editStudentWork('report', row)">修改</AppButton>
          <AppButton variant="quiet" size="small" @click="openTimelineDialog('report', row)">记录</AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!internship.lists.reports.items.length" class="mobile-empty">暂无报告记录</div>
    <InternshipListFooter list-key="reports" />
  </section>

  <section v-if="internship.submitSection === 'safety'" class="app-record-section">
    <header class="app-record-section-title">
      <CheckCircle2 :size="20" />
      <strong>安全承诺记录</strong>
    </header>
    <div class="app-record-list">
      <AppListCard
        v-for="row in internship.lists.safetyLetters.items"
        :key="row.id"
        :title="row.arrangement_title || '学生实习安全承诺书'"
        :subtitle="row.signed_at || row.created_at || '-'"
        :status="row.status"
      >
        <template #actions>
          <AppButton variant="quiet" size="small" @click="openTimelineDialog('safety_letter', row)">记录</AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!internship.lists.safetyLetters.items.length" class="mobile-empty">暂无安全承诺记录</div>
    <InternshipListFooter list-key="safetyLetters" />
  </section>

  <section v-if="internship.submitSection === 'delay'" class="app-record-section">
    <header class="app-record-section-title">
      <FileClock :size="20" />
      <strong>延期记录</strong>
    </header>
    <div class="app-record-list">
      <AppListCard
        v-for="row in internship.lists.delays.items"
        :key="row.id"
        :title="delayConfigText(row.config_key)"
        :subtitle="row.arrangement_title || '-'"
        :status="row.status"
        :meta="[`延期至 ${row.requested_date || '-'}`]"
      >
        <template #actions>
          <AppButton v-if="canEditStudentWork(row)" variant="quiet" size="small" @click="editStudentWork('delay', row)">修改</AppButton>
          <AppButton variant="quiet" size="small" @click="openTimelineDialog('delay', row)">记录</AppButton>
        </template>
      </AppListCard>
    </div>
    <div v-if="!internship.lists.delays.items.length" class="mobile-empty">暂无延期申请</div>
    <InternshipListFooter list-key="delays" />
  </section>
</template>

<script setup>
import { CheckCircle2, FileClock, FileText, MapPin } from '@lucide/vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import InternshipFormPage from './InternshipFormPage.vue';
import InternshipListFooter from './InternshipListFooter.vue';
import { useInternshipContext } from './internshipContext';

const {
  canEditStudentWork,
  delayConfigText,
  editStudentWork,
  internship,
  joinFact,
  openStudentSubmitSection,
  openTimelineDialog,
  signTypeText,
  studentSubmitCards,
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
