<template>
  <section class="admin-panel database-schema-panel">
    <div class="admin-toolbar">
      <strong class="admin-toolbar-title">数据库结构</strong>
      <label class="database-schema-target">
        <span>检查范围</span>
        <el-select v-model="state.target" :disabled="state.loading || !state.targets.length" aria-label="检查范围" placeholder="请选择数据库">
          <el-option v-for="target in state.targets" :key="target.value" :label="target.label" :value="target.value" />
        </el-select>
      </label>
      <el-button :icon="RefreshCw" :loading="state.loading" :disabled="state.checking" @click="loadOptions">重新加载</el-button>
      <el-button type="primary" :icon="Search" :loading="state.checking" :disabled="!canCheck" @click="checkStructure">检查结构</el-button>
      <el-button :icon="FileText" :disabled="!hasSql" @click="state.preview = true">预览升级 SQL</el-button>
      <el-button :icon="Download" :disabled="!hasSql" @click="downloadSql">下载升级 SQL</el-button>
    </div>

    <div class="database-schema-meta">
      <span>结构基准：{{ state.result?.baseline_version || state.baselineVersion || '未加载' }}</span>
      <template v-if="state.result">
        <span>数据库：{{ state.result.database }}</span>
        <span>检查时间：{{ state.result.checked_at }}</span>
        <span>检查表数：{{ state.result.table_count }}</span>
      </template>
    </div>

    <el-alert v-if="state.error" :title="state.error" type="error" :closable="false" show-icon />
    <el-alert
      v-if="!state.loading && state.baselineVersion && !state.baselineCurrent"
      title="结构基准与当前代码不匹配，请部署完整版本或更新基准后重新加载。"
      type="warning"
      :closable="false"
      show-icon
    />

    <template v-if="state.result">
      <el-alert v-if="state.result.consistent" title="数据库结构一致" type="success" :closable="false" show-icon />
      <div v-else class="database-schema-counts">
        <strong>结构差异 {{ state.result.summary.total }} 项</strong>
        <el-tag type="success">可新增 {{ state.result.summary.add }}</el-tag>
        <el-tag type="warning">需人工确认 {{ state.result.summary.review }}</el-tag>
        <el-tag type="info">保留现状 {{ state.result.summary.keep }}</el-tag>
      </div>
      <div class="database-schema-table">
        <el-table :data="state.result.differences" height="100%" size="small" stripe empty-text="未发现结构差异">
          <el-table-column prop="table" label="表名" min-width="170" show-overflow-tooltip />
          <el-table-column prop="object" label="对象" min-width="150" show-overflow-tooltip />
          <el-table-column prop="type" label="差异类型" min-width="150" show-overflow-tooltip />
          <el-table-column label="当前结构" min-width="250">
            <template #default="{ row }"><pre class="database-schema-definition">{{ definitionText(row.current) }}</pre></template>
          </el-table-column>
          <el-table-column label="基准结构" min-width="250">
            <template #default="{ row }"><pre class="database-schema-definition">{{ definitionText(row.expected) }}</pre></template>
          </el-table-column>
          <el-table-column label="处理建议" width="120" fixed="right">
            <template #default="{ row }">
              <el-tag :type="actionType(row.action)">{{ actionText(row.action) }}</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <small>升级 SQL 仅供预览和下载，需人工确认的差异请核对后处理。</small>
    </template>
    <div v-else class="database-schema-empty" v-loading="state.loading || state.checking" :element-loading-text="state.checking ? '正在检查数据库结构' : '正在加载数据库范围'">
      <el-empty :description="state.error ? '暂未取得结构检查结果' : '选择数据库后点击检查结构'" />
    </div>

    <OperationDialog :visible="state.preview && hasSql" title="升级 SQL" dialog-class="database-schema-sql-dialog" @close="state.preview = false">
      <div class="database-schema-preview">
        <div class="database-schema-meta">
          <span>{{ state.result?.file_name }}</span>
          <span>数据库：{{ state.result?.database }}</span>
          <span>检查时间：{{ state.result?.checked_at }}</span>
        </div>
        <pre>{{ state.result?.sql_content }}</pre>
      </div>
      <template #footer>
        <el-button @click="state.preview = false">关闭</el-button>
        <el-button type="primary" :icon="Download" :disabled="!hasSql" @click="downloadSql">下载升级 SQL</el-button>
      </template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, watch } from 'vue';
import { Download, FileText, RefreshCw, Search } from '@lucide/vue';
import { request } from '../api/client';
import OperationDialog from './OperationDialog.vue';

const props = defineProps({
  sessionKey: { type: String, required: true },
});

const state = reactive({
  target: '',
  targets: [],
  baselineVersion: '',
  baselineCurrent: false,
  loading: false,
  checking: false,
  error: '',
  result: null,
  preview: false,
});

const canCheck = computed(() => Boolean(state.target && state.baselineCurrent && !state.loading && !state.checking));
const hasSql = computed(() => Boolean(state.result?.sql_content?.trim()));
let optionsRequestId = 0;
let checkRequestId = 0;
let optionsController = null;
let checkController = null;

function clearResult() {
  checkRequestId += 1;
  checkController?.abort();
  checkController = null;
  state.checking = false;
  state.result = null;
  state.preview = false;
  state.error = '';
}

async function loadOptions() {
  const requestId = ++optionsRequestId;
  optionsController?.abort();
  const controller = new AbortController();
  optionsController = controller;
  clearResult();
  state.target = '';
  state.targets = [];
  state.baselineVersion = '';
  state.baselineCurrent = false;
  state.loading = true;
  try {
    const data = await request('/config/database-schema/options', { signal: controller.signal, refreshOnUnauthorized: false });
    if (requestId !== optionsRequestId) return;
    state.targets = data.targets || [];
    state.baselineVersion = data.baseline_version || '';
    state.baselineCurrent = data.baseline_current === true;
    state.target = state.targets.find(target => target.value === 'school')?.value || state.targets[0]?.value || '';
  } catch (error) {
    if (requestId === optionsRequestId) state.error = error.message || '数据库范围加载失败';
  } finally {
    if (requestId === optionsRequestId) {
      state.loading = false;
      optionsController = null;
    }
  }
}

async function checkStructure() {
  if (!canCheck.value) return;
  clearResult();
  const requestId = ++checkRequestId;
  const target = state.target;
  const controller = new AbortController();
  checkController = controller;
  state.checking = true;
  try {
    const data = await request('/config/database-schema/check', {
      method: 'POST',
      body: JSON.stringify({ target }),
      signal: controller.signal,
      refreshOnUnauthorized: false,
    });
    if (requestId !== checkRequestId || target !== state.target) return;
    state.result = data;
  } catch (error) {
    if (requestId === checkRequestId) state.error = error.message || '数据库结构检查失败';
  } finally {
    if (requestId === checkRequestId) {
      state.checking = false;
      checkController = null;
    }
  }
}

function definitionText(value) {
  if (value === null || value === undefined || value === '') return '—';
  return typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
}

function actionText(action) {
  return { add: '可新增', review: '需人工确认', keep: '保留现状' }[action] || action;
}

function actionType(action) {
  return { add: 'success', review: 'warning', keep: 'info' }[action] || 'info';
}

function downloadSql() {
  if (!hasSql.value) return;
  const blob = new Blob([state.result.sql_content], { type: 'application/sql;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = state.result.file_name || 'database-upgrade.sql';
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

watch(() => state.target, clearResult, { flush: 'sync' });
watch(() => props.sessionKey, loadOptions, { immediate: true, flush: 'sync' });

onBeforeUnmount(() => {
  optionsRequestId += 1;
  optionsController?.abort();
  clearResult();
});
</script>

<style scoped>
.database-schema-panel {
  height: 100%;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
  box-sizing: border-box;
}

.database-schema-target,
.database-schema-meta,
.database-schema-counts {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 12px;
}

.database-schema-target,
.database-schema-meta {
  color: var(--muted);
  font-size: 12px;
}

.database-schema-meta {
  overflow-wrap: anywhere;
}

.database-schema-panel > :not(.database-schema-table):not(.database-schema-empty) {
  flex-shrink: 0;
}

.database-schema-table,
.database-schema-empty {
  flex: 1;
  min-height: 120px;
  min-width: 0;
}

.database-schema-empty {
  display: grid;
  place-items: center;
  overflow: auto;
}

.database-schema-counts {
  font-size: 13px;
}

.database-schema-definition {
  max-height: 100px;
  margin: 0;
  overflow: auto;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font: 12px/1.6 monospace;
}

:global(.operation-dialog.database-schema-sql-dialog) {
  width: min(1000px, calc(100vw - 48px));
  height: min(680px, calc(100dvh - 122px));
}

.database-schema-preview {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  overflow: hidden;
}

.database-schema-preview pre {
  flex: 1;
  min-height: 0;
  margin: 0;
  padding: 14px;
  overflow: auto;
  border: 1px solid var(--line);
  border-radius: var(--control-radius);
  background: #f4f6f8;
  font: 12px/1.8 monospace;
}
</style>
