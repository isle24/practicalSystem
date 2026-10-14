import { computed, reactive } from 'vue';
import { fetchContext, fetchDataScope, fetchMenus } from '../api/system';

const state = reactive({
  loading: false,
  error: '',
  context: {},
  menus: [],
  permissions: [],
  dataScope: null,
});
let loadVersion = 0;

export function usePermissions() {
  const permissionSet = computed(() => new Set(state.permissions));

  const hasPermission = (code) => {
    if (!code) {
      return true;
    }
    return permissionSet.value.has(code);
  };

  const invalidateLoad = () => {
    loadVersion++;
    state.loading = false;
  };

  const load = async () => {
    const version = ++loadVersion;
    state.loading = true;
    state.error = '';

    try {
      const context = await fetchContext();
      if (version !== loadVersion) return;

      if (!context.role_id) {
        state.context = context;
        state.error = context.auth_error || '';
        state.menus = [];
        state.permissions = [];
        state.dataScope = null;
        return;
      }

      const menuResult = await fetchMenus({ platform: 'pc' });
      if (version !== loadVersion) return;
      const scope = context.role_type ? await fetchDataScope() : null;
      if (version !== loadVersion) return;
      Object.assign(state, {
        context,
        menus: menuResult.menus?.length ? flattenMenus(menuResult.menus) : [],
        permissions: menuResult.permissions || [],
        dataScope: scope,
      });
    } catch (error) {
      if (version !== loadVersion) return;
      state.context = {};
      state.error = error.message;
      state.menus = [];
      state.permissions = [];
      state.dataScope = null;
    } finally {
      if (version === loadVersion) state.loading = false;
    }
  };

  return {
    state,
    hasPermission,
    load,
    invalidateLoad,
  };
}

function flattenMenus(menus) {
  const items = [];
  const walk = (nodes, ancestors = []) => {
    nodes.forEach((node) => {
      items.push({
        ...node,
        root_name: ancestors[0]?.name || node.name,
        parent_name: ancestors.at(-1)?.name || '',
      });
      if (node.children?.length) {
        walk(node.children, [...ancestors, node]);
      }
    });
  };
  walk(menus);
  return items;
}
