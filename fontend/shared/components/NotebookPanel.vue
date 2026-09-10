<template>
  <section class="notebook" :class="{ 'note-mobile-detail': mobileDetail }">
    <aside class="note-list">
      <header><strong>记事本</strong><button title="新建笔记" :disabled="busy || trash" @click="createNote"><Plus :size="20" /></button></header>
      <div v-if="recovery" class="note-recovery"><span>有未保存的本机草稿</span><div><button @click="restoreDraft">恢复编辑</button><button @click="recovery = null">暂不恢复</button></div></div>
      <form class="note-search" @submit.prevent="load(1)"><Search :size="17" /><input v-model="keyword" placeholder="搜索笔记" /><button title="搜索"><ArrowRight :size="17" /></button></form>
      <div v-if="error && !mobileDetail" class="note-error" role="alert">{{ error }}<button title="关闭提示" @click="error = ''"><X :size="16" /></button></div>
      <nav><button :class="{ selected: !trash }" @click="setTrash(false)">全部</button><button :class="{ selected: trash }" @click="setTrash(true)">回收站</button><span>{{ pagination.total }} 篇</span></nav>
      <div class="note-rows" :aria-busy="loading">
        <button v-for="row in items" :key="row.id" class="note-row" :class="{ active: current?.id === row.id }" @click="selectNote(row)">
          <strong>{{ row.title }}</strong><time>{{ row.updated_at }}</time><p>{{ row.preview || '暂无内容' }}</p>
        </button>
        <p v-if="!items.length" class="note-empty">{{ loading ? '正在加载…' : trash ? '回收站为空' : '暂无笔记' }}</p>
      </div>
      <footer><button title="上一页" :disabled="pagination.page <= 1 || loading" @click="load(pagination.page - 1)"><ChevronLeft :size="18" /></button><span>{{ pagination.page }} / {{ Math.max(1, Math.ceil(pagination.total / pagination.page_size)) }}</span><button title="下一页" :disabled="pagination.page * pagination.page_size >= pagination.total || loading" @click="load(pagination.page + 1)"><ChevronRight :size="18" /></button></footer>
    </aside>
    <main class="note-main">
      <div v-if="error" class="note-error" role="alert">{{ error }}<button title="关闭提示" @click="error = ''"><X :size="16" /></button></div>
      <template v-if="current">
        <header class="note-editor-head">
          <button class="note-back" title="返回笔记列表" @click="back"><ChevronLeft :size="20" /></button>
          <input v-model="current.title" maxlength="180" placeholder="未命名笔记" :readonly="Boolean(current.deleted_at)" aria-label="笔记标题" />
          <span class="note-save-state" aria-live="polite">{{ busy ? '保存中…' : dirty ? '待保存' : '已保存' }}</span>
          <button v-if="current.id" title="重新读取已保存内容" :disabled="busy" @click="reloadNote"><RefreshCw :size="18" /></button>
          <template v-if="!current.deleted_at"><button title="保存笔记" class="note-primary" :disabled="busy || !dirty" @click="save"><Save :size="19" /></button><button title="移入回收站" :disabled="busy || !current.id" @click="change('delete')"><Trash2 :size="19" /></button></template>
          <template v-else><button title="恢复笔记" :disabled="busy" @click="change('restore')"><RotateCcw :size="19" /></button><button title="永久删除" :disabled="busy" @click="change('purge')"><Trash2 :size="19" /></button></template>
        </header>
        <div class="note-editor-tools"><nav><button v-for="item in modes" :key="item.key" :class="{ selected: mode === item.key }" @click="mode = item.key">{{ item.name }}</button></nav><span>{{ current.content_md.length }} 字符</span><button v-if="mode !== 'preview' && !current.deleted_at" title="插入标题" @click="insert('## ')"><Heading2 :size="17" /></button><button v-if="mode !== 'preview' && !current.deleted_at" title="插入待办事项" @click="insert('- [ ] ')"><ListChecks :size="17" /></button></div>
        <div class="note-editor-body" :class="`note-mode-${mode}`">
          <textarea v-if="mode !== 'preview'" ref="editor" v-model="current.content_md" :readonly="Boolean(current.deleted_at)" placeholder="开始记录…" aria-label="Markdown 内容" spellcheck="false" />
          <article v-if="mode !== 'edit'" class="note-rendered" v-html="rendered" />
        </div>
        <footer class="note-meta">{{ current.updated_at ? `最后保存 ${current.updated_at}` : '新笔记' }}<span v-if="dirty">编辑内容已暂存于本机</span></footer>
      </template>
      <div v-else class="note-welcome"><NotebookPen :size="44" /><strong>{{ trash ? '回收站' : '记录此刻的想法' }}</strong><button v-if="!trash" class="note-new" @click="createNote">新建笔记</button></div>
    </main>
  </section>
</template>

<script setup>
import { claimNoteDraft } from '../noteDrafts';
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import MarkdownIt from 'markdown-it';
import DOMPurify from 'dompurify';
import { Plus, Search, ArrowRight, ChevronLeft, ChevronRight, Save, Trash2, RotateCcw, RefreshCw, X, Heading2, ListChecks, NotebookPen } from '@lucide/vue';

const props = defineProps({ request: { type: Function, required: true }, sessionKey: { type: String, required: true } });
const items = ref([]), keyword = ref(''), trash = ref(false), loading = ref(false), busy = ref(false), error = ref('');
const current = ref(null), baseline = ref(''), mobileDetail = ref(false), mode = ref('edit'), editor = ref(null);
const pagination = ref({ page: 1, page_size: 20, total: 0 });
const modes = [{ key: 'edit', name: '编辑' }, { key: 'preview', name: '预览' }, { key: 'split', name: '对照' }];
const markdown = new MarkdownIt({ html: false, linkify: true, breaks: true });
markdown.renderer.rules.link_open = (tokens, index, options, env, self) => {
  tokens[index].attrSet('target', '_blank');
  tokens[index].attrSet('rel', 'noopener noreferrer');
  return self.renderToken(tokens, index, options);
};
const rendered = computed(() => DOMPurify.sanitize(markdown.render(current.value?.content_md || ''), { FORBID_TAGS: ['img', 'iframe', 'form'], ADD_ATTR: ['target'] }));
const snapshot = () => JSON.stringify([current.value?.title || '', current.value?.content_md || '']);
const dirty = computed(() => Boolean(current.value && !current.value.deleted_at && snapshot() !== baseline.value));
const localDraft = claimNoteDraft(props.sessionKey);
const recovery = ref(localDraft.draft);
const draftKey = () => localDraft.key;
let listSequence = 0, disposed = false;

/** 获取分页摘要，不让旧请求覆盖新筛选。 */
async function load(page = 1) {
  const sequence = ++listSequence;
  loading.value = true;
  try {
    const data = await props.request(`/note/list?${new URLSearchParams({ page, page_size: 20, keyword: keyword.value, trash: trash.value })}`);
    if (!disposed && sequence === listSequence) { items.value = data.items; pagination.value = data.pagination; }
  } catch (e) { if (!disposed) error.value = e.message; }
  finally { if (sequence === listSequence) loading.value = false; }
}

/** 在切换或关闭之前处理未保存内容。 */
async function beforeLeave() {
  if (busy.value) return false;
  if (!dirty.value) return true;
  if (!window.confirm('当前笔记尚未保存，保存后继续？取消将保留当前编辑。')) return false;
  return save();
}

/** 新建笔记只在首次保存时写入服务器。 */
async function createNote() {
  if (!(await beforeLeave())) return;
  current.value = { id: null, uuid: crypto.randomUUID(), title: '', content_md: '', revision: 0 };
  baseline.value = snapshot();
  mobileDetail.value = true;
  await nextTick(); editor.value?.focus();
}

/** 读取属于当前账号的完整内容。 */
async function selectNote(row) {
  if (!(await beforeLeave())) return;
  busy.value = true; error.value = '';
  try { const data = await props.request(`/note/detail?id=${row.id}`); current.value = data.item; baseline.value = snapshot(); mobileDetail.value = true; }
  catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}

/** 放弃本机修改并读取服务器最新修订。 */
async function reloadNote() {
  if (busy.value || !current.value?.id) return;
  if (dirty.value && !window.confirm('重新读取将放弃本窗口尚未保存的修改，确认继续？')) return;
  busy.value = true; error.value = '';
  try {
    const { item } = await props.request(`/note/detail?id=${current.value.id}`);
    current.value = item; baseline.value = snapshot();
    try { localDraft.clear(); } catch { /* 不影响服务器读取。 */ }
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}

/** 保存当前快照，编辑期间的新输入不会被响应覆盖。 */
async function save() {
  if (busy.value || !current.value) return false;
  const payload = { ...current.value }, sent = snapshot();
  busy.value = true; error.value = '';
  try {
    const { item } = await props.request('/note/save', { method: 'POST', body: JSON.stringify(payload) });
    if (disposed) return true;
    if (snapshot() === sent) { current.value = item; baseline.value = snapshot(); }
    else { current.value.id = item.id; current.value.revision = item.revision; current.value.updated_at = item.updated_at; baseline.value = sent; }
    if (!dirty.value) { try { localDraft.clear(); } catch { /* 服务器保存已完成。 */ } }
    await load(pagination.value.page);
    return true;
  } catch (e) { if (!disposed) error.value = e.message; return false; }
  finally { busy.value = false; }
}

/** 变更回收站状态，永久删除必须显式确认。 */
async function change(action) {
  if (busy.value || !current.value?.id) return;
  const prompts = { delete: '将这篇笔记移入回收站？未保存的更改不会提交。', restore: '恢复这篇笔记？', purge: '永久删除这篇笔记？此操作无法恢复。' };
  if (!window.confirm(prompts[action])) return;
  busy.value = true; error.value = '';
  try {
    await props.request(`/note/${action}`, { method: 'POST', body: JSON.stringify({ id: current.value.id, revision: current.value.revision }) });
    current.value = null; baseline.value = ''; mobileDetail.value = false; try { localDraft.clear(); } catch { /* 不影响服务器操作。 */ } await load(1);
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}

async function setTrash(value) { if (value === trash.value || !(await beforeLeave())) return; trash.value = value; current.value = null; mobileDetail.value = false; await load(1); }
async function back() { if (await beforeLeave()) mobileDetail.value = false; }
function insert(value) { const el = editor.value; if (!el) return; const at = el.selectionStart; current.value.content_md = current.value.content_md.slice(0, at) + value + current.value.content_md.slice(at); nextTick(() => { el.focus(); el.setSelectionRange(at + value.length, at + value.length); }); }
function unload(event) { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } }

/** 用户主动恢复草稿，保留其他窗口的本机副本。 */
async function restoreDraft() {
  if (!recovery.value || !(await beforeLeave())) return;
  localDraft.accept();
  current.value = recovery.value.item; baseline.value = recovery.value.baseline;
  recovery.value = null; mobileDetail.value = true;
}

watch(() => [current.value?.title, current.value?.content_md], () => {
  if (dirty.value) { try { localStorage.setItem(draftKey(), JSON.stringify({ item: current.value, baseline: baseline.value })); } catch { error.value = '本机草稿存储不可用，请及时保存到服务器'; } }
});
onMounted(() => {
  load(1); window.addEventListener('beforeunload', unload);
});
onBeforeUnmount(() => { disposed = true; localDraft.release(); window.removeEventListener('beforeunload', unload); });
defineExpose({ beforeLeave });
</script>

<style scoped>
.note-recovery{margin:6px 14px;padding:10px;background:#fff5cf;border-radius:5px;font-size:12px;color:#715c21}.note-recovery>div{display:flex;gap:8px;margin-top:5px}
.notebook{display:grid;grid-template-columns:260px minmax(0,1fr);height:100%;min-height:420px;background:#fff;color:#25282c;overflow:hidden;letter-spacing:0}.notebook *{box-sizing:border-box}.notebook button{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:5px;background:transparent;color:inherit;cursor:pointer;min-height:34px;padding:6px 9px;font:inherit}.notebook button:hover{background:#edf0f3}.notebook button:disabled{opacity:.45;cursor:default}.note-list{display:flex;flex-direction:column;min-height:0;border-right:1px solid #e7e8ea;background:#f6f7f9}.note-list header{display:flex;justify-content:space-between;align-items:center;padding:18px 16px 10px}.note-list header strong{font-size:20px}.note-search{display:flex;align-items:center;gap:7px;margin:6px 14px;background:white;border:1px solid #dce0e6;border-radius:5px;padding-left:8px}.note-search input{min-width:0;flex:1;border:0;background:transparent;height:34px;outline:0}.notebook nav{display:flex;align-items:center;gap:8px}.note-list nav{padding:8px 14px}.note-list nav span{margin-left:auto;font-size:12px;color:#737a83}.notebook nav .selected{color:#2461ce;border-radius:0;border-bottom:2px solid #2461ce}.note-rows{overflow:auto;flex:1;min-height:0}.notebook .note-row{display:flex;align-items:flex-start;flex-direction:column;gap:6px;text-align:left;width:100%;padding:16px 18px;border-radius:0;border-bottom:1px solid #e7e8ea}.note-row.active{background:#fff3b7}.note-row strong{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.note-row time{font-size:11px;color:#7a8089}.note-row p{font-size:13px;color:#737984;margin:0;max-width:100%;line-height:1.6;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow-wrap:anywhere}.note-list footer{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:12px}.note-empty{padding:30px;text-align:center;color:#9299a1}.note-main{min-width:0;min-height:0;display:flex;flex-direction:column}.note-editor-head{display:flex;gap:8px;align-items:center;padding:18px 20px 12px;border-bottom:1px solid #edf0f3}.note-editor-head input{min-width:0;flex:1;border:0;background:transparent;font-size:21px;font-weight:600;outline:0;height:40px}.note-save-state{font-size:11px;white-space:nowrap;color:#89909a}.notebook .note-primary{color:#fff;background:#356ad6}.note-editor-tools{display:flex;gap:6px;align-items:center;padding:8px 20px;border-bottom:1px solid #edf0f3;font-size:13px}.note-editor-tools>span{margin-left:auto;color:#8b9097;font-size:12px}.note-editor-body{flex:1;min-height:0;display:grid;grid-template-columns:minmax(0,1fr);overflow:hidden}.note-editor-body.note-mode-split{grid-template-columns:1fr 1fr}.note-editor-body textarea{width:100%;height:100%;min-height:220px;border:0;resize:none;padding:22px 24px;line-height:1.85;font:14px/1.85 ui-monospace,SFMono-Regular,monospace;color:#3a414b;outline:0;background:#fff}.note-rendered{overflow:auto;padding:8px 24px;overflow-wrap:anywhere;line-height:1.85;min-width:0}.note-rendered :deep(pre){overflow:auto;background:#f3f5f7;padding:12px;border-radius:5px}.note-rendered :deep(table){border-collapse:collapse;max-width:100%;display:block;overflow:auto}.note-rendered :deep(td),.note-rendered :deep(th){border:1px solid #dce1e7;padding:6px 10px}.note-mode-split .note-rendered{border-left:1px solid #e7e8ea}.note-meta{padding:10px 20px;border-top:1px solid #edf0f3;color:#9298a0;font-size:11px;display:flex;justify-content:space-between;gap:10px}.note-welcome{margin:auto;display:flex;flex-direction:column;gap:20px;align-items:center;color:#a1a7b0}.note-welcome strong{font-weight:500;color:#737b87}.notebook .note-new{color:#fff;background:#356ad6;padding:8px 20px}.note-error{background:#fff0ed;color:#ad3925;padding:10px 16px;display:flex;gap:8px;align-items:center;font-size:13px}.note-error button{margin-left:auto;flex-shrink:0}.notebook .note-back{display:none}@media(max-width:700px){.notebook{display:block;height:calc(100dvh - 180px);min-height:360px}.note-list{height:100%;border:0}.note-main{display:none;height:100%}.note-mobile-detail .note-list{display:none}.note-mobile-detail .note-main{display:flex}.notebook .note-back{display:inline-flex}.note-editor-head{padding:10px;gap:4px}.note-editor-head input{font-size:17px}.note-save-state{display:none}.note-editor-tools{padding:6px 12px}.note-meta{padding:8px 12px}.note-editor-body textarea{padding:18px 16px}.note-rendered{padding:6px 16px}.note-mode-split{grid-template-columns:1fr!important;grid-template-rows:1fr 1fr}.note-mode-split .note-rendered{border-left:0;border-top:1px solid #e7e8ea}}
</style>
