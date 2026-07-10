<template>
  <section class="summary-band internship-summary">
    <div v-for="item in internshipSummaries" :key="item.name">
      <strong>{{ item.value }}</strong>
      <span>{{ item.name }}</span>
    </div>
  </section>

  <section class="mobile-card">
    <header>
      <BriefcaseBusiness :size="20" />
      <strong>当前任务</strong>
    </header>
    <van-cell
      v-for="item in internshipWorkbenchCells"
      :key="item.title"
      :title="item.title"
      :label="item.label"
      :value="item.value"
    />
  </section>
</template>

<script setup>
import { BriefcaseBusiness } from '@lucide/vue';
import { useInternshipContext } from './internshipContext';

const { internshipSummaries, internshipWorkbenchCells } = useInternshipContext();
</script>
