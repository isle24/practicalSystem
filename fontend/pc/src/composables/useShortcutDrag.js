import { onBeforeUnmount, watch } from 'vue';
import Sortable from 'sortablejs';

/** 桌面与启动台共用的本地拖拽排序。 */
export function useShortcutDrag(root, modules, onReorder, page = () => null) {
  let instances = [];
  function dispose() {
    instances.forEach(instance => instance.destroy());
    instances = [];
  }
  watch([root, modules, page], () => {
    dispose();
    if (!root.value) return;
    const grids = root.value.matches('.desktop-icons') ? [root.value] : [...root.value.querySelectorAll('.desktop-launcher-grid')];
    instances = grids.map(grid => new Sortable(grid, {
      draggable: '.shortcut-tile', dataIdAttr: 'data-shortcut-id',
      forceFallback: true, fallbackTolerance: 5, fallbackOnBody: true,
      animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 140,
      filter: '.shortcut-tile-action', preventOnFilter: false,
      ghostClass: 'is-dragging', chosenClass: 'is-drop-target',
      onEnd(event) {
        const sorted = [...grid.querySelectorAll(':scope > .shortcut-tile')].map(item => item.dataset.shortcutId);
        // 将 DOM 还原后交给 Vue 更新，保留其他分组的顺序。
        event.item.remove();
        grid.insertBefore(event.item, grid.children[event.oldIndex] || null);
        if (event.oldIndex === event.newIndex) return;
        const ids = new Set(sorted);
        let index = 0;
        onReorder(modules().map(item => ids.has(String(item.id)) ? sorted[index++] : String(item.id)));
      },
    }));
  }, { immediate: true, flush: 'post' });
  onBeforeUnmount(dispose);
}
