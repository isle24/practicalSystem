<template>
  <section class="module-collection">
    <header>
      <div>
        <h1>{{ title }}</h1>
        <p>{{ description }}</p>
      </div>
      <span>{{ items.length }} 个应用</span>
    </header>
    <div class="module-collection-grid">
      <ShortcutTile
        v-for="item in items"
        :key="item.id"
        :item="item"
        mode="collection"
        :draggable="true"
        :show-action="showActions"
        :added="isDesktopShortcut(item.id)"
        :locked="isDefaultDesktopShortcut(item.id)"
        :disabled="loading || isDefaultDesktopShortcut(item.id)"
        :action-title="shortcutTitle(item.id)"
        :backend-url="backendUrl"
        @open="emit('open', item)"
        @action="emit('toggle', item.id)"
        @drag-module="emit('drag-module', $event)"
      />
    </div>
  </section>
</template>

<script setup>
import ShortcutTile from './ShortcutTile.vue';

defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  backendUrl: { type: Function, required: true },
  showActions: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  isDesktopShortcut: { type: Function, default: () => false },
  isDefaultDesktopShortcut: { type: Function, default: () => false },
  shortcutTitle: { type: Function, default: () => '' },
});

const emit = defineEmits(['open', 'toggle', 'drag-module']);
</script>
