<template>
  <nav ref="gridRef" class="desktop-icons" :style="layoutStyle" aria-label="应用模块">
    <ShortcutTile
      v-for="module in modules"
      :key="module.id"
      :item="module"
      mode="desktop"
      :href="moduleHref(module)"
      :active="isFocused(module.id)"
      :backend-url="backendUrl"
      @open="emit('open', module)"
    />
  </nav>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ShortcutTile from './ShortcutTile.vue';
import { useShortcutDrag } from '../composables/useShortcutDrag';

const props = defineProps({
  modules: { type: Array, default: () => [] },
  moduleHref: { type: Function, required: true },
  isFocused: { type: Function, required: true },
  backendUrl: { type: Function, required: true },
});

const emit = defineEmits(['open', 'reorder']);
const gridRef = ref(null);
useShortcutDrag(gridRef, () => props.modules, ids => emit('reorder', ids));
const bounds = ref({ width: 0, height: 0 });
let observer = null;

const layoutStyle = computed(() => {
  const count = Math.max(1, props.modules.length);
  const width = Math.max(1, bounds.value.width);
  const height = Math.max(1, bounds.value.height);
  const naturalWidth = 92;
  const naturalHeight = 104;
  const gapX = 12;
  const gapY = 8;
  let best = { columns: 1, rows: count, scale: 0 };

  for (let columns = 1; columns <= count; columns += 1) {
    const rows = Math.ceil(count / columns);
    const widthScale = (width - gapX * (columns - 1)) / (naturalWidth * columns);
    const heightScale = (height - gapY * (rows - 1)) / (naturalHeight * rows);
    const scale = Math.min(1, widthScale, heightScale);
    const isBetterScale = scale > best.scale + 0.005;
    const isBetterShape = Math.abs(scale - best.scale) <= 0.005
      && Math.abs(columns - Math.min(3, count)) < Math.abs(best.columns - Math.min(3, count));
    if (isBetterScale || isBetterShape) {
      best = { columns, rows, scale };
    }
  }

  const scale = Math.max(0.68, Math.min(1, best.scale));
  return {
    '--desktop-columns': best.columns,
    '--desktop-cell-width': `${Math.round(naturalWidth * scale)}px`,
    '--desktop-cell-height': `${Math.round(naturalHeight * scale)}px`,
    '--desktop-icon-box': `${Math.round(48 * scale)}px`,
    '--desktop-icon-image': `${Math.round(30 * scale)}px`,
    '--desktop-icon-gap': `${Math.max(4, Math.round(7 * scale))}px`,
    '--desktop-gap-x': `${Math.max(6, Math.round(gapX * scale))}px`,
    '--desktop-gap-y': `${Math.max(4, Math.round(gapY * scale))}px`,
    '--desktop-tile-padding-x': `${Math.max(3, Math.round(6 * scale))}px`,
    '--desktop-tile-padding-top': `${Math.max(4, Math.round(10 * scale))}px`,
  };
});

function updateBounds(entry = null) {
  const rect = entry?.contentRect || gridRef.value?.getBoundingClientRect();
  if (!rect) {
    return;
  }
  bounds.value = { width: rect.width, height: rect.height };
}

onMounted(() => {
  updateBounds();
  observer = new ResizeObserver(entries => updateBounds(entries[0]));
  observer.observe(gridRef.value);
});

onBeforeUnmount(() => observer?.disconnect());

</script>
