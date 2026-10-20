<template>
  <main class="agent-page" :aria-busy="loading">
    <header class="agent-heading">
      <div><span class="eyebrow">实践系统 · 桌面客户端</span><h1>Agent</h1></div>
      <div class="heading-actions">
        <button type="button" :disabled="loading || pending" @click="bootstrap">同步配置</button>
        <button ref="settingsButton" type="button" :aria-expanded="settingsOpen" aria-controls="personal-settings" :disabled="!scope || loading" @click="toggleSettings">个人配置</button>
      </div>
    </header>

    <p v-if="scope" class="scope-label">{{ scope.origin }} · 学校 {{ scope.school_id }} · 账号 {{ scope.account_id }}</p>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>

    <section v-if="settingsOpen && scope" id="personal-settings" class="settings-panel" aria-labelledby="settings-title">
      <div class="section-heading"><h2 id="settings-title">个人云配置</h2><button type="button" @click="closeSettings">收起</button></div>
      <form class="settings-form" @submit.prevent="saveSettings">
        <label class="check-label"><input v-model="settings.enabled" type="checkbox" :disabled="pending || busy">启用个人模式</label>
        <label for="agent-endpoint">接口地址</label>
        <input id="agent-endpoint" ref="endpointInput" v-model="settings.endpoint" type="url" maxlength="500" placeholder="https://api.example.com/v1/chat/completions" autocomplete="off" spellcheck="false" aria-describedby="agent-endpoint-hint" :disabled="pending || busy">
        <p id="agent-endpoint-hint" class="muted">完整 Chat Completions 接口地址</p>
        <label for="agent-model">模型</label>
        <input id="agent-model" v-model="settings.model" maxlength="120" autocomplete="off" spellcheck="false" :disabled="pending || busy">
        <label for="agent-key">API Key <span class="muted">{{ personal.has_key ? '已保存，留空保留原密钥' : '尚未保存' }}</span></label>
        <input id="agent-key" v-model="settings.api_key" type="password" maxlength="2048" autocomplete="new-password" spellcheck="false" :disabled="pending || busy || settings.clear_key">
        <label class="check-label"><input v-model="settings.clear_key" type="checkbox" :disabled="pending || busy || !personal.has_key">清除已保存的密钥</label>
        <div class="settings-footer"><span class="muted">学校模式由学校管理员配置。</span><button class="primary" type="submit" :disabled="pending || loading || busy">{{ saving ? '保存中' : '保存配置' }}</button></div>
      </form>
    </section>

    <section class="conversation" aria-labelledby="conversation-title">
      <div class="conversation-toolbar">
        <h2 id="conversation-title">当前会话</h2>
        <div class="conversation-actions">
          <button type="button" :disabled="!scope || loading || pending || busy" @click="requestClear('new')">新会话</button>
          <button type="button" :disabled="!scope || loading || pending || busy || (!history.length && !memoryError)" @click="requestClear('history')">清空本地历史</button>
        </div>
      </div>
      <div ref="messageList" class="message-list" tabindex="0" aria-label="会话消息">
        <div v-if="!history.length && !activeText && !busy" class="empty-state"><h3>{{ loading ? '正在连接' : '输入任务开始会话' }}</h3><p>会话按当前学校与账号保存在本机，展示最近 30 轮。</p><p>执行写入操作时，客户端会单独请求确认。</p></div>
        <article v-for="(message, index) in history" :key="index" class="message" :class="message.role"><h3>{{ message.role === 'user' ? '你' : 'Agent' }}</h3><p>{{ message.text }}</p></article>
        <article v-if="busy || activeText" class="message assistant"><h3>Agent<span v-if="busy" class="message-state">处理中</span></h3><p v-if="activeText">{{ activeText }}</p><p v-else class="muted">正在处理任务…</p></article>
      </div>
      <div class="run-status" role="status" aria-live="polite"><span>{{ statusText }}</span><span v-if="toolStatus">{{ toolStatus }}</span></div>
    </section>

    <form class="composer" @submit.prevent="send">
      <div class="composer-options"><label for="agent-mode">模型来源</label><select id="agent-mode" v-model="mode" :disabled="!scope || busy || pending || loading"><option value="school">学校模式{{ school.enabled ? '' : '（未启用）' }}</option><option value="personal">个人模式{{ personal.enabled ? '' : '（未启用）' }}</option></select><span class="model-label">{{ selectedModel || '尚未配置模型' }}</span></div>
      <label class="sr-only" for="agent-prompt">任务内容</label>
      <textarea id="agent-prompt" ref="promptInput" v-model="prompt" rows="4" maxlength="12000" placeholder="输入任务，Enter 发送，Shift + Enter 换行" :disabled="!scope || loading || pending || busy" @keydown="handlePromptKeydown"></textarea>
      <div class="composer-footer"><span class="muted">{{ prompt.length }} / 12000</span><div><button v-if="busy" type="button" :disabled="pending || !runId" @click="stop">{{ stopping ? '正在停止' : '停止' }}</button><button v-else class="primary" type="submit" :disabled="!canSend">发送</button></div></div>
      <div v-if="scope" class="tools-actions"><button type="button" :disabled="pending || loading || busy" @click="reconnect">连接业务工具</button><span class="muted">连接后可调用学校已授权功能；未连接时可进行模型问答。</span></div>
    </form>

    <dialog ref="clearDialog" class="confirm-dialog" aria-labelledby="clear-title" aria-describedby="clear-description">
      <h2 id="clear-title">{{ clearKind === 'new' ? '开始新会话' : '清空本地历史' }}</h2><p id="clear-description">将清空当前学校与账号的本地会话记录及上下文，是否继续？</p><div class="dialog-actions"><button type="button" autofocus @click="clearDialog.close()">取消</button><button class="primary" type="button" @click="clearHistory">确认清空</button></div>
    </dialog>
  </main>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const scope = ref(null);
const personal = ref({ enabled: false, endpoint: '', model: '', has_key: false });
const school = ref({ enabled: false, model: '' });
const settings = reactive({ enabled: false, endpoint: '', model: '', api_key: '', clear_key: false });
const settingsOpen = ref(false);
const settingsButton = ref(null);
const endpointInput = ref(null);
const promptInput = ref(null);
const messageList = ref(null);
const clearDialog = ref(null);
const clearKind = ref('new');
const mode = ref('school');
const prompt = ref('');
const history = ref([]);
const memoryError = ref('');
const activeText = ref('');
const runId = ref('');
const busy = ref(false);
const loading = ref(true);
const pending = ref(false);
const saving = ref(false);
const stopping = ref(false);
const error = ref('');
const notice = ref('');
const toolStatus = ref('');
let pollTimer;
let generation = 0;
let disposed = false;
let bootstrapping = false;
let polling = false;
const requests = new Set();
const selectedModel = computed(() => mode.value === 'personal' ? personal.value.model : school.value.model);
const canSend = computed(() => scope.value && !loading.value && !pending.value && !busy.value && prompt.value.trim() && (mode.value === 'personal' ? personal.value.enabled : school.value.enabled));
const statusText = computed(() => loading.value ? '正在同步配置' : busy.value ? '任务运行中' : scope.value ? '就绪' : '请在客户端登录并解锁');

function wipe() {
  generation++;
  clearTimeout(pollTimer);
  for (const controller of requests) controller.abort();
  requests.clear();
  scope.value = null;
  personal.value = { enabled: false, endpoint: '', model: '', has_key: false };
  school.value = { enabled: false, model: '' };
  Object.assign(settings, { enabled: false, endpoint: '', model: '', api_key: '', clear_key: false });
  history.value = [];
  memoryError.value = '';
  prompt.value = '';
  activeText.value = '';
  runId.value = '';
  busy.value = false;
  pending.value = false;
  loading.value = false;
  saving.value = false;
  stopping.value = false;
  settingsOpen.value = false;
  toolStatus.value = '';
  notice.value = '';
  clearDialog.value?.close();
}

async function request(action, payload = {}) {
  const version = generation;
  const controller = new AbortController();
  requests.add(controller);
  const timeout = setTimeout(() => controller.abort(), 15000);
  try {
    const response = await fetch('/_desktop/agent', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...payload }), signal: controller.signal,
    });
    if (disposed || version !== generation) return null;
    if (response.status === 401 || response.status === 423) {
      wipe();
      error.value = response.status === 423 ? '客户端已锁定，请解锁后同步配置。' : '登录已失效，请重新登录后同步配置。';
      return null;
    }
    const result = await response.json().catch(() => null);
    if (disposed || version !== generation) return null;
    if (!response.ok) throw new Error(typeof result?.message === 'string' ? result.message : `请求失败（${response.status}）`);
    if (!result || typeof result !== 'object') throw new Error('客户端返回的数据无效。');
    return result;
  } catch (reason) {
    if (disposed || version !== generation) return null;
    throw new Error(reason.name === 'AbortError' ? '请求超时，请同步配置后重试。' : reason.message || '无法连接客户端。');
  } finally {
    clearTimeout(timeout);
    requests.delete(controller);
  }
}

function readHistory(value) {
  return Array.isArray(value) ? value.filter((item) => ['user', 'assistant'].includes(item?.role) && typeof item.text === 'string').slice(-60) : [];
}

function readStatus(value) {
  return typeof value === 'string' ? value : '';
}

function applyBootstrap(value) {
  const previousScope = scope.value ? JSON.stringify(scope.value) : '';
  const nextScope = value.scope;
  if (!nextScope || typeof nextScope !== 'object') throw new Error('当前学校与账号信息不可用。');
  if (previousScope && previousScope !== JSON.stringify(nextScope)) {
    prompt.value = '';
    activeText.value = '';
    settingsOpen.value = false;
    notice.value = '';
  }
  scope.value = nextScope;
  personal.value = { enabled: value.personal?.enabled === true, endpoint: readStatus(value.personal?.endpoint), model: readStatus(value.personal?.model), has_key: value.personal?.has_key === true };
  school.value = { enabled: value.school?.enabled === true, model: readStatus(value.school?.model) };
  Object.assign(settings, { ...personal.value, api_key: '', clear_key: false });
  history.value = readHistory(value.history);
  memoryError.value = readStatus(value.memory_error);
  const run = value.run && typeof value.run === 'object' ? value.run : null;
  busy.value = run ? run.status === 'running' : value.busy === true;
  runId.value = readStatus(run?.run_id) || readStatus(value.run_id);
  toolStatus.value = readStatus(run?.tools_status || run?.tool_status || value.tools_status || value.tool_status);
  activeText.value = run && ['running', 'error', 'cancelled'].includes(run.status) ? readStatus(run.text) : '';
  error.value = memoryError.value || (run?.status === 'error' ? readStatus(run.error) || '任务执行失败。' : '');
  notice.value = run?.status === 'cancelled' ? readStatus(run.error) || '任务已停止。' : '';
  if (mode.value === 'school' && !school.value.enabled && personal.value.enabled) mode.value = 'personal';
  if (busy.value && runId.value) schedulePoll();
  scrollMessages();
}

async function bootstrap() {
  if (disposed || bootstrapping || pending.value) return;
  bootstrapping = true;
  generation++;
  clearTimeout(pollTimer);
  for (const controller of requests) controller.abort();
  requests.clear();
  loading.value = true;
  error.value = '';
  try {
    const value = await request('bootstrap');
    if (value) applyBootstrap(value);
  } catch (reason) {
    error.value = reason.message;
  } finally {
    loading.value = false;
    bootstrapping = false;
  }
}

async function saveSettings() {
  if (pending.value || busy.value || !scope.value) return;
  pending.value = true;
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const value = await request('save', { enabled: settings.enabled, endpoint: settings.endpoint.trim(), model: settings.model.trim(), api_key: settings.api_key, clear_key: settings.clear_key });
    if (value) { applyBootstrap(value); notice.value = '个人配置已保存。'; }
  } catch (reason) {
    error.value = reason.message;
  } finally {
    settings.api_key = '';
    pending.value = false;
    saving.value = false;
  }
}

async function send() {
  if (!canSend.value) return;
  const text = prompt.value.trim();
  pending.value = true;
  error.value = '';
  notice.value = '';
  activeText.value = '';
  try {
    const value = await request('start', { mode: mode.value, prompt: text });
    if (!value) return;
    if (typeof value.run_id !== 'string' || !value.run_id) throw new Error('客户端未返回任务编号，请同步配置。');
    history.value = [...history.value, { role: 'user', text }].slice(-60);
    prompt.value = '';
    runId.value = value.run_id;
    busy.value = true;
    schedulePoll();
    scrollMessages();
  } catch (reason) {
    error.value = reason.message;
  } finally {
    pending.value = false;
  }
}

function schedulePoll() {
  clearTimeout(pollTimer);
  if (!disposed && busy.value && runId.value) pollTimer = setTimeout(poll, 500);
}

async function poll() {
  if (!busy.value || !runId.value || disposed) return;
  if (polling) { schedulePoll(); return; }
  polling = true;
  const id = runId.value;
  try {
    const value = await request('poll', { run_id: id });
    if (!value || runId.value !== id) return;
    activeText.value = readStatus(value.text);
    toolStatus.value = readStatus(value.tools_status || value.tool_status);
    if (value.status === 'running') {
      schedulePoll();
    } else if (['done', 'error', 'cancelled'].includes(value.status)) {
      busy.value = false;
      if (Array.isArray(value.history)) {
        history.value = readHistory(value.history);
        activeText.value = '';
      } else if (activeText.value) {
        history.value = [...history.value, { role: 'assistant', text: activeText.value }].slice(-60);
        activeText.value = '';
      }
      if (value.status === 'error') error.value = readStatus(value.error) || '任务执行失败。';
      if (value.status === 'cancelled') notice.value = readStatus(value.error) || '任务已停止。';
    } else throw new Error('任务状态无效，请同步配置。');
    scrollMessages();
  } catch (reason) {
    error.value = reason.message;
    notice.value = '状态同步已暂停，可停止任务或重新同步配置。';
  } finally {
    polling = false;
  }
}

async function stop() {
  if (!runId.value || pending.value) return;
  pending.value = true;
  stopping.value = true;
  error.value = '';
  try {
    const value = await request('cancel', { run_id: runId.value });
    if (value) { notice.value = '已请求停止任务。'; schedulePoll(); }
  } catch (reason) {
    error.value = reason.message;
  } finally {
    pending.value = false;
    stopping.value = false;
  }
}

function requestClear(kind) {
  if (!scope.value || busy.value || pending.value) return;
  clearKind.value = kind;
  clearDialog.value?.showModal();
}

async function clearHistory() {
  clearDialog.value?.close();
  pending.value = true;
  error.value = '';
  notice.value = '';
  try {
    const value = await request('clear');
    if (value) { history.value = readHistory(value.history); memoryError.value = ''; error.value = ''; activeText.value = ''; runId.value = ''; prompt.value = ''; notice.value = clearKind.value === 'new' ? '已开始新会话。' : '本地历史已清空。'; }
  } catch (reason) {
    error.value = reason.message;
  } finally {
    pending.value = false;
  }
}

async function reconnect() {
  if (!scope.value || pending.value || busy.value) return;
  pending.value = true;
  error.value = '';
  notice.value = '';
  try {
    const reconnected = await request('reconnect');
    if (!reconnected) return;
    const value = await request('bootstrap');
    if (value) { applyBootstrap(value); notice.value = '业务工具将在下次任务连接。'; }
  } catch (reason) {
    error.value = reason.message;
  } finally {
    pending.value = false;
  }
}

function handlePromptKeydown(event) {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
    event.preventDefault();
    send();
  }
}

async function toggleSettings() {
  if (settingsOpen.value) { closeSettings(); return; }
  settingsOpen.value = true;
  await nextTick();
  endpointInput.value?.focus();
}

function closeSettings() {
  settings.api_key = '';
  settingsOpen.value = false;
  nextTick(() => settingsButton.value?.focus());
}

async function scrollMessages() {
  await nextTick();
  if (messageList.value) messageList.value.scrollTop = messageList.value.scrollHeight;
}

function restoreVisibility() {
  if (!document.hidden && !pending.value) bootstrap();
}

onMounted(() => { bootstrap(); document.addEventListener('visibilitychange', restoreVisibility); });
onBeforeUnmount(() => { disposed = true; wipe(); document.removeEventListener('visibilitychange', restoreVisibility); });
</script>
