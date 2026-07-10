<template>
  <header class="mobile-app-header" :class="{ 'login-header': !loggedIn }">
    <div class="mobile-app-header-side start">
      <AppIconButton v-if="showBack" label="返回" @click="emit('back')">
        <ChevronLeft :size="22" />
      </AppIconButton>
      <span v-else class="mobile-app-brand-mark">实</span>
    </div>
    <div class="mobile-app-title">
      <small>{{ schoolName }}</small>
      <strong>{{ title }}</strong>
    </div>
    <div class="mobile-app-header-side end">
      <AppIconButton
        v-if="loggedIn"
        class="mobile-message-entry"
        label="消息中心"
        :badge="unreadBadge"
        @click="emit('message')"
      >
        <Bell :size="21" />
      </AppIconButton>
    </div>
  </header>
</template>

<script setup>
import { computed } from 'vue';
import { Bell, ChevronLeft } from '@lucide/vue';
import AppIconButton from './ui/AppIconButton.vue';

const props = defineProps({
  loggedIn: { type: Boolean, default: false },
  title: { type: String, default: '' },
  schoolName: { type: String, default: '' },
  showBack: { type: Boolean, default: false },
  unreadCount: { type: Number, default: 0 },
});

const emit = defineEmits(['back', 'message']);
const unreadBadge = computed(() => {
  if (props.unreadCount <= 0) {
    return '';
  }
  return props.unreadCount > 99 ? '99+' : props.unreadCount;
});
</script>
