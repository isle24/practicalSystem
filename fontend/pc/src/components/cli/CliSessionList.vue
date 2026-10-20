<script setup>
import { computed, ref, watch } from 'vue';
import { ElMessageBox } from 'element-plus';
import { RefreshCw } from '@lucide/vue';
import { request } from '../../api/client';
import { cliError, cliMutation, cliTime, cliTimestamp, useCliScope } from '../../utils/cli';

const props = defineProps({ sessionKey: { type: String, required: true }, admin: Boolean });
const begin = useCliScope(() => props.sessionKey);
const items = ref([]);
const pagination = ref({ page: 1, page_size: 20, total: 0 });
const loading = ref(false);
const revoking = ref('');
const error = ref('');
const message = ref('');
const prefix = computed(() => props.admin ? '/cli/admin' : '/cli/sessions');
let listVersion = 0;

function active(row) {
  const expiresAt = cliTimestamp(row.expires_at);
  return ['active', 'enabled'].includes(row.status) && (!Number.isFinite(expiresAt) || expiresAt > Date.now());
}

function status(row) {
  if (['revoked', 'disabled'].includes(row.status)) return '已撤销';
  if (row.status === 'expired' || (Number.isFinite(cliTimestamp(row.expires_at)) && cliTimestamp(row.expires_at) <= Date.now())) return '已过期';
  return active(row) ? '有效' : (row.status || '未知');
}

async function load(page = 1) {
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  const version = ++listVersion;
  loading.value = true;
  error.value = '';
  try {
    const path = `${props.admin ? '/cli/admin/sessions' : '/cli/sessions'}?${new URLSearchParams({ page, page_size: 20 })}`;
    const data = await request(path, scope.options);
    if (!scope.current() || version !== listVersion) return;
    items.value = Array.isArray(data.items) ? data.items : [];
    pagination.value = data.pagination || { page: 1, page_size: 20, total: items.value.length };
  } catch (reason) {
    if (scope.current() && version === listVersion) error.value = cliError(reason);
  } finally {
    if (scope.current() && version === listVersion) loading.value = false;
    scope.finish();
  }
}

async function revoke(row) {
  if (revoking.value || !active(row)) return;
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  const sessionId = String(row.session_id || '');
  if (!sessionId) { scope.finish(); return; }
  revoking.value = sessionId;
  error.value = '';
  message.value = '';
  try {
    await ElMessageBox.confirm(`撤销“${row.device_name || row.client_name || 'CLI 设备'}”后，该会话将无法继续调用 API。`, '撤销 CLI 会话', {
      type: 'warning', confirmButtonText: '撤销会话', cancelButtonText: '取消', dangerouslyUseHTMLString: false,
    });
    if (!scope.current()) return;
    await cliMutation(`${prefix.value}/revoke`, { session_id: sessionId }, scope.options);
    if (!scope.current()) return;
    message.value = 'CLI 会话已撤销。';
    await load(pagination.value.page || 1);
  } catch (reason) {
    if (scope.current() && !['cancel', 'close'].includes(reason)) error.value = cliError(reason);
  } finally {
    if (scope.current()) revoking.value = '';
    scope.finish();
  }
}

watch(() => props.sessionKey, key => {
  listVersion++;
  items.value = [];
  loading.value = false;
  revoking.value = '';
  error.value = '';
  message.value = '';
  if (key) load();
}, { immediate: true });
</script>

<template>
  <section class="cli-sessions" aria-label="CLI 会话管理">
    <header><div><h3>{{ admin ? '本校 CLI 会话' : '我的 CLI 会话' }}</h3><p>{{ admin ? '仅展示当前学校的会话。撤销后立即停止该会话的后续请求。' : '撤销不再使用或无法辨认的 CLI 设备。' }}</p></div><el-button :icon="RefreshCw" :loading="loading" :disabled="Boolean(revoking) || !sessionKey" @click="load(pagination.page)">刷新会话</el-button></header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-alert v-if="message" :title="message" type="success" :closable="false" show-icon />
    <el-table :data="items" v-loading="loading" stripe empty-text="暂无 CLI 会话" width-storage-key="cli:sessions">
      <el-table-column v-if="admin" label="账号" min-width="155"><template #default="{ row }"><span>{{ row.account_name || '—' }}</span><small class="cli-cell-secondary">{{ row.login_name }}</small></template></el-table-column>
      <el-table-column label="设备 / 客户端" min-width="190"><template #default="{ row }"><span>{{ row.device_name || '未命名设备' }}</span><small class="cli-cell-secondary">{{ row.client_name || '—' }} · {{ row.platform || '—' }}</small></template></el-table-column>
      <el-table-column label="状态" width="90"><template #default="{ row }"><el-tag :type="active(row) ? 'success' : 'info'">{{ status(row) }}</el-tag></template></el-table-column>
      <el-table-column label="创建时间" min-width="180"><template #default="{ row }">{{ cliTime(row.created_at) }}</template></el-table-column>
      <el-table-column label="最近使用" min-width="180"><template #default="{ row }">{{ cliTime(row.last_seen_at) }}</template></el-table-column>
      <el-table-column label="到期时间" min-width="180"><template #default="{ row }">{{ cliTime(row.expires_at) }}</template></el-table-column>
      <el-table-column label="操作" width="100" fixed="right"><template #default="{ row }"><el-button link type="danger" :disabled="!active(row) || Boolean(revoking) || !sessionKey" :loading="revoking === row.session_id" @click="revoke(row)">撤销</el-button></template></el-table-column>
    </el-table>
    <el-pagination v-if="pagination.total > pagination.page_size" :current-page="pagination.page" :page-size="pagination.page_size" :total="pagination.total" layout="total, prev, pager, next" :disabled="loading || Boolean(revoking)" @current-change="load" />
  </section>
</template>

<style scoped>
.cli-sessions { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
header { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
h3 { margin: 0; font-size: 16px; }
p { margin: 6px 0 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
.cli-cell-secondary { display: block; color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
</style>
