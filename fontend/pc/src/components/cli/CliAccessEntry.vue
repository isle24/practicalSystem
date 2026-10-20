<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RefreshCw } from '@lucide/vue';
import { request } from '../../api/client';
import { cliError, cliMutation, cliTime, cliTimestamp, useCliScope } from '../../utils/cli';
import CliSessionList from './CliSessionList.vue';

const props = defineProps({
  mode: { type: String, required: true }, userCode: { type: String, default: '' },
  sessionKey: { type: String, required: true }, accountName: { type: String, default: '' },
});
const emit = defineEmits(['close', 'logout']);
const begin = useCliScope(() => props.sessionKey ? `${props.sessionKey}:${props.userCode}:${props.mode}` : '');
const context = ref(null);
const loading = ref(false);
const action = ref('');
const error = ref('');
const checked = ref(false);
const now = ref(Date.now());
let version = 0;
let timer;
const codeValid = computed(() => /^[A-Za-z0-9-]{4,32}$/.test(props.userCode));
const expiresAt = computed(() => cliTimestamp(context.value?.expires_at));
const expired = computed(() => context.value?.state === 'expired' || (Number.isFinite(expiresAt.value) && now.value >= expiresAt.value));
const seconds = computed(() => Number.isFinite(expiresAt.value) ? Math.max(0, Math.ceil((expiresAt.value - now.value) / 1000)) : null);
const pending = computed(() => context.value?.state === 'pending' && !expired.value);
const canAuthorize = computed(() => Boolean(props.sessionKey) && pending.value && context.value?.can_authorize === true && !loading.value && !action.value);
const capabilities = computed(() => Array.isArray(context.value?.capabilities) ? context.value.capabilities : []);
const notice = computed(() => {
  if (expired.value) return { type: 'warning', text: '授权码已过期。请在 CLI 中重新发起登录，再打开新的授权地址。' };
  const state = context.value?.state;
  if (state === 'approved') return { type: 'success', text: '设备授权已确认，请返回 CLI 等待登录完成。' };
  if (['denied', 'rejected'].includes(state)) return { type: 'warning', text: '本次设备授权已拒绝。CLI 需要重新发起登录才能再次授权。' };
  if (['consumed', 'completed'].includes(state)) return { type: 'success', text: '本次设备登录已完成，可在“我的 CLI 会话”中管理设备。' };
  if (context.value && !context.value.can_authorize) return { type: 'warning', text: '当前学校未启用 CLI 或此账号没有可授权功能。请联系学校管理员，或使用正确的账号重新登录。' };
  if (context.value && !pending.value) return { type: 'info', text: '本次设备授权已结束，请重新读取或在 CLI 中重新发起登录。' };
  return null;
});

async function load() {
  if (action.value || props.mode !== 'authorize') return;
  checked.value = false;
  context.value = null;
  error.value = '';
  if (!codeValid.value) { error.value = '授权码格式无效，请使用 CLI 显示的完整授权地址。'; return; }
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  const ticket = ++version;
  loading.value = true;
  try {
    const data = await request(`/cli/device/context?user_code=${encodeURIComponent(props.userCode)}`, scope.options);
    if (!scope.current() || ticket !== version) return;
    if (!data || String(data.user_code).toUpperCase() !== props.userCode.toUpperCase()) throw new Error('设备核对码不一致，请重新打开 CLI 显示的授权地址。');
    context.value = data;
    now.value = Date.now();
  } catch (reason) {
    if (scope.current() && ticket === version) error.value = cliError(reason);
  } finally {
    if (scope.current() && ticket === version) loading.value = false;
    scope.finish();
  }
}

async function decide(decision) {
  if (!props.sessionKey || !pending.value || loading.value || action.value || (decision === 'approve' && (!canAuthorize.value || !checked.value))) return;
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  action.value = decision;
  error.value = '';
  try {
    const data = await cliMutation(`/cli/device/${decision}`, { user_code: props.userCode }, scope.options);
    if (!scope.current()) return;
    context.value = { ...context.value, state: data.state, can_authorize: false };
    checked.value = false;
  } catch (reason) {
    if (scope.current()) {
      error.value = cliError(reason);
      checked.value = false;
      context.value = null;
    }
  } finally {
    if (scope.current()) action.value = '';
    scope.finish();
  }
}

watch(() => [props.sessionKey, props.userCode, props.mode], () => {
  version++;
  context.value = null;
  error.value = '';
  loading.value = false;
  action.value = '';
  checked.value = false;
  if (props.sessionKey && props.mode === 'authorize') load();
}, { immediate: true });
watch(expired, value => { if (value) checked.value = false; });
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => { version++; clearInterval(timer); });
</script>

<template>
  <main class="cli-entry-shell">
    <section class="cli-entry-card" :class="{ 'cli-entry-wide': mode === 'sessions' }">
      <header><div><h1>{{ mode === 'authorize' ? '确认 CLI 设备授权' : '我的 CLI 会话' }}</h1><p>当前登录：{{ accountName || '当前账号' }}</p></div><el-button :disabled="Boolean(action)" @click="emit('logout')">更换账号</el-button></header>
      <template v-if="mode === 'authorize'">
        <p>请核对 CLI 中显示的授权码与设备信息。确认后，该设备可按学校配置和当前账号权限调用下列功能。</p>
        <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
        <el-alert v-if="notice" :title="notice.text" :type="notice.type" :closable="false" show-icon />
        <div v-loading="loading" class="cli-authorization-content">
          <template v-if="context">
            <div class="cli-code" aria-label="设备核对码">{{ context.user_code }}</div>
            <dl><div><dt>学校</dt><dd>{{ context.school?.name || '—' }}<small>{{ context.school?.domain }}</small></dd></div><div><dt>账号</dt><dd>{{ context.account?.name || accountName }}<small>{{ context.account?.login_name }}</small></dd></div><div><dt>客户端</dt><dd>{{ context.client_name || '未命名客户端' }}</dd></div><div><dt>设备</dt><dd>{{ context.device_name || '未命名设备' }}<small>{{ context.platform || '—' }}</small></dd></div><div><dt>到期时间</dt><dd>{{ cliTime(context.expires_at) }}<small v-if="pending && seconds !== null">剩余 {{ seconds }} 秒</small></dd></div></dl>
            <section v-if="pending" class="cli-access-capabilities"><h2>本次允许的功能</h2><ul v-if="capabilities.length"><li v-for="(item, index) in capabilities" :key="typeof item === 'string' ? item : (item.id || index)"><span>{{ typeof item === 'string' ? item : (item.label || item.id) }}</span><small v-if="typeof item !== 'string' && item.description">{{ item.description }}</small></li></ul><p v-else>当前账号暂无可授权功能。</p></section>
            <el-checkbox v-if="canAuthorize" v-model="checked" class="cli-confirm-check">我已核对设备及授权码，确认向此设备授权</el-checkbox>
          </template>
          <p v-else-if="loading">正在读取设备授权信息...</p>
        </div>
        <footer class="cli-entry-actions"><el-button :icon="RefreshCw" :loading="loading" :disabled="Boolean(action) || !sessionKey" @click="load">重新读取</el-button><el-button v-if="pending" type="danger" plain :loading="action === 'deny'" :disabled="loading || Boolean(action) || !sessionKey" @click="decide('deny')">拒绝授权</el-button><el-button v-if="pending" type="primary" :loading="action === 'approve'" :disabled="!canAuthorize || !checked" @click="decide('approve')">确认授权</el-button></footer>
      </template>
      <CliSessionList v-else :key="sessionKey" :session-key="sessionKey" />
      <div class="cli-entry-bottom"><el-button :disabled="Boolean(action)" @click="emit('close')">返回系统</el-button><span v-if="mode === 'authorize'">授权成功后可在个人设置中撤销 CLI 会话。</span></div>
    </section>
  </main>
</template>

<style scoped>
.cli-entry-shell { min-height: 100vh; background: var(--surface-2); display: flex; justify-content: center; align-items: flex-start; padding: 40px 20px; box-sizing: border-box; overflow: auto; }
.cli-entry-card { width: min(100%, 660px); background: var(--surface); color: var(--text); border: 1px solid var(--line); border-radius: 12px; padding: 28px; box-sizing: border-box; display: flex; flex-direction: column; gap: 18px; }
.cli-entry-wide { width: min(100%, 1180px); }
header, .cli-entry-actions, .cli-entry-bottom { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
h1 { font-size: 22px; margin: 0; } h2 { font-size: 15px; margin: 0 0 12px; }
p { color: var(--muted); font-size: 13px; line-height: 1.8; margin: 0; } header p { margin-top: 8px; }
.cli-authorization-content { min-height: 70px; }
.cli-code { padding: 18px; text-align: center; font-size: 30px; font-weight: 700; letter-spacing: .15em; background: var(--surface-2); border-radius: 8px; overflow-wrap: anywhere; }
dl { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin: 22px 0; }
dt { font-size: 12px; color: var(--muted); margin-bottom: 5px; } dd { margin: 0; font-size: 14px; overflow-wrap: anywhere; } small { display: block; color: var(--muted); font-size: 12px; margin-top: 4px; }
.cli-access-capabilities { border-top: 1px solid var(--line); padding-top: 18px; }
ul { margin: 0; padding-left: 20px; max-height: 240px; overflow: auto; } li { font-size: 13px; margin: 10px 0; overflow-wrap: anywhere; }
.cli-confirm-check { margin-top: 20px; height: auto; white-space: normal; }.cli-confirm-check :deep(.el-checkbox__label) { white-space: normal; line-height: 1.6; }
.cli-entry-actions { justify-content: flex-end; }.cli-entry-bottom { padding-top: 18px; border-top: 1px solid var(--line); font-size: 12px; color: var(--muted); }
@media (max-width: 600px) { .cli-entry-shell { padding: 14px 10px; }.cli-entry-card { padding: 18px; } dl { grid-template-columns: 1fr; } }
</style>
