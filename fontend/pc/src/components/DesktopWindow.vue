<template>
  <section
    ref="windowRef"
    class="desktop-window"
    :class="{ dragging: interaction?.type === 'drag', resizing: interaction?.type === 'resize' }"
    :style="windowStyle"
    @mousedown.self="emit('focus')"
    @pointerdown.capture="handlePanelEvent"
    @mousedown.capture="handlePanelEvent"
    @click.capture="handlePanelEvent"
  >
    <header class="window-title" @mousedown="startDrag">
      <div class="window-name">
        <component :is="icon" :size="18" />
        <span>{{ title }}</span>
      </div>
      <div class="window-controls">
        <button title="最小化" @mousedown.stop @click.stop="emit('minimize')"><Minus :size="14" /></button>
        <button title="最大化" @mousedown.stop @click.stop="toggleMaximize"><Square :size="13" /></button>
        <button title="关闭" @mousedown.stop @click.stop="emit('close')"><X :size="14" /></button>
      </div>
    </header>

    <main class="window-content">
      <slot />
    </main>

    <span
      v-for="handle in handles"
      :key="handle"
      class="resize-handle"
      :class="handle"
      @mousedown="event => startResize(event, handle)"
    />
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Minus, Square, X } from '@lucide/vue';

const props = defineProps({
  title: { type: String, required: true },
  icon: { type: null, required: true },
  zIndex: { type: Number, default: 1 },
  initialLeft: { type: Number, default: 184 },
  initialTop: { type: Number, default: 70 },
  initialWidth: { type: Number, default: 1120 },
  initialHeight: { type: Number, default: 680 },
});

const emit = defineEmits(['close', 'focus', 'minimize', 'panel']);
const handles = ['n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw'];
const windowRef = ref(null);
const frame = reactive({
  left: props.initialLeft,
  top: props.initialTop,
  width: props.initialWidth,
  height: props.initialHeight,
});
const interaction = ref(null);
const baseMinFrame = { width: 860, height: 560 };
const maximized = ref(false);
const restoreFrame = ref(null);

const windowStyle = computed(() => ({
  left: `${frame.left}px`,
  top: `${frame.top}px`,
  width: `${frame.width}px`,
  height: `${frame.height}px`,
  zIndex: props.zIndex,
}));

function startDrag(event) {
  if (maximized.value || event.button !== 0 || event.target.closest('.window-controls')) {
    return;
  }
  emit('focus');
  startInteraction(event, 'drag');
}

function startResize(event, direction) {
  if (maximized.value || event.button !== 0) {
    return;
  }
  emit('focus');
  startInteraction(event, 'resize', direction);
}

function startInteraction(event, type, direction = '') {
  event.preventDefault();
  const minFrame = effectiveMinFrame();

  interaction.value = {
    type,
    direction,
    startX: event.clientX,
    startY: event.clientY,
    ...frame,
    minWidth: minFrame.width,
    minHeight: minFrame.height,
  };

  window.addEventListener('mousemove', move);
  window.addEventListener('mouseup', stop);
}

function effectiveMinFrame() {
  const parent = windowRef.value?.parentElement;
  if (!parent) {
    return baseMinFrame;
  }

  return {
    width: Math.min(baseMinFrame.width, parent.clientWidth),
    height: Math.min(baseMinFrame.height, parent.clientHeight),
  };
}

function move(event) {
  if (!interaction.value || !windowRef.value?.parentElement) {
    return;
  }

  if (interaction.value.type === 'drag') {
    updateDrag(event);
  } else {
    updateResize(event);
  }
}

function updateDrag(event) {
  const parent = windowRef.value.parentElement;
  const dx = event.clientX - interaction.value.startX;
  const dy = event.clientY - interaction.value.startY;

  frame.left = clamp(interaction.value.left + dx, 0, Math.max(0, parent.clientWidth - frame.width));
  frame.top = clamp(interaction.value.top + dy, 0, Math.max(0, parent.clientHeight - frame.height));
}

function updateResize(event) {
  const parent = windowRef.value.parentElement;
  const direction = interaction.value.direction;
  const dx = event.clientX - interaction.value.startX;
  const dy = event.clientY - interaction.value.startY;

  let left = interaction.value.left;
  let top = interaction.value.top;
  let width = interaction.value.width;
  let height = interaction.value.height;

  if (direction.includes('e')) {
    width = clamp(interaction.value.width + dx, interaction.value.minWidth, parent.clientWidth - interaction.value.left);
  }

  if (direction.includes('s')) {
    height = clamp(interaction.value.height + dy, interaction.value.minHeight, parent.clientHeight - interaction.value.top);
  }

  if (direction.includes('w')) {
    const maxLeft = interaction.value.left + interaction.value.width - interaction.value.minWidth;
    left = clamp(interaction.value.left + dx, 0, maxLeft);
    width = interaction.value.width + interaction.value.left - left;
  }

  if (direction.includes('n')) {
    const maxTop = interaction.value.top + interaction.value.height - interaction.value.minHeight;
    top = clamp(interaction.value.top + dy, 0, maxTop);
    height = interaction.value.height + interaction.value.top - top;
  }

  Object.assign(frame, { left, top, width, height });
}

function stop() {
  interaction.value = null;
  window.removeEventListener('mousemove', move);
  window.removeEventListener('mouseup', stop);
}

function toggleMaximize() {
  const parent = windowRef.value?.parentElement;
  if (!parent) {
    return;
  }

  emit('focus');
  if (!maximized.value) {
    restoreFrame.value = { ...frame };
    Object.assign(frame, {
      left: 0,
      top: 0,
      width: parent.clientWidth,
      height: parent.clientHeight,
    });
    maximized.value = true;
    return;
  }

  Object.assign(frame, restoreFrame.value || {
    left: props.initialLeft,
    top: props.initialTop,
    width: props.initialWidth,
    height: props.initialHeight,
  });
  maximized.value = false;
}

function handlePanelEvent(event) {
  const element = event.target instanceof Element ? event.target : null;
  const target = element?.closest('[data-window-panel]');
  if (!target) {
    return;
  }

  emit('panel', target.dataset.windowPanel);
}

function fitToWorkspace() {
  const parent = windowRef.value?.parentElement;
  if (!parent) {
    return;
  }
  const minFrame = effectiveMinFrame();

  if (maximized.value) {
    Object.assign(frame, {
      left: 0,
      top: 0,
      width: parent.clientWidth,
      height: parent.clientHeight,
    });
    return;
  }

  const maxWidth = Math.max(minFrame.width, parent.clientWidth - frame.left);
  const maxHeight = Math.max(minFrame.height, parent.clientHeight - frame.top);

  frame.width = clamp(frame.width, minFrame.width, maxWidth);
  frame.height = clamp(frame.height, minFrame.height, maxHeight);
  frame.left = clamp(frame.left, 0, Math.max(0, parent.clientWidth - frame.width));
  frame.top = clamp(frame.top, 0, Math.max(0, parent.clientHeight - frame.height));
}

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

onMounted(() => {
  fitToWorkspace();
  window.addEventListener('resize', fitToWorkspace);
});

onBeforeUnmount(() => {
  stop();
  window.removeEventListener('resize', fitToWorkspace);
});
</script>
