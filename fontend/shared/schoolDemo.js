import { reactive } from 'vue';

export const schoolDemo = reactive({ available: false, enabled: false, selection: '', subject: null, routes: {}, permissions: [], error: '' });
let storageKey = '';
let generation = 0;

export async function loadSchoolDemo(context, request, platform) {
  const version = ++generation;
  const operator = ['super_admin', 'school_admin'].includes(context.role_type) && !context.sso_impersonated;
  const nextKey = operator ? ['school-demo', window.__PRACTICAL_DESKTOP__?.serverOrigin || location.origin, platform, context.school_id, context.account_id].join(':') : '';
  if (!nextKey || storageKey !== nextKey) {
    Object.assign(schoolDemo, { available: false, enabled: false, selection: '', subject: null, permissions: [], routes: {}, error: '' });
  }
  storageKey = nextKey;
  if (!storageKey) return null;
  let saved;
  try { saved = JSON.parse(sessionStorage.getItem(storageKey)); } catch { saved = null; }
  if (saved?.selection) Object.assign(schoolDemo, { selection: saved.selection, routes: saved.routes, subject: saved.subject, available: true });
  try {
    const settings = await request('/school-demo/settings');
    if (version !== generation) return null;
    Object.assign(schoolDemo, { available: true, enabled: settings.enabled, routes: settings.routes, error: '' });
    if (!saved?.selection) return null;
    if (!settings.enabled) throw new Error('超级管理员已关闭本校演示，请退出代办。');
    const query = new URLSearchParams({ account_id: saved.subject.id, role_id: saved.subject.role_id, selection: saved.selection, platform });
    const data = await request('/school-demo/workspace?' + query);
    if (version !== generation) return null;
    Object.assign(schoolDemo, { subject: data.subject, permissions: data.permissions, error: '' });
    return data;
  } catch (error) {
    if (version === generation && saved?.selection) schoolDemo.error = error.message;
    return null;
  }
}

export async function selectSchoolDemo(request, platform, id, roleId) {
  const key = storageKey;
  const data = await request('/school-demo/workspace?' + new URLSearchParams({ account_id: id, role_id: roleId, platform }));
  if (!key || key !== storageKey) throw new Error('当前登录账号已变更，请重新选择');
  sessionStorage.setItem(key, JSON.stringify({ selection: data.selection, subject: data.subject, routes: schoolDemo.routes }));
  window.location.reload();
}

export function leaveSchoolDemo() {
  if (storageKey) sessionStorage.removeItem(storageKey);
  window.location.reload();
}

export function schoolDemoHeaders(path) {
  if (!schoolDemo.selection) return {};
  const [, group, action] = String(path).split('?')[0].split('/');
  const allowed = schoolDemo.routes[group];
  if (!allowed || (allowed !== '*' && !allowed.split(' ').includes(action))) return {};
  if (schoolDemo.error) throw new Error(schoolDemo.error);
  return { 'X-School-Demo': schoolDemo.selection };
}

export function mergeDemoMenus(menus, extra) {
  if (!extra?.length) return menus;
  const rows = new Map(menus.map(row => [row.id, row]));
  for (const item of extra) {
    const prior = rows.get(item.id);
    rows.set(item.id, prior ? { ...prior, children: mergeDemoMenus(prior.children || [], item.children) } : item);
  }
  return [...rows.values()];
}

export function businessPermissions(permissions, demo) {
  if (!demo) return permissions;
  return [...permissions.filter(code => !demo.business_prefixes.some(prefix => code.startsWith(prefix))), ...demo.permissions];
}
