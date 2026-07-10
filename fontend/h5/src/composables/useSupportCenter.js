import { reactive } from 'vue';
import { showToast } from 'vant';
import {
  downloadTemplateItem,
  fetchDocCategories,
  fetchDocDetail,
  fetchDocList,
  fetchTemplateCategories,
  fetchTemplateList,
} from '../api/system';

function createSupportState() {
  return {
    doc: {
      loading: false,
      message: '',
      categories: [],
      items: [],
      detail: {
        visible: false,
        loading: false,
        message: '',
        article: null,
      },
      filters: {
        category_id: '',
        keyword: '',
      },
      pagination: {
        page: 1,
        page_size: 20,
        total: 0,
      },
    },
    template: {
      loading: false,
      message: '',
      categories: [],
      items: [],
      filters: {
        category_id: '',
        keyword: '',
      },
      pagination: {
        page: 1,
        page_size: 20,
        total: 0,
      },
    },
  };
}

export function useSupportCenter({ isLoggedIn, hasPermission }) {
  const support = reactive(createSupportState());

  async function loadCategories() {
    if (!isLoggedIn()) {
      support.doc.categories = [];
      support.template.categories = [];
      return;
    }

    const tasks = [];
    if (hasPermission('doc:view')) {
      tasks.push(fetchDocCategories().then((data) => {
        support.doc.categories = flattenCategories(data.tree || data.items || []);
      }));
    }
    if (hasPermission('template:view')) {
      tasks.push(fetchTemplateCategories().then((data) => {
        support.template.categories = data.items || [];
      }));
    }

    try {
      await Promise.all(tasks);
    } catch (error) {
      support.doc.message = error.message;
      support.template.message = error.message;
    }
  }

  async function loadDocs(page = 1, append = false) {
    if (!isLoggedIn() || !hasPermission('doc:view') || support.doc.loading) {
      return;
    }

    support.doc.loading = true;
    support.doc.message = '';
    try {
      if (!support.doc.categories.length) {
        const categories = await fetchDocCategories();
        support.doc.categories = flattenCategories(categories.tree || categories.items || []);
      }
      const data = await fetchDocList({
        page,
        page_size: support.doc.pagination.page_size,
        category_id: support.doc.filters.category_id || '',
        keyword: support.doc.filters.keyword || '',
      });
      const items = data.items || [];
      support.doc.items = append ? [...support.doc.items, ...items] : items;
      support.doc.pagination = {
        page: Number(data.pagination?.page || page),
        page_size: Number(data.pagination?.page_size || support.doc.pagination.page_size),
        total: Number(data.pagination?.total || 0),
      };
    } catch (error) {
      support.doc.message = error.message;
      showToast(error.message);
    } finally {
      support.doc.loading = false;
    }
  }

  async function loadTemplates(page = 1, append = false) {
    if (!isLoggedIn() || !hasPermission('template:view') || support.template.loading) {
      return;
    }

    support.template.loading = true;
    support.template.message = '';
    try {
      if (!support.template.categories.length) {
        const categories = await fetchTemplateCategories();
        support.template.categories = categories.items || [];
      }
      const data = await fetchTemplateList({
        page,
        page_size: support.template.pagination.page_size,
        category_id: support.template.filters.category_id || '',
        keyword: support.template.filters.keyword || '',
      });
      const items = data.items || [];
      support.template.items = append ? [...support.template.items, ...items] : items;
      support.template.pagination = {
        page: Number(data.pagination?.page || page),
        page_size: Number(data.pagination?.page_size || support.template.pagination.page_size),
        total: Number(data.pagination?.total || 0),
      };
    } catch (error) {
      support.template.message = error.message;
      showToast(error.message);
    } finally {
      support.template.loading = false;
    }
  }

  async function openDoc(row) {
    if (!row?.id) {
      return;
    }

    support.doc.detail.visible = true;
    support.doc.detail.loading = true;
    support.doc.detail.message = '';
    support.doc.detail.article = row;
    try {
      const data = await fetchDocDetail(row.id);
      support.doc.detail.article = data.article || row;
    } catch (error) {
      support.doc.detail.message = error.message;
      showToast(error.message);
    } finally {
      support.doc.detail.loading = false;
    }
  }

  function closeDoc() {
    support.doc.detail.visible = false;
  }

  async function downloadTemplate(row) {
    if (!row?.id) {
      return;
    }

    support.template.message = '';
    try {
      const data = await downloadTemplateItem(row.id);
      if (data.url) {
        window.open(data.url, '_blank', 'noopener');
      } else {
        support.template.message = '模板文件暂无下载地址';
        showToast(support.template.message);
      }
      await loadTemplates(support.template.pagination.page || 1);
    } catch (error) {
      support.template.message = error.message;
      showToast(error.message);
    }
  }

  function canLoadMore(type) {
    const target = type === 'template' ? support.template : support.doc;
    return target.items.length < (target.pagination.total || 0);
  }

  function reset() {
    const initial = createSupportState();
    support.doc = initial.doc;
    support.template = initial.template;
  }

  return {
    support,
    canLoadMore,
    closeDoc,
    downloadTemplate,
    loadCategories,
    loadDocs,
    loadTemplates,
    openDoc,
    reset,
  };
}

function flattenCategories(rows, level = 0) {
  const result = [];
  (rows || []).forEach((row) => {
    result.push({
      ...row,
      name: `${'　'.repeat(level)}${row.name || '-'}`,
    });
    if (row.children?.length) {
      result.push(...flattenCategories(row.children, level + 1));
    }
  });
  return result;
}
