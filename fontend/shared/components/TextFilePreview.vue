<template>
  <section class="text-preview">
    <div class="text-toolbar">
      <select v-model="encoding" aria-label="文本编码">
        <option value="auto">自动编码</option><option value="utf-8">UTF-8</option><option value="gb18030">GB18030 / GBK</option><option value="utf-16le">UTF-16 LE</option><option value="utf-16be">UTF-16 BE</option><option value="windows-1252">Windows-1252</option>
      </select>
      <span class="text-meta">{{ decoded.encoding.toUpperCase() }}</span>
      <div v-if="kind !== 'text'" class="text-tabs" role="tablist" aria-label="查看方式">
        <button role="tab" :aria-selected="!source" :class="{ active: !source }" @click="source = false">{{ kind === 'csv' ? '表格' : '排版' }}</button>
        <button role="tab" :aria-selected="source" :class="{ active: source }" @click="source = true">原文</button>
      </div>
      <label v-if="plain" class="text-wrap"><input v-model="wrap" type="checkbox">自动换行</label>
      <form v-if="plain" class="text-search" @submit.prevent="findNext">
        <input v-model="query" aria-label="搜索文本" placeholder="搜索文本" maxlength="100" @input="foundLine = -1; searchMessage = ''">
        <button type="submit" :disabled="!query.trim()" title="查找下一处" aria-label="查找下一处"><Search :size="17" /></button>
      </form>
    </div>
    <p v-if="decoded.error" class="text-notice" role="alert">{{ decoded.error }}</p>
    <template v-else>
      <p v-if="decoded.truncated" class="text-notice">仅预览前 50 万字符，请下载查看完整内容。</p>
      <template v-if="plain">
        <div ref="scrollArea" class="text-content" :class="{ 'is-wrapped': wrap }">
          <div v-for="(line, index) in currentLines" :key="index" class="text-line" :class="{ 'is-found': startLine + index === foundLine }">
            <span class="text-line-number" aria-hidden="true">{{ startLine + index + 1 }}</span><code>{{ line || ' ' }}</code>
          </div>
        </div>
        <footer class="text-pager"><span>{{ lines.length }} 行</span><span role="status">{{ searchMessage }}</span><div><button :disabled="page <= 1" title="上一页" aria-label="上一页" @click="page--"><ChevronLeft :size="18" /></button><span>{{ page }} / {{ pages }}</span><button :disabled="page >= pages" title="下一页" aria-label="下一页" @click="page++"><ChevronRight :size="18" /></button></div></footer>
      </template>
      <iframe v-else-if="kind === 'markdown'" :srcdoc="markdownHtml" sandbox="" referrerpolicy="no-referrer" title="Markdown 文档预览" />
      <div v-else class="text-table">
        <p v-if="table.truncated" class="text-notice">仅预览前 2000 行、100 列及最多 10 万个单元格，请下载查看完整内容。</p>
        <p v-if="table.warning" class="text-notice" role="status">{{ table.warning }}</p>
        <table v-if="table.rows.length"><tbody><tr v-for="(row, index) in table.rows" :key="index"><th scope="row">{{ index + 1 }}</th><td v-for="(cell, column) in row" :key="column">{{ cell }}</td></tr></tbody></table>
        <p v-else class="text-notice">空表格</p>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import { Search, ChevronLeft, ChevronRight } from '@lucide/vue';
import MarkdownIt from 'markdown-it';
import DOMPurify from 'dompurify';
import Papa from 'papaparse';
import { previewTextContent } from '../textPreview';

const props = defineProps({ bytes: { type: Uint8Array, required: true }, kind: { type: String, required: true }, extension: { type: String, default: '' } });
const encoding = ref('auto'), source = ref(false), wrap = ref(true), page = ref(1), query = ref(''), foundLine = ref(-1), searchMessage = ref(''), scrollArea = ref();
const plain = computed(() => props.kind === 'text' || source.value);
const decoded = computed(() => {
  try { return previewTextContent(props.bytes, encoding.value); }
  catch (error) { return { text: '', encoding: '', error: error.message }; }
});
const lines = computed(() => decoded.value.text.split('\n'));
const pages = computed(() => Math.max(1, Math.ceil(lines.value.length / 200)));
const startLine = computed(() => (page.value - 1) * 200);
const currentLines = computed(() => lines.value.slice(startLine.value, startLine.value + 200));
const markdown = new MarkdownIt({ html: false, linkify: false, typographer: false });
const markdownHtml = computed(() => {
  if (props.kind !== 'markdown' || source.value || decoded.value.error) return '';
  const content = DOMPurify.sanitize(markdown.render(decoded.value.text), { FORBID_TAGS: ['img', 'a', 'form', 'iframe', 'object', 'embed', 'style'], FORBID_ATTR: ['src', 'srcset', 'style'] });
  return `<html><head><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'"><style>body{margin:0;padding:24px;box-sizing:border-box;color:#273243;background:white;font:15px/1.8 system-ui;overflow-wrap:anywhere;letter-spacing:0}h1{font-size:26px}h2{font-size:22px}h3{font-size:18px}pre{overflow:auto;background:#f2f4f7;padding:16px;border-radius:6px}code{font-family:ui-monospace,monospace}blockquote{margin:16px 0;padding:4px 16px;border-left:3px solid #93b4dd;color:#5c6572}table{border-collapse:collapse;display:block;overflow:auto}th,td{border:1px solid #dfe4eb;padding:8px 12px}hr{border:0;border-top:1px solid #dfe4eb}@media(max-width:600px){body{padding:16px}}</style></head><body>${content}</body></html>`;
});
const table = computed(() => {
  const rows = []; let cells = 0, truncated = false, warning = '';
  if (props.kind !== 'csv' || source.value || decoded.value.error) return { rows, truncated, warning };
  Papa.parse(decoded.value.text, {
    delimiter: props.extension === 'tsv' ? '\t' : '', skipEmptyLines: 'greedy',
    step(result, parser) {
      if (result.errors.some(error => error.code !== 'UndetectableDelimiter')) warning = '部分字段格式不规范，以下为可读取内容，请核对原文件。';
      const row = result.data;
      if (rows.length >= 2000 || cells + Math.min(row.length, 100) > 100000) { truncated = true; parser.abort(); return; }
      if (row.length > 100) truncated = true;
      rows.push(row.slice(0, 100)); cells += Math.min(row.length, 100);
    },
  });
  return { rows, truncated, warning };
});

/** 在已载入文本内定位下一条匹配行。 */
async function findNext() {
  const term = query.value.trim().toLocaleLowerCase();
  if (!term) return;
  for (let offset = 1; offset <= lines.value.length; offset++) {
    const index = (foundLine.value + offset) % lines.value.length;
    if (!lines.value[index].toLocaleLowerCase().includes(term)) continue;
    foundLine.value = index; page.value = Math.floor(index / 200) + 1;
    searchMessage.value = `第 ${index + 1} 行`;
    await nextTick(); scrollArea.value?.querySelector('.is-found')?.scrollIntoView({ block: 'center', behavior: 'instant' }); return;
  }
  searchMessage.value = '未找到';
}
watch(encoding, () => { page.value = 1; foundLine.value = -1; searchMessage.value = ''; });
watch(page, async () => { await nextTick(); scrollArea.value?.scrollTo(0, 0); });
</script>

<style scoped>
.text-preview{height:100%;min-height:0;display:flex;flex-direction:column;background:white;color:#273243;font-size:13px;letter-spacing:0}.text-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:10px 16px;border-bottom:1px solid #e3e7ed}.text-toolbar select,.text-search input{height:34px;min-width:0;box-sizing:border-box;border:1px solid #d8dee7;border-radius:5px;background:white;color:inherit;font:inherit;padding:0 10px}.text-meta{font-size:11px;color:#687487}.text-tabs{display:flex;gap:10px}.text-preview button{display:inline-flex;align-items:center;justify-content:center;height:34px;min-width:34px;border:0;border-radius:4px;padding:0 8px;color:inherit;background:transparent;cursor:pointer;font:inherit}.text-preview button:disabled{opacity:.35;cursor:default}.text-preview button:hover:not(:disabled){background:#edf2f8}.text-tabs button{border-radius:0;border-bottom:2px solid transparent}.text-tabs .active{color:#1c65b5;border-bottom-color:#1c65b5}.text-wrap{display:flex;align-items:center;gap:4px;white-space:nowrap}.text-search{display:flex;gap:4px;margin-left:auto}.text-search input{width:160px}.text-content{flex:1;min-height:0;overflow:auto;padding:12px 0;background:#fcfdff;overscroll-behavior:contain}.text-line{display:flex;min-width:100%;width:max-content;font:13px/1.8 ui-monospace,SFMono-Regular,Consolas,monospace}.text-line-number{flex:0 0 5em;box-sizing:border-box;text-align:right;padding:0 12px;color:#8b95a3;user-select:none;border-right:1px solid #e7ebf0}.text-line code{display:block;padding:0 16px;white-space:pre;tab-size:4;font:inherit}.is-wrapped .text-line{width:100%}.is-wrapped code{min-width:0;flex:1;white-space:pre-wrap;overflow-wrap:anywhere}.is-found{background:#fff1c7}.text-pager{display:flex;align-items:center;gap:16px;border-top:1px solid #e3e7ed;padding:8px 16px;color:#657083}.text-pager>div{margin-left:auto;display:flex;align-items:center;gap:8px}.text-notice{margin:0;padding:12px 16px;color:#78541b;background:#fff7e7;line-height:1.6}.text-preview iframe{flex:1;min-height:0;width:100%;border:0;background:white}.text-table{flex:1;overflow:auto;min-height:0}.text-table table{border-collapse:collapse;font-size:13px}.text-table th,.text-table td{border:1px solid #dfe4eb;min-width:90px;max-width:420px;padding:8px 12px;white-space:pre-wrap;overflow-wrap:anywhere}.text-table th{min-width:36px;color:#758196;background:#f2f5f8;position:sticky;left:0}@media(max-width:600px){.text-toolbar{padding:8px 12px;gap:8px}.text-search{width:100%;margin:0}.text-search input{flex:1}.text-line-number{flex-basis:4em;padding:0 8px}.text-line code{padding:0 10px}.text-pager{gap:8px;padding:8px 12px;font-size:12px}}
</style>
