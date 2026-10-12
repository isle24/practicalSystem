<template>
  <section class="favorites">
    <EmbeddedExternalPage v-if="embeddedItem" :item="embeddedItem" @close="embeddedItem = null" />
    <template v-else>
    <header class="favorites-toolbar"><el-input v-model="keyword" clearable placeholder="搜索标题或网址" @keyup.enter="load(1)" /><el-select v-model="scope" placeholder="请选择收藏范围" @change="load(1)"><el-option label="全部收藏" value="" /><el-option label="我的收藏" value="personal" /><el-option label="学校共享" value="school" /><el-option label="学院共享" value="college" /><el-option label="指定人员" value="accounts" /></el-select><el-button :icon="Search" :loading="loading" @click="load(1)">查询</el-button><el-button class="favorites-create" :icon="Plus" type="primary" @click="edit()">新增收藏</el-button></header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div class="favorites-grid" v-loading="loading">
      <article v-for="row in items" :key="row.id" class="favorite-tile">
        <button class="favorite-open" :disabled="row.can_open === false || isExternalLinkOpening(row)" :aria-busy="isExternalLinkOpening(row)" @click="open(row)"><AppIcon :icon="Globe2" :icon-url="row.icon_url" :label="row.title" :size="28" :backend-url="backendUrl" /><span><strong>{{ row.title }}</strong><small>{{ isExternalLinkOpening(row) ? '正在打开…' : host(row.url) }}</small></span></button>
        <div class="favorite-tile-footer"><span :class="{ shared: row.scope !== 'personal' }">{{ scopeLabels[row.scope] || '个人收藏' }}</span><el-dropdown trigger="click" @command="command => action(row, command)"><el-button :icon="MoreHorizontal" text circle title="更多操作" /><template #dropdown><el-dropdown-menu><el-dropdown-item command="current">当前页打开</el-dropdown-item><el-dropdown-item command="client">{{ desktop ? '客户端窗口打开' : '新标签页打开' }}</el-dropdown-item><el-dropdown-item v-if="desktop" command="browser">外部浏览器打开</el-dropdown-item><el-dropdown-item v-if="desktop && row.auth_mode !== 'oidc'" command="credentials">本机认证配置</el-dropdown-item><el-dropdown-item command="desktop" :disabled="row.auto_desktop">{{ row.auto_desktop ? '管理员共享到桌面' : isDesktop(row.id) ? '从桌面移除' : '添加到桌面' }}</el-dropdown-item><el-dropdown-item v-if="row.can_edit" command="edit" divided>编辑</el-dropdown-item><el-dropdown-item v-if="row.can_edit" command="delete">删除</el-dropdown-item></el-dropdown-menu></template></el-dropdown></div>
      </article>
      <el-empty v-if="!loading && !items.length" description="暂无收藏" />
    </div>
    <footer class="favorites-pagination"><span>共 {{ pagination.total }} 个收藏</span><ListPagination layout="prev, pager, next" :current-page="pagination.page" :page-size="pagination.page_size" :total="pagination.total" @current-change="load" /></footer>
    <OperationDialog :visible="dialog" :title="form.id ? '编辑收藏' : '新增收藏'" :busy="saving || uploading" @close="dialog = false">
      <div class="favorites-dialog-body">
      <div class="favorites-form" :inert="saving"><label>标题<el-input v-model="form.title" maxlength="180" placeholder="请输入收藏标题" /></label><label>认证方式<el-radio-group v-model="form.auth_mode"><el-radio-button value="none">普通链接</el-radio-button><el-radio-button value="oidc">应用授权</el-radio-button></el-radio-group></label><label v-if="form.auth_mode === 'oidc'">授权应用<el-select v-model="form.sso_client_id" filterable remote :remote-method="searchClients" :loading="clientLoading" placeholder="搜索本校应用" @visible-change="visible => visible && searchClients('')"><el-option v-for="client in clientOptions" :key="client.id" :label="client.client_name" :value="client.id" /></el-select><small>网址使用已注册应用的固定入口。</small></label><label v-else>网址<el-input v-model="form.url" maxlength="500" placeholder="https://example.com" /></label><label>默认打开方式<el-radio-group v-model="form.open_mode"><el-radio-button v-if="form.auth_mode !== 'oidc'" value="current">当前页打开</el-radio-button><el-radio-button value="client">客户端窗口</el-radio-button><el-radio-button value="browser">外部浏览器</el-radio-button></el-radio-group></label><label>共享范围<el-select v-model="form.scope"><el-option v-for="value in formScopes" :key="value" :label="scopeLabels[value]" :value="value" :disabled="!shareScopes.includes(value)" /></el-select><small v-if="form.scope === 'college' || form.scope === 'accounts'">符合共享范围的有效账号将自动显示桌面快捷方式。</small></label><label v-if="form.scope === 'college'" class="favorites-targets">共享学院<FavoriteTargetSelect v-model="form.college_ids" type="college" :disabled="saving" /></label><label v-if="form.scope === 'accounts'" class="favorites-targets">共享人员<FavoriteTargetSelect v-model="form.account_ids" type="accounts" :disabled="saving" /></label><label class="favorites-switch">{{ formAutoDesktop ? '管理员共享到我的桌面' : '添加到我的桌面' }}<el-switch v-model="form.on_desktop" :disabled="formAutoDesktop" /></label><label>排序<el-input-number v-model="form.sort" :min="0" :max="99999" /></label><label>图标<IconUpload :icon="Globe2" :icon-url="form.icon_url" label="收藏图标" :uploading="uploading" :backend-url="backendUrl" @select="upload" /></label></div>
      <div v-if="form.auth_mode !== 'oidc'" class="favorites-parameters" :inert="saving"><label>请求方式<el-radio-group v-model="form.request_config.method"><el-radio-button value="GET">GET</el-radio-button><el-radio-button value="POST">POST</el-radio-button></el-radio-group></label><LinkParameterEditor v-model="form.request_config.query" title="Query 参数" /><LinkParameterEditor v-if="form.request_config.method === 'POST'" v-model="form.request_config.form" title="POST 参数" /><small>这里只保存非敏感参数。密码、令牌、Cookie 请在收藏的“本机认证配置”中设置。</small></div>
      </div>
      <template #footer><el-button :disabled="saving || uploading" @click="dialog = false">取消</el-button><el-button type="primary" :loading="saving" :disabled="uploading" @click="save">保存</el-button></template>
    </OperationDialog>
    <FavoriteCredentials :item="credentialItem" @close="credentialItem = null" />
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { Plus, Search, Globe2, MoreHorizontal } from '@lucide/vue';
import { ElMessageBox, ElMessage } from 'element-plus';
import { request, backendUrl } from '../api/client';
import { isExternalLinkOpening, openExternalLink } from '../utils/externalLinks';
import AppIcon from './AppIcon.vue';
import IconUpload from './IconUpload.vue';
import OperationDialog from './OperationDialog.vue';
import LinkParameterEditor from './LinkParameterEditor.vue';
import FavoriteCredentials from './FavoriteCredentials.vue';
import EmbeddedExternalPage from './EmbeddedExternalPage.vue';
import FavoriteTargetSelect from './FavoriteTargetSelect.vue';
const embeddedItem = ref(null);
const credentialItem = ref(null);
const props = defineProps({ sessionKey: { type: String, required: true }, isDesktop: { type: Function, required: true }, setDesktop: { type: Function, required: true } });
const emit = defineEmits(['changed']);
const items = ref([]), keyword = ref(''), scope = ref(''), error = ref('');
const scopeLabels = { personal: '个人收藏', school: '学校共享', college: '学院共享', accounts: '指定人员' };
const shareScopes = ref(['personal']);
const formScopes = computed(() => [...new Set([...shareScopes.value, form.value.scope || 'personal'])]);
const formAutoDesktop = computed(() => Boolean(form.value.auto_desktop) && form.value.scope === originalScope && targetSignature(form.value) === originalTargets);
const loading = ref(false), saving = ref(false), uploading = ref(false), dialog = ref(false), form = ref({});
const pagination = ref({ page: 1, page_size: 20, total: 0 });
const desktop = Boolean(window.__PRACTICAL_DESKTOP__);
const clientOptions = ref([]), clientLoading = ref(false);
let clientSequence = 0, sequence = 0, generation = 0, disposed = false;
let originalScope = 'personal', originalTargets = '';
const controllers = new Set();
const sourceContext = () => ({ key: props.sessionKey, generation });
const currentContext = context => !disposed && context.key === props.sessionKey && context.generation === generation;
const host = url => { try { return new URL(url).host; } catch { return url; } };
function invalidateContext() {
  generation += 1; sequence += 1; clientSequence += 1;
  controllers.forEach(controller => controller.abort());
  controllers.clear();
}
async function contextRequest(context, path, options = {}) {
  if (!currentContext(context)) return null;
  const controller = new AbortController(); controllers.add(controller);
  try {
    const data = await request(path, { ...options, signal: controller.signal, refreshOnUnauthorized: false, isCurrent: () => currentContext(context) });
    return currentContext(context) ? data : null;
  } finally { controllers.delete(controller); }
}
async function saveRequest(context, payload) {
  const headers = {};
  if (payload.auth_mode === 'oidc') {
    const security = await contextRequest(context, '/sso/security');
    if (!currentContext(context)) return null;
    if (!security?.csrf_token) throw new Error('授权操作校验不可用');
    headers['X-CSRF-Token'] = security.csrf_token;
    headers['X-Request-Token'] = crypto.randomUUID();
  }
  if (!currentContext(context)) return null;
  return contextRequest(context, '/favorite/save', { method: 'POST', headers, body: JSON.stringify(payload) });
}
async function searchClients(keyword = '') {
  const context = sourceContext(), ticket = ++clientSequence;
  if (!currentContext(context)) return;
  clientLoading.value = true;
  try {
    const data = await contextRequest(context, `/sso-client/options?${new URLSearchParams({ keyword, page_size: 100 })}`);
    if (!currentContext(context) || ticket !== clientSequence) return;
    const selected = clientOptions.value.find(item => item.id === form.value.sso_client_id);
    clientOptions.value = selected && !data.items.some(item => item.id === selected.id) ? [selected, ...data.items] : data.items;
  } catch (error) { if (currentContext(context) && ticket === clientSequence) ElMessage.error(error.message); }
  finally { if (currentContext(context) && ticket === clientSequence) clientLoading.value = false; }
}
async function load(page = 1, context = sourceContext()) {
  if (!currentContext(context)) return;
  const ticket = ++sequence; loading.value = true; error.value = '';
  try {
    const data = await contextRequest(context, `/favorite/list?${new URLSearchParams({ keyword: keyword.value, scope: scope.value, page, page_size: 20 })}`);
    if (!currentContext(context) || ticket !== sequence) return;
    items.value = data.items; pagination.value = data.pagination;
    shareScopes.value = data.share_scopes || (data.can_share ? ['personal', 'school'] : ['personal']);
  } catch (reason) { if (currentContext(context) && ticket === sequence) error.value = reason.message; }
  finally { if (currentContext(context) && ticket === sequence) loading.value = false; }
}
function edit(row = {}) {
  if (disposed) return;
  form.value = { id: row.id || null, title: row.title || '', url: row.url || '', scope: row.scope || 'personal', open_mode: row.open_mode || 'client', sort: Number(row.sort || 0), icon_file_id: row.icon_file_id || null, icon_url: row.icon_url || '', revision: row.revision || 0, on_desktop: row.auto_desktop || (row.id ? props.isDesktop(row.id) : true), auto_desktop: Boolean(row.auto_desktop), college_ids: [...(row.college_ids || [])], account_ids: [...(row.account_ids || [])], auth_mode: row.auth_mode || 'none', sso_client_id: row.sso_client_id || null, request_config: JSON.parse(JSON.stringify({ method:'GET', query:[], form:[], ...row.request_config })) };
  originalScope = form.value.scope; originalTargets = targetSignature(form.value);
  if (row.sso_client_id) clientOptions.value = [{ id: row.sso_client_id, client_name: row.sso_client_name || '已选择应用' }];
  dialog.value = true;
}
function targetSignature(row) { return JSON.stringify([row.college_ids || [], row.account_ids || []]); }
async function open(row, mode) {
  const context = sourceContext();
  if (!currentContext(context)) return;
  try { await openExternalLink(row, mode, item => { if (currentContext(context)) embeddedItem.value = item; }); }
  catch (reason) { if (currentContext(context)) ElMessage.error(reason.message); }
}
async function save() {
  const context = sourceContext();
  if (!currentContext(context) || saving.value || uploading.value) return;
  const draft = JSON.parse(JSON.stringify(form.value));
  saving.value = true;
  let serverSaved = false;
  try {
    if (draft.scope !== originalScope || (draft.scope !== 'personal' && (targetSignature(draft) !== originalTargets || !draft.id))) {
      try { await ElMessageBox.confirm(draft.scope === 'personal' ? '取消共享后其他用户将无法使用此快捷方式，确认继续？' : `此收藏将按“${scopeLabels[draft.scope]}”范围共享，范围外账号的快捷方式将失效，确认保存？`, '确认共享范围'); }
      catch { return; }
      if (!currentContext(context)) return;
    }
    const payload = { ...draft, open_mode: draft.auth_mode === 'oidc' && draft.open_mode === 'current' ? 'client' : draft.open_mode, request_config: draft.auth_mode === 'oidc' ? { method: 'GET', query: [], form: [] } : { ...draft.request_config, form: draft.request_config.method === 'POST' ? draft.request_config.form : [] } };
    if (payload.scope === 'college' && !payload.college_ids.length) throw new Error('请选择共享学院');
    if (payload.scope === 'accounts' && !payload.account_ids.length) throw new Error('请选择共享人员');
    if (payload.auth_mode === 'oidc' && !payload.sso_client_id) throw new Error('请选择授权应用');
    if (!currentContext(context)) return;
    const data = await saveRequest(context, payload);
    if (!currentContext(context)) return;
    const item = data.item; serverSaved = true;
    form.value = { ...draft, ...item }; originalScope = item.scope; originalTargets = targetSignature(item);
    if (!item.auto_desktop) {
      if (!currentContext(context)) return;
      await props.setDesktop(item, draft.on_desktop && item.can_open !== false);
      if (!currentContext(context)) return;
    }
    if (!currentContext(context)) return;
    dialog.value = false;
    await load(1, context);
    if (!currentContext(context)) return;
    ElMessage.success('已保存');
  } catch (reason) { if (currentContext(context)) ElMessage.error(reason.message); }
  finally {
    if (currentContext(context)) {
      saving.value = false;
      if (serverSaved) emit('changed');
    }
  }
}
async function upload(file) {
  const context = sourceContext(), draft = form.value;
  if (!currentContext(context) || uploading.value) return;
  uploading.value = true;
  try {
    const body = new FormData(); body.append('file', file);
    const data = await contextRequest(context, '/favorite/upload-icon', { method: 'POST', body });
    if (!currentContext(context) || form.value !== draft) return;
    form.value.icon_file_id = data.file_id; form.value.icon_url = data.url;
  } catch (reason) { if (currentContext(context)) ElMessage.error(reason.message); }
  finally { if (currentContext(context)) uploading.value = false; }
}
async function action(row, command) {
  const context = sourceContext();
  if (!currentContext(context)) return;
  if (command === 'credentials') { credentialItem.value = row; return; }
  if (command === 'edit') return edit(row);
  if (command === 'client' || command === 'browser' || command === 'current') return open(row, command);
  if (command === 'desktop' && row.auto_desktop) return;
  if (command === 'desktop') {
    try {
      if (!currentContext(context)) return;
      await props.setDesktop(row, !props.isDesktop(row.id));
      if (!currentContext(context)) return;
      emit('changed');
    } catch (reason) { if (currentContext(context)) ElMessage.error(reason.message); }
    return;
  }
  if (command === 'delete') {
    try { await ElMessageBox.confirm(`确认删除“${row.title}”？${row.scope !== 'personal' ? '共享范围内用户的该快捷方式将同步失效。' : ''}`, '删除收藏'); }
    catch { return; }
    if (!currentContext(context)) return;
    try {
      await contextRequest(context, '/favorite/delete', { method: 'POST', body: JSON.stringify({ id: row.id, revision: row.revision }) });
      if (!currentContext(context)) return;
      await load(1, context);
      if (!currentContext(context)) return;
      emit('changed');
    } catch (reason) { if (currentContext(context)) ElMessage.error(reason.message); }
  }
}
watch(() => props.sessionKey, () => {
  invalidateContext();
  items.value = []; clientOptions.value = []; dialog.value = false; embeddedItem.value = null; credentialItem.value = null;
  loading.value = false; saving.value = false; uploading.value = false; clientLoading.value = false;
  load(1);
}, { flush: 'sync' });
onBeforeUnmount(() => { disposed = true; invalidateContext(); });
onMounted(() => load(1));
</script>

<style scoped>
.favorites-toolbar > .el-button { margin: 0; }
.favorites-toolbar > .favorites-create { margin-left: auto; }
.favorite-open:disabled { cursor: progress; opacity: .65; }
.favorites-dialog-body{flex:1;min-height:0;overflow:auto}.favorites-dialog-body .favorites-form{overflow:visible;flex:initial}.favorites-dialog-body .favorites-parameters{overflow:visible;max-height:none}
.favorites-parameters{padding:8px 24px 20px;display:flex;flex-direction:column;gap:16px;overflow:auto;flex-shrink:0;max-height:280px}.favorites-parameters>label{display:flex;align-items:center;gap:16px;font-size:13px}.favorites-parameters small{color:var(--muted);line-height:1.6}
.favorites{display:flex;flex-direction:column;gap:18px;padding:20px;min-height:0;height:100%;box-sizing:border-box}.favorites-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.favorites-toolbar>.el-input{flex:0 1 280px;min-width:180px}.favorites-toolbar>.el-select{width:150px}.favorites-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;overflow:auto;align-content:start;flex:1;min-height:140px}.favorite-tile{background:var(--surface-2);border:1px solid var(--line);border-radius:8px;min-width:0;padding:16px}.favorite-open{border:0;background:transparent;display:flex;gap:14px;align-items:center;text-align:left;width:100%;cursor:pointer;color:var(--text);padding:8px 0}.favorite-open span{min-width:0;display:flex;flex-direction:column;gap:8px}.favorite-open strong{font-size:15px;overflow-wrap:anywhere}.favorite-open small{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.favorite-tile-footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px;font-size:11px;color:var(--muted)}.favorite-tile-footer .shared{color:var(--green)}.favorites-pagination{display:flex;align-items:center;justify-content:space-between;color:var(--muted);font-size:12px}.favorites-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;padding:20px 24px;min-width:0;min-height:0;overflow:auto;flex:1}.favorites-form>label:nth-child(-n+3){grid-column:1/-1}.favorites-form label{display:flex;flex-direction:column;gap:9px;font-size:13px;color:var(--muted)}.favorites-form .favorites-targets{grid-column:1/-1}.favorites-form small{font-size:12px;line-height:1.6}.favorites-form .favorites-switch{flex-direction:row;justify-content:space-between;align-items:center}.favorites-actions{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px 24px;border-top:1px solid var(--line)}
@media(max-width:560px){.favorites-form{grid-template-columns:minmax(0,1fr);padding:18px;gap:16px}.favorites-form>label{grid-column:1}}
</style>
