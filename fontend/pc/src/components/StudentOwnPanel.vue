<template>
  <section class="student-own-panel">
    <header class="student-own-head">
      <div>
        <strong>{{ title }}</strong>
        <small>{{ description }}</small>
      </div>
      <el-button :icon="RefreshCw" :loading="loading" @click="emit('refresh')">
        刷新
      </el-button>
    </header>

    <div v-if="rows.length" class="student-own-grid">
      <article v-for="row in rows" :key="row.id || row.uuid" class="student-own-card">
        <header>
          <strong>{{ cardTitle(row) }}</strong>
          <el-tag v-if="statusValue(row)" :type="statusType(row)">
            {{ statusText(row) }}
          </el-tag>
        </header>
        <dl>
          <template v-for="field in fields" :key="field.key || field.label">
            <dt>{{ field.label }}</dt>
            <dd>{{ fieldText(field, row) }}</dd>
          </template>
        </dl>
        <footer v-if="timelineEntity">
          <el-button link type="info" @click="emit('timeline', row)">
            记录
          </el-button>
        </footer>
      </article>
    </div>

    <div v-else class="student-own-empty">
      <ClipboardList :size="30" />
      <span>{{ emptyText }}</span>
    </div>

    <footer
      v-if="pagination.total > pagination.page_size"
      class="student-own-pagination"
    >
      <span>共 {{ pagination.total }} 条</span>
      <el-pagination
        small
        layout="prev, pager, next"
        :current-page="pagination.page || 1"
        :page-size="pagination.page_size || 20"
        :total="pagination.total || 0"
        @current-change="page => emit('page-change', page)"
      />
    </footer>
  </section>
</template>

<script setup>
import { ClipboardList, RefreshCw } from '@lucide/vue';

const props = defineProps({
  description: {
    type: String,
    default: '',
  },
  emptyText: {
    type: String,
    default: '暂无数据',
  },
  fields: {
    type: Array,
    default: () => [],
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
  statusFormatter: {
    type: Function,
    default: value => value || '-',
  },
  statusTagType: {
    type: Function,
    default: () => 'primary',
  },
  timelineEntity: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['page-change', 'refresh', 'timeline']);

function cardTitle(row) {
  return row.title || row.arrangement_title || row.student_name || row.name || (row.id ? `记录 ${row.id}` : '记录');
}

function statusValue(row) {
  return row.status || row.teacher_status || row.admin_status || '';
}

function statusText(row) {
  return props.statusFormatter(statusValue(row));
}

function statusType(row) {
  return props.statusTagType(statusValue(row));
}

function fieldText(field, row) {
  if (typeof field.formatter === 'function') {
    return field.formatter(row);
  }
  const value = row[field.key];
  return value === null || value === undefined || value === '' ? '-' : value;
}
</script>
