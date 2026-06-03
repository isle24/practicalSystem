<template>
  <section class="data-list-panel">
    <div class="data-list-toolbar">
      <div class="data-list-filters">
        <label v-for="filter in filters" :key="filter.key">
          <span>{{ filter.label }}</span>
          <el-select
            v-if="filter.type === 'select'"
            :model-value="filterValues[filter.key]"
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
            :value="filterValues[filter.key]"
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
        <el-button v-if="exportable" :icon="Download" :disabled="!rows.length" @click="emit('export')">
          导出
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
      <el-table-column v-if="actions.length || $slots.actions" label="操作" width="150">
        <template #default="{ row }">
          <template v-if="actions.length">
            <a
              v-for="action in actions"
              :key="action.key"
              class="table-action"
              :class="action.theme"
              :href="isActionDisabled(action, row) ? '#' : actionHref(action, row)"
              :aria-disabled="isActionDisabled(action, row)"
            >
              {{ action.label }}
            </a>
          </template>
          <slot v-else name="actions" :row="row" />
        </template>
      </el-table-column>
    </el-table>

    <div class="data-list-pagination">
      <span>共 {{ pagination.total || 0 }} 条</span>
      <el-pagination
        small
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
import { Download, RefreshCw } from '@lucide/vue';

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
  exportable: {
    type: Boolean,
    default: false,
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

const emit = defineEmits(['export', 'filter-change', 'page-change', 'reset', 'row-action', 'search']);

function updateFilter(key, value) {
  emit('filter-change', { key, value });
}

function columnText(column, row) {
  if (typeof column.formatter === 'function') {
    return column.formatter(row);
  }
  const value = row[column.prop];
  return value === null || value === undefined || value === '' ? '-' : value;
}

function isActionDisabled(action, row) {
  return typeof action.disabled === 'function' ? action.disabled(row) : Boolean(action.disabled);
}

function actionHref(action, row) {
  if (typeof action.href === 'function') {
    return action.href(row);
  }
  return action.href || '#';
}

</script>
