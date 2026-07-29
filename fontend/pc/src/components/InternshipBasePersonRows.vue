<template>
  <section class="internship-edit-section">
    <header>
      <div><strong>{{ title }}</strong><small>支持维护多名人员</small></div>
      <el-button v-if="!readonly" :icon="Plus" size="small" @click="addRow">新增</el-button>
    </header>
    <el-table v-if="readonly && model.length" :data="model" stripe size="small">
      <el-table-column prop="name" label="姓名" width="110" />
      <el-table-column prop="gender" label="性别" width="72" />
      <el-table-column prop="birth_date" label="出生日期" width="112" />
      <el-table-column prop="title" label="职务/职称" min-width="130" show-overflow-tooltip />
      <el-table-column prop="education" label="学历" width="100" />
      <el-table-column prop="phone" label="联系电话" width="130" />
      <el-table-column prop="duties" label="主要职责" min-width="220" show-overflow-tooltip />
    </el-table>
    <div v-else-if="model.length" class="dynamic-row-list">
      <div v-for="(item, index) in model" :key="`${title}-${index}`" class="dynamic-form-row person-row">
        <label><span>姓名</span><input v-model="item.name" :disabled="readonly"></label>
        <label>
          <span>性别</span>
          <el-select v-model="item.gender" clearable :disabled="readonly">
            <el-option label="男" value="男" />
            <el-option label="女" value="女" />
          </el-select>
        </label>
        <label><span>出生日期</span><input v-model="item.birth_date" type="date" :disabled="readonly"></label>
        <label><span>职务/职称</span><input v-model="item.title" :disabled="readonly"></label>
        <label><span>学历</span><input v-model="item.education" :disabled="readonly"></label>
        <label><span>联系电话</span><input v-model="item.phone" :disabled="readonly"></label>
        <label class="wide-field"><span>主要职责</span><input v-model="item.duties" :disabled="readonly"></label>
        <el-button v-if="!readonly" text type="danger" :icon="Trash2" @click="model.splice(index, 1)">删除</el-button>
      </div>
    </div>
    <el-empty v-else :description="`暂无${title}`" :image-size="52" />
  </section>
</template>

<script setup>
import { Plus, Trash2 } from '@lucide/vue';

defineProps({
  readonly: { type: Boolean, default: false },
  title: { type: String, required: true },
});

const model = defineModel({ type: Array, default: () => [] });

function addRow() {
  model.value.push({
    user_id: null,
    name: '',
    gender: '',
    birth_date: '',
    title: '',
    education: '',
    phone: '',
    duties: '',
  });
}
</script>
