<script setup>
import { computed, onBeforeUnmount, reactive, watch } from 'vue';
import { ElMessageBox } from 'element-plus';
import { Copy, RefreshCw, Save } from '@lucide/vue';
import FormFieldLabel from '../../../shared/components/FormFieldLabel.vue';
import ListPagination from './ListPagination.vue';
import { request } from '../api/client';

const props = defineProps({ sessionKey: { type: String, required: true } });
const fields = ['enabled', 'display_name', 'base_url', 'client_id'];
const initial = () => ({
  settings: null, form: { enabled: false, display_name: '统一身份认证', base_url: 'https://tysfrz.cdjcc.edu.cn', client_id: '', client_secret: '' },
  loading: false, busy: '', error: '', notice: '',
  showConflicts: false, conflictState: 'pending', conflicts: [], conflictPage: 1, conflictTotal: 0,
  conflictLoading: false, conflictError: '', approvingId: 0,
});
const state = reactive(initial());
const dirty = computed(() => Boolean(state.settings) && (fields.some(key => state.form[key] !== state.settings[key]) || Boolean(state.form.client_secret)));
const pending = computed(() => state.loading || Boolean(state.busy));
const step = computed(() => !state.settings?.client_id || !state.settings?.client_secret_configured ? '配置应用'
  : !state.settings.enabled ? '校方登记回调' : !state.settings.ready ? '配置应用' : '授权登录');
let generation = 0;
let controller = new AbortController();
let disposed = false;
let conflictRequestId = 0;
const current = token => !disposed && token === generation;

function apply(settings) {
  state.settings = settings;
  state.form = { ...Object.fromEntries(fields.map(key => [key, settings[key]])), client_secret: '' };
}
function api(body, token) {
  return request('/iam/settings', { ...(body ? { method: 'POST', body: JSON.stringify(body) } : {}), signal: controller.signal, isCurrent: () => current(token) });
}
async function load() {
  if (pending.value) return;
  const token = generation;
  state.loading = true;
  state.error = '';
  try {
    const settings = await api(null, token);
    if (current(token)) apply(settings);
  } catch (error) { if (current(token)) state.error = error.message || '读取统一身份认证配置失败。'; }
  finally { if (current(token)) state.loading = false; }
}
async function reload() {
  if (pending.value) return;
  const token = generation;
  if (dirty.value) {
    try { await ElMessageBox.confirm('重新读取会放弃尚未保存的配置修改。', '重新读取配置', { confirmButtonText: '放弃修改并读取', cancelButtonText: '返回' }); }
    catch { return; }
  }
  if (current(token)) await load();
}
async function save(action = 'save') {
  if (pending.value || !state.settings) return;
  const token = generation;
  if (action === 'clear') {
    try { await ElMessageBox.confirm('立即停用统一身份认证并清空已保存的应用密钥；表单中的其他修改不会保存。', '停用并清空应用密钥', { confirmButtonText: '停用并清空', cancelButtonText: '返回', type: 'warning' }); }
    catch { return; }
    if (!current(token)) return;
  }
  state.busy = action;
  state.error = '';
  state.notice = '';
  try {
    const body = { revision: state.settings.revision, enabled: false };
    if (action === 'clear') body.clear_secret = true;
    if (action === 'save') {
      Object.assign(body, Object.fromEntries(fields.map(key => [key, typeof state.form[key] === 'string' ? state.form[key].trim() : state.form[key]])));
      if (!body.display_name) throw new Error('请填写登录入口名称。');
      let url;
      try { url = new URL(body.base_url); } catch { throw new Error('请填写有效的 HTTPS 认证地址。'); }
      if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') throw new Error('请填写不含路径、账号密码、查询参数或片段的 HTTPS 认证地址。');
      if (body.enabled && (!body.base_url || !body.client_id || !(state.form.client_secret || state.settings.client_secret_configured))) throw new Error('启用时须填写认证地址、Client ID，并配置应用密钥。');
      if (state.form.client_secret) body.client_secret = state.form.client_secret;
    }
    const settings = await api(body, token);
    if (!current(token)) return;
    if (action === 'save') apply(settings);
    else {
      state.settings = settings;
      state.form.enabled = false;
      if (action === 'clear') state.form.client_secret = '';
    }
    state.notice = action === 'clear' ? '统一身份认证已停用，已保存的应用密钥已清空。' : action === 'disable' ? '统一身份认证已停用；其他未保存修改仍保留。' : '配置已保存，请核对校方登记的回调地址后使用登录入口。';
    if (state.showConflicts) await loadConflicts(1);
  } catch (error) { if (current(token)) state.error = error.message || '保存统一身份认证配置失败。'; }
  finally { if (current(token)) state.busy = ''; }
}

async function loadConflicts(page = 1) {
  const token = generation;
  const requestId = ++conflictRequestId;
  const active = () => current(token) && requestId === conflictRequestId;
  state.conflictLoading = true;
  state.conflictError = '';
  try {
    const result = await request(`/iam/conflicts?page=${page}&status=${state.conflictState}`, { signal: controller.signal, isCurrent: active });
    if (active()) {
      state.conflicts = result.items || [];
      state.conflictPage = page;
      state.conflictTotal = result.total || 0;
      if (!state.conflicts.length && page > 1) await loadConflicts(page - 1);
    }
  } catch (error) {
    if (active()) {
      state.conflicts = [];
      state.conflictError = error.message || '读取绑定冲突失败';
    }
  } finally { if (active()) state.conflictLoading = false; }
}

async function reviewConflict(row) {
  if (!row.can_approve || state.approvingId || state.conflictLoading || pending.value) return;
  const token = generation;
  state.approvingId = row.id;
  state.conflictError = '';
  try {
    let reason;
    try {
      const result = await ElMessageBox.prompt(
        `核对本校人员 ${row.user_name || row.user_id}、原编号 ${row.before.business_number} 与本次编号 ${row.business_number} 后，填写迁移原因。`,
        '确认迁移绑定', {
          type: 'warning', confirmButtonText: '确认迁移', cancelButtonText: '返回', inputType: 'textarea',
          inputValidator: value => Boolean(value?.trim()) && value.trim().length <= 500 || '迁移原因必填，最多 500 字',
        },
      );
      reason = result.value.trim();
    } catch { return; }
    if (!current(token)) return;
    await request('/iam/conflict-approve', { method: 'POST', body: JSON.stringify({ id: row.id, reason }), signal: controller.signal, isCurrent: () => current(token) });
    if (!current(token)) return;
    state.notice = '绑定已迁移，请本人重新发起统一身份认证登录。';
    await loadConflicts(state.conflictPage);
  } catch (error) {
    if (current(token)) state.conflictError = error.message || '绑定迁移未完成';
  } finally { if (current(token)) state.approvingId = 0; }
}
async function copyCallback() {
  try { await navigator.clipboard.writeText(state.settings.callback_url); state.notice = '回调地址已复制。'; }
  catch { state.error = '复制失败，请在回调地址中手动选择并复制。'; }
}
watch(() => props.sessionKey, () => {
  generation++;
  controller.abort();
  controller = new AbortController();
  Object.assign(state, initial());
  if (props.sessionKey) load();
}, { immediate: true });
onBeforeUnmount(() => { disposed = true; generation++; controller.abort(); state.form.client_secret = ''; });
</script>

<template>
  <section class="iam-settings" aria-label="统一身份认证配置">
    <header class="iam-heading"><div><h2>统一身份认证</h2><p>仅超级管理员和学校管理员维护当前学校的认证应用。</p></div><el-button :icon="RefreshCw" :loading="state.loading" :disabled="pending" @click="reload">重新读取</el-button></header>
    <div class="iam-step" aria-live="polite"><strong>当前步骤：{{ step }}</strong><span>配置应用 → 校方登记回调 → 授权登录</span></div>
    <div class="iam-settings-body" v-loading="state.loading">
      <el-alert v-if="state.error" :title="state.error" type="error" :closable="false" show-icon />
      <el-alert v-if="state.notice" :title="state.notice" type="success" :closable="false" />
      <p v-if="state.settings?.blocked_reason" class="iam-help">阻塞原因：{{ state.settings.blocked_reason }}。请完善下方配置后保存。</p>
      <el-form class="iam-form" :disabled="pending || !state.settings" @submit.prevent>
        <label><FormFieldLabel :required="true" help="登录页显示的入口名称，最多 60 字。">登录入口名称</FormFieldLabel><el-input v-model="state.form.display_name" maxlength="60" /></label>
        <div><FormFieldLabel :required="true" help="保存后生效；未启用或配置不完整时不显示登录入口。">登录开关</FormFieldLabel><el-switch v-model="state.form.enabled" active-text="启用" inactive-text="停用" /></div>
        <label class="iam-wide"><FormFieldLabel :required="true" help="校方提供的 HTTPS 来源地址，不含路径和查询参数，最多 500 字。">认证地址</FormFieldLabel><el-input v-model="state.form.base_url" maxlength="500" placeholder="https://tysfrz.cdjcc.edu.cn" autocomplete="off" /></label>
        <label><FormFieldLabel :submit-required="true" help="启用时必填；由校方应用管理提供，最多 200 字。">Client ID</FormFieldLabel><el-input v-model="state.form.client_id" maxlength="200" autocomplete="off" /></label>
        <label><FormFieldLabel :submit-required="true" :help="`启用时须已配置；${state.settings?.client_secret_configured ? '已保存，留空保留' : '尚未配置'}，最多 512 字。`">应用密钥</FormFieldLabel><el-input v-model="state.form.client_secret" maxlength="512" type="password" autocomplete="new-password" :placeholder="state.settings?.client_secret_configured ? '已保存，留空保持不变' : '请输入应用密钥'" /></label>
      </el-form>
      <section v-if="state.settings" class="iam-callback">
        <h3>校方登记回调</h3>
        <p class="iam-help">将此完整地址登记到校方应用的回调配置；本系统不能核实校方是否已登记。</p>
        <el-input :model-value="state.settings.callback_url" readonly aria-label="学校回调地址"><template #append><el-button :icon="Copy" @click="copyCallback">复制</el-button></template></el-input>
        <p class="iam-help">保存并登记回调后，退出到当前学校登录页，选择统一身份认证完成授权。仅关联已开通的本校学号或工号账号。</p>
      </section>
      <section v-if="state.settings" class="iam-callback iam-conflicts" aria-label="身份绑定冲突">
        <div class="iam-heading">
          <h3>身份绑定冲突</h3>
          <el-button v-if="!state.showConflicts" :disabled="pending" @click="state.showConflicts = true; loadConflicts(1)">查看冲突</el-button>
          <div v-else class="iam-actions">
            <el-select v-model="state.conflictState" aria-label="冲突状态" class="iam-conflict-filter" :disabled="Boolean(state.approvingId)" @change="loadConflicts(1)">
              <el-option label="待核对" value="pending" /><el-option label="已迁移" value="resolved" />
            </el-select>
            <el-button :icon="RefreshCw" :loading="state.conflictLoading" :disabled="Boolean(state.approvingId)" @click="loadConflicts(state.conflictPage)">刷新</el-button>
          </div>
        </div>
        <p v-if="state.showConflicts" class="iam-help">请本人从当前校方入口完成一次认证，再核对本校人员与原绑定。迁移原因必填，最多 500 字。</p>
        <el-alert v-if="state.conflictError" :title="state.conflictError" type="error" :closable="false" />
        <template v-if="state.showConflicts">
          <el-table :data="state.conflicts" size="small" v-loading="state.conflictLoading" empty-text="当前状态下没有绑定冲突">
            <el-table-column label="本校人员" min-width="115"><template #default="{ row }">{{ row.user_name || '档案待核对' }} · {{ row.user_id }}</template></el-table-column>
            <el-table-column label="原绑定" min-width="145" show-overflow-tooltip><template #default="{ row }">{{ row.before.business_number }}<br>{{ row.before.subject }}<br>{{ row.before.source_key }}</template></el-table-column>
            <el-table-column label="本次认证" min-width="145" show-overflow-tooltip><template #default="{ row }">{{ row.business_number }}<br>{{ row.subject }}<br>{{ row.source_key }}</template></el-table-column>
            <el-table-column label="处理" min-width="170">
              <template #default="{ row }">
                <template v-if="row.resolved_at">{{ row.resolved_at }}<br>管理员 {{ row.resolved_by }} · {{ row.resolution_reason }}</template>
                <template v-else>
                  <span>{{ row.verified_at }}</span><br>
                  <span v-if="!row.can_approve">{{ row.blocked_reason }}</span>
                  <el-button v-else link type="primary" :loading="state.approvingId === row.id" :disabled="pending || Boolean(state.approvingId)" @click="reviewConflict(row)">确认迁移</el-button>
                </template>
              </template>
            </el-table-column>
          </el-table>
          <ListPagination layout="total, prev, pager, next" :current-page="state.conflictPage" :page-size="20" :total="state.conflictTotal" :disabled="state.conflictLoading || Boolean(state.approvingId)" @current-change="loadConflicts" />
        </template>
      </section>
    </div>
    <footer class="iam-footer"><div class="iam-actions"><el-button type="primary" :icon="Save" :loading="state.busy === 'save'" :disabled="pending || !state.settings" @click="save('save')">保存配置</el-button><span class="iam-help">{{ dirty ? '有未保存的修改' : `配置版本 ${state.settings?.revision ?? '—'}` }}</span></div><div class="iam-actions"><el-button :disabled="pending || !state.settings?.enabled" :loading="state.busy === 'disable'" @click="save('disable')">停用登录</el-button><el-button type="danger" plain :disabled="pending || !state.settings?.client_secret_configured" :loading="state.busy === 'clear'" @click="save('clear')">停用并清空密钥</el-button></div></footer>
  </section>
</template>

<style scoped>
.iam-settings { display: flex; flex: 1 1 0; flex-direction: column; height: 100%; min-height: 0; min-width: 0; gap: 14px; color: var(--text, #202b3a); }
.iam-heading, .iam-footer, .iam-actions { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.iam-heading h2 { margin: 0; font-size: 20px; }
.iam-heading p, .iam-help { margin: 5px 0; color: var(--muted, #627083); font-size: 12px; line-height: 1.7; overflow-wrap: anywhere; }
.iam-step { display: flex; flex-wrap: wrap; gap: 8px 18px; padding: 10px 12px; background: var(--surface-2, #f5f7fa); border-radius: var(--control-radius, 6px); font-size: 13px; }
.iam-step span { color: var(--muted, #627083); }
.iam-settings-body { display: grid; flex: 1 1 0; align-content: start; gap: 16px; min-height: 0; overflow: auto; padding-right: 6px; }
.iam-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.iam-form > label, .iam-form > div { display: grid; align-content: start; gap: 8px; min-width: 0; }
.iam-wide { grid-column: 1 / -1; }
.iam-callback h3 { margin: 0 0 8px; font-size: 15px; }
.iam-conflicts { border-top: 1px solid var(--line, #dce2e9); padding-top: 12px; }
.iam-conflict-filter { width: 110px; }
.iam-footer { flex: 0 0 auto; border-top: 1px solid var(--line, #dce2e9); padding-top: 12px; }
.iam-actions { justify-content: flex-start; }
.iam-actions :deep(.el-button + .el-button) { margin-left: 0; }
@media (max-width: 760px) { .iam-form { grid-template-columns: minmax(0, 1fr); } }
</style>
