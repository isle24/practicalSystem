<template>
  <ElTooltip
    :content="label"
    :disabled="disabled"
    placement="top"
    effect="light"
    popper-class="dock-tooltip"
    :trigger="['hover', 'focus']"
    :trigger-keys="[]"
    :show-after="180"
    :hide-after="0"
    :offset="14"
    :enterable="false"
  >
    <slot />
  </ElTooltip>
</template>

<script setup>
import { ElTooltip } from 'element-plus';

defineProps({ label: { type: String, required: true }, disabled: Boolean });
</script>

<style>
.el-popper.dock-tooltip {
  --el-bg-color-overlay: var(--surface);
  --el-border-color-light: var(--line);
  --el-transition-fade-linear: opacity .12s ease;
  max-width: min(260px, calc(100vw - 24px));
  padding: 5px 10px;
  border-radius: 10px;
  box-shadow: 0 4px 14px #17213524;
  color: var(--text);
  font-size: 12px;
  line-height: 18px;
  overflow-wrap: anywhere;
}

@media (prefers-reduced-motion: reduce) {
  .el-popper.dock-tooltip { transition: none; }
}
</style>
