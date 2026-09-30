<template>
  <header class="desktop-native-titlebar" :class="{ mac: platform === 'macos' }">
    <div class="native-title-drag" @pointerdown="drag" @dblclick="act('maximize')"><span>实践管理系统</span></div>
    <div v-if="active" class="native-title-user"><slot /></div>
    <div v-if="platform !== 'macos'" class="native-title-controls">
      <button type="button" aria-label="最小化" title="最小化" @click="act('minimize')"><Minus :size="14" /></button>
      <button type="button" :aria-label="maximized ? '还原' : '最大化'" :title="maximized ? '还原' : '最大化'" @click="act('maximize')"><Copy v-if="maximized" :size="13" /><Square v-else :size="13" /></button>
      <button type="button" class="native-title-close" aria-label="关闭窗口" title="关闭窗口" @click="act('close')"><X :size="17" /></button>
    </div>
  </header>
</template>
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Copy, Minus, Square, X } from '@lucide/vue';
defineProps({ active: { type: Boolean, default: false } });
const emit = defineEmits(['window-action', 'error']);
const bridge = window.__PRACTICAL_DESKTOP__;
const platform = bridge?.platform || '';
const maximized = ref(false);
let timer;
async function status() { try { const result = await bridge?.windowControls('status'); maximized.value = Boolean(result?.maximized); } catch {} }
async function act(action) { emit('window-action'); try { const result = await bridge?.windowControls(action); if (result) maximized.value = Boolean(result.maximized); } catch (error) { emit('error', error.message); } }
function drag(event) { if (event.button === 0 && event.detail === 1) act('drag'); }
onMounted(() => { status(); timer = setInterval(status, 2000); });
onBeforeUnmount(() => clearInterval(timer));
</script>
<style scoped>
.desktop-native-titlebar{height:42px;display:flex;align-items:center;background:var(--surface);color:var(--text);border-bottom:1px solid var(--line);position:relative;z-index:10005;user-select:none}.native-title-drag{display:flex;align-items:center;align-self:stretch;flex:1;min-width:70px;padding:0 16px;font-size:12px;cursor:default}.native-title-user{display:flex;align-items:center;min-width:0;padding:0 10px}.native-title-controls{display:flex;height:42px;flex:none}.native-title-controls button{border:0;background:transparent;color:inherit;width:46px;display:grid;place-items:center;cursor:pointer}.native-title-controls button:hover{background:var(--surface-2)}.native-title-controls .native-title-close:hover{background:#d92d36;color:#fff}.mac .native-title-drag{padding-left:82px}.native-title-user :deep(.taskbar-status){gap:10px}.native-title-user :deep(.taskbar-online){font-size:11px}.native-title-user :deep(.taskbar-switch-account){position:relative}.native-title-user :deep(.switch-account-menu){top:calc(100% + 8px);bottom:auto;right:0;z-index:10010}@media(max-width:1000px){.native-title-user :deep(.taskbar-operator-name),.native-title-user :deep(.taskbar-version),.native-title-user :deep(.taskbar-online){display:none}}@media(max-width:700px){.native-title-user{display:none}}
</style>
