<template>
  <section v-if="isTeacherRole" class="mobile-card form-card">
    <header>
      <GraduationCap :size="20" />
      <strong>成绩录入</strong>
    </header>
    <label>
      <span>学生</span>
      <input
        v-model="internship.filters.pairs.keyword"
        placeholder="搜索学生、学号或任务"
        @keyup.enter="reloadScorePairs"
      >
    </label>
    <label>
      <span>任务绑定</span>
      <select v-model.number="internship.forms.score.pair_id" @change="selectScorePair">
        <option v-for="pair in internship.lists.pairs.items" :key="pair.id" :value="pair.id">
          {{ pair.student_name }} / {{ pair.arrangement_title }}
        </option>
      </select>
    </label>
    <AppButton block variant="secondary" :loading="internship.loading" @click="reloadScorePairs">查询绑定学生</AppButton>
    <label><span>签到成绩</span><input v-model="internship.forms.score.sign_in_score" type="number"></label>
    <label><span>日志成绩</span><input v-model="internship.forms.score.journal_score" type="number"></label>
    <label><span>报告成绩</span><input v-model="internship.forms.score.report_score" type="number"></label>
    <label><span>企业成绩</span><input v-model="internship.forms.score.enterprise_score" type="number"></label>
    <AppButton block :loading="internship.loading" @click="submitScore">保存成绩</AppButton>
  </section>

  <section class="mobile-card">
    <header>
      <GraduationCap :size="20" />
      <strong>任务成绩记录</strong>
    </header>
    <MobileFilterSheet
      :select-filters="mobileListSelectFilters(getMobileListConfig('scores'))"
      :values="internship.filters.scores"
      keyword-placeholder="学生、学号、安排"
      :loading="internship.loading"
      @update-filter="payload => updateInternshipListFilter('scores', payload)"
      @search="reloadInternshipList('scores')"
      @reset="resetInternshipListFilters('scores')"
    />
    <div class="app-score-list">
      <AppListCard
        v-for="row in internship.lists.scores.items"
        :key="row.id"
        :title="row.student_name || row.student_num || '任务成绩'"
        :subtitle="row.arrangement_title || '-'"
        :meta="[`总评 ${row.final_score ?? '-'}`, `指导教师 ${row.teacher_name || '-'}`]"
        clickable
        @open="openTimelineDialog('score', row)"
      />
    </div>
    <div v-if="!internship.lists.scores.items.length" class="mobile-empty">暂无成绩记录</div>
    <InternshipListFooter list-key="scores" />
  </section>

  <section class="mobile-card">
    <header>
      <GraduationCap :size="20" />
      <strong>课程成绩汇总</strong>
    </header>
    <MobileFilterSheet
      :select-filters="mobileListSelectFilters(getMobileListConfig('courseScores'))"
      :values="internship.filters.courseScores"
      keyword-placeholder="学生、学号、课程、任务"
      :loading="internship.loading"
      @update-filter="payload => updateInternshipListFilter('courseScores', payload)"
      @search="reloadInternshipList('courseScores')"
      @reset="resetInternshipListFilters('courseScores')"
    />
    <div class="app-score-list">
      <AppListCard
        v-for="row in internship.lists.courseScores.items"
        :key="`${row.plan_id}-${row.student_id}`"
        :title="joinFact([row.student_name, row.student_num]) || '学生成绩'"
        :subtitle="row.course_name || row.course_code || '-'"
        :meta="mobileListFacts('courseScores', row)"
        :footer-text="row.course_final_score !== null && row.course_final_score !== undefined ? `${row.course_final_score} 分` : '-'"
      />
    </div>
    <div v-if="!internship.lists.courseScores.items.length" class="mobile-empty">暂无课程成绩汇总</div>
    <InternshipListFooter list-key="courseScores" />
  </section>
</template>

<script setup>
import { GraduationCap } from '@lucide/vue';
import MobileFilterSheet from '../../components/MobileFilterSheet.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppListCard from '../../components/ui/AppListCard.vue';
import InternshipListFooter from './InternshipListFooter.vue';
import { useInternshipContext } from './internshipContext';

const {
  getMobileListConfig,
  internship,
  isTeacherRole,
  joinFact,
  mobileListFacts,
  mobileListSelectFilters,
  openTimelineDialog,
  reloadInternshipList,
  reloadScorePairs,
  resetInternshipListFilters,
  selectScorePair,
  submitScore,
  updateInternshipListFilter,
} = useInternshipContext();
</script>

<style scoped>
.app-score-list {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}
</style>
