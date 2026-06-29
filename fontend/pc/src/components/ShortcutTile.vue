<template>
  <span
    class="shortcut-tile"
    :class="tileClasses"
    :title="title || item.scope || item.name"
  >
    <component
      :is="tagName"
      :href="href || undefined"
      :type="tagName === 'button' ? 'button' : undefined"
      class="shortcut-tile-main"
      :aria-label="item.name"
      @click.prevent="emit('open', item)"
    >
      <AppIcon
        class="app-glyph"
        :icon="item.icon"
        :icon-url="item.iconUrl"
        :label="item.name"
        :color="item.color"
        :size="iconSize"
        :backend-url="backendUrl"
      />
      <span class="shortcut-tile-label">
        <strong>{{ item.name }}</strong>
        <small v-if="subtitle">{{ subtitle }}</small>
      </span>
    </component>
    <button
      v-if="showAction"
      type="button"
      class="shortcut-tile-action"
      :class="{ added, locked }"
      :disabled="disabled"
      :title="actionTitle"
      @click.stop="emit('action', item)"
    >
      <CheckCircle2 v-if="added" :size="actionIconSize" />
      <Plus v-else :size="actionIconSize" />
    </button>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { CheckCircle2, Plus } from '@lucide/vue';
import AppIcon from './AppIcon.vue';

const props = defineProps({
  item: { type: Object, required: true },
  mode: { type: String, default: 'desktop' },
  href: { type: String, default: '' },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  active: { type: Boolean, default: false },
  showAction: { type: Boolean, default: false },
  added: { type: Boolean, default: false },
  locked: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  actionTitle: { type: String, default: '' },
  backendUrl: { type: Function, required: true },
});

const emit = defineEmits(['open', 'action']);
const tagName = computed(() => (props.href ? 'a' : 'button'));
const iconSize = computed(() => (props.mode === 'launcher' ? 24 : 25));
const actionIconSize = computed(() => (props.mode === 'launcher' ? 18 : 16));
const tileClasses = computed(() => [
  `shortcut-tile-${props.mode}`,
  {
    active: props.active,
    'has-action': props.showAction,
  },
]);
</script>
