<template>
  <AppErrorState
    v-if="state.message && !state.items.length"
    title="消息加载失败"
    :message="state.message"
    @retry="emit('reload')"
  />

  <template v-else>
    <section class="app-message-toolbar">
      <AppSegmented
        :model-value="state.filter"
        :items="filterItems"
        @update:model-value="emit('filter', $event)"
      />
      <AppButton
        variant="quiet"
        size="small"
        :disabled="unreadCount <= 0 || state.loading"
        @click="emit('read-all')"
      >
        全部已读
      </AppButton>
    </section>

    <section class="app-message-types app-scroll-x" aria-label="消息类型">
      <AppButton
        v-for="item in typeOptions"
        :key="item.value"
        variant="secondary"
        size="small"
        :selected="state.type === item.value"
        @click="emit('type', item.value)"
      >
        {{ item.label }}
        <span class="app-message-type-count">{{ typeUnread(item.value) }}</span>
      </AppButton>
    </section>

    <section v-if="groups.length" class="app-message-list">
      <div v-for="group in groups" :key="group.key" class="app-message-day">
        <time>{{ group.label }}</time>
        <article
          v-for="item in group.items"
          :key="item.target_id"
          class="app-message-card"
          :class="{ unread: !item.is_read, urgent: item.level === 'urgent', own: isOwn(item) }"
          @click="emit('open', item)"
        >
          <header>
            <span class="app-message-kind" :class="item.type">{{ typeText(item.type) }}</span>
            <small>{{ timeText(item) }}</small>
          </header>
          <strong>{{ item.title }}</strong>
          <p>{{ item.content }}</p>
          <footer>
            <span>{{ isOwn(item) ? '我发送' : (item.sender_name || '系统') }}</span>
            <span :class="{ danger: item.level === 'urgent' }">{{ levelText(item.level) }}</span>
            <span :class="{ unread: !item.is_read }">{{ item.is_read ? '已读' : '未读' }}</span>
            <AppButton v-if="item.link_url" variant="quiet" size="small" @click.stop="emit('linked', item)">
              查看关联
            </AppButton>
          </footer>
        </article>
      </div>
    </section>

    <AppEmptyState v-else title="暂无消息" description="新的通知会按时间显示在这里。" />

    <div v-if="groups.length" class="app-message-load-more">
      <AppButton
        variant="secondary"
        size="small"
        :loading="state.loading"
        :disabled="state.items.length >= state.pagination.total"
        @click="emit('load-more')"
      >
        {{ state.items.length >= state.pagination.total ? '没有更多' : '加载更多' }}
      </AppButton>
    </div>
  </template>
</template>

<script setup>
import { computed } from 'vue';
import AppButton from '../components/ui/AppButton.vue';
import AppEmptyState from '../components/ui/AppEmptyState.vue';
import AppErrorState from '../components/ui/AppErrorState.vue';
import AppSegmented from '../components/ui/AppSegmented.vue';

const props = defineProps({
  state: { type: Object, required: true },
  groups: { type: Array, default: () => [] },
  unreadCount: { type: Number, default: 0 },
  typeOptions: { type: Array, default: () => [] },
  isOwn: { type: Function, required: true },
  levelText: { type: Function, required: true },
  timeText: { type: Function, required: true },
  typeText: { type: Function, required: true },
  typeUnread: { type: Function, required: true },
});

const emit = defineEmits(['filter', 'linked', 'load-more', 'open', 'read-all', 'reload', 'type']);
const filterItems = computed(() => [
  { value: 'all', label: '全部' },
  { value: 'unread', label: `未读 ${props.unreadCount}` },
]);
</script>

<style scoped>
.app-message-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.app-message-types {
  display: flex;
  gap: 8px;
  margin: 0 -12px 12px;
  padding: 0 12px;
}

.app-message-types :deep(.app-button) {
  flex: 0 0 auto;
}

.app-message-type-count {
  min-width: 18px;
  height: 18px;
  border-radius: 999px;
  display: grid;
  place-items: center;
  padding: 0 4px;
  color: var(--app-text-secondary);
  background: var(--app-neutral-soft);
  font-size: 10px;
}

.app-message-list,
.app-message-day {
  display: grid;
  gap: 10px;
}

.app-message-list {
  gap: 14px;
}

.app-message-day > time {
  justify-self: center;
  padding: 3px 9px;
  color: var(--app-text-tertiary);
  font-size: 11px;
}

.app-message-card {
  margin-right: 28px;
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  padding: 12px;
  background: var(--app-surface);
  box-shadow: var(--app-shadow-sm);
  transform: scale(1);
  transition: border-color var(--app-motion-control) var(--app-ease-standard),
    background var(--app-motion-control) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.app-message-card:active {
  transform: scale(.99);
}

.app-message-card.own {
  margin-right: 0;
  margin-left: 28px;
  border-color: color-mix(in srgb, var(--app-success) 28%, var(--app-line));
  background: var(--app-success-soft);
}

.app-message-card.unread {
  border-color: color-mix(in srgb, var(--app-info) 35%, var(--app-line));
  background: #f7fbff;
}

.app-message-card.urgent {
  border-color: color-mix(in srgb, var(--app-danger) 35%, var(--app-line));
}

.app-message-card header,
.app-message-card footer {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--app-text-secondary);
  font-size: 12px;
}

.app-message-card header {
  justify-content: space-between;
}

.app-message-kind {
  min-height: 22px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  padding: 0 8px;
  color: var(--app-neutral);
  background: var(--app-neutral-soft);
  font-size: 11px;
  font-weight: 600;
}

.app-message-kind.todo { color: var(--app-info); background: var(--app-info-soft); }
.app-message-kind.result { color: var(--app-success); background: var(--app-success-soft); }
.app-message-kind.alert { color: var(--app-danger); background: var(--app-danger-soft); }

.app-message-card > strong {
  display: block;
  margin-top: 9px;
  font-size: 15px;
  line-height: 1.4;
}

.app-message-card p {
  margin: 7px 0 10px;
  color: var(--app-text);
  line-height: 1.65;
  white-space: pre-wrap;
  word-break: break-word;
}

.app-message-card footer {
  flex-wrap: wrap;
}

.app-message-card footer span.danger,
.app-message-card footer span.unread {
  color: var(--app-danger);
  font-weight: 600;
}

.app-message-card footer :deep(.app-button) {
  margin-left: auto;
}

.app-message-load-more {
  display: grid;
  place-items: center;
  padding: 14px 0 4px;
}
</style>
