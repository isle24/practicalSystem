<script setup>
import { computed, ref } from 'vue';
import { ElCheckbox, ElMessage } from 'element-plus';
import { Upload } from '@lucide/vue';
import { request } from '../api/client';

const props = defineProps({ assets: { type: Array, default: () => [] }, deferred: { type: Array, default: () => [] }, disabled: Boolean });
const emit = defineEmits(['update:deferred', 'uploaded', 'busy', 'retry', 'refresh']);
const fileInput = ref(null), target = ref(null), uploading = ref(false), progress = ref(0);
const platforms = { 'darwin-universal': 'macOS', 'windows-x86_64': 'Windows', 'linux-x86_64': 'Linux' };
const pendingPlatforms = computed(() => Object.keys(platforms).filter(key => {
  const rows = props.assets.filter(a => a.platform === key);
  return !rows.some(a => a.kind === 'updater' && a.status === 'ready' && a.signature) || rows.some(a => a.status !== 'ready');
}));
const status = value => ({ pending: '等待下载', downloading: '后台下载中', failed: '下载失败', ready: '已校验' }[value] || value);
function defer(key, checked) { emit('update:deferred', checked ? [...new Set([...props.deferred, key])] : props.deferred.filter(item => item !== key)); }
function choose(row) { if (props.disabled || uploading.value) return; target.value = row; fileInput.value.value = ''; fileInput.value.click(); }
async function upload(event) {
  const file = event.target.files?.[0], asset = target.value;
  if (!file || !asset || uploading.value) return;
  if (file.name !== asset.file_name || file.size !== Number(asset.size)) { ElMessage.error('请选择与清单名称和大小一致的安装包'); return; }
  uploading.value = true; progress.value = 0; emit('busy', true);
  try {
    const session = await request('/release/begin-upload', { method: 'POST', body: JSON.stringify({ asset_id: asset.id }) });
    for (let offset = 0; offset < file.size;) {
      const end = Math.min(offset + session.chunk_size, file.size);
      const body = new FormData(); body.append('upload_id', session.upload_id); body.append('offset', String(offset)); body.append('file', file.slice(offset, end), 'chunk.bin');
      const result = await request('/release/upload-chunk', { method: 'POST', body });
      if (Number(result.offset) !== end) throw new Error('分片校验位置不一致，请重新上传');
      offset = end; progress.value = Math.floor(offset / file.size * 100);
    }
    const data = await request('/release/finish-upload', { method: 'POST', timeoutMs: 180000, body: JSON.stringify({ upload_id: session.upload_id }) });
    emit('uploaded', data); ElMessage.success('安装包已上传并通过校验');
  } catch (e) { ElMessage.error(e.message); }
  finally { uploading.value = false; emit('busy', false); }
}
</script>

<template>
  <section class="release-assets">
    <input ref="fileInput" type="file" hidden @change="upload" />
    <div class="release-assets-toolbar"><el-button :disabled="disabled" @click="emit('retry')">重试未完成下载</el-button><el-button :disabled="disabled" @click="emit('refresh')">刷新状态</el-button></div>
    <fieldset v-if="pendingPlatforms.length"><legend>延期平台</legend><ElCheckbox v-for="key in pendingPlatforms" :key="key" :model-value="deferred.includes(key)" :disabled="disabled" @update:model-value="defer(key, $event)">{{ platforms[key] }} 暂缓，后台继续下载</ElCheckbox><small>先发布已就绪平台；延期平台下载校验完成后自动开放，期间保留上一版可用下载。</small></fieldset>
    <el-progress v-if="uploading" :percentage="progress" /><p v-if="uploading && progress === 100">正在校验安装包，请勿关闭窗口。</p>
    <el-table :data="assets"><el-table-column prop="file_name" label="安装文件" min-width="220" /><el-table-column label="平台" width="105"><template #default="{ row }">{{ platforms[row.platform] || row.platform }}</template></el-table-column><el-table-column label="大小" width="100"><template #default="{ row }">{{ (row.size / 1048576).toFixed(1) }} MB</template></el-table-column><el-table-column label="状态" width="115"><template #default="{ row }">{{ status(row.status) }}</template></el-table-column><el-table-column prop="error_message" label="下载提示" min-width="160" /><el-table-column label="操作" width="130" fixed="right"><template #default="{ row }"><el-button v-if="row.status !== 'ready'" :disabled="disabled" :icon="Upload" @click="choose(row)">本地上传</el-button></template></el-table-column></el-table>
  </section>
</template>

<style scoped>
.release-assets { display: grid; gap: 16px; min-width: 0; }.release-assets-toolbar { display: flex; gap: 10px; flex-wrap: wrap; }.release-assets fieldset { border: 1px solid #e1e6ed; border-radius: 6px; padding: 12px 16px; display: flex; flex-direction: column; gap: 10px; }.release-assets legend { padding: 0 6px; font-size: 13px; }.release-assets small { color: #7c8794; line-height: 1.6; }.release-assets :deep(.el-checkbox) { white-space: normal; height: auto; }.release-assets :deep(.el-checkbox__label) { white-space: normal; }
</style>
