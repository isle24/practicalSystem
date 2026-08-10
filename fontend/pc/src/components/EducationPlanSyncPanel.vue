<template>
  <section class="education-sync-panel" :class="{ 'education-sync-panel-configurable': canConfigure }">
    <header class="education-sync-header">
      <div>
        <span class="education-sync-eyebrow">教务数据接收</span>
        <h2>教学计划同步</h2>
        <p>只接收教务系统教学计划，确认后生成本地计划草稿，任务和实施数据仍由本系统维护。</p>
      </div>
      <div class="education-sync-actions">
        <el-button :icon="RefreshCw" :loading="state.loading" @click="loadInbox(state.pagination.page)">刷新</el-button>
        <el-button v-if="canConfigure" type="primary" :icon="Download" :loading="state.pulling" @click="pullPlans">主动同步</el-button>
      </div>
    </header>

    <section v-if="canConfigure" class="education-sync-config">
      <div class="education-sync-section-title">
        <div>
          <strong>同步配置</strong>
          <small>配置只允许学校级管理员维护</small>
        </div>
        <el-button type="primary" :icon="Save" :loading="state.configLoading" @click="saveConfig">保存配置</el-button>
      </div>
      <div class="education-sync-form">
        <label>
          <span>教务计划接口</span>
          <input v-model.trim="state.config.pull_url" placeholder="https://example.edu/open-api" autocomplete="off">
        </label>
        <label>
          <span>应用编号</span>
          <input v-model.trim="state.config.pull_app_id" placeholder="请输入应用编号" autocomplete="off">
        </label>
        <label>
          <span>应用密钥</span>
          <input v-model="state.config.pull_app_secret" type="password" placeholder="留空表示保持原密钥" autocomplete="new-password">
        </label>
        <div class="education-sync-last-time">
          <span>上次同步</span>
          <strong>{{ state.config.last_synced_at || '尚未同步' }}</strong>
        </div>
      </div>
    </section>

    <section class="education-sync-inbox">
      <div class="education-sync-section-title">
        <div>
          <strong>计划接收箱</strong>
          <small>先查看映射与差异，再确认生成本地计划</small>
        </div>
        <div class="education-sync-filter">
          <input v-model.trim="state.keyword" placeholder="搜索课程、学院、专业" @keyup.enter="loadInbox(1)">
          <el-button :icon="Search" :loading="state.loading" @click="loadInbox(1)">查询</el-button>
        </div>
      </div>

      <el-tabs v-model="state.tab" @tab-change="loadInbox(1)">
        <el-tab-pane label="待接收" name="pending" />
        <el-tab-pane label="已生成" name="generated" />
        <el-tab-pane label="已忽略" name="ignored" />
      </el-tabs>

      <el-table :data="state.items" stripe size="small" v-loading="state.loading" class="education-sync-table">
        <el-table-column type="index" label="序号" width="66" align="center" />
        <el-table-column prop="course_name" label="课程名称" min-width="190" show-overflow-tooltip />
        <el-table-column prop="course_code" label="课程代码" width="130" />
        <el-table-column prop="grade_name" label="年级" width="100" />
        <el-table-column prop="dep_name" label="学院" min-width="150" show-overflow-tooltip />
        <el-table-column prop="profession_name" label="专业" min-width="170" show-overflow-tooltip />
        <el-table-column prop="credit" label="学分" width="76" />
        <el-table-column label="映射" width="100">
          <template #default="{ row }">
            <el-tag :type="row.mapping_status === 'matched' ? 'success' : 'danger'">
              {{ row.mapping_status === 'matched' ? '已匹配' : '需处理' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="source_updated_at" label="来源更新时间" width="168" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">{{ statusLabel(row.status) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">查看差异</el-button>
            <el-button v-if="isPending(row)" link type="success" :disabled="row.mapping_status !== 'matched'" @click="confirmRow(row)">确认生成</el-button>
            <el-button v-if="isPending(row)" link type="warning" @click="openIgnore(row)">忽略</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="education-sync-pagination">
        <span>共 {{ state.pagination.total || 0 }} 条</span>
        <el-pagination
          size="small"
          layout="prev, pager, next"
          :current-page="state.pagination.page || 1"
          :page-size="state.pagination.page_size || 20"
          :total="state.pagination.total || 0"
          @current-change="loadInbox"
        />
      </div>
      <el-alert v-if="state.message" type="warning" :closable="false" :title="state.message" />
    </section>

    <OperationDialog
      :visible="state.detailVisible"
      title="教学计划差异"
      dialog-class="education-sync-detail-dialog"
      :busy="state.detailLoading"
      @close="state.detailVisible = false"
    >
      <el-skeleton v-if="state.detailLoading" :rows="8" animated />
      <template v-else>
        <div class="education-sync-detail-head">
          <strong>{{ state.detail.item?.course_name || '-' }}</strong>
          <span>{{ state.detail.item?.source_plan_id || '-' }} / {{ state.detail.item?.source_version || '-' }}</span>
        </div>
        <el-alert
          v-if="state.detail.item?.mapping_status !== 'matched'"
          type="error"
          :closable="false"
          title="基础档案映射失败，修正年级、学院或专业后重新同步"
        />
        <el-alert
          v-else
          type="info"
          :closable="false"
          title="确认后生成本地计划草稿；实习类别仍需在计划表补充后再提交审核"
        />
        <el-table :data="state.detail.diff || []" size="small" stripe>
          <el-table-column prop="label" label="字段" width="130" />
          <el-table-column prop="source" label="教务系统" min-width="220" show-overflow-tooltip />
          <el-table-column prop="local" label="本地数据" min-width="220" show-overflow-tooltip />
        </el-table>
        <section class="education-sync-raw-preview">
          <span>来源信息</span>
          <pre>{{ rawPreview }}</pre>
        </section>
      </template>
    </OperationDialog>

    <OperationDialog
      :visible="state.ignoreVisible"
      title="忽略教学计划"
      dialog-class="education-sync-ignore-dialog"
      :busy="state.ignoreLoading"
      @close="state.ignoreVisible = false"
    >
      <label class="education-sync-ignore-field">
        <span>忽略原因</span>
        <textarea v-model.trim="state.ignoreReason" rows="5" maxlength="500" placeholder="请填写忽略原因" />
        <small>{{ state.ignoreReason.length }} / 500</small>
      </label>
      <template #footer>
        <el-button @click="state.ignoreVisible = false">取消</el-button>
        <el-button type="warning" :loading="state.ignoreLoading" @click="ignoreRow">确认忽略</el-button>
      </template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive } from 'vue';
import { ElMessageBox } from 'element-plus';
import { Download, RefreshCw, Save, Search } from '@lucide/vue';
import OperationDialog from './OperationDialog.vue';
import {
  confirmEducationPlanSync,
  fetchEducationPlanSyncConfig,
  fetchEducationPlanSyncDetail,
  fetchEducationPlanSyncInbox,
  ignoreEducationPlanSync,
  pullEducationPlans,
  saveEducationPlanSyncConfig,
} from '../api/system';

const props = defineProps({
  canConfigure: {
    type: Boolean,
    default: false,
  },
});

const state = reactive({
  tab: 'pending',
  keyword: '',
  items: [],
  pagination: { page: 1, page_size: 20, total: 0 },
  loading: false,
  pulling: false,
  configLoading: false,
  message: '',
  config: { pull_url: '', pull_app_id: '', pull_app_secret: '', last_synced_at: '' },
  detailVisible: false,
  detailLoading: false,
  detail: { item: null, diff: [] },
  ignoreVisible: false,
  ignoreLoading: false,
  ignoreReason: '',
  ignoreRowId: 0,
});

const rawPreview = computed(() => JSON.stringify(state.detail.item?.raw_payload || {}, null, 2));

function statusLabel(status) {
  return {
    pending: '待接收',
    change_pending: '待确认变更',
    mapping_failed: '映射失败',
    generated: '已生成',
    ignored: '已忽略',
  }[status] || status || '-';
}

function isPending(row) {
  return ['pending', 'change_pending', 'mapping_failed'].includes(row?.status);
}

async function loadConfig() {
  try {
    const data = await fetchEducationPlanSyncConfig();
    Object.assign(state.config, data || {});
  } catch (error) {
    state.message = error.message;
  }
}

async function loadInbox(page = 1) {
  state.loading = true;
  state.message = '';
  try {
    const data = await fetchEducationPlanSyncInbox({
      page,
      page_size: state.pagination.page_size || 20,
      status: state.tab,
      keyword: state.keyword,
    });
    state.items = data.items || [];
    state.pagination = { ...state.pagination, ...(data.pagination || {}), page };
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

async function saveConfig() {
  if (state.configLoading) {
    return;
  }
  state.configLoading = true;
  state.message = '';
  try {
    const data = await saveEducationPlanSyncConfig({
      pull_url: state.config.pull_url,
      pull_app_id: state.config.pull_app_id,
      pull_app_secret: state.config.pull_app_secret,
    });
    Object.assign(state.config, data || {});
    state.message = '同步配置已保存';
  } catch (error) {
    state.message = error.message;
  } finally {
    state.configLoading = false;
  }
}

async function pullPlans() {
  if (state.pulling || !props.canConfigure) {
    return;
  }
  try {
    await ElMessageBox.confirm('同步只会进入接收箱，不会直接覆盖本地计划。', '确认主动同步', {
      type: 'warning',
      confirmButtonText: '确认同步',
      cancelButtonText: '取消',
    });
  } catch {
    return;
  }
  state.pulling = true;
  state.message = '';
  try {
    const result = await pullEducationPlans({});
    state.message = `同步完成：接收 ${result.received || 0} 条，新增 ${result.created || 0} 条，更新 ${result.updated || 0} 条`;
    await loadConfig();
    await loadInbox(1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.pulling = false;
  }
}

async function openDetail(row) {
  state.detailVisible = true;
  state.detailLoading = true;
  state.detail = { item: row, diff: [] };
  try {
    state.detail = await fetchEducationPlanSyncDetail(row.id);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.detailLoading = false;
  }
}

async function confirmRow(row) {
  try {
    await ElMessageBox.confirm(
      `确认接收「${row.course_name || row.source_plan_id}」并生成本地计划草稿？`,
      '确认生成计划',
      {
        type: 'warning',
        confirmButtonText: '确认生成',
        cancelButtonText: '取消',
      },
    );
  } catch {
    return;
  }
  state.loading = true;
  state.message = '';
  try {
    await confirmEducationPlanSync({ ids: [row.id] });
    state.message = '教学计划已生成本地草稿';
    await loadInbox(state.pagination.page || 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.loading = false;
  }
}

function openIgnore(row) {
  state.ignoreRowId = row.id;
  state.ignoreReason = '';
  state.ignoreVisible = true;
}

async function ignoreRow() {
  if (!state.ignoreRowId || !state.ignoreReason) {
    state.message = '请填写忽略原因';
    return;
  }
  state.ignoreLoading = true;
  try {
    await ignoreEducationPlanSync({ id: state.ignoreRowId, reason: state.ignoreReason });
    state.ignoreVisible = false;
    state.message = '教学计划已忽略';
    await loadInbox(state.pagination.page || 1);
  } catch (error) {
    state.message = error.message;
  } finally {
    state.ignoreLoading = false;
  }
}

onMounted(async () => {
  await Promise.all([props.canConfigure ? loadConfig() : Promise.resolve(), loadInbox(1)]);
});
</script>

<style scoped>
.education-sync-panel {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  gap: 16px;
  height: 100%;
  min-height: 0;
  padding: 4px;
}

.education-sync-panel-configurable {
  grid-template-rows: auto auto minmax(0, 1fr);
}

.education-sync-header,
.education-sync-section-title {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.education-sync-eyebrow,
.education-sync-section-title small,
.education-sync-last-time span,
.education-sync-ignore-field span {
  color: var(--muted);
  font-size: 12px;
}

.education-sync-header h2 {
  margin: 4px 0;
  color: var(--text);
  font-size: 20px;
}

.education-sync-header p {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
}

.education-sync-actions,
.education-sync-filter {
  display: flex;
  align-items: center;
  gap: 8px;
}

.education-sync-section-title > .el-button {
  height: var(--control-height);
  padding-top: 0;
  padding-bottom: 0;
}

.education-sync-config,
.education-sync-inbox {
  min-height: 0;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--surface);
  padding: 16px;
}

.education-sync-section-title strong {
  display: block;
  margin-bottom: 3px;
  color: var(--text);
  font-size: 14px;
}

.education-sync-form {
  display: grid;
  grid-template-columns: minmax(240px, 2fr) minmax(180px, 1fr) minmax(180px, 1fr) minmax(150px, 1fr);
  gap: 12px;
  margin-top: 14px;
}

.education-sync-form label,
.education-sync-ignore-field {
  display: grid;
  gap: 6px;
}

.education-sync-form label > span {
  color: var(--muted);
  font-size: 12px;
}

.education-sync-form input,
.education-sync-filter input,
.education-sync-ignore-field textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0 10px;
  color: var(--text);
  background: var(--surface-2);
}

.education-sync-form input,
.education-sync-filter input {
  height: var(--control-height);
}

.education-sync-ignore-field textarea {
  padding-top: 9px;
  padding-bottom: 9px;
}

.education-sync-last-time {
  display: grid;
  align-content: center;
  gap: 5px;
  padding: 0 8px;
  background: var(--surface-2);
}

.education-sync-last-time strong {
  color: var(--text);
  font-size: 13px;
}

.education-sync-inbox {
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr) auto auto;
  overflow: hidden;
}

.education-sync-inbox :deep(.el-tabs__header) {
  margin-bottom: 8px;
}

.education-sync-table {
  min-height: 0;
}

.education-sync-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-top: 12px;
  color: var(--muted);
  font-size: 12px;
}

.education-sync-detail-head {
  display: grid;
  gap: 4px;
  margin-bottom: 14px;
}

.education-sync-detail-head strong {
  color: var(--text);
  font-size: 16px;
}

.education-sync-detail-head span,
.education-sync-raw-preview > span,
.education-sync-ignore-field small {
  color: var(--muted);
  font-size: 12px;
}

.education-sync-raw-preview {
  display: grid;
  gap: 6px;
  margin-top: 14px;
}

.education-sync-raw-preview pre {
  max-height: 180px;
  overflow: auto;
  margin: 0;
  padding: 10px;
  border-radius: 6px;
  background: var(--surface-2);
  color: var(--muted);
  font-size: 11px;
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 1100px) {
  .education-sync-form {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
