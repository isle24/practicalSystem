<template>
  <section class="release-manager"><header><el-radio-group v-model="product" @change="load(1)"><el-radio-button value="system">系统更新</el-radio-button><el-radio-button value="desktop">客户端版本</el-radio-button></el-radio-group><el-button :icon="RefreshCw" :loading="loading" @click="load(page)">刷新</el-button><el-button v-if="product === 'desktop'" :icon="Download" :loading="busy" @click="github">从 GitHub 导入</el-button><ServerUpdatePanel /></header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table width-storage-key="release-manager:items" :data="items" v-loading="loading" height="100%"><el-table-column type="index" label="序号" width="68" :index="i => (page - 1) * 20 + i + 1" /><el-table-column prop="version" label="版本" width="100" /><el-table-column prop="title" label="标题" min-width="230" /><el-table-column label="状态" width="100"><template #default="{ row }"><el-tag :type="row.status === 'published' ? 'success' : 'info'">{{ status(row.status) }}</el-tag></template></el-table-column><el-table-column prop="published_at" label="发布时间" width="170" /><el-table-column label="操作" fixed="right" width="130"><template #default="{ row }"><el-button link type="primary" @click="detail(row.id)">编辑 / 详情</el-button></template></el-table-column></el-table>
    <footer><span>共 {{ total }} 个版本</span><ListPagination layout="prev, pager, next" :current-page="page" :page-size="20" :total="total" @current-change="load" /></footer>
    <OperationDialog :visible="Boolean(selected)" title="版本与更新" :busy="busy" @close="close">
      <div v-if="selected" class="release-detail" :inert="busy">
        <TextTabs v-model="tab" :items="tabs" />
        <template v-if="tab === 'notes'">
          <el-alert v-if="selected.item.upgrade_notes_review_required" title="此说明已被管理员编辑，请核对用户正文，并将部署操作移入升级/部署事项。" type="warning" :closable="false" />
          <label>版本标题<el-input v-model="selected.item.title" maxlength="180" /></label>
          <small>用户更新说明发布后对全校用户可见。</small>
          <RichTextEditor v-model="selected.item.notes_html" />
          <small v-if="selected.item.status === 'published'">保存后作为新草稿保留，再次发布才会更新用户可见的说明。</small>
        </template>
        <template v-if="tab === 'upgrade'">
          <small>仅具有版本维护权限的超级管理员、学校管理员可查看和编辑。保存后生效，不向用户发布。</small>
          <RichTextEditor v-model="selected.item.upgrade_notes_html" />
        </template>
        <template v-if="tab === 'sql'">
          <p v-if="!selected.sql_files.length">此版本没有需要当前管理员处理的 SQL。</p>
          <section v-for="sql in selected.sql_files" :key="sql.id" class="release-sql">
            <strong>{{ sql.file_name }}</strong><small>{{ sql.target_scope === 'school' ? '学校业务库' : '主库' }} · SHA256 {{ sql.sha256 }}</small><pre>{{ sql.sql_content }}</pre>
            <div><el-button :icon="Copy" @click="copySql(sql)">复制 SQL</el-button><el-button :disabled="Boolean(sql.executed_at) || busy" @click="markSql(sql)">{{ sql.executed_at ? '已登记执行' : '登记已执行' }}</el-button></div>
            <small v-if="sql.executed_at">人工登记于 {{ sql.executed_at }}，不代表系统验证了执行结果。</small>
          </section>
        </template>
        <ReleaseAssets v-if="tab === 'assets'" v-model:deferred="deferredPlatforms" :assets="selected.assets" :disabled="busy" @busy="busy = $event" @uploaded="selected.assets = $event.assets" @retry="mutate('retry')" @refresh="detail(selected.item.id)" />
      </div>
      <template #footer>
        <el-button v-if="selected?.item.status === 'published'" type="danger" :disabled="busy" @click="confirmAction('withdraw')">撤回发布</el-button>
        <el-button :disabled="busy" @click="close">关闭</el-button><el-button :loading="busy" @click="mutate('save')">保存草稿</el-button><el-button type="primary" :loading="busy" @click="confirmAction('publish')">发布</el-button>
      </template>
    </OperationDialog>
    <OperationDialog :visible="githubVisible" title="导入客户端版本" :busy="busy" @close="githubVisible = false"><div class="release-detail"><el-alert v-if="githubError" :title="githubError" type="error" :closable="false" /><el-select v-model="githubId" :disabled="busy" placeholder="请选择 GitHub 正式版本" style="width:100%"><el-option v-for="row in githubItems" :key="row.id" :label="row.name || row.tag" :value="row.id" /></el-select><small>导入时读取版本清单，安装包随后由后台队列下载。网络较慢时请等待当前操作完成。</small></div><template #footer><el-button type="primary" :disabled="!githubId" :loading="busy" @click="importVersion">导入草稿并下载</el-button></template></OperationDialog>
  </section>
</template>
<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { RefreshCw, Download, Copy } from '@lucide/vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { request } from '../api/client';
import OperationDialog from './OperationDialog.vue';
import TextTabs from './TextTabs.vue';
import RichTextEditor from '../../../shared/components/RichTextEditor.vue';
import ReleaseAssets from './ReleaseAssets.vue';
import ServerUpdatePanel from './ServerUpdatePanel.vue';
const deferredPlatforms = ref([]);
const product = ref('system'), items = ref([]), page = ref(1), total = ref(0), loading = ref(false), busy = ref(false), error = ref(''), selected = ref(null), tab = ref('notes');
const githubVisible = ref(false), githubItems = ref([]), githubId = ref(null);
const githubError = ref('');
const tabs = [{ key: 'notes', name: '用户更新说明' }, { key: 'upgrade', name: '升级/部署事项' }, { key: 'sql', name: '升级 SQL' }, { key: 'assets', name: '客户端安装包' }];
let baseline = '';
let alive = true, listRevision = 0;
const status = s => ({ draft: '草稿', published: '已发布', withdrawn: '已撤回' }[s] || s);
const payload = () => ({ id: selected.value.item.id, revision: selected.value.item.revision, title: selected.value.item.title, notes_html: selected.value.item.notes_html, upgrade_notes_html: selected.value.item.upgrade_notes_html ?? '' });
async function load(target = 1) { if (!alive) return; const ticket = ++listRevision; loading.value = true; error.value = ''; try { const data = await request(`/release/admin-list?product=${product.value}&page=${target}`); if (!alive || ticket !== listRevision) return; items.value = data.items; page.value = target; total.value = data.pagination.total; } catch (e) { if (alive && ticket === listRevision) error.value = e.message; } finally { if (alive && ticket === listRevision) loading.value = false; } }
function requireSaved() { if (selected.value && baseline !== JSON.stringify(payload())) { ElMessage.warning('请先保存草稿，再进行此操作'); return false; } return true; }
async function detail(id) { if (!alive || busy.value || !requireSaved()) return; const previousId = selected.value?.item.id; busy.value = true; try { const data = await request(`/release/detail?id=${id}`); if (!alive) return; selected.value = data; baseline = JSON.stringify(payload()); if (previousId !== id) { tab.value = 'notes'; deferredPlatforms.value = []; } } catch (e) { if (alive) ElMessage.error(e.message); } finally { busy.value = false; } }
async function close() { if (!alive || busy.value) return; const target = selected.value; const snapshot = target ? JSON.stringify(payload()) : ''; if (target && baseline !== snapshot) { try { await ElMessageBox.confirm('说明尚未保存，确认放弃本次编辑？', '关闭编辑'); } catch { return; } } if (!alive || busy.value || target !== selected.value || (target && snapshot !== JSON.stringify(payload()))) return; selected.value = null; }
async function mutate(action, extras = {}) { if (!alive || !selected.value || busy.value || (action !== 'save' && !requireSaved())) return; busy.value = true; try { const data = await request(`/release/${action}`, { method: 'POST', body: JSON.stringify({ ...payload(), ...extras }) }); if (!alive) return; if (data.item) { selected.value = data; baseline = JSON.stringify(payload()); } else { const detail = await request(`/release/detail?id=${selected.value.item.id}`); if (!alive) return; selected.value = detail; baseline = JSON.stringify(payload()); } await load(page.value); if (!alive) return; ElMessage.success(action === 'retry' ? '已排队下载' : '操作成功'); } catch (e) { if (alive) ElMessage.error(e.message); } finally { busy.value = false; } }
async function confirmAction(action) {
  if (!alive || busy.value || !selected.value) return;
  const target = selected.value, snapshot = JSON.stringify(payload());
  if (action === 'publish' && baseline !== JSON.stringify(payload())) { ElMessage.warning('请先保存草稿，再确认发布'); return; }
  const platforms = { 'darwin-universal': 'macOS', 'windows-x86_64': 'Windows', 'linux-x86_64': 'Linux' };
  const deferred = selected.value.item.product === 'desktop' ? [...deferredPlatforms.value] : [];
  const suffix = deferred.length ? ` ${deferred.map(key => platforms[key]).join('、')}暂缓，后台下载校验完成后自动开放。` : '';
  try { await ElMessageBox.confirm(action === 'publish' ? `发布后全校用户可查看用户更新说明，升级/部署事项仅管理员可见，已就绪平台将提示升级。${suffix}确认发布？` : '撤回后用户将不再看到该版本说明和升级提示，确认继续？', '确认发布操作'); } catch { return; }
  if (!alive || busy.value || target !== selected.value || snapshot !== JSON.stringify(payload())) return;
  await mutate(action, { deferred_platforms: deferred });
}
async function copySql(sql) { try { await navigator.clipboard.writeText(sql.sql_content); ElMessage.success('SQL 已复制'); } catch { ElMessage.error('浏览器不允许复制，请选择 SQL 内容复制'); } }
async function markSql(sql) { if (!alive || busy.value || !selected.value) return; const target = selected.value; try { const result = await ElMessageBox.prompt('请先在数据库工具中完成执行，再登记执行说明。此操作不会执行 SQL。', '登记 SQL', { inputPlaceholder: '执行备注', inputValidator: v => Boolean(v?.trim()) || '请填写执行备注' }); if (!alive || busy.value || target !== selected.value) return; await mutate('mark-sql', { sql_id: sql.id, execution_note: result.value }); } catch { /* 取消登记。 */ } }
async function github() { if (!alive || busy.value) return; busy.value = true; githubError.value = ''; try { const data = await request('/release/github', { timeoutMs: 60000 }); if (!alive) return; githubItems.value = data.items; githubVisible.value = true; } catch (e) { if (alive) ElMessage.error(e.message); } finally { busy.value = false; } }
async function importVersion() { if (!alive || busy.value) return; busy.value = true; githubError.value = ''; try { const data = await request('/release/import', { method: 'POST', timeoutMs: 90000, body: JSON.stringify({ id: githubId.value }) }); if (!alive) return; selected.value = data; baseline = JSON.stringify(payload()); deferredPlatforms.value = []; githubVisible.value = false; tab.value = 'assets'; await load(1); } catch (e) { if (alive) githubError.value = e.message; } finally { busy.value = false; } }
load();
onBeforeUnmount(() => { alive = false; listRevision++; });
</script>
<style scoped>
.release-manager{height:100%;min-height:0;display:flex;flex-direction:column;gap:16px;padding:22px;box-sizing:border-box}.release-manager>header{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.release-manager>footer{display:flex;align-items:center;justify-content:space-between;color:var(--muted);font-size:12px}.release-detail{padding:24px;display:flex;flex-direction:column;gap:20px;overflow:auto;min-height:0}.release-detail label{display:flex;flex-direction:column;gap:10px}.release-detail small{color:var(--muted);line-height:1.6;overflow-wrap:anywhere}.release-sql{display:flex;flex-direction:column;gap:12px}.release-sql pre{padding:18px;background:var(--surface-2);border-radius:5px;overflow:auto;max-height:280px;font:12px/1.8 monospace}.release-sql>div{display:flex;gap:10px}
</style>
