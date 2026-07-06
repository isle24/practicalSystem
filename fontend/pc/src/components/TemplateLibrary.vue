<template>
  <section class="support-panel template-library-panel">
    <div class="template-library-tabs">
      <button
        v-if="canViewMessageTemplates"
        type="button"
        :class="{ active: activeTab === 'message' }"
        @click="setActiveTab('message')"
      >
        流程审核待办/消息模板
      </button>
      <button type="button" :class="{ active: activeTab === 'file' }" @click="setActiveTab('file')">
        材料文件模板
      </button>
    </div>
    <div class="template-library-hint">
      <span v-if="activeTab === 'message'">
        学生提交、教师审核、通过后修改等流程都会按这里的模板发送待办和消息；默认模板已写入学校业务库，管理员只需要按需编辑。
      </span>
      <span v-else>
        材料文件模板用于上传 Word、PDF 等文件；流程审核待办和消息请切换到“流程审核待办/消息模板”维护。
      </span>
    </div>

    <div v-if="activeTab === 'file'" class="support-toolbar">
      <el-select v-model="filters.category_id" clearable filterable placeholder="全部分类" @change="loadTemplates(1)">
        <el-option v-for="item in categories" :key="item.id" :label="item.name" :value="item.id" />
      </el-select>
      <el-input v-model="filters.keyword" clearable placeholder="搜索模板、说明、分类" @keyup.enter="loadTemplates(1)" />
      <el-button :icon="Search" :loading="loading" @click="loadTemplates(1)">查询</el-button>
      <el-button :icon="RefreshCw" :loading="loading" @click="reload">刷新</el-button>
      <el-button v-if="canManage" :icon="Plus" @click="openCategoryDialog">分类</el-button>
      <el-button v-if="canManage" type="primary" :icon="Upload" @click="openTemplateDialog()">上传模板</el-button>
    </div>

    <div v-if="activeTab === 'file'" class="template-category-strip">
      <button type="button" :class="{ active: !filters.category_id }" @click="setCategory('')">全部</button>
      <button
        v-for="item in categories"
        :key="item.id"
        type="button"
        :class="{ active: Number(filters.category_id) === Number(item.id) }"
        @click="setCategory(item.id)"
      >
        {{ item.name }}
      </button>
    </div>

    <section v-if="activeTab === 'file'" class="support-table">
      <el-table :data="templates" height="100%" stripe v-loading="loading">
        <template #empty>
          <div class="template-empty-state">
            <strong>暂无材料文件模板</strong>
            <span>这里仅显示 Word、PDF 等材料文件；流程审核的待办和消息模板在“流程审核待办/消息模板”中维护。</span>
            <div>
              <el-button v-if="canViewMessageTemplates" type="primary" @click="setActiveTab('message')">
                查看流程审核待办/消息模板
              </el-button>
              <el-button v-if="canManage" @click="openTemplateDialog()">上传材料文件模板</el-button>
            </div>
          </div>
        </template>
        <el-table-column label="模板" min-width="260">
          <template #default="{ row }">
            <div class="template-cell">
              <FileText :size="18" />
              <span>
                <strong>{{ row.name }}</strong>
                <small>{{ row.description || '无说明' }}</small>
              </span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="category_name" label="分类" width="150" />
        <el-table-column prop="version" label="版本" width="90" />
        <el-table-column label="文件" min-width="180">
          <template #default="{ row }">
            {{ row.file?.download_name || row.file?.name || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="大小" width="100">
          <template #default="{ row }">
            {{ formatFileSize(row.file?.size) }}
          </template>
        </el-table-column>
        <el-table-column prop="download_count" label="下载" width="80" />
        <el-table-column prop="updated_at" label="更新时间" width="168" />
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="downloadTemplate(row)">下载</el-button>
            <el-button v-if="canManage" link type="primary" @click="openTemplateDialog(row)">编辑</el-button>
            <el-button v-if="canManage" link type="danger" @click="deleteTemplate(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="file-pagination">
        <span>共 {{ pagination.total }} 个模板</span>
        <el-pagination
          size="small"
          layout="prev, pager, next"
          :current-page="pagination.page"
          :page-size="pagination.page_size"
          :total="pagination.total"
          @current-change="loadTemplates"
        />
      </div>
    </section>

    <section v-else class="support-table message-template-support">
      <div class="message-template-library-toolbar">
        <el-select v-model="messageFilters.type" @change="loadMessageTemplates(1)">
          <el-option
            v-for="item in messageTemplateTypeOptions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
        <el-select v-model="messageFilters.status" @change="loadMessageTemplates(1)">
          <el-option label="全部状态" value="all" />
          <el-option label="启用" value="enabled" />
          <el-option label="停用" value="disabled" />
        </el-select>
        <el-input v-model="messageFilters.keyword" clearable placeholder="搜索名称、编码、内容" @keyup.enter="loadMessageTemplates(1)" />
        <el-button :icon="Search" :loading="messageLoading" @click="loadMessageTemplates(1)">查询</el-button>
        <el-button
          v-if="canManageMessageTemplates"
          :icon="RefreshCw"
          :loading="messageSyncing"
          @click="syncDefaultMessageTemplates"
        >
          同步默认流程模板
        </el-button>
      </div>

      <el-table :data="messageTemplates" height="100%" stripe v-loading="messageLoading">
        <template #empty>
          <div class="template-empty-state">
            <strong>{{ messageTemplateEmptyTitle }}</strong>
            <span>{{ messageTemplateEmptyText }}</span>
            <el-button
              v-if="canManageMessageTemplates"
              type="primary"
              :loading="messageSyncing"
              @click="syncDefaultMessageTemplates"
            >
              同步默认流程模板
            </el-button>
          </div>
        </template>
        <el-table-column prop="name" label="模板名称" min-width="170" />
        <el-table-column prop="code" label="模板编码" min-width="210" show-overflow-tooltip />
        <el-table-column label="类型" width="100">
          <template #default="{ row }">{{ messageTypeText(row.type) }}</template>
        </el-table-column>
        <el-table-column label="级别" width="90">
          <template #default="{ row }">{{ messageLevelText(row.level) }}</template>
        </el-table-column>
        <el-table-column prop="title_tpl" label="标题模板" min-width="220" show-overflow-tooltip />
        <el-table-column prop="content_tpl" label="内容模板" min-width="320" show-overflow-tooltip />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'enabled' ? 'success' : 'info'" size="small">
              {{ row.status === 'enabled' ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <el-button v-if="canManageMessageTemplates" link type="primary" @click="openMessageTemplateDialog(row)">
              编辑
            </el-button>
            <span v-else>-</span>
          </template>
        </el-table-column>
      </el-table>

      <div class="file-pagination">
        <span>共 {{ messagePagination.total }} 个流程审核待办/消息模板</span>
        <el-pagination
          size="small"
          layout="prev, pager, next"
          :current-page="messagePagination.page"
          :page-size="messagePagination.page_size"
          :total="messagePagination.total"
          @current-change="loadMessageTemplates"
        />
      </div>
    </section>

    <small v-if="message">{{ message }}</small>

    <div v-if="messageTemplateDialog.visible" class="operation-mask" @click.self="closeMessageTemplateDialog">
      <section class="operation-dialog message-template-edit-dialog">
        <header>
          <strong>编辑流程消息模板</strong>
          <button type="button" @click="closeMessageTemplateDialog">关闭</button>
        </header>
        <div class="message-template-edit-body">
          <label>
            <span>模板名称</span>
            <el-input v-model="messageTemplateDialog.form.name" maxlength="180" />
          </label>
          <label>
            <span>模板编码</span>
            <el-input v-model="messageTemplateDialog.form.code" disabled maxlength="120" />
          </label>
          <label>
            <span>消息类型</span>
            <el-select v-model="messageTemplateDialog.form.type">
              <el-option
                v-for="item in messageTemplateTypeOptions.filter(option => option.value !== 'all')"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
          </label>
          <label>
            <span>消息级别</span>
            <el-select v-model="messageTemplateDialog.form.level">
              <el-option label="普通" value="normal" />
              <el-option label="重要" value="important" />
              <el-option label="紧急" value="urgent" />
            </el-select>
          </label>
          <label>
            <span>状态</span>
            <el-switch
              v-model="messageTemplateDialog.form.status"
              active-value="enabled"
              inactive-value="disabled"
              active-text="启用"
              inactive-text="停用"
            />
          </label>
          <label>
            <span>排序</span>
            <el-input v-model.number="messageTemplateDialog.form.sort" type="number" />
          </label>
          <label class="wide">
            <span>标题模板</span>
            <el-input v-model="messageTemplateDialog.form.title_tpl" maxlength="255" show-word-limit />
          </label>
          <label class="wide">
            <span>内容模板</span>
            <el-input v-model="messageTemplateDialog.form.content_tpl" type="textarea" :rows="4" />
          </label>
          <label class="wide">
            <span>跳转地址模板</span>
            <el-input v-model="messageTemplateDialog.form.link_url_tpl" placeholder="#panel={module_key}:{panel_key}" />
          </label>
          <label class="wide">
            <span>变量说明 JSON</span>
            <el-input v-model="messageTemplateDialog.form.variables_text" type="textarea" :rows="5" />
          </label>
          <label class="wide">
            <span>说明</span>
            <el-input v-model="messageTemplateDialog.form.description" type="textarea" :rows="2" maxlength="500" show-word-limit />
          </label>
        </div>
        <footer>
          <el-button @click="closeMessageTemplateDialog">取消</el-button>
          <el-button type="primary" :icon="Save" :loading="messageSaving" @click="saveMessageTemplateDialog">保存</el-button>
        </footer>
      </section>
    </div>

    <div v-if="templateDialog.visible" class="operation-mask" @click.self="closeTemplateDialog">
      <section class="operation-dialog template-edit-dialog">
        <header>
          <strong>{{ templateDialog.form.id ? '编辑模板' : '上传模板' }}</strong>
          <button type="button" @click="closeTemplateDialog">关闭</button>
        </header>
        <div class="operation-form">
          <label>
            <span>模板名称</span>
            <input v-model="templateDialog.form.name">
          </label>
          <label>
            <span>分类</span>
            <el-select v-model="templateDialog.form.category_id" clearable filterable placeholder="选择分类">
              <el-option v-for="item in categories" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
          </label>
          <label>
            <span>版本</span>
            <input v-model="templateDialog.form.version">
          </label>
          <label>
            <span>状态</span>
            <el-switch
              v-model="templateDialog.form.flag"
              active-value="on"
              inactive-value="off"
              active-text="启用"
              inactive-text="停用"
            />
          </label>
          <label class="span-2">
            <span>说明</span>
            <textarea v-model="templateDialog.form.description" rows="3" />
          </label>
          <div class="span-2 template-upload-row">
            <span>模板文件</span>
            <button type="button" @click="chooseFile">
              <Upload :size="16" />
              <strong>{{ templateDialog.fileName || '选择文件' }}</strong>
            </button>
            <small>{{ templateDialog.form.file_id ? `文件ID：${templateDialog.form.file_id}` : '支持 doc/docx/xls/xlsx/pdf/ppt/zip/txt/csv' }}</small>
            <input ref="fileInputRef" type="file" hidden @change="handleFileSelected">
          </div>
        </div>
        <footer>
          <el-button @click="closeTemplateDialog">取消</el-button>
          <el-button type="primary" :icon="Save" :loading="saving" @click="saveTemplate">保存</el-button>
        </footer>
      </section>
    </div>

    <div v-if="categoryDialog.visible" class="operation-mask" @click.self="categoryDialog.visible = false">
      <section class="operation-dialog category-edit-dialog">
        <header>
          <strong>模板分类</strong>
          <button type="button" @click="categoryDialog.visible = false">关闭</button>
        </header>
        <div class="operation-form">
          <label>
            <span>分类名称</span>
            <input v-model="categoryDialog.form.name">
          </label>
          <label>
            <span>编码</span>
            <input v-model="categoryDialog.form.code">
          </label>
          <label>
            <span>排序</span>
            <input v-model="categoryDialog.form.sort" type="number">
          </label>
          <label>
            <span>状态</span>
            <el-switch
              v-model="categoryDialog.form.flag"
              active-value="on"
              inactive-value="off"
              active-text="启用"
              inactive-text="停用"
            />
          </label>
          <label class="span-2">
            <span>说明</span>
            <textarea v-model="categoryDialog.form.description" rows="3" />
          </label>
        </div>
        <footer>
          <el-button @click="categoryDialog.visible = false">取消</el-button>
          <el-button type="primary" :icon="Save" :loading="saving" @click="saveCategory">保存分类</el-button>
        </footer>
      </section>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { FileText, Plus, RefreshCw, Save, Search, Upload } from '@lucide/vue';
import {
  deleteTemplateItem,
  downloadTemplateItem,
  fetchMessageTemplates,
  fetchTemplateCategories,
  fetchTemplateList,
  saveTemplateCategory,
  saveMessageTemplate,
  saveTemplateItem,
  syncMessageTemplates,
  uploadTemplateFile,
} from '../api/system';

const props = defineProps({
  canManage: {
    type: Boolean,
    default: false,
  },
  canViewMessageTemplates: {
    type: Boolean,
    default: false,
  },
  canManageMessageTemplates: {
    type: Boolean,
    default: false,
  },
});

const activeTab = ref(props.canViewMessageTemplates ? 'message' : 'file');
const loading = ref(false);
const saving = ref(false);
const messageLoading = ref(false);
const messageSaving = ref(false);
const messageSyncing = ref(false);
const messageDefaultSynced = ref(false);
const message = ref('');
const categories = ref([]);
const templates = ref([]);
const messageTemplates = ref([]);
const fileInputRef = ref(null);
const filters = reactive({
  category_id: '',
  keyword: '',
});
const messageFilters = reactive({
  type: 'all',
  status: 'enabled',
  keyword: '',
});
const messageTemplateTypeOptions = [
  { label: '全部类型', value: 'all' },
  { label: '系统通知', value: 'system' },
  { label: '待办提醒', value: 'todo' },
  { label: '处理结果', value: 'result' },
  { label: '预警提醒', value: 'alert' },
];
const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0,
});
const messagePagination = reactive({
  page: 1,
  page_size: 100,
  total: 0,
});
const messageTemplateEmptyTitle = computed(() => (
  hasMessageTemplateFilters() ? '当前筛选无流程模板' : '暂无启用流程审核待办/消息模板'
));
const messageTemplateEmptyText = computed(() => {
  if (hasMessageTemplateFilters()) {
    return '请调整类型、状态或关键词后重新查询。';
  }

  return props.canManageMessageTemplates
    ? '默认流程模板会自动写入学校业务库；学生提交、老师审核、通过后修改都会按启用模板发送待办和消息。'
    : '未读取到默认流程模板，请联系管理员同步。';
});
const templateDialog = reactive({
  visible: false,
  fileName: '',
  form: emptyTemplateForm(),
});
const messageTemplateDialog = reactive({
  visible: false,
  form: emptyMessageTemplateForm(),
});
const categoryDialog = reactive({
  visible: false,
  form: emptyCategoryForm(),
});

onMounted(reload);

watch(
  () => props.canViewMessageTemplates,
  async (canView) => {
    if (canView) {
      if (activeTab.value !== 'message') {
        activeTab.value = 'message';
      }
      await loadMessageTemplates(1);
      return;
    }

    if (activeTab.value === 'message') {
      activeTab.value = 'file';
      await reload();
    }
  }
);

async function reload() {
  if (activeTab.value === 'message') {
    await loadMessageTemplates(1);
    return;
  }

  await loadCategories();
  await loadTemplates(1);
}

async function setActiveTab(tab) {
  activeTab.value = tab;
  message.value = '';
  if (tab === 'message') {
    await loadMessageTemplates(1);
    return;
  }

  if (!categories.value.length) {
    await loadCategories();
  }
  await loadTemplates(1);
}

async function loadCategories() {
  try {
    const data = await fetchTemplateCategories();
    categories.value = data.items || [];
  } catch (error) {
    message.value = error.message;
  }
}

async function loadTemplates(page = 1) {
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchTemplateList({
      page,
      page_size: pagination.page_size,
      category_id: filters.category_id || '',
      keyword: filters.keyword,
    });
    templates.value = data.items || [];
    Object.assign(pagination, data.pagination || {});
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

async function loadMessageTemplates(page = 1) {
  if (!props.canViewMessageTemplates || messageLoading.value) {
    return;
  }

  messageLoading.value = true;
  message.value = '';
  try {
    if (page === 1 && shouldSyncDefaultMessageTemplates()) {
      await ensureDefaultMessageTemplates();
    }
    let data = await fetchMessageTemplates({
      page,
      page_size: messagePagination.page_size,
      type: messageFilters.type,
      status: messageFilters.status,
      keyword: messageFilters.keyword,
    });
    const shouldAutoSync = page === 1
      && props.canManageMessageTemplates
      && (data.items || []).length === 0
      && messageFilters.type === 'all'
      && ['all', 'enabled'].includes(messageFilters.status)
      && !String(messageFilters.keyword || '').trim();
    if (shouldAutoSync) {
      await syncMessageTemplates();
      data = await fetchMessageTemplates({
        page,
        page_size: messagePagination.page_size,
        type: messageFilters.type,
        status: messageFilters.status,
        keyword: messageFilters.keyword,
      });
    }
    messageTemplates.value = data.items || [];
    Object.assign(messagePagination, data.pagination || {});
  } catch (error) {
    message.value = error.message;
  } finally {
    messageLoading.value = false;
  }
}

async function syncDefaultMessageTemplates() {
  if (!props.canManageMessageTemplates || messageSyncing.value) {
    return;
  }

  messageSyncing.value = true;
  message.value = '';
  try {
    const data = await syncMessageTemplates();
    messageDefaultSynced.value = true;
    await loadMessageTemplates(1);
    message.value = `已同步默认流程模板，当前系统模板 ${data.system_total || 0} 个`;
  } catch (error) {
    message.value = error.message;
  } finally {
    messageSyncing.value = false;
  }
}

async function ensureDefaultMessageTemplates() {
  if (!props.canManageMessageTemplates || messageDefaultSynced.value || messageSyncing.value) {
    return;
  }

  messageSyncing.value = true;
  try {
    await syncMessageTemplates();
    messageDefaultSynced.value = true;
  } finally {
    messageSyncing.value = false;
  }
}

function shouldSyncDefaultMessageTemplates() {
  return props.canManageMessageTemplates
    && !messageDefaultSynced.value
    && messageFilters.type === 'all'
    && ['all', 'enabled'].includes(messageFilters.status)
    && !String(messageFilters.keyword || '').trim();
}

function hasMessageTemplateFilters() {
  return messageFilters.type !== 'all'
    || !['all', 'enabled'].includes(messageFilters.status)
    || Boolean(String(messageFilters.keyword || '').trim());
}

function setCategory(id) {
  filters.category_id = id;
  loadTemplates(1);
}

function openTemplateDialog(row = null) {
  templateDialog.form = row ? {
    id: row.id,
    category_id: row.category_id || '',
    name: row.name || '',
    description: row.description || '',
    file_id: row.file_id || '',
    version: row.version || '1.0',
    flag: row.flag || 'on',
    status: row.status || 'enabled',
  } : emptyTemplateForm();
  templateDialog.fileName = row?.file?.download_name || row?.file?.name || '';
  templateDialog.visible = true;
}

function openMessageTemplateDialog(row) {
  if (!props.canManageMessageTemplates) {
    message.value = '当前账号不能维护流程消息模板';
    return;
  }
  messageTemplateDialog.form = emptyMessageTemplateForm(row || {});
  messageTemplateDialog.visible = true;
}

function closeMessageTemplateDialog() {
  if (!messageSaving.value) {
    messageTemplateDialog.visible = false;
  }
}

function closeTemplateDialog() {
  templateDialog.visible = false;
}

function chooseFile() {
  if (fileInputRef.value) {
    fileInputRef.value.value = '';
    fileInputRef.value.click();
  }
}

async function handleFileSelected(event) {
  const file = event.target?.files?.[0];
  if (!file) {
    return;
  }
  saving.value = true;
  message.value = '';
  try {
    const data = await uploadTemplateFile(file);
    templateDialog.form.file_id = data.file_id;
    templateDialog.fileName = data.name || file.name;
    if (!templateDialog.form.name) {
      templateDialog.form.name = file.name.replace(/\.[^.]+$/, '');
    }
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function saveTemplate() {
  saving.value = true;
  message.value = '';
  try {
    await saveTemplateItem(templateDialog.form);
    templateDialog.visible = false;
    await loadTemplates(pagination.page || 1);
    message.value = '已保存';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function saveMessageTemplateDialog() {
  if (!props.canManageMessageTemplates || messageSaving.value) {
    return;
  }

  const form = messageTemplateDialog.form;
  if (!String(form.name || '').trim()) {
    message.value = '请填写模板名称';
    return;
  }
  if (!String(form.code || '').trim()) {
    message.value = '模板编码不能为空';
    return;
  }
  if (!String(form.title_tpl || '').trim()) {
    message.value = '请填写标题模板';
    return;
  }
  if (!String(form.content_tpl || '').trim()) {
    message.value = '请填写内容模板';
    return;
  }

  let variables = {};
  try {
    variables = form.variables_text ? JSON.parse(form.variables_text) : {};
  } catch (error) {
    message.value = '变量说明 JSON 格式不正确';
    return;
  }

  messageSaving.value = true;
  message.value = '';
  try {
    await saveMessageTemplate({
      id: form.id,
      name: form.name,
      code: form.code,
      title_tpl: form.title_tpl,
      content_tpl: form.content_tpl,
      type: form.type,
      level: form.level,
      status: form.status,
      sort: form.sort,
      description: form.description,
      link_url_tpl: form.link_url_tpl,
      variables,
      channels: ['internal'],
      is_system: form.is_system,
    });
    messageTemplateDialog.visible = false;
    await loadMessageTemplates(messagePagination.page || 1);
    message.value = '流程消息模板已保存';
  } catch (error) {
    message.value = error.message;
  } finally {
    messageSaving.value = false;
  }
}

async function deleteTemplate(row) {
  if (!window.confirm(`确认删除模板「${row.name}」？`)) {
    return;
  }
  saving.value = true;
  message.value = '';
  try {
    await deleteTemplateItem(row.id);
    await loadTemplates(1);
    message.value = '已删除';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function downloadTemplate(row) {
  message.value = '';
  try {
    const data = await downloadTemplateItem(row.id);
    if (data.url) {
      window.open(data.url, '_blank', 'noopener');
    }
    await loadTemplates(pagination.page || 1);
  } catch (error) {
    message.value = error.message;
  }
}

function openCategoryDialog() {
  categoryDialog.form = emptyCategoryForm();
  categoryDialog.visible = true;
}

async function saveCategory() {
  saving.value = true;
  message.value = '';
  try {
    const data = await saveTemplateCategory(categoryDialog.form);
    categories.value = data.categories?.items || [];
    categoryDialog.visible = false;
    message.value = '分类已保存';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

function emptyTemplateForm() {
  return {
    id: null,
    category_id: '',
    name: '',
    description: '',
    file_id: '',
    version: '1.0',
    flag: 'on',
    status: 'enabled',
  };
}

function emptyMessageTemplateForm(row = {}) {
  return {
    id: row.id || null,
    name: row.name || '',
    code: row.code || '',
    title_tpl: row.title_tpl || '',
    content_tpl: row.content_tpl || '',
    type: row.type || 'todo',
    level: row.level || 'important',
    status: row.status || 'enabled',
    sort: Number(row.sort || 100),
    description: row.description || '',
    link_url_tpl: row.link_url_tpl || '',
    variables_text: JSON.stringify(row.variables || {}, null, 2),
    is_system: Boolean(row.is_system),
  };
}

function messageTypeText(type) {
  return {
    system: '系统通知',
    alert: '预警提醒',
    audit: '审核消息',
    todo: '待办提醒',
    result: '审核结果',
  }[type] || '系统通知';
}

function messageLevelText(level) {
  return {
    normal: '普通',
    important: '重要',
    urgent: '紧急',
  }[level] || '普通';
}

function emptyCategoryForm() {
  return {
    id: null,
    code: '',
    name: '',
    description: '',
    sort: 0,
    flag: 'on',
    status: 'enabled',
  };
}

function formatFileSize(size) {
  const value = Number(size || 0);
  if (!value) {
    return '-';
  }
  if (value < 1024) {
    return `${value} B`;
  }
  if (value < 1024 * 1024) {
    return `${(value / 1024).toFixed(1)} KB`;
  }
  return `${(value / 1024 / 1024).toFixed(1)} MB`;
}
</script>
