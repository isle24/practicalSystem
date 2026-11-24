import { computed, onBeforeUnmount, provide, ref, watch } from 'vue';

export const DEFAULT_UI_STYLE = 'liquid_glass';
export const FOLLOW_SCHOOL = 'school_default';
export const MENU_ICON_STYLE = Symbol('menu-icon-style');
export const DEFAULT_MENU_ICON_STYLE = 'classic';
export const menuIconStyles = [
  { key: DEFAULT_MENU_ICON_STYLE, name: '经典彩底' },
  { key: 'emoji', name: 'Emoji' },
  { key: 'dimensional', name: '立体拟物' },
  { key: 'handdrawn', name: '校园手绘' },
  { key: 'glass', name: '教务玻璃' },
];
export const uiStyles = [
  { key: DEFAULT_UI_STYLE, code: 'STYLE_LIQUID_GLASS', name: 'Apple 液态玻璃', accent: '#0071e3', darkAccent: '#70b8ff' },
  { key: 'obsidian_pro', code: 'STYLE_OBSIDIAN_PRO', name: '精密工程', accent: '#18181b', darkAccent: '#d4d4d8' },
  { key: 'ivory_editorial', code: 'STYLE_IVORY_EDITORIAL', name: '典藏学术书刊', accent: '#9f2b1f', darkAccent: '#e9a196' },
  { key: 'classic', code: 'STYLE_CLASSIC_STD', name: '经典原版', accent: '#2563eb', darkAccent: '#60a5fa' },
];

export const schoolUiOrigin = window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin;
const schoolKey = `practical:school-ui-style:${schoolUiOrigin}`;
const schoolIconsKey = `practical:school-menu-icons:${schoolUiOrigin}`;
const schoolAppearanceKey = `practical:school-appearance:${schoolUiOrigin}`;
export const desktopUiKey = `practical:desktop-ui-style:${schoolUiOrigin}`;
export const localThemeKey = scope => `practical:local-theme:v1:${scope}`;
const validStyle = value => uiStyles.some(({ key }) => key === value);
const validMenuIcons = value => menuIconStyles.some(({ key }) => key === value);

export function applyUiStyle(value) {
  const style = uiStyles.find(({ key }) => key === value) || uiStyles[0];
  document.documentElement.dataset.uiStyle = style.key;
  document.documentElement.dataset.uiStyleCode = style.code;
  return style.key;
}

function cachedValue(key, valid, fallback) {
  try {
    const value = localStorage.getItem(key);
    if (valid(value)) return value;
  } catch { /* Storage is optional before login. */ }
  return fallback;
}

export const cachedSchoolStyle = () => cachedValue(schoolKey, validStyle, DEFAULT_UI_STYLE);
const cachedSchoolIcons = () => cachedValue(schoolIconsKey, validMenuIcons, DEFAULT_MENU_ICON_STYLE);

function cachedSchoolAppearance() {
  try { return JSON.parse(localStorage.getItem(schoolAppearanceKey)) || {}; }
  catch { return {}; }
}

export function useUiStyle(scope, refreshSchool) {
  const school = ref(cachedSchoolStyle());
  const choice = ref(FOLLOW_SCHOOL);
  const appearance = ref(cachedSchoolAppearance());
  const personalAllowed = computed(() => appearance.value.allow_personal_appearance !== false);
  const personalRevision = computed(() => appearance.value.personal_appearance_revision ?? 0);
  const schoolWallpaper = computed(() => appearance.value.default_wallpaper_url || '');
  const allowsSelection = revision => personalAllowed.value && (revision ?? 0) === personalRevision.value;
  const schoolMenuIcons = ref(cachedSchoolIcons());
  const menuIconChoice = ref(FOLLOW_SCHOOL);
  const menuIconStyle = computed(() => menuIconChoice.value === FOLLOW_SCHOOL ? schoolMenuIcons.value : menuIconChoice.value);
  provide(MENU_ICON_STYLE, menuIconStyle);
  const effective = computed(() => choice.value === FOLLOW_SCHOOL ? school.value : choice.value);

  function restoreLocal(value) {
    let stored;
    if (value) {
      try { stored = JSON.parse(localStorage.getItem(localThemeKey(value))); }
      catch { /* The local theme editor reports unreadable saved settings. */ }
    }
    const fallback = stored?.saved ? 'classic' : FOLLOW_SCHOOL;
    choice.value = stored?.ui_style === FOLLOW_SCHOOL || validStyle(stored?.ui_style) ? stored.ui_style : fallback;
    menuIconChoice.value = allowsSelection(stored?.menu_icon_revision) && validMenuIcons(stored?.menu_icon_style) ? stored.menu_icon_style : FOLLOW_SCHOOL;
  }
  watch([scope, personalRevision, personalAllowed], () => restoreLocal(scope.value), { immediate: true, flush: 'sync' });
  watch(effective, applyUiStyle, { immediate: true, flush: 'sync' });

  function setAppearance(data) {
    const revision = data.personal_appearance_revision ?? 0;
    const sameSchool = !data.school_database_id || data.school_database_id === appearance.value.school_database_id;
    if (sameSchool && revision < personalRevision.value) return false;
    appearance.value = {
      school_database_id: data.school_database_id ?? appearance.value.school_database_id,
      allow_personal_appearance: data.allow_personal_appearance !== false,
      personal_appearance_revision: revision,
      default_wallpaper_url: data.default_wallpaper_url ?? (sameSchool ? schoolWallpaper.value : ''),
    };
    try { localStorage.setItem(schoolAppearanceKey, JSON.stringify(appearance.value)); } catch {}
    return true;
  }
  function setSchool(settings) {
    if (!setAppearance(settings)) return false;
    school.value = validStyle(settings.default_ui_style) ? settings.default_ui_style : DEFAULT_UI_STYLE;
    schoolMenuIcons.value = validMenuIcons(settings.default_menu_icon_style) ? settings.default_menu_icon_style : DEFAULT_MENU_ICON_STYLE;
    try {
      localStorage.setItem(schoolKey, school.value);
      localStorage.setItem(schoolIconsKey, schoolMenuIcons.value);
    } catch { /* The server values still apply. */ }
    return true;
  }
  function saveChoice(fields) {
    const key = localThemeKey(scope.value);
    const stored = JSON.parse(localStorage.getItem(key) || '{}');
    localStorage.setItem(key, JSON.stringify({ ...stored, ...fields }));
  }
  function choose(value) {
    if (!scope.value || !(value === FOLLOW_SCHOOL || validStyle(value))) return;
    saveChoice({ ui_style: value });
    choice.value = value;
  }
  function chooseMenuIcons(value) {
    if (!personalAllowed.value || !scope.value || !(value === FOLLOW_SCHOOL || validMenuIcons(value))) return;
    saveChoice({ menu_icon_style: value, menu_icon_revision: personalRevision.value });
    menuIconChoice.value = value;
  }
  function onStorage(event) {
    if (event.key === schoolAppearanceKey && event.newValue) appearance.value = cachedSchoolAppearance();
    if (event.key === schoolKey || event.key === null) school.value = cachedSchoolStyle();
    if (event.key === schoolIconsKey || event.key === null) schoolMenuIcons.value = cachedSchoolIcons();
    if (scope.value && (event.key === localThemeKey(scope.value) || event.key === null)) restoreLocal(scope.value);
  }
  const refreshVisible = () => { if (scope.value && !document.hidden) refreshSchool(); };
  const refreshTimer = window.setInterval(refreshVisible, 60000);
  document.addEventListener('visibilitychange', refreshVisible);
  window.addEventListener('online', refreshVisible);
  window.addEventListener('storage', onStorage);
  onBeforeUnmount(() => {
    window.clearInterval(refreshTimer);
    document.removeEventListener('visibilitychange', refreshVisible);
    window.removeEventListener('online', refreshVisible);
    window.removeEventListener('storage', onStorage);
  });
  return { school, choice, effective, choose, setSchool, setAppearance, schoolMenuIcons, menuIconChoice, chooseMenuIcons,
    personalAllowed, personalRevision, allowsSelection, schoolWallpaper };
}
