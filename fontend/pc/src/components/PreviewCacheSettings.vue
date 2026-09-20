<template><section v-if="desktop" class="preview-cache-settings"><header><strong>文件预览缓存</strong><span>{{ used }} / {{ state.max_gb }} GB · {{ state.files }} 个文件</span></header><div><label>最大缓存<el-input-number v-model="limit" :min="0" :max="128" :step="1" :disabled="busy" /><span>GB</span></label><el-button :loading="busy" @click="save">保存</el-button><el-button :disabled="busy || !state.files" @click="clear">清空缓存</el-button></div><small>设为 0 关闭缓存。按学校和账号隔离，超过上限自动清理最久未使用的文件。</small><p v-if="error" role="alert">{{ error }}</p></section></template>
<script setup>
import { ref, computed, onMounted } from 'vue';
import { ElMessageBox, ElMessage } from 'element-plus';
const desktop = window.__PRACTICAL_DESKTOP__?.previewCache;
const state = ref({max_gb:8,used_bytes:0,files:0}), limit = ref(8), busy=ref(false), error=ref('');
const used = computed(()=>(state.value.used_bytes / 1073741824).toFixed(2));
async function run(action) { busy.value=true;error.value='';try{state.value=await desktop(action,limit.value);limit.value=state.value.max_gb;if(action!=='status')ElMessage.success('缓存设置已更新');}catch(e){error.value=e.message;}finally{busy.value=false;} }
async function save(){ if(limit.value < state.value.max_gb) { try{ await ElMessageBox.confirm('降低容量会清理超出上限的本机缓存，不影响服务器文件。确认继续？','调整缓存'); }catch{return;} } await run('save'); }
async function clear(){try{await ElMessageBox.confirm('清空本机全部学校账号的预览缓存？服务器原文件不会删除。','清空缓存');}catch{return;}await run('clear');}
onMounted(()=>{if(desktop)run('status');});
</script>
<style scoped>.preview-cache-settings{display:flex;flex-direction:column;gap:14px;padding:22px 0;border-top:1px solid #e5e9ef}.preview-cache-settings header,.preview-cache-settings>div,.preview-cache-settings label{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.preview-cache-settings header{justify-content:space-between}.preview-cache-settings strong{font-size:14px}.preview-cache-settings span,.preview-cache-settings small{font-size:12px;color:#798392}.preview-cache-settings label{font-size:13px}.preview-cache-settings p{color:#b53939;font-size:13px}</style>
