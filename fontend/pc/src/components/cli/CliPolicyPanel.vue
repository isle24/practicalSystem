<script setup>
import { computed, ref, watch } from 'vue';
import { RefreshCw, Save } from '@lucide/vue';
import { request } from '../../api/client';
import { cliDownloadUrl, cliError, cliMutation, useCliScope } from '../../utils/cli';
import CliSessionList from './CliSessionList.vue';
import CliPlatformDownloads from './CliPlatformDownloads.vue';

const props = defineProps({ sessionKey: { type: String, required: true }, publicOrigin: { type: String, required: true } });
const begin = useCliScope(() => props.sessionKey);
const policy = ref(null);
const enabled = ref(false);
const selected = ref([]);
const loading = ref(false);
const saving = ref(false);
const conflict = ref(false);
const error = ref('');
const message = ref('');
let loadVersion = 0;
const available = item => (item.available === true || item.available === 1) && String(item.phase || '').toUpperCase() !== 'C';
const selectableIds = computed(() => (policy.value?.catalog || []).filter(available).map(item => item.id));
const selectedIds = computed(() => new Set(selected.value));
const moduleNames = { note: '个人笔记', favorite: '收藏', file: '文件', doc: '文档', archive: '基础档案', internship: '实习管理', practice: '实验实训', social_practice: '社会实践', config: '系统配置' };
const groups = computed(() => {
  const grouped = new Map();
  for (const item of policy.value?.catalog || []) {
    const name = moduleNames[item.module] || item.module || '其他';
    if (!grouped.has(name)) grouped.set(name, []);
    grouped.get(name).push(item);
  }
  return Array.from(grouped, ([name, items]) => ({ name, items, ids: items.filter(available).map(item => item.id) }));
});
const sorted = values => JSON.stringify([...new Set(values || [])].sort());
const dirty = computed(() => policy.value && (enabled.value !== Boolean(policy.value.enabled) || sorted(selected.value) !== sorted(policy.value.capabilities)));
const writable = computed(() => policy.value && Number.isInteger(policy.value.revision) && !conflict.value && Boolean(props.sessionKey));
const downloads = computed(() => Object.fromEntries(Object.entries(policy.value?.downloads || {}).map(([key, value]) => [key, cliDownloadUrl(value, props.publicOrigin)])));
const downloadItems = computed(() => [
  { key: 'manifest', label: '平台版本清单（含 SHA256）' },
  { key: 'skill', label: '标准 Skill ZIP' },
  { key: 'connector', label: 'WorkBuddy 连接器 ZIP' },
  { key: 'install_sh', label: 'macOS / Linux 安装脚本' },
  { key: 'install_ps1', label: 'Windows 安装脚本' },
]);
const limitLabels = {
  account_concurrency: '账号并发', school_concurrency: '学校并发', read_per_second: '查询 / 秒', read_per_minute: '查询 / 分钟',
  write_per_second: '写入 / 秒', write_per_minute: '写入 / 分钟', school_per_second: '学校请求 / 秒', school_per_minute: '学校请求 / 分钟',
  task_requests: '每任务请求数', task_seconds: '任务有效期（秒）', max_retries: '最多自动重试次数',
};
const limits = computed(() => Object.entries(policy.value?.limits || {}).map(([key, value]) => ({ label: limitLabels[key] || key, value })));

function changeSelection(mode, ids = selectableIds.value) {
  if (!writable.value || loading.value || saving.value) return;
  const allowed = new Set(selectableIds.value);
  const next = new Set(selected.value.filter(id => allowed.has(id)));
  for (const id of ids) {
    if (!allowed.has(id)) continue;
    if (mode === 'all') next.add(id);
    else if (mode === 'invert') next.has(id) ? next.delete(id) : next.add(id);
    else if (mode === 'clear') next.delete(id);
  }
  selected.value = [...next];
}

function apply(data) {
  if (!data || !Array.isArray(data.catalog) || !Array.isArray(data.capabilities) || !Number.isInteger(data.revision)) throw new Error('学校 CLI 策略响应不完整，请重新读取');
  policy.value = data;
  enabled.value = Boolean(data.enabled);
  const allowed = new Set(data.catalog.filter(available).map(item => item.id));
  selected.value = data.capabilities.filter(id => allowed.has(id));
  conflict.value = false;
}

async function load() {
  if (saving.value) return;
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  const version = ++loadVersion;
  loading.value = true;
  error.value = '';
  message.value = '';
  try {
    const data = await request('/cli/admin/policy', scope.options);
    if (scope.current() && version === loadVersion) apply(data);
  } catch (reason) {
    if (scope.current() && version === loadVersion) error.value = cliError(reason);
  } finally {
    if (scope.current() && version === loadVersion) loading.value = false;
    scope.finish();
  }
}

async function save() {
  if (!writable.value || saving.value || loading.value || !dirty.value) return;
  const scope = begin();
  if (!scope.current()) { scope.finish(); return; }
  const payload = { enabled: enabled.value, revision: policy.value.revision, capabilities: [...selected.value] };
  saving.value = true;
  error.value = '';
  message.value = '';
  try {
    const data = await cliMutation('/cli/admin/policy', payload, scope.options);
    if (!scope.current()) return;
    apply(data);
    message.value = '学校 CLI 策略已保存。后续请求按最新策略校验。';
  } catch (reason) {
    if (scope.current()) {
      conflict.value = reason.status === 409;
      error.value = conflict.value ? '学校策略已由其他管理员更新。请点击“重新读取”，核对最新配置后再保存。' : cliError(reason);
    }
  } finally {
    if (scope.current()) saving.value = false;
    scope.finish();
  }
}

watch(() => props.sessionKey, key => {
  loadVersion++;
  policy.value = null;
  selected.value = [];
  enabled.value = false;
  loading.value = false;
  saving.value = false;
  conflict.value = false;
  error.value = '';
  message.value = '';
  if (key) load();
}, { immediate: true });
</script>

<template>
  <section class="cli-policy">
    <header class="cli-heading"><div><h2>CLI 授权</h2><p>{{ policy?.school?.name || '当前学校' }} · 仅管理本校 CLI 功能和会话</p></div><div class="cli-actions"><el-button :icon="RefreshCw" :loading="loading" :disabled="saving || !sessionKey" @click="load">重新读取</el-button><el-button type="primary" :icon="Save" :loading="saving" :disabled="!writable || loading || !dirty" @click="save">保存配置</el-button></div></header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-alert v-if="message" :title="message" type="success" :closable="false" show-icon />
    <div v-loading="loading" class="cli-policy-content">
      <template v-if="policy">
        <section class="cli-card cli-enable"><div><h3>学校 CLI 开关</h3><p>本校用户统一受此配置约束。已勾选功能还需满足账号原有权限与数据范围。</p><small>配置修订 {{ policy.revision }}{{ dirty ? ' · 有未保存修改' : '' }}</small></div><el-switch v-model="enabled" active-text="启用" inactive-text="关闭" :disabled="!writable || loading || saving" aria-label="学校 CLI 开关" /></section>
        <el-alert v-if="!enabled" title="学校 CLI 已关闭，设备授权与后续调用不可用。更改后请保存。" type="info" :closable="false" show-icon />
        <section class="cli-card"><div class="cli-catalog-heading"><h3>允许调用的功能</h3><div class="cli-catalog-controls"><small aria-live="polite">已选 {{ selected.length }} / {{ selectableIds.length }} 项</small><el-button size="small" :disabled="!writable || loading || saving || !selectableIds.length" aria-label="全选已开放能力" @click="changeSelection('all')">全选</el-button><el-button size="small" :disabled="!writable || loading || saving || !selectableIds.length" aria-label="反选已开放能力" @click="changeSelection('invert')">反选</el-button><el-button size="small" :disabled="!writable || loading || saving || !selected.length" aria-label="清空已选能力" @click="changeSelection('clear')">清空</el-button></div></div><p>查询、普通新增和修改按目录逐项开放。批量勾选仅作用于已开放功能，更改后请保存。阶段 C 的删除、审批、导入和系统配置首版不开放。</p>
          <el-checkbox-group v-model="selected" :disabled="!writable || loading || saving" class="cli-catalog">
            <section v-for="group in groups" :key="group.name" class="cli-capability-group"><div class="cli-catalog-heading cli-group-heading"><h4>{{ group.name }}</h4><div class="cli-catalog-controls"><small>已选 {{ group.ids.filter(id => selectedIds.has(id)).length }} / {{ group.ids.length }} 项</small><el-button size="small" :disabled="!writable || loading || saving || !group.ids.length" :aria-label="`全选${group.name}能力`" @click="changeSelection('all', group.ids)">全选</el-button><el-button size="small" :disabled="!writable || loading || saving || !group.ids.length" :aria-label="`反选${group.name}能力`" @click="changeSelection('invert', group.ids)">反选</el-button><el-button size="small" :disabled="!writable || loading || saving || !group.ids.some(id => selectedIds.has(id))" :aria-label="`清空${group.name}能力`" @click="changeSelection('clear', group.ids)">清空</el-button></div></div><div v-for="item in group.items" :key="item.id" class="cli-capability"><el-checkbox :value="item.id" :disabled="!available(item)"><span>{{ item.label || item.id }}</span><el-tag v-if="!available(item)" type="info" size="small">{{ String(item.phase || '').toUpperCase() === 'C' ? '阶段 C · 首版不开放' : '暂不可用' }}</el-tag></el-checkbox><small>{{ item.description || item.id }}</small><code>{{ item.method }} {{ item.path }}</code></div></section>
          </el-checkbox-group>
          <el-empty v-if="!groups.length" description="暂无可配置的 CLI 功能" :image-size="64" />
        </section>
        <section class="cli-card"><h3>调用限额</h3><p>账号和学校限额分别计数；任务预算耗尽后不会自动重置。</p><dl class="cli-limits"><div v-for="limit in limits" :key="limit.label"><dt>{{ limit.label }}</dt><dd>{{ limit.value }}</dd></div></dl></section>
        <section class="cli-card"><h3>安装与下载</h3><p>Windows、macOS、Linux 的 amd64 / arm64 版本从本校域名分发。安装命令可重复执行以更新，凭证独立保存。</p><div class="cli-downloads"><template v-for="item in downloadItems" :key="item.key"><a v-if="downloads[item.key]" :href="downloads[item.key]" target="_blank" rel="noopener noreferrer">{{ item.label }}</a><span v-else>{{ item.label }}：链接不可用</span></template></div><CliPlatformDownloads :manifest-url="downloads.manifest || ''" :public-origin="publicOrigin" :session-key="sessionKey" /><template v-if="downloads.install_sh"><h4>macOS / Linux</h4><pre><code>curl -q --no-location -fsS '{{ downloads.install_sh }}' | sh</code></pre></template><template v-if="downloads.install_ps1"><h4>Windows PowerShell</h4><pre><code>&amp; ([ScriptBlock]::Create((Invoke-WebRequest -UseBasicParsing '{{ downloads.install_ps1 }}' -MaximumRedirection 0).Content))</code></pre></template><p>下载 ZIP 时，请在平台版本清单中选择对应系统与架构，并核对 SHA256。服务器需完成静态分发文件部署后，链接才会生效。</p></section>
      </template>
      <el-empty v-else-if="!loading && !error" description="请重新读取学校 CLI 配置" />
    </div>
    <section class="cli-card"><CliSessionList :key="sessionKey" :session-key="sessionKey" admin /></section>
  </section>
</template>

<style scoped>
.cli-policy { height: 100%; overflow: auto; padding: 22px; display: flex; flex-direction: column; gap: 16px; box-sizing: border-box; }
.cli-heading, .cli-actions, .cli-enable { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.cli-policy-content { display: flex; flex-direction: column; gap: 16px; min-height: 90px; }
.cli-policy > * { flex-shrink: 0; }
h2, h3 { margin: 0; font-size: 18px; }
h3 { font-size: 16px; }
h4 { margin: 16px 0 10px; font-size: 14px; }
p { color: var(--muted); font-size: 13px; line-height: 1.7; margin: 8px 0; }
small { color: var(--muted); font-size: 12px; }
.cli-card { border: 1px solid var(--line); border-radius: 10px; padding: 20px; background: var(--surface); min-width: 0; }
.cli-catalog { display: flex; flex-direction: column; font-size: 13px; line-height: 1.6; }
.cli-catalog-heading, .cli-catalog-controls { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.cli-catalog-controls { justify-content: flex-end; }.cli-catalog-controls :deep(.el-button + .el-button) { margin-left: 0; }
.cli-group-heading { margin: 16px 0 8px; }.cli-group-heading h4 { margin: 0; }
.cli-capability-group + .cli-capability-group { border-top: 1px solid var(--line); margin-top: 14px; }
.cli-capability { padding: 9px 0; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.cli-capability :deep(.el-checkbox) { height: auto; white-space: normal; }
.cli-capability :deep(.el-checkbox__label) { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; line-height: 1.6; }
.cli-capability small, .cli-capability code { margin-left: 24px; overflow-wrap: anywhere; font-size: 12px; }
.cli-capability code { color: var(--muted); }
.cli-limits { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
.cli-limits div { background: var(--surface-2); padding: 12px; border-radius: 6px; }
dt { color: var(--muted); font-size: 12px; } dd { margin: 5px 0 0; font-size: 18px; }
.cli-downloads { display: flex; flex-wrap: wrap; gap: 12px 20px; font-size: 13px; }
.cli-downloads a { color: var(--primary-ink); }.cli-downloads span { color: var(--muted); }
pre { padding: 12px; background: var(--surface-2); overflow: auto; border-radius: 6px; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; }
@media (max-width: 600px) { .cli-policy { padding: 12px; }.cli-card { padding: 14px; } }
</style>
