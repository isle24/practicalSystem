<script setup>
import { onMounted, reactive, ref } from 'vue';
import { request } from '../api/client';
import WorkflowAccountSelect from './WorkflowAccountSelect.vue';

const loading = ref(false);
const message = ref('');
const definitions = ref([]);
const options = reactive({ entity_types: [], accounts: [], roles: [], departments: [] });
const labels = { base_application: '基地申报', base_expense: '基地建设费用申请' };
const channels = [{ value: 'internal', label: '站内消息' }, { value: 'wechat', label: '企业微信' }, { value: 'sms', label: '短信' }];
const form = reactive({ entity_type: 'base_expense', dep_id: 0, name: '', nodes: [] });
const selectedVersion = ref(null);
const dirty = ref(false);

function node(name = '审批节点', kind = 'review') {
  return { name, kind, mode: 'any', signature_required: false, selector: { type: 'accounts', account_ids: [], role_id: 0, department: 'entity', dep_id: 0 }, notification: { enabled: false, channels: ['internal'], template: 'workflow_pending' } };
}
function newDefinition() {
  Object.assign(form, { entity_type: options.entity_types.includes('base_expense') ? 'base_expense' : options.entity_types[0] || '', dep_id: 0, name: '基地建设费用审批', nodes: [node('直接上级'), node('教务处审批'), node('教务处领导'), node('抄送', 'cc')] });
  selectedVersion.value = null;
  dirty.value = true;
}
async function run(action) {
  if (loading.value) return;
  loading.value = true;
  message.value = '';
  try { await action(); } catch (error) { message.value = error.message; } finally { loading.value = false; }
}
async function reloadDefinitions() { definitions.value = (await request('/workflow/definitions')).items || []; }
async function loadVersion(definition, versionId) {
  await run(async () => {
    const data = await request(`/workflow/definition?version_id=${versionId}`);
    Object.assign(form, { entity_type: definition.entity_type, dep_id: Number(definition.dep_id), name: data.version.name, nodes: data.nodes });
    selectedVersion.value = { ...data.version, published: Number(definition.current_version_id) === Number(versionId) };
    dirty.value = false;
  });
}
async function save() {
  await run(async () => {
    const data = await request('/workflow/save', { method: 'POST', body: JSON.stringify(form) });
    selectedVersion.value = { id: data.version_id, version: data.version, published: false };
    form.nodes = data.nodes;
    dirty.value = false;
    await reloadDefinitions();
    message.value = `版本 ${data.version} 已保存，请发布后使用。`;
  });
}
async function publish() {
  if (!selectedVersion.value || dirty.value) return;
  await run(async () => {
    await request('/workflow/publish', { method: 'POST', body: JSON.stringify({ version_id: selectedVersion.value.id }) });
    selectedVersion.value.published = true;
    await reloadDefinitions();
    message.value = '流程已发布，新提交的申请将使用此版本。';
  });
}
function move(index, step) {
  const target = index + step;
  if (target < 0 || target >= form.nodes.length) return;
  [form.nodes[index], form.nodes[target]] = [form.nodes[target], form.nodes[index]];
  dirty.value = true;
}
onMounted(() => run(async () => {
  Object.assign(options, await request('/workflow/options?include_accounts=0'));
  await reloadDefinitions();
  newDefinition();
}));
</script>

<template>
  <section class="workflow-settings" v-loading="loading">
    <header class="workflow-settings-toolbar"><strong>审批流程设置</strong><el-button @click="newDefinition">新建流程</el-button></header>
    <el-alert v-if="message" :title="message" type="info" :closable="false" />
    <div class="workflow-settings-layout">
      <aside class="workflow-definition-list">
        <article v-for="definition in definitions" :key="definition.id">
          <strong>{{ definition.name }}</strong>
          <small>{{ labels[definition.entity_type] || definition.entity_type }} · {{ options.departments.find(item => Number(item.dep_id) === Number(definition.dep_id))?.dep_name || '学校默认' }}</small>
          <el-button v-for="version in definition.versions" :key="version.id" text @click="loadVersion(definition, version.id)">版本 {{ version.version }}{{ Number(definition.current_version_id) === Number(version.id) ? '（已发布）' : '' }}</el-button>
        </article>
        <el-empty v-if="!definitions.length" description="尚未配置流程" :image-size="70" />
      </aside>
      <el-form class="workflow-definition-editor" label-position="top" @change="dirty = true">
        <div class="workflow-settings-grid">
          <el-form-item label="适用模块"><el-select v-model="form.entity_type" @change="dirty = true"><el-option v-for="type in options.entity_types" :key="type" :value="type" :label="labels[type] || type" /></el-select></el-form-item>
          <el-form-item label="适用学院"><el-select v-model="form.dep_id" @change="dirty = true"><el-option :value="0" label="学校默认" /><el-option v-for="department in options.departments" :key="department.dep_id" :value="Number(department.dep_id)" :label="department.dep_name" /></el-select></el-form-item>
          <el-form-item label="流程名称"><el-input v-model="form.name" maxlength="180" @input="dirty = true" /></el-form-item>
        </div>
        <p class="workflow-help">按顺序处理审批节点；或签由任一审批人通过，会签需全部审批人通过。发布前须为每个节点配置有效人员。</p>
        <article v-for="(item, index) in form.nodes" :key="index" class="workflow-node-editor">
          <header><strong>节点 {{ index + 1 }}</strong><div><el-button size="small" :disabled="index === 0" @click="move(index, -1)">上移</el-button><el-button size="small" :disabled="index === form.nodes.length - 1" @click="move(index, 1)">下移</el-button><el-button size="small" type="danger" text @click="form.nodes.splice(index, 1); dirty = true">删除</el-button></div></header>
          <div class="workflow-settings-grid">
            <el-form-item label="节点名称"><el-input v-model="item.name" maxlength="120" @input="dirty = true" /></el-form-item>
            <el-form-item label="节点类型"><el-select v-model="item.kind" @change="dirty = true"><el-option value="review" label="审批" /><el-option value="cc" label="抄送" /></el-select></el-form-item>
            <el-form-item v-if="item.kind === 'review'" label="通过规则"><el-select v-model="item.mode" @change="dirty = true"><el-option value="any" label="或签" /><el-option value="all" label="会签" /></el-select></el-form-item>
            <el-form-item label="人员选择"><el-select v-model="item.selector.type" @change="dirty = true"><el-option value="accounts" label="指定账号" /><el-option value="role" label="按角色" /></el-select></el-form-item>
            <el-form-item v-if="item.selector.type === 'accounts'" label="审批人 / 抄送人" class="workflow-wide"><WorkflowAccountSelect v-model="item.selector.account_ids" @update:model-value="dirty = true" /></el-form-item>
            <template v-else>
              <el-form-item label="角色"><el-select v-model="item.selector.role_id" filterable @change="dirty = true"><el-option v-for="role in options.roles" :key="role.id" :value="Number(role.id)" :label="role.name" /></el-select></el-form-item>
              <el-form-item label="学院范围"><el-select v-model="item.selector.department" @change="dirty = true"><el-option value="entity" label="申请所属学院" /><el-option value="fixed" label="指定学院" /><el-option value="school" label="全校" /></el-select></el-form-item>
              <el-form-item v-if="item.selector.department === 'fixed'" label="指定学院"><el-select v-model="item.selector.dep_id" @change="dirty = true"><el-option v-for="department in options.departments" :key="department.dep_id" :value="Number(department.dep_id)" :label="department.dep_name" /></el-select></el-form-item>
            </template>
            <el-form-item v-if="item.kind === 'review'" label="电子签名"><el-switch v-model="item.signature_required" active-text="审批时必须签名" @change="dirty = true" /></el-form-item>
            <el-form-item label="阶段通知"><el-switch v-model="item.notification.enabled" active-text="发送消息" @change="dirty = true" /></el-form-item>
            <template v-if="item.notification.enabled">
              <el-form-item label="消息渠道"><el-checkbox-group v-model="item.notification.channels" @change="dirty = true"><el-checkbox v-for="channel in channels" :key="channel.value" :value="channel.value">{{ channel.label }}</el-checkbox></el-checkbox-group></el-form-item>
              <el-form-item label="消息模板代码"><el-input v-model="item.notification.template" maxlength="120" @input="dirty = true" /></el-form-item>
            </template>
          </div>
        </article>
        <div class="workflow-settings-toolbar"><el-button @click="form.nodes.push(node()); dirty = true">添加节点</el-button><el-button type="primary" @click="save">保存为新版本</el-button><el-button type="success" :disabled="!selectedVersion || dirty || selectedVersion.published" @click="publish">发布版本{{ selectedVersion?.version || '' }}</el-button></div>
      </el-form>
    </div>
  </section>
</template>

<style scoped>
.workflow-settings { padding: 18px; height: 100%; overflow: auto; box-sizing: border-box; }
.workflow-settings-toolbar,.workflow-node-editor header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.workflow-settings-toolbar strong,.workflow-node-editor header strong { margin-right: auto; }
.workflow-settings-layout { display: grid; grid-template-columns: 210px minmax(0,1fr); gap: 20px; margin-top: 16px; }
.workflow-definition-list article { display: flex; flex-direction: column; padding: 12px 0; border-bottom: 1px solid #e4e8ee; }
.workflow-definition-list small,.workflow-help { color: #697587; font-size: 12px; line-height: 1.7; }
.workflow-definition-list .el-button { margin: 0; justify-content: start; }
.workflow-settings-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 0 14px; }
.workflow-wide { grid-column: 1 / -1; }
.workflow-node-editor { border: 1px solid #e3e8f0; border-radius: 8px; padding: 14px; margin: 14px 0; }
.workflow-definition-editor :deep(.el-select) { width: 100%; }
@media(max-width:800px) { .workflow-settings-layout { grid-template-columns: 1fr; } .workflow-settings-grid { grid-template-columns: 1fr; } }
</style>
