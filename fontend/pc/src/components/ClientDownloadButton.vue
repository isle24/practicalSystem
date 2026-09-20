<script setup>
import { ref } from 'vue';
import { Download, Monitor } from '@lucide/vue';
import OperationDialog from './OperationDialog.vue';
import { backendUrl, request } from '../api/client';

defineProps({ compact: Boolean });
const native = Boolean(window.__PRACTICAL_DESKTOP__);
const visible = ref(false), loading = ref(false), items = ref([]), error = ref('');
const platforms = { 'darwin-universal': 'macOS', 'windows-x86_64': 'Windows', 'linux-x86_64': 'Linux' };
async function open() {
  if (loading.value) return;
  visible.value = true; loading.value = true; error.value = '';
  try { items.value = (await request('/release/downloads')).items || []; }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
</script>

<template>
  <template v-if="!native">
    <button type="button" class="client-download-entry" :class="{ compact }" title="下载客户端" aria-label="下载客户端" @click="open"><Download :size="18" /><span v-if="!compact">下载客户端</span></button>
    <OperationDialog :visible="visible" title="下载客户端" @close="visible = false">
      <section class="client-download-list" aria-live="polite">
        <p v-if="loading">正在获取安装包</p><p v-else-if="error" role="alert">{{ error }}</p><p v-else-if="!items.length">学校尚未发布可下载的客户端。</p>
        <a v-for="item in items" :key="item.download_path" :href="backendUrl(item.download_path)" target="_blank" rel="noopener noreferrer">
          <Monitor :size="24" /><span><strong>{{ platforms[item.platform] }} <small>v{{ item.version }}</small></strong><small>{{ item.file_name }} · {{ (item.size / 1048576).toFixed(1) }} MB</small></span><Download :size="18" />
        </a>
      </section>
    </OperationDialog>
  </template>
</template>

<style scoped>
.client-download-entry { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; background: transparent; color: #316aa9; padding: 10px; cursor: pointer; font: inherit; border-radius: 6px; }
.client-download-entry.compact { width: 34px; height: 34px; padding: 8px; color: inherit; }
.client-download-entry:hover { background: #3d7bcc10; }
.client-download-list { padding: 24px; display: flex; flex-direction: column; gap: 12px; overflow: auto; }
.client-download-list a { display: flex; align-items: center; gap: 14px; color: #3066b1; text-decoration: none; padding: 16px 0; border-bottom: 1px solid #e3e8ef; }
.client-download-list a > span { flex: 1; min-width: 0; display: grid; gap: 6px; overflow-wrap: anywhere; }.client-download-list a > svg { flex-shrink: 0; }.client-download-list small { font-size: 12px; color: #788496; font-weight: normal; }
</style>
