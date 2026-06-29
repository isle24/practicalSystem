<template>
  <div
    v-if="visible"
    class="desktop-launcher-mask"
    tabindex="-1"
    @click.self="emit('close')"
    @keyup.esc="emit('close')"
  >
    <section class="desktop-launcher-panel" @click="handlePanelClick">
      <button type="button" class="desktop-launcher-close" title="关闭" @click="emit('close')">
        <X :size="22" />
      </button>
      <label class="desktop-launcher-search">
        <Search :size="22" />
        <input
          :value="keyword"
          type="search"
          placeholder="搜索应用模块"
          @input="emit('update:keyword', $event.target.value)"
        >
      </label>
      <small v-if="message" class="desktop-launcher-message">{{ message }}</small>
      <div class="desktop-launcher-grid">
        <article v-for="module in modules" :key="module.id" class="launcher-app">
          <button
            type="button"
            class="launcher-module-main"
            :title="module.scope"
            @click="emit('open', module)"
          >
            <AppIcon class="app-glyph" :icon="module.icon" :icon-url="module.iconUrl" :label="module.name" :color="module.color" :size="24" :backend-url="backendUrl" />
            <strong>{{ module.name }}</strong>
          </button>
          <button
            v-if="module.type !== 'favoriteLink'"
            type="button"
            class="launcher-module-add"
            :class="{ added: isDesktopShortcut(module.id), locked: isDefaultDesktopShortcut(module.id) }"
            :disabled="isDefaultDesktopShortcut(module.id) || loading"
            :title="shortcutTitle(module.id)"
            @click.stop="emit('toggle', module.id)"
          >
            <CheckCircle2 v-if="isDesktopShortcut(module.id)" :size="18" />
            <Plus v-else :size="18" />
          </button>
        </article>
      </div>
    </section>
  </div>
</template>

<script setup>
import { CheckCircle2, Plus, Search, X } from '@lucide/vue';
import AppIcon from './AppIcon.vue';

defineProps({
  visible: { type: Boolean, default: false },
  modules: { type: Array, default: () => [] },
  keyword: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  message: { type: String, default: '' },
  backendUrl: { type: Function, required: true },
  isDesktopShortcut: { type: Function, required: true },
  isDefaultDesktopShortcut: { type: Function, required: true },
  shortcutTitle: { type: Function, required: true },
});

const emit = defineEmits(['close', 'open', 'toggle', 'update:keyword']);

function handlePanelClick(event) {
  const target = event?.target instanceof Element ? event.target : null;
  if (!target) {
    emit('close');
    return;
  }
  if (target.closest('.launcher-app, .desktop-launcher-search, .desktop-launcher-close')) {
    return;
  }
  emit('close');
}
</script>
