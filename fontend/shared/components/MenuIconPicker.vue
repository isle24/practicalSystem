<template>
  <fieldset class="menu-icon-picker">
    <legend>菜单图标</legend>
    <p>选中即应用，仅保存在本机。</p>
    <div class="icon-style-options">
      <button v-for="option in menuIconStyles" :key="option.key" type="button"
        :aria-pressed="look.menuIconStyle.value === option.key" @click="choose(option.key)">
        <span class="icon-style-preview" aria-hidden="true">
          <AppIcon v-for="key in ['internship', 'practice', 'favorite']" :key="key" :menu-key="key" :icon-style="option.key" :size="28" />
        </span>
        <span class="icon-style-name">{{ option.name }}</span>
      </button>
    </div>
    <small v-if="error" role="alert">{{ error }}</small>
  </fieldset>
</template>

<script setup>
import { ref } from 'vue';
import { menuIconStyles } from '../uiStyles.js';
import AppIcon from './AppIcon.vue';

const props = defineProps({ look: { type: Object, required: true } });
const error = ref('');
function choose(value) {
  try { props.look.chooseMenuIcons(value); error.value = ''; }
  catch { error.value = '菜单图标保存失败，请检查本机存储'; }
}
</script>

<style scoped>
.menu-icon-picker { min-width: 0; margin: 0; padding: 0; border: 0; }
legend { padding: 0; color: var(--text, inherit); font-size: 14px; font-weight: 600; }
p { margin: 4px 0 10px; color: var(--muted, #64748b); font-size: 12px; }
.icon-style-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 119px), 1fr)); gap: 8px; }
button {
  display: grid; justify-items: center; gap: 10px; min-width: 0; padding: 12px 5px 9px;
  border: 1px solid var(--line, #cbd5e1); border-radius: 8px;
  color: var(--text, inherit); background: var(--surface, #fff); font: inherit; cursor: pointer;
}
button[aria-pressed=true] { border-color: var(--primary, #2563eb); box-shadow: inset 0 0 0 1px var(--primary, #2563eb); }
button:focus-visible { outline: 2px solid var(--primary, #2563eb); outline-offset: 2px; }
.icon-style-preview, .icon-style-name { display: inline-flex; align-items: center; gap: 6px; }
.icon-style-name { font-size: 12px; }
.icon-style-name::before { content: ''; width: 8px; height: 8px; border: 1px solid var(--muted, #64748b); border-radius: 50%; }
[aria-pressed=true] .icon-style-name::before { border-color: var(--primary, #2563eb); background: var(--primary, #2563eb); }
small { display: block; margin-top: 6px; color: var(--el-color-danger, #b42318); }
</style>
