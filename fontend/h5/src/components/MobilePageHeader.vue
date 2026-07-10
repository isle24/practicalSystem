<template>
  <header class="mobile-app-header" :class="{ 'login-header': !loggedIn }">
    <div class="mobile-app-header-side start">
      <button v-if="showBack" type="button" title="返回" aria-label="返回" @click="emit('back')">
        <ChevronLeft :size="22" />
      </button>
      <span v-else class="mobile-app-brand-mark">实</span>
    </div>
    <div class="mobile-app-title">
      <small>{{ schoolName }}</small>
      <strong>{{ title }}</strong>
    </div>
    <div class="mobile-app-header-side end">
      <button v-if="loggedIn" type="button" class="mobile-message-entry" title="消息中心" aria-label="消息中心" @click="emit('message')">
        <Bell :size="21" />
        <i v-if="unreadCount > 0">{{ unreadCount > 99 ? '99+' : unreadCount }}</i>
      </button>
    </div>
  </header>
</template>

<script setup>
import { Bell, ChevronLeft } from '@lucide/vue';

defineProps({
  loggedIn: { type: Boolean, default: false },
  title: { type: String, default: '' },
  schoolName: { type: String, default: '' },
  showBack: { type: Boolean, default: false },
  unreadCount: { type: Number, default: 0 },
});

const emit = defineEmits(['back', 'message']);
</script>
