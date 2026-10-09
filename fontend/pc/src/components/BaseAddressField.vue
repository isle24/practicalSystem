<template>
  <div class="base-address-field">
    <div class="base-address-mode">
      <el-button text type="primary" @click="manual = !manual">{{ manual ? '使用联动选择' : '手动填写省市区' }}</el-button>
      <el-button text :disabled="!mapKeyword" @click="openMap">地图查找</el-button>
    </div>
    <div v-if="manual" class="base-address-regions">
      <el-input v-model="model.province_name" placeholder="省 / 直辖市" aria-label="省份" @input="clearCodes" />
      <el-input v-model="model.city_name" placeholder="城市" aria-label="城市" @input="clearCodes" />
      <el-input v-model="model.district_name" placeholder="区 / 县" aria-label="区县" @input="clearCodes" />
    </div>
    <el-cascader v-else :model-value="regionPath" :options="regions" :props="{ value: 'code', label: 'name', children: 'children' }" filterable clearable placeholder="请选择省 / 市 / 区县" @update:model-value="selectRegion" />
    <el-input v-if="model.province_name" v-model="model.address_detail" placeholder="街道、门牌号、楼栋等详细地址" aria-label="详细地址" @input="updateAddress" />
    <template v-else-if="model.address"><el-input v-model="model.address" placeholder="历史完整地址" aria-label="历史完整地址" /><small>已保留历史地址，重新选择省市区后可填写详细地址。</small></template>
    <el-input v-else v-model="model.address_detail" placeholder="街道、门牌号、楼栋等详细地址" aria-label="详细地址" />
    <small>区划选项采用 2023 年数据；新设区划可手动填写。</small>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import regions from '../../../shared/data/pca-code.json';
import { ElCascader, ElMessage } from 'element-plus';
import { openExternalLink } from '../utils/externalLinks';
const model = defineModel({ type: Object, required: true });
const manual = ref(false);
const regionPath = computed(() => [model.value.province_code, model.value.city_code, model.value.district_code].every(Boolean) ? [model.value.province_code, model.value.city_code, model.value.district_code] : []);
const mapKeyword = computed(() => [model.value.province_name, model.value.city_name, model.value.district_name, model.value.address_detail].filter(Boolean).join('') || model.value.address || '');
watch(() => model.value.id, () => { manual.value = Boolean(model.value.province_name && !model.value.province_code); }, { immediate: true });
function clearCodes() { for (const key of ['province_code', 'city_code', 'district_code']) model.value[key] = ''; updateAddress(); }
function updateAddress() {
  if (model.value.province_name && model.value.city_name && model.value.district_name) model.value.address = [...new Set([model.value.province_name, model.value.city_name, model.value.district_name])].join('') + model.value.address_detail;
}
function selectRegion(path) {
  const names = ['province', 'city', 'district'];
  let items = regions;
  for (let index = 0; index < names.length; index++) {
    const selected = items.find(item => item.code === path?.[index]);
    model.value[`${names[index]}_code`] = selected?.code || '';
    model.value[`${names[index]}_name`] = selected?.name || '';
    items = selected?.children || [];
  }
  if (!path?.length) model.value.address_detail = '';
  updateAddress();
}
function openMap() {
  if (mapKeyword.value) openExternalLink({ url: `https://map.baidu.com/search/${encodeURIComponent(mapKeyword.value)}` }, 'browser').catch(error => ElMessage.error(error.message));
}
</script>

<style scoped>
.base-address-field { display: grid; gap: 8px; min-width: 0; }
.base-address-field .el-cascader { width: 100%; }
.base-address-mode { display: flex; justify-content: flex-end; gap: 8px; }
.base-address-regions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
small { color: var(--muted); font-size: 12px; line-height: 1.6; }
@media (max-width: 600px) { .base-address-regions { grid-template-columns: 1fr; } }
</style>
