<template>
  <div
    v-if="visible"
    ref="maskRef"
    class="desktop-launcher-mask"
    tabindex="-1"
    @click.self="emit('close')"
    @keyup.esc="emit('close')"
    @keydown.left.prevent="changePage(-1)"
    @keydown.right.prevent="changePage(1)"
    @wheel="handleWheel"
  >
    <section class="desktop-launcher-panel" @click="handlePanelClick">
      <button type="button" class="desktop-launcher-close" title="关闭" @click="emit('close')">
        <X :size="20" />
      </button>
      <label class="desktop-launcher-search">
        <Search :size="20" />
        <input
          ref="searchRef"
          :value="keyword"
          type="search"
          placeholder="搜索应用模块"
          @input="emit('update:keyword', $event.target.value)"
        >
      </label>
      <small v-if="message" class="desktop-launcher-message">{{ message }}</small>
      <div class="desktop-launcher-content">
        <button
          v-if="launcherPages.length > 1"
          type="button"
          class="desktop-launcher-page-arrow previous"
          title="上一页"
          :disabled="currentPage <= 0"
          @click="changePage(-1)"
        >
          <ChevronLeft :size="26" />
        </button>
        <div v-if="activePage" class="desktop-launcher-page">
          <section v-for="group in activePage.groups" :key="group.key" class="desktop-launcher-group">
            <header>
              <strong>{{ group.title }}</strong>
              <span>{{ group.items.length }} 个应用</span>
            </header>
            <div class="desktop-launcher-grid">
              <ShortcutTile
                v-for="module in group.items"
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
        <div v-else class="desktop-launcher-empty">
          <Search :size="28" />
          <strong>没有找到应用</strong>
          <span>请尝试其他名称或关键词</span>
        </div>
        <button
          v-if="launcherPages.length > 1"
          type="button"
          class="desktop-launcher-page-arrow next"
          title="下一页"
          :disabled="currentPage >= launcherPages.length - 1"
          @click="changePage(1)"
        >
          <ChevronRight :size="26" />
        </button>
      </div>
      <nav v-if="launcherPages.length > 1" class="desktop-launcher-pagination" aria-label="启动台分页">
        <button
          v-for="(_, index) in launcherPages"
          :key="index"
          type="button"
          :class="{ active: currentPage === index }"
          :aria-label="`第 ${index + 1} 页`"
          @click="currentPage = index"
        />
      </nav>
      <div v-else class="desktop-launcher-pagination-spacer" />
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, Search, X } from '@lucide/vue';
import ShortcutTile from './ShortcutTile.vue';

const props = defineProps({
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
const maskRef = ref(null);
const searchRef = ref(null);
const currentPage = ref(0);
const pageSize = ref(28);
let lastWheelAt = 0;

const groupDefinitions = [
  {
    key: 'teaching',
    title: '教学业务',
    ids: new Set(['internship', 'practice']),
  },
  {
    key: 'data',
    title: '管理工具',
    ids: new Set(['dataCenter', 'auditCenter', 'resourceCenter', 'config']),
  },
  {
    key: 'personal',
    title: '个人工具',
    ids: new Set(['profile']),
  },
];

const groupedModules = computed(() => {
  if (props.keyword.trim()) {
    return [{ key: 'search', title: '搜索结果', items: props.modules }];
  }

  const groups = groupDefinitions.map(group => ({ ...group, items: [] }));
  const fallback = { key: 'other', title: '其他应用', items: [] };
  props.modules.forEach((module) => {
    const group = groups.find(item => item.ids.has(module.id))
      || (module.type === 'favoriteLink' ? groups.find(item => item.key === 'personal') : null)
      || fallback;
    group.items.push(module);
  });
  return [...groups, fallback].filter(group => group.items.length);
});

const launcherPages = computed(() => paginateGroups(groupedModules.value, pageSize.value));
const activePage = computed(() => launcherPages.value[currentPage.value] || null);

watch(() => [props.visible, props.keyword, props.modules.length, pageSize.value], () => {
  currentPage.value = Math.min(currentPage.value, Math.max(0, launcherPages.value.length - 1));
  if (props.keyword.trim()) {
    currentPage.value = 0;
  }
  if (props.visible) {
    nextTick(() => {
      maskRef.value?.focus();
      searchRef.value?.focus();
    });
  }
});

function paginateGroups(groups, capacity) {
  if (!groups.length) {
    return [];
  }

  const pages = [];
  let page = { key: `page-${pages.length}`, groups: [], count: 0 };
  groups.forEach((group) => {
    let offset = 0;
    while (offset < group.items.length) {
      const remaining = Math.max(1, capacity - page.count);
      const items = group.items.slice(offset, offset + remaining);
      page.groups.push({ key: `${group.key}-${offset}`, title: group.title, items });
      page.count += items.length;
      offset += items.length;
      if (page.count >= capacity && offset < group.items.length) {
        pages.push(page);
        page = { key: `page-${pages.length}`, groups: [], count: 0 };
      } else if (page.count >= capacity) {
        pages.push(page);
        page = { key: `page-${pages.length}`, groups: [], count: 0 };
      }
    }
  });
  if (page.groups.length) {
    pages.push(page);
  }
  return pages;
}

function updatePageSize() {
  const columns = Math.max(4, Math.min(10, Math.floor((window.innerWidth - 180) / 122)));
  const rows = Math.max(2, Math.min(4, Math.floor((window.innerHeight - 250) / 126)));
  pageSize.value = columns * rows;
}

function changePage(offset) {
  currentPage.value = Math.min(
    launcherPages.value.length - 1,
    Math.max(0, currentPage.value + offset),
  );
}

function handleWheel(event) {
  if (launcherPages.value.length <= 1 || Math.abs(event.deltaY) < 24) {
    return;
  }
  const now = Date.now();
  if (now - lastWheelAt < 420) {
    return;
  }
  lastWheelAt = now;
  changePage(event.deltaY > 0 ? 1 : -1);
}

function handlePanelClick(event) {
  const target = event?.target instanceof Element ? event.target : null;
  if (!target) {
    emit('close');
    return;
  }
  if (target.closest('.shortcut-tile, .desktop-launcher-search, .desktop-launcher-close, .desktop-launcher-page-arrow, .desktop-launcher-pagination')) {
    return;
  }
  emit('close');
}

onMounted(() => {
  updatePageSize();
  window.addEventListener('resize', updatePageSize);
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', updatePageSize);
});
</script>
