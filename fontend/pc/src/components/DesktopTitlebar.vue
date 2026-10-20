<template>
  <button v-if="autoHide" type="button" class="native-title-edge" aria-label="显示窗口控制" aria-controls="desktop-native-titlebar" @pointerenter="hovered = true" @focus="hovered = true" @blur="hovered = false" @pointerleave="hovered = false" />
  <header id="desktop-native-titlebar" class="desktop-native-titlebar" :class="{ mac: platform === 'macos', revealed: !autoHide || hovered || menuOpen }" @pointerenter="hovered = true" @pointerleave="hovered = false">
    <div class="native-title-drag" @mousedown="drag" @dblclick="act('maximize')"><span>实践系统</span></div>
    <button v-if="active && bridge?.agent" type="button" class="native-title-agent" title="打开 Agent 独立模块" @click="openAgent"><Bot :size="16" /><span>Agent</span></button>
    <div v-if="active" class="native-title-user"><slot /></div>
    <div class="native-title-controls">
      <button type="button" aria-label="最小化" title="最小化" @click="act('minimize')"><Minus :size="14" /></button>
      <button type="button" :aria-label="maximized ? '还原' : '最大化'" :title="maximized ? '还原' : '最大化'" @click="act('maximize')"><Copy v-if="maximized" :size="13" /><Square v-else :size="13" /></button>
      <button type="button" class="native-title-close" aria-label="关闭窗口" title="关闭窗口" @click="act('close')"><X :size="17" /></button>
    </div>
  </header>
</template>
<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Bot, Copy, Minus, Square, X } from '@lucide/vue';
const props = defineProps({ active: { type: Boolean, default: false }, autoHide: { type: Boolean, default: false }, menuOpen: Boolean, maximized: Boolean, controls: { type: Function, required: true } });
const emit = defineEmits(['window-action', 'error']);
const bridge = window.__PRACTICAL_DESKTOP__;
const platform = bridge?.platform || '';
const hovered = ref(false);
async function act(action) { emit('window-action'); try { await props.controls(action); } catch (error) { emit('error', error.message); } }
async function openAgent() { emit('window-action'); try { await bridge.agent('open'); } catch (error) { emit('error', error.message); } }
function drag(event) { if (event.button === 0 && event.detail === 1) act('drag'); }
watch(() => props.autoHide, value => { hovered.value = false; document.documentElement.style.setProperty('--desktop-top-inset', value ? '0px' : '42px'); }, { immediate: true, flush: 'sync' });
onBeforeUnmount(() => { document.documentElement.style.removeProperty('--desktop-top-inset'); });
</script>
<style scoped>
.native-title-agent{display:flex;align-items:center;gap:6px;margin:0 8px;padding:5px 9px;border:1px solid var(--line,#dce2ea);border-radius:6px;background:var(--surface,#fff);color:var(--primary,#236bc1);font-size:12px;cursor:pointer}.native-title-agent:hover{background:var(--surface-2)}
.desktop-native-titlebar{height:42px;box-sizing:border-box;display:flex;align-items:center;background:var(--surface,#ffffff);background:color-mix(in srgb,var(--surface,#ffffff) 78%,transparent);color:var(--text,#202b3a);backdrop-filter:blur(20px) saturate(140%);-webkit-backdrop-filter:blur(20px) saturate(140%);border-bottom:1px solid var(--line,#dce2ea);position:fixed;inset:0 0 auto;z-index:20000;user-select:none;transform:translateY(-100%);transition:transform .16s ease}.desktop-native-titlebar.revealed,.desktop-native-titlebar:focus-within{transform:translateY(0)}.native-title-edge{position:fixed;inset:0 0 auto;height:10px;z-index:20001;border:0;padding:0;background:transparent;cursor:default}.native-title-edge:focus-visible{outline:2px solid var(--primary);outline-offset:-2px}.native-title-drag{display:flex;align-items:center;align-self:stretch;flex:1;min-width:70px;padding:0 16px;font-size:12px;cursor:default}.native-title-user{display:flex;align-items:center;min-width:0;padding:0 10px}.native-title-controls{display:flex;height:100%;flex:none}.native-title-controls button{border:0;background:transparent;color:inherit;width:46px;display:grid;place-items:center;cursor:pointer}.native-title-controls button:hover{background:var(--surface-2)}.native-title-controls .native-title-close:hover{background:#d92d36;color:#fff}.native-title-user :deep(.taskbar-status){gap:10px}.native-title-user :deep(.taskbar-online){font-size:11px}.native-title-user :deep(.taskbar-switch-account){position:relative}.native-title-user :deep(.switch-account-menu){top:calc(100% + 8px);bottom:auto;right:0;z-index:10010}@media(max-width:1000px){.native-title-user :deep(.taskbar-operator-name),.native-title-user :deep(.taskbar-version),.native-title-user :deep(.taskbar-online){display:none}}@media(max-width:700px){.native-title-drag{min-width:0;padding:0 8px}.native-title-drag span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.native-title-user{padding:0 4px}.native-title-controls button{width:34px}}
@media(prefers-reduced-motion:reduce){.desktop-native-titlebar{transition:none}}
</style>
