<script setup>
import FormFieldLabel from '../../../shared/components/FormFieldLabel.vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { ElMessageBox } from 'element-plus';
import { ArrowDown, ArrowUp, List, Plus, RefreshCw, Trash2 } from '@lucide/vue';
import { request } from '../api/client';
import WorkflowAccountSelect from './RemoteAccountSelect.vue';
import WorkflowCanvas from './WorkflowCanvas.vue';

const props = defineProps({ sessionKey: { type: [String, Number], default: '' } });
const loading = ref(false), ready = ref(false), message = ref(''), messageType = ref('info');
const definitions = ref([]), selectedVersion = ref(null), selectedKey = ref(''), baseline = ref('');
const showVersions = ref(true), versionFilter = ref('');
const options = reactive({ entity_types: [], roles: [], departments: [] });
const form = reactive({ entity_type: '', dep_id: 0, name: '', nodes: [] });
const accountCheck = reactive({ pending: false, error: '', validIds: [] });
const labels = { internship_plan: '实习计划', lab_plan: '实验教学计划', training_plan: '实训教学计划', social_practice_plan: '社会实践计划', base_application: '基地申报', base_expense: '基地建设费用申请' };
const defaultNames = { internship_plan: '实习计划审批', lab_plan: '实验教学计划审批', training_plan: '实训教学计划审批', base_application: '基地申报审批', base_expense: '基地建设费用审批', social_practice_plan: '社会实践计划审批' };
const channels = [{ value: 'internal', label: '站内消息' }, { value: 'wechat', label: '企业微信' }, { value: 'sms', label: '短信' }];
const controllers = new Set();
let alive = true, epoch = 0, sequence = 0, keySequence = 0, accountRevision = 0;
const current = value => alive && value === epoch;
const selectedIndex = computed(() => form.nodes.findIndex(item => item._key === selectedKey.value));
const selectedNode = computed(() => form.nodes[selectedIndex.value] || null);
const reviewCount = computed(() => form.nodes.filter(item => item.kind === 'review').length);
const onlyReview = computed(() => selectedNode.value?.kind === 'review' && reviewCount.value === 1);
const dirty = computed(() => signature() !== baseline.value);
const filteredDefinitions = computed(() => definitions.value.filter(item => `${item.name} ${labels[item.entity_type] || item.entity_type} ${departmentName(item.dep_id)}`.toLowerCase().includes(versionFilter.value.trim().toLowerCase())));
const accountSignature = computed(() => JSON.stringify([...new Set(form.nodes.filter(item => item.selector.type === 'accounts').flatMap(item => item.selector.account_ids).map(Number))].sort((left, right) => left - right)));

function makeNode(value = {}, key = '') {
  return { _key: key || `workflow-node-${++keySequence}`, name: value.name || '人工审批', kind: value.kind || 'review', mode: value.mode || 'any', signature_required: Boolean(value.signature_required),
    selector: { type: value.selector?.type || 'accounts', account_ids: [...(value.selector?.account_ids || [])].map(Number), role_id: Number(value.selector?.role_id || 0), department: value.selector?.department || 'entity', dep_id: Number(value.selector?.dep_id || 0) },
    notification: { enabled: Boolean(value.notification?.enabled), channels: [...(value.notification?.channels || ['internal'])], template: value.notification?.template || 'workflow_pending' } };
}
function payload() {
  return { entity_type: form.entity_type, dep_id: form.dep_id, name: form.name.trim(), nodes: form.nodes.map(({ _key, ...item }) => ({ ...item, name: item.name.trim(), selector: { ...item.selector, account_ids: [...item.selector.account_ids] }, notification: { ...item.notification, channels: [...item.notification.channels], template: item.notification.template.trim() } })) };
}
function signature() { return JSON.stringify(payload()); }
function departmentName(id) { return Number(id) === 0 ? '学校默认' : options.departments.find(item => Number(item.dep_id) === Number(id))?.dep_name || '学院已不可用'; }
function availableRole(id) { return options.roles.some(item => Number(item.id) === Number(id)); }
function availableDepartment(id) { return options.departments.some(item => Number(item.dep_id) === Number(id)); }
function resetDefinition() {
  const type = options.entity_types.includes('base_expense') ? 'base_expense' : options.entity_types[0] || '';
  const first = makeNode();
  Object.assign(form, { entity_type: type, dep_id: 0, name: defaultNames[type] || '', nodes: [first] });
  selectedVersion.value = null; selectedKey.value = first._key; baseline.value = signature(); message.value = '';
}
async function discardChanges() {
  if (!dirty.value || !ready.value) return true;
  const expected = epoch;
  try {
    await ElMessageBox.confirm('当前修改尚未保存为版本，继续将放弃这些修改。', '离开当前流程', { confirmButtonText: '放弃修改并继续', cancelButtonText: '继续编辑', type: 'warning' });
    return current(expected);
  } catch { return false; }
}
async function newDefinition() {
  const expected = epoch;
  if (!loading.value && await discardChanges() && current(expected) && !loading.value) resetDefinition();
}
function changeEntityType() { if (Object.values(defaultNames).includes(form.name) || !form.name.trim()) form.name = defaultNames[form.entity_type] || ''; }
async function scopedRequest(path, init = {}, expected = epoch) {
  const controller = new AbortController(); controllers.add(controller);
  try { return await request(path, { ...init, signal: controller.signal, isCurrent: () => current(expected) }); }
  finally { controllers.delete(controller); }
}
async function run(action) {
  if (loading.value) return;
  const expected = epoch, operation = ++sequence;
  loading.value = true; message.value = ''; messageType.value = 'info';
  try { await action(expected); }
  catch (error) { if (current(expected) && error.name !== 'AbortError') { message.value = error.message || '工作流操作失败，请重试'; messageType.value = 'error'; } }
  finally { if (current(expected) && operation === sequence) loading.value = false; }
}
async function reloadDefinitions(expected) {
  const data = await scopedRequest('/workflow/definitions', {}, expected);
  if (current(expected)) definitions.value = data.items || [];
}
async function initialize() {
  await run(async expected => {
    const [metadata, listing] = await Promise.all([scopedRequest('/workflow/options?include_accounts=0', {}, expected), scopedRequest('/workflow/definitions', {}, expected)]);
    if (!current(expected)) return;
    Object.assign(options, { entity_types: metadata.entity_types || [], roles: metadata.roles || [], departments: metadata.departments || [] });
    definitions.value = listing.items || []; resetDefinition(); ready.value = true;
  });
}
async function refresh() {
  if (!ready.value) return initialize();
  await run(async expected => {
    const [metadata, listing] = await Promise.all([scopedRequest('/workflow/options?include_accounts=0', {}, expected), scopedRequest('/workflow/definitions', {}, expected)]);
    if (!current(expected)) return;
    Object.assign(options, { entity_types: metadata.entity_types || [], roles: metadata.roles || [], departments: metadata.departments || [] });
    definitions.value = listing.items || [];
    if (selectedVersion.value) selectedVersion.value.published = definitions.value.some(item => Number(item.current_version_id) === Number(selectedVersion.value.id));
    await validateAccounts();
  });
}
async function loadVersion(definition, versionId) {
  const origin = epoch;
  if (loading.value || !await discardChanges() || !current(origin)) return;
  await run(async expected => {
    const data = await scopedRequest(`/workflow/definition?version_id=${Number(versionId)}`, {}, expected);
    if (!current(expected)) return;
    const nodes = (data.nodes || []).map(item => makeNode(item));
    Object.assign(form, { entity_type: definition.entity_type, dep_id: Number(definition.dep_id), name: data.version.name, nodes });
    selectedKey.value = nodes[0]?._key || '';
    selectedVersion.value = { ...data.version, published: Number(definition.current_version_id) === Number(versionId) };
    baseline.value = signature();
  });
}
function nodeSaveIssue(item) {
  if (!item.name.trim()) return '请填写节点名称';
  if (item.selector.department === 'fixed' && !availableDepartment(item.selector.dep_id)) return '请指定有效学院';
  if (item.notification.enabled && (!item.notification.channels.length || !/^[a-zA-Z0-9_.:-]{1,120}$/.test(item.notification.template.trim()))) return '请配置通知渠道及有效模板代码';
  return '';
}
function nodePublishIssue(item) {
  const issue = nodeSaveIssue(item); if (issue) return issue;
  if (item.selector.type === 'role') return availableRole(item.selector.role_id) ? '' : '请指定有效角色';
  if (!item.selector.account_ids.length) return '请至少选择一个有效账号';
  if (accountCheck.pending) return '正在校验所选账号';
  if (accountCheck.error) return '账号校验未完成，请刷新后重试';
  if (item.selector.account_ids.some(id => !accountCheck.validIds.includes(Number(id)))) return '含已停用或不可用账号，请移除后重新选择';
  return '';
}
const saveIssue = computed(() => {
  if (!ready.value) return loading.value ? '正在读取工作流配置' : '工作流配置未就绪，请刷新重试';
  if (!options.entity_types.includes(form.entity_type)) return '请选择有效的适用业务';
  if (form.dep_id && !availableDepartment(form.dep_id)) return '适用学院已不可用，请重新选择';
  if (!form.name.trim()) return '请填写流程名称';
  if (!form.nodes.length || form.nodes.length > 100 || !reviewCount.value) return '配置 1 至 100 个节点，且至少保留一个审批节点';
  const index = form.nodes.findIndex(item => nodeSaveIssue(item));
  return index < 0 ? '' : `节点 ${index + 1}：${nodeSaveIssue(form.nodes[index])}`;
});
const nodeIssues = computed(() => Object.fromEntries(form.nodes.map(item => [item._key, nodePublishIssue(item)])));
const publishIssue = computed(() => {
  if (saveIssue.value) return saveIssue.value;
  if (!selectedVersion.value) return '先保存为新版本，再发布';
  if (dirty.value) return '有未保存修改，请先保存为新版本';
  if (selectedVersion.value.published) return '当前版本已发布';
  const index = form.nodes.findIndex(item => nodePublishIssue(item));
  return index < 0 ? '' : `节点 ${index + 1}：${nodePublishIssue(form.nodes[index])}`;
});
const versionState = computed(() => dirty.value ? '有未保存修改' : selectedVersion.value?.published ? `版本 ${selectedVersion.value.version} 已发布` : selectedVersion.value ? `版本 ${selectedVersion.value.version} 待发布` : '新流程');
async function save() {
  if (saveIssue.value) { message.value = saveIssue.value; messageType.value = 'warning'; return; }
  await run(async expected => {
    const data = await scopedRequest('/workflow/save', { method: 'POST', body: JSON.stringify(payload()) }, expected);
    if (!current(expected)) return;
    const index = selectedIndex.value;
    form.nodes = data.nodes.map((item, position) => makeNode(item, form.nodes[position]?._key));
    selectedKey.value = form.nodes[Math.max(0, index)]?._key || form.nodes[0]?._key || '';
    selectedVersion.value = { id: data.version_id, version: data.version, published: false }; baseline.value = signature();
    message.value = `版本 ${data.version} 已保存，发布后用于新提交的申请。`; messageType.value = 'success';
    try { await reloadDefinitions(expected); }
    catch (error) { if (current(expected) && error.name !== 'AbortError') { message.value = `版本 ${data.version} 已保存，版本列表刷新失败，请刷新列表。`; messageType.value = 'warning'; } }
  });
}
async function publish() {
  if (publishIssue.value) return;
  await run(async expected => {
    await scopedRequest('/workflow/publish', { method: 'POST', body: JSON.stringify({ version_id: selectedVersion.value.id }) }, expected);
    if (!current(expected)) return;
    selectedVersion.value.published = true;
    message.value = '版本已发布，新提交的申请使用此流程；进行中的申请保持原版本。'; messageType.value = 'success';
    try { await reloadDefinitions(expected); }
    catch (error) { if (current(expected) && error.name !== 'AbortError') { message.value = '版本已发布，版本列表刷新失败，请刷新列表。'; messageType.value = 'warning'; } }
  });
}
function reorder({ from, to }) {
  if (loading.value || from === to || from < 0 || to < 0 || from >= form.nodes.length || to >= form.nodes.length) return;
  const nodes = [...form.nodes], [moved] = nodes.splice(from, 1); nodes.splice(to, 0, moved); form.nodes = nodes; selectedKey.value = moved._key;
}
function addNode(kind) {
  if (loading.value || form.nodes.length >= 100) return;
  const item = makeNode({ name: kind === 'cc' ? '抄送节点' : '审批节点', kind });
  const index = selectedIndex.value < 0 ? form.nodes.length : selectedIndex.value + 1;
  form.nodes.splice(index, 0, item); selectedKey.value = item._key;
}
function removeNode() {
  if (loading.value || !selectedNode.value || onlyReview.value) return;
  const index = selectedIndex.value; form.nodes.splice(index, 1); selectedKey.value = form.nodes[Math.min(index, form.nodes.length - 1)]?._key || '';
}
function changeKind() { if (selectedNode.value?.kind === 'cc') { selectedNode.value.signature_required = false; selectedNode.value.mode = 'any'; } }
function changeSelector() {
  const item = selectedNode.value; if (item) item.selector = { type: item.selector.type, account_ids: [], role_id: 0, department: 'entity', dep_id: 0 };
}
function changeDepartmentScope() { if (selectedNode.value?.selector.department !== 'fixed') selectedNode.value.selector.dep_id = 0; }
async function validateAccounts() {
  const expected = epoch, revision = ++accountRevision;
  const ids = [...new Set(form.nodes.filter(item => item.selector.type === 'accounts').flatMap(item => item.selector.account_ids).map(Number))];
  accountCheck.pending = ids.length > 0; accountCheck.error = ''; accountCheck.validIds = [];
  if (!ids.length) return;
  const valid = [];
  try {
    for (let index = 0; index < ids.length; index += 100) {
      const data = await scopedRequest(`/workflow/accounts?${new URLSearchParams({ ids: ids.slice(index, index + 100).join(',') })}`, {}, expected);
      if (!current(expected) || revision !== accountRevision) return;
      valid.push(...(data.items || []).map(item => Number(item.id)));
    }
    if (current(expected) && revision === accountRevision) accountCheck.validIds = valid;
  } catch (error) { if (current(expected) && revision === accountRevision && error.name !== 'AbortError') accountCheck.error = error.message || '账号校验失败'; }
  finally { if (current(expected) && revision === accountRevision) accountCheck.pending = false; }
}
watch(accountSignature, validateAccounts);
watch(() => props.sessionKey, () => {
  epoch++; sequence++; accountRevision++;
  controllers.forEach(controller => controller.abort()); controllers.clear();
  loading.value = false; ready.value = false; message.value = ''; definitions.value = []; selectedVersion.value = null; selectedKey.value = ''; baseline.value = '';
  Object.assign(options, { entity_types: [], roles: [], departments: [] }); Object.assign(form, { entity_type: '', dep_id: 0, name: '', nodes: [] });
  Object.assign(accountCheck, { pending: false, error: '', validIds: [] }); initialize();
}, { immediate: true, flush: 'sync' });
onBeforeUnmount(() => { alive = false; epoch++; accountRevision++; controllers.forEach(controller => controller.abort()); controllers.clear(); });
</script>

<template>
  <section class="workflow-settings" :aria-busy="loading">
    <header class="workflow-settings-toolbar">
      <div><h1>工作流</h1><p>按顺序配置审批与抄送，保存版本后发布。</p></div>
      <div class="workflow-toolbar-actions"><el-button :icon="List" :aria-expanded="showVersions" @click="showVersions = !showVersions">流程版本</el-button><el-button :icon="RefreshCw" :loading="loading" @click="refresh">刷新</el-button><el-button :icon="Plus" :disabled="loading || !ready" @click="newDefinition">新建流程</el-button></div>
    </header>
    <el-alert v-if="message" :title="message" :type="messageType" closable show-icon @close="message = ''" />
    <div class="workflow-settings-layout" :class="{ 'versions-hidden': !showVersions }">
      <aside v-if="showVersions" class="workflow-definition-list" aria-label="流程及版本">
        <div class="workflow-version-search"><el-input v-model="versionFilter" placeholder="搜索流程或学院" clearable aria-label="搜索流程或学院" /></div>
        <div class="workflow-versions-scroll">
          <article v-for="definition in filteredDefinitions" :key="definition.id">
            <strong>{{ definition.name }}</strong><small>{{ labels[definition.entity_type] || definition.entity_type }}</small><small>{{ departmentName(definition.dep_id) }}</small>
            <button v-for="version in definition.versions" :key="version.id" type="button" class="workflow-version-button" :class="{ active: Number(selectedVersion?.id) === Number(version.id) }" :disabled="loading" :aria-current="Number(selectedVersion?.id) === Number(version.id) ? 'true' : undefined" @click="loadVersion(definition, version.id)"><span>版本 {{ version.version }}</span><small>{{ Number(definition.current_version_id) === Number(version.id) ? '已发布' : '历史 / 草稿' }}</small></button>
          </article>
          <p v-if="!filteredDefinitions.length" class="workflow-empty">{{ loading ? '正在读取流程…' : definitions.length ? '没有匹配的流程' : '尚未配置流程。新建并发布后，新申请才会使用配置流程。' }}</p>
        </div>
      </aside>
      <div class="workflow-editor" v-loading="loading">
        <el-form class="workflow-definition-basics" label-position="top" :disabled="loading || !ready">
          <el-form-item><template #label><FormFieldLabel :required="true">适用业务</FormFieldLabel></template><el-select v-model="form.entity_type" @change="changeEntityType"><el-option v-for="type in options.entity_types" :key="type" :value="type" :label="labels[type] || type" /></el-select></el-form-item>
          <el-form-item><template #label><FormFieldLabel :required="false" help="学校默认流程适用于未单独配置的学院">适用学院</FormFieldLabel></template><el-select v-model="form.dep_id" filterable><el-option :value="0" label="学校默认" /><el-option v-if="form.dep_id && !availableDepartment(form.dep_id)" :value="form.dep_id" label="原学院已不可用，请重新选择" disabled /><el-option v-for="department in options.departments" :key="department.dep_id" :value="Number(department.dep_id)" :label="department.dep_name" /></el-select></el-form-item>
          <el-form-item><template #label><FormFieldLabel :required="true" help="最多 180 字">流程名称</FormFieldLabel></template><el-input v-model="form.name" maxlength="180" /></el-form-item>
        </el-form>
        <div class="workflow-design-area">
          <WorkflowCanvas :key="sessionKey" :nodes="form.nodes" :selected-key="selectedKey" :roles="options.roles" :issues="nodeIssues" :disabled="loading || !ready" @select="selectedKey = $event" @reorder="reorder" @add="addNode" />
          <aside class="workflow-properties" aria-label="节点属性">
            <header><strong>节点属性<span v-if="selectedNode"> · {{ selectedIndex + 1 }}</span></strong><span>{{ form.nodes.length }} / 100</span></header>
            <div v-if="selectedNode" class="workflow-properties-scroll">
              <p v-if="nodeIssues[selectedKey]" class="workflow-node-warning" role="status">{{ nodeIssues[selectedKey] }}</p>
              <el-form :key="`${sessionKey}:${selectedKey}`" label-position="top" :disabled="loading || !ready" class="workflow-node-form">
          <div class="workflow-settings-grid">
            <el-form-item label="节点名称"><template #label><FormFieldLabel :required="true">节点名称</FormFieldLabel></template><el-input v-model="selectedNode.name" maxlength="120"  /></el-form-item>
            <el-form-item label="节点类型"><template #label><FormFieldLabel :required="false">节点类型</FormFieldLabel></template><el-select v-model="selectedNode.kind" @change="changeKind"><el-option value="review" label="审批" /><el-option value="cc" label="抄送" :disabled="onlyReview" /></el-select></el-form-item>
            <el-form-item v-if="selectedNode.kind === 'review'" label="通过规则"><template #label><FormFieldLabel :required="false">通过规则</FormFieldLabel></template><el-select v-model="selectedNode.mode" ><el-option value="any" label="或签" /><el-option value="all" label="会签" /></el-select></el-form-item>
            <el-form-item label="人员选择"><template #label><FormFieldLabel :required="false">人员选择</FormFieldLabel></template><el-select v-model="selectedNode.selector.type" @change="changeSelector"><el-option value="accounts" label="指定账号" /><el-option value="role" label="按角色" /></el-select></el-form-item>
            <el-form-item v-if="selectedNode.selector.type === 'accounts'" label="审批人 / 抄送人" class="workflow-wide"><template #label><FormFieldLabel :required="false" :submit-required="true" help="发布时必填；失效账号须移除，保存草稿可暂不指定">审批人 / 抄送人</FormFieldLabel></template><WorkflowAccountSelect :key="`${sessionKey}:${selectedKey}:accounts`" :disabled="loading" v-model="selectedNode.selector.account_ids"  /></el-form-item>
            <template v-else>
              <el-form-item label="角色"><template #label><FormFieldLabel :required="false" :submit-required="true" help="发布时必填，并确保范围内有有效人员">角色</FormFieldLabel></template><el-select v-model="selectedNode.selector.role_id" filterable ><el-option v-if="selectedNode.selector.role_id && !availableRole(selectedNode.selector.role_id)" :value="selectedNode.selector.role_id" label="原角色已不可用，请重新选择" disabled /><el-option v-for="role in options.roles" :key="role.id" :value="Number(role.id)" :label="role.name" /></el-select></el-form-item>
              <el-form-item label="学院范围"><template #label><FormFieldLabel :required="false">学院范围</FormFieldLabel></template><el-select v-model="selectedNode.selector.department" @change="changeDepartmentScope"><el-option value="entity" label="申请所属学院" /><el-option value="fixed" label="指定学院" /><el-option value="school" label="全校" /></el-select></el-form-item>
              <el-form-item v-if="selectedNode.selector.department === 'fixed'" label="指定学院"><template #label><FormFieldLabel :required="true">指定学院</FormFieldLabel></template><el-select v-model="selectedNode.selector.dep_id" filterable><el-option v-if="selectedNode.selector.dep_id && !availableDepartment(selectedNode.selector.dep_id)" :value="selectedNode.selector.dep_id" label="原学院已不可用，请重新选择" disabled /><el-option v-for="department in options.departments" :key="department.dep_id" :value="Number(department.dep_id)" :label="department.dep_name" /></el-select></el-form-item>
            </template>
            <el-form-item v-if="selectedNode.kind === 'review'" label="电子签名"><template #label><FormFieldLabel :required="false">电子签名</FormFieldLabel></template><el-switch v-model="selectedNode.signature_required" active-text="审批时必须签名"  /></el-form-item>
            <el-form-item label="阶段通知"><template #label><FormFieldLabel :required="false">阶段通知</FormFieldLabel></template><el-switch v-model="selectedNode.notification.enabled" active-text="发送消息"  /></el-form-item>
            <template v-if="selectedNode.notification.enabled">
              <el-form-item label="消息渠道"><template #label><FormFieldLabel :required="true">消息渠道</FormFieldLabel></template><el-checkbox-group v-model="selectedNode.notification.channels" ><el-checkbox v-for="channel in channels" :key="channel.value" :value="channel.value">{{ channel.label }}</el-checkbox></el-checkbox-group></el-form-item>
              <el-form-item label="消息模板代码"><template #label><FormFieldLabel :required="true">消息模板代码</FormFieldLabel></template><el-input v-model="selectedNode.notification.template" maxlength="120"  /></el-form-item>
            </template>
          </div>
              </el-form>
              <div class="workflow-node-actions"><el-button size="small" :icon="ArrowUp" :disabled="loading || selectedIndex === 0" @click="reorder({ from: selectedIndex, to: selectedIndex - 1 })">上移</el-button><el-button size="small" :icon="ArrowDown" :disabled="loading || selectedIndex === form.nodes.length - 1" @click="reorder({ from: selectedIndex, to: selectedIndex + 1 })">下移</el-button><el-button size="small" :icon="Trash2" type="danger" text :disabled="loading || onlyReview" @click="removeNode">删除</el-button></div>
              <p v-if="onlyReview" class="workflow-help">至少保留一个审批节点。</p><p class="workflow-help">已保存版本保持原内容。修改后须保存为新版本，再决定是否发布。</p>
            </div>
            <p v-else class="workflow-empty">选择画布节点以配置人员、签名及通知。</p>
          </aside>
        </div>
        <footer class="workflow-editor-footer">
          <div class="workflow-footer-state"><strong>{{ versionState }}</strong><small>{{ saveIssue || publishIssue || '发布时将再次校验审批人、角色及学院范围。' }}</small></div>
          <div class="workflow-footer-actions"><el-button :disabled="loading || Boolean(saveIssue) || (!dirty && Boolean(selectedVersion))" @click="save">保存为新版本</el-button><el-button type="primary" :disabled="loading || Boolean(publishIssue)" @click="publish">发布版本{{ selectedVersion?.version || '' }}</el-button></div>
        </footer>
      </div>
    </div>
  </section>
</template>

<style scoped>
.workflow-settings { display: flex; flex-direction: column; height: 100%; min-height: 0; min-width: 0; padding: 16px; gap: 12px; overflow: hidden; box-sizing: border-box; color: var(--text); container: workflow-editor / inline-size; }
.workflow-settings-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; flex-shrink: 0; }
.workflow-settings-toolbar h1 { margin: 0; font-size: 18px; font-weight: 600; line-height: 26px; }
.workflow-settings-toolbar p { margin: 3px 0 0; font-size: 12px; color: var(--muted); }
.workflow-toolbar-actions,.workflow-footer-actions,.workflow-node-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.workflow-settings .el-button + .el-button { margin-left: 0; }
.workflow-settings > .el-alert { flex-shrink: 0; }
.workflow-settings-layout { display: grid; grid-template-columns: 196px minmax(0, 1fr); flex: 1; min-height: 0; min-width: 0; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
.workflow-settings-layout.versions-hidden { grid-template-columns: minmax(0, 1fr); }
.workflow-definition-list { display: flex; flex-direction: column; min-height: 0; border-right: 1px solid var(--line); background: var(--el-bg-color); }
.workflow-version-search { padding: 12px; border-bottom: 1px solid var(--line); }
.workflow-versions-scroll { overflow: auto; overscroll-behavior: contain; min-height: 0; flex: 1; padding: 0 12px 12px; }
.workflow-definition-list article { display: flex; flex-direction: column; gap: 4px; padding: 14px 0; border-bottom: 1px solid var(--line); }
.workflow-definition-list article > strong { font-size: 13px; line-height: 1.6; overflow-wrap: anywhere; }
.workflow-definition-list small,.workflow-help,.workflow-empty,.workflow-node-form small { color: var(--muted); font-size: 12px; line-height: 1.6; }
.workflow-version-button { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 3px; padding: 7px 8px; text-align: left; border: 0; border-radius: 4px; background: transparent; color: var(--text); font: inherit; font-size: 12px; cursor: pointer; }
.workflow-version-button:hover,.workflow-version-button.active { background: var(--el-fill-color); }
.workflow-version-button.active { font-weight: 600; }
.workflow-version-button:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: -2px; }
.workflow-version-button:disabled { cursor: wait; opacity: .6; }
.workflow-editor { display: flex; flex-direction: column; min-width: 0; min-height: 0; background: var(--el-bg-color); }
.workflow-definition-basics { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(120px, 1fr) minmax(150px, 1.4fr); gap: 12px; padding: 12px 14px; border-bottom: 1px solid var(--line); flex-shrink: 0; }
.workflow-definition-basics .el-form-item { margin-bottom: 0; min-width: 0; }
.workflow-settings :deep(.el-select) { width: 100%; }
.workflow-design-area { display: grid; grid-template-columns: minmax(240px, 1fr) 300px; flex: 1; min-height: 0; min-width: 0; }
.workflow-properties { display: flex; flex-direction: column; min-height: 0; min-width: 0; border-left: 1px solid var(--line); }
.workflow-properties > header { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 14px; border-bottom: 1px solid var(--line); font-size: 13px; flex-shrink: 0; }
.workflow-properties > header > span { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
.workflow-properties-scroll { flex: 1; min-height: 0; overflow: auto; overscroll-behavior: contain; padding: 14px; }
.workflow-node-warning { margin: 0 0 14px; font-size: 12px; line-height: 1.6; color: var(--el-color-warning-dark-2); }
.workflow-settings-grid { display: grid; grid-template-columns: minmax(0,1fr); }
.workflow-node-form :deep(.el-form-item) { margin-bottom: 16px; }
.workflow-node-form :deep(.el-form-item__label) { line-height: 1.6; height: auto; margin-bottom: 6px; font-size: 12px; }
.workflow-node-form :deep(.el-checkbox) { margin-right: 12px; }
.workflow-node-actions { padding-top: 8px; border-top: 1px solid var(--line); }
.workflow-help { margin: 12px 0 0; }
.workflow-empty { margin: 0; padding: 20px 12px; }
.workflow-editor-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-top: 1px solid var(--line); background: var(--el-bg-color); flex-shrink: 0; }
.workflow-footer-state { display: flex; flex-direction: column; gap: 3px; min-width: 160px; flex: 1; }
.workflow-footer-state strong { font-size: 12px; font-weight: 600; }
.workflow-footer-state small { color: var(--muted); font-size: 12px; line-height: 1.5; }
@media(max-width: 580px) { .workflow-settings { padding: 10px; } }
@container workflow-editor (max-width: 1020px) { .workflow-settings-layout { grid-template-columns: 170px minmax(0, 1fr); } .workflow-design-area { grid-template-columns: minmax(200px, 1fr) 270px; } .workflow-definition-basics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .workflow-definition-basics .el-form-item:last-child { grid-column: 1 / -1; } }
@container workflow-editor (max-width: 700px) {
  .workflow-settings-layout { display: flex; flex-direction: column; overflow: auto; }
  .workflow-definition-list { height: 160px; min-height: 120px; max-height: 180px; flex: 0 0 160px; border-right: 0; border-bottom: 1px solid var(--line); }
  .workflow-editor { height: 660px; min-height: 660px; max-height: 660px; flex: 0 0 660px; }
  .workflow-design-area { min-height: 0; flex: 1 1 0%; overflow: hidden; }
  .workflow-editor-footer { position: sticky; bottom: 0; z-index: 2; }
}
@container workflow-editor (max-width: 520px) {
  .workflow-editor { height: auto; min-height: 0; max-height: none; flex: 0 0 auto; }
  .workflow-design-area { display: grid; grid-template-columns: minmax(0, 1fr); grid-template-rows: 360px minmax(0, 1fr); height: 830px; min-height: 830px; max-height: 830px; flex: 0 0 830px; }
  .workflow-properties { border-left: 0; border-top: 1px solid var(--line); max-height: none; }
  .workflow-definition-basics { grid-template-columns: minmax(0, 1fr); }
  .workflow-definition-basics .el-form-item:last-child { grid-column: auto; }
  .workflow-footer-actions { width: 100%; }
  .workflow-footer-actions .el-button { flex: 1; }
}
</style>
