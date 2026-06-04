<template>
  <section class="data-list-panel">
    <div class="data-list-toolbar">
      <div class="data-list-filters">
        <label
          v-for="filter in filters"
          :key="filter.key"
          :class="{ 'filter-active': hasFilterValue(filterValue(filter.key)) }"
        >
          <span>{{ filter.label }}</span>
          <el-select
            v-if="filter.type === 'select'"
            class="filter-select"
            :class="{ 'is-filter-active': hasFilterValue(filterValue(filter.key)) }"
            :model-value="filterValue(filter.key)"
            clearable
            filterable
            :placeholder="filter.placeholder || '全部'"
            @update:model-value="value => updateFilter(filter.key, value)"
          >
            <el-option
              v-for="option in filter.options || []"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </el-select>
          <input
            v-else
            :value="filterValue(filter.key)"
            :placeholder="filter.placeholder || ''"
            @input="event => updateFilter(filter.key, event.target.value)"
            @keyup.enter="emit('search')"
          >
        </label>
      </div>
      <div class="data-list-actions">
        <el-button :icon="RefreshCw" :loading="loading" @click="emit('search')">
          查询
        </el-button>
        <el-button @click="emit('reset')">
          重置
        </el-button>
      </div>
    </div>

    <el-table :data="rows" height="100%" stripe>
      <el-table-column
        v-for="column in columns"
        :key="column.key || column.prop"
        :prop="column.prop"
        :label="column.label"
        :width="column.width"
        :min-width="column.minWidth"
        :fixed="column.fixed"
        :show-overflow-tooltip="column.tooltip !== false"
      >
        <template #default="{ row }">
          <el-tag v-if="column.tag" :type="column.tagType ? column.tagType(row) : 'primary'">
            {{ columnText(column, row) }}
          </el-tag>
          <span v-else>{{ columnText(column, row) }}</span>
        </template>
      </el-table-column>
      <el-table-column v-if="actions.length || $slots.actions" label="操作" width="280" fixed="right">
        <template #default="{ row }">
          <template v-if="actions.length">
            <button
              v-for="action in actions"
              :key="action.key"
              type="button"
              class="table-action"
              :class="action.theme"
              :disabled="isActionDisabled(action, row)"
              @pointerdown.stop.prevent="handleAction($event, action, row)"
              @mousedown.stop.prevent="handleAction($event, action, row)"
              @click.stop.prevent="handleAction($event, action, row)"
            >
              {{ action.label }}
            </button>
          </template>
          <slot v-else name="actions" :row="row" />
        </template>
      </el-table-column>
    </el-table>

    <div class="data-list-pagination">
      <span>共 {{ pagination.total || 0 }} 条</span>
      <el-pagination
        size="small"
        layout="prev, pager, next"
        :current-page="pagination.page || 1"
        :page-size="pagination.page_size || 20"
        :total="pagination.total || 0"
        @current-change="page => emit('page-change', page)"
      />
    </div>
  </section>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { RefreshCw } from '@lucide/vue';

const props = defineProps({
  actions: {
    type: Array,
    default: () => [],
  },
  actionHandler: {
    type: Function,
    default: null,
  },
  columns: {
    type: Array,
    default: () => [],
  },
  filters: {
    type: Array,
    default: () => [],
  },
  filterValues: {
    type: Object,
    default: () => ({}),
  },
  loading: {
    type: Boolean,
    default: false,
  },
  pagination: {
    type: Object,
    default: () => ({ page: 1, page_size: 20, total: 0 }),
  },
  rows: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['filter-change', 'page-change', 'reset', 'row-action', 'search']);
const localFilterValues = reactive({});
let lastActionAt = 0;

watch(
  () => props.filterValues,
  (values = {}) => {
    Object.keys(localFilterValues).forEach((key) => {
      if (!Object.prototype.hasOwnProperty.call(values, key)) {
        delete localFilterValues[key];
      }
    });
    Object.entries(values).forEach(([key, value]) => {
      localFilterValues[key] = value;
    });
  },
  { immediate: true, deep: true },
);

function filterValue(key) {
  return Object.prototype.hasOwnProperty.call(localFilterValues, key)
    ? localFilterValues[key]
    : props.filterValues[key];
}

function updateFilter(key, value) {
  localFilterValues[key] = value ?? '';
  emit('filter-change', { key, value });
}

function hasFilterValue(value) {
  return value !== '' && value !== null && value !== undefined;
}

function columnText(column, row) {
  if (typeof column.formatter === 'function') {
    return column.formatter(row);
  }
  const value = row[column.prop || column.key];
  return value === null || value === undefined || value === '' ? '-' : value;
}

function isActionDisabled(action, row) {
  return typeof action.disabled === 'function' ? action.disabled(row) : Boolean(action.disabled);
}

function handleAction(event, action, row) {
  const now = Date.now();
  if (now - lastActionAt < 80) {
    return;
  }
  lastActionAt = now;
  if (isActionDisabled(action, row)) {
    event.preventDefault();
    return;
  }
  if (props.actionHandler) {
    event.preventDefault();
    props.actionHandler({ action: action.key, row });
    return;
  }
  emit('row-action', { action: action.key, row });
}

</script>
