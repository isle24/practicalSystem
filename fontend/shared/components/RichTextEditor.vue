<template>
  <div class="release-editor"><nav><button v-for="tool in tools" :key="tool.command" type="button" :title="tool.title" @mousedown.prevent="format(tool)"><component :is="tool.icon" :size="18" /></button></nav><div ref="surface" class="rich-surface" contenteditable="true" role="textbox" aria-label="更新说明" aria-multiline="true" @input="emit('update:modelValue', surface.innerHTML)" @paste="paste" /></div>
</template>
<script setup>
import { ref, onMounted, watch } from 'vue';
import { Bold, Italic, List, ListOrdered, Heading2, Undo2 } from '@lucide/vue';
import DOMPurify from 'dompurify';
const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);
const surface = ref(null);
const tools = [{ command: 'bold', title: '加粗', icon: Bold }, { command: 'italic', title: '斜体', icon: Italic }, { command: 'formatBlock', value: 'h2', title: '标题', icon: Heading2 }, { command: 'insertUnorderedList', title: '项目列表', icon: List }, { command: 'insertOrderedList', title: '编号列表', icon: ListOrdered }, { command: 'undo', title: '撤销', icon: Undo2 }];
function format(tool) { surface.value.focus(); document.execCommand(tool.command, false, tool.value); emit('update:modelValue', surface.value.innerHTML); }
function paste(event) { event.preventDefault(); document.execCommand('insertText', false, event.clipboardData.getData('text/plain')); }
function sync() { if (surface.value && surface.value.innerHTML !== props.modelValue && document.activeElement !== surface.value) surface.value.innerHTML = DOMPurify.sanitize(props.modelValue); }
watch(() => props.modelValue, sync); onMounted(sync);
</script>
<style scoped>
.release-editor{border:1px solid #dce2e9;border-radius:6px;overflow:hidden;flex-shrink:0;min-width:0}.release-editor nav{display:flex;flex-wrap:wrap;gap:8px;padding:8px 12px;background:#f7f8fa;border-bottom:1px solid #e5e9ed}.release-editor button{width:32px;height:32px;display:grid;place-items:center;border:0;background:transparent;color:#4b5665;border-radius:4px;cursor:pointer}.release-editor button:hover{background:#e4eaf2}.rich-surface{min-height:230px;max-height:400px;overflow:auto;padding:18px 24px;outline:none;line-height:1.8;overflow-wrap:anywhere;white-space:normal}.rich-surface :deep(pre){white-space:pre-wrap}.rich-surface :deep(img){max-width:100%}
</style>
