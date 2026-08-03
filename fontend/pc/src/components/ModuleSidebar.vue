<template>
  <aside class="module-sidebar" :class="{ 'is-collapsed': collapsed }">
    <header class="module-sidebar-head">
      <p>{{ label }}</p>
      <button
        type="button"
        :title="collapsed ? '展开侧栏' : '收起侧栏'"
        :aria-label="collapsed ? '展开侧栏' : '收起侧栏'"
        @click="toggleCollapsed"
      >
        <PanelLeftOpen v-if="collapsed" :size="17" />
        <PanelLeftClose v-else :size="17" />
      </button>
    </header>
    <nav>
      <a
        v-for="item in items"
        :key="item.key"
        :href="item.href || '#'"
        class="side-item"
        :class="{ active: activeKey === item.key }"
        :title="collapsed ? item.name : ''"
        :aria-label="item.name"
        @click.prevent.stop="emit('select', item.key)"
      >
        <component
          :is="item.icon || LayoutGrid"
          class="side-item-icon"
          :size="17"
          :stroke-width="1.9"
          aria-hidden="true"
        />
        <span class="side-item-label">{{ item.name }}</span>
      </a>
    </nav>
  </aside>
</template>

<script setup>
import { ref, watch } from 'vue';
import { LayoutGrid, PanelLeftClose, PanelLeftOpen } from '@lucide/vue';

const props = defineProps({
  items: { type: Array, default: () => [] },
  activeKey: { type: String, default: '' },
  storageKey: { type: String, default: '' },
  label: { type: String, default: '模块' },
});

const emit = defineEmits(['select']);
const collapsed = ref(readCollapsed());

watch(
  () => props.storageKey,
  () => {
    collapsed.value = readCollapsed();
  },
);

/** 切换并保存侧栏折叠状态 */
function toggleCollapsed() {
  collapsed.value = !collapsed.value;
  if (props.storageKey) {
    localStorage.setItem(props.storageKey, collapsed.value ? '1' : '0');
  }
}

/** 读取侧栏折叠状态 */
function readCollapsed() {
  return props.storageKey ? localStorage.getItem(props.storageKey) === '1' : false;
}
</script>
