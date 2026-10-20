<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ElCheckbox, ElMessage } from 'element-plus';
import { Upload } from '@lucide/vue';
import { request } from '../api/client';

const props = defineProps({ assets: { type: Array, default: () => [] }, deferred: { type: Array, default: () => [] }, settings: { type: Object, default: null }, disabled: Boolean, refreshing: Boolean, autoUpdating: Boolean });
const emit = defineEmits(['update:deferred', 'uploaded', 'busy', 'retry', 'refresh', 'source']);
const downloadSource = ref('github');
watch(() => props.settings?.source, source => { downloadSource.value = source === 'mirror' ? 'mirror' : 'github'; }, { immediate: true });
const canChangeSource = computed(() => props.settings && downloadSource.value !== props.settings.source && (downloadSource.value !== 'mirror' || props.settings.mirror_available));
const fileInput = ref(null), target = ref(null), uploading = ref(false), progress = ref(0);
let alive = true, uploadController;
const platforms = { 'darwin-universal': 'macOS', 'windows-x86_64': 'Windows', 'linux-x86_64': 'Linux' };
const pendingPlatforms = computed(() => Object.keys(platforms).filter(key => {
  const rows = props.assets.filter(a => a.platform === key);
  return !rows.some(a => a.kind === 'updater' && a.status === 'ready' && a.signature) || rows.some(a => a.status !== 'ready');
}));
const bytes = value => {
  const size = Math.max(0, Number(value) || 0);
  if (size < 1024) return `${size.toFixed(0)} B`;
  if (size < 1048576) return `${(size / 1024).toFixed(1)} KB`;
  if (size < 1073741824) return `${(size / 1048576).toFixed(1)} MB`;
  return `${(size / 1073741824).toFixed(2)} GB`;
};
const downloadPercent = row => {
  const total = Number(row.download_progress?.total) || Number(row.size);
  return total > 0 ? Math.min(100, Math.max(0, Math.floor((Number(row.download_progress?.downloaded) || 0) / total * 100))) : 0;
};
const timestamp = value => {
  const time = Number(value);
  return time > 0 && Number.isFinite(time) ? new Date(time * 1000).toLocaleString('zh-CN', { hour12: false }) : '';
};
const sourceLabel = source => source === 'mirror' ? (props.settings?.mirror_label ? `${props.settings.mirror_label} 加速` : '加速源') : 'GitHub 直连';
const status = value => ({ pending: '等待下载', downloading: '后台下载中', failed: '下载失败', ready: '已校验' }[value] || value);
function defer(key, checked) { emit('update:deferred', checked ? [...new Set([...props.deferred, key])] : props.deferred.filter(item => item !== key)); }
function choose(row) { if (props.disabled || uploading.value) return; target.value = row; fileInput.value.value = ''; fileInput.value.click(); }
async function upload(event) {
  const file = event.target.files?.[0], asset = target.value;
  if (!alive || props.disabled || !file || !asset || uploading.value || !props.assets.some(item => item.id === asset.id)) return;
  if (file.name !== asset.file_name || file.size !== Number(asset.size)) { ElMessage.error('请选择与清单名称和大小一致的安装包'); return; }
  uploading.value = true; progress.value = 0; emit('busy', true);
  const controller = new AbortController(); uploadController = controller;
  try {
    const session = await request('/release/begin-upload', { method: 'POST', signal: controller.signal, body: JSON.stringify({ asset_id: asset.id }) });
    for (let offset = 0; offset < file.size;) {
      const end = Math.min(offset + session.chunk_size, file.size);
      const body = new FormData(); body.append('upload_id', session.upload_id); body.append('offset', String(offset)); body.append('file', file.slice(offset, end), 'chunk.bin');
      const result = await request('/release/upload-chunk', { method: 'POST', signal: controller.signal, body });
      if (Number(result.offset) !== end) throw new Error('分片校验位置不一致，请重新上传');
      offset = end; progress.value = Math.floor(offset / file.size * 100);
    }
    const data = await request('/release/finish-upload', { method: 'POST', signal: controller.signal, timeoutMs: 180000, body: JSON.stringify({ upload_id: session.upload_id }) });
    if (!alive) return;
    emit('uploaded', data); ElMessage.success('安装包已上传并通过校验');
  } catch (e) { if (alive) ElMessage.error(e.message); }
  finally { uploadController = null; if (alive) { uploading.value = false; emit('busy', false); } }
}
onBeforeUnmount(() => { alive = false; target.value = null; uploadController?.abort(); });
</script>

<template>
  <section class="release-assets">
    <input ref="fileInput" type="file" hidden @change="upload" />
    <div v-if="settings" class="release-source">
      <div class="release-source-controls"><span>下载源</span><el-select v-model="downloadSource" :disabled="disabled" aria-label="下载源"><el-option :label="sourceLabel('mirror')" value="mirror" :disabled="!settings.mirror_available" /><el-option label="GitHub 直连（关闭加速）" value="github" /></el-select><el-button :disabled="disabled || !canChangeSource" @click="emit('source', downloadSource)">切换下载源</el-button></div>
      <small>当前：{{ sourceLabel(settings.source) }}。切换后，正在下载的文件将停止并从已下载位置继续。</small>
      <small>加速源仅用于公开安装包，必要时回退 GitHub 直连。</small>
      <small>切换下载源不会重置重试次数；已耗尽次数的文件请点击“重试未完成下载”。</small>
      <small v-if="!settings.mirror_available">当前服务器未启用加速源，可使用 GitHub 直连。</small>
    </div>
    <div class="release-assets-toolbar"><el-button :disabled="disabled" @click="emit('retry')">重试未完成下载</el-button><el-button :disabled="disabled" :loading="refreshing" @click="emit('refresh')">刷新状态</el-button><small v-if="autoUpdating">下载期间每 3 秒自动更新</small></div>
    <fieldset v-if="pendingPlatforms.length"><legend>延期平台</legend><ElCheckbox v-for="key in pendingPlatforms" :key="key" :model-value="deferred.includes(key)" :disabled="disabled" @update:model-value="defer(key, $event)">{{ platforms[key] }} 暂缓，后台继续下载</ElCheckbox><small>先发布已就绪平台；延期平台下载校验完成后自动开放，期间保留上一版可用下载。</small></fieldset>
    <el-progress v-if="uploading" :percentage="progress" /><p v-if="uploading && progress === 100">正在校验安装包，请勿关闭窗口。</p>
    <el-table width-storage-key="release-assets:assets" :data="assets"><el-table-column prop="file_name" label="安装文件" min-width="220" /><el-table-column label="平台" width="105"><template #default="{ row }">{{ platforms[row.platform] || row.platform }}</template></el-table-column><el-table-column label="大小" width="100"><template #default="{ row }">{{ (row.size / 1048576).toFixed(1) }} MB</template></el-table-column><el-table-column label="状态" width="115"><template #default="{ row }">{{ status(row.status) }}</template></el-table-column><el-table-column label="下载进度" min-width="260"><template #default="{ row }"><div v-if="row.download_progress && row.status !== 'ready'" class="release-download-progress"><el-progress :percentage="downloadPercent(row)" :stroke-width="6" /><small>{{ bytes(row.download_progress.downloaded) }} / {{ bytes(row.download_progress.total || row.size) }} · {{ bytes(row.download_progress.speed) }}/s</small><small>{{ sourceLabel(row.download_progress.source) }} · 尝试 {{ row.download_progress.attempts || 0 }} / {{ row.download_progress.max_attempts || 3 }} 次</small><small v-if="row.download_progress.next_retry_at">等待重试，计划于 {{ timestamp(row.download_progress.next_retry_at) }}</small><small v-if="row.download_progress.updated_at">更新于 {{ timestamp(row.download_progress.updated_at) }}</small></div><span v-else>—</span></template></el-table-column><el-table-column prop="error_message" label="下载提示" min-width="160" /><el-table-column label="操作" width="130" fixed="right"><template #default="{ row }"><el-button v-if="row.status !== 'ready'" :disabled="disabled" :icon="Upload" @click="choose(row)">本地上传</el-button></template></el-table-column></el-table>
  </section>
</template>

<style scoped>
.release-assets { display: grid; gap: 16px; min-width: 0; }.release-assets-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }.release-source, .release-download-progress { display: grid; gap: 6px; }.release-source-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }.release-source-controls :deep(.el-select) { width: 240px; max-width: 100%; }.release-download-progress { padding: 6px 0; }.release-assets fieldset { border: 1px solid var(--line); border-radius: 6px; padding: 12px 16px; display: flex; flex-direction: column; gap: 10px; }.release-assets legend { padding: 0 6px; font-size: 13px; }.release-assets small { color: var(--muted); line-height: 1.6; }.release-assets :deep(.el-checkbox) { white-space: normal; height: auto; }.release-assets :deep(.el-checkbox__label) { white-space: normal; }
</style>
