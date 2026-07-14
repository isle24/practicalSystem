import { LayoutGrid } from '@lucide/vue';

export function menuLaunchKey(menu, builtIn = null) {
  const explicit = String(menu?.module_key || '').trim();
  if (explicit) {
    return explicit;
  }
  if (builtIn?.id) {
    return String(builtIn.id);
  }
  return `menu-${menu?.id || Date.now()}`;
}

export function buildLaunchableMenuModules(menus = [], builtInModules = [], resolveIcon = defaultIcon) {
  return (menus || [])
    .filter(menu => menu?.is_module === 'true' && menu.visible !== 'false' && ['pc', 'both'].includes(menu.platform || 'both'))
    .map((menu) => {
      const explicitKey = String(menu?.module_key || '').trim();
      const builtIn = builtInModules.find(item => item.id === explicitKey || item.id === menu.path || item.id === menu.code);
      const moduleId = menuLaunchKey(menu, builtIn);
      const isBuiltIn = Boolean(builtIn);
      return {
        ...(builtIn || {}),
        id: moduleId,
        name: menu.name || builtIn?.name || '菜单模块',
        icon: resolveIcon(menu.icon || builtIn?.icon),
        iconUrl: menu.icon_url || builtIn?.iconUrl || '',
        color: builtIn?.color || 'blue',
        scope: menu.path || menu.url || menu.code || builtIn?.scope || '菜单模块',
        url: menu.url || builtIn?.url || '',
        viewPermission: menu.code || builtIn?.viewPermission || '',
        managePermission: builtIn?.managePermission || '',
        defaultPanel: isBuiltIn ? (builtIn.defaultPanel || 'overview') : 'menuModule',
        source: isBuiltIn ? builtIn.source : 'menu',
        menu,
        menuId: menu.id,
      };
    });
}

export function mergeLaunchableModules(baseModules, menuModules) {
  const rows = new Map();
  baseModules.forEach(module => rows.set(module.id, module));
  menuModules.forEach((module) => {
    const current = rows.get(module.id) || {};
    rows.set(module.id, {
      ...current,
      ...module,
      icon: module.icon || current.icon || LayoutGrid,
      iconUrl: module.iconUrl || current.iconUrl || '',
    });
  });
  return Array.from(rows.values());
}

function defaultIcon() {
  return LayoutGrid;
}
