import { computed, reactive } from 'vue';
import { fetchContext, fetchDataScope, fetchMenus } from '../api/system';

const state = reactive({
  loading: false,
  error: '',
  context: {},
  permissions: [],
  menus: [],
  dataScope: null,
});

export function useMobilePermissions() {
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
      const context = await fetchContext();
      state.context = context;

      if (!context.role_id) {
        state.error = context.auth_error || '';
        state.menus = [];
        state.permissions = [];
        state.dataScope = null;
        return;
      }

      const result = await fetchMenus({ platform: 'h5' });
      state.menus = result.menus || [];
      state.permissions = result.permissions || [];

      if (context.role_type) {
        state.dataScope = await fetchDataScope();
      }
    } catch (error) {
      state.error = error.message;
      state.permissions = [];
      state.menus = [];
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
