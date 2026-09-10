import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

export function useMobileNavigation(options = {}) {
  const activeTab = ref(options.initialTab || 'home');
  const transitionDirection = ref('tab');
  const state = reactive({
    backStack: [],
    restoring: false,
  });

  const snapshot = () => ({
    tab: activeTab.value,
    ...(options.captureExtras?.() || {}),
  });
  const snapshotKey = computed(() => JSON.stringify(snapshot()));
  const pageKey = computed(() => activeTab.value);
  const canGoBack = computed(() => state.backStack.length > 0);

  watch(snapshotKey, (current, previous) => {
    if (state.restoring || !previous || current === previous) {
      return;
    }

    const previousSnapshot = parseSnapshot(previous);
    const currentSnapshot = parseSnapshot(current);
    state.backStack.push(previous);
    if (state.backStack.length > 40) {
      state.backStack.shift();
    }
    transitionDirection.value = transitionFor(previousSnapshot, currentSnapshot);
    history.pushState({ practicalMobileNavigation: true }, '', window.location.href);
  });

  async function applyKey(key, direction = 'back') {
    const target = parseSnapshot(key);
    if (!target) {
      return;
    }

    state.restoring = true;
    transitionDirection.value = direction;
    activeTab.value = target.tab || options.initialTab || 'home';
    await options.restoreExtras?.(target);
    await nextTick();
    state.restoring = false;
  }

  function navigate(tab) {
    if (tab && tab !== activeTab.value) {
      activeTab.value = tab;
    }
  }

  function goBack() {
    if (!canGoBack.value) {
      return;
    }
    history.back();
  }

  async function handlePopState() {
    if (options.beforeLeave && !(await options.beforeLeave())) {
      history.pushState({ practicalMobileNavigation: true }, '', window.location.href);
      return;
    }
    const target = state.backStack.pop();
    if (target) {
      await applyKey(target, 'back');
    }
  }

  function clear() {
    state.backStack.splice(0);
  }

  async function resetToHome() {
    state.restoring = true;
    transitionDirection.value = 'tab';
    activeTab.value = options.initialTab || 'home';
    clear();
    await nextTick();
    state.restoring = false;
    history.replaceState({ practicalMobileNavigation: true }, '', window.location.href);
  }

  function transitionFor(previous, current) {
    const rootTabs = options.rootTabs || ['home', 'internship', 'training', 'lab', 'mine'];
    if (previous?.tab !== current?.tab && rootTabs.includes(previous?.tab) && rootTabs.includes(current?.tab)) {
      return 'tab';
    }
    return 'forward';
  }

  onMounted(() => {
    history.replaceState({ practicalMobileNavigation: true }, '', window.location.href);
    window.addEventListener('popstate', handlePopState);
  });

  onBeforeUnmount(() => {
    window.removeEventListener('popstate', handlePopState);
  });

  return {
    activeTab,
    canGoBack,
    pageKey,
    state,
    transitionDirection,
    applyKey,
    clear,
    goBack,
    navigate,
    resetToHome,
  };
}

function parseSnapshot(value) {
  try {
    return typeof value === 'string' ? JSON.parse(value) : value;
  } catch {
    return null;
  }
}
