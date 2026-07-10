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
    <button v-if="hasAdvancedFilters" type="button" class="mobile-filter-button" @click="visible = true">
      <SlidersHorizontal :size="17" />
      <span>筛选</span>
      <i v-if="activeCount > 0">{{ activeCount }}</i>
    </button>
    <button type="button" class="mobile-search-button" :disabled="loading" title="查询" aria-label="查询" @click="emit('search')">
      <Search :size="18" />
    </button>
  </section>

  <van-popup v-model:show="visible" round position="bottom" safe-area-inset-bottom>
    <section class="mobile-filter-sheet">
      <header>
        <div>
          <strong>筛选条件</strong>
          <small>按当前账号的数据范围查询</small>
        </div>
        <button type="button" title="关闭" aria-label="关闭" @click="visible = false">
          <X :size="19" />
        </button>
      </header>
      <div class="mobile-filter-fields">
        <label v-for="filter in selectFilters" :key="filter.key">
          <span>{{ filter.label }}</span>
          <select :value="values[filter.key] ?? ''" @change="updateFilter(filter.key, $event.target.value)">
            <option value="">{{ filter.placeholder }}</option>
            <option v-for="option in filter.options || []" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
        <label v-if="statusOptions.length">
          <span>状态</span>
          <select :value="values[statusKey] ?? ''" @change="updateFilter(statusKey, $event.target.value)">
            <option value="">全部状态</option>
            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
      </div>
      <footer>
        <button type="button" @click="emit('reset')">重置</button>
        <button type="button" class="primary" :disabled="loading" @click="applyFilters">应用筛选</button>
      </footer>
    </section>
  </van-popup>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Search, SlidersHorizontal, X } from '@lucide/vue';

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
