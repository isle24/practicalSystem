<template>
  <main class="mobile-shell" :class="{ 'is-authenticated': loggedIn }">
    <MobilePageHeader
      :logged-in="loggedIn"
      :title="title"
      :school-name="schoolName"
      :show-back="showBack"
      :unread-count="unreadCount"
      @back="emit('back')"
      @message="emit('message')"
    />

    <van-notice-bar
      v-if="error"
      color="#8a5a00"
      background="#fff3d8"
      left-icon="warning-o"
      :text="error"
    />

    <van-pull-refresh
      :model-value="refreshing"
      :disabled="!loggedIn"
      @update:model-value="emit('update:refreshing', $event)"
      @refresh="emit('refresh')"
    >
      <AppPageTransition :page-key="pageKey" :direction="transitionDirection">
        <slot />
      </AppPageTransition>
    </van-pull-refresh>

    <MobileBottomNav
      v-if="loggedIn"
      :model-value="activeTab"
      :internship-visible="internshipVisible"
      :training-visible="trainingVisible"
      :lab-visible="labVisible"
      @update:model-value="emit('update:activeTab', $event)"
    />

    <slot name="overlays" />
  </main>
</template>

<script setup>
import MobileBottomNav from '../components/MobileBottomNav.vue';
import MobilePageHeader from '../components/MobilePageHeader.vue';
import AppPageTransition from '../components/ui/AppPageTransition.vue';

defineProps({
  loggedIn: { type: Boolean, default: false },
  title: { type: String, default: '' },
  schoolName: { type: String, default: '' },
  showBack: { type: Boolean, default: false },
  unreadCount: { type: Number, default: 0 },
  error: { type: String, default: '' },
  refreshing: { type: Boolean, default: false },
  activeTab: { type: String, default: 'home' },
  pageKey: { type: [String, Number], default: 'home' },
  transitionDirection: { type: String, default: 'tab' },
  internshipVisible: { type: Boolean, default: false },
  trainingVisible: { type: Boolean, default: false },
  labVisible: { type: Boolean, default: false },
});

const emit = defineEmits([
  'back',
  'message',
  'refresh',
  'update:activeTab',
  'update:refreshing',
]);
</script>

<style scoped>
.mobile-shell :deep(.app-page-transition) {
  min-height: calc(100dvh - var(--app-header-height) - var(--app-tabbar-height) - env(safe-area-inset-top) - env(safe-area-inset-bottom));
}

.mobile-shell:not(.is-authenticated) :deep(.app-page-transition) {
  min-height: calc(100dvh - var(--app-header-height) - env(safe-area-inset-top));
}
</style>
