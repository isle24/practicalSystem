import { computed, onBeforeUnmount, ref, watch } from 'vue';

// 坐标只保存在当前学校、账号的本地存储。
export function useDesktopPositions({ modules, storageKey, bounds, cell, notify }) {
  const saved = ref({});
  const drag = ref(null);
  let suppressClickUntil = 0;
  let captureTarget = null;

  function readPositions() {
    try {
      const data = JSON.parse(localStorage.getItem(storageKey()) || '{}');
      const entries = Object.entries(data.version === 1 ? data.positions || {} : {});
      saved.value = Object.fromEntries(entries.filter(([, p]) => p
        && Number.isFinite(p.x) && Number.isFinite(p.y) && p.x >= 0 && p.y >= 0));
    } catch {
      saved.value = {};
    }
  }

  function persist() {
    try {
      localStorage.setItem(storageKey(), JSON.stringify({ version: 1, positions: saved.value }));
    } catch {
      notify('无法保存桌面位置，当前布局将在关闭后丢失');
    }
  }

  function clamp(point) {
    return {
      x: Math.round(Math.max(0, Math.min(point.x, bounds.value.width - cell.value.width))),
      y: Math.round(Math.max(0, Math.min(point.y, bounds.value.height - cell.value.height))),
    };
  }

  function overlaps(point, occupied) {
    return occupied.some(other => Math.abs(point.x - other.x) < cell.value.width + 4
      && Math.abs(point.y - other.y) < cell.value.height + 4);
  }

  // 首选精确落点；重叠时在已占区域边缘寻找最近空位。
  function vacant(point, occupied) {
    const desired = clamp(point);
    if (!overlaps(desired, occupied)) return desired;
    const xs = new Set([0, desired.x, Math.max(0, bounds.value.width - cell.value.width)]);
    const ys = new Set([0, desired.y, Math.max(0, bounds.value.height - cell.value.height)]);
    occupied.forEach(other => {
      xs.add(clamp({ x: other.x + cell.value.width + 4, y: 0 }).x);
      xs.add(clamp({ x: other.x - cell.value.width - 4, y: 0 }).x);
      ys.add(clamp({ x: 0, y: other.y + cell.value.height + 4 }).y);
      ys.add(clamp({ x: 0, y: other.y - cell.value.height - 4 }).y);
    });
    let nearest = null;
    let distance = Infinity;
    for (const x of xs) {
      for (const y of ys) {
        const candidate = { x, y };
        const delta = (x - desired.x) ** 2 + (y - desired.y) ** 2;
        if (delta < distance && !overlaps(candidate, occupied)) {
          nearest = candidate;
          distance = delta;
        }
      }
    }
    return nearest;
  }

  const positions = computed(() => {
    const result = {};
    const occupied = [];
    const rows = Math.max(1, Math.floor((bounds.value.height + 8) / (cell.value.height + 8)));
    const source = modules();
    const indexes = new Map(source.map((item, index) => [item.id, index]));
    const items = [...source].sort((a, b) => Number(Boolean(saved.value[b.id])) - Number(Boolean(saved.value[a.id])));
    items.forEach((item) => {
      const index = indexes.get(item.id);
      const fallback = { x: Math.floor(index / rows) * (cell.value.width + 12), y: (index % rows) * (cell.value.height + 8) };
      const point = vacant(saved.value[item.id] || fallback, occupied) || clamp(fallback);
      result[item.id] = point;
      occupied.push(point);
    });
    return result;
  });

  function tileStyle(id) {
    const point = drag.value?.id === String(id) && drag.value.moved ? drag.value.point : positions.value[id];
    return { transform: `translate3d(${point?.x || 0}px, ${point?.y || 0}px, 0)` };
  }

  function start(event) {
    if (event.button !== 0 || !event.isPrimary) return;
    const tile = event.target.closest('[data-shortcut-id]');
    if (!tile || event.target.closest('.shortcut-tile-action')) return;
    const id = tile.dataset.shortcutId;
    const origin = positions.value[id];
    if (!origin) return;
    drag.value = { id, pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, origin, point: origin, moved: false };
    captureTarget = tile;
    window.addEventListener('pointermove', move, { passive: false });
    window.addEventListener('pointerup', finish);
    window.addEventListener('pointercancel', cancel);
    window.addEventListener('blur', cancel);
    window.addEventListener('keydown', onKeydown);
  }

  function move(event) {
    const current = drag.value;
    if (!current || current.pointerId !== event.pointerId) return;
    const dx = event.clientX - current.startX;
    const dy = event.clientY - current.startY;
    if (!current.moved && Math.hypot(dx, dy) < 5) return;
    event.preventDefault();
    if (!current.moved) captureTarget?.setPointerCapture(event.pointerId);
    current.moved = true;
    current.point = clamp({ x: current.origin.x + dx, y: current.origin.y + dy });
  }

  function finish(event) {
    const current = drag.value;
    if (!current || current.pointerId !== event.pointerId) return;
    if (current.moved) {
      const occupied = Object.entries(positions.value).filter(([id]) => id !== current.id).map(([, point]) => point);
      const point = vacant(current.point, occupied);
      if (point) {
        saved.value = { ...saved.value, [current.id]: point };
        persist();
      } else {
        notify('当前桌面没有足够空位，已保留原位置');
      }
    }
    cancel();
  }

  function cancel() {
    if (drag.value?.moved) suppressClickUntil = Date.now() + 300;
    if (drag.value && captureTarget?.hasPointerCapture(drag.value.pointerId)) captureTarget.releasePointerCapture(drag.value.pointerId);
    drag.value = null;
    captureTarget = null;
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', finish);
    window.removeEventListener('pointercancel', cancel);
    window.removeEventListener('blur', cancel);
    window.removeEventListener('keydown', onKeydown);
  }

  function onKeydown(event) {
    if (event.key === 'Escape') cancel();
  }

  function captureClick(event) {
    if (Date.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }

  function reset() {
    cancel();
    saved.value = {};
    persist();
  }

  watch(storageKey, () => { cancel(); readPositions(); }, { immediate: true, flush: 'sync' });
  watch(() => modules().map(item => item.id).join('|'), cancel);
  onBeforeUnmount(cancel);
  return { drag, tileStyle, start, captureClick, reset };
}
