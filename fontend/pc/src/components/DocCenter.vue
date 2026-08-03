<template>
  <section class="support-panel doc-center-panel">
    <div class="support-toolbar">
      <el-select v-model="filters.category_id" clearable filterable placeholder="全部分类" @change="loadArticles(1)">
        <el-option v-for="item in flatCategories" :key="item.id" :label="item.name" :value="item.id" />
      </el-select>
      <el-select v-if="canManage" v-model="filters.status" @change="loadArticles(1)">
        <el-option label="全部状态" value="all" />
        <el-option label="草稿" value="draft" />
        <el-option label="已发布" value="published" />
        <el-option label="已归档" value="archived" />
      </el-select>
      <el-input v-model="filters.keyword" clearable placeholder="搜索标题、内容、分类" @keyup.enter="loadArticles(1)" />
      <el-button :icon="Search" :loading="loading" @click="loadArticles(1)">查询</el-button>
      <el-button :icon="RefreshCw" :loading="loading" @click="reload">刷新</el-button>
      <el-button v-if="canManage" :icon="Plus" @click="openCategoryDialog()">分类</el-button>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openArticleDialog()">新增文档</el-button>
    </div>

    <div class="support-layout doc-layout">
      <aside class="support-side">
        <button type="button" :class="{ active: !filters.category_id }" @click="setCategory('')">
          <BookOpen :size="17" />
          <span>全部文档</span>
        </button>
        <button
          v-for="item in flatCategories"
          :key="item.id"
          type="button"
          :class="{ active: Number(filters.category_id) === Number(item.id) }"
          @click="setCategory(item.id)"
        >
          <BookOpen :size="17" />
          <span>{{ item.name }}</span>
        </button>
      </aside>

      <section class="support-table">
        <el-table :data="articles" height="100%" stripe v-loading="loading" @row-click="openDetail">
          <el-table-column type="index" label="序号" width="66" align="center" :index="index => tableSequence(index, pagination)" />
          <el-table-column prop="title" label="标题" min-width="220" />
          <el-table-column prop="category_name" label="分类" width="130" />
          <el-table-column prop="version" label="版本" width="90" />
          <el-table-column label="状态" width="96">
            <template #default="{ row }">
              <el-tag :type="statusTagType(row.status)">{{ articleStatusText(row.status) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="author_name" label="维护人" width="120" />
          <el-table-column prop="updated_at" label="更新时间" width="168" />
          <el-table-column v-if="canManage" label="操作" width="160" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click.stop="openArticleDialog(row)">编辑</el-button>
              <el-button link type="danger" @click.stop="deleteArticle(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="file-pagination">
          <span>共 {{ pagination.total }} 篇文档</span>
          <el-pagination
            size="small"
            layout="prev, pager, next"
            :current-page="pagination.page"
            :page-size="pagination.page_size"
            :total="pagination.total"
            @current-change="loadArticles"
          />
        </div>
      </section>

      <aside class="support-detail">
        <template v-if="selectedArticle">
          <header>
            <span>{{ selectedArticle.category_name || '未分类' }}</span>
            <strong>{{ selectedArticle.title }}</strong>
            <small>版本 {{ selectedArticle.version || '-' }} / 浏览 {{ selectedArticle.view_count || 0 }}</small>
          </header>
          <article class="rich-content support-rich" v-html="selectedArticle.content" />
          <footer v-if="canManage">
            <el-button :icon="FileClock" @click="loadHistory(selectedArticle.id)">版本记录</el-button>
          </footer>
        </template>
        <div v-else class="module-empty-state">
          <strong>选择文档</strong>
          <span>点击左侧列表查看文档内容。</span>
        </div>
      </aside>
    </div>

    <small v-if="message">{{ message }}</small>

    <OperationDialog
      :visible="articleDialog.visible"
      :title="articleDialog.form.id ? '编辑文档' : '新增文档'"
      dialog-class="doc-edit-dialog"
      :busy="saving"
      @close="closeArticleDialog"
    >
        <div class="operation-form">
          <label>
            <span>标题</span>
            <input v-model="articleDialog.form.title">
          </label>
          <label>
            <span>分类</span>
            <el-select v-model="articleDialog.form.category_id" clearable filterable placeholder="选择分类" @change="syncArticleVisibleRolesByCategory">
              <el-option v-for="item in flatCategories" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
          </label>
          <label>
            <span>版本</span>
            <input v-model="articleDialog.form.version">
          </label>
          <label>
            <span>状态</span>
            <el-select v-model="articleDialog.form.status">
              <el-option label="草稿" value="draft" />
              <el-option label="发布" value="published" />
              <el-option label="归档" value="archived" />
            </el-select>
          </label>
          <label>
            <span>适用角色</span>
            <el-select v-model="articleDialog.form.visible_roles" multiple collapse-tags collapse-tags-tooltip>
              <el-option v-for="item in roleOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </label>
          <label class="span-2">
            <span>变更说明</span>
            <input v-model="articleDialog.form.change_note" placeholder="用于版本记录">
          </label>
          <section class="span-2 doc-rich-field">
            <span>正文</span>
            <div class="rich-editor-toolbar">
              <button type="button" @click="applyArticleBlock('h3')">标题</button>
              <button type="button" @click="applyArticleBlock('p')">正文</button>
              <button type="button" @click="applyArticleFormat('bold')">B</button>
              <button type="button" @click="applyArticleFormat('insertUnorderedList')">列表</button>
              <button type="button" @click="applyArticleFormat('insertOrderedList')">编号</button>
              <button type="button" @click="insertArticleTemplate">模板</button>
            </div>
            <div
              ref="articleEditorRef"
              class="rich-editor doc-rich-editor"
              contenteditable="true"
              data-placeholder="请输入文档正文"
              @input="syncArticleEditor"
              v-html="articleDialog.form.content"
            />
          </section>
        </div>
        <template #footer>
          <el-button @click="closeArticleDialog">取消</el-button>
          <el-button type="primary" :icon="Save" :loading="saving" @click="saveArticle">保存</el-button>
        </template>
    </OperationDialog>

    <OperationDialog
      :visible="categoryDialog.visible"
      title="文档分类"
      dialog-class="category-edit-dialog"
      :busy="saving"
      @close="closeCategoryDialog"
    >
        <div class="operation-form">
          <label>
            <span>分类名称</span>
            <input v-model="categoryDialog.form.name">
          </label>
          <label>
            <span>编码</span>
            <input v-model="categoryDialog.form.code" placeholder="如 practice_flow">
          </label>
          <label>
            <span>父级</span>
            <el-select v-model="categoryDialog.form.parent_id" clearable filterable placeholder="顶级">
              <el-option v-for="item in flatCategories" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
          </label>
          <label>
            <span>排序</span>
            <input v-model="categoryDialog.form.sort" type="number">
          </label>
        </div>
        <template #footer>
          <el-button @click="closeCategoryDialog">取消</el-button>
          <el-button type="primary" :icon="Save" :loading="saving" @click="saveCategory">保存分类</el-button>
        </template>
    </OperationDialog>

    <OperationDialog :visible="historyDialog.visible" title="版本记录" dialog-class="history-dialog" @close="historyDialog.visible = false">
        <el-table :data="historyDialog.items" height="100%" stripe>
          <el-table-column type="index" label="序号" width="66" align="center" />
          <el-table-column prop="version" label="版本" width="90" />
          <el-table-column prop="title" label="标题" min-width="180" />
          <el-table-column prop="change_note" label="说明" min-width="220" />
          <el-table-column prop="editor_name" label="维护人" width="120" />
          <el-table-column prop="created_at" label="时间" width="168" />
        </el-table>
    </OperationDialog>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { BookOpen, FileClock, Plus, RefreshCw, Save, Search } from '@lucide/vue';
import {
  deleteDocArticle,
  fetchDocCategories,
  fetchDocDetail,
  fetchDocHistory,
  fetchDocList,
  saveDocArticle,
  saveDocCategory,
} from '../api/system';
import OperationDialog from './OperationDialog.vue';
import { tableSequence } from '../utils/table';

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
const articles = ref([]);
const selectedArticle = ref(null);
const articleEditorRef = ref(null);
const filters = reactive({
  category_id: '',
  status: 'all',
  keyword: '',
});
const roleOptions = [
  { value: 'all', label: '全员' },
  { value: 'admin', label: '管理员' },
  { value: 'teacher', label: '教师' },
  { value: 'student', label: '学生' },
  { value: 'enterprise', label: '企业' },
];
const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0,
});
const articleDialog = reactive({
  visible: false,
  form: emptyArticleForm(),
});
const categoryDialog = reactive({
  visible: false,
  form: emptyCategoryForm(),
});
const historyDialog = reactive({
  visible: false,
  items: [],
});

const flatCategories = computed(() => flattenCategories(categories.value));

onMounted(reload);

async function reload() {
  await loadCategories();
  await loadArticles(1);
}

async function loadCategories() {
  try {
    const data = await fetchDocCategories();
    categories.value = data.tree || data.items || [];
  } catch (error) {
    message.value = error.message;
  }
}

async function loadArticles(page = 1) {
  loading.value = true;
  message.value = '';
  try {
    const data = await fetchDocList({
      page,
      page_size: pagination.page_size,
      category_id: filters.category_id || '',
      status: filters.status,
      keyword: filters.keyword,
    });
    articles.value = data.items || [];
    Object.assign(pagination, data.pagination || {});
    if (selectedArticle.value && !articles.value.some(item => Number(item.id) === Number(selectedArticle.value.id))) {
      selectedArticle.value = null;
    }
    if (!selectedArticle.value && articles.value.length) {
      await openDetail(articles.value[0]);
    }
  } catch (error) {
    message.value = error.message;
  } finally {
    loading.value = false;
  }
}

async function openDetail(row) {
  try {
    const data = await fetchDocDetail(row.id);
    selectedArticle.value = data.article;
  } catch (error) {
    message.value = error.message;
  }
}

function setCategory(id) {
  filters.category_id = id;
  selectedArticle.value = null;
  loadArticles(1);
}

function openArticleDialog(row = null) {
  articleDialog.form = row ? {
    id: row.id,
    category_id: row.category_id || '',
    title: row.title || '',
    version: row.version || '1.0',
    status: row.status || 'draft',
    visible_roles: Array.isArray(row.visible_roles) && row.visible_roles.length ? row.visible_roles : defaultVisibleRoles(row),
    content: selectedArticle.value?.id === row.id ? selectedArticle.value.content : '',
    change_note: '更新文档',
  } : emptyArticleForm();
  if (row && articleDialog.form.content === '') {
    fetchDocDetail(row.id).then((data) => {
      articleDialog.form.content = data.article?.content || '';
      nextTick(syncArticleEditorDom);
    }).catch((error) => {
      message.value = error.message;
    });
  }
  articleDialog.visible = true;
  nextTick(syncArticleEditorDom);
}

function closeArticleDialog() {
  articleDialog.visible = false;
}

async function saveArticle() {
  syncArticleEditor();
  saving.value = true;
  message.value = '';
  try {
    const data = await saveDocArticle(articleDialog.form);
    articleDialog.visible = false;
    await loadArticles(pagination.page || 1);
    if (data.article) {
      selectedArticle.value = data.article;
    }
    message.value = '已保存';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

function activeArticleEditor() {
  const editor = articleEditorRef.value;
  return Array.isArray(editor) ? editor.at(-1) : editor;
}

function syncArticleEditorDom() {
  const editor = activeArticleEditor();
  if (editor && editor.innerHTML !== articleDialog.form.content) {
    editor.innerHTML = articleDialog.form.content || '';
  }
}

function syncArticleEditor() {
  const editor = activeArticleEditor();
  articleDialog.form.content = editor?.innerHTML || '';
}

function applyArticleFormat(command) {
  const editor = activeArticleEditor();
  if (!editor) {
    return;
  }
  editor.focus();
  document.execCommand(command, false, null);
  syncArticleEditor();
}

function applyArticleBlock(tagName) {
  const editor = activeArticleEditor();
  if (!editor) {
    return;
  }
  editor.focus();
  document.execCommand('formatBlock', false, tagName);
  syncArticleEditor();
}

function insertArticleTemplate() {
  const editor = activeArticleEditor();
  const html = articleTemplateHtml();
  if (!editor) {
    articleDialog.form.content = html;
    return;
  }

  editor.focus();
  if (!stripHtml(articleDialog.form.content).trim()) {
    editor.innerHTML = html;
  } else {
    document.execCommand('insertHTML', false, html);
  }
  syncArticleEditor();
}

async function deleteArticle(row) {
  if (!window.confirm(`确认删除文档「${row.title}」？`)) {
    return;
  }
  saving.value = true;
  message.value = '';
  try {
    await deleteDocArticle(row.id);
    if (selectedArticle.value?.id === row.id) {
      selectedArticle.value = null;
    }
    await loadArticles(1);
    message.value = '已删除';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

function openCategoryDialog() {
  categoryDialog.form = emptyCategoryForm();
  categoryDialog.visible = true;
}

function closeCategoryDialog() {
  categoryDialog.visible = false;
}

async function saveCategory() {
  saving.value = true;
  message.value = '';
  try {
    const data = await saveDocCategory(categoryDialog.form);
    categories.value = data.categories?.tree || data.categories?.items || [];
    categoryDialog.visible = false;
    message.value = '分类已保存';
  } catch (error) {
    message.value = error.message;
  } finally {
    saving.value = false;
  }
}

async function loadHistory(articleId) {
  historyDialog.visible = true;
  historyDialog.items = [];
  try {
    const data = await fetchDocHistory(articleId);
    historyDialog.items = data.items || [];
  } catch (error) {
    message.value = error.message;
  }
}

function emptyArticleForm() {
  return {
    id: null,
    category_id: '',
    title: '',
    version: '1.0',
    visible_roles: ['all'],
    status: 'draft',
    content: articleTemplateHtml(),
    change_note: '新增文档',
  };
}

function defaultVisibleRoles(row) {
  const code = row?.category_code || '';
  if (code === 'admin_help') {
    return ['admin'];
  }
  if (code === 'teacher_help') {
    return ['teacher', 'admin'];
  }
  if (code === 'student_help') {
    return ['student', 'teacher', 'admin'];
  }
  return ['all'];
}

function syncArticleVisibleRolesByCategory() {
  const category = flatCategories.value.find(item => Number(item.id) === Number(articleDialog.form.category_id));
  articleDialog.form.visible_roles = defaultVisibleRoles({
    category_code: category?.code || '',
  });
}

function articleTemplateHtml() {
  return '<h3>操作流程</h3><p></p><h3>常见问题</h3><p></p>';
}

function stripHtml(content) {
  const element = document.createElement('div');
  element.innerHTML = content || '';
  return element.textContent || element.innerText || '';
}

function emptyCategoryForm() {
  return {
    id: null,
    parent_id: '',
    name: '',
    code: '',
    icon: 'BookOpen',
    sort: 0,
    status: 'enabled',
  };
}

function flattenCategories(items, level = 0) {
  const rows = [];
  (items || []).forEach((item) => {
    rows.push({
      ...item,
      name: `${'　'.repeat(level)}${item.name}`,
    });
    rows.push(...flattenCategories(item.children || [], level + 1));
  });
  return rows;
}

function articleStatusText(status) {
  const names = {
    draft: '草稿',
    published: '已发布',
    archived: '已归档',
  };
  return names[status] || status || '-';
}

function statusTagType(status) {
  if (status === 'published') {
    return 'success';
  }
  if (status === 'archived') {
    return 'info';
  }
  return 'warning';
}
</script>
