<template>
  <section class="embedded-external-page">
    <header><el-button :icon="ArrowLeft" @click="emit('close')">返回</el-button><strong>{{ item.title || item.name || '外部网页' }}</strong><el-button :icon="RefreshCw" @click="reload">重新加载</el-button><el-button :icon="X" @click="emit('close')">关闭</el-button></header>
    <div v-if="loading" class="embedded-loading">正在加载外部网页...</div>
    <el-alert v-if="error" :title="error" type="warning" :closable="false" />
    <iframe v-if="address" :key="sequence" :src="address" :title="item.title || item.name || '外部网页'" sandbox="allow-scripts allow-same-origin allow-forms allow-modals allow-popups" referrerpolicy="no-referrer" @load="loaded" @error="failed" />
    <footer><span>外部网页独立显示。若页面为空或站点禁止内嵌，请选择其他打开方式。</span><el-button size="small" @click="open('client')">{{ desktop ? '客户端窗口' : '新标签页' }}</el-button><el-button v-if="desktop" size="small" @click="open('browser')">外部浏览器</el-button><el-button size="small" link @click="failed">无法显示</el-button></footer>
  </section>
</template>
<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ArrowLeft, RefreshCw, X } from '@lucide/vue';
import { openExternalLink, restrictedHost } from '../utils/externalLinks';
const props = defineProps({ item: { type: Object, required: true } });
const emit = defineEmits(['close']);
const desktop = Boolean(window.__PRACTICAL_DESKTOP__);
const sequence = ref(0), loading = ref(true), error = ref('');
let timer;
const address = computed(() => {
  let url;
  try { url = new URL(props.item.url); } catch { return ''; }
  const schoolOrigin = window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin;
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || restrictedHost(url.hostname)
    || url.origin === window.location.origin || url.origin === new URL(schoolOrigin).origin) return '';
  for (const pair of props.item.request_config?.query || []) url.searchParams.set(pair.key, pair.value);
  return url.href;
});
function reload() { clearTimeout(timer); error.value = ''; loading.value = Boolean(address.value); if (!address.value) { error.value = '链接无效，或目标属于学校及内部网络'; return; } sequence.value++; timer = setTimeout(() => { error.value = '加载超时，请重试或改用客户端窗口、外部浏览器'; loading.value = false; }, 20000); }
function loaded() { clearTimeout(timer); loading.value = false; }
function failed() { clearTimeout(timer); loading.value = false; error.value = '目标站点无法在内容区显示，请改用其他打开方式'; }
async function open(mode) { try { await openExternalLink(props.item, mode); } catch (reason) { error.value = reason.message; } }
watch(() => props.item.url, reload, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));
</script>
<style scoped>
.embedded-external-page{display:flex;flex-direction:column;min-height:260px;height:100%;width:100%;background:var(--surface);color:var(--text)}header{display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid var(--line)}header strong{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}iframe{flex:1;min-height:0;width:100%;border:0;background:var(--surface)}.embedded-loading{padding:12px;text-align:center;color:var(--muted)}footer{display:flex;align-items:center;gap:8px;padding:8px 12px;border-top:1px solid var(--line);font-size:11px;color:var(--muted)}footer span{flex:1;line-height:1.5}
</style>
