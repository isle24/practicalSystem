<script setup>
import { ref, watch } from 'vue';
import { cliDownloadUrl, cliError, useCliScope } from '../../utils/cli';

const props = defineProps({
  manifestUrl: { type: String, default: '' }, publicOrigin: { type: String, required: true },
  sessionKey: { type: String, required: true },
});
const begin = useCliScope(() => props.sessionKey ? `${props.sessionKey}:${props.manifestUrl}` : '');
const items = ref([]);
const loading = ref(false);
const error = ref('');
const version = ref('');
const platforms = {
  'windows-amd64': 'Windows · x64', 'windows-arm64': 'Windows · ARM64',
  'darwin-amd64': 'macOS · Intel', 'darwin-arm64': 'macOS · Apple 芯片',
  'linux-amd64': 'Linux · x64', 'linux-arm64': 'Linux · ARM64',
};
let generation = 0;

async function load() {
  const scope = begin();
  if (!scope.current() || !props.manifestUrl) { scope.finish(); return; }
  const ticket = ++generation;
  const controller = new AbortController();
  const cancel = () => controller.abort();
  scope.options.signal.addEventListener('abort', cancel, { once: true });
  const timeout = setTimeout(cancel, 15000);
  loading.value = true;
  error.value = '';
  items.value = [];
  version.value = '';
  try {
    const response = await fetch(props.manifestUrl, { signal: controller.signal, cache: 'no-store', credentials: 'same-origin', redirect: 'error' });
    if (!response.ok) throw new Error('平台安装包尚不可用，请确认学校已部署 CLI 分发文件。');
    const manifest = await response.json();
    if (!scope.current() || ticket !== generation) return;
    if (manifest.protocol_version !== 1 || !manifest.artifacts || typeof manifest.version !== 'string') throw new Error('CLI 版本清单格式不受支持，请联系学校管理员。');
    items.value = Object.entries(platforms).map(([key, label]) => {
      const artifact = manifest.artifacts[key];
      const url = cliDownloadUrl(artifact?.url, props.publicOrigin);
      return { key, label, url: url && /^[a-f\d]{64}$/i.test(artifact?.sha256 || '') ? url : '', sha256: artifact?.sha256 || '' };
    });
    version.value = manifest.version;
  } catch (reason) {
    if (scope.current() && ticket === generation) error.value = reason.name === 'AbortError' ? '读取平台版本清单超时，请稍后重试。' : cliError(reason);
  } finally {
    if (scope.current() && ticket === generation) loading.value = false;
    clearTimeout(timeout);
    scope.options.signal.removeEventListener('abort', cancel);
    scope.finish();
  }
}

watch(() => [props.sessionKey, props.manifestUrl], () => {
  generation++;
  items.value = [];
  version.value = '';
  error.value = '';
  loading.value = false;
  if (props.sessionKey && props.manifestUrl) load();
}, { immediate: true });
</script>

<template>
  <section class="cli-platform-downloads" aria-label="CLI 平台安装包">
    <header><h4>平台 ZIP{{ version ? ` · ${version}` : '' }}</h4><el-button link :loading="loading" :disabled="!manifestUrl || !sessionKey" @click="load">刷新安装包</el-button></header>
    <p v-if="loading">正在读取本校平台版本清单...</p>
    <el-alert v-if="error" :title="error" type="warning" :closable="false" />
    <div v-if="items.length" class="cli-platform-grid"><div v-for="item in items" :key="item.key"><a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer">{{ item.label }} ZIP</a><span v-else>{{ item.label }}：暂不可用</span><small v-if="item.url">SHA256：{{ item.sha256 }}</small></div></div>
  </section>
</template>

<style scoped>
.cli-platform-downloads { margin: 16px 0; } header { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
h4 { font-size: 14px; margin: 0; } p, small, span { color: var(--muted); font-size: 12px; }
.cli-platform-grid { margin-top: 12px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.cli-platform-grid div { min-width: 0; padding: 12px; border: 1px solid var(--line); border-radius: 6px; }
a { color: var(--primary-ink); font-size: 13px; } small { display: block; margin-top: 8px; overflow-wrap: anywhere; line-height: 1.6; }
</style>
