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
    <van-tabbar-item v-if="trainingVisible || labVisible" name="practice">
      <template #icon><Workflow :size="21" /></template>
      实验实训
    </van-tabbar-item>
    <van-tabbar-item name="mine">
      <template #icon><UserRound :size="21" /></template>
      我的
    </van-tabbar-item>
  </van-tabbar>
</template>

<script setup>
import { BriefcaseBusiness, Home, UserRound, Workflow } from '@lucide/vue';

defineProps({
  modelValue: { type: String, default: 'home' },
  internshipVisible: { type: Boolean, default: false },
  trainingVisible: { type: Boolean, default: false },
  labVisible: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>

<style scoped>
.mobile-bottom-nav :deep(.van-tabbar-item) {
  transition: color var(--app-motion-tab) var(--app-ease-standard),
    background var(--app-motion-tab) var(--app-ease-standard);
}

.mobile-bottom-nav :deep(.van-tabbar-item__icon) {
  transform: translateY(0) scale(1);
  transition: transform var(--app-motion-tab) var(--app-ease-enter);
}

.mobile-bottom-nav :deep(.van-tabbar-item--active .van-tabbar-item__icon) {
  transform: translateY(-1px) scale(1.06);
}

.mobile-bottom-nav :deep(.van-tabbar-item:active .van-tabbar-item__icon) {
  transform: scale(.92);
}
</style>
