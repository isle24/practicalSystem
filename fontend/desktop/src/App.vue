<template>
  <main class="connection-page">
    <fieldset class="connection-background" :inert="captchaVisible" :aria-hidden="captchaVisible ? 'true' : undefined" :disabled="captchaVisible || busy || loading || captchaLoading">
    <LoginModeToggle v-if="!switching" :mode="loginMethod" :disabled="busy || loading" @update:mode="switchLoginMode" />
    <header class="connection-heading">
      <span class="product-name">实践系统</span>
      <h1>{{ editingSchool ? (domain.trim() === defaultSchoolOrigin ? defaultSchoolName : '连接学校') : schoolName }}</h1>
      <div v-if="!editingSchool" class="school-identity">
        <span>{{ remark || '学校账号登录' }}</span>
        <button type="button" class="switch-school" :disabled="busy || loading || captchaLoading" @click="switching = !switching"><RefreshCw :size="14" />切换</button>
      </div>
    </header>
    <p v-if="requireFreshLogin" class="connection-guidance" role="status">客户端此前已锁定，请重新输入密码或使用企业微信扫码验证。</p>

    <section v-if="switching" class="recent-schools" aria-label="切换学校">
      <div class="history-heading"><h2>学校与账号</h2><button type="button" class="icon-button" aria-label="关闭切换" @click="switching = false"><X :size="16" /></button></div>
      <button v-for="school in profiles" :key="`${school.origin}:${school.username || ''}`" class="school-row" :disabled="busy || loading" type="button" @click="selectSchool(school)">
        <span class="school-label"><strong>{{ school.name || '学校' }}</strong><small>{{ [school.remark, school.username].filter(Boolean).join(' · ') }}</small></span>
        <ChevronRight :size="18" />
      </button>
      <button class="add-school" type="button" @click="newSchool"><Plus :size="16" />连接其他学校</button>
    </section>

    <form v-else class="connection-form" @submit.prevent="connect()">
      <template v-if="editingSchool">
      <label for="school-domain">学校域名</label>
      <div class="domain-field" :class="{ invalid: error }">
        <Globe2 :size="19" aria-hidden="true" />
        <input id="school-domain" v-model="domain" type="text" autocomplete="url" autocapitalize="off"
          spellcheck="false" placeholder="请输入学校服务器域名" :disabled="busy || loading" :aria-invalid="!!error"
          :aria-describedby="error ? 'connection-error' : undefined" autofocus>
      </div>
      <button v-if="profiles.length" class="switch-school history-entry" type="button" @click="switching = true">选择已连接的学校<ChevronRight :size="14" /></button>
      </template>
      <template v-if="loginMethod === 'password'">
      <label for="school-username">账号</label>
      <input id="school-username" v-model="username" type="text" autocomplete="username" placeholder="请输入账号" :disabled="busy || loading">
      <label for="school-password">密码</label>
      <input id="school-password" ref="passwordInput" v-model="password" type="password" autocomplete="current-password" placeholder="请输入密码" :disabled="busy || loading" @input="markFreshPassword">
      </template>
      <p v-else class="connection-guidance">连接学校后将显示登录二维码，请使用已绑定账号的企业微信扫码。</p>
      <label for="school-remark">备注 <small>可选</small></label>
      <input id="school-remark" v-model="remark" maxlength="60" placeholder="例如：工作账号" :disabled="busy || loading">
      <div v-if="loginMethod === 'password'" class="connection-options">
        <label class="check-option"><input v-model="rememberPassword" type="checkbox" :disabled="busy || loading || !username.trim() || !password"><span>记住密码</span></label>
        <label class="check-option"><input v-model="autoLogin" type="checkbox" :disabled="busy || loading || !rememberPassword"><span>自动登录</span></label>
      </div>
      <p v-if="error" id="connection-error" class="error" role="alert"><CircleAlert :size="17" />{{ error }}</p>
      <button ref="loginButton" class="connect-button" type="submit" :disabled="busy || loading || captchaLoading || retrySeconds > 0 || !domain.trim()">
        <LoaderCircle v-if="busy" :size="19" class="spin" />
        <span>{{ busy ? '正在连接' : retrySeconds > 0 ? `${retrySeconds} 秒后重试` : loginMethod === 'wechat' ? '连接学校并扫码' : '登录' }}</span>
        <ArrowRight v-if="!busy" :size="19" />
      </button>
      <button type="button" class="switch-school recovery-entry" :disabled="busy || loading || !domain.trim()" @click="recoverPassword">忘记密码</button>
    </form>

    <footer><LockKeyhole :size="13" /><span>学校独立登录</span><span class="version">v{{ version }}</span></footer>
    </fieldset>

    <div v-if="captchaVisible" class="captcha-overlay" @click.self="closeCaptcha">
      <form ref="captchaDialog" class="captcha-dialog" role="dialog" aria-modal="true" aria-labelledby="captcha-title" tabindex="-1" @submit.prevent="connect(true)" @keydown.esc.prevent="closeCaptcha" @keydown.tab.prevent="handleCaptchaTab">
        <div class="captcha-dialog-heading"><h2 id="captcha-title">验证后登录</h2><button type="button" class="icon-button" aria-label="关闭验证码" :disabled="busy || captchaLoading" @click="closeCaptcha"><X :size="16" /></button></div>
        <p class="connection-guidance">请完成验证码。账号和密码可关闭此窗口后修改。</p>
        <LoginCaptchaInput v-model="captchaCode" :captcha="captcha" :busy="busy || captchaLoading" @refresh="refreshCaptcha" />
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <button class="connect-button" type="submit" :disabled="busy || loading || captchaLoading || retrySeconds > 0 || !captcha || !captchaCode.trim()"><span>{{ busy ? '正在登录' : retrySeconds > 0 ? `${retrySeconds} 秒后重试` : '验证并登录' }}</span></button>
      </form>
    </div>
  </main>
</template>

<script setup>
import { ref, nextTick, onBeforeUnmount, onMounted, watch } from 'vue';
import { invoke } from '@tauri-apps/api/core';
import { getVersion } from '@tauri-apps/api/app';
import { ArrowRight, ChevronRight, CircleAlert, Globe2, LoaderCircle, LockKeyhole, Plus, RefreshCw, X } from '@lucide/vue';
import LoginModeToggle from '../../shared/components/LoginModeToggle.vue';
import LoginCaptchaInput from '../../shared/components/LoginCaptchaInput.vue';

const defaultSchoolOrigin = 'https://sxxt.cdjcc.edu.cn';
const defaultSchoolName = '成都锦城学院';
const domain = ref(defaultSchoolOrigin);
const schoolName = ref(defaultSchoolName);
const remark = ref('');
const editingSchool = ref(true);
const switching = ref(false);
const loginMethod = ref('password');
const username = ref('');
const password = ref('');
const rememberPassword = ref(false);
const autoLogin = ref(false);
const profiles = ref([]);
const error = ref('');
const loading = ref(true);
const busy = ref(false);
const version = ref('');
const requireFreshLogin = ref(false);
const freshPassword = ref(false);
const captchaRequired = ref(false);
const captcha = ref(null);
const captchaCode = ref('');
const captchaVisible = ref(false);
const captchaLoading = ref(false);
const captchaDialog = ref(null);
const passwordInput = ref(null);
const loginButton = ref(null);
const retrySeconds = ref(0);
let retryUntil = 0;
let retryTimer;
let loginScope = crypto.randomUUID();
let scopeVersion = 0;
let operationVersion = 0;
let disposed = false;

function resetLoginScope() {
  const previousScope = loginScope;
  loginScope = crypto.randomUUID();
  scopeVersion++;
  captchaRequired.value = false;
  captcha.value = null;
  captchaCode.value = '';
  captchaVisible.value = false;
  retryUntil = 0;
  retrySeconds.value = 0;
  invoke('cancel_connection_login', { loginScope: previousScope }).catch(() => {});
}

function currentScope(version, scope) {
  return !disposed && scopeVersion === version && loginScope === scope;
}

function showLoginFailure(reason) {
  const value = reason && typeof reason === 'object' ? reason : {};
  error.value = typeof value.message === 'string' ? value.message : String(reason);
  if (value.captcha_required === true) captchaRequired.value = true;
  if (value.captcha) {
    captcha.value = value.captcha;
    captchaCode.value = '';
  }
  if (Number.isFinite(value.retry_after) && value.retry_after > 0) {
    retryUntil = Date.now() + Math.min(value.retry_after, 86400) * 1000;
    retrySeconds.value = Math.ceil((retryUntil - Date.now()) / 1000);
  }
  if (captchaRequired.value) captchaVisible.value = true;
}

function closeCaptcha() {
  if (busy.value || captchaLoading.value) return;
  captchaVisible.value = false;
  captchaCode.value = '';
}

function focusCaptchaDialog() {
  if (!captchaVisible.value || disposed || document.hidden) return;
  const dialog = captchaDialog.value;
  const target = dialog?.querySelector('.login-captcha input:not(:disabled)')
    || dialog?.querySelector('.login-captcha button:not(:disabled)') || dialog;
  target?.focus();
}

function handleCaptchaTab(event) {
  const dialog = captchaDialog.value;
  const controls = Array.from(dialog?.querySelectorAll('input:not(:disabled), button:not(:disabled)') || []);
  if (!controls.length) { dialog?.focus(); return; }
  const index = controls.indexOf(document.activeElement);
  if (index < 0) { controls[event.shiftKey ? controls.length - 1 : 0].focus(); return; }
  controls[(index + (event.shiftKey ? -1 : 1) + controls.length) % controls.length].focus();
}

watch(captchaVisible, async (visible, previous) => {
  await nextTick();
  if (disposed || document.hidden) return;
  if (visible && captchaVisible.value) focusCaptchaDialog();
  else if (previous && !captchaVisible.value) {
    const target = passwordInput.value && !passwordInput.value.disabled ? passwordInput.value : loginButton.value;
    if (target && !target.disabled) target.focus();
  }
});

watch([busy, captchaLoading], async ([connecting, refreshing], [wasConnecting, wasRefreshing]) => {
  if (!captchaVisible.value || connecting || refreshing || !(wasConnecting || wasRefreshing)) return;
  await nextTick();
  focusCaptchaDialog();
});

async function refreshCaptcha() {
  if (busy.value || loading.value || captchaLoading.value || retrySeconds.value > 0 || loginMethod.value !== 'password' || !domain.value.trim() || !username.value.trim()) return;
  const scope = loginScope;
  const version = scopeVersion;
  const operation = ++operationVersion;
  captchaLoading.value = true;
  error.value = '';
  captchaCode.value = '';
  try {
    const result = await invoke('create_connection_login_captcha', { domain: domain.value, username: username.value, loginScope: scope });
    if (!currentScope(version, scope)) return;
    captchaRequired.value = captchaRequired.value || result.captcha_required === true;
    captcha.value = result.captcha || null;
  } catch (reason) {
    if (currentScope(version, scope)) showLoginFailure(reason);
  } finally {
    if (!disposed && operation === operationVersion) captchaLoading.value = false;
  }
}

function applyFreshRequirement(settings) {
  const required = settings.require_fresh_login === true;
  if (required && !requireFreshLogin.value) {
    resetLoginScope();
    password.value = '';
    freshPassword.value = false;
    autoLogin.value = false;
  }
  requireFreshLogin.value = required;
}

function markFreshPassword(event) {
  freshPassword.value = event.isTrusted && Boolean(password.value);
}

watch([domain, username], () => {
  if (loading.value || busy.value) return;
  resetLoginScope();
  password.value = '';
  freshPassword.value = false;
  rememberPassword.value = false;
  autoLogin.value = false;
}, { flush: 'sync' });
watch(rememberPassword, value => { if (!value) autoLogin.value = false; }, { flush: 'sync' });

// 加载本机保存的学校连接。
onMounted(async () => {
  try {
    const settings = await invoke('read_connection_settings');
    if (disposed) return;
    applyFreshRequirement(settings);
    profiles.value = settings.schools;
    domain.value = settings.last_origin || domain.value;
    const recent = settings.schools.find(item => item.origin === domain.value && item.username === settings.last_username)
      || settings.schools.find(item => item.origin === domain.value);
    if (recent) {
      schoolName.value = recent.name;
      remark.value = recent.remark || '';
      loginMethod.value = profileLoginMethod(recent);
      editingSchool.value = false;
    }
    if (recent?.username) username.value = recent.username;
    if (recent?.username && loginMethod.value === 'password' && !requireFreshLogin.value) {
      const credentialScope = loginScope;
      const credentialVersion = scopeVersion;
      const saved = await invoke('read_saved_connection_password', { origin: recent.origin, username: recent.username });
      if (currentScope(credentialVersion, credentialScope) && !document.hidden) {
        password.value = saved || '';
        rememberPassword.value = Boolean(saved);
        autoLogin.value = Boolean(saved && recent.auto_login);
      }
    }
    const appVersion = await getVersion();
    if (!disposed) version.value = appVersion;
  } catch (reason) {
    if (!disposed) error.value = String(reason);
  } finally {
    if (!disposed) loading.value = false;
  }
  if (disposed) return;
  window.addEventListener('focus', refreshProfiles);
  window.addEventListener('practical-client-password-login', showPasswordConnection);
  window.addEventListener('practical-client-login-cancelled', resetCancelledConnection);
  document.addEventListener('visibilitychange', handleConnectionVisibility);
  retryTimer = window.setInterval(() => { retrySeconds.value = Math.max(0, Math.ceil((retryUntil - Date.now()) / 1000)); }, 1000);
  if (autoLogin.value && password.value) {
    await connect();
  }
});

onBeforeUnmount(() => {
  disposed = true;
  resetLoginScope();
  password.value = '';
  freshPassword.value = false;
  window.clearInterval(retryTimer);
  window.removeEventListener('focus', refreshProfiles);
  window.removeEventListener('practical-client-password-login', showPasswordConnection);
  window.removeEventListener('practical-client-login-cancelled', resetCancelledConnection);
  document.removeEventListener('visibilitychange', handleConnectionVisibility);
});

function handleConnectionVisibility() {
  if (!document.hidden) return;
  operationVersion++;
  busy.value = false;
  loading.value = false;
  captchaLoading.value = false;
  resetLoginScope();
  password.value = '';
  freshPassword.value = false;
}

function resetCancelledConnection() {
  if (disposed) return;
  operationVersion++;
  busy.value = false;
  loading.value = false;
  captchaLoading.value = false;
  resetLoginScope();
}

async function showPasswordConnection() {
  if (busy.value || loading.value || captchaLoading.value) return;
  switching.value = false;
  await selectLoginMethod('password');
}

function profileLoginMethod(profile) {
  return profile.login_method === 'wechat' || (!profile.login_method && !profile.username) ? 'wechat' : 'password';
}

async function refreshProfiles() {
  if (busy.value || loading.value) return;
  const scope = loginScope;
  const version = scopeVersion;
  try {
    const settings = await invoke('read_connection_settings');
    if (!currentScope(version, scope)) return;
    applyFreshRequirement(settings);
    profiles.value = settings.schools;
  } catch (reason) {
    if (currentScope(version, scope)) error.value = String(reason);
  }
}

async function selectLoginMethod(method) {
  if (busy.value || loading.value || captchaLoading.value || loginMethod.value === method) return;
  resetLoginScope();
  const scope = loginScope;
  const version = scopeVersion;
  loginMethod.value = method;
  password.value = '';
  freshPassword.value = false;
  rememberPassword.value = false;
  autoLogin.value = false;
  error.value = '';
  if (method !== 'password' || !username.value.trim() || editingSchool.value || requireFreshLogin.value) return;
  loading.value = true;
  try {
    const saved = await invoke('read_saved_connection_password', { origin: domain.value, username: username.value });
    if (!currentScope(version, scope) || document.hidden) return;
    password.value = saved || '';
    rememberPassword.value = Boolean(saved);
  } catch (reason) {
    if (currentScope(version, scope)) error.value = String(reason);
  } finally {
    if (currentScope(version, scope)) loading.value = false;
  }
}

async function switchLoginMode(method) {
  if (busy.value || loading.value) return;
  await selectLoginMethod(method);
  if (method === 'wechat' && domain.value.trim()) await connect();
}

// 使用所填域名建立独立学校会话。
async function connect(fromCaptcha = false) {
  if (busy.value || loading.value || captchaLoading.value || retrySeconds.value > 0) return;
  const passwordLogin = loginMethod.value === 'password';
  if (passwordLogin && (!username.value.trim() || !password.value)) {
    error.value = '请输入账号和密码，或选择企业微信扫码登录';
    return;
  }
  if (passwordLogin && captchaRequired.value && (!fromCaptcha || !captcha.value?.id || !captchaCode.value.trim())) {
    captchaVisible.value = true;
    if (!captcha.value) await refreshCaptcha();
    return;
  }
  const scope = loginScope;
  const version = scopeVersion;
  const operation = ++operationVersion;
  busy.value = true;
  error.value = '';
  try {
    const settings = await invoke('connect_school', {
      domain: domain.value,
      loginMethod: loginMethod.value,
      username: passwordLogin ? username.value : '',
      password: passwordLogin ? password.value : '',
      rememberPassword: Boolean(passwordLogin && rememberPassword.value),
      autoLogin: Boolean(passwordLogin && autoLogin.value),
      remark: remark.value,
      freshPassword: freshPassword.value,
      loginScope: scope,
      captchaId: passwordLogin && captchaRequired.value ? captcha.value?.id : null,
      captchaCode: passwordLogin && captchaRequired.value ? captchaCode.value : null,
    });
    if (!currentScope(version, scope)) return;
    profiles.value = settings.schools;
    domain.value = settings.last_origin;
    const connected = settings.schools.find(item => item.origin === settings.last_origin && item.username === settings.last_username);
    if (connected) { schoolName.value = connected.name; editingSchool.value = false; }
    password.value = '';
    freshPassword.value = false;
    requireFreshLogin.value = false;
    resetLoginScope();
  } catch (reason) {
    if (currentScope(version, scope)) showLoginFailure(reason);
  } finally {
    if (!disposed && operation === operationVersion) busy.value = false;
  }
}

async function recoverPassword() {
  if (busy.value || loading.value || captchaLoading.value || !domain.value.trim()) return;
  resetLoginScope();
  const scope = loginScope;
  const version = scopeVersion;
  const operation = ++operationVersion;
  busy.value = true; error.value = ''; password.value = ''; freshPassword.value = false;
  try {
    const settings = await invoke('connect_school', {
      domain: domain.value, loginMethod: 'wechat', username: '', password: '',
      rememberPassword: false, autoLogin: false, remark: remark.value, recovery: true, loginScope: scope,
    });
    if (!currentScope(version, scope)) return;
    profiles.value = settings.schools;
    applyFreshRequirement(settings);
  } catch (reason) { if (currentScope(version, scope)) showLoginFailure(reason); }
  finally { if (!disposed && operation === operationVersion) busy.value = false; }
}

// 选择最近使用的学校。
async function selectSchool(school) {
  if (busy.value || loading.value || captchaLoading.value) return;
  resetLoginScope();
  const scope = loginScope;
  const version = scopeVersion;
  loading.value = true;
  error.value = '';
  domain.value = school.origin;
  schoolName.value = school.name;
  remark.value = school.remark || '';
  editingSchool.value = false;
  switching.value = false;
  username.value = school.username || '';
  loginMethod.value = profileLoginMethod(school);
  password.value = '';
  freshPassword.value = false;
  autoLogin.value = false;
  rememberPassword.value = false;
  try {
    if (school.username && loginMethod.value === 'password' && !requireFreshLogin.value) {
      const saved = await invoke('read_saved_connection_password', { origin: school.origin, username: school.username });
      if (!currentScope(version, scope) || document.hidden) return;
      password.value = saved || '';
      rememberPassword.value = Boolean(saved);
      autoLogin.value = Boolean(saved && school.auto_login);
    }
  } catch (reason) {
    if (currentScope(version, scope)) error.value = String(reason);
  } finally {
    if (currentScope(version, scope)) loading.value = false;
  }
}

// 切换到域名输入时不携带原学校的凭据。
function newSchool() {
  if (busy.value || loading.value || captchaLoading.value) return;
  resetLoginScope();
  domain.value = ''; username.value = ''; password.value = ''; remark.value = '';
  freshPassword.value = false;
  rememberPassword.value = false; autoLogin.value = false;
  schoolName.value = ''; editingSchool.value = true; switching.value = false; error.value = '';
}
</script>

<style scoped>
.connection-guidance { margin: 0 0 14px; color: var(--connection-muted, #5f6b78); font-size: 13px; line-height: 1.6; }
.recovery-entry { margin-top: 16px; }
</style>
