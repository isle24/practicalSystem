<script setup>
import { FOLLOW_SCHOOL, uiStyles } from '../uiStyles';

defineProps({
  modelValue: { type: String, required: true },
  label: { type: String, default: '界面风格' },
  school: { type: String, default: '' },
  disabled: Boolean,
});
defineEmits(['update:modelValue']);
</script>

<template>
  <fieldset class="ui-style-picker" :disabled="disabled">
    <legend>{{ label }}</legend>
    <div class="ui-style-options">
      <button v-if="school" type="button" :aria-pressed="modelValue === FOLLOW_SCHOOL" @click="$emit('update:modelValue', FOLLOW_SCHOOL)">
        <span class="style-sample" :data-sample="school" aria-hidden="true"><i /></span><span>跟随学校</span>
      </button>
      <button v-for="style in uiStyles" :key="style.key" type="button" :aria-pressed="modelValue === style.key" @click="$emit('update:modelValue', style.key)">
        <span class="style-sample" :data-sample="style.key" aria-hidden="true"><i /></span><span>{{ style.name }}</span>
      </button>
    </div>
  </fieldset>
</template>

<style scoped>
.ui-style-picker { min-width: 0; margin: 0; padding: 0; border: 0; }
.ui-style-picker legend { margin: 0 0 10px; padding: 0; color: var(--text); font-size: 14px; font-weight: 600; }
.ui-style-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 8px; }
.ui-style-options button { min-width: 0; padding: 5px 5px 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; font-size: 12px; cursor: pointer; }
.ui-style-options button[aria-pressed=true] { border-color: var(--primary); box-shadow: inset 0 0 0 1px var(--primary); }
.ui-style-options button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.style-sample { display: grid; place-items: center; height: 42px; margin-bottom: 6px; overflow: hidden; border-radius: 4px; background: #c6d7e7; }
.style-sample i { display: block; width: 60%; height: 67%; border-radius: 2px; border: 1px solid #c2ccda; border-top: 5px solid #2563eb; background: #fff; }
[data-sample=liquid_glass] { background: linear-gradient(125deg,#c8dffb 12%,#f6f8ff 38%,#a7c7f3 55%,#e6edfa 79%); }
[data-sample=liquid_glass] i { border: 1px solid #ffffffc4; border-radius: 8px; background: #ffffff75; box-shadow: 0 2px 6px #30527c28; }
[data-sample=obsidian_pro] { background: radial-gradient(#a9abb5 .7px, transparent .8px) 0 0 / 5px 5px, #f6f6f7; }
[data-sample=obsidian_pro] i { border: 1px solid #72727c; border-left: 4px solid #29292d; }
[data-sample=ivory_editorial] { background: #eee7d9; }
[data-sample=ivory_editorial] i { border: 1px solid #c9bea9; border-top: 3px solid #9f3024; background: #fffcf5; border-radius: 0; }
</style>
