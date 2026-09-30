<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, watch } from 'vue';
import { ElMessageBox } from 'element-plus';

const props = defineProps({ sessionKey: { type: String, default: '' } });
const bridge = window.__PRACTICAL_DESKTOP__;
const supported = Boolean(bridge?.credentialVault);
const state = reactive({ items: [], enabled: false, available: false, cloudEnabled: false,
  cloudCount: null, reason: '', migrationError: '', loading: false, busy: false, error: '', message: '' });
const locked = computed(() => state.loading || state.busy);
let sequence = 0;

async function load() {
  if (!supported) return;
  const ticket = ++sequence;
  state.loading = true;
  try {
    const data = await bridge.credentialVault('status');
    if (ticket !== sequence) return;
    state.items = data.items || [];
    state.enabled = Boolean(data.enabled);
    state.available = Boolean(data.available);
    state.cloudEnabled = Boolean(data.cloud_enabled);
    state.cloudCount = Number.isInteger(data.cloud_count) ? data.cloud_count : null;
    state.reason = data.reason || '';
    state.migrationError = data.migration_error || '';
  } catch (error) {
    if (ticket === sequence) { state.error = error.message; state.available = false; }
  } finally { if (ticket === sequence) state.loading = false; }
}

async function operate(action, payload, message) {
  if (locked.value) return;
  state.busy = true;
  state.error = '';
  state.message = '';
  try {
    const result = await bridge.credentialVault(action, payload);
    state.message = typeof message === 'function' ? message(result) : message;
  } catch (error) { state.error = error.message; }
  finally { await load(); state.busy = false; }
}

async function changeEnabled(enabled) {
  if (locked.value) return;
  if (enabled) {
    try {
      await ElMessageBox.confirm('云同步采用服务器独立密钥加密。服务器具备解密能力，此功能不是端到端加密。确认将本机已保存的外部站点认证配置同步到当前学校账号，并下载已有云副本？', '开启云同步密码', { confirmButtonText: '确认开启', cancelButtonText: '取消' });
    } catch { return; }
  }
  await operate('settings', { enabled, confirm: enabled }, enabled ? '已开启并完成同步。' : '已停止本机与服务器同步，服务器副本保留。');
}

async function remove(item) {
  try {
    await ElMessageBox.confirm(state.enabled ? '删除本机此条凭据，并把删除操作同步到当前账号的其他设备？' : '删除本机此条凭据？服务器副本保持不变。', '删除凭据');
  } catch { return; }
  await operate('remove', { id: item.id, confirm: true }, '已删除本机凭据。');
}

async function clear() {
  try {
    await ElMessageBox.confirm(state.enabled ? '清空当前学校账号已建立索引的本机凭据，并同步这些记录的删除操作？' : '清空当前学校账号已建立索引的本机凭据？服务器副本保持不变。', '清空本机凭据');
  } catch { return; }
  await operate('clear', { confirm: true }, '已清空已建立索引的本机凭据。');
}

async function deleteCloud() {
  try {
    await ElMessageBox.confirm('永久删除当前学校账号的全部服务器凭据副本，并停止云同步？本机和其他设备已保存的本地凭据会保留。', '删除服务器副本', { type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消' });
  } catch { return; }
  await operate('delete-cloud', { confirm: true }, '已删除服务器副本并停止同步，本机凭据保留。');
}

function sync() {
  operate('sync', {}, result => '同步完成：上传 ' + result.uploaded + ' 条，下载更新 ' + result.imported + ' 条，接收删除 ' + result.removed + ' 条。');
}

function refresh() { state.error = ''; state.message = ''; load(); }
watch(() => props.sessionKey, () => { sequence++; state.items = []; state.enabled = false; state.error = ''; state.message = ''; load(); });
onMounted(() => { load(); window.addEventListener('practical-credential-vault-changed', refresh); });
onBeforeUnmount(() => { sequence++; window.removeEventListener('practical-credential-vault-changed', refresh); });
</script>

<template>
  <section v-if="supported" class="profile-panel-card credential-vault-settings">
    <header><strong>本机凭据与云同步</strong><small>外部站点认证配置按学校和当前账号隔离。密码始终保存在系统安全凭据库，页面只显示元数据。</small></header>
    <el-alert v-if="state.error" :title="state.error" type="error" :closable="false" />
    <el-alert v-if="state.message" :title="state.message" type="success" :closable="false" />
    <el-alert v-if="state.reason" :title="state.reason" type="warning" :closable="false" />
    <el-alert v-if="state.migrationError" :title="'历史凭据索引迁移尚未完成：' + state.migrationError" type="warning" :closable="false" />
    <label class="vault-switch"><span><strong>云同步密码</strong><small>默认关闭。服务器密钥加密，服务器具备解密能力。</small></span><el-switch :model-value="state.enabled" :disabled="locked || (!state.enabled && !state.available)" @change="changeEnabled" /></label>
    <p>停止同步保留服务器副本；“删除服务器副本”会单独确认。开启后，保存和删除认证配置会同步；每台设备仍需明确开启。</p>
    <div class="vault-toolbar"><el-button :loading="state.loading" :disabled="state.busy" @click="refresh">读取</el-button><el-button :disabled="locked || !state.enabled || !state.available" @click="sync">立即同步</el-button><el-button :disabled="locked" @click="deleteCloud">删除服务器副本</el-button><span v-if="state.cloudCount !== null">服务器 {{ state.cloudCount }} 条{{ state.cloudEnabled ? '' : '（已停止同步）' }}</span></div>
    <div class="vault-list" v-loading="state.loading">
      <article v-for="item in state.items" :key="item.id"><div><strong>{{ item.label || '外部站点' }}</strong><small>{{ item.site_origin }}</small></div><el-button text type="danger" :disabled="locked" @click="remove(item)">删除</el-button></article>
      <el-empty v-if="!state.loading && !state.items.length" description="暂无已建立索引的本机凭据" :image-size="48" />
    </div>
    <div class="vault-toolbar"><span>本机 {{ state.items.length }} 条</span><el-button type="danger" plain :disabled="locked || !state.items.length" @click="clear">清空本机凭据</el-button></div>
    <p>通过收藏的“本机认证配置”保存或更新。列表只管理已建立索引的记录；旧版本中已删除或改址的收藏无法自动恢复索引，其历史凭据请通过系统凭据管理器处理。</p>
  </section>
</template>

<style scoped>
.credential-vault-settings{display:flex;flex-direction:column;gap:14px;min-width:0}.credential-vault-settings header{display:flex;flex-direction:column;gap:6px}.credential-vault-settings p,.credential-vault-settings small{margin:0;font-size:12px;line-height:1.6;color:var(--muted)}.vault-switch,.vault-toolbar,.vault-list article{display:flex;align-items:center;justify-content:space-between;gap:12px}.vault-switch>span,.vault-list article>div{display:flex;flex-direction:column;gap:5px;min-width:0}.vault-toolbar{justify-content:flex-start;flex-wrap:wrap;font-size:12px;color:var(--muted)}.vault-list{border:1px solid var(--line);border-radius:6px;max-height:300px;overflow:auto}.vault-list article{padding:12px 14px;border-bottom:1px solid var(--line)}.vault-list article:last-child{border-bottom:0}.vault-list strong,.vault-list small{overflow-wrap:anywhere}.vault-list strong{font-size:13px;color:var(--text)}
</style>
