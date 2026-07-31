<template>
  <section class="data-list-panel">
    <div class="data-list-toolbar">
      <div
        ref="filtersRef"
        class="data-list-filters"
        :class="{ 'is-collapsed': filterCollapsible && !filtersExpanded }"
      >
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
        <div ref="columnMenuRef" class="data-list-column-selector">
          <button type="button" class="el-button" @click="toggleColumnMenu">
            <Columns3 :size="15" />
            <span>显示字段</span>
          </button>
        </div>
        <Teleport to="body">
          <div
            v-if="columnMenuOpen"
            ref="columnMenuPanelRef"
            class="data-list-column-menu"
            :style="columnMenuStyle"
          >
            <header>
              <strong>显示字段</strong>
              <el-button link type="primary" @click="resetVisibleColumns">恢复默认</el-button>
            </header>
            <label
              v-for="column in selectableColumns"
              :key="columnKey(column)"
            >
              <input
                type="checkbox"
                :checked="isColumnVisible(column)"
                :disabled="isRequiredColumn(column)"
                @change="event => setColumnVisible(column, event.target.checked)"
              >
              <span>{{ column.label }}</span>
            </label>
          </div>
        </Teleport>
        <el-button
          v-if="filterCollapsible"
          text
          :icon="filtersExpanded ? ChevronUp : ChevronDown"
          @click="filtersExpanded = !filtersExpanded"
        >
          {{ filtersExpanded ? '收起条件' : '显示更多' }}
        </el-button>
        <el-button :icon="RefreshCw" :loading="loading" @click="emit('search')">
          查询
        </el-button>
        <el-button @click="emit('reset')">
          重置
        </el-button>
      </div>
    </div>

    <el-table :data="rows" height="100%" size="small" stripe v-loading="loading">
      <el-table-column
        v-for="column in visibleColumns"
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
      <el-table-column v-if="actions.length || $slots.actions" label="操作" :width="actionColumnWidth" fixed="right">
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
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { ChevronDown, ChevronUp, Columns3, RefreshCw } from '@lucide/vue';

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
  storageKey: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['filter-change', 'page-change', 'reset', 'row-action', 'search']);
const localFilterValues = reactive({});
const filtersRef = ref(null);
const columnMenuRef = ref(null);
const columnMenuPanelRef = ref(null);
const filtersExpanded = ref(false);
const filterCollapsible = ref(false);
const columnMenuOpen = ref(false);
const columnMenuPosition = reactive({ top: 0, left: 0 });
const visibleColumnKeys = ref([]);
let filterResizeObserver = null;
let lastActionAt = 0;
const actionColumnWidth = computed(() => {
  if (!props.actions.length) {
    return 280;
  }
  return Math.min(320, Math.max(128, props.actions.length * 72 + 20));
});
const selectableColumns = computed(() => props.columns.filter(column => column.label));
const visibleColumns = computed(() => props.columns.filter(column => isColumnVisible(column)));
const columnMenuStyle = computed(() => ({
  top: `${columnMenuPosition.top}px`,
  left: `${columnMenuPosition.left}px`,
}));

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

watch(
  () => props.filters,
  async () => {
    filtersExpanded.value = false;
    await nextTick();
    updateFilterOverflow();
  },
  { deep: true },
);

watch(
  [() => props.columns, () => props.storageKey],
  () => loadVisibleColumns(),
  { immediate: true, deep: true },
);

onMounted(() => {
  updateFilterOverflow();
  document.addEventListener('pointerdown', closeColumnMenuOutside);
  if (typeof ResizeObserver !== 'undefined' && filtersRef.value) {
    filterResizeObserver = new ResizeObserver(updateFilterOverflow);
    filterResizeObserver.observe(filtersRef.value);
  }
});

onBeforeUnmount(() => {
  filterResizeObserver?.disconnect();
  document.removeEventListener('pointerdown', closeColumnMenuOutside);
});

function updateFilterOverflow() {
  const element = filtersRef.value;
  if (!element || props.filters.length < 2) {
    filterCollapsible.value = false;
    return;
  }
  const wasExpanded = filtersExpanded.value;
  element.classList.add('is-measuring');
  filterCollapsible.value = element.scrollHeight > 52;
  element.classList.remove('is-measuring');
  if (!filterCollapsible.value && wasExpanded) {
    filtersExpanded.value = false;
  }
}

/** 点击列表外部时关闭字段菜单 */
function closeColumnMenuOutside(event) {
  if (columnMenuOpen.value
    && !columnMenuRef.value?.contains(event.target)
    && !columnMenuPanelRef.value?.contains(event.target)) {
    columnMenuOpen.value = false;
  }
}

/** 切换字段菜单并计算视口位置 */
async function toggleColumnMenu() {
  columnMenuOpen.value = !columnMenuOpen.value;
  if (!columnMenuOpen.value) {
    return;
  }
  await nextTick();
  const rect = columnMenuRef.value?.getBoundingClientRect();
  if (!rect) {
    return;
  }
  const width = 250;
  const panelHeight = columnMenuPanelRef.value?.offsetHeight || 360;
  columnMenuPosition.left = Math.max(12, Math.min(window.innerWidth - width - 12, rect.right - width));
  columnMenuPosition.top = rect.bottom + 6 + panelHeight > window.innerHeight - 12
    ? Math.max(12, rect.top - panelHeight - 6)
    : rect.bottom + 6;
}

/** 返回稳定的列表字段标识 */
function columnKey(column) {
  return String(column.key || column.prop || column.label || '');
}

/** 判断字段是否不可隐藏 */
function isRequiredColumn(column) {
  return Boolean(column.required) || props.columns.indexOf(column) === 0;
}

/** 判断字段当前是否显示 */
function isColumnVisible(column) {
  return isRequiredColumn(column) || visibleColumnKeys.value.includes(columnKey(column));
}

/** 更新字段显示状态 */
function setColumnVisible(column, visible) {
  const key = columnKey(column);
  const keys = new Set(visibleColumnKeys.value);
  visible ? keys.add(key) : keys.delete(key);
  visibleColumnKeys.value = [...keys];
  persistVisibleColumns();
}

/** 恢复字段默认显示配置 */
function resetVisibleColumns() {
  visibleColumnKeys.value = defaultVisibleColumnKeys();
  persistVisibleColumns();
}

/** 读取字段显示配置 */
function loadVisibleColumns() {
  const available = new Set(props.columns.map(columnKey));
  if (props.storageKey) {
    try {
      const stored = JSON.parse(localStorage.getItem(props.storageKey) || '[]');
      if (Array.isArray(stored) && stored.length) {
        visibleColumnKeys.value = stored.filter(key => available.has(String(key))).map(String);
        return;
      }
    } catch {
      localStorage.removeItem(props.storageKey);
    }
  }
  visibleColumnKeys.value = defaultVisibleColumnKeys();
}

/** 保存字段显示配置 */
function persistVisibleColumns() {
  if (props.storageKey) {
    localStorage.setItem(props.storageKey, JSON.stringify(visibleColumnKeys.value));
  }
}

/** 返回默认显示字段 */
function defaultVisibleColumnKeys() {
  return props.columns
    .filter(column => column.defaultVisible !== false || isRequiredColumn(column))
    .map(columnKey);
}

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
