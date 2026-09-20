<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { ArrowUp, ChevronLeft, ChevronRight, MessageSquare, Plus, Settings, X } from '@lucide/vue';
import MarkdownIt from 'markdown-it';
import DOMPurify from 'dompurify';

const props = defineProps({ request: { type: Function, required: true } });
const state = reactive({ enabled: false, personalEnabled: false, name: '问答助手', can_manage: false, mode: 'school', threads: [], total: 0, page: 1, turns: [], threadId: 0, loading: false, sending: false, error: '', question: '', older: false });
const emptySettings = () => ({ name: '问答助手', enabled: false, endpoint: '', model: '', api_key: '', has_key: false, clear_key: false });
const settings = reactive(emptySettings());
const configurations = reactive({ school: {}, personal: {} });
const configMode = ref('personal');
const configVisible = ref(false), historyVisible = ref(false), saving = ref(false), scroll = ref(null);
const enabled = computed(() => state.mode === 'personal' ? state.personalEnabled : state.enabled);
const modeName = mode => mode === 'personal' ? '个人服务' : '学校服务';
const pending = computed(() => state.turns.some(item => ['queued', 'processing'].includes(item.status)));
const markdown = new MarkdownIt({ html: false, linkify: false, breaks: true });
markdown.renderer.rules.link_open = (tokens, index, options, env, renderer) => {
  tokens[index].attrSet('target', '_blank'); tokens[index].attrSet('rel', 'noopener noreferrer');
  return renderer.renderToken(tokens, index, options);
};
const render = value => DOMPurify.sanitize(markdown.render(value || ''), { FORBID_TAGS: ['img', 'iframe', 'form'], ADD_ATTR: ['target'] });
let poll, generation = 0, disposed = false, turnLoading = false, sendId = '';

async function loadSettings() {
  const data = await props.request('/assistant/settings');
  Object.assign(state, { enabled: data.enabled, personalEnabled: data.personal?.enabled || false, name: data.name, can_manage: data.can_manage });
  configurations.school = data.settings || {};
  configurations.personal = data.personal || {};
}
function openSettings(mode = state.mode === 'school' && state.can_manage ? 'school' : 'personal') {
  if (saving.value) return;
  configMode.value = mode;
  Object.assign(settings, emptySettings(), configurations[mode], { api_key: '', clear_key: false });
  configVisible.value = true; state.error = '';
}
function closeSettings() {
  if (saving.value) return;
  settings.api_key = ''; configVisible.value = false;
}
async function changeMode(mode) {
  if (state.mode === mode || state.sending) return;
  if (state.question.trim() && !window.confirm('切换服务将新建会话并清空未发送的问题，是否继续？')) return;
  state.mode = mode; await selectThread(0);
}
async function loadThreads(page = 1) {
  const data = await props.request(`/assistant/threads?page=${page}`);
  if (disposed) return;
  state.threads = data.items; state.total = data.total; state.page = page;
}
async function loadTurns(older = false) {
  if (!state.threadId || turnLoading) return;
  const id = state.threadId, version = generation;
  turnLoading = true;
  try {
    const before = older ? state.turns[0]?.id || 0 : 0;
    const data = await props.request(`/assistant/turns?thread_id=${id}&before=${before}`);
    if (version !== generation || disposed) return;
    if (older) state.turns = [...data.items, ...state.turns];
    else {
      const incoming = new Map(data.items.map(item => [item.id, item]));
      state.turns = [...state.turns.filter(item => !incoming.has(item.id)), ...data.items].sort((a, b) => a.id - b.id);
    }
    if (older || state.turns.length <= 20) state.older = data.items.length === 20;
  } finally { turnLoading = false; }
}
async function selectThread(id) {
  if (state.sending) return;
  if (id) state.mode = state.threads.find(thread => thread.id === id)?.provider_mode || 'school';
  turnLoading = false;
  generation++; state.threadId = id; state.turns = []; state.question = ''; state.error = ''; state.older = false; sendId = ''; historyVisible.value = false;
  state.loading = true;
  try { await loadTurns(); await toBottom(); } catch (e) { state.error = e.message; }
  finally { state.loading = false; }
}
async function toBottom() { await nextTick(); if (scroll.value) scroll.value.scrollTop = scroll.value.scrollHeight; }
async function send() {
  if (!enabled.value || state.sending || pending.value || !state.question.trim()) return;
  state.sending = true; state.error = '';
  try {
    if (!sendId) sendId = Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
    const turn = await props.request('/assistant/ask', { method: 'POST', body: JSON.stringify({ thread_id: state.threadId, request_id: sendId, question: state.question, mode: state.mode }) });
    if (disposed) return;
    state.threadId = turn.thread_id;
    state.question = ''; sendId = '';
    if (!state.turns.some(item => item.id === turn.id)) state.turns.push(turn);
    await loadThreads(); await toBottom();
  } catch (e) { state.error = e.message; }
  finally { state.sending = false; }
}
async function saveSettings() {
  if (saving.value) return;
  if (settings.clear_key && !window.confirm('清除密钥将停用此服务，是否继续？')) return;
  saving.value = true; state.error = '';
  try {
    await props.request('/assistant/save-settings', { method: 'POST', body: JSON.stringify({ ...settings, mode: configMode.value }) });
    settings.api_key = '';
    await loadSettings(); configVisible.value = false;
  } catch (e) { state.error = e.message; }
  finally { saving.value = false; }
}
onMounted(async () => {
  state.loading = true;
  try { await loadSettings(); await loadThreads(); } catch (e) { state.error = e.message; }
  finally { state.loading = false; }
  if (disposed) return;
  poll = setInterval(() => {
    if (pending.value && !document.hidden) loadTurns().catch(e => { state.error = e.message; });
  }, 3000);
});
onBeforeUnmount(() => { disposed = true; generation++; clearInterval(poll); });
</script>

<template>
  <section class="assistant-panel">
    <header>
      <button type="button" title="会话记录" aria-label="会话记录" @click="historyVisible = !historyVisible"><MessageSquare :size="18" /></button>
      <strong>{{ state.mode === 'personal' ? '个人问答助手' : state.name }}</strong><span class="assistant-status">{{ enabled ? '已启用' : '未启用' }}</span>
      <button type="button" title="新建会话" aria-label="新建会话" :disabled="state.sending" @click="selectThread(0)"><Plus :size="18" /></button>
      <button type="button" title="助手配置" aria-label="助手配置" :disabled="saving" @click="configVisible ? closeSettings() : openSettings()"><Settings :size="18" /></button>
    </header>
    <div v-if="!configVisible" class="assistant-provider" role="group" aria-label="问答服务">
      <div class="assistant-segments">
        <button v-for="mode in ['school', 'personal']" :key="mode" type="button" :aria-pressed="state.mode === mode" :disabled="state.sending" @click="changeMode(mode)">{{ modeName(mode) }}</button>
      </div>
      <button v-if="!enabled" type="button" class="assistant-config-link" @click="openSettings()">{{ state.mode === 'school' && !state.can_manage ? '配置个人服务' : '配置服务' }}</button>
    </div>
    <p v-if="state.error" class="assistant-error" role="alert">{{ state.error }}</p>
    <form v-if="configVisible" class="assistant-config" @submit.prevent="saveSettings">
      <div class="assistant-config-fields">
      <div class="assistant-config-heading"><strong>{{ modeName(configMode) }}配置</strong><button type="button" aria-label="关闭配置" :disabled="saving" @click="closeSettings"><X :size="18" /></button></div>
      <div v-if="state.can_manage" class="assistant-segments" role="group" aria-label="配置范围">
        <button v-for="mode in ['personal', 'school']" :key="mode" type="button" :aria-pressed="configMode === mode" :disabled="saving" @click="openSettings(mode)">{{ modeName(mode) }}</button>
      </div>
      <label v-if="configMode === 'school'">名称<input v-model="settings.name" :disabled="saving" maxlength="60" /></label>
      <label>接口完整地址<input v-model="settings.endpoint" :disabled="saving" type="url" maxlength="500" placeholder="https://服务域名/v1/chat/completions" autocapitalize="off" spellcheck="false" /></label>
      <label>模型<input v-model="settings.model" :disabled="saving" maxlength="120" autocomplete="off" placeholder="请输入模型名称" /></label>
      <label>API Key<input v-model="settings.api_key" :disabled="saving || settings.clear_key" type="password" maxlength="2048" autocomplete="new-password" :placeholder="settings.has_key ? '已保存，留空保留原密钥' : '请输入 API Key'" /></label>
      <label v-if="settings.has_key" class="assistant-check"><input v-model="settings.clear_key" :disabled="saving" type="checkbox" />清除已保存密钥并停用</label>
      <label class="assistant-enable"><input v-model="settings.enabled" :disabled="saving || settings.clear_key" type="checkbox" role="switch" />启用{{ modeName(configMode) }}</label>
      <small class="assistant-privacy">{{ configMode === 'personal' ? '密钥加密保存于学校服务器，仅此账号使用；问答经学校服务器转发，费用由个人 API 账户承担。' : '密钥加密保存，供本校用户调用；费用由学校 API 账户承担。' }}</small>
      </div>
      <footer class="assistant-config-actions"><button type="submit" class="assistant-primary" :disabled="saving">{{ saving ? '保存中' : '保存配置' }}</button></footer>
    </form>
    <div v-else class="assistant-workspace">
      <aside v-if="historyVisible" class="assistant-history">
        <button v-for="thread in state.threads" :key="thread.id" type="button" :class="{ selected: thread.id === state.threadId }" :disabled="state.sending" @click="selectThread(thread.id)"><span>{{ thread.title }}</span><small>{{ modeName(thread.provider_mode) }}</small></button>
        <span v-if="!state.threads.length">暂无会话</span>
        <footer><button aria-label="上一页" :disabled="state.page <= 1" @click="loadThreads(state.page - 1).catch(e => state.error = e.message)"><ChevronLeft :size="16" /></button><span>{{ state.page }}</span><button aria-label="下一页" :disabled="state.page * 20 >= state.total" @click="loadThreads(state.page + 1).catch(e => state.error = e.message)"><ChevronRight :size="16" /></button></footer>
      </aside>
      <div class="assistant-main">
        <div ref="scroll" class="assistant-turns" aria-live="polite" :aria-busy="state.loading">
          <button v-if="state.older" @click="loadTurns(true).catch(e => state.error = e.message)">更早记录</button>
          <p v-if="!state.turns.length" class="assistant-empty">{{ state.loading ? '加载中' : enabled ? '新会话' : `${modeName(state.mode)}尚未启用` }}</p>
          <article v-for="turn in state.turns" :key="turn.id" class="assistant-turn">
            <time>{{ turn.created_at }}</time><p class="assistant-question">{{ turn.question }}</p>
            <div v-if="turn.status === 'completed'" class="assistant-answer" v-html="render(turn.answer)" />
            <p v-else-if="turn.status === 'failed'" class="assistant-error">{{ turn.error_message }}<button type="button" :disabled="pending || state.sending" @click="state.question = turn.question; sendId = ''">重新填写</button></p>
            <p v-else class="assistant-wait" role="status">{{ turn.status === 'queued' ? '排队中' : '正在回答' }}<span class="assistant-pulse" /></p>
          </article>
        </div>
        <form class="assistant-compose" @submit.prevent="send">
          <textarea v-model="state.question" :disabled="!enabled || state.sending" maxlength="4000" rows="3" aria-label="问题" placeholder="输入问题" @input="sendId = ''" />
          <div><small>{{ state.question.length }}/4000</small><button type="submit" class="assistant-primary" aria-label="发送问题" title="发送问题" :disabled="!enabled || state.sending || pending || !state.question.trim()"><ArrowUp :size="19" /></button></div>
          <small class="assistant-privacy">问题经学校服务器发送至{{ state.mode === 'personal' ? '个人配置' : '学校配置' }}的 AI 服务。请勿输入密码或敏感个人信息。</small>
        </form>
      </div>
    </div>
  </section>
</template>

<style scoped>
.assistant-panel { display: flex; flex-direction: column; min-height: 420px; height: min(700px, 72vh); color: var(--text-primary, #273449); background: var(--panel-background, #fff); }
.assistant-panel header { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #e6e9ef; flex-wrap: wrap; }
.assistant-panel header strong { font-size: 16px; margin-right: auto; }
.assistant-status { font-size: 12px; color: #6b7786; }
.assistant-provider { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; padding: 10px 16px; border-bottom: 1px solid #e6e9ef; }
.assistant-segments { display: inline-flex; padding: 3px; gap: 3px; background: #f0f3f7; border-radius: 8px; width: fit-content; }
.assistant-panel .assistant-segments button { background: transparent; padding: 6px 12px; font-size: 13px; }
.assistant-panel .assistant-segments button[aria-pressed=true] { background: #fff; color: #1c5abd; box-shadow: 0 1px 3px #16294a1a; }
.assistant-panel .assistant-config-link { color: #1c5abd; background: transparent; font-size: 13px; }
.assistant-panel button:focus-visible, .assistant-panel input:focus-visible, .assistant-panel textarea:focus-visible { outline: 2px solid #2563bd; outline-offset: 2px; }
.assistant-panel button { display: inline-flex; align-items: center; justify-content: center; padding: 8px; border: 0; background: #f0f3f7; border-radius: 6px; color: inherit; cursor: pointer; gap: 6px; min-height: 34px; }
.assistant-panel button:disabled { cursor: default; opacity: .45; }
.assistant-workspace { display: flex; min-height: 0; flex: 1; position: relative; }
.assistant-history { width: 220px; flex-shrink: 0; overflow: auto; padding: 12px; border-right: 1px solid #e6e9ef; background: #fafbfc; }
.assistant-history > button { width: 100%; display: block; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-bottom: 6px; background: transparent; }
.assistant-history > button.selected { color: #1c5abd; background: #e8f0fc; }
.assistant-history > button span { display: block; overflow: hidden; text-overflow: ellipsis; }
.assistant-history > button small { display: block; margin-top: 4px; font-size: 11px; color: #768397; }
.assistant-history footer { display: flex; align-items: center; justify-content: space-between; padding-top: 12px; }
.assistant-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.assistant-turns { flex: 1; overflow: auto; padding: 16px 20px; }
.assistant-empty { text-align: center; padding: 40px 0; color: #7a8595; }
.assistant-turn { display: flex; flex-direction: column; align-items: start; gap: 10px; margin-bottom: 22px; font-size: 14px; line-height: 1.7; }
.assistant-turn time { align-self: center; font-size: 11px; color: #8591a0; }
.assistant-question { align-self: flex-end; max-width: 90%; border-radius: 8px; padding: 10px 14px; background: #edf5ee; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.assistant-answer { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
.assistant-answer :deep(pre) { overflow: auto; background: #f4f6f8; padding: 12px; border-radius: 6px; }
.assistant-answer :deep(table) { display: block; overflow: auto; border-collapse: collapse; }
.assistant-answer :deep(td), .assistant-answer :deep(th) { padding: 6px 10px; border: 1px solid #e1e5eb; }
.assistant-compose { margin: 0 16px 12px; padding: 12px; border: 1px solid #dce2e9; border-radius: 8px; }
.assistant-compose textarea { width: 100%; resize: none; border: 0; outline: none; background: transparent; color: inherit; font: inherit; box-sizing: border-box; padding: 0; }
.assistant-compose > div { display: flex; justify-content: space-between; align-items: center; color: #7a8595; }
.assistant-panel .assistant-primary { background: #2563bd; color: white; }
.assistant-privacy { display: block; margin-top: 9px; color: #7a8595; font-size: 11px; line-height: 1.5; }
.assistant-error { color: #b52c40; padding: 8px 16px; margin: 0; font-size: 13px; overflow-wrap: anywhere; }
.assistant-error button { margin-left: 8px; }
.assistant-config { width: 100%; max-width: 580px; box-sizing: border-box; display: flex; flex-direction: column; min-height: 0; flex: 1; }
.assistant-config-fields { display: grid; gap: 16px; padding: 16px 20px; overflow: auto; min-height: 0; }
.assistant-config-actions { padding: 12px 20px; border-top: 1px solid #e6e9ef; flex-shrink: 0; }
.assistant-config-actions button { width: 100%; }
.assistant-config label { display: grid; gap: 7px; font-size: 13px; }
.assistant-config input:not([type=checkbox]) { width: 100%; box-sizing: border-box; min-height: 40px; border: 1px solid #dce2e9; border-radius: 6px; padding: 8px 12px; font: inherit; }
.assistant-config-heading, .assistant-config .assistant-enable { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.assistant-config .assistant-enable { justify-content: flex-start; }
.assistant-config .assistant-check { display: flex; align-items: center; gap: 8px; }
.assistant-check input { margin: 0; width: 16px; height: 16px; accent-color: #2563bd; }
.assistant-config input:disabled { opacity: .55; }
.assistant-enable input { appearance: none; width: 36px; height: 22px; border-radius: 11px; background: #a5adb9; position: relative; cursor: pointer; }
.assistant-enable input::after { content: ''; position: absolute; width: 16px; height: 16px; border-radius: 50%; background: white; top: 3px; left: 3px; }
.assistant-enable input:checked { background: #2563bd; }
.assistant-enable input:checked::after { left: 17px; }
.assistant-wait { color: #768397; }
.assistant-pulse { display: inline-block; width: 6px; height: 6px; background: #6383b0; border-radius: 50%; margin-left: 8px; animation: assistant-pulse 1.4s ease-in-out infinite; }
@keyframes assistant-pulse { 50% { opacity: .25; } }
@media (prefers-reduced-motion: reduce) { .assistant-pulse { animation: none; } }
@media (max-width: 640px) { .assistant-history { position: absolute; inset: 0 20% 0 0; width: auto; z-index: 2; box-shadow: 4px 0 14px #1b294015; } .assistant-turns { padding: 12px; } .assistant-compose { margin: 0 8px 8px; } .assistant-panel { height: 68dvh; } }
</style>
