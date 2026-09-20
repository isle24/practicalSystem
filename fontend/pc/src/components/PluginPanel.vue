<template>
  <section class="plugin-panel">
    <header class="plugin-toolbar">
      <div>
        <h2>插件中心</h2>
        <p>仅提供经过学校配置的安全 Web/OAuth 入口。</p>
      </div>
      <div class="plugin-search">
        <el-input v-model="keyword" clearable placeholder="搜索插件" @keyup.enter="load" />
        <el-button :icon="Search" :loading="loading" @click="load">查询</el-button>
        <el-button v-if="canManage" type="primary" :icon="Plus" @click="edit()">新增</el-button>
      </div>
    </header>
    <el-alert v-if="error" type="warning" :closable="false" show-icon :title="error" />
    <div class="plugin-grid" v-loading="loading">
      <article v-for="item in items" :key="item.id" class="plugin-card">
        <div class="plugin-card-icon">
          <img v-if="item.icon_url" :src="backendUrl(item.icon_url)" :alt="item.name">
          <Globe2 v-else :size="28" />
        </div>
        <div class="plugin-card-body">
          <header><strong>{{ item.name }}</strong><small>v{{ item.version || '1.0.0' }}</small></header>
          <p>{{ item.description || '暂无说明' }}</p>
          <small class="plugin-permission">{{ item.permission_description || '由学校管理员配置入口权限。' }}</small>
          <el-button type="primary" :icon="ExternalLink" @click="open(item)">打开</el-button>
          <el-button v-if="canManage" link type="primary" @click="edit(item)">编辑</el-button>
          <el-button v-if="canManage" link type="danger" @click="remove(item)">删除</el-button>
        </div>
      </article>
      <el-empty v-if="!loading && !items.length" description="暂无可用插件" />
    </div>
    <OperationDialog :visible="dialog.visible" :title="dialog.form.id ? '编辑插件' : '新增插件'" :busy="saving" @close="dialog.visible = false">
      <div class="plugin-form">
        <label><span>插件编码</span><el-input v-model="dialog.form.code" :disabled="Boolean(dialog.form.id)" placeholder="如 cloud-storage" /></label>
        <label><span>名称</span><el-input v-model="dialog.form.name" placeholder="请输入插件名称" /></label>
        <label><span>版本</span><el-input v-model="dialog.form.version" placeholder="1.0.0" /></label>
        <label><span>打开方式</span><el-select v-model="dialog.form.open_mode" placeholder="请选择打开方式"><el-option label="客户端窗口" value="client" /><el-option label="外部浏览器" value="browser" /></el-select></label>
        <label class="wide"><span>HTTPS 入口</span><el-input v-model="dialog.form.entry_url" placeholder="https://example.com/oauth/start" /></label>
        <label class="wide"><span>允许域名</span><el-input v-model="dialog.form.allowed_domains_text" placeholder="多个域名用逗号分隔" /></label>
        <label class="wide"><span>图标地址</span><el-input v-model="dialog.form.icon_url" placeholder="可选 HTTPS 图片地址" /></label>
        <label class="wide"><span>说明</span><el-input v-model="dialog.form.description" type="textarea" :rows="2" /></label>
        <label class="wide"><span>权限说明</span><el-input v-model="dialog.form.permission_description" type="textarea" :rows="2" /></label>
        <label><span>排序</span><el-input-number v-model="dialog.form.sort" :min="0" :max="99999" /></label>
        <label><span>状态</span><el-switch v-model="dialog.form.status" active-value="enabled" inactive-value="disabled" active-text="启用" inactive-text="停用" /></label>
      </div>
      <template #footer><el-button @click="dialog.visible = false">取消</el-button><el-button type="primary" :loading="saving" @click="save">保存</el-button></template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { ExternalLink, Globe2, Plus, Search } from '@lucide/vue';
import { backendUrl, request } from '../api/client';
import { openExternalLink } from '../utils/externalLinks';
import OperationDialog from './OperationDialog.vue';

const props = defineProps({ canManage: { type: Boolean, default: false } });
const canManage = ref(props.canManage);
const items = ref([]);
const keyword = ref('');
const loading = ref(false);
const error = ref('');
const saving = ref(false);
const dialog = ref({ visible: false, form: emptyForm() });

function emptyForm(item = {}) {
  return { id: item.id || null, code: item.code || '', name: item.name || '', version: item.version || '1.0.0', entry_url: item.entry_url || '', open_mode: item.open_mode || 'browser', allowed_domains_text: (item.allowed_domains || []).join(', '), icon_url: item.icon_url || '', description: item.description || '', permission_description: item.permission_description || '', sort: Number(item.sort || 100), status: item.status || 'enabled' };
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await request(`/plugin/list?${new URLSearchParams({ keyword: keyword.value, page: 1, page_size: 100 })}`);
    items.value = data.items || [];
    canManage.value = Boolean(data.can_manage);
  } catch (reason) {
    error.value = reason.message || '插件目录加载失败';
  } finally {
    loading.value = false;
  }
}

function edit(item = {}) {
  dialog.value = { visible: true, form: emptyForm(item) };
}

async function save() {
  if (saving.value) return;
  saving.value = true;
  try {
    const form = dialog.value.form;
    await request('/plugin/save', { method: 'POST', body: JSON.stringify({ ...form, allowed_domains: form.allowed_domains_text.split(',').map(value => value.trim()).filter(Boolean) }) });
    dialog.value.visible = false;
    await load();
  } catch (reason) { ElMessage.error(reason.message || '插件保存失败'); }
  finally { saving.value = false; }
}

async function remove(item) {
  if (!window.confirm(`确认删除插件“${item.name}”？`)) return;
  try { await request('/plugin/delete', { method: 'POST', body: JSON.stringify({ id: item.id }) }); await load(); }
  catch (reason) { ElMessage.error(reason.message || '插件删除失败'); }
}

async function open(item) {
  try { await openExternalLink({ url: item.entry_url, open_mode: item.open_mode || 'browser' }); }
  catch (reason) { ElMessage.error(reason.message || '插件打开失败'); }
}

onMounted(load);
</script>

<style scoped>
.plugin-panel{display:flex;flex-direction:column;gap:18px;padding:22px;min-height:0;height:100%;overflow:auto}
.plugin-toolbar{display:flex;align-items:center;justify-content:space-between;gap:18px}
.plugin-toolbar h2{margin:0;font-size:20px}
.plugin-toolbar p{margin:5px 0 0;color:#788596;font-size:13px}
.plugin-search{display:flex;gap:8px;min-width:300px}
.plugin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;align-content:start}
.plugin-card{display:flex;gap:14px;padding:17px;border:1px solid #e4e9ef;border-radius:10px;background:#fff;min-width:0}
.plugin-card-icon{width:52px;height:52px;display:grid;place-items:center;flex:none;border-radius:13px;background:#edf5ff;color:#2875c5}
.plugin-card-icon img{width:100%;height:100%;object-fit:cover;border-radius:13px}
.plugin-card-body{display:grid;gap:8px;min-width:0;flex:1}
.plugin-card-body header{display:flex;align-items:center;justify-content:space-between;gap:10px}
.plugin-card-body header small,.plugin-permission{color:#8793a2;font-size:11px}
.plugin-card-body p{margin:0;color:#536173;font-size:13px;line-height:1.55}
.plugin-card-body .el-button{justify-self:start}
.plugin-permission{line-height:1.45}
.plugin-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 20px;padding:4px 2px;min-width:0}
.plugin-form label{display:grid;gap:6px;color:#5e6b7a;font-size:13px;min-width:0}
.plugin-form .wide{grid-column:1/-1}
@media(max-width:620px){.plugin-toolbar{display:grid}.plugin-search{min-width:0}}
@media(max-width:560px){.plugin-form{grid-template-columns:1fr}.plugin-form .wide{grid-column:auto}}
</style>
