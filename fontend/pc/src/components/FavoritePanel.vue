<template>
  <section class="favorites">
    <header class="favorites-toolbar"><el-input v-model="keyword" clearable placeholder="搜索标题或网址" @keyup.enter="load(1)" /><el-select v-model="scope" placeholder="请选择收藏范围" @change="load(1)"><el-option label="全部收藏" value="" /><el-option label="我的收藏" value="personal" /><el-option label="学校共享" value="school" /></el-select><el-button :icon="Search" :loading="loading" @click="load(1)">查询</el-button><el-button :icon="Plus" type="primary" @click="edit()">新增收藏</el-button></header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div class="favorites-grid" v-loading="loading">
      <article v-for="row in items" :key="row.id" class="favorite-tile">
        <button class="favorite-open" @click="open(row)"><AppIcon :icon="Globe2" :icon-url="row.icon_url" :label="row.title" :size="28" :backend-url="backendUrl" /><span><strong>{{ row.title }}</strong><small>{{ host(row.url) }}</small></span></button>
        <div class="favorite-tile-footer"><span :class="{ shared: row.scope === 'school' }">{{ row.scope === 'school' ? '学校共享' : '个人收藏' }}</span><el-dropdown trigger="click" @command="command => action(row, command)"><el-button :icon="MoreHorizontal" text circle title="更多操作" /><template #dropdown><el-dropdown-menu><el-dropdown-item command="client">{{ desktop ? '客户端窗口打开' : '新标签页打开' }}</el-dropdown-item><el-dropdown-item v-if="desktop" command="browser">外部浏览器打开</el-dropdown-item><el-dropdown-item command="desktop">{{ isDesktop(row.id) ? '从桌面移除' : '添加到桌面' }}</el-dropdown-item><el-dropdown-item v-if="row.can_edit" command="edit" divided>编辑</el-dropdown-item><el-dropdown-item v-if="row.can_edit" command="delete">删除</el-dropdown-item></el-dropdown-menu></template></el-dropdown></div>
      </article>
      <el-empty v-if="!loading && !items.length" description="暂无收藏" />
    </div>
    <footer class="favorites-pagination"><span>共 {{ pagination.total }} 个收藏</span><el-pagination layout="prev, pager, next" :current-page="pagination.page" :page-size="pagination.page_size" :total="pagination.total" @current-change="load" /></footer>
    <OperationDialog :visible="dialog" :title="form.id ? '编辑收藏' : '新增收藏'" :busy="saving || uploading" @close="dialog = false">
      <div class="favorites-form" :inert="saving"><label>标题<el-input v-model="form.title" maxlength="180" placeholder="请输入收藏标题" /></label><label>网址<el-input v-model="form.url" maxlength="500" placeholder="https://example.com" /></label><label>默认打开方式<el-radio-group v-model="form.open_mode"><el-radio-button value="client">客户端窗口</el-radio-button><el-radio-button value="browser">外部浏览器</el-radio-button></el-radio-group></label><label v-if="canShare" class="favorites-switch">同步到全校<el-switch v-model="form.scope" active-value="school" inactive-value="personal" /></label><label class="favorites-switch">添加到我的桌面<el-switch v-model="form.on_desktop" /></label><label>排序<el-input-number v-model="form.sort" :min="0" :max="99999" /></label><label>图标<IconUpload :icon="Globe2" :icon-url="form.icon_url" label="收藏图标" :uploading="uploading" :backend-url="backendUrl" @select="upload" /></label></div>
      <template #footer><el-button :disabled="saving || uploading" @click="dialog = false">取消</el-button><el-button type="primary" :loading="saving" :disabled="uploading" @click="save">保存</el-button></template>
    </OperationDialog>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Plus, Search, Globe2, MoreHorizontal } from '@lucide/vue';
import { ElMessageBox, ElMessage } from 'element-plus';
import { request, backendUrl } from '../api/client';
import { openExternalLink } from '../utils/externalLinks';
import AppIcon from './AppIcon.vue';
import IconUpload from './IconUpload.vue';
import OperationDialog from './OperationDialog.vue';
const props = defineProps({ isDesktop: { type: Function, required: true }, setDesktop: { type: Function, required: true } });
const emit = defineEmits(['changed']);
const items = ref([]), keyword = ref(''), scope = ref(''), canShare = ref(false), error = ref('');
const loading = ref(false), saving = ref(false), uploading = ref(false), dialog = ref(false), form = ref({});
const pagination = ref({ page: 1, page_size: 20, total: 0 });
const desktop = Boolean(window.__PRACTICAL_DESKTOP__);
let sequence = 0, originalScope = 'personal';
const host = url => { try { return new URL(url).host; } catch { return url; } };
async function load(page = 1) {
  const ticket = ++sequence; loading.value = true; error.value = '';
  try { const data = await request(`/favorite/list?${new URLSearchParams({ keyword: keyword.value, scope: scope.value, page, page_size: 20 })}`); if (ticket === sequence) { items.value = data.items; pagination.value = data.pagination; canShare.value = data.can_share; } }
  catch (e) { error.value = e.message; } finally { if (ticket === sequence) loading.value = false; }
}
function edit(row = {}) { form.value = { id: row.id || null, title: row.title || '', url: row.url || '', scope: row.scope || 'personal', open_mode: row.open_mode || 'client', sort: Number(row.sort || 0), icon_file_id: row.icon_file_id || null, icon_url: row.icon_url || '', revision: row.revision || 0, on_desktop: row.id ? props.isDesktop(row.id) : true }; originalScope = form.value.scope; dialog.value = true; }
async function open(row, mode) { try { await openExternalLink(row, mode); } catch (e) { ElMessage.error(e.message); } }
async function save() {
  if (saving.value || uploading.value) return;
  saving.value = true;
  try {
    if (form.value.scope !== originalScope || (!form.value.id && form.value.scope === 'school')) {
      try { await ElMessageBox.confirm(form.value.scope === 'school' ? '此收藏将对全校用户可见，确认保存？' : '取消共享后其他用户将无法使用此快捷方式，确认继续？', '确认共享范围'); } catch { return; }
    }
    const { item } = await request('/favorite/save', { method: 'POST', body: JSON.stringify(form.value) });
    form.value = { ...form.value, ...item }; originalScope = item.scope;
    await props.setDesktop(item, form.value.on_desktop && item.can_open !== false);
    dialog.value = false; await load(1); ElMessage.success('已保存');
  } catch (e) { ElMessage.error(e.message); }
  finally { saving.value = false; emit('changed'); }
}
async function upload(file) { uploading.value = true; try { const body = new FormData(); body.append('file', file); const data = await request('/favorite/upload-icon', { method: 'POST', body }); form.value.icon_file_id = data.file_id; form.value.icon_url = data.url; } catch (e) { ElMessage.error(e.message); } finally { uploading.value = false; } }
async function action(row, command) {
  if (command === 'edit') return edit(row);
  if (command === 'client' || command === 'browser') return open(row, command);
  if (command === 'desktop') { try { await props.setDesktop(row, !props.isDesktop(row.id)); emit('changed'); } catch (e) { ElMessage.error(e.message); } return; }
  if (command === 'delete') {
    try { await ElMessageBox.confirm(`确认删除“${row.title}”？${row.scope === 'school' ? '全校用户的该快捷方式将同步失效。' : ''}`, '删除收藏'); } catch { return; }
    try { await request('/favorite/delete', { method: 'POST', body: JSON.stringify({ id: row.id, revision: row.revision }) }); await load(1); emit('changed'); } catch (e) { ElMessage.error(e.message); }
  }
}
onMounted(() => load(1));
</script>

<style scoped>
.favorites{display:flex;flex-direction:column;gap:18px;padding:20px;min-height:0;height:100%;box-sizing:border-box}.favorites-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.favorites-toolbar>.el-input{flex:1;min-width:180px}.favorites-toolbar>.el-select{width:150px}.favorites-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;overflow:auto;align-content:start;flex:1;min-height:140px}.favorite-tile{background:#f8fafc;border:1px solid #e5e9ef;border-radius:8px;min-width:0;padding:16px}.favorite-open{border:0;background:transparent;display:flex;gap:14px;align-items:center;text-align:left;width:100%;cursor:pointer;color:#263141;padding:8px 0}.favorite-open span{min-width:0;display:flex;flex-direction:column;gap:8px}.favorite-open strong{font-size:15px;overflow-wrap:anywhere}.favorite-open small{font-size:12px;color:#8390a0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.favorite-tile-footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px;font-size:11px;color:#8791a0}.favorite-tile-footer .shared{color:#25816b}.favorites-pagination{display:flex;align-items:center;justify-content:space-between;color:#808895;font-size:12px}.favorites-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;padding:20px 24px;min-width:0;min-height:0;overflow:auto;flex:1}.favorites-form>label:nth-child(-n+3){grid-column:1/-1}.favorites-form label{display:flex;flex-direction:column;gap:9px;font-size:13px;color:#566272}.favorites-form .favorites-switch{flex-direction:row;justify-content:space-between;align-items:center}.favorites-actions{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px 24px;border-top:1px solid #edf0f4}
@media(max-width:560px){.favorites-form{grid-template-columns:minmax(0,1fr);padding:18px;gap:16px}.favorites-form>label{grid-column:1}}
</style>
