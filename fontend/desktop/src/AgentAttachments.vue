<template>
  <ul v-if="items.length" class="agent-attachments" aria-label="待发送附件">
    <li v-for="(file, index) in items" :key="index">
      <img v-if="file.kind === 'image'" :src="'data:' + file.mime_type + ';base64,' + file.data"
        :width="25" :height="25" alt="" />
      <FileSpreadsheet v-else-if="file.kind === 'table'" :size="16" aria-hidden="true" />
      <FileText v-else :size="16" aria-hidden="true" />
      <span :title="file.name">{{ file.name }}</span>
      <small v-if="file.truncated">已截断</small>
      <button type="button" :disabled="disabled || picking" :aria-label="'移除 ' + file.name"
        @click="items = items.filter((_, position) => position !== index)">
        <X :size="13" aria-hidden="true" />
      </button>
    </li>
  </ul>
</template>

<script setup>
import { onScopeDispose, ref } from 'vue';
import { FileSpreadsheet, FileText, X } from '@lucide/vue';

const items = defineModel({ type: Array, required: true });
const props = defineProps({ pick: { type: Function, required: true }, disabled: Boolean });
const emit = defineEmits(['error']);
const picking = ref(false);
const help = '可选 JPEG、PNG、WebP、XLSX、CSV、TSV 和文本。每次最多 4 个，单个原文件不超过 16 MiB，随本条消息发送的附件合计最多 192 KiB。图片会缩至最长边 1280 像素、每张最多 88 KiB，文本和表格每件最多 24 KiB。';
let active = true;
onScopeDispose(() => { active = false; });

async function choose() {
  if (props.disabled || picking.value || items.value.length >= 4) return;
  const before = items.value;
  picking.value = true;
  try {
    const result = await props.pick();
    if (!active || items.value !== before || !result?.attachments?.length) return;
    const next = [...before, ...result.attachments];
    if (next.length > 4 || new TextEncoder().encode(JSON.stringify(next)).length > 192 * 1024) {
      throw new Error('本条消息最多添加 4 个附件，合计 192 KiB。可减少附件或分开发送。');
    }
    items.value = next;
  } catch (reason) {
    if (active && items.value === before) emit('error', reason?.message || '无法读取所选附件。');
  } finally {
    picking.value = false;
  }
}
defineExpose({ choose, picking, help });
</script>

<style scoped>
.agent-attachments, .agent-attachments li { display: flex; align-items: center; gap: 6px; }
.agent-attachments { flex-wrap: wrap; margin: 0; padding: 7px 6px 0; list-style: none; color: var(--muted); }
.agent-attachments li { min-width: 0; max-width: 100%; padding: 2px 4px 2px 7px; border: 1px solid var(--border); border-radius: 8px; }
.agent-attachments li img { object-fit: contain; }
.agent-attachments li span { max-width: 16em; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-size: 12px; }
.agent-attachments li small { font-size: 10px; white-space: nowrap; }
.agent-attachments button { display: inline-flex; align-items: center; gap: 4px; color: inherit; background: transparent; border: 0; padding: 4px; cursor: pointer; }
.agent-attachments button:disabled { opacity: .5; cursor: default; }
</style>
