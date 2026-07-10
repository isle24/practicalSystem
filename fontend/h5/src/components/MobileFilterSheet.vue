<template>
  <section class="mobile-filter-bar">
    <label>
      <Search :size="17" />
      <input
        :value="values.keyword || ''"
        :placeholder="keywordPlaceholder"
        @input="updateFilter('keyword', $event.target.value)"
        @keyup.enter="emit('search')"
      >
    </label>
    <AppButton v-if="hasAdvancedFilters" class="mobile-filter-button" variant="secondary" size="small" @click="visible = true">
      <template #icon><SlidersHorizontal :size="17" /></template>
      <span>筛选</span>
      <i v-if="activeCount > 0">{{ activeCount }}</i>
    </AppButton>
    <AppIconButton class="mobile-search-button" label="查询" variant="secondary" size="small" :disabled="loading" @click="emit('search')">
      <Search :size="18" />
    </AppIconButton>
  </section>

  <AppSheet v-model="visible" title="筛选条件" subtitle="按当前账号的数据范围查询">
    <div class="mobile-filter-fields">
      <label v-for="filter in selectFilters" :key="filter.key" class="app-field">
        <span>{{ filter.label }}</span>
        <select :value="values[filter.key] ?? ''" @change="updateFilter(filter.key, $event.target.value)">
          <option value="">{{ filter.placeholder }}</option>
          <option v-for="option in filter.options || []" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <label v-if="statusOptions.length" class="app-field">
        <span>状态</span>
        <select :value="values[statusKey] ?? ''" @change="updateFilter(statusKey, $event.target.value)">
          <option value="">全部状态</option>
          <option v-for="option in statusOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
    </div>
    <template #footer>
      <AppButton variant="secondary" @click="emit('reset')">重置</AppButton>
      <AppButton :loading="loading" @click="applyFilters">应用筛选</AppButton>
    </template>
  </AppSheet>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Search, SlidersHorizontal } from '@lucide/vue';
import AppButton from './ui/AppButton.vue';
import AppIconButton from './ui/AppIconButton.vue';
import AppSheet from './ui/AppSheet.vue';

const props = defineProps({
  selectFilters: { type: Array, default: () => [] },
  statusOptions: { type: Array, default: () => [] },
  statusKey: { type: String, default: 'status' },
  values: { type: Object, default: () => ({}) },
  keywordPlaceholder: { type: String, default: '输入关键词' },
  loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update-filter', 'search', 'reset']);
const visible = ref(false);
const hasAdvancedFilters = computed(() => props.selectFilters.length > 0 || props.statusOptions.length > 0);
const activeCount = computed(() => {
  const keys = props.selectFilters.map(item => item.key);
  if (props.statusOptions.length) {
    keys.push(props.statusKey);
  }
  return keys.filter(key => props.values[key] !== '' && props.values[key] !== null && props.values[key] !== undefined).length;
});

function updateFilter(key, value) {
  emit('update-filter', { key, value });
}

function applyFilters() {
  visible.value = false;
  emit('search');
}
</script>

<style scoped>
.mobile-filter-bar {
  transition: border-color var(--app-motion-control) var(--app-ease-standard),
    background var(--app-motion-control) var(--app-ease-standard);
}

.mobile-filter-bar > label {
  transition: border-color var(--app-motion-control) var(--app-ease-standard),
    box-shadow var(--app-motion-control) var(--app-ease-standard),
    background var(--app-motion-control) var(--app-ease-standard);
}

.mobile-filter-bar > label:focus-within {
  border-color: color-mix(in srgb, var(--app-primary) 58%, var(--app-line));
  background: var(--app-surface);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--app-primary) 10%, transparent);
}

.mobile-filter-button i {
  min-width: 17px;
  height: 17px;
  border-radius: 999px;
  display: grid;
  place-items: center;
  color: #fff;
  background: var(--app-primary);
  font-size: 10px;
  font-style: normal;
}

.mobile-filter-fields {
  min-height: 0;
  display: grid;
  gap: 12px;
  align-content: start;
  padding: 14px 16px;
}
</style>
