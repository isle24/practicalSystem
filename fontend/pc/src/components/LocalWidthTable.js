import { cloneVNode, computed, defineComponent, Fragment, h, inject, isVNode, shallowRef, unref, watch } from 'vue';
import { ElTable, ElTableColumn } from 'element-plus';
import { columnWidthIdentity, readTableWidths, TABLE_WIDTH_SCOPE, validColumnWidth, writeTableWidths } from '../utils/tableWidths';

export default defineComponent({
  name: 'LocalWidthTable',
  inheritAttrs: false,
  props: { widthStorageKey: { type: String, default: '' } },
  setup(props, { attrs, slots, expose }) {
    const table = shallowRef(null);
    const widths = shallowRef({});
    const scope = inject(TABLE_WIDTH_SCOPE, '');
    const storageKey = computed(() => unref(scope) && props.widthStorageKey
      ? `practical:pc:table-widths:v1:${unref(scope)}:${props.widthStorageKey}` : '');
    watch(storageKey, key => { widths.value = readTableWidths(key); }, { immediate: true });

    expose(new Proxy({}, {
      get: (target, key) => Reflect.has(target, key) ? Reflect.get(target, key) : table.value?.[key],
      has: (target, key) => Reflect.has(target, key) || Boolean(table.value && key in table.value),
    }));

    function columns(nodes) {
      return (Array.isArray(nodes) ? nodes : [nodes]).map(node => {
        if (!isVNode(node)) return node;
        if (node.type === Fragment && Array.isArray(node.children)) {
          const result = cloneVNode(node);
          result.children = columns(node.children);
          result.dynamicChildren = null;
          return result;
        }
        if (node.type !== ElTableColumn) return node;
        const key = columnWidthIdentity({ ...node.props, rawColumnKey: node.key });
        const width = widths.value[key];
        const result = cloneVNode(node, validColumnWidth(width) ? { width } : {});
        const children = node.children;
        if (children && typeof children.default === 'function') {
          result.children = { ...children, default: (...args) => columns(children.default(...args)) };
        }
        return result;
      });
    }

    function resized(width, previous, column, event) {
      if (validColumnWidth(width)) {
        widths.value = { ...widths.value, [columnWidthIdentity(column)]: Math.round(width) };
        writeTableWidths(storageKey.value, widths.value);
      }
      const callbacks = Array.isArray(attrs.onHeaderDragend) ? attrs.onHeaderDragend : [attrs.onHeaderDragend];
      for (const callback of callbacks) if (typeof callback === 'function') callback(width, previous, column, event);
    }

    return () => h(ElTable, {
      border: true,
      ...attrs,
      key: storageKey.value || props.widthStorageKey || 'table',
      ref: table,
      class: ['resizable-data-table', attrs.class],
      onHeaderDragend: resized,
    }, { ...slots, default: slots.default ? (...args) => columns(slots.default(...args)) : undefined });
  },
});
