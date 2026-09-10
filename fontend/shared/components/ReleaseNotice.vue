<template><aside v-if="latest && visible" class="release-notice" role="status"><Bell :size="20" /><div><strong>{{ latest.title }}</strong><small>新版本 {{ latest.version }} 已发布</small></div><button @click="open">查看</button><button title="关闭" @click="dismiss"><X :size="16" /></button></aside></template>
<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Bell, X } from '@lucide/vue';
const props = defineProps({ request: { type: Function, required: true }, sessionKey: { type: String, required: true } });
const emit = defineEmits(['open']);
const latest = ref(null), visible = ref(false);
let lastCheck = 0, disposed = false;
const key = () => `release-seen:${props.sessionKey}`;
async function check() {
  if (document.hidden || Date.now() - lastCheck < 300000) return;
  lastCheck = Date.now();
  try { const { items } = await props.request('/release/list?page=1&page_size=1'); if (disposed) return; latest.value = items[0] || null; visible.value = Boolean(latest.value && localStorage.getItem(key()) !== `${latest.value.id}:${latest.value.published_at}`); } catch { /* 提醒失败不阻断业务。 */ }
  window.__PRACTICAL_DESKTOP__?.checkUpdate?.(false).catch(() => {});
}
function dismiss() { visible.value = false; try { localStorage.setItem(key(), `${latest.value.id}:${latest.value.published_at}`); } catch { /* 存储不可用不影响阅读。 */ } }
function open() { dismiss(); emit('open'); }
onMounted(() => { check(); window.addEventListener('focus', check); });
onBeforeUnmount(() => { disposed = true; window.removeEventListener('focus', check); });
</script>
<style scoped>
.release-notice{position:fixed;z-index:70000;right:20px;bottom:88px;max-width:calc(100vw - 40px);padding:16px;display:flex;gap:12px;align-items:center;border:1px solid #e1e7ee;border-radius:8px;box-shadow:0 8px 32px #17243626;background:#fff;color:#364151;box-sizing:border-box}.release-notice div{display:flex;flex-direction:column;gap:6px;min-width:0}.release-notice strong{font-size:13px;overflow-wrap:anywhere}.release-notice small{font-size:11px;color:#8b929f}.release-notice button{border:0;background:transparent;color:#326aca;padding:5px;cursor:pointer;flex-shrink:0}
</style>
