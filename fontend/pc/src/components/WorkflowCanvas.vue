<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ArrowDown, ArrowUp, GripVertical, Minus, Plus, RotateCcw } from '@lucide/vue';
import Sortable from 'sortablejs';

const props = defineProps({
  nodes: { type: Array, default: () => [] },
  selectedKey: { type: String, default: '' },
  roles: { type: Array, default: () => [] },
  issues: { type: Object, default: () => ({}) },
  disabled: Boolean,
});
const emit = defineEmits(['select', 'reorder', 'add']);
const viewport = ref(null), list = ref(null), zoom = ref(1), panning = ref(false), width = ref(0);
const announcement = ref('');
let sortable = null, observer = null, drag = null, mounted = false;
const boundsStyle = computed(() => ({
  width: `${Math.max(width.value, 520 * zoom.value)}px`,
  height: `${(200 + props.nodes.length * 160) * zoom.value}px`,
}));
const flowStyle = computed(() => ({ transform: `translateX(-50%) scale(${zoom.value})` }));

function people(node) {
  if (node.selector.type === 'accounts') return node.selector.account_ids.length ? `已选 ${node.selector.account_ids.length} 个账号` : '待选择人员';
  const role = props.roles.find(item => Number(item.id) === Number(node.selector.role_id));
  return role ? `${role.name} · ${{ entity: '申请所属学院', fixed: '指定学院', school: '全校' }[node.selector.department]}` : '待选择有效角色';
}
function move(index, step) {
  const target = index + step;
  if (props.disabled || target < 0 || target >= props.nodes.length) return;
  emit('reorder', { from: index, to: target });
  announcement.value = `节点已移至第 ${target + 1} 位`;
}
function keyboard(event, index) {
  if (!event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
  event.preventDefault();
  move(index, event.key === 'ArrowUp' ? -1 : 1);
}
async function setZoom(value) {
  const box = viewport.value;
  const previous = zoom.value;
  const top = box ? (box.scrollTop + box.clientHeight / 2) / previous : 0;
  zoom.value = Math.round(Math.min(1.5, Math.max(0.5, value)) * 10) / 10;
  await nextTick();
  if (mounted && box) box.scrollTop = Math.max(0, top * zoom.value - box.clientHeight / 2);
}
async function resetView() {
  zoom.value = 1;
  await nextTick();
  if (mounted && viewport.value) viewport.value.scrollTo({ top: 0, left: Math.max(0, (520 - viewport.value.clientWidth) / 2) });
}
function wheel(event) {
  if (!event.ctrlKey && !event.metaKey) return;
  event.preventDefault();
  setZoom(zoom.value + (event.deltaY < 0 ? 0.1 : -0.1));
}
function beginPan(event) {
  if (event.button !== 0 || event.pointerType !== 'mouse' || event.target.closest('button, .workflow-canvas-node')) return;
  const box = viewport.value;
  drag = { id: event.pointerId, x: event.clientX, y: event.clientY, left: box.scrollLeft, top: box.scrollTop };
  box.setPointerCapture(event.pointerId);
  panning.value = true;
  event.preventDefault();
}
function pan(event) {
  if (!drag || drag.id !== event.pointerId) return;
  viewport.value.scrollLeft = drag.left + drag.x - event.clientX;
  viewport.value.scrollTop = drag.top + drag.y - event.clientY;
}
function endPan(event) {
  if (!drag || (event && drag.id !== event.pointerId)) return;
  if (viewport.value?.hasPointerCapture(drag.id)) viewport.value.releasePointerCapture(drag.id);
  drag = null;
  panning.value = false;
}
watch(() => props.disabled, value => sortable?.option('disabled', value));
onMounted(() => {
  mounted = true;
  observer = new ResizeObserver(entries => { width.value = entries[0].contentRect.width; });
  observer.observe(viewport.value);
  sortable = Sortable.create(list.value, {
    animation: 120, handle: '.workflow-drag-handle', draggable: '.workflow-canvas-node',
    disabled: props.disabled, ghostClass: 'workflow-node-ghost', chosenClass: 'workflow-node-chosen',
    onStart(event) { emit('select', event.item.dataset.nodeKey); },
    onEnd(event) {
      if (!mounted) return;
      const { oldIndex, newIndex, item, from } = event;
      if (oldIndex === newIndex || !Number.isInteger(oldIndex) || !Number.isInteger(newIndex)) return;
      from.removeChild(item);
      from.insertBefore(item, from.children[oldIndex] || null);
      if (props.disabled) return;
      emit('reorder', { from: oldIndex, to: newIndex });
      announcement.value = `节点已移至第 ${newIndex + 1} 位，保存后按此顺序执行`;
    },
  });
});
onBeforeUnmount(() => { mounted = false; endPan(); observer?.disconnect(); sortable?.destroy(); });
</script>

<template>
  <section class="workflow-canvas" aria-label="工作流顺序画布">
    <header class="workflow-canvas-toolbar">
      <div class="workflow-canvas-add">
        <el-button :disabled="disabled || nodes.length >= 100" :icon="Plus" size="small" @click="emit('add', 'review')">审批</el-button>
        <el-button :disabled="disabled || nodes.length >= 100" :icon="Plus" size="small" @click="emit('add', 'cc')">抄送</el-button>
      </div>
      <div class="workflow-canvas-zoom" aria-label="画布缩放">
        <el-button :icon="Minus" size="small" :disabled="zoom <= 0.5" aria-label="缩小画布" title="缩小" @click="setZoom(zoom - 0.1)" />
        <span>{{ Math.round(zoom * 100) }}%</span>
        <el-button :icon="Plus" size="small" :disabled="zoom >= 1.5" aria-label="放大画布" title="放大" @click="setZoom(zoom + 0.1)" />
        <el-button :icon="RotateCcw" size="small" aria-label="重置画布视图" title="回到流程起点" @click="resetView" />
      </div>
    </header>
    <p class="workflow-canvas-hint">拖动节点手柄调整执行顺序；拖动空白处平移，也可滚动浏览。</p>
    <div ref="viewport" class="workflow-canvas-viewport" :class="{ panning }" tabindex="0" aria-label="流程画布，可滚动浏览" @wheel="wheel" @pointerdown="beginPan" @pointermove="pan" @pointerup="endPan" @pointercancel="endPan" @lostpointercapture="endPan">
      <div class="workflow-canvas-bounds" :style="boundsStyle">
        <div class="workflow-canvas-flow" :style="flowStyle">
          <div class="workflow-terminus workflow-start">开始<span>提交申请</span></div>
          <div ref="list" class="workflow-node-sequence" role="list" aria-label="按顺序执行的流程节点">
            <article v-for="(node, index) in nodes" :key="node._key" class="workflow-canvas-node" :class="{ selected: node._key === selectedKey, incomplete: issues[node._key] }" :data-node-key="node._key" role="listitem" @keydown="keyboard($event, index)">
              <header>
                <span class="workflow-node-position">{{ String(index + 1).padStart(2, '0') }}</span>
                <span>{{ node.kind === 'cc' ? '抄送' : '审批' }}</span>
                <button class="workflow-drag-handle" type="button" :disabled="disabled" :aria-label="`拖动调整第 ${index + 1} 个节点顺序，或按 Alt 加上下方向键`" title="拖动调整顺序 · Alt + ↑ / ↓"><GripVertical :size="16" /></button>
              </header>
              <button type="button" class="workflow-node-content" :aria-pressed="selectedKey === node._key" :disabled="disabled" @click="emit('select', node._key)">
                <strong :title="node.name">{{ node.name || '未命名节点' }}</strong>
                <span>{{ people(node) }}</span>
                <small>{{ node.kind === 'review' ? (node.mode === 'all' ? '会签 · 全部通过' : '或签 · 任一通过') : '抄送后继续' }}{{ node.kind === 'review' && node.signature_required ? ' · 必须签名' : '' }}</small>
              </button>
              <div class="workflow-node-move">
                <button type="button" :disabled="disabled || index === 0" :aria-label="`上移节点 ${node.name || index + 1}`" title="上移" @click="move(index, -1)"><ArrowUp :size="13" /></button>
                <button type="button" :disabled="disabled || index === nodes.length - 1" :aria-label="`下移节点 ${node.name || index + 1}`" title="下移" @click="move(index, 1)"><ArrowDown :size="13" /></button>
              </div>
            </article>
          </div>
          <div class="workflow-terminus">结束<span>完成流程</span></div>
        </div>
      </div>
    </div>
    <span class="workflow-sr-only" aria-live="polite">{{ announcement }}</span>
  </section>
</template>

<style scoped>
.workflow-canvas { display: flex; flex-direction: column; min-width: 0; min-height: 0; background: var(--el-fill-color-lighter); }
.workflow-canvas-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 14px 6px; }
.workflow-canvas-add,.workflow-canvas-zoom { display: flex; align-items: center; gap: 6px; }
.workflow-canvas .el-button + .el-button { margin-left: 0; }
.workflow-canvas-zoom span { width: 38px; text-align: center; font-size: 12px; font-variant-numeric: tabular-nums; }
.workflow-canvas-zoom .el-button { padding: 5px 7px; }
.workflow-canvas-hint { margin: 0; padding: 2px 14px 10px; color: var(--muted); font-size: 12px; line-height: 1.5; }
.workflow-canvas-viewport { position: relative; flex: 1; min-height: 0; overflow: auto; overscroll-behavior: contain; cursor: grab; }
.workflow-canvas-viewport.panning { cursor: grabbing; user-select: none; }
.workflow-canvas-viewport:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: -2px; }
.workflow-canvas-bounds { position: relative; min-width: 100%; }
.workflow-canvas-flow { position: absolute; left: 50%; top: 24px; width: 520px; transform-origin: top center; display: flex; align-items: center; flex-direction: column; }
.workflow-terminus { height: 36px; min-width: 150px; display: flex; align-items: center; justify-content: center; gap: 8px; border: 1px solid var(--line); border-radius: 20px; background: var(--el-bg-color); font-size: 13px; font-weight: 600; box-sizing: border-box; }
.workflow-terminus span { font-size: 12px; font-weight: 400; color: var(--muted); }
.workflow-start { position: relative; margin-bottom: 44px; }
.workflow-start::after,.workflow-canvas-node::after { content: ''; position: absolute; left: 50%; top: 100%; width: 1px; height: 44px; background: var(--el-border-color-darker); }
.workflow-canvas-node::before { content: ''; position: absolute; left: calc(50% - 4px); bottom: -43px; width: 7px; height: 7px; border-right: 1px solid var(--el-border-color-darker); border-bottom: 1px solid var(--el-border-color-darker); transform: rotate(45deg); }
.workflow-node-sequence { width: 288px; }
.workflow-canvas-node { position: relative; height: 116px; margin-bottom: 44px; border: 1px solid var(--line); border-radius: 8px; background: var(--el-bg-color); box-sizing: border-box; cursor: default; }
.workflow-canvas-node.selected { border-color: var(--el-color-primary); outline: 1px solid var(--el-color-primary); }
.workflow-canvas-node.incomplete header { color: var(--el-color-warning-dark-2); }
.workflow-canvas-node header { display: flex; height: 30px; align-items: center; gap: 8px; padding: 0 10px; border-bottom: 1px solid var(--line); font-size: 12px; color: var(--muted); }
.workflow-node-position { font-variant-numeric: tabular-nums; }
.workflow-drag-handle { margin-left: auto; display: grid; place-items: center; padding: 4px; background: transparent; border: 0; color: inherit; cursor: grab; touch-action: none; }
.workflow-node-content { display: flex; flex-direction: column; width: 100%; height: 84px; gap: 4px; align-items: flex-start; text-align: left; border: 0; padding: 8px 46px 8px 12px; background: transparent; color: var(--text); cursor: pointer; font-family: inherit; }
.workflow-node-content strong { font-size: 14px; line-height: 20px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.workflow-node-content span,.workflow-node-content small { font-size: 12px; line-height: 17px; color: var(--muted); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.workflow-node-move { position: absolute; right: 8px; top: 42px; display: flex; flex-direction: column; gap: 3px; }
.workflow-node-move button { display: grid; place-items: center; width: 24px; height: 23px; border: 1px solid var(--line); border-radius: 4px; background: var(--el-bg-color); color: var(--muted); cursor: pointer; }
button:disabled { opacity: .5; cursor: not-allowed; }
button:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 2px; }
.workflow-node-ghost { opacity: .35; }
.workflow-node-chosen { border-color: var(--el-color-primary); }
.workflow-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
</style>
