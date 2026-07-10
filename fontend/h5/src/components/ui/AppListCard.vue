<template>
  <article
    class="app-list-card"
    :class="{ 'is-clickable': clickable }"
    :tabindex="clickable ? 0 : undefined"
    :role="clickable ? 'button' : undefined"
    @click="handleOpen"
    @keydown.enter.prevent="handleOpen"
    @keydown.space.prevent="handleOpen"
  >
    <header>
      <span v-if="$slots.icon" class="app-list-card-icon"><slot name="icon" /></span>
      <div class="app-list-card-heading">
        <strong>{{ title }}</strong>
        <small v-if="subtitle">{{ subtitle }}</small>
      </div>
      <AppStatusBadge v-if="status || statusLabel" :status="status" :label="statusLabel" />
    </header>
    <div v-if="meta.length || $slots.default" class="app-list-card-body">
      <slot>
        <span v-for="(item, index) in meta" :key="`${item}-${index}`">{{ item }}</span>
      </slot>
    </div>
    <footer v-if="$slots.actions || footerText" @click.stop>
      <small v-if="footerText">{{ footerText }}</small>
      <div v-if="$slots.actions" class="app-list-card-actions"><slot name="actions" /></div>
    </footer>
  </article>
</template>

<script setup>
import AppStatusBadge from './AppStatusBadge.vue';

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  status: { type: [String, Number], default: '' },
  statusLabel: { type: String, default: '' },
  meta: { type: Array, default: () => [] },
  footerText: { type: String, default: '' },
  clickable: { type: Boolean, default: false },
});

const emit = defineEmits(['open']);

function handleOpen() {
  if (props.clickable) {
    emit('open');
  }
}
</script>

<style scoped>
.app-list-card {
  border: 1px solid var(--app-line);
  border-radius: var(--app-radius);
  padding: 12px;
  color: var(--app-text);
  background: var(--app-surface);
  box-shadow: var(--app-shadow-sm);
  transition: border-color var(--app-motion-control) var(--app-ease-standard),
    background var(--app-motion-control) var(--app-ease-standard),
    transform var(--app-motion-fast) var(--app-ease-standard);
}

.app-list-card.is-clickable {
  cursor: pointer;
}

.app-list-card.is-clickable:active {
  border-color: color-mix(in srgb, var(--app-primary) 32%, var(--app-line));
  background: var(--app-surface-muted);
  transform: scale(.99);
}

.app-list-card header {
  display: flex;
  align-items: flex-start;
  gap: 9px;
}

.app-list-card-icon {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  border-radius: var(--app-radius);
  display: grid;
  place-items: center;
  color: var(--app-primary);
  background: var(--app-primary-soft);
}

.app-list-card-heading {
  min-width: 0;
  flex: 1;
  display: grid;
  gap: 3px;
}

.app-list-card-heading strong,
.app-list-card-heading small {
  overflow-wrap: anywhere;
}

.app-list-card-heading strong {
  font-size: 15px;
  line-height: 1.35;
}

.app-list-card-heading small,
.app-list-card footer small {
  color: var(--app-text-secondary);
  font-size: 12px;
  line-height: 1.45;
}

.app-list-card-body {
  display: grid;
  gap: 5px;
  margin-top: 10px;
  color: var(--app-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.app-list-card footer {
  min-height: 38px;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 10px;
  margin: 10px -2px -2px;
  padding-top: 9px;
  border-top: 1px solid color-mix(in srgb, var(--app-line) 72%, transparent);
}

.app-list-card-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
  flex-wrap: wrap;
}
</style>
