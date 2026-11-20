import { computed, onBeforeUnmount, ref, watch } from 'vue';

export const DEFAULT_UI_STYLE = 'liquid_glass';
export const FOLLOW_SCHOOL = 'school_default';
export const uiStyles = [
  { key: DEFAULT_UI_STYLE, code: 'STYLE_LIQUID_GLASS', name: 'Apple 液态玻璃', accent: '#0071e3', darkAccent: '#70b8ff' },
  { key: 'obsidian_pro', code: 'STYLE_OBSIDIAN_PRO', name: '精密工程', accent: '#18181b', darkAccent: '#d4d4d8' },
  { key: 'ivory_editorial', code: 'STYLE_IVORY_EDITORIAL', name: '典藏学术书刊', accent: '#9f2b1f', darkAccent: '#e9a196' },
  { key: 'classic', code: 'STYLE_CLASSIC_STD', name: '经典原版', accent: '#2563eb', darkAccent: '#60a5fa' },
];

export const schoolUiOrigin = window.__PRACTICAL_DESKTOP__?.serverOrigin || window.location.origin;
const schoolKey = `practical:school-ui-style:${schoolUiOrigin}`;
export const desktopUiKey = `practical:desktop-ui-style:${schoolUiOrigin}`;
export const localThemeKey = scope => `practical:local-theme:v1:${scope}`;
const validStyle = value => uiStyles.some(({ key }) => key === value);

export function applyUiStyle(value) {
  const style = uiStyles.find(({ key }) => key === value) || uiStyles[0];
  document.documentElement.dataset.uiStyle = style.key;
  document.documentElement.dataset.uiStyleCode = style.code;
  return style.key;
}

export function cachedSchoolStyle() {
  try {
    const value = localStorage.getItem(schoolKey);
    if (validStyle(value)) return value;
  } catch { /* Storage is optional before login. */ }
  return DEFAULT_UI_STYLE;
}

function localChoice(scope) {
  if (scope) {
    try {
      const stored = JSON.parse(localStorage.getItem(localThemeKey(scope)) || 'null');
      if (stored?.ui_style === FOLLOW_SCHOOL || validStyle(stored?.ui_style)) return stored.ui_style;
      if (stored?.saved) return 'classic';
    } catch { /* The local theme editor reports unreadable saved settings. */ }
  }
  return FOLLOW_SCHOOL;
}

export function useUiStyle(scope) {
  const school = ref(cachedSchoolStyle());
  const choice = ref(FOLLOW_SCHOOL);
  const effective = computed(() => choice.value === FOLLOW_SCHOOL ? school.value : choice.value);
  watch(scope, value => { choice.value = localChoice(value); }, { immediate: true, flush: 'sync' });
  watch(effective, applyUiStyle, { immediate: true, flush: 'sync' });

  function setSchool(value) {
    school.value = validStyle(value) ? value : DEFAULT_UI_STYLE;
    try { localStorage.setItem(schoolKey, school.value); } catch { /* The server value still applies. */ }
  }
  function choose(value) {
    if (!scope.value || !(value === FOLLOW_SCHOOL || validStyle(value))) return;
    const key = localThemeKey(scope.value);
    const stored = JSON.parse(localStorage.getItem(key) || '{}');
    localStorage.setItem(key, JSON.stringify({ ...stored, ui_style: value }));
    choice.value = value;
  }
  function onStorage(event) {
    if (event.key === schoolKey || event.key === null) school.value = cachedSchoolStyle();
    if (scope.value && (event.key === localThemeKey(scope.value) || event.key === null)) choice.value = localChoice(scope.value);
  }
  window.addEventListener('storage', onStorage);
  onBeforeUnmount(() => window.removeEventListener('storage', onStorage));
  return { school, choice, effective, choose, setSchool };
}
