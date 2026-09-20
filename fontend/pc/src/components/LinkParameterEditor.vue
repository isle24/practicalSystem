<template><section class="link-parameters"><header><strong>{{ title }}</strong><button type="button" :disabled="modelValue.length >= 20" :title="`添加${title}`" @click="update([...modelValue, { key: '', value: '' }])"><Plus :size="16" /></button></header><div v-for="(row,index) in modelValue" :key="index" class="parameter-row"><el-input :model-value="row.key" placeholder="参数名" maxlength="80" @update:model-value="value => change(index,'key',value)" /><el-input :model-value="row.value" :type="secret ? 'password' : 'text'" :show-password="secret" placeholder="参数值" maxlength="4000" @update:model-value="value => change(index,'value',value)" /><button type="button" title="移除参数" @click="update(modelValue.filter((_,i) => i !== index))"><X :size="16" /></button></div></section></template>
<script setup>
import { Plus, X } from '@lucide/vue';
const props = defineProps({ modelValue: { type: Array, default: () => [] }, title: String, secret: Boolean });
const emit = defineEmits(['update:modelValue']);
function update(value) { emit('update:modelValue', value); }
function change(index, key, value) { update(props.modelValue.map((row,i) => i === index ? { ...row, [key]:value } : row)); }
</script>
<style scoped>.link-parameters{display:flex;flex-direction:column;gap:10px;min-width:0}.link-parameters header{display:flex;align-items:center;justify-content:space-between;font-size:13px;padding:0;border:0;background:transparent;min-height:30px}.link-parameters button{display:grid;place-items:center;border:0;background:transparent;color:#426486;min-width:30px;height:30px;cursor:pointer}.parameter-row{display:grid;grid-template-columns:minmax(90px,1fr) minmax(130px,2fr) 30px;gap:8px;align-items:center}</style>
