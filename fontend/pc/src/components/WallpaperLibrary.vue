<script setup>
import { computed, onMounted, reactive, watch } from 'vue';
import { Check, Images, ImagePlus, RefreshCw } from '@lucide/vue';
import { backendUrl, request } from '../api/client';

const props = defineProps({ selection: { type: Object, default: () => ({}) }, schoolDefaultUrl: { type: String, default: '' } });
const emit = defineEmits(['applied']);
const state = reactive({ scope: 'mine', items: [], total: 0, page: 1, size: 12, loading: false, busy: false, error: '', selected: null, importing: false, importItems: [], importPage: 1, importTotal: 0 });
const activeId = computed(() => Number(state.selected?.wallpaper_id ?? props.selection?.wallpaper_id ?? 0));
const activeMode = computed(() => state.selected?.wallpaper_mode ?? props.selection?.wallpaper_mode ?? 'school');

async function load() {
  state.loading = true;
  state.error = '';
  try {
    const data = await request(`/wallpaper/list?scope=${state.scope}&page=${state.page}&page_size=${state.size}`);
    state.items = data.items || [];
    state.total = data.pagination?.total || 0;
  } catch (error) { state.error = error.message; }
  finally { state.loading = false; }
}

async function apply(id = null) {
  if (state.busy) return;
  state.busy = true;
  state.error = '';
  try {
    const data = await request('/wallpaper/apply', { method: 'POST', body: JSON.stringify(id ? { id } : { mode: 'school' }) });
    state.selected = data;
    emit('applied', data);
  } catch (error) { state.error = error.message; }
  finally { state.busy = false; }
}

async function share(item, shared) {
  if (state.busy) return;
  state.busy = true;
  state.error = '';
  try {
    const updated = await request('/wallpaper/share', { method: 'POST', body: JSON.stringify({ id: item.id, shared }) });
    Object.assign(item, updated);
  } catch (error) { state.error = error.message; }
  finally { state.busy = false; }
}

async function upload(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || state.busy) return;
  if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 8 * 1024 * 1024) {
    state.error = '请选择不超过 8 MB 的 JPG、PNG、WebP 或 GIF 图片';
    return;
  }
  state.busy = true;
  state.error = '';
  try {
    const body = new FormData();
    body.append('file', file);
    await request('/wallpaper/upload', { method: 'POST', body });
    state.scope = 'mine';
    state.page = 1;
    await load();
  } catch (error) { state.error = error.message; }
  finally { state.busy = false; }
}

async function loadImportable() {
  state.error = '';
  try {
    const data = await request(`/wallpaper/importable?page=${state.importPage}&page_size=12`);
    state.importItems = data.items || [];
    state.importTotal = data.pagination?.total || 0;
  } catch (error) { state.error = error.message; }
}

async function openImport() {
  state.importPage = 1;
  state.importing = true;
  await loadImportable();
}

async function importImage(fileId) {
  if (state.busy) return;
  state.busy = true;
  state.error = '';
  try {
    await request('/wallpaper/import', { method: 'POST', body: JSON.stringify({ file_id: fileId }) });
    state.importing = false;
    state.scope = 'mine';
    state.page = 1;
    await load();
  } catch (error) { state.error = error.message; }
  finally { state.busy = false; }
}

watch(() => state.scope, () => { state.page = 1; load(); });
watch(() => state.page, load);
watch(() => props.selection, () => { state.selected = null; });
onMounted(load);
</script>

<template>
  <section class="wallpaper-library">
    <header class="wallpaper-library-head">
      <div class="wallpaper-library-tabs" role="tablist" aria-label="壁纸来源">
        <button type="button" role="tab" :aria-selected="state.scope === 'mine'" :class="{ active: state.scope === 'mine' }" @click="state.scope = 'mine'">我的上传</button>
        <button type="button" role="tab" :aria-selected="state.scope === 'shared'" :class="{ active: state.scope === 'shared' }" @click="state.scope = 'shared'">壁纸库</button>
      </div>
      <div class="wallpaper-library-actions">
        <button v-if="state.scope === 'mine'" type="button" class="wallpaper-library-upload" :disabled="state.busy" @click="openImport"><Images :size="16" />历史图片</button>
        <label class="wallpaper-library-upload" :class="{ disabled: state.busy }"><ImagePlus :size="16" />上传壁纸<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" :disabled="state.busy" @change="upload"></label>
        <button type="button" class="wallpaper-library-icon" title="刷新壁纸" :disabled="state.loading" @click="load"><RefreshCw :size="17" /></button>
      </div>
    </header>
    <p class="wallpaper-library-hint">推荐 1920 × 1080 px 或更高的 16:9 横图，最大 8 MB。</p>
    <p v-if="state.error" class="wallpaper-library-error" role="alert">{{ state.error }}</p>
    <div class="wallpaper-library-grid">
      <article class="wallpaper-library-item" :class="{ active: activeMode === 'school' }">
        <div class="wallpaper-library-image school"><img v-if="schoolDefaultUrl" :src="backendUrl(schoolDefaultUrl)" alt="学校默认壁纸"><span v-else>学校默认</span></div>
        <div class="wallpaper-library-item-footer"><strong>学校默认</strong><button type="button" :disabled="state.busy" @click="apply()"><Check v-if="activeMode === 'school'" :size="15" />{{ activeMode === 'school' ? '已应用' : '应用' }}</button></div>
      </article>
      <article v-for="item in state.items" :key="item.id" class="wallpaper-library-item" :class="{ active: activeMode === 'item' && activeId === item.id }">
        <div class="wallpaper-library-image"><img :src="backendUrl(item.url)" :alt="item.name" loading="lazy"></div>
        <div class="wallpaper-library-item-footer"><strong :title="item.name">{{ item.name }}</strong><button type="button" :disabled="state.busy" @click="apply(item.id)"><Check v-if="activeMode === 'item' && activeId === item.id" :size="15" />{{ activeMode === 'item' && activeId === item.id ? '已应用' : '应用' }}</button></div>
        <label v-if="state.scope === 'mine'" class="wallpaper-library-share"><input type="checkbox" :checked="item.is_shared" :disabled="state.busy" @change="share(item, $event.target.checked)">分享到壁纸库</label>
      </article>
    </div>
    <p v-if="!state.loading && !state.items.length" class="wallpaper-library-empty">{{ state.scope === 'mine' ? '暂无上传壁纸' : '壁纸库暂无共享图片' }}</p>
    <el-pagination v-if="state.total > state.size" v-model:current-page="state.page" :page-size="state.size" :total="state.total" layout="prev, pager, next" background />
    <el-dialog v-model="state.importing" title="选择历史图片" width="min(720px, 92vw)">
      <div class="wallpaper-library-grid">
        <button v-for="image in state.importItems" :key="image.file_id" type="button" class="wallpaper-library-import-item" :disabled="state.busy" @click="importImage(image.file_id)"><img :src="backendUrl(image.url)" :alt="image.name"><span>{{ image.name }}</span></button>
      </div>
      <p v-if="!state.importItems.length" class="wallpaper-library-empty">暂无可选图片</p>
      <el-pagination v-if="state.importTotal > 12" v-model:current-page="state.importPage" :page-size="12" :total="state.importTotal" layout="prev, pager, next" background @current-change="loadImportable" />
    </el-dialog>
  </section>
</template>

<style scoped>
.wallpaper-library { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.wallpaper-library-head, .wallpaper-library-actions, .wallpaper-library-item-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.wallpaper-library-tabs { display: inline-flex; border-bottom: 1px solid #d7dfe8; }
.wallpaper-library-tabs button { border: 0; background: none; padding: 9px 16px; color: #596776; cursor: pointer; border-bottom: 2px solid transparent; }
.wallpaper-library-tabs button.active { color: #18689a; border-color: #18689a; font-weight: 600; }
.wallpaper-library-actions { justify-content: flex-end; }
.wallpaper-library-upload { position: relative; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #cbd6df; border-radius: 6px; padding: 8px 12px; cursor: pointer; color: #245e86; white-space: nowrap; }
.wallpaper-library-upload input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
.wallpaper-library-upload.disabled { opacity: .5; }
.wallpaper-library-icon { width: 36px; height: 36px; display: grid; place-items: center; border: 1px solid #cbd6df; border-radius: 6px; background: #fff; cursor: pointer; }
.wallpaper-library-hint, .wallpaper-library-empty { color: #697683; font-size: 12px; margin: 0; }
.wallpaper-library-error { color: #b42318; font-size: 12px; margin: 0; }
.wallpaper-library-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 12px; }
.wallpaper-library-item { min-width: 0; border: 1px solid #dce3ea; border-radius: 6px; overflow: hidden; background: #fff; }
.wallpaper-library-item.active { border-color: #2375a9; box-shadow: inset 0 0 0 1px #2375a9; }
.wallpaper-library-image { aspect-ratio: 16 / 9; background: #e9eef2; display: grid; place-items: center; color: #607488; }
.wallpaper-library-image img { width: 100%; height: 100%; object-fit: cover; }
.wallpaper-library-item-footer { padding: 9px; min-height: 44px; }
.wallpaper-library-item-footer strong { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; }
.wallpaper-library-item-footer button { display: inline-flex; align-items: center; gap: 3px; border: 0; background: none; color: #17669a; cursor: pointer; font-size: 12px; white-space: nowrap; }
.wallpaper-library-share { display: flex; align-items: center; gap: 5px; padding: 0 9px 9px; color: #516273; font-size: 12px; }
.wallpaper-library-import-item { min-width: 0; border: 1px solid #dce3ea; border-radius: 6px; overflow: hidden; background: #fff; padding: 0; text-align: left; cursor: pointer; }
.wallpaper-library-import-item img { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; }
.wallpaper-library-import-item span { display: block; padding: 8px; font-size: 12px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
@media (max-width: 560px) { .wallpaper-library-head { align-items: stretch; flex-direction: column; } .wallpaper-library-actions { justify-content: flex-start; } .wallpaper-library-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
