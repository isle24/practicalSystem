import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import { showConfirmDialog, showToast } from 'vant';
import {
  fetchRegisterOptions,
  fetchSwitchableAccounts,
  login as loginApi,
  logout as logoutApi,
  passkeyLogin,
  registerAccount,
  switchAccount,
} from '../api/system';

export function useAuthSession(options = {}) {
  const loginForm = reactive({
    login_name: '',
    password: '',
  });
  const loginState = reactive({
    loading: false,
    message: '',
  });
  const registerForm = reactive({
    role_type: 'student',
    name: '',
    login_name: '',
    password: '',
    mobile: '',
    email: '',
    student_num: '',
    teacher_num: '',
  });
  const registerState = reactive({
    enabled: false,
    open: false,
    loading: false,
    message: '',
    roles: [],
  });
  const switchAccountState = reactive({
    loading: false,
    message: '',
    items: [],
  });

  const registerRoleOptions = computed(() => registerState.roles.length
    ? registerState.roles
    : [
        { role_type: 'student', name: '学生' },
        { role_type: 'teacher', name: '教师' },
      ]);

  function isLoggedIn() {
    return Boolean(options.isLoggedIn?.());
  }

  function resetAccountChoices() {
    switchAccountState.items = [];
    switchAccountState.message = '';
    switchAccountState.loading = false;
  }

  async function loadSwitchableAccounts() {
    if (!isLoggedIn()) {
      resetAccountChoices();
      return;
    }

    switchAccountState.loading = true;
    switchAccountState.message = '';
    try {
      const data = await fetchSwitchableAccounts();
      switchAccountState.items = data.accounts || [];
    } catch (error) {
      switchAccountState.items = [];
      switchAccountState.message = error.message;
    } finally {
      switchAccountState.loading = false;
    }
  }

  async function switchMobileAccount(account) {
    const accountId = Number(account?.id || 0);
    if (!accountId || account?.is_current || switchAccountState.loading) {
      return;
    }

    switchAccountState.loading = true;
    switchAccountState.message = '';
    try {
      await switchAccount({ account_id: accountId, client: 'H5' });
      resetAccountChoices();
      await options.onAuthenticated?.(true);
      showToast('已切换身份');
    } catch (error) {
      switchAccountState.message = error.message;
      showToast(error.message);
    } finally {
      switchAccountState.loading = false;
    }
  }

  function accountSwitchTitle(account) {
    return account?.name || account?.login_name || '未命名账号';
  }

  function accountSwitchLabel(account) {
    const parts = [
      account?.role_name || options.roleLabel?.(account?.role_type) || account?.role_type || '未分配角色',
      account?.login_name || '',
    ].filter(Boolean);
    return parts.join(' / ');
  }

  async function consumeUrlPasskey() {
    const params = new URLSearchParams(window.location.search);
    const passkey = params.get('passkey') || params.get('login_key');
    if (!passkey) {
      return false;
    }

    loginState.loading = true;
    loginState.message = '';
    try {
      await passkeyLogin({ passkey, client: 'H5' });
      params.delete('passkey');
      params.delete('login_key');
      const query = params.toString();
      history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
      return true;
    } catch (error) {
      loginState.message = error.message;
      showToast(error.message);
      return false;
    } finally {
      loginState.loading = false;
    }
  }

  function applyRegisterOptions(data = {}) {
    const roles = (data.roles || []).filter(role => ['student', 'teacher'].includes(role.role_type));
    registerState.enabled = data.enabled === true;
    registerState.roles = roles;
    if (roles.length && !roles.some(role => role.role_type === registerForm.role_type)) {
      registerForm.role_type = roles[0].role_type;
    }
    if (!registerState.enabled) {
      registerState.open = false;
    }
  }

  async function loadRegisterOptions() {
    registerState.message = '';
    try {
      applyRegisterOptions(await fetchRegisterOptions());
    } catch {
      registerState.enabled = false;
      registerState.open = false;
    }
  }

  function toggleRegisterForm() {
    registerState.open = !registerState.open;
    registerState.message = '';
  }

  async function submitRegister() {
    if (!registerState.enabled || registerState.loading) {
      return;
    }
    if (!registerForm.name.trim() || !registerForm.login_name.trim() || !registerForm.password.trim()) {
      registerState.message = '姓名、账号和密码不能为空';
      return;
    }

    registerState.loading = true;
    registerState.message = '';
    try {
      await registerAccount({ ...registerForm, client: 'H5' });
      registerState.open = false;
      await options.onAuthenticated?.(true);
      showToast('注册成功');
    } catch (error) {
      registerState.message = error.message;
      showToast(error.message);
    } finally {
      registerState.loading = false;
    }
  }

  async function submitLogin() {
    if (loginState.loading) {
      return;
    }
    loginState.loading = true;
    loginState.message = '';
    try {
      await loginApi({
        login_name: loginForm.login_name,
        password: loginForm.password,
        client: 'H5',
      });
      await options.onAuthenticated?.(true);
    } catch (error) {
      loginState.message = error.message;
    } finally {
      loginState.loading = false;
    }
  }

  async function confirmMobileLogout() {
    if (loginState.loading) {
      return;
    }
    try {
      await showConfirmDialog({
        title: '退出登录',
        message: '确认退出当前账号？',
        confirmButtonText: '退出',
        cancelButtonText: '取消',
      });
    } catch {
      return;
    }
    await submitLogout();
  }

  async function submitLogout() {
    if (loginState.loading) {
      return;
    }
    loginState.loading = true;
    loginState.message = '';
    try {
      await logoutApi();
      resetAccountChoices();
      await options.onLoggedOut?.();
      await loadRegisterOptions();
    } catch (error) {
      loginState.message = error.message;
    } finally {
      loginState.loading = false;
    }
  }

  function handleAuthExpired(event) {
    const message = event?.detail?.message || '登录已过期，请重新登录';
    loginState.loading = false;
    loginState.message = message;
    resetAccountChoices();
    options.onExpired?.(message);
    showToast(message);
  }

  onMounted(async () => {
    window.addEventListener('practical-auth-expired', handleAuthExpired);
    await loadRegisterOptions();
    await consumeUrlPasskey();
    await options.onInitialLoad?.();
  });

  onBeforeUnmount(() => {
    window.removeEventListener('practical-auth-expired', handleAuthExpired);
  });

  return {
    loginForm,
    loginState,
    registerForm,
    registerRoleOptions,
    registerState,
    switchAccountState,
    accountSwitchLabel,
    accountSwitchTitle,
    confirmMobileLogout,
    loadRegisterOptions,
    loadSwitchableAccounts,
    resetAccountChoices,
    submitLogin,
    submitLogout,
    submitRegister,
    switchMobileAccount,
    toggleRegisterForm,
  };
}
