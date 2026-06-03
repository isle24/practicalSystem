import { computed, reactive } from 'vue';
import { fetchContext, fetchDataScope, fetchMenus, fetchSchool } from '../api/system';

const state = reactive({
  loading: false,
  error: '',
  context: {},
  school: {},
  menus: [],
  permissions: [],
  dataScope: null,
});

export function usePermissions() {
  const permissionSet = computed(() => new Set(state.permissions));

  const hasPermission = (code) => {
    if (!code) {
      return true;
    }
    return permissionSet.value.has(code);
  };

  const load = async () => {
    state.loading = true;
    state.error = '';

    try {
      const [context, school] = await Promise.all([fetchContext(), fetchSchool()]);
      state.context = context;
      state.school = school;

      if (!context.role_id) {
        state.error = context.auth_error || '';
        state.menus = [];
        state.permissions = [];
        state.dataScope = null;
        return;
      }

      const menuResult = await fetchMenus({ platform: 'pc' });
      state.menus = menuResult.menus?.length ? flattenMenus(menuResult.menus) : [];
      state.permissions = menuResult.permissions || [];

      if (context.role_type) {
        state.dataScope = await fetchDataScope();
      }
    } catch (error) {
      state.error = error.message;
      state.menus = [];
      state.permissions = [];
      state.dataScope = null;
    } finally {
      state.loading = false;
    }
  };

  return {
    state,
    hasPermission,
    load,
  };
}

function flattenMenus(menus) {
  const items = [];
  const walk = (nodes) => {
    nodes.forEach((node) => {
      if (node.type === 'menu' || !node.children?.length) {
        items.push(node);
      }
      if (node.children?.length) {
        walk(node.children);
      }
    });
  };
  walk(menus);
  return items;
}
