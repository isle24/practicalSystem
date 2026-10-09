<template>
  <Teleport to="body">
    <div ref="maskRef" v-if="visible" :class="{ 'operation-mask-maximized': maximized }" class="operation-mask" @click.self="requestClose">
      <section
        ref="dialogRef"
        class="operation-dialog operation-dialog-managed"
        :class="[dialogClass, { 'is-maximized': maximized, 'is-resizing': resizing }]"
        :style="dialogStyle"
        role="dialog"
        aria-modal="true"
        tabindex="-1"
        :aria-label="title"
        @keydown="handleKeydown"
      >
        <header>
          <strong>{{ title }}</strong>
          <div class="operation-dialog-controls">
            <button v-if="resizable" type="button" title="放大" aria-label="放大" :disabled="maximized" @click="enlarge">
              <ZoomIn :size="16" />
            </button>
            <button
              v-if="maximizable"
              type="button"
              :title="maximized ? '还原' : '最大化'"
              :aria-label="maximized ? '还原' : '最大化'"
              @click="toggleMaximize"
            >
              <Minimize2 v-if="maximized" :size="16" />
              <Maximize2 v-else :size="16" />
            </button>
            <button
              type="button"
              class="operation-dialog-close"
              title="关闭"
              aria-label="关闭"
              :disabled="busy"
              @click="requestClose"
            >
              <X :size="17" />
            </button>
          </div>
        </header>
        <slot />
        <footer v-if="$slots.footer">
          <slot name="footer" />
        </footer>
        <span v-if="resizable && !maximized" class="operation-dialog-resize-handle e" @pointerdown="event => startResize(event, 'e')" />
        <span v-if="resizable && !maximized" class="operation-dialog-resize-handle s" @pointerdown="event => startResize(event, 's')" />
        <span v-if="resizable && !maximized" class="operation-dialog-resize-handle se" @pointerdown="event => startResize(event, 'se')" />
      </section>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Maximize2, Minimize2, X, ZoomIn } from '@lucide/vue';
import { useMaximizedWindow } from '../composables/useMaximizedWindows';

const props = defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: '' },
  dialogClass: { type: [String, Array, Object], default: '' },
  busy: { type: Boolean, default: false },
  resizable: { type: Boolean, default: true },
  maximizable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);
const dialogRef = ref(null);
const maskRef = ref(null);
let previousFocus = null;
let maskObserver;
const maximized = ref(false);
const registration = useMaximizedWindow();
watch(() => [props.visible, maximized.value], ([visible, full]) => {
  if (visible && full) registration.register();
  else registration.unregister();
}, { flush: 'sync' });
const resizing = ref(false);
const customSize = ref(null);
let resizeState = null;

const dialogStyle = computed(() => customSize.value && !maximized.value
  ? { width: `${customSize.value.width}px`, height: `${customSize.value.height}px`, maxHeight: 'none' }
  : {});

watch(
  () => props.visible,
  async visible => {
    maskObserver?.disconnect();
    window.removeEventListener('resize', clampSize);
    if (!visible) {
      stopResize();
      maximized.value = false;
      customSize.value = null;
      await nextTick();
      const active = document.activeElement;
      if ((!active || active === document.body || !active.isConnected) && previousFocus?.isConnected) previousFocus.focus();
      previousFocus = null;
      return;
    }
    previousFocus = document.activeElement;
    await nextTick();
    dialogRef.value?.focus();
    clampSize();
    window.addEventListener('resize', clampSize);
    maskObserver = new ResizeObserver(clampSize);
    if (maskRef.value) maskObserver.observe(maskRef.value);
  },
  { immediate: true },
);

function handleKeydown(event) {
  if (event.key === 'Escape') { event.stopPropagation(); requestClose(); }
  if (event.key !== 'Tab') return;
  const nodes = [...dialogRef.value.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]')].filter(node => node.getClientRects().length);
  const first = nodes[0], last = nodes.at(-1);
  if (!first) { event.preventDefault(); return; }
  if (event.shiftKey && (document.activeElement === first || document.activeElement === dialogRef.value)) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialogRef.value)) { event.preventDefault(); first.focus(); }
}

/** 请求关闭弹窗 */
function requestClose() {
  if (!props.busy) {
    emit('close');
  }
}

/** 按固定步长放大弹窗 */
function enlarge() {
  const element = dialogRef.value;
  if (!element || maximized.value) {
    return;
  }
  const limits = sizeLimits();
  customSize.value = {
    width: Math.min(limits.maxWidth, element.offsetWidth + 120),
    height: Math.min(limits.maxHeight, element.offsetHeight + 80),
  };
}

/** 切换最大化状态 */
function toggleMaximize() {
  maximized.value = !maximized.value;
}

/** 开始拖动调整弹窗尺寸 */
function startResize(event, direction) {
  if (event.button !== 0 || !dialogRef.value) {
    return;
  }
  event.preventDefault();
  resizeState = {
    direction,
    startX: event.clientX,
    startY: event.clientY,
    width: dialogRef.value.offsetWidth,
    height: dialogRef.value.offsetHeight,
  };
  resizing.value = true;
  window.addEventListener('pointermove', resizeDialog);
  window.addEventListener('pointerup', stopResize);
}

/** 更新弹窗尺寸 */
function resizeDialog(event) {
  if (!resizeState) {
    return;
  }
  const limits = sizeLimits();
  const width = resizeState.direction.includes('e')
    ? clamp(resizeState.width + event.clientX - resizeState.startX, limits.minWidth, limits.maxWidth)
    : resizeState.width;
  const height = resizeState.direction.includes('s')
    ? clamp(resizeState.height + event.clientY - resizeState.startY, limits.minHeight, limits.maxHeight)
    : resizeState.height;
  customSize.value = { width, height };
}

/** 结束弹窗尺寸调整 */
function stopResize() {
  resizeState = null;
  resizing.value = false;
  window.removeEventListener('pointermove', resizeDialog);
  window.removeEventListener('pointerup', stopResize);
}

/** 限制已保存的弹窗尺寸 */
function clampSize() {
  if (!customSize.value) {
    return;
  }
  const limits = sizeLimits();
  customSize.value = {
    width: clamp(customSize.value.width, limits.minWidth, limits.maxWidth),
    height: clamp(customSize.value.height, limits.minHeight, limits.maxHeight),
  };
}

/** 返回当前视口允许的弹窗尺寸 */
function sizeLimits() {
  const mask = maskRef.value;
  const style = mask ? getComputedStyle(mask) : null;
  const horizontal = style ? parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) : 48;
  const vertical = style ? parseFloat(style.paddingTop) + parseFloat(style.paddingBottom) : 122;
  const maxWidth = Math.max(0, (mask?.clientWidth || window.innerWidth) - horizontal);
  const maxHeight = Math.max(0, (mask?.clientHeight || window.innerHeight) - vertical);
  return { minWidth: Math.min(420, maxWidth), minHeight: Math.min(320, maxHeight), maxWidth, maxHeight };
}

/** 限制数值范围 */
function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

onBeforeUnmount(() => {
  maskObserver?.disconnect();
  stopResize();
  window.removeEventListener('resize', clampSize);
});
</script>
