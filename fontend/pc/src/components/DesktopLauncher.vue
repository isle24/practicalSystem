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
        <ShortcutTile
          v-for="module in modules"
          :key="module.id"
          :item="module"
          mode="launcher"
          :show-action="module.type !== 'favoriteLink'"
          :added="isDesktopShortcut(module.id)"
          :locked="isDefaultDesktopShortcut(module.id)"
          :disabled="isDefaultDesktopShortcut(module.id) || loading"
          :action-title="shortcutTitle(module.id)"
          :backend-url="backendUrl"
          @open="emit('open', module)"
          @action="emit('toggle', module.id)"
        />
      </div>
    </section>
  </div>
</template>

<script setup>
import { Search, X } from '@lucide/vue';
import ShortcutTile from './ShortcutTile.vue';

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
  if (target.closest('.shortcut-tile, .desktop-launcher-search, .desktop-launcher-close')) {
    return;
  }
  emit('close');
}
</script>
