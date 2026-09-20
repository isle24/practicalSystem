<template>
  <div class="file-preview-mask" @click.self="emit('close')">
    <section ref="dialog" class="file-preview" role="dialog" aria-modal="true" :aria-label="name" tabindex="-1" @keydown="keyboard">
      <header><strong>{{ name || '文件预览' }}</strong><div class="preview-actions">
        <a v-if="originalUrl" :href="originalUrl" target="_blank" rel="noopener noreferrer" :download="name" title="下载原文件" aria-label="下载原文件"><Download :size="19" /></a>
        <button type="button" title="关闭" aria-label="关闭预览" @click="emit('close')"><X :size="20" /></button>
      </div></header>
      <nav v-if="!loading && !error && ['image', 'pdf'].includes(kind)" class="preview-tools" aria-label="预览工具">
        <template v-if="kind === 'pdf'"><button :disabled="page <= 1 || rendering" title="上一页" @click="page--"><ChevronLeft :size="18" /></button><span>{{ page }} / {{ pages }}</span><button :disabled="page >= pages || rendering" title="下一页" @click="page++"><ChevronRight :size="18" /></button></template>
        <button :disabled="scale <= .25 || rendering" title="缩小" @click="zoom(-.25)"><ZoomOut :size="18" /></button><span>{{ Math.round(scale * 100) }}%</span><button :disabled="scale >= 3 || rendering" title="放大" @click="zoom(.25)"><ZoomIn :size="18" /></button><button title="适应窗口" @click="scale = 1"><Maximize :size="18" /></button>
        <button v-if="kind === 'image'" title="旋转" @click="rotation = (rotation + 90) % 360"><RotateCw :size="18" /></button>
        <form v-if="kind === 'pdf'" class="preview-search" @submit.prevent="search"><input v-model="query" aria-label="搜索 PDF 文字" placeholder="搜索文档" maxlength="100"><button :disabled="searching || !query.trim()" title="查找下一处"><Search :size="18" /></button><span role="status">{{ searchStatus }}</span></form>
      </nav>
      <nav v-if="kind === 'xlsx' && sheets.length" class="preview-sheets" aria-label="工作表"><button v-for="(sheet, index) in sheets" :key="index" :class="{ active: sheetIndex === index }" @click="sheetIndex = index">{{ sheet.name }}</button></nav>
      <div ref="body" class="preview-body" :aria-busy="loading || rendering">
        <p v-if="loading" class="preview-state" role="status">正在读取文件…</p>
        <p v-else-if="error" class="preview-state" role="alert">{{ error }}</p>
        <template v-else>
          <div v-if="kind === 'image'" class="preview-image"><img :src="url" :alt="name" :style="{ transform: `scale(${scale}) rotate(${rotation}deg)` }" @error="error = '图片读取失败，请下载查看'"></div>
          <video v-else-if="kind === 'video'" :src="url" controls playsinline preload="metadata" @error="mediaError" />
          <audio v-else-if="kind === 'audio'" :src="url" controls preload="metadata" @error="mediaError" />
          <canvas v-else-if="kind === 'pdf'" ref="canvas" :aria-label="`第 ${page} 页`" />
          <iframe v-else-if="kind === 'docx'" :srcdoc="documentHtml" sandbox="" referrerpolicy="no-referrer" title="Word 文档预览" />
          <TextFilePreview v-else-if="textBytes" :bytes="textBytes" :kind="kind" :extension="extension" />
          <div v-else-if="kind === 'xlsx'" class="preview-spreadsheet"><p v-if="sheets[sheetIndex]?.truncated">仅预览前 2000 行、100 列，请下载查看完整表格。</p><table><tbody><tr v-for="(row, index) in sheets[sheetIndex]?.rows" :key="index"><th>{{ index + 1 }}</th><td v-for="(cell, column) in row" :key="column">{{ cell }}</td></tr></tbody></table><p v-if="!sheets[sheetIndex]?.rows.length">空工作表</p></div>
          <p v-else class="preview-state">此格式暂不支持内置预览，请下载原文件。</p>
        </template>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, shallowRef, defineAsyncComponent, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { Download, X, ChevronLeft, ChevronRight, ZoomIn, ZoomOut, RotateCw, Maximize, Search } from '@lucide/vue';
import DOMPurify from 'dompurify';
import formats from '../previewFormats.json';
const TextFilePreview = defineAsyncComponent(() => import('./TextFilePreview.vue'));
const types = Object.fromEntries(Object.entries(formats).flatMap(([type, extensions]) => extensions.map(ext => [ext, type])));
const props = defineProps({ file: { type: Object, required: true }, adapter: { type: Object, required: true } });
const emit = defineEmits(['close']);
const dialog = ref(), body = ref(), canvas = ref();
const name = ref(''), url = ref(''), kind = ref(''), loading = ref(true), error = ref('');
const originalUrl = ref('');
const textBytes = shallowRef(null), extension = ref('');
const scale = ref(1), rotation = ref(0), page = ref(1), pages = ref(0), rendering = ref(false);
const query = ref(''), searchStatus = ref(''), searching = ref(false), documentHtml = ref('');
const sheets = ref([]), sheetIndex = ref(0);
const controller = new AbortController();
const limit = 30 * 1024 * 1024;
let pdfTask, pdf, renderTask, disposed = false, renderSequence = 0, resizeObserver, spreadsheetWorker;
const timeout = setTimeout(() => { if (loading.value) { controller.abort(); spreadsheetWorker?.terminate(); error.value = '读取超时，请重新打开或下载原文件'; loading.value = false; } }, 60000);

/** 读取受限大小的文件内容。 */
async function readBytes(maxBytes = limit) {
  const response = await fetch(url.value, { credentials: new URL(url.value).origin === new URL(props.adapter.backendUrl('/')).origin ? 'include' : 'omit', signal: controller.signal });
  if (!response.ok) throw new Error(response.status === 401 || response.status === 403 ? '没有文件访问权限或登录已过期' : '文件读取失败');
  const sizeError = `文档超过 ${maxBytes / 1024 / 1024} MB，请下载查看`;
  if (Number(response.headers.get('content-length')) > maxBytes) { await response.body?.cancel(); throw new Error(sizeError); }
  const reader = response.body.getReader(), chunks = [];
  let size = 0;
  while (true) { const { done, value } = await reader.read(); if (done) break; size += value.byteLength; if (size > maxBytes) { await reader.cancel(); throw new Error(sizeError); } chunks.push(value); }
  const bytes = new Uint8Array(size); let offset = 0;
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
  return bytes;
}

/** 载入元数据并按格式延迟解析。 */
async function load() {
  try {
    let file = props.file;
    const id = Number(file.file_id || file.id || 0);
    if (id) { const data = await props.adapter.request(`/file/info?file_id=${id}`, { signal: controller.signal }); file = data.file || data; }
    if (disposed) return;
    name.value = file.download_name || file.name || file.file_name || '文件预览';
    const address = props.adapter.backendUrl(file.url || file.file_url || '');
    if (!address || !['http:', 'https:'].includes(new URL(address).protocol)) throw new Error('文件地址无效');
    originalUrl.value = address;
    url.value = id && window.__PRACTICAL_DESKTOP__?.previewFileUrl && new URL(address).origin === new URL(props.adapter.backendUrl('/')).origin
      ? new URL(window.__PRACTICAL_DESKTOP__.previewFileUrl(id), window.location.origin).href : address;
    const ext = String(file.blob?.ext || name.value.split('.').pop() || '').toLowerCase();
    const suffix = new URL(address).pathname.split('.').pop().toLowerCase();
    extension.value = types[ext] ? ext : suffix;
    kind.value = types[ext] || types[suffix] || '';
    if (['text', 'markdown', 'csv'].includes(kind.value)) {
      if (Number(file.blob?.size || file.size) > 5 * 1024 * 1024) throw new Error('文本超过 5 MB，请下载查看');
      const bytes = await readBytes(5 * 1024 * 1024);
      if (!disposed) textBytes.value = bytes;
    }
    if (['pdf', 'docx', 'xlsx'].includes(kind.value)) {
      if (Number(file.blob?.size || file.size) > limit) throw new Error('文档超过 30 MB，请下载查看');
      const bytes = await readBytes();
      if (disposed) return;
      if (kind.value === 'pdf') {
        const engine = await import('pdfjs-dist');
        const worker = await import('pdfjs-dist/build/pdf.worker.min.mjs?url');
        if (disposed) return;
        engine.GlobalWorkerOptions.workerSrc = worker.default;
        const resources = new URL(`${import.meta.env.BASE_URL}pdf-assets/`, document.baseURI).href;
        pdfTask = engine.getDocument({ data: bytes, isEvalSupported: false, useSystemFonts: true, cMapUrl: `${resources}cmaps/`, cMapPacked: true, standardFontDataUrl: `${resources}standard_fonts/`, wasmUrl: `${resources}wasm/` });
        pdf = await pdfTask.promise; pages.value = pdf.numPages;
      } else if (kind.value === 'docx') {
        const { validateOfficeArchive } = await import('../officeArchive');
        validateOfficeArchive(bytes);
        const { renderAsync } = await import('docx-preview');
        if (disposed) return;
        const content = document.createElement('div');
        await renderAsync(bytes, content, undefined, { useBase64URL: true, renderAltChunks: false, renderComments: false });
        const safe = DOMPurify.sanitize(content.innerHTML, { FORCE_BODY: true, ADD_TAGS: ['style'], FORBID_TAGS: ['a', 'form', 'iframe', 'object', 'embed'], FORBID_ATTR: ['srcset'] });
        documentHtml.value = `<html><head><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: blob:; font-src data: blob:; style-src 'unsafe-inline'"><style>body{margin:0;background:#eceff3}.docx-wrapper{padding:16px!important}section.docx{max-width:100%;box-sizing:border-box;background:white;margin:0 auto 16px;white-space:pre-wrap}</style></head><body>${safe}</body></html>`;
      } else {
        sheets.value = await new Promise((resolve, reject) => {
          spreadsheetWorker = new Worker(new URL('../spreadsheet.worker.js', import.meta.url), { type: 'module' });
          spreadsheetWorker.onmessage = ({data}) => { spreadsheetWorker.terminate(); if(data.error) reject(new Error(data.error)); else resolve(data.sheets); };
          spreadsheetWorker.onerror = () => { spreadsheetWorker.terminate(); reject(new Error('表格读取失败，请下载查看')); };
          controller.signal.addEventListener('abort', () => reject(new DOMException('Aborted','AbortError')), { once:true });
          spreadsheetWorker.postMessage(bytes, [bytes.buffer]);
        });
      }
    }
  } catch (e) { if (!disposed) error.value = e.name === 'AbortError' ? '读取已取消或超时，请重新打开' : e.message || '预览失败，请下载查看'; }
  finally { clearTimeout(timeout); if (!disposed) { loading.value = false; await nextTick(); if (pdf) await renderPage(); } }
}

/** 按容器尺寸渲染当前 PDF 页。 */
async function renderPage() {
  if (!pdf || disposed || !canvas.value) return;
  const ticket = ++renderSequence;
  rendering.value = true;
  try {
    if (renderTask) { renderTask.cancel(); await renderTask.promise.catch(() => {}); }
    const item = await pdf.getPage(page.value);
    if (disposed || ticket !== renderSequence) return;
    const original = item.getViewport({ scale: 1 });
    const width = Math.max(200, (body.value?.clientWidth || 800) - 32);
    const viewport = item.getViewport({ scale: Math.min(width / original.width, 1.5) * scale.value });
    const ratio = Math.min(window.devicePixelRatio || 1, 2, 6000 / Math.max(viewport.width, viewport.height));
    const target = canvas.value; target.width = Math.floor(viewport.width * ratio); target.height = Math.floor(viewport.height * ratio);
    target.style.width = `${viewport.width}px`; target.style.height = `${viewport.height}px`;
    renderTask = item.render({ canvasContext: target.getContext('2d'), viewport, transform: [ratio, 0, 0, ratio, 0, 0] });
    await renderTask.promise;
  } catch (e) { if (e.name !== 'RenderingCancelledException' && !disposed) error.value = 'PDF 渲染失败，请下载查看'; }
  finally { if (ticket === renderSequence) rendering.value = false; }
}
/** 查找包含关键词的下一页。 */
async function search() {
  if (!pdf || searching.value || !query.value.trim()) return;
  searching.value = true; searchStatus.value = '查找中';
  try { for (let offset = 0; offset < pages.value; offset++) { if (disposed) return; const n = (page.value + offset) % pages.value + 1; const content = await (await pdf.getPage(n)).getTextContent(); if (content.items.map(item => item.str || '').join('').toLowerCase().includes(query.value.trim().toLowerCase())) { page.value = n; searchStatus.value = `位于第 ${n} 页`; return; } } searchStatus.value = '未找到'; }
  catch { searchStatus.value = '查找失败'; } finally { searching.value = false; }
}
function zoom(step) { scale.value = Math.max(.25, Math.min(3, scale.value + step)); }
function mediaError() { error.value = '当前设备不支持此编码或文件读取失败，请下载播放'; }
/** 保持键盘焦点在预览弹窗。 */
function keyboard(event) {
  if (event.key === 'Escape') { event.stopPropagation(); emit('close'); }
  if (event.key !== 'Tab') return;
  const items = [...dialog.value.querySelectorAll('button:not(:disabled), a[href], input, select, video, audio, iframe')].filter(item => item.getClientRects().length);
  const first = items[0], last = items.at(-1);
  if (!items.length) { event.preventDefault(); return; }
  if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
watch([page, scale], renderPage);
onMounted(() => { dialog.value.focus(); load(); resizeObserver = new ResizeObserver(() => { if (pdf) renderPage(); }); resizeObserver.observe(body.value); });
onBeforeUnmount(() => { disposed = true; controller.abort(); spreadsheetWorker?.terminate(); clearTimeout(timeout); resizeObserver?.disconnect(); renderTask?.cancel(); pdfTask?.destroy()?.catch(() => {}); });
</script>

<style scoped>
.file-preview-mask{position:fixed;inset:0;z-index:2147483000;background:rgba(18,24,34,.5);display:grid;place-items:center;padding:20px;box-sizing:border-box}.file-preview{width:min(1180px,100%);height:min(900px,100%);max-height:100%;display:flex;flex-direction:column;overflow:hidden;background:#fff;color:#273243;border-radius:8px;box-shadow:0 16px 60px #0003;outline:none}.file-preview header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid #e3e7ed;gap:16px}.file-preview header strong{font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.preview-actions,.preview-tools{display:flex;align-items:center;gap:8px}.file-preview button,.preview-actions a{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;min-width:34px;height:34px;padding:0 8px;border:0;border-radius:5px;background:transparent;color:inherit;cursor:pointer;font:inherit}.file-preview button:hover,.preview-actions a:hover{background:#e9eff8}.file-preview button:disabled{opacity:.35;cursor:default}.preview-tools{padding:8px 14px;border-bottom:1px solid #e3e7ed;flex-wrap:wrap;font-size:12px}.preview-search{display:flex;align-items:center;margin-left:auto;gap:4px}.preview-search input{border:1px solid #d8dee7;border-radius:5px;min-width:0;width:130px;padding:7px;font:inherit}.preview-body{flex:1;min-height:0;overflow:auto;background:#edf0f4;position:relative}.preview-state{padding:48px 24px;text-align:center;font-size:14px}.preview-image{height:100%;display:grid;place-items:center;padding:16px;box-sizing:border-box;overflow:auto}.preview-image img{max-width:100%;max-height:100%;object-fit:contain;transform-origin:center}.preview-body video{display:block;width:100%;height:100%;background:#14171c}.preview-body audio{display:block;width:min(600px,90%);margin:80px auto}.preview-body canvas{display:block;margin:16px auto;background:white;box-shadow:0 1px 6px #0002}.preview-body iframe{width:100%;height:100%;border:0;display:block;background:white}.preview-sheets{display:flex;overflow-x:auto;padding:8px;gap:8px;border-bottom:1px solid #dce2ea}.preview-sheets button{white-space:nowrap;font-size:13px}.preview-sheets .active{color:#136c52;background:#e1f3eb}.preview-spreadsheet{background:white;min-height:100%;font-size:13px}.preview-spreadsheet table{border-collapse:collapse;white-space:pre-wrap}.preview-spreadsheet td,.preview-spreadsheet th{border:1px solid #dfe4eb;padding:8px 12px;min-width:90px;max-width:420px;overflow-wrap:anywhere}.preview-spreadsheet th{min-width:40px;background:#f0f3f6;color:#748094;position:sticky;left:0}.preview-spreadsheet p{padding:12px;color:#6a7380}@media(max-width:600px){.file-preview-mask{padding:0}.file-preview{width:100%;height:100%;height:100dvh;border-radius:0;padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom);box-sizing:border-box}.file-preview header{padding:12px}.preview-tools{gap:3px;padding:6px}.preview-search{width:100%;margin:0}.preview-search input{flex:1}.preview-body{overscroll-behavior:contain}}
</style>
