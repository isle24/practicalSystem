<template>
  <section class="support-panel template-library-panel">
    <div class="support-toolbar">
      <el-select v-model="filters.category_id" clearable filterable placeholder="全部分类" @change="loadTemplates(1)">
        <el-option v-for="item in categories" :key="item.id" :label="item.name" :value="item.id" />
      </el-select>
      <el-input v-model="filters.keyword" clearable placeholder="搜索模板、说明、分类" @keyup.enter="loadTemplates(1)" />
      <el-button :icon="Search" :loading="loading" @click="loadTemplates(1)">查询</el-button>
      <el-button :icon="RefreshCw" :loading="loading" @click="reload">刷新</el-button>
      <el-button v-if="canManage" :icon="Plus" @click="openCategoryDialog">分类</el-button>
      <el-button v-if="canManage" type="primary" :icon="Upload" @click="openTemplateDialog()">上传模板</el-button>
    </div>

    <div class="template-category-strip">
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

    <section class="support-table">
      <el-table :data="templates" height="100%" stripe v-loading="loading">
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

    <small v-if="message">{{ message }}</small>

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
            <el-select v-model="templateDialog.form.flag">
              <el-option label="启用" value="on" />
              <el-option label="停用" value="off" />
            </el-select>
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
            <el-select v-model="categoryDialog.form.flag">
              <el-option label="启用" value="on" />
              <el-option label="停用" value="off" />
            </el-select>
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
import { onMounted, reactive, ref } from 'vue';
import { FileText, Plus, RefreshCw, Save, Search, Upload } from '@lucide/vue';
import {
  deleteTemplateItem,
  downloadTemplateItem,
  fetchTemplateCategories,
  fetchTemplateList,
  saveTemplateCategory,
  saveTemplateItem,
  uploadTemplateFile,
} from '../api/system';

defineProps({
  canManage: {
    type: Boolean,
    default: false,
  },
});

const loading = ref(false);
const saving = ref(false);
const message = ref('');
const categories = ref([]);
const templates = ref([]);
const fileInputRef = ref(null);
const filters = reactive({
  category_id: '',
  keyword: '',
});
const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0,
});
const templateDialog = reactive({
  visible: false,
  fileName: '',
  form: emptyTemplateForm(),
});
const categoryDialog = reactive({
  visible: false,
  form: emptyCategoryForm(),
});

onMounted(reload);

async function reload() {
  await loadCategories();
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
