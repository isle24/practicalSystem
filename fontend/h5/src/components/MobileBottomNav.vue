<template>
  <van-tabbar
    :model-value="modelValue"
    class="mobile-bottom-nav"
    safe-area-inset-bottom
    @update:model-value="emit('update:modelValue', $event)"
  >
    <van-tabbar-item name="home">
      <template #icon><Home :size="21" /></template>
      首页
    </van-tabbar-item>
    <van-tabbar-item v-if="internshipVisible" name="internship">
      <template #icon><BriefcaseBusiness :size="21" /></template>
      实习
    </van-tabbar-item>
    <van-tabbar-item v-if="trainingVisible" name="training">
      <template #icon><Workflow :size="21" /></template>
      实训
    </van-tabbar-item>
    <van-tabbar-item v-if="labVisible" name="lab">
      <template #icon><FlaskConical :size="21" /></template>
      实验
    </van-tabbar-item>
    <van-tabbar-item name="mine">
      <template #icon><UserRound :size="21" /></template>
      我的
    </van-tabbar-item>
  </van-tabbar>
</template>

<script setup>
import { BriefcaseBusiness, FlaskConical, Home, UserRound, Workflow } from '@lucide/vue';

defineProps({
  modelValue: { type: String, default: 'home' },
  internshipVisible: { type: Boolean, default: false },
  trainingVisible: { type: Boolean, default: false },
  labVisible: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>
