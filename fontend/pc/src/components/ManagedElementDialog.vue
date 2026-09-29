<script setup>
import { ref, useSlots, watch } from 'vue';
import { Maximize2, Minimize2 } from '@lucide/vue';
import { useMaximizedWindow } from '../composables/useMaximizedWindows';

defineOptions({ inheritAttrs: false });
const props = defineProps({ modelValue: Boolean });
const emit = defineEmits(['update:modelValue']);
const slots = useSlots();
const maximized = ref(false);
const registration = useMaximizedWindow();
watch(() => [props.modelValue, maximized.value], ([visible, full]) => {
  if (!visible) maximized.value = false;
  if (visible && full) registration.register();
  else registration.unregister();
}, { flush: 'sync' });
</script>

<template>
  <el-dialog v-bind="$attrs" :model-value="modelValue" :fullscreen="maximized" :class="{ 'managed-element-fullscreen': maximized }" @update:model-value="emit('update:modelValue', $event)">
    <template #header="headerProps">
      <div class="managed-element-header">
        <div><slot name="header" v-bind="headerProps">{{ $attrs.title }}</slot></div>
        <button type="button" :title="maximized ? '还原' : '最大化'" :aria-label="maximized ? '还原' : '最大化'" @click="maximized = !maximized">
          <Minimize2 v-if="maximized" :size="16" /><Maximize2 v-else :size="16" />
        </button>
      </div>
    </template>
    <template v-for="name in Object.keys(slots).filter(name => name !== 'header')" :key="name" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps || {}" />
    </template>
  </el-dialog>
</template>

<style>
.managed-element-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-right: 24px; }
.managed-element-header button { display: grid; place-items: center; width: 28px; height: 28px; background: transparent; border-radius: 4px; color: #606873; }
.managed-element-header button:hover { background: #edf1f7; }
.el-dialog.managed-element-fullscreen { margin: 0; width: 100%; height: 100dvh; max-height: none; border-radius: 0; display: flex; flex-direction: column; }
.managed-element-fullscreen > .el-dialog__body { min-height: 0; flex: 1; overflow: auto; max-height: none !important; }
.managed-element-fullscreen .archive-detail-body, .managed-element-fullscreen .appraisal-body { max-height: none; }
</style>
