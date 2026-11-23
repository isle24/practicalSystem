<script setup>
import { ref } from 'vue';
import { Download } from '@lucide/vue';
import ClientPackages from '../../../shared/components/ClientPackages.vue';
import OperationDialog from './OperationDialog.vue';
import { backendUrl, request } from '../api/client';

defineProps({ compact: Boolean });
const native = Boolean(window.__PRACTICAL_DESKTOP__);
const visible = ref(false), loading = ref(false), items = ref([]), error = ref('');
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
      <section class="client-download-list">
        <p v-if="loading" role="status">正在获取安装包</p>
        <p v-else-if="error" role="alert">{{ error }}</p>
        <ClientPackages v-else :items="items" :backend-url="backendUrl" />
      </section>
    </OperationDialog>
  </template>
</template>

<style scoped>
.client-download-entry { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; background: transparent; color: var(--primary-ink); padding: 10px; cursor: pointer; font: inherit; border-radius: 6px; }
.client-download-entry.compact { width: 34px; height: 34px; padding: 8px; color: inherit; }
.client-download-entry:hover { background: var(--primary-soft); }
.client-download-list { padding: clamp(14px, 3vw, 24px); min-height: 0; overflow: auto; }
</style>
