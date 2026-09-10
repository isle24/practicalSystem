<template><Teleport to="body"><aside v-if="state" class="desktop-update-status" role="status"><Download :size="21" /><div><strong>{{ labels[state.phase] }}</strong><progress v-if="state.phase === 'downloading'" :value="state.bytes" :max="state.total || undefined" /><small v-if="state.total">{{ (state.bytes / 1048576).toFixed(1) }} / {{ (state.total / 1048576).toFixed(1) }} MB</small></div><button v-if="['error', 'cancelled', 'ready'].includes(state.phase)" title="关闭" @click="state = null"><X :size="17" /></button></aside></Teleport></template>
<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Download, X } from '@lucide/vue';
const state = ref(null);
const labels = { downloading: '正在下载客户端更新', ready: '校验完成，等待安装确认', installing: '正在安装，请稍候', cancelled: '已取消安装', error: '更新未完成，请重新检查' };
function receive(event) { if (labels[event.detail?.phase]) state.value = event.detail; }
onMounted(() => window.addEventListener('desktop-update-progress', receive));
onBeforeUnmount(() => window.removeEventListener('desktop-update-progress', receive));
</script>
<style scoped>
.desktop-update-status{position:fixed;z-index:80000;right:24px;bottom:160px;background:#fff;border:1px solid #dfe6ee;box-shadow:0 8px 32px #26395026;border-radius:8px;display:flex;align-items:center;gap:16px;padding:20px;color:#3b4b5f;max-width:calc(100vw - 48px);box-sizing:border-box}.desktop-update-status div{display:flex;flex-direction:column;gap:10px;min-width:220px}.desktop-update-status strong{font-size:14px}.desktop-update-status small{font-size:12px;color:#8793a2}.desktop-update-status progress{width:100%;accent-color:#3573cc}.desktop-update-status button{border:0;background:transparent;color:#8390a0;cursor:pointer}
</style>
