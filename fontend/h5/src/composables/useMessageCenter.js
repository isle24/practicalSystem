import { computed, reactive } from 'vue';
import { showToast } from 'vant';
import { fetchMessages, fetchMessageSummary, markMessagesRead } from '../api/system';

const typeNames = {
  system: '系统通知',
  todo: '待办提醒',
  result: '处理结果',
  alert: '预警提醒',
};

const levelNames = {
  normal: '普通',
  important: '重要',
  urgent: '紧急',
};

export const messageTypeOptions = [
  { label: '全部类型', value: 'all' },
  { label: '待办', value: 'todo' },
  { label: '结果', value: 'result' },
  { label: '预警', value: 'alert' },
  { label: '系统', value: 'system' },
];

export function useMessageCenter(options = {}) {
  const state = reactive({
    loading: false,
    message: '',
    filter: 'all',
    type: 'all',
    items: [],
    summary: {
      unread: 0,
      by_type: {},
    },
    pagination: {
      page: 1,
      page_size: 20,
      total: 0,
    },
  });

  const unreadCount = computed(() => Number(state.summary.unread || 0));
  const groups = computed(() => groupMessagesByDay(state.items));

  function isLoggedIn() {
    return Boolean(options.isLoggedIn?.());
  }

  async function loadSummary() {
    if (!isLoggedIn()) {
      reset();
      return;
    }

    try {
      const data = await fetchMessageSummary();
      state.summary = {
        unread: Number(data.unread || 0),
        by_type: data.by_type || {},
      };
    } catch (error) {
      state.message = error.message;
    }
  }

  async function load(page = 1, append = false) {
    if (!isLoggedIn() || state.loading) {
      return;
    }

    state.loading = true;
    state.message = '';
    try {
      const data = await fetchMessages({
        page,
        page_size: state.pagination.page_size,
        status: state.filter,
        type: state.type,
      });
      const items = data.items || [];
      state.items = append ? [...state.items, ...items] : items;
      state.pagination = {
        page: Number(data.pagination?.page || page),
        page_size: Number(data.pagination?.page_size || state.pagination.page_size),
        total: Number(data.pagination?.total || 0),
      };
      await loadSummary();
    } catch (error) {
      state.message = error.message;
    } finally {
      state.loading = false;
    }
  }

  function loadMore() {
    if (state.items.length < state.pagination.total) {
      load(state.pagination.page + 1, true);
    }
  }

  function setFilter(filter) {
    state.filter = filter;
    load(1);
  }

  function setType(type) {
    state.type = type;
    load(1);
  }

  async function markRead(item) {
    if (!item?.target_id || item.is_read) {
      return;
    }

    try {
      const data = await markMessagesRead({ ids: [item.target_id] });
      item.is_read = true;
      item.read_at = new Date().toLocaleString();
      state.summary = data.summary || state.summary;
      if (state.filter === 'unread') {
        state.items = state.items.filter(row => row.target_id !== item.target_id);
        state.pagination.total = Math.max(0, state.pagination.total - 1);
      }
    } catch (error) {
      state.message = error.message;
      showToast(error.message);
    }
  }

  async function markAllRead() {
    if (unreadCount.value <= 0 || state.loading) {
      return;
    }

    state.loading = true;
    state.message = '';
    try {
      const data = await markMessagesRead({ all: true });
      state.summary = data.summary || { unread: 0, by_type: {} };
      state.items = state.filter === 'unread'
        ? []
        : state.items.map(item => ({ ...item, is_read: true, read_at: item.read_at || new Date().toLocaleString() }));
      if (state.filter === 'unread') {
        state.pagination = { ...state.pagination, page: 1, total: 0 };
      }
    } catch (error) {
      state.message = error.message;
      showToast(error.message);
    } finally {
      state.loading = false;
    }
  }

  async function openLinked(item) {
    await markRead(item);
    const link = String(item?.link_url || '').trim();
    if (!link) {
      return;
    }
    if (link.startsWith('#tab=')) {
      options.navigate?.(link.replace('#tab=', '').trim());
      return;
    }
    if (link.startsWith('#')) {
      const tab = link.slice(1).split(':')[0].replace('panel=', '').trim();
      if (tab && options.canNavigate?.(tab)) {
        options.navigate?.(tab);
      }
      return;
    }
    window.open(link, '_blank', 'noopener,noreferrer');
  }

  function typeUnread(type) {
    return type === 'all' ? unreadCount.value : Number(state.summary.by_type?.[type] || 0);
  }

  function typeText(type) {
    return typeNames[type] || type || '系统通知';
  }

  function levelText(level) {
    return levelNames[level] || level || '普通';
  }

  function isOwn(item) {
    return Number(item?.sender_id || 0) > 0
      && Number(item.sender_id) === Number(options.currentAccountId?.() || 0);
  }

  function reset() {
    state.loading = false;
    state.message = '';
    state.filter = 'all';
    state.type = 'all';
    state.items = [];
    state.summary = { unread: 0, by_type: {} };
    state.pagination = { page: 1, page_size: 20, total: 0 };
  }

  return {
    state,
    groups,
    typeOptions: messageTypeOptions,
    unreadCount,
    isOwn,
    levelText,
    load,
    loadMore,
    loadSummary,
    markAllRead,
    markRead,
    openLinked,
    reset,
    setFilter,
    setType,
    timeText: messageTimeText,
    typeText,
    typeUnread,
  };
}

function groupMessagesByDay(items) {
  const groups = new Map();
  const sortedItems = [...(items || [])].sort((a, b) => {
    const timeA = new Date(String(a.created_at || '').replace(' ', 'T')).getTime() || 0;
    const timeB = new Date(String(b.created_at || '').replace(' ', 'T')).getTime() || 0;
    return timeA !== timeB ? timeA - timeB : Number(a.target_id || 0) - Number(b.target_id || 0);
  });
  sortedItems.forEach((item) => {
    const key = messageDateKey(item.date_key || item.created_at);
    if (!groups.has(key)) {
      groups.set(key, { key, label: messageDayLabel(key), items: [] });
    }
    groups.get(key).items.push(item);
  });
  return Array.from(groups.values());
}

function messageDateKey(value) {
  const text = String(value || '');
  return /^\d{4}-\d{2}-\d{2}/.test(text) ? text.slice(0, 10) : formatDateKey(new Date());
}

function messageDayLabel(key) {
  const today = formatDateKey(new Date());
  const yesterdayDate = new Date();
  yesterdayDate.setDate(yesterdayDate.getDate() - 1);
  const yesterday = formatDateKey(yesterdayDate);
  if (key === today) return '今天';
  if (key === yesterday) return '昨天';
  return key;
}

function messageTimeText(value) {
  if (value && typeof value === 'object') {
    return value.time_label || messageTimeText(value.created_at);
  }
  const text = String(value || '');
  return /^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(text) ? text.slice(11, 16) : (text || '-');
}

function formatDateKey(date) {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-');
}
