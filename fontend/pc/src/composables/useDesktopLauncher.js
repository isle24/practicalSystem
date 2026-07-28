import { computed, reactive } from 'vue';
import { Globe2 } from '@lucide/vue';

export const DEFAULT_DESKTOP_MODULE_IDS = ['internship', 'practice', 'config'];

export function useDesktopLauncher(options) {
  const state = reactive({
    visible: false,
    keyword: '',
    items: [],
    loading: false,
    message: '',
  });

  const defaultModuleIds = options.defaultModuleIds || DEFAULT_DESKTOP_MODULE_IDS;
  const defaultModuleIdSet = new Set(defaultModuleIds);

  const customShortcutItems = computed(() => state.items.filter(item => item?.type === 'module' || item?.type === 'favorite'));
  const launchableModuleIds = computed(() => new Set(options.allModules.value
    .filter(module => module?.type !== 'favoriteLink')
    .map(module => module.id)));
  const customModuleKeys = computed(() => customShortcutItems.value
    .filter(item => item.type === 'module')
    .map(item => String(item.key || item.item_key || ''))
    .filter(key => key && !defaultModuleIdSet.has(key) && launchableModuleIds.value.has(key)));
  const moduleShortcutKeys = computed(() => {
    const defaults = defaultModuleIds.filter(key => launchableModuleIds.value.has(key));
    return Array.from(new Set([...defaults, ...customModuleKeys.value]));
  });
  const favoriteDesktopShortcuts = computed(() => customShortcutItems.value
    .filter(item => item.type === 'favorite' && item.favorite?.url)
    .map(item => favoriteShortcutFromStoredItem(item)));
  const visibleDesktopModules = computed(() => {
    const shortcutIds = new Set(moduleShortcutKeys.value);
    const desktopModules = options.allModules.value.filter(module => shortcutIds.has(module.id));
    return [...desktopModules, ...favoriteDesktopShortcuts.value];
  });
  const favoriteLauncherShortcuts = computed(() => {
    const rows = new Map();
    favoriteDesktopShortcuts.value.forEach((item) => {
      if (item.favoriteId) {
        rows.set(Number(item.favoriteId), item);
      }
    });
    (options.favoriteItems.value || []).forEach((item) => {
      if (!item?.id || !item.url || rows.has(Number(item.id))) {
        return;
      }
      rows.set(Number(item.id), favoriteShortcutFromFavorite(item));
    });
    return Array.from(rows.values());
  });
  const launcherModules = computed(() => [...options.allModules.value, ...favoriteLauncherShortcuts.value]);
  const filteredModules = computed(() => {
    const value = state.keyword.trim().toLowerCase();
    if (!value) {
      return launcherModules.value;
    }
    return launcherModules.value.filter(module => options.searchText(module).includes(value));
  });
  const payloadItems = computed(() => [
    ...customModuleKeys.value.map(key => ({ type: 'module', key })),
    ...customShortcutItems.value
      .filter(item => item.type === 'favorite' && Number(item.ref_id || 0) > 0)
      .map(item => ({ type: 'favorite', ref_id: Number(item.ref_id) })),
  ]);

  function isShortcut(moduleId) {
    return moduleShortcutKeys.value.includes(moduleId);
  }

  function isDefaultShortcut(moduleId) {
    return defaultModuleIdSet.has(moduleId);
  }

  function shortcutTitle(moduleId) {
    if (isDefaultShortcut(moduleId)) {
      return '默认固定模块，不能移除';
    }
    return isShortcut(moduleId) ? '已添加到桌面' : '添加到桌面';
  }

  function normalizeShortcutItems(items) {
    return (Array.isArray(items) ? items : [])
      .map(item => ({
        type: item.type || item.item_type || 'module',
        key: item.key || item.item_key || '',
        ref_id: item.ref_id === null || item.ref_id === undefined ? null : Number(item.ref_id),
        favorite: item.favorite || null,
      }))
      .filter(item => (item.type === 'module' && item.key) || (item.type === 'favorite' && item.ref_id));
  }

  function setItems(items) {
    state.items = normalizeShortcutItems(items);
  }

  function reset() {
    state.items = [];
    state.message = '';
  }

  function favoriteShortcutFromStoredItem(item) {
    return favoriteShortcutFromFavorite({
      id: item.ref_id,
      title: item.favorite.title,
      url: item.favorite.url,
      icon_url: item.favorite.icon_url,
    });
  }

  function favoriteShortcutFromFavorite(item) {
    return {
      id: `favorite-link-${item.id}`,
      favoriteId: Number(item.id),
      name: item.title || '收藏网址',
      icon: Globe2,
      iconUrl: item.icon_url || '',
      color: 'blue',
      scope: item.url,
      url: item.url,
      type: 'favoriteLink',
    };
  }

  return {
    state,
    customShortcutItems,
    launchableModuleIds,
    moduleShortcutKeys,
    favoriteDesktopShortcuts,
    visibleDesktopModules,
    launcherModules,
    filteredModules,
    payloadItems,
    isShortcut,
    isDefaultShortcut,
    shortcutTitle,
    normalizeShortcutItems,
    setItems,
    reset,
  };
}
