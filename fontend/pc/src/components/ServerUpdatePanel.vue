<template>
  <el-button v-if="state?.allowed" :icon="ServerCog" @click="open">服务器更新</el-button>
  <OperationDialog :visible="visible" title="服务器 Git 更新" :busy="busy" @close="close">
    <div class="server-update">
      <el-alert title="更新会短暂中断此服务器上全部学校的访问。数据库和依赖变更需人工处理，操作前请确认维护时间。" type="warning" :closable="false" />
      <el-alert v-if="error" :title="error" type="error" :closable="false" />
      <el-alert v-if="waiting" title="正在等待服务恢复，将继续查询同一任务，请勿重复提交。" type="info" :closable="false" />
      <dl v-if="state"><dt>固定分支</dt><dd>{{ state.branch }}</dd><dt>当前提交</dt><dd class="sha">{{ state.current_sha }}</dd><dt>维护状态</dt><dd>{{ state.maintenance ? '维护中' : '正常访问' }}</dd><dt>任务日志目录</dt><dd class="sha">{{ state.log_directory }}</dd></dl>
      <section v-if="job" class="job">
        <div class="job-heading"><strong>{{ phaseLabel }}</strong><el-tag :type="job.phase === 'succeeded' ? 'success' : job.phase === 'failed' || job.phase === 'manual_required' || job.orphaned ? 'danger' : 'info'">{{ kindLabel }}</el-tag></div>
        <p>{{ job.message }}</p><small>任务 {{ job.id }} · {{ job.updated_at }}</small>
        <el-alert v-if="job.orphaned || job.phase === 'manual_required'" title="任务需要人工核对。请在服务器查看上述目录中的任务日志；系统不会覆盖代码或自动重投任务。" type="error" :closable="false" />
        <template v-if="job.plan">
          <dl><dt>原提交</dt><dd class="sha">{{ job.plan.old_sha }}</dd><dt>目标提交</dt><dd class="sha">{{ job.plan.target_sha }}</dd><dt>变更文件</dt><dd>{{ job.plan.file_count }} 个</dd></dl>
          <p v-if="job.kind === 'check'">计划有效至 {{ new Date(job.plan.expires * 1000).toLocaleString() }}。</p>
          <pre v-if="job.plan.commits" class="changes">{{ job.plan.commits }}</pre>
          <details v-if="job.plan.files?.length"><summary>查看变更文件（最多展示 150 个）</summary><pre class="changes">{{ job.plan.files.join('\n') }}</pre></details>
        </template>
        <pre v-if="logs" class="logs" aria-label="更新任务日志">{{ logs }}</pre>
      </section>
      <p v-else>点击“检查更新”读取固定远端分支，并检查工作区、PHP 语法和静态资源。检查不会停止服务。</p>
    </div>
    <template #footer>
      <el-button :disabled="busy" @click="refresh">刷新状态</el-button>
      <el-button :disabled="busy || active || state?.maintenance" @click="submit('check')">检查更新</el-button>
      <el-button v-if="state?.rollback && state?.last_success_id" type="warning" :disabled="busy || active || state?.maintenance" @click="rollback">回退上次更新</el-button>
      <el-button v-if="state?.execute" type="primary" :disabled="!canApply" :loading="busy" @click="apply">拉取并重启</el-button>
    </template>
  </OperationDialog>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { ServerCog } from '@lucide/vue';
import { ElMessageBox } from 'element-plus';
import { request } from '../api/client';
import OperationDialog from './OperationDialog.vue';

const visible = ref(false), busy = ref(false), waiting = ref(false), error = ref('');
const state = ref(null), job = ref(null), logs = ref('');
const terminal = ['succeeded', 'failed', 'rolled_back', 'manual_required'];
const active = computed(() => Boolean(job.value && !terminal.includes(job.value.phase)));
const canApply = computed(() => !busy.value && !active.value && !state.value?.maintenance && job.value?.kind === 'check' && job.value.phase === 'succeeded' && job.value.plan?.old_sha !== job.value.plan?.target_sha && job.value.plan?.expires * 1000 > Date.now());
const phaseLabel = computed(() => ({ queued: '等待执行', checking: '检查更新', preflight: '更新前复核', draining: '等待在途工作完成', stopping: '停止旧服务', applying: '应用目标提交', restarting: '启动服务', verifying: '核对服务状态', finishing: '恢复访问', recovering: '恢复原版本', succeeded: '已完成', failed: '未完成', rolled_back: '已恢复原版本', manual_required: '需要人工处理' }[job.value?.phase] || job.value?.phase));
const kindLabel = computed(() => ({ check: '更新检查', update: '代码更新', rollback: '版本回退' }[job.value?.kind] || ''));
let alive = true, timer = null, controller = null, cursor = 0, pending = null;

function schedule() {
  clearTimeout(timer);
  if (alive && visible.value) timer = setTimeout(refresh, active.value || waiting.value ? 2000 : 10000);
}

async function refresh() {
  if (!alive || busy.value) return;
  controller?.abort();
  controller = new AbortController();
  const signal = controller.signal;
  try {
    const data = await request('/server-update/status', { signal, timeoutMs: 8000, refreshOnUnauthorized: false });
    if (!alive || signal.aborted) return;
    state.value = data;
    if (!data.allowed) { visible.value = false; job.value = null; logs.value = ''; return; }
    if (job.value?.id !== data.job?.id) { cursor = 0; logs.value = ''; }
    job.value = data.job;
    if (pending?.requestId === data.job?.request_id) pending = null;
    waiting.value = false;
    if (job.value && visible.value) {
      const page = await request(`/server-update/jobs/${job.value.id}/logs?cursor=${cursor}`, { signal, timeoutMs: 8000, refreshOnUnauthorized: false });
      if (!alive || signal.aborted) return;
      cursor = page.cursor;
      logs.value = (logs.value + page.text).slice(-120000);
    }
  } catch (e) {
    if (!alive || signal.aborted) return;
    if (job.value && !terminal.includes(job.value.phase) && ![401, 403].includes(e.status)) waiting.value = true;
    else if (visible.value) error.value = e.message;
  } finally { if (controller?.signal === signal) schedule(); }
}

async function open() { visible.value = true; error.value = ''; await refresh(); }
function close() { visible.value = false; clearTimeout(timer); controller?.abort(); }

async function submit(kind, reference = '') {
  if (!alive || busy.value || active.value || !state.value?.allowed) return;
  busy.value = true; error.value = ''; clearTimeout(timer); controller?.abort();
  const key = `${kind}:${reference}`;
  if (!pending || pending.key !== key) pending = { key, requestId: crypto.randomUUID() };
  const path = kind === 'check' ? '/server-update/check' : kind === 'rollback' ? `/server-update/jobs/${reference}/rollback` : '/server-update/jobs';
  try {
    const result = await request(path, { method: 'POST', timeoutMs: 15000, refreshOnUnauthorized: false, headers: { 'X-CSRF-Token': state.value.csrf_token }, body: JSON.stringify({ request_id: pending.requestId, ...(kind === 'update' ? { plan_id: reference } : {}) }) });
    if (!alive) return;
    job.value = result; cursor = 0; logs.value = ''; pending = null;
  } catch (e) { if (alive) error.value = `${e.message}；请先刷新状态确认原任务，重复提交将使用同一请求标识。`; }
  finally { if (alive) { busy.value = false; await refresh(); } }
}

async function apply() {
  if (!canApply.value) return;
  const plan = job.value.plan;
  try { await ElMessageBox.confirm(`将更新此实例的全部学校，目标提交 ${plan.target_sha.slice(0, 12)}。服务会短暂中断，确认已安排维护时间并继续？`, '确认拉取并重启', { type: 'warning', confirmButtonText: '确认更新' }); } catch { return; }
  if (!alive || !visible.value || !canApply.value || job.value?.plan?.id !== plan.id) return;
  await submit('update', plan.id);
}

async function rollback() {
  const id = state.value?.last_success_id;
  if (!id || active.value || busy.value) return;
  try { await ElMessageBox.confirm('将停止服务并恢复最近一次成功更新前的代码。仅在当前提交和工作区符合条件时执行，确认继续？', '确认回退', { type: 'warning', confirmButtonText: '确认回退' }); } catch { return; }
  if (!alive || !visible.value || state.value?.last_success_id !== id || active.value || busy.value) return;
  await submit('rollback', id);
}

refresh();
onBeforeUnmount(() => { alive = false; clearTimeout(timer); controller?.abort(); });
</script>

<style scoped>
.server-update{padding:24px;display:flex;flex-direction:column;gap:18px;min-height:0;overflow:auto;color:var(--text)}
dl{display:grid;grid-template-columns:100px minmax(0,1fr);gap:12px;margin:0;font-size:13px;line-height:1.6}dt,small{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}.sha{font-family:monospace}.job{display:flex;flex-direction:column;gap:14px}.job-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.job p{margin:0;line-height:1.7;overflow-wrap:anywhere}pre{margin:0;padding:16px;border:1px solid var(--border);border-radius:8px;background:var(--surface-2);color:var(--text);white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.8 monospace;max-height:240px;overflow:auto}.logs{max-height:300px}summary{cursor:pointer;color:var(--muted);margin-bottom:12px}@media(max-width:600px){.server-update{padding:16px}dl{grid-template-columns:1fr;gap:4px}dd{margin-bottom:10px}}
</style>
