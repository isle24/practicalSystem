<template><button type="button" class="file-preview-button" title="预览附件" @click.stop="previewFile(file)"><Eye :size="16" /><span>预览</span></button></template>
<script setup>
import { Eye } from '@lucide/vue';
import { previewFile } from '../filePreview';
defineProps({ file: { type: Object, required: true } });
</script>
<style scoped>.file-preview-button{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:6px 9px;min-height:30px;border:0;border-radius:4px;background:transparent;color:#2563ad;font:inherit;font-size:13px;cursor:pointer;white-space:nowrap}.file-preview-button:hover{background:#eaf2fc}</style>
