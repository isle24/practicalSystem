<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import WorkflowHistory from '../../../shared/components/WorkflowHistory.vue';
import { uploadFile } from '../api/system';

const props = defineProps({ request: { type: Function, required: true }, backendUrl: { type: Function, default: value => value }, accountId: { type: [Number, String], required: true }, openExpenseId: { type: [Number, String], default: 0 }, canView: { type: Boolean, default: false }, canCreate: { type: Boolean, default: false }, canExport: { type: Boolean, default: false } });
const rows = ref([]);
const options = reactive({ bases: [], semesters: [], categories: [] });
const selected = ref(null);
const busy = ref(false);
const message = ref('');
const editorVisible = ref(false);
const workflowPreview = ref([]);
const workflowPreviewVisible = ref(false);
const form = reactive(emptyForm());

function emptyForm() { return { id: 0, name: '', title: '', base_id: 0, dep_id: 0, term_id: 0, semester: '', base_type: '', base_category: '', amount_upper: '', remark: '', items: [{ project: '', amount: '' }], attachment_ids: [] }; }
const total = computed(() => (form.items.reduce((sum, item) => sum + amountCents(item.amount), 0) / 100).toFixed(2));
const amountUpper = computed(() => chineseAmount(form.items.reduce((sum, item) => sum + amountCents(item.amount), 0)));
const selectedBase = computed(() => options.bases.find(item => Number(item.id) === Number(form.base_id)) || null);
function amountCents(value) { const match = String(value || '').match(/^(?:0|[1-9]\d*)(?:\.(\d{1,2}))?$/); if (!match) return 0; const [whole, fraction = ''] = String(value).split('.'); return Number(whole) * 100 + Number((fraction + '00').slice(0, 2)); }
function chineseAmount(cents) {
  const digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
  const units = ['', '拾', '佰', '仟'];
  const groups = ['', '万', '亿', '兆'];
  const toGroup = value => {
    let result = '';
    let zero = false;
    let position = 0;
    while (value > 0) {
      const digit = value % 10;
      value = Math.floor(value / 10);
      if (digit === 0) zero = result !== '';
      else { result = digits[digit] + units[position] + (zero ? '零' : '') + result; zero = false; }
      position++;
    }
    return result;
  };
  let yuan = Math.floor(cents / 100);
  const fraction = cents % 100;
  const hasYuan = yuan > 0;
  let text = '';
  if (!hasYuan) text = '零';
  else {
    let group = 0;
    let zeroGroup = false;
    while (yuan > 0) {
      const part = yuan % 10000;
      yuan = Math.floor(yuan / 10000);
      if (part > 0) {
        const prefix = text !== '' && (zeroGroup || part < 1000) ? '零' : '';
        text = toGroup(part) + groups[group] + prefix + text;
        zeroGroup = false;
      } else if (text !== '') zeroGroup = true;
      group++;
    }
  }
  text += '元';
  const jiao = Math.floor(fraction / 10);
  const fen = fraction % 10;
  if (jiao || fen) {
    if (jiao) text += digits[jiao] + '角';
    if (fen) text += (jiao || hasYuan ? '' : '零') + digits[fen] + '分';
  } else text += '整';
  return text;
}
async function load() { busy.value = true; message.value = ''; try { if (!props.canView) { rows.value = []; return; } const [list, optionData] = await Promise.all([props.request('/expense/list?page=1&page_size=100'), props.request('/expense/options')]); rows.value = list.items || []; Object.assign(options, optionData || {}); } catch (error) { message.value = error.message; } finally { busy.value = false; } }
function openCreate() { Object.assign(form, emptyForm()); form.items = [{ project: '', amount: '' }]; editorVisible.value = true; }
async function openEdit(row) { editorVisible.value = false; try { const data = await props.request(`/expense/detail?id=${Number(row.id)}`); const item = data.item || {}; const ids = Array.isArray(item.attachment_ids) ? item.attachment_ids : JSON.parse(item.attachment_ids || '[]'); Object.assign(form, emptyForm(), item, { items: (item.items || []).map(line => ({ project: line.project, amount: line.amount })), attachment_ids: ids, attachments: item.attachments || [] }); editorVisible.value = true; return true; } catch (error) { message.value = error.message; return false; } }
async function editAndSubmit(row) { if (await openEdit(row)) await save(true); }
function selectBase() { if (!form.dep_id || !options.units.some(unit => Number(unit.dep_id) === Number(form.dep_id))) form.dep_id = Number(selectedBase.value?.dep_id || 0); form.title = selectedBase.value?.name || form.title; form.base_type = selectedBase.value?.base_type || ''; form.base_category = selectedBase.value?.base_category || ''; }
function addItem() { form.items.push({ project: '', amount: '' }); }
function copyItem(index) { form.items.splice(index + 1, 0, { ...form.items[index] }); }
function removeItem(index) { if (form.items.length > 1) form.items.splice(index, 1); }
function requestId() { return typeof crypto?.randomUUID === 'function' ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2); }
async function save(submit = false) { if (busy.value) return; busy.value = true; message.value = ''; try { const data = await props.request('/expense/save', { method: 'POST', body: JSON.stringify({ ...form, total_amount: total.value }) }); Object.assign(form, data.item || {}); editorVisible.value = false; await load(); if (submit) { const preview = await props.request(`/workflow/preview?entity_type=base_expense&entity_id=${Number(form.id)}`); workflowPreview.value = preview.nodes || []; workflowPreviewVisible.value = true; } else message.value = '草稿已保存'; } catch (error) { message.value = error.message; } finally { busy.value = false; } }
async function confirmSubmit() { if (busy.value || !form.id) return; busy.value = true; message.value = ''; try { await props.request('/expense/submit', { method: 'POST', body: JSON.stringify({ id: form.id, request_id: requestId() }) }); workflowPreviewVisible.value = false; await load(); message.value = '申请已提交审批'; } catch (error) { message.value = error.message; } finally { busy.value = false; } }
async function uploadAttachments(event) { const files = [...(event.target.files || [])]; event.target.value = ''; for (const file of files) { busy.value = true; try { const uploaded = await uploadFile(file, { category: 'base_expense', is_temporary: 'false' }); form.attachment_ids.push(Number(uploaded.file_id)); form.attachments = [...(form.attachments || []), { file_id: Number(uploaded.file_id), name: uploaded.name || file.name, url: uploaded.url || '' }]; } catch (error) { message.value = error.message; break; } finally { busy.value = false; } } }
function removeAttachment(fileId) { form.attachment_ids = form.attachment_ids.filter(id => Number(id) !== Number(fileId)); form.attachments = (form.attachments || []).filter(file => Number(file.file_id) !== Number(fileId)); }
async function exportItem(row, format = 'docx') { try { await props.request('/expense/export', { method: 'POST', body: JSON.stringify({ id: row.id, format }) }); message.value = '导出任务已创建，请在导出中心查看'; } catch (error) { message.value = error.message; } }
function canEdit(row) { return ['draft', 'modify'].includes(String(row.workflow_status)) && Number(row.submitter_id) === Number(props.accountId); }
function statusText(value) { return ({ draft: '草稿', wait: '审批中', accept: '已通过', modify: '已退回', cancelled: '已取消' })[String(value)] || String(value || '—'); }
async function openExpense(id) { if (!id) return; message.value = ''; try { const data = await props.request(`/expense/detail?id=${Number(id)}`); selected.value = data.item || null; } catch (error) { message.value = error.message; } }
defineExpose({ load });
onMounted(load);
watch(() => props.openExpenseId, openExpense, { immediate: true });
</script>

<template>
  <section class="expense-panel" :aria-busy="busy">
    <header class="expense-panel-toolbar"><div><h2>经费管理</h2><p>基地建设费用申请</p></div><div><button :disabled="busy" @click="load">刷新</button><button v-if="canCreate" class="primary" :disabled="busy" @click="openCreate">新建申请</button></div></header>
    <p v-if="message" class="expense-message" role="status">{{ message }}</p>
    <table v-if="rows.length" class="expense-table"><thead><tr><th>审批编号</th><th>基地</th><th>单位</th><th>总金额</th><th>状态</th><th>操作</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td>{{ row.code || row.id }}</td><td>{{ row.title || row.name || '—' }}</td><td>{{ row.name || '—' }}</td><td>{{ row.total_amount || row.amount || '0.00' }}</td><td>{{ statusText(row.workflow_status) }}</td><td><button v-if="canEdit(row)" @click="openEdit(row)">编辑</button><button v-if="canEdit(row)" class="primary" @click="editAndSubmit(row)">提交</button><button @click="openExpense(row.id)">查看详情</button><button v-if="canExport" @click="exportItem(row, 'docx')">Word</button><button v-if="canExport" @click="exportItem(row, 'pdf')">PDF</button></td></tr></tbody></table>
    <p v-else-if="!busy" class="expense-empty">暂无费用申请。</p>
    <div v-if="editorVisible" class="expense-editor"><div class="expense-editor-card"><header><h3>{{ form.id ? '编辑费用申请' : '新建费用申请' }}</h3><button @click="editorVisible = false">关闭</button></header>
      <label>申请单位<select v-model.number="form.dep_id"><option :value="0">请选择单位</option><option v-for="unit in options.units" :key="unit.dep_id" :value="Number(unit.dep_id)">{{ unit.dep_name }}</option></select></label><label>基地<select v-model.number="form.base_id" @change="selectBase"><option :value="0">请选择基地</option><option v-for="base in options.bases" :key="base.id" :value="Number(base.id)">{{ base.name }}</option></select></label><label>学年学期<select v-model="form.semester"><option value="">未选择</option><option v-for="term in options.semesters" :key="term" :value="term">{{ term }}</option></select></label><p>基地类型：{{ form.base_type || '—' }}；基地类别：{{ form.base_category || '—' }}</p><label>备注<textarea v-model.trim="form.remark" rows="3"></textarea></label>
      <div class="expense-lines"><div class="expense-line-head"><strong>费用明细</strong><button @click="addItem">新增明细</button></div><div v-for="(item, index) in form.items" :key="index" class="expense-line"><input v-model.trim="item.project" list="expense-projects" placeholder="建设项目"><input v-model.trim="item.amount" inputmode="decimal" placeholder="项目金额"><button @click="copyItem(index)">复制</button><button :disabled="form.items.length <= 1" @click="removeItem(index)">删除</button></div><datalist id="expense-projects"><option v-for="project in options.categories" :key="project" :value="project"></option></datalist><strong class="expense-total">合计：{{ total }}（{{ amountUpper }}）</strong></div>
      <label>附件<input type="file" multiple @change="uploadAttachments"><small>支持常见文档和图片格式</small></label><ul v-if="form.attachments?.length" class="expense-attachments"><li v-for="file in form.attachments" :key="file.file_id"><a :href="backendUrl(file.url)" target="_blank" rel="noopener noreferrer">{{ file.name }}</a><button type="button" @click="removeAttachment(file.file_id)">移除</button></li></ul>
      <footer><button @click="save(false)">保存草稿</button><button class="primary" @click="save(true)">保存并提交</button></footer></div></div>
    <div v-if="selected" class="expense-detail-backdrop" @click.self="selected = null">
      <article class="expense-detail">
        <header><h3>费用申请详情</h3><button @click="selected = null">关闭</button></header>
        <dl class="expense-detail-fields"><dt>审批编号</dt><dd>{{ selected.code || selected.id }}</dd><dt>申请人</dt><dd>{{ selected.submitter_name || `账号 ${selected.submitter_id || '—'}` }}</dd><dt>申请单位</dt><dd>{{ selected.name || '—' }}</dd><dt>基地名称</dt><dd>{{ selected.title || '—' }}</dd><dt>学年学期</dt><dd>{{ selected.semester || '—' }}</dd><dt>基地类型 / 类别</dt><dd>{{ selected.base_type || '—' }} / {{ selected.base_category || '—' }}</dd><dt>提交时间</dt><dd>{{ selected.submitted_at || selected.created_at || '—' }}</dd><dt>状态</dt><dd>{{ statusText(selected.workflow_status) }}</dd></dl>
        <table v-if="selected.items?.length" class="expense-table"><thead><tr><th>建设项目</th><th>项目金额</th></tr></thead><tbody><tr v-for="(item, index) in selected.items" :key="item.id || index"><td>{{ item.project }}</td><td>{{ item.amount }}</td></tr></tbody></table>
        <p class="expense-detail-total">合计：{{ selected.total_amount || selected.amount || '0.00' }}（{{ selected.amount_upper || '—' }}）</p>
        <ul v-if="selected.attachments?.length" class="expense-attachments"><li v-for="file in selected.attachments" :key="file.file_id"><a :href="backendUrl(file.url)" target="_blank" rel="noopener noreferrer">{{ file.name }}</a></li></ul><p v-else class="expense-muted">无附件</p>
        <p class="expense-detail-remark">备注：{{ selected.remark || '无' }}</p>
        <div class="expense-detail-history"><WorkflowHistory entity-type="base_expense" :entity-id="selected.id" :account-id="accountId" :request="request" :backend-url="backendUrl" /></div>
        <footer v-if="canExport"><button @click="exportItem(selected, 'docx')">导出 Word</button><button @click="exportItem(selected, 'pdf')">导出 PDF</button></footer>
      </article>
    </div>
    <div v-if="workflowPreviewVisible" class="expense-preview-backdrop" @click.self="workflowPreviewVisible = false">
      <article class="expense-preview">
        <header><h3>提交前确认审批流程</h3><button type="button" :disabled="busy" @click="workflowPreviewVisible = false">关闭</button></header>
        <ol><li v-for="(node, index) in workflowPreview" :key="index"><strong>{{ index + 1 }}. {{ node.name }}</strong><span>{{ node.kind === 'cc' ? '抄送' : node.mode === 'all' ? '会签' : '或签' }}</span><p>{{ (node.candidates || []).map(person => person.name).join('、') || '无已解析人员' }}</p></li></ol>
        <footer><button type="button" :disabled="busy" @click="workflowPreviewVisible = false">返回修改</button><button type="button" class="primary" :disabled="busy || !workflowPreview.length" @click="confirmSubmit">确认提交</button></footer>
      </article>
    </div>
  </section>
</template>

<style scoped>
.expense-panel { height: 100%; overflow: auto; padding: 20px; box-sizing: border-box; color: #253248; }
.expense-panel-toolbar,.expense-editor-card > header,.expense-detail > header,.expense-line-head { display: flex; align-items: center; gap: 12px; }
.expense-panel-toolbar > div:first-child,.expense-editor-card > header h3,.expense-detail > header h3 { margin-right: auto; }
h2,h3,p { margin: 0; }
.expense-panel-toolbar p,.expense-message,.expense-empty { color: #68768a; font-size: 13px; margin-top: 5px; }
button { border: 1px solid #d6deea; border-radius: 5px; padding: 7px 12px; background: #fff; color: #253248; cursor: pointer; }
button.primary { color: #fff; border-color: #2e68b3; background: #2e68b3; }
button:disabled { opacity: .5; cursor: default; }
.expense-table { width: 100%; border-collapse: collapse; margin-top: 18px; }
th,td { border-bottom: 1px solid #e8edf3; padding: 10px; text-align: left; font-size: 13px; }
td button { margin-right: 6px; }
.expense-editor { position: fixed; inset: 0; z-index: 20; background: rgba(20,31,48,.35); display: grid; place-items: center; padding: 20px; }
.expense-editor-card,.expense-detail { background: #fff; border-radius: 9px; width: min(760px,calc(100vw - 40px)); max-height: 90vh; overflow: auto; padding: 20px; box-sizing: border-box; }
.expense-detail-backdrop { position: fixed; inset: 0; z-index: 21; background: rgba(20,31,48,.35); display: grid; place-items: center; padding: 20px; box-sizing: border-box; }
.expense-detail { box-shadow: 0 16px 48px rgba(20,31,48,.28); }
.expense-preview-backdrop { position: fixed; inset: 0; z-index: 22; display: grid; place-items: center; padding: 20px; box-sizing: border-box; background: rgba(20,31,48,.35); }
.expense-preview { width: min(680px,calc(100vw - 40px)); max-height: 90vh; overflow: auto; padding: 20px; box-sizing: border-box; border-radius: 9px; background: #fff; box-shadow: 0 16px 48px rgba(20,31,48,.28); }
.expense-preview > header,.expense-preview > footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.expense-preview h3 { margin: 0; }
.expense-preview ol { display: grid; gap: 10px; padding-left: 26px; }
.expense-preview li { padding: 10px; border: 1px solid #e1e7ef; border-radius: 6px; }
.expense-preview li span { margin-left: 8px; color: #657185; font-size: 12px; }
.expense-preview li p { margin: 6px 0 0; color: #657185; font-size: 13px; }
.expense-preview .primary { color: #fff; border-color: #2e68b3; background: #2e68b3; }
.expense-editor-card { display: grid; gap: 14px; }
label { display: grid; gap: 5px; font-size: 13px; }
input,select,textarea { border: 1px solid #d2dbe7; border-radius: 5px; padding: 8px; font: inherit; box-sizing: border-box; }
.expense-lines { display: grid; gap: 9px; }
.expense-line { display: grid; grid-template-columns: 1fr 150px auto; gap: 8px; }
.expense-total,.expense-detail-total { text-align: right; }
footer { display: flex; justify-content: flex-end; gap: 10px; }
.expense-detail-fields { display: grid; grid-template-columns: 130px minmax(0,1fr); gap: 9px 12px; margin: 18px 0; font-size: 13px; }
.expense-detail-fields dt { color: #68768a; }
.expense-detail-fields dd { margin: 0; overflow-wrap: anywhere; }
.expense-detail-total { margin: 12px 0; font-weight: 600; }
.expense-detail-remark { margin: 14px 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.expense-muted { color: #68768a; font-size: 13px; }
.expense-detail-history { border-top: 1px solid #e8edf3; padding-top: 14px; }
</style>
