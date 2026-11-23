import { onBeforeUnmount, watch } from 'vue';
import { applyUiStyle, cachedSchoolStyle, desktopUiKey, schoolUiOrigin } from '../../shared/uiStyles';
import '../../shared/interface.css';
import './window-appearance.css';

export function useWindowAppearance(accountScope) {
  const root = document.documentElement;
  const dark = window.matchMedia('(prefers-color-scheme: dark)');

  function updateTheme() {
    let appearance;
    if (accountScope) {
      const account = accountScope.value;
      if (account?.origin === schoolUiOrigin && account.account_id) {
        try {
          const stored = window.__PRACTICAL_AGENT_APPEARANCE__ || JSON.parse(localStorage.getItem(desktopUiKey));
          if (Number(stored?.account_id) === Number(account.account_id)) appearance = stored;
        } catch { /* 学校默认外观仍可使用。 */ }
      }
      applyUiStyle(appearance?.style || cachedSchoolStyle());
    }
    root.dataset.theme = ['light', 'dark'].includes(appearance?.theme)
      ? appearance.theme : (dark.matches ? 'dark' : 'light');
  }

  function updateFocus() {
    root.toggleAttribute('data-window-inactive', document.hidden || !document.hasFocus());
  }
  function onStorage(event) {
    if (event.key === desktopUiKey || event.key === null) updateTheme();
  }

  updateTheme();
  updateFocus();
  if (accountScope) {
    watch(accountScope, updateTheme, { flush: 'sync' });
    window.addEventListener('storage', onStorage);
    window.addEventListener('practical-agent-appearance', updateTheme);
  }
  dark.addEventListener('change', updateTheme);
  window.addEventListener('focus', updateFocus);
  window.addEventListener('blur', updateFocus);
  document.addEventListener('visibilitychange', updateFocus);
  onBeforeUnmount(() => {
    dark.removeEventListener('change', updateTheme);
    window.removeEventListener('storage', onStorage);
    window.removeEventListener('practical-agent-appearance', updateTheme);
    window.removeEventListener('focus', updateFocus);
    window.removeEventListener('blur', updateFocus);
    document.removeEventListener('visibilitychange', updateFocus);
  });
}
