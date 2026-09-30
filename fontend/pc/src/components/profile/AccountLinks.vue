<template>
  <section class="account-links" aria-labelledby="account-links-title">
    <h3 id="account-links-title">账号与角色切换</h3>
    <p>账号关联仅用于当前学校内切换，不合并账号的业务数据、消息或文件。手机号相同不会自动获得切换权限。</p>
    <p v-if="loading" role="status">正在读取关联账号…</p>
    <p v-if="message" class="message" role="status">{{ message }}</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-if="!loading && accounts.length" class="account-list">
      <article v-for="account in accounts" :key="account.id" class="account-row">
        <div class="account-detail">
          <strong>{{ account.name || account.login_name }} <span v-if="isCurrent(account)" class="current-label">当前账号</span></strong>
          <div>{{ account.login_name }} · {{ account.role_name || '未分配角色' }} · {{ account.status === 'enabled' ? '有效' : '不可用' }}</div>
          <div class="scope">{{ organizationLabel(account) }}</div>
          <div class="relationship">{{ account.link_type === 'same_user' ? '同一用户账号' : '双方验证关联' }}</div>
        </div>
        <div class="account-actions">
          <button v-if="!isCurrent(account)" type="button" :disabled="busy || loading || !account.can_switch" @click="switchAccount(account)">切换</button>
          <template v-if="account.can_unlink">
            <button v-if="pendingRemove !== account.id" type="button" :disabled="busy || loading" @click="pendingRemove = account.id">解除关联</button>
            <template v-else>
              <span>确认解除与该账号的直接关联？</span>
              <button type="button" :disabled="busy" @click="remove(account.id)">确认解除</button>
              <button type="button" :disabled="busy" @click="pendingRemove = null">取消</button>
            </template>
          </template>
        </div>
      </article>
    </div>
    <button v-if="error && !loading" type="button" :disabled="busy" @click="reload">重新读取</button>
    <p v-if="!loading && !linkingAvailable" class="notice">当前学校尚未升级账号关联结构，可继续切换同一用户的有效账号。</p>
    <form class="link-form" autocomplete="off" @submit.prevent="add">
      <h4>添加关联账号</h4>
      <label>当前账号密码<input v-model="form.current_password" type="password" autocomplete="current-password" maxlength="1024" :disabled="busy || loading || !linkingAvailable" required></label>
      <label>待关联登录账号<input v-model.trim="form.login_name" autocomplete="off" maxlength="80" :disabled="busy || loading || !linkingAvailable" required></label>
      <label>待关联账号密码<input v-model="form.password" type="password" autocomplete="off" maxlength="1024" :disabled="busy || loading || !linkingAvailable" required></label>
      <button type="submit" :disabled="busy || loading || !linkingAvailable">{{ busy ? '处理中…' : '验证双方并添加关联' }}</button>
    </form>
  </section>
</template>

<script setup>
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import { request } from '../../api/client';

const props = defineProps({ sessionKey: { type: [String, Number], default: '' }, currentAccountId: { type: Number, required: true } });
const emit = defineEmits(['changed', 'switch']);
const accounts = ref([]);
const loading = ref(true);
const busy = ref(false);
const linkingAvailable = ref(false);
const message = ref('');
const error = ref('');
const pendingRemove = ref(null);
const form = reactive({ current_password: '', login_name: '', password: '' });
const requests = new Set();
let revision = 0;
let alive = true;

function isCurrent(account) { return Number(account.id) === props.currentAccountId; }
function clearPasswords() { form.current_password = ''; form.password = ''; }
function cancelRequests() { requests.forEach((controller) => controller.abort()); requests.clear(); }
function organizationLabel(account) {
  const labels = (account.organization_scopes || []).map((scope) => [scope.dep_name || (scope.dep_id ? `学院 ${scope.dep_id}` : ''), scope.profession_name || (scope.profession_id ? `专业 ${scope.profession_id}` : ''), scope.class_name || (scope.class_id ? `班级 ${scope.class_id}` : ''), scope.company_name || (scope.company_id ? `企业 ${scope.company_id}` : '')].filter(Boolean).join(' / '));
  return labels.length ? [...new Set(labels)].join('；') : '无学院或专业范围';
}
function applyList(data) { accounts.value = data.accounts || []; linkingAvailable.value = data.linking_available === true; pendingRemove.value = null; }
async function send(path, options = {}) {
  const controller = new AbortController();
  requests.add(controller);
  try { return await request(path, { ...options, signal: controller.signal }); }
  finally { requests.delete(controller); }
}
async function reload() {
  const currentRevision = ++revision;
  cancelRequests(); clearPasswords();
  form.login_name = ''; accounts.value = []; pendingRemove.value = null; linkingAvailable.value = false;
  busy.value = false; loading.value = true; error.value = ''; message.value = '';
  try {
    const data = await send('/account-link/list');
    if (alive && currentRevision === revision) applyList(data);
  } catch (failure) { if (alive && currentRevision === revision) error.value = failure.message; }
  finally { if (alive && currentRevision === revision) loading.value = false; }
}
async function add() {
  if (busy.value || loading.value || !linkingAvailable.value) return;
  const currentRevision = revision;
  const body = JSON.stringify({ ...form });
  clearPasswords(); busy.value = true; error.value = ''; message.value = '';
  try {
    const data = await send('/account-link/add', { method: 'POST', body, refreshOnUnauthorized: false });
    if (!alive || currentRevision !== revision) return;
    applyList(data); form.login_name = ''; message.value = '关联已添加，可切换到该账号。'; emit('changed');
  } catch (failure) { if (alive && currentRevision === revision) error.value = failure.message; }
  finally { if (alive && currentRevision === revision) busy.value = false; }
}
async function remove(accountId) {
  if (busy.value || loading.value) return;
  const currentRevision = revision;
  busy.value = true; error.value = ''; message.value = ''; clearPasswords();
  try {
    const data = await send('/account-link/remove', { method: 'POST', body: JSON.stringify({ account_id: accountId }), refreshOnUnauthorized: false });
    if (!alive || currentRevision !== revision) return;
    applyList(data); message.value = '直接关联已解除。'; emit('changed');
  } catch (failure) { if (alive && currentRevision === revision) error.value = failure.message; }
  finally { if (alive && currentRevision === revision) busy.value = false; }
}
function switchAccount(account) {
  if (busy.value || loading.value || !account.can_switch || isCurrent(account)) return;
  clearPasswords(); pendingRemove.value = null; emit('switch', Number(account.id));
}
watch(() => [props.sessionKey, props.currentAccountId], reload, { immediate: true });
onBeforeUnmount(() => { alive = false; revision++; cancelRequests(); clearPasswords(); });
</script>

<style scoped>
.account-links { padding: 20px; color: var(--theme-text, #334155); background: var(--theme-panel, #fff); border: 1px solid var(--theme-border, #e2e8f0); border-radius: 12px; }
h3, h4 { margin: 0 0 12px; }
p { margin: 0 0 14px; line-height: 1.6; font-size: 14px; }
.account-list { display: grid; gap: 10px; margin-bottom: 20px; }
.account-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px; border: 1px solid var(--theme-border, #e2e8f0); border-radius: 8px; }
.account-detail { min-width: 0; overflow-wrap: anywhere; line-height: 1.8; font-size: 13px; }
.scope, .relationship { color: var(--muted, #64748b); }
.current-label { margin-left: 6px; color: var(--theme-accent, #2563eb); font-size: 12px; }
.account-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 8px; }
.account-actions span { width: 100%; font-size: 13px; }
.link-form { display: grid; gap: 12px; max-width: 520px; }
label { display: grid; gap: 6px; font-size: 14px; }
input { box-sizing: border-box; min-width: 0; padding: 10px; border: 1px solid var(--theme-border, #cbd5e1); border-radius: 6px; background: var(--surface, #fff); color: inherit; font: inherit; }
button { padding: 9px 14px; border: 1px solid var(--theme-border, #cbd5e1); border-radius: 6px; background: var(--surface, #fff); color: inherit; cursor: pointer; }
button:disabled { opacity: .5; cursor: default; }
.error { color: #b91c1c; }
.message { color: var(--theme-accent, #2563eb); }
.notice { color: var(--muted, #64748b); }
@media (max-width: 650px) { .account-row { align-items: stretch; flex-direction: column; } .account-actions { justify-content: flex-start; } }
</style>
