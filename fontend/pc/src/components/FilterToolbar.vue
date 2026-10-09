<template>
  <div class="shared-filter-toolbar">
    <div v-show="visible" class="shared-filter-fields"><slot /></div>
    <div class="shared-filter-actions">
      <el-button :aria-expanded="visible" @click="visible = !visible">{{ visible ? '隐藏筛选' : '展开筛选' }}<span v-if="count">（{{ count }}）</span></el-button>
      <slot name="actions" />
    </div>
  </div>
</template>
<script setup>
import { computed, ref } from 'vue';
import { activeFilterCount } from '../composables/viewportArea';
const props = defineProps({ values: { type: Object, default: () => ({}) } });
const visible = ref(true);
const count = computed(() => activeFilterCount(props.values));
</script>
